import { useRef } from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { PersonLifecycleActions } from '@/components/people/PersonLifecycleActions';
import { TooltipProvider } from '@/components/ui/Tooltip';
import type { DeletePersonResult } from '@/lib/people/results';
import { DELETE_REFUSALS, PERSON_ACTIVE, VIEWER_ID } from '@/components/preview/fixtures/people';
import { BULK_CONTENTION } from '@/components/preview/fixtures/bulk';
import { BulkHarness } from './bulk/harness';
import { CUSTOM_ROLE, SAVE_REFUSALS } from '@/components/preview/fixtures/permissions';
import { ChecklistHarness } from './permissions/harness';
import { JONAS, OFFERED_JONAS_BY_ADMIN, PROPOSAL_BY_OTHER, PROPOSAL_FINANCE, PROPOSAL_REFUSALS, SAVE_REFUSALS as PICKER_REFUSALS, page } from '@/components/preview/fixtures/roles';
import { PickerHarness, scene } from './roles/harness';

// Regression for the B-4 step 3 flake (b4-step3-progress.md "Flake"): when an injected action answers in a LATER task
// than the click (as a real network answer does), focus must move in the SAME commit that inserts the banner — never a
// task later. A MutationObserver callback runs as a microtask right after that commit, before any passive effect, so
// it sees exactly what a user's assistive tech sees when the banner appears.

/** Resolves after a 20 ms timer: always outside the click's act() scope. */
const later = <T,>(value: T) => new Promise<T>((resolve) => setTimeout(() => resolve(value), 20));

/** Records where focus is at the first DOM mutation after which `text` is present inside `root()`. */
function focusWhenInserted(text: string, root: () => HTMLElement = () => document.body) {
  const seen: Array<Element | null> = [];
  const observer = new MutationObserver(() => {
    if (seen.length === 0 && within(root()).queryByText(text)) seen.push(document.activeElement);
  });
  observer.observe(document.body, { childList: true, subtree: true, characterData: true });
  return { first: () => seen[0], stop: () => observer.disconnect() };
}

describe('focus moves in the commit that shows the banner (late answer)', () => {
  it('person: Delete refusal inside the dialog', async () => {
    function Page() {
      const h1 = useRef<HTMLHeadingElement>(null);
      const answer = (): Promise<DeletePersonResult> => later(DELETE_REFUSALS[0]!);
      return (
        <TooltipProvider>
          <main>
            <h1 ref={h1} tabIndex={-1}>Sample Person</h1>
            <PersonLifecycleActions person={PERSON_ACTIVE} viewerId={VIEWER_ID} canDelete canAssign onPageTookOver={() => undefined} fallbackFocusRef={h1} timeZone="UTC" deletePerson={answer} restorePerson={async () => ({ kind: 'unreachable' })} />
          </main>
        </TooltipProvider>
      );
    }
    render(<Page />);
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: 'Delete person' }));
    const dialog = await screen.findByRole('dialog');
    const probe = focusWhenInserted('Not deleted', () => dialog);
    await user.click(within(dialog).getByRole('button', { name: 'Delete person' }));
    await waitFor(() => expect(probe.first()).toBeDefined());
    probe.stop();
    expect(probe.first()).toBe(within(dialog).getByText('Not deleted'));
  });

  it('bulk: Delete refusal inside the dialog', async () => {
    render(<BulkHarness run={() => later(BULK_CONTENTION)} maxMembers={5} />);
    const user = userEvent.setup();
    await user.click(screen.getByRole('checkbox', { name: 'Select Sample Supplier A' }));
    await user.click(screen.getByRole('button', { name: 'Delete' }));
    const dialog = await screen.findByRole('dialog');
    const probe = focusWhenInserted('Not deleted', () => dialog);
    await user.click(within(dialog).getByRole('button', { name: 'Delete' }));
    await waitFor(() => expect(probe.first()).toBeDefined());
    probe.stop();
    expect(probe.first()).toBe(within(dialog).getByText('Not deleted'));
  });

  it('bulk: Restore refusal in the banner zone', async () => {
    render(<BulkHarness run={() => later(BULK_CONTENTION)} maxMembers={5} />);
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: 'Scope: archived' }));
    await user.click(screen.getByRole('checkbox', { name: 'Select every row on this page' }));
    const probe = focusWhenInserted('Not restored');
    await user.click(screen.getByRole('button', { name: 'Restore' }));
    await waitFor(() => expect(probe.first()).toBeDefined());
    probe.stop();
    expect(probe.first()).toBe(screen.getByText('Not restored'));
  });

  it('checklist: Not saved banner above the groups (B-4 step 4)', async () => {
    render(<ChecklistHarness role={CUSTOM_ROLE} answer={() => later(SAVE_REFUSALS[0]!)} />);
    const user = userEvent.setup();
    await user.click(screen.getByRole('checkbox', { name: 'View customers' }));
    const probe = focusWhenInserted('Not saved');
    await user.click(screen.getByRole('button', { name: 'Save changes' }));
    await waitFor(() => expect(probe.first()).toBeDefined());
    probe.stop();
    expect(probe.first()).toBe(screen.getByText('Not saved'));
  });

  it('picker: the first refused banner after Save roles (B-4 step 5; the harness answers in later tasks)', async () => {
    render(<PickerHarness scene={scene(JONAS, OFFERED_JONAS_BY_ADMIN)} assign={() => later(PICKER_REFUSALS.assignProposalPending)} />);
    const user = userEvent.setup();
    await user.click(screen.getByRole('checkbox', { name: 'admin' }));
    const probe = focusWhenInserted('Not given: admin');
    await user.click(screen.getByRole('button', { name: 'Save roles' }));
    await waitFor(() => expect(probe.first()).toBeDefined());
    probe.stop();
    expect(probe.first()).toBe(screen.getByText('Not given: admin'));
  });

  it('Waiting group: Not withdrawn (B-4 step 5)', async () => {
    render(<PickerHarness scene={scene(JONAS, OFFERED_JONAS_BY_ADMIN, [PROPOSAL_BY_OTHER])} withdraw={() => later(PROPOSAL_REFUSALS.notTheProposer)} />);
    const user = userEvent.setup();
    const probe = focusWhenInserted('Not withdrawn');
    await user.click(screen.getByRole('button', { name: 'Withdraw' }));
    await waitFor(() => expect(probe.first()).toBeDefined());
    probe.stop();
    expect(probe.first()).toBe(screen.getByText('Not withdrawn'));
  });

  it('Waiting group: "Proposal confirmed…" is written AFTER focus reached the <h2> (gaps Rev 1 P-4, RED L-3)', async () => {
    render(
      <PickerHarness
        scene={scene(JONAS, OFFERED_JONAS_BY_ADMIN, [PROPOSAL_FINANCE, PROPOSAL_BY_OTHER])}
        canConfirm
        confirm={() => later({ kind: 'confirmed' as const, proposal: PROPOSAL_FINANCE })}
        reread={(s) => ({ ...s, proposals: page([PROPOSAL_BY_OTHER]) })}
      />,
    );
    const user = userEvent.setup();
    const probe = focusWhenInserted('Proposal confirmed: finance is now given to this person.');
    await user.click(screen.getAllByRole('button', { name: 'Confirm' })[0]!);
    await waitFor(() => expect(probe.first()).toBeDefined());
    probe.stop();
    expect(probe.first()).toBe(screen.getByRole('heading', { level: 2, name: 'Waiting for an owner' }));
  });
});

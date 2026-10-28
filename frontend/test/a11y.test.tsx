import { render, waitFor } from '@testing-library/react';
import axe from 'axe-core';
import { describe, expect, it } from 'vitest';
import { ComponentPreview } from '@/components/preview/ComponentPreview';
import { Checkbox } from '@/components/ui/Checkbox';
import { DateTime } from '@/components/ui/DateTime';
import { Dialog } from '@/components/ui/Dialog';
import { TooltipProvider } from '@/components/ui/Tooltip';
import { PersonLifecycleActions } from '@/components/people/PersonLifecycleActions';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { User } from '@/lib/api/users';
import type { DeletePersonResult, RestorePersonResult } from '@/lib/people/results';
import { BulkHarness } from './bulk/harness';
import { BULK_CONTENTION } from '@/components/preview/fixtures/bulk';
import { DELETE_REFUSALS, PERSON_ACTIVE, PERSON_DELETED_ACTIVE, PERSON_DELETED_SWITCHED_OFF, PERSON_SELF, RESTORE_REFUSALS, VIEWER_ID } from '@/components/preview/fixtures/people';

/** The injected action has answered and its result is rendered (no `aria-busy` left) — see b4-step3-progress.md "Flake". */
const settled = () => waitFor(() => expect(document.querySelector('[aria-busy="true"]')).toBeNull());


// axe on the component preview in jsdom. Two limits of jsdom, handled explicitly rather than by switching rules off:
//  - no layout or computed colours → colour-contrast is disabled here; contrast is computed from the tokens instead
//    (scripts/contrast.mjs, test/tokens.test.ts);
//  - no CSS media queries → all three navigation slots would be "visible" at once. The frame renders the 248 px
//    sidebar, the 64 px rail and the menu button and shows exactly one per width class with CSS. Each width class is
//    simulated by hiding the other slots, and axe runs once per class.
const CLASSES = { 'desktop ≥ 1280': 'sidebar', 'rail 768–1279': 'rail', 'menu < 768': 'menu' } as const;

describe('axe — component preview', () => {
  for (const [name, visible] of Object.entries(CLASSES)) {
    it(`has no violations at ${name}`, async () => {
      const { container } = render(<ComponentPreview />);
      const slots = container.querySelectorAll<HTMLElement>('[data-shell-slot]');
      expect(slots).toHaveLength(3);
      slots.forEach((el) => {
        el.style.display = el.dataset.shellSlot === visible ? '' : 'none';
      });
      const result = await axe.run(container, { rules: { 'color-contrast': { enabled: false } } });
      const summary = result.violations.map((v) => `${v.id}: ${v.nodes.length} × ${v.help}`);
      expect(summary).toEqual([]);
    });
  }
});

// B-4 step 1: the open Dialog is portalled out of the preview's container, so it gets its own axe run.
describe('axe — B-4 primitives', () => {
  it('an open Dialog with an unavailable Checkbox and a DateTime has no violations', async () => {
    const ref = { current: null as HTMLElement | null };
    render(
      <TooltipProvider>
        <h1 tabIndex={-1}>Page</h1>
        <Dialog open onOpenChange={() => undefined} title="Archive 3 items?" description={<p>You can restore them later.</p>} initialFocusRef={ref} fallbackFocusRef={ref}>
          <Checkbox label="Also archive lines" checked="mixed" onCheckedChange={() => undefined} unavailable describedBy="why" />
          <p id="why">Reason text.</p>
          <DateTime value="2026-10-22T06:05:00Z" form="date-time" timeZone="UTC" />
        </Dialog>
      </TooltipProvider>,
    );
    const result = await axe.run(document.body, { rules: { 'color-contrast': { enabled: false } } });
    expect(result.violations.map((v) => `${v.id}: ${v.nodes.length} × ${v.help}`)).toEqual([]);
  });
});

// B-4 step 2: the person surface in each main state, including open dialogs with a banner inside (portalled, so the
// whole document is checked).
describe('axe — B-4 person delete/restore', () => {
  const axeDoc = async () => {
    const result = await axe.run(document.body, { rules: { 'color-contrast': { enabled: false } } });
    return result.violations.map((v) => `${v.id}: ${v.nodes.length} × ${v.help}`);
  };
  const surface = (person: User, del: DeletePersonResult = DELETE_REFUSALS[0]!, res: RestorePersonResult = RESTORE_REFUSALS[0]!) => {
    const ref = { current: null as HTMLElement | null };
    return (
      <TooltipProvider>
        <main>
          <h1 tabIndex={-1}>{person.display_name}</h1>
          <PersonLifecycleActions person={person} viewerId={VIEWER_ID} canDelete canAssign onGiveRole={() => undefined} fallbackFocusRef={ref} timeZone="UTC" deletePerson={async () => del} restorePerson={async () => res} />
        </main>
      </TooltipProvider>
    );
  };

  for (const [label, person] of [['person page', PERSON_ACTIVE], ['own page', PERSON_SELF], ['archived page', PERSON_DELETED_ACTIVE]] as const) {
    it(`${label}: no violations`, async () => {
      render(surface(person));
      expect(await axeDoc()).toEqual([]);
    });
  }

  it('the Delete dialog with a refusal inside: no violations', async () => {
    render(surface(PERSON_ACTIVE));
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: 'Delete person' }));
    await user.click(screen.getAllByRole('button', { name: 'Delete person' }).at(-1)!);
    await settled();
    expect(await axeDoc()).toEqual([]);
  });

  it('the Restore dialog (switched off) with a 403 inside, and the after-message: no violations', async () => {
    render(surface(PERSON_DELETED_SWITCHED_OFF, { kind: 'deleted' }, { kind: 'forbidden' }));
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: 'Restore person' }));
    await user.click(screen.getAllByRole('button', { name: 'Restore person' }).at(-1)!);
    await settled();
    expect(await axeDoc()).toEqual([]);
  });

  it('after a restore of an active person (Give a role, Dismiss message): no violations', async () => {
    render(surface(PERSON_DELETED_ACTIVE, { kind: 'deleted' }, { kind: 'restored', person: { ...PERSON_DELETED_ACTIVE, archived_at: null } }));
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: 'Restore person' }));
    await user.click(screen.getAllByRole('button', { name: 'Restore person' }).at(-1)!);
    await settled();
    expect(screen.getByRole('button', { name: 'Give a role' })).toBeInTheDocument();
    expect(await axeDoc()).toEqual([]);
  });
});

// B-4 step 3: the bulk surface — the column, the bar (incl. an over-cap reason), the Delete dialog with a refusal inside,
// the banner zone (restore) and the after-message.
describe('axe — B-4 bulk', () => {
  const axeDoc = async () => {
    const result = await axe.run(document.body, { rules: { 'color-contrast': { enabled: false } } });
    return result.violations.map((v) => `${v.id}: ${v.nodes.length} × ${v.help}`);
  };
  const pick = async (user: ReturnType<typeof userEvent.setup>, n: number) => {
    for (const name of ['Sample Supplier A', 'Sample Supplier B', 'Sample Supplier C', 'Sample Supplier D'].slice(0, n)) {
      await user.click(screen.getByRole('checkbox', { name: `Select ${name}` }));
    }
  };

  it('the list with a selection over the cap: no violations', async () => {
    render(<BulkHarness run={async () => BULK_CONTENTION} maxMembers={3} />);
    const user = userEvent.setup();
    await pick(user, 4);
    expect(screen.getByText('You can delete up to 3 at a time.')).toBeInTheDocument();
    expect(await axeDoc()).toEqual([]);
  });

  it('the Delete dialog with a refusal inside: no violations', async () => {
    render(<BulkHarness run={async () => BULK_CONTENTION} maxMembers={10} />);
    const user = userEvent.setup();
    await pick(user, 2);
    await user.click(screen.getByRole('button', { name: 'Delete' }));
    await user.click(screen.getAllByRole('button', { name: 'Delete' }).at(-1)!);
    await settled();
    expect(screen.getByText('Not deleted')).toBeInTheDocument();
    expect(await axeDoc()).toEqual([]);
  });

  it('a restore refusal in the banner zone, then the after-message: no violations', async () => {
    const answers = [BULK_CONTENTION, { kind: 'done' as const, requested: 3, applied: 2 }];
    render(<BulkHarness run={async () => answers.shift()!} maxMembers={10} />);
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: 'Scope: archived' }));
    await user.click(screen.getByRole('checkbox', { name: 'Select every row on this page' }));
    await user.click(screen.getByRole('button', { name: 'Restore' }));
    await settled();
    expect(screen.getByText('Not restored')).toBeInTheDocument();
    expect(await axeDoc()).toEqual([]);
    await user.click(screen.getByRole('button', { name: 'Restore' }));
    await settled();
    expect(screen.getByRole('button', { name: 'Dismiss message' })).toBeInTheDocument();
    expect(await axeDoc()).toEqual([]);
  });
});

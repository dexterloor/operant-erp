import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { JONAS, OFFERED_JONAS_BY_ADMIN, PROPOSAL_FINANCE, ROLES, SAVE_REFUSALS, page } from '@/components/preview/fixtures/roles';
import { PICKER, WAITING } from '@/copy/roles';
import { textsOf } from '../wired/harness';
import { PickerHarness, scene } from './harness';

// FE-R9 (frontend-residuals.md): a sentence written again word for word with nothing written between is no change to the
// region, so a screen reader is given nothing. The mechanism is FE-R6 commit A's: the region is emptied at the press,
// after its guards, before the request. The recorder is `textsOf` (wired/harness.tsx): the initial text, then each change.

const settled = () => waitFor(() => expect(document.querySelector('[aria-busy="true"]')).toBeNull());
const box = (name: string) => screen.getByRole('checkbox', { name });
const pickerStatus = () => screen.getAllByRole('status').find((s) => s.closest('section')?.querySelector('h2')?.textContent === PICKER.rolesHeading)!;
const proposalsStatus = () => screen.getAllByRole('status').find((s) => s.closest('section') === null)!;
const saveButton = () => screen.getByRole('button', { name: PICKER.save });
const adminId = ROLES.find((r) => r.name === 'admin')!.id;

describe('RolePicker — a repeated save summary is written again (FE-R9)', () => {
  const SUMMARY = "Not given: admin. Each change that wasn't saved has a message with the reason.";

  it('a refused give, then Save again with the row still staged: the region empties at the second press', async () => {
    const user = userEvent.setup();
    const log: string[] = [];
    render(<PickerHarness scene={scene(JONAS, OFFERED_JONAS_BY_ADMIN)} log={log} assign={(id) => (id === adminId ? SAVE_REFUSALS.assignProposalPending : { kind: 'unreachable' })} />);
    await user.click(box('admin'));
    // The tick's own sentence (the staged change); the press must not leave it there for the summary to equal.
    const ticked = pickerStatus().textContent ?? '';
    const rec = textsOf(pickerStatus());
    await user.click(saveButton());
    await settled();
    await waitFor(() => expect(pickerStatus()).toHaveTextContent(SUMMARY));
    await user.click(saveButton());
    await settled();
    await waitFor(() => expect(rec.texts).toEqual([ticked, '', SUMMARY, '', SUMMARY].filter((t, i) => i > 0 || t !== '')));
    expect(log.filter((l) => l === 'assign:admin')).toHaveLength(2);
    rec.stop();
  });

  it('a Save press with nothing staged sends nothing and leaves the summary where it is', async () => {
    const user = userEvent.setup();
    const log: string[] = [];
    render(<PickerHarness scene={scene(JONAS, OFFERED_JONAS_BY_ADMIN)} log={log} reread={(s) => ({ ...s, person: { ...JONAS, roles: [{ id: adminId, name: 'admin' }] } })} />);
    await user.click(box('admin'));
    await user.click(saveButton());
    await settled();
    await waitFor(() => expect(pickerStatus().textContent).not.toBe(''));
    const said = pickerStatus().textContent;
    const rec = textsOf(pickerStatus());
    await user.click(saveButton());
    await settled();
    expect(log.filter((l) => l.startsWith('assign:'))).toHaveLength(1);
    expect(rec.texts).toEqual([said]);
    rec.stop();
  });
});

describe('RoleProposals — a repeated done sentence is written again (FE-R9)', () => {
  const REJECTED = WAITING.done.reject('finance');
  const AGAIN = { ...PROPOSAL_FINANCE, id: '00000000-0000-4000-8000-0000000000f2' };

  it('reject a proposal of finance, a new one of finance arrives on the re-read, reject it too: spoken both times', async () => {
    const user = userEvent.setup();
    const log: string[] = [];
    render(
      <PickerHarness
        scene={scene(JONAS, OFFERED_JONAS_BY_ADMIN, [PROPOSAL_FINANCE])}
        canConfirm
        reject={(id) => ({ kind: 'rejected', proposal: id === AGAIN.id ? AGAIN : PROPOSAL_FINANCE })}
        log={log}
        reread={(s, n) => ({ ...s, proposals: page(n === 1 ? [AGAIN] : []) })}
      />,
    );
    const rec = textsOf(proposalsStatus());
    const group = () => screen.getByRole('region', { name: WAITING.heading });
    await user.click(within(group()).getByRole('button', { name: WAITING.reject }));
    await settled();
    await waitFor(() => expect(proposalsStatus()).toHaveTextContent(REJECTED));
    await user.click(within(group()).getByRole('button', { name: WAITING.reject }));
    await settled();
    await waitFor(() => expect(rec.texts).toEqual(['', REJECTED, '', REJECTED]));
    expect(log.filter((l) => l.startsWith('reject:'))).toEqual([`reject:${PROPOSAL_FINANCE.id}`, `reject:${AGAIN.id}`]);
    rec.stop();
  });

  // The Withdraw twin (fe-r9-red.md L-2): withdraw runs the same line as reject; this pins it.
  it('withdraw a proposal of finance, a new one of finance arrives on the re-read, withdraw it too: spoken both times', async () => {
    const WITHDRAWN = WAITING.done.withdraw('finance');
    const user = userEvent.setup();
    const log: string[] = [];
    render(
      <PickerHarness
        scene={scene(JONAS, OFFERED_JONAS_BY_ADMIN, [PROPOSAL_FINANCE])}
        withdraw={(id) => ({ kind: 'withdrawn', proposal: id === AGAIN.id ? AGAIN : PROPOSAL_FINANCE })}
        log={log}
        reread={(s, n) => ({ ...s, proposals: page(n === 1 ? [AGAIN] : []) })}
      />,
    );
    const rec = textsOf(proposalsStatus());
    const group = () => screen.getByRole('region', { name: WAITING.heading });
    await user.click(within(group()).getByRole('button', { name: WAITING.withdraw }));
    await settled();
    await waitFor(() => expect(proposalsStatus()).toHaveTextContent(WITHDRAWN));
    await user.click(within(group()).getByRole('button', { name: WAITING.withdraw }));
    await settled();
    await waitFor(() => expect(rec.texts).toEqual(['', WITHDRAWN, '', WITHDRAWN]));
    expect(log.filter((l) => l.startsWith('withdraw:'))).toEqual([`withdraw:${PROPOSAL_FINANCE.id}`, `withdraw:${AGAIN.id}`]);
    rec.stop();
  });
});

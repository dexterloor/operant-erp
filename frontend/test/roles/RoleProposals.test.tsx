import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { JONAS, OFFERED_JONAS_BY_ADMIN, PROPOSAL_BY_OTHER, PROPOSAL_EXPIRED, PROPOSAL_FINANCE, PROPOSAL_REFUSALS, page } from '@/components/preview/fixtures/roles';
import { PICKER, WAITING } from '@/copy/roles';
import { PAGE_STATES } from '@/copy/shell';
import { PickerHarness, scene } from './harness';

const settled = () => waitFor(() => expect(document.querySelector('[aria-busy="true"]')).toBeNull());
const group = () => screen.getByRole('region', { name: WAITING.heading });
const rowOf = (roleName: string) => within(group()).getByText(roleName).closest('li')!;
const withProposals = (...items: (typeof PROPOSAL_FINANCE)[]) => scene(JONAS, OFFERED_JONAS_BY_ADMIN, items);

afterEach(() => vi.useRealTimers());

describe('RoleProposals — rows from served data only', () => {
  it('a live row: role, proposed by + date, Expires + date-time; heading and line; "{n} shown"', () => {
    render(<PickerHarness scene={withProposals(PROPOSAL_FINANCE)} />);
    expect(within(group()).getByText(WAITING.line)).toBeInTheDocument();
    const row = rowOf('finance');
    expect(row).toHaveTextContent('Proposed by Sample Admin on 22 Oct 2026');
    expect(row).toHaveTextContent('Expires 5 Nov 2026, 14:05');
    expect(within(group()).getByText('1 shown')).toBeInTheDocument();
  });

  it('an expired row (served is_expired): the RULED chip, "Expired {date, time}", Reject and Withdraw only', () => {
    render(<PickerHarness scene={withProposals(PROPOSAL_EXPIRED)} canConfirm />);
    const row = rowOf('purchasing_manager');
    expect(within(row).getByText(WAITING.expiredChip)).toBeInTheDocument();
    expect(row).toHaveTextContent('Expired 4 Oct 2026, 10:00');
    expect(within(row).queryByRole('button', { name: WAITING.confirm })).toBeNull();
    expect(within(row).getByRole('button', { name: WAITING.reject })).toBeInTheDocument();
    expect(within(row).getByRole('button', { name: WAITING.withdraw })).toBeInTheDocument();
  });

  it('no clock: a row past expires_at with is_expired false renders live, under a far-future system time (4c S-2)', () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2099-01-01T00:00:00Z'));
    render(<PickerHarness scene={withProposals(PROPOSAL_FINANCE)} canConfirm />);
    const row = rowOf('finance');
    expect(within(row).queryByText(WAITING.expiredChip)).toBeNull();
    expect(within(row).getByRole('button', { name: WAITING.confirm })).toBeInTheDocument();
  });

  it('Confirm/Reject only for an owner who manages roles; Withdraw for every viewer, enabled, even a non-proposer', () => {
    const { unmount } = render(<PickerHarness scene={withProposals(PROPOSAL_BY_OTHER)} />);
    const row = rowOf('warehouse_operator');
    expect(within(row).queryByRole('button', { name: WAITING.confirm })).toBeNull();
    expect(within(row).queryByRole('button', { name: WAITING.reject })).toBeNull();
    expect(within(row).getByRole('button', { name: WAITING.withdraw })).not.toHaveAttribute('aria-disabled');
    unmount();
    render(<PickerHarness scene={withProposals(PROPOSAL_BY_OTHER)} canConfirm />);
    expect(within(rowOf('warehouse_operator')).getByRole('button', { name: WAITING.confirm })).toBeInTheDocument();
  });

  it('never counted in the effective permissions', () => {
    render(<PickerHarness scene={withProposals(PROPOSAL_FINANCE)} />);
    expect(screen.getByText('17 permissions')).toBeInTheDocument();
  });

  it('no rows: the group is not rendered', () => {
    render(<PickerHarness scene={withProposals()} />);
    expect(screen.queryByRole('region', { name: WAITING.heading })).toBeNull();
  });
});

describe('RoleProposals — actions', () => {
  it('Withdraw by a non-proposer is refused after the click: "Not withdrawn" + the server sentence, focus to it', async () => {
    const user = userEvent.setup();
    render(<PickerHarness scene={withProposals(PROPOSAL_BY_OTHER)} withdraw={PROPOSAL_REFUSALS.notTheProposer} />);
    await user.click(within(rowOf('warehouse_operator')).getByRole('button', { name: WAITING.withdraw }));
    await settled();
    await waitFor(() => expect(screen.getByText('Not withdrawn')).toHaveFocus());
    expect(screen.getByText(PROPOSAL_REFUSALS.notTheProposer.message)).toBeInTheDocument();
  });

  it('Confirm succeeds → the page re-reads, the row leaves, focus goes to the page h1', async () => {
    const user = userEvent.setup();
    const log: string[] = [];
    render(
      <PickerHarness
        scene={withProposals(PROPOSAL_FINANCE)}
        canConfirm
        log={log}
        confirm={{ kind: 'confirmed', proposal: { ...PROPOSAL_FINANCE, status: 'confirmed' } }}
        reread={(s) => ({ ...s, proposals: page([]) })}
      />,
    );
    await user.click(within(rowOf('finance')).getByRole('button', { name: WAITING.confirm }));
    await settled();
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveFocus());
    expect(log).toEqual([`confirm:${PROPOSAL_FINANCE.id}`, 'reread']);
    expect(screen.queryByRole('region', { name: WAITING.heading })).toBeNull();
  });

  it('interrupted: the R1-1 heading and the try-again body; the banner shows even with no rows left', async () => {
    const user = userEvent.setup();
    render(<PickerHarness scene={withProposals(PROPOSAL_FINANCE)} canConfirm reject={{ kind: 'server-error' }} reread={(s) => ({ ...s, proposals: page([]) })} />);
    await user.click(within(rowOf('finance')).getByRole('button', { name: WAITING.reject }));
    await settled();
    await waitFor(() => expect(screen.getByText('Something went wrong on our side.')).toHaveFocus());
    expect(screen.getByText(WAITING.interrupted.reject)).toBeInTheDocument();
  });

  it('403: heading per action, the permission named in <em> (R1-5)', async () => {
    const user = userEvent.setup();
    render(<PickerHarness scene={withProposals(PROPOSAL_FINANCE)} withdraw={{ kind: 'forbidden' }} />);
    await user.click(within(rowOf('finance')).getByRole('button', { name: WAITING.withdraw }));
    await settled();
    await waitFor(() => expect(screen.getByText('Not withdrawn')).toHaveFocus());
    expect(screen.getAllByText('Give and take away roles').some((n) => n.tagName === 'EM')).toBe(true);
  });

  it('a repeated refusal after a commit: the server sentence as it reads (R1-6)', async () => {
    const user = userEvent.setup();
    render(<PickerHarness scene={withProposals(PROPOSAL_FINANCE)} canConfirm confirm={PROPOSAL_REFUSALS.alreadyConfirmed} />);
    await user.click(within(rowOf('finance')).getByRole('button', { name: WAITING.confirm }));
    await settled();
    await waitFor(() => expect(screen.getByText('Not confirmed')).toHaveFocus());
    expect(screen.getByText('This proposal has already been confirmed.')).toBeInTheDocument();
  });

  it('pager: Older / Newer only when served cursors exist; onPage(direction, cursor)', async () => {
    const user = userEvent.setup();
    const onPage = vi.fn();
    const s = { ...withProposals(PROPOSAL_FINANCE), proposals: page([PROPOSAL_FINANCE], 'c-next', null) };
    render(<PickerHarness scene={s} onPage={onPage} />);
    expect(within(group()).queryByRole('button', { name: WAITING.newer })).toBeNull();
    await user.click(within(group()).getByRole('button', { name: WAITING.older }));
    expect(onPage).toHaveBeenCalledWith('after', 'c-next');
  });
});

describe('RoleProposals — gaps fix P-4 / L-4 (b4-step5-gaps.md Rev 2 > Rev 1)', () => {
  const groupStatus = () => screen.getAllByRole('status').find((n) => !n.closest('[data-surface="role-picker"]'))!;

  it('the group region is mounted empty at the root, outside the rows branch (shown even with no rows)', () => {
    render(<PickerHarness scene={withProposals()} />);
    expect(groupStatus()).toHaveTextContent(/^$/);
  });

  it('Confirm with rows remaining: focus to the Waiting <h2>, never an action button; then "Proposal confirmed…"', async () => {
    const user = userEvent.setup();
    render(
      <PickerHarness
        scene={withProposals(PROPOSAL_FINANCE, PROPOSAL_BY_OTHER)}
        canConfirm
        confirm={{ kind: 'confirmed', proposal: { ...PROPOSAL_FINANCE, status: 'confirmed' } }}
        reread={(s) => ({ ...s, proposals: page([PROPOSAL_BY_OTHER]) })}
      />,
    );
    await user.click(within(rowOf('finance')).getByRole('button', { name: WAITING.confirm }));
    await settled();
    await waitFor(() => expect(screen.getByRole('heading', { level: 2, name: WAITING.heading })).toHaveFocus());
    expect(document.activeElement?.tagName).not.toBe('BUTTON');
    expect(groupStatus()).toHaveTextContent('Proposal confirmed: finance is now given to this person.');
  });

  it('L-4: a Confirm whose re-read turns margin_exposure true appends the held form; Reject never does', async () => {
    const user = userEvent.setup();
    const exposed = (s: ReturnType<typeof withProposals>) => ({ ...s, person: { ...s.person, margin_exposure: true }, proposals: page([PROPOSAL_BY_OTHER]) });
    const { unmount } = render(
      <PickerHarness scene={withProposals(PROPOSAL_FINANCE, PROPOSAL_BY_OTHER)} canConfirm confirm={{ kind: 'confirmed', proposal: PROPOSAL_FINANCE }} reread={exposed} />,
    );
    await user.click(within(rowOf('finance')).getByRole('button', { name: WAITING.confirm }));
    await settled();
    await waitFor(() => expect(groupStatus()).toHaveTextContent(`Proposal confirmed: finance is now given to this person. ${PICKER.marginHeld}`));
    unmount();
    render(<PickerHarness scene={withProposals(PROPOSAL_FINANCE, PROPOSAL_BY_OTHER)} canConfirm reject={{ kind: 'rejected', proposal: PROPOSAL_FINANCE }} reread={exposed} />);
    await user.click(within(rowOf('finance')).getByRole('button', { name: WAITING.reject }));
    await settled();
    await waitFor(() => expect(groupStatus()).toHaveTextContent('Proposal rejected: finance.'));
    expect(groupStatus().textContent).not.toContain(PICKER.marginHeld);
  });

  it('a group action answered 404: "Not withdrawn" with ST-5\'s RULED words as one copy key', async () => {
    const user = userEvent.setup();
    render(<PickerHarness scene={withProposals(PROPOSAL_BY_OTHER)} withdraw={{ kind: 'not-found' }} />);
    await user.click(within(rowOf('warehouse_operator')).getByRole('button', { name: WAITING.withdraw }));
    await settled();
    expect(await screen.findByText(PAGE_STATES.notFoundInline)).toBeInTheDocument();
    expect(screen.getByText('Not withdrawn')).toBeInTheDocument();
  });

  it.each(['forbidden', 'not-found'] as const)('A-10: a re-read that fails %s is the page\'s — no message, no stale sentence from the group', async (failure) => {
    const user = userEvent.setup();
    render(<PickerHarness scene={withProposals(PROPOSAL_BY_OTHER)} withdraw={{ kind: 'withdrawn', proposal: PROPOSAL_BY_OTHER }} reread={() => ({ kind: 'failed', failure })} />);
    await user.click(within(rowOf('warehouse_operator')).getByRole('button', { name: WAITING.withdraw }));
    await settled();
    await settled();
    expect(groupStatus()).toHaveTextContent('');
    expect(screen.queryByText(WAITING.listStale)).toBeNull();
  });

  it('success + failed re-read: the message and the stale sentence; focus left on the opener; no held form', async () => {
    const user = userEvent.setup();
    render(<PickerHarness scene={withProposals(PROPOSAL_BY_OTHER)} withdraw={{ kind: 'withdrawn', proposal: PROPOSAL_BY_OTHER }} reread={() => ({ kind: 'failed', failure: 'unreachable' })} />);
    const opener = within(rowOf('warehouse_operator')).getByRole('button', { name: WAITING.withdraw });
    await user.click(opener);
    await settled();
    await waitFor(() => expect(groupStatus()).toHaveTextContent(`Proposal withdrawn: warehouse_operator. ${WAITING.listStale}`));
    expect(opener).toHaveFocus();
  });

  it('Confirm + failed re-read: the long form "Proposal confirmed: {role} is now given to this person." then the stale sentence, exactly; focus on the opener (ux-confirm-b4.md §2.5)', async () => {
    const user = userEvent.setup();
    render(
      <PickerHarness
        scene={withProposals(PROPOSAL_FINANCE, PROPOSAL_BY_OTHER)}
        canConfirm
        confirm={{ kind: 'confirmed', proposal: PROPOSAL_FINANCE }}
        reread={() => ({ kind: 'failed', failure: 'server-error' })}
      />,
    );
    const opener = within(rowOf('finance')).getByRole('button', { name: WAITING.confirm });
    await user.click(opener);
    await settled();
    await waitFor(() => expect(groupStatus().textContent).toBe(`Proposal confirmed: finance is now given to this person. ${WAITING.listStale}`));
    expect(opener).toHaveFocus();
  });

  it('L-4 (c): margin_exposure already true when Confirm was pressed — the held form is NOT appended', async () => {
    const user = userEvent.setup();
    const start = withProposals(PROPOSAL_FINANCE, PROPOSAL_BY_OTHER);
    const exposed = { ...start, person: { ...start.person, margin_exposure: true } };
    render(
      <PickerHarness
        scene={exposed}
        canConfirm
        confirm={{ kind: 'confirmed', proposal: PROPOSAL_FINANCE }}
        reread={(s) => ({ ...s, proposals: page([PROPOSAL_BY_OTHER]) })}
      />,
    );
    await user.click(within(rowOf('finance')).getByRole('button', { name: WAITING.confirm }));
    await settled();
    await waitFor(() => expect(groupStatus().textContent).toBe('Proposal confirmed: finance is now given to this person.'));
  });
});

// R-15 (r15-red.md > r15-recommendation.md §2.2, §3; the ruling: Reject and Withdraw DO report): a Confirm, Reject or
// Withdraw answered 2xx whose re-read was shown is reported once, after its message is on screen.
describe('RoleProposals — R-15, the report after an action', () => {
  const groupStatus = () => screen.getAllByRole('status').find((n) => !n.closest('[data-surface="role-picker"]'))!;
  const quiet = () => new Promise((r) => setTimeout(r, 30));
  const reportSpy = () => {
    const seen: string[] = [];
    const fn = vi.fn(() => {
      seen.push(groupStatus().textContent ?? '');
    });
    return { fn, seen };
  };
  const answers = {
    confirm: { kind: 'confirmed', proposal: { ...PROPOSAL_FINANCE, status: 'confirmed' } },
    reject: { kind: 'rejected', proposal: { ...PROPOSAL_FINANCE, status: 'rejected' } },
    withdraw: { kind: 'withdrawn', proposal: { ...PROPOSAL_FINANCE, status: 'withdrawn' } },
  } as const;
  const label = { confirm: WAITING.confirm, reject: WAITING.reject, withdraw: WAITING.withdraw } as const;

  it.each(['confirm', 'reject', 'withdraw'] as const)('T-11: %s 200 with the re-read ok → exactly once, after WAITING.done', async (act) => {
    const user = userEvent.setup();
    const r = reportSpy();
    render(
      <PickerHarness
        scene={withProposals(PROPOSAL_FINANCE, PROPOSAL_BY_OTHER)}
        canConfirm
        onProposalChanged={r.fn}
        {...{ [act]: answers[act] }}
        reread={(s) => ({ ...s, proposals: page([PROPOSAL_BY_OTHER]) })}
      />,
    );
    await user.click(within(rowOf('finance')).getByRole('button', { name: label[act] }));
    await settled();
    await waitFor(() => expect(r.fn).toHaveBeenCalled());
    await quiet();
    expect(r.fn).toHaveBeenCalledTimes(1);
    expect(r.seen).toEqual([WAITING.done[act]('finance')]);
  });

  it('T-11: Confirm refused → 0; Confirm 200 with the re-read failed → 0; Confirm 200 with the re-read taken over → 0', async () => {
    const user = userEvent.setup();
    const r = reportSpy();
    const press = async () => {
      await user.click(within(rowOf('finance')).getByRole('button', { name: WAITING.confirm }));
      await settled();
    };
    let v = render(<PickerHarness scene={withProposals(PROPOSAL_FINANCE)} canConfirm onProposalChanged={r.fn} confirm={PROPOSAL_REFUSALS.alreadyConfirmed} />);
    await press();
    await waitFor(() => expect(screen.getByText('Not confirmed')).toHaveFocus());
    await quiet();
    v.unmount();
    v = render(<PickerHarness scene={withProposals(PROPOSAL_FINANCE)} canConfirm onProposalChanged={r.fn} confirm={answers.confirm} reread={() => ({ kind: 'failed', failure: 'unreachable' })} />);
    await press();
    await waitFor(() => expect(groupStatus()).toHaveTextContent(WAITING.listStale));
    await quiet();
    v.unmount();
    for (const failure of ['forbidden', 'not-found'] as const) {
      v = render(<PickerHarness scene={withProposals(PROPOSAL_FINANCE)} canConfirm onProposalChanged={r.fn} confirm={answers.confirm} reread={() => ({ kind: 'failed', failure })} />);
      await press();
      await quiet();
      v.unmount();
    }
    expect(r.fn).not.toHaveBeenCalled();
  });
});

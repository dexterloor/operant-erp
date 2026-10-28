import { StrictMode } from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
  DELETED_PERSON,
  HELD_PAIR_PERSON,
  INACTIVE_PERSON,
  JONAS,
  OFFERED_HELD_PAIR_BY_ADMIN,
  OFFERED_JONAS_BY_ADMIN,
  OFFERED_JONAS_BY_OWNER,
  OFFERED_SELF_ADMIN,
  PROPOSAL_FINANCE,
  ROLES,
  SAVE_REFUSALS,
  SELF_ADMIN,
  VIEWER_ID,
} from '@/components/preview/fixtures/roles';
import type { User } from '@/lib/api/users';
import { PICKER, PICKER_BANNERS, PICKER_STATUS } from '@/copy/roles';
import { PickerHarness, scene } from './harness';

const settled = () => waitFor(() => expect(document.querySelector('[aria-busy="true"]')).toBeNull());
const box = (name: string) => screen.getByRole('checkbox', { name });
const status = () => screen.getAllByRole('status').find((s) => s.closest('section')?.querySelector('h2')?.textContent === PICKER.rolesHeading)!;
const effective = () => screen.getByRole('region', { name: PICKER.effectiveHeading });
const saveButton = () => screen.getByRole('button', { name: PICKER.save });
const rowOf = (name: string) => box(name).closest('li')!;
const roleRef = (name: string) => {
  const r = ROLES.find((x) => x.name === name)!;
  return { id: r.id, name: r.name };
};
const jonas = () => scene(JONAS, OFFERED_JONAS_BY_ADMIN);
const holding = (person: User, ...names: string[]): User => ({ ...person, roles: names.map(roleRef) });

async function save(user: ReturnType<typeof userEvent.setup>) {
  await user.click(saveButton());
  await settled();
}

afterEach(() => vi.restoreAllMocks());

describe('RolePicker — F-7, the six steps as served', () => {
  it('step 1: buyer is held, ticked and enabled; warehouse_operator is refused with its SoD-2 line and no tag', () => {
    render(<PickerHarness scene={jonas()} />);
    expect(box('buyer')).toBeChecked();
    expect(box('buyer')).not.toHaveAttribute('aria-disabled');
    const wo = box('warehouse_operator');
    expect(wo).toHaveAttribute('aria-disabled', 'true');
    expect(wo).toHaveAccessibleDescription('Conflicts with buyer: one person could then raise or approve purchase orders and also record the goods as received (SoD-2).');
    expect(within(rowOf('warehouse_operator')).queryByText(PICKER.confirmationTag)).toBeNull();
    expect(status()).toHaveTextContent(/^$/);
  });

  it('step 2: ticking finance stages a proposal, disables sales_rep and admin with their lines, adds warehouse_operator\'s SoD-5 line, announces once, warns on the panel, and leaves the preview alone', async () => {
    const user = userEvent.setup();
    render(<PickerHarness scene={jonas()} />);
    expect(within(effective()).getByText('17 permissions')).toBeInTheDocument();
    await user.click(box('finance'));
    expect(within(rowOf('finance')).getByText(PICKER.willBeProposed)).toBeInTheDocument();
    expect(within(rowOf('finance')).getByText(PICKER.confirmationTag)).toBeInTheDocument();
    expect(box('sales_rep')).toHaveAttribute('aria-disabled', 'true');
    expect(box('sales_rep')).toHaveAccessibleDescription('Conflicts with finance: one person could then issue invoices and also record their payment, void them or credit them (SoD-3).');
    expect(box('admin')).toHaveAccessibleDescription(
      'Conflicts with finance: one person could then issue invoices and also record their payment, void them or credit them (SoD-3). Conflicts with finance: one person could then record goods as returned and also credit the invoice for them (SoD-5).',
    );
    const woLines = within(rowOf('warehouse_operator')).getAllByText(/^Conflicts with/).map((p) => p.textContent);
    expect(woLines).toEqual([
      'Conflicts with buyer: one person could then raise or approve purchase orders and also record the goods as received (SoD-2).',
      'Conflicts with finance: one person could then record goods as returned and also credit the invoice for them (SoD-5).',
    ]);
    expect(status()).toHaveTextContent(
      "admin and sales_rep can't be given now, because each conflicts with finance. With finance as well, this person could see both what goods are bought for and what they are sold for.",
    );
    expect(within(effective()).getByText(PICKER.marginPair('finance'))).toBeInTheDocument();
    expect(within(effective()).getByText('17 permissions')).toBeInTheDocument();
    expect(within(effective()).queryByText(PICKER.addedFlag)).toBeNull();
    expect(within(rowOf('warehouse_operator')).queryByText(PICKER.willBeGiven)).toBeNull();
  });

  it('step 3: unticking finance clears the new refusals, lines and warning; warehouse_operator keeps SoD-2', async () => {
    const user = userEvent.setup();
    render(<PickerHarness scene={jonas()} />);
    await user.click(box('finance'));
    await user.click(box('finance'));
    expect(box('sales_rep')).not.toHaveAttribute('aria-disabled');
    expect(box('admin')).not.toHaveAttribute('aria-disabled');
    expect(within(rowOf('warehouse_operator')).getAllByText(/^Conflicts with/)).toHaveLength(1);
    expect(within(effective()).queryByText(PICKER.marginPair('finance'))).toBeNull();
    expect(status()).toHaveTextContent('admin and sales_rep can be given again.');
  });

  it('step 4: saving the proposal — summary, the row drawn as served (no pill), the proposal only in the Waiting group, the pair warning gone', async () => {
    const user = userEvent.setup();
    render(
      <PickerHarness
        scene={jonas()}
        propose={{ kind: 'proposed', proposal: PROPOSAL_FINANCE }}
        reread={(s) => ({ ...s, proposals: { ...s.proposals, items: [PROPOSAL_FINANCE] } })}
      />,
    );
    await user.click(box('finance'));
    await save(user);
    expect(status()).toHaveTextContent('Proposed, waiting for an owner: finance.');
    expect(box('finance')).not.toBeChecked();
    expect(within(rowOf('finance')).queryByText(/Proposed|Will be proposed|Expires/)).toBeNull();
    expect(within(rowOf('finance')).getByText(PICKER.confirmationTag)).toBeInTheDocument();
    const group = screen.getByRole('region', { name: 'Waiting for an owner' });
    expect(within(group).getByText('finance')).toBeInTheDocument();
    expect(within(group).getByText(/^Expires/)).toBeInTheDocument();
    expect(within(effective()).queryByText(PICKER.marginPair('finance'))).toBeNull();
    await waitFor(() => expect(saveButton()).toHaveFocus());
  });

  it('step 5: after an owner confirms and the page re-reads, finance counts and the held warning shows', () => {
    render(<PickerHarness scene={scene({ ...holding(JONAS, 'buyer', 'finance'), margin_exposure: true }, OFFERED_JONAS_BY_ADMIN)} />);
    expect(box('finance')).toBeChecked();
    expect(within(effective()).getByText(PICKER.marginHeld)).toBeInTheDocument();
  });

  it('step 6: a held conflicting pair — both ticked with their reasons; Save aria-disabled with its reason; unticking one re-enables Save', async () => {
    const user = userEvent.setup();
    render(<PickerHarness scene={scene(HELD_PAIR_PERSON, OFFERED_HELD_PAIR_BY_ADMIN)} />);
    expect(box('finance')).toBeChecked();
    expect(box('sales_rep')).toBeChecked();
    expect(box('finance')).toHaveAccessibleDescription('Conflicts with sales_rep: one person could then issue invoices and also record their payment, void them or credit them (SoD-3).');
    expect(box('sales_rep')).toHaveAccessibleDescription('Conflicts with finance: one person could then issue invoices and also record their payment, void them or credit them (SoD-3).');
    expect(saveButton()).toHaveAttribute('aria-disabled', 'true');
    expect(saveButton()).toHaveAccessibleDescription(PICKER.saveBlocked);
    const saveNode = saveButton();
    await user.click(box('finance'));
    expect(within(rowOf('finance')).getByText(PICKER.willBeTakenAway)).toBeInTheDocument();
    expect(saveButton()).not.toHaveAttribute('aria-disabled');
    expect(saveButton()).toBe(saveNode);
  });
});

describe('RolePicker — served refusals and page-level cases', () => {
  it('owner-only and the ceiling (custom role only) show their reasons; the ceiling has its help line', () => {
    render(<PickerHarness scene={jonas()} />);
    expect(box('owner')).toHaveAccessibleDescription('Only an owner can give someone this role, because it makes them an owner.');
    expect(box('Stock controller')).toHaveAttribute('aria-disabled', 'true');
    expect(within(rowOf('Stock controller')).getByText("This role includes permissions you don't have yourself, so you can't give it to anyone.")).toBeInTheDocument();
    expect(within(rowOf('Stock controller')).getByText('Ask an owner, or someone who holds all of its permissions.')).toBeInTheDocument();
    expect(box('buyer')).not.toHaveAttribute('aria-disabled');
  });

  it('a refused role is not tickable (click and Space change nothing)', async () => {
    const user = userEvent.setup();
    render(<PickerHarness scene={jonas()} />);
    await user.click(box('warehouse_operator'));
    box('owner').focus();
    await user.keyboard(' ');
    expect(box('warehouse_operator')).not.toBeChecked();
    expect(box('owner')).not.toBeChecked();
    expect(screen.queryByText(PICKER.willBeGiven)).toBeNull();
  });

  it('an owner sees no confirmation tags and can stage owner directly', async () => {
    const user = userEvent.setup();
    render(<PickerHarness scene={scene(JONAS, OFFERED_JONAS_BY_OWNER)} />);
    expect(screen.queryByText(PICKER.confirmationTag)).toBeNull();
    await user.click(box('finance'));
    expect(within(rowOf('finance')).getByText(PICKER.willBeGiven)).toBeInTheDocument();
  });

  it('a served refusal code with no copy: aria-disabled with the neutral reason, a dev warning, never the code', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const offered = OFFERED_JONAS_BY_ADMIN.map((r) => (r.name === 'viewer' ? { ...r, refusal: 'user_role.something_new' } : r));
    render(<PickerHarness scene={scene(JONAS, offered)} />);
    expect(box('viewer')).toHaveAttribute('aria-disabled', 'true');
    expect(box('viewer')).toHaveAccessibleDescription(PICKER.unknownRefusal);
    expect(document.body.textContent).not.toContain('something_new');
    expect(warn.mock.calls.some((c) => String(c[0]).includes('user_role.something_new'))).toBe(true);
  });

  it('an SoD id with no clause still refuses and still draws its line, with the fallback wording (b4-step4-gaps Rev 2)', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const offered = OFFERED_JONAS_BY_ADMIN.map((r) => ({ ...r, conflicts_with: r.conflicts_with.map((c) => ({ ...c, sod_row: c.sod_row === 'SoD-2' ? 'SoD-9' : c.sod_row })) }));
    render(<PickerHarness scene={scene(JONAS, offered)} />);
    expect(box('warehouse_operator')).toHaveAttribute('aria-disabled', 'true');
    expect(box('warehouse_operator')).toHaveAccessibleDescription('Conflicts with buyer: together they would complete a separation-of-duty split (SoD-9).');
    expect(warn.mock.calls.some((c) => String(c[0]).includes('SoD-9'))).toBe(true);
  });

  it('own page: every option and Save aria-disabled, each described by the one own-page line; no proposals group', () => {
    render(<PickerHarness scene={scene(SELF_ADMIN, OFFERED_SELF_ADMIN)} viewerId={VIEWER_ID} showProposals={false} />);
    expect(screen.getByText(PICKER.ownPage)).toBeInTheDocument();
    for (const b of screen.getAllByRole('checkbox')) {
      expect(b).toHaveAttribute('aria-disabled', 'true');
      expect(b.getAttribute('aria-describedby')).toContain(screen.getByText(PICKER.ownPage).id);
    }
    expect(saveButton()).toHaveAttribute('aria-disabled', 'true');
    expect(saveButton()).toHaveAccessibleDescription(PICKER.ownPage);
  });

  it('not rendered for a deleted person; an inactive person gets the banner only', () => {
    const { unmount } = render(<PickerHarness scene={scene(DELETED_PERSON, OFFERED_JONAS_BY_ADMIN)} />);
    expect(screen.queryByRole('checkbox')).toBeNull();
    expect(screen.queryByText(PICKER.inactiveBanner)).toBeNull();
    unmount();
    render(<PickerHarness scene={scene(INACTIVE_PERSON, OFFERED_JONAS_BY_ADMIN)} />);
    expect(screen.queryByRole('checkbox')).toBeNull();
    expect(screen.getByText(PICKER.inactiveBanner)).toBeInTheDocument();
  });
});

describe('RolePicker — effective permissions and the margin warning', () => {
  it('a direct give changes the preview with Added flags; a take-away strikes with Taken away', async () => {
    const user = userEvent.setup();
    render(<PickerHarness scene={jonas()} />);
    expect(within(effective()).getByText('17 permissions')).toBeInTheDocument();
    await user.click(box('viewer'));
    expect(within(effective()).getByText(/^\d+ permissions after saving: \d+ added\.$/)).toBeInTheDocument();
    expect(within(effective()).getAllByText(PICKER.addedFlag).length).toBeGreaterThan(0);
    await user.click(box('viewer'));
    await user.click(box('buyer'));
    expect(within(effective()).getByText('0 permissions after saving: 17 taken away.')).toBeInTheDocument();
    expect(within(effective()).getAllByText(PICKER.takenAwayFlag)).toHaveLength(17);
  });

  it('a role.assign holder without role.view: the warning sits above "aren\'t shown"; no summary, no list', async () => {
    const user = userEvent.setup();
    render(<PickerHarness scene={jonas()} roleView={false} />);
    await user.click(box('finance'));
    const panel = effective();
    const texts = [...panel.querySelectorAll('p')].map((p) => p.textContent);
    expect(texts).toContain(PICKER.marginPair('finance'));
    expect(texts).toContain(PICKER.permissionsHidden);
    expect(texts.indexOf(PICKER.marginPair('finance'))).toBeLessThan(texts.indexOf(PICKER.permissionsHidden));
    expect(within(panel).queryByText(/permissions after saving|^\d+ permissions?$/)).toBeNull();
    expect(within(panel).queryByRole('list')).toBeNull();
  });

  it('the margin warning is never on a role option and never disables anything', async () => {
    const user = userEvent.setup();
    render(<PickerHarness scene={jonas()} />);
    await user.click(box('finance'));
    expect(within(rowOf('finance')).queryByText(PICKER.marginPair('finance'))).toBeNull();
    expect(saveButton()).not.toHaveAttribute('aria-disabled');
  });

  it('when the re-read makes the held form appear, its sentence is appended to the save summary (4b-2)', async () => {
    const user = userEvent.setup();
    render(
      <PickerHarness
        scene={scene(JONAS, OFFERED_JONAS_BY_OWNER)}
        reread={(s) => ({ ...s, person: { ...holding(JONAS, 'buyer', 'finance'), margin_exposure: true } })}
      />,
    );
    await user.click(box('finance'));
    await save(user);
    expect(status()).toHaveTextContent(`Given: finance. ${PICKER.marginHeld}`);
  });
});

describe('RolePicker — Save roles', () => {
  it('Q-2: a take-away and the give it unblocks are sent revoke → assign, one after the other', async () => {
    const user = userEvent.setup();
    const log: string[] = [];
    render(<PickerHarness scene={jonas()} log={log} />);
    await user.click(box('buyer'));
    expect(box('warehouse_operator')).not.toHaveAttribute('aria-disabled');
    await user.click(box('warehouse_operator'));
    await save(user);
    expect(log).toEqual(['revoke:buyer', 'assign:warehouse_operator', 'reread']);
  });

  it('every request succeeds: Given/Taken away pills, focus stays on the SAME Save roles node, the summary is announced', async () => {
    const user = userEvent.setup();
    render(<PickerHarness scene={jonas()} reread={(s) => ({ ...s, person: holding(JONAS, 'viewer') })} />);
    await user.click(box('buyer'));
    await user.click(box('viewer'));
    const node = saveButton();
    await save(user);
    await waitFor(() => expect(document.activeElement).toBe(node));
    expect(saveButton()).toBe(node);
    expect(within(rowOf('viewer')).getByText(PICKER.given)).toBeInTheDocument();
    expect(within(rowOf('buyer')).getByText(PICKER.takenAway)).toBeInTheDocument();
    expect(status()).toHaveTextContent('Given: viewer. Taken away: buyer.');
    await user.click(box('admin'));
    expect(within(rowOf('viewer')).queryByText(PICKER.given)).toBeNull(); // pills clear on the next tick
  });

  it('a refusal: banner "Not given: {role}" with the server sentence, focus to its heading, the row stays staged', async () => {
    const user = userEvent.setup();
    render(<PickerHarness scene={jonas()} assign={(id) => (id === roleRef('admin').id ? SAVE_REFUSALS.assignProposalPending : { kind: 'given', role: ROLES.find((r) => r.id === id)! })} />);
    await user.click(box('admin'));
    await user.click(box('viewer'));
    await save(user);
    const heading = screen.getByText('Not given: admin');
    await waitFor(() => expect(heading).toHaveFocus());
    expect(screen.getByText(SAVE_REFUSALS.assignProposalPending.message)).toBeInTheDocument();
    expect(within(rowOf('admin')).getByText(PICKER.willBeGiven)).toBeInTheDocument();
    expect(status()).toHaveTextContent("Given: viewer. Not given: admin. Each change that wasn't saved has a message with the reason.");
  });

  it('a take-away that was already gone (404): "Not taken away: {role}" with the client sentence (R1-6)', async () => {
    const user = userEvent.setup();
    render(<PickerHarness scene={jonas()} revoke={{ kind: 'not-found' }} />);
    await user.click(box('buyer'));
    await save(user);
    await waitFor(() => expect(screen.getByText('Not taken away: buyer')).toHaveFocus());
    expect(screen.getByText(PICKER_BANNERS.nothingToTakeAway)).toBeInTheDocument();
  });

  it('A-2: a 201 then a failed re-read — the row is not staged, has its Given pill, and Save has nothing to re-send (case D)', async () => {
    const user = userEvent.setup();
    const log: string[] = [];
    render(<PickerHarness scene={jonas()} log={log} reread={() => ({ kind: 'failed', failure: 'unreachable' })} />);
    await user.click(box('viewer'));
    await save(user);
    expect(within(rowOf('viewer')).queryByText(PICKER.willBeGiven)).toBeNull();
    expect(within(rowOf('viewer')).getByText(PICKER.given)).toBeInTheDocument();
    await waitFor(() => expect(screen.getByText("We couldn't reach the server.")).toHaveFocus());
    expect(screen.getByText(PICKER_BANNERS.savedButStale)).toBeInTheDocument();
    await user.click(saveButton());
    await settled();
    expect(log.filter((l) => l.startsWith('assign'))).toEqual(['assign:viewer']);
  });

  it('interrupted, re-read OK (case A): "Not saved: {n} changes", the failure as first sentence, focus to it', async () => {
    const user = userEvent.setup();
    const log: string[] = [];
    render(<PickerHarness scene={jonas()} log={log} assign={{ kind: 'unreachable' }} />);
    await user.click(box('admin'));
    await user.click(box('viewer'));
    await save(user);
    expect(log).toEqual(['assign:admin', 'reread']);
    const heading = screen.getByText('Not saved: 2 changes');
    await waitFor(() => expect(heading).toHaveFocus());
    expect(screen.getByText(`We couldn't reach the server. ${PICKER_BANNERS.stillMarked.other(2)}`)).toBeInTheDocument();
    expect(status()).toHaveTextContent("The save didn't finish. A message says what wasn't saved.");
  });

  it('case A counts only what THAT save left undecided (b4-final-red M-2): a later tick never joins it, and an unticked row does not come back', async () => {
    const user = userEvent.setup();
    render(<PickerHarness scene={jonas()} assign={{ kind: 'unreachable' }} />);
    await user.click(box('viewer'));
    await save(user);
    await waitFor(() => expect(screen.getByText('Not saved: 1 change')).toHaveFocus());
    await user.click(box('admin'));
    expect(screen.getByText('Not saved: 1 change')).toBeInTheDocument();
    expect(screen.queryByText('Not saved: 2 changes')).toBeNull();
    expect(screen.getByText(`We couldn't reach the server. ${PICKER_BANNERS.stillMarked.one(1)}`)).toBeInTheDocument();
    await user.click(box('viewer'));
    expect(screen.queryByText(/^Not saved/)).toBeNull();
    await user.click(box('admin'));
    await user.click(box('admin'));
    expect(screen.queryByText(/^Not saved/)).toBeNull();
  });

  it('interrupted on the last request that in fact committed (case B): no banner, "All your changes are now saved.", focus on Save', async () => {
    const user = userEvent.setup();
    render(<PickerHarness scene={jonas()} assign={{ kind: 'server-error' }} reread={(s) => ({ ...s, person: holding(JONAS, 'buyer', 'viewer') })} />);
    await user.click(box('viewer'));
    await save(user);
    await waitFor(() => expect(saveButton()).toHaveFocus());
    expect(screen.queryByText(/^Not saved/)).toBeNull();
    expect(status()).toHaveTextContent('All your changes are now saved.');
  });

  it('(e) interrupted AND the re-read fails (case C): what is known, Load roles again; the proposal intent stays; reloading OK replaces it with case A', async () => {
    const user = userEvent.setup();
    let fail = true;
    render(
      <PickerHarness
        scene={jonas()}
        assign={{ kind: 'given', role: ROLES.find((r) => r.name === 'viewer')! }}
        propose={{ kind: 'unreachable' }}
        reread={(s) => (fail ? { kind: 'failed', failure: 'unreachable' } : s)}
      />,
    );
    await user.click(box('viewer'));
    await user.click(box('purchasing_manager'));
    await save(user);
    await waitFor(() => expect(screen.getByText("We couldn't reach the server.")).toHaveFocus());
    expect(screen.getByText(PICKER_BANNERS.rereadFailedLead)).toBeInTheDocument();
    expect(screen.getByText('Given: viewer.')).toBeInTheDocument();
    expect(screen.getByText('We can\'t tell whether this went through: proposing purchasing_manager.')).toBeInTheDocument();
    expect(within(rowOf('purchasing_manager')).getByText(PICKER.willBeProposed)).toBeInTheDocument();
    const again = screen.getByRole('button', { name: PICKER_BANNERS.loadAgain });
    await user.click(again);
    await settled();
    expect(again).toHaveFocus(); // still failing: unchanged, focus stays
    fail = false;
    await user.click(again);
    await settled();
    await waitFor(() => expect(screen.getByText('Not saved: 1 change')).toHaveFocus());
  });

  it('A-4: a 403 stops the save — one R1-5 banner naming the permission; nothing more is sent', async () => {
    const user = userEvent.setup();
    const log: string[] = [];
    render(<PickerHarness scene={jonas()} log={log} revoke={{ kind: 'forbidden' }} />);
    await user.click(box('buyer'));
    await user.click(box('viewer'));
    await save(user);
    expect(log).toEqual(['revoke:buyer', 'reread']);
    const heading = screen.getByText('Not saved: 2 changes');
    await waitFor(() => expect(heading).toHaveFocus());
    expect(screen.getByText('Give and take away roles').tagName).toBe('EM');
    expect(screen.queryByText("We couldn't reach the server.")).toBeNull();
  });

  it('re-ticking a pending role as an owner is refused after the click, with the server sentence', async () => {
    const user = userEvent.setup();
    render(<PickerHarness scene={scene(JONAS, OFFERED_JONAS_BY_OWNER, [PROPOSAL_FINANCE])} canConfirm assign={SAVE_REFUSALS.assignProposalPending} />);
    await user.click(box('finance'));
    expect(within(rowOf('finance')).getByText(PICKER.willBeGiven)).toBeInTheDocument();
    await save(user);
    await waitFor(() => expect(screen.getByText('Not given: finance')).toHaveFocus());
  });

  it('a thrown action is a lost connection', async () => {
    const user = userEvent.setup();
    render(<PickerHarness scene={jonas()} assign="throw" />);
    await user.click(box('viewer'));
    await save(user);
    await waitFor(() => expect(screen.getByText('Not saved: 1 change')).toHaveFocus());
  });

  it('a new props object (re-read) keeps the same Save roles node and focus (M-4)', () => {
    const { rerender } = render(<PickerHarness scene={jonas()} />);
    saveButton().focus();
    const node = document.activeElement;
    rerender(<PickerHarness scene={{ ...jonas(), person: { ...JONAS } }} />);
    expect(document.activeElement).toBe(node);
    expect(saveButton()).toBe(node);
  });

  it('StrictMode: one announcement per tick, the same status node', async () => {
    const user = userEvent.setup();
    render(
      <StrictMode>
        <PickerHarness scene={jonas()} />
      </StrictMode>,
    );
    const node = status();
    await user.click(box('finance'));
    expect(status()).toBe(node);
    expect(node.textContent).toMatch(/^admin and sales_rep can't be given now/);
  });
});

describe('RolePicker — gaps fix (b4-step5-gaps.md Rev 2 > Rev 1)', () => {
  const adminRefused = (id: string) => (id === roleRef('admin').id ? SAVE_REFUSALS.assignProposalPending : { kind: 'given' as const, role: ROLES.find((r) => r.id === id)! });

  it('P-1 case E: refusals, no transport failure, re-read failed — its own lead, the 2xx lines, Load roles again; focus to it; non-interrupted summary', async () => {
    const user = userEvent.setup();
    render(<PickerHarness scene={jonas()} assign={adminRefused} reread={() => ({ kind: 'failed', failure: 'server-error' })} />);
    await user.click(box('admin'));
    await user.click(box('viewer'));
    await save(user);
    const heading = screen.getByText('Something went wrong on our side.');
    await waitFor(() => expect(heading).toHaveFocus());
    expect(screen.getByText(PICKER_BANNERS.marksMayBeStale)).toBeInTheDocument();
    expect(screen.queryByText(PICKER_BANNERS.rereadFailedLead)).toBeNull();
    expect(screen.queryByText(/^We can't tell whether this went through|^Not sent:/)).toBeNull();
    expect(screen.getByText('Given: viewer.')).toBeInTheDocument();
    expect(screen.getByText(PICKER_BANNERS.loadFirst)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: PICKER_BANNERS.loadAgain })).toBeInTheDocument();
    const banners = [...document.querySelectorAll('p[tabindex="-1"]')].map((p) => p.textContent);
    expect(banners.indexOf('Something went wrong on our side.')).toBeLessThan(banners.indexOf('Not given: admin'));
    expect(status()).toHaveTextContent("Given: viewer. Not given: admin. Each change that wasn't saved has a message with the reason.");
  });

  it('P-2: case B after a refusal does not say "All your changes are now saved."; it ends with the reasons sentence', async () => {
    const user = userEvent.setup();
    render(
      <PickerHarness
        scene={jonas()}
        assign={(id) => (id === roleRef('admin').id ? SAVE_REFUSALS.assignProposalPending : { kind: 'server-error' })}
        reread={(s) => ({ ...s, person: holding(JONAS, 'buyer', 'viewer') })}
      />,
    );
    await user.click(box('admin'));
    await user.click(box('viewer'));
    await save(user);
    await waitFor(() => expect(screen.getByText('Not given: admin')).toHaveFocus());
    expect(status().textContent).not.toContain(PICKER_STATUS.allSaved);
    expect(status().textContent).toMatch(/Each change that wasn't saved has a message with the reason\.$/);
  });

  it('P-3: Load roles again after case D announces "This person\'s roles were loaded again." with focus on Save roles', async () => {
    const user = userEvent.setup();
    let fail = true;
    render(<PickerHarness scene={jonas()} reread={(s) => (fail ? { kind: 'failed', failure: 'unreachable' } : s)} />);
    await user.click(box('viewer'));
    await save(user);
    fail = false;
    await user.click(screen.getByRole('button', { name: PICKER_BANNERS.loadAgain }));
    await settled();
    await waitFor(() => expect(saveButton()).toHaveFocus());
    expect(status()).toHaveTextContent(PICKER_STATUS.reloaded);
  });

  it('P-3: after case E, focus goes to the first remaining (refused) banner', async () => {
    const user = userEvent.setup();
    let fail = true;
    render(<PickerHarness scene={jonas()} assign={adminRefused} reread={(s) => (fail ? { kind: 'failed', failure: 'unreachable' } : s)} />);
    await user.click(box('admin'));
    await save(user);
    fail = false;
    await user.click(screen.getByRole('button', { name: PICKER_BANNERS.loadAgain }));
    await settled();
    await waitFor(() => expect(screen.getByText('Not given: admin')).toHaveFocus());
    expect(status()).toHaveTextContent(PICKER_STATUS.reloaded);
  });

  it('P-5: a 403 stop and a failed re-read — R1-5 banner plus the one sentence, no Load roles again', async () => {
    const user = userEvent.setup();
    render(<PickerHarness scene={jonas()} revoke={{ kind: 'forbidden' }} reread={() => ({ kind: 'failed', failure: 'unreachable' })} />);
    await user.click(box('buyer'));
    await save(user);
    const heading = screen.getByText('Not saved: 1 change');
    await waitFor(() => expect(heading).toHaveFocus());
    expect(heading.parentElement).toHaveTextContent(PICKER_BANNERS.marksMayBeStale);
    expect(screen.queryByRole('button', { name: PICKER_BANNERS.loadAgain })).toBeNull();
    expect(screen.queryByText(PICKER_BANNERS.loadFirst)).toBeNull();
  });
});

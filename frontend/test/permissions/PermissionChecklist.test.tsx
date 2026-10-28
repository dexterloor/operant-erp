import { readFileSync, readdirSync } from 'node:fs';
import path from 'node:path';
import { StrictMode } from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import type { Permission } from '@/lib/api/permissions';
import type { Role } from '@/lib/api/roles';
import { MODULE_HEADINGS, PERMISSION_LABELS } from '@/copy/permissionLabels';
import { CUSTOM_ROLE, MARGIN_ROLE, OWNER_ROLE, PERMISSIONS, PURCHASING_MANAGER_ROLE, SAVE_REFUSALS, UNLABELLED_PERMISSION } from '@/components/preview/fixtures/permissions';
import { ChecklistHarness } from './harness';

// Behaviour of the permission checklist (§5.15): design round 6 brief §2b, permission-labels.md Rev 2, ux-copy-frontend.md
// Rev 3 items 5–6, R4-1 "Checklist page", R4-4 L-7/L-10, b4-owed-copy.md Rev 2 > 1 (R1-1, R1-3, R1-4, R1-5, R1-6, R1-7).
// Copy is asserted as literal text, word for word.

/** The injected action has answered and its result is rendered (no `aria-busy` left) — b4-step3-progress.md "Flake". */
const settled = () => waitFor(() => expect(document.querySelector('[aria-busy="true"]')).toBeNull());
const box = (name: string) => screen.getByRole('checkbox', { name });
const groupOf = (heading: string) => screen.getByRole('region', { name: heading });
const role = (codes: string[], over: Partial<Role> = {}): Role => ({ ...CUSTOM_ROLE, permission_codes: codes, ...over });

async function save(user: ReturnType<typeof userEvent.setup>) {
  await user.click(screen.getByRole('button', { name: 'Save changes' }));
  await settled();
}

describe('PermissionChecklist — groups, labels, counts', () => {
  it('groups by module in the sidebar order with the ruled headings; one labelled box per code', () => {
    render(<ChecklistHarness role={CUSTOM_ROLE} />);
    expect(screen.getAllByRole('heading', { level: 2 }).map((h) => h.textContent)).toEqual(MODULE_HEADINGS.map((m) => m.heading));
    for (const code of Object.keys(PERMISSION_LABELS)) expect(box(PERMISSION_LABELS[code]!.label)).toBeInTheDocument();
    expect(screen.getAllByRole('checkbox')).toHaveLength(PERMISSIONS.length + MODULE_HEADINGS.length);
  });

  it('header "{n} of {total} permissions" counts the served catalogue (never a typed total); groups "{n} of {m}"', async () => {
    render(<ChecklistHarness role={CUSTOM_ROLE} />);
    expect(screen.getByText(`6 of ${PERMISSIONS.length} permissions`)).toBeInTheDocument();
    expect(within(groupOf('Purchasing')).getByText('2 of 8')).toBeInTheDocument();
    expect(within(groupOf('Sales')).getByText('2 of 16')).toBeInTheDocument();
    await userEvent.setup().click(box('View customers'));
    expect(screen.getByText(`7 of ${PERMISSIONS.length} permissions`)).toBeInTheDocument();
  });

  it('a smaller served catalogue gives a smaller total', () => {
    const three = PERMISSIONS.filter((p) => p.module === 'Modules\\Customers').slice(0, 3);
    render(<ChecklistHarness role={role([])} permissions={three} />);
    expect(screen.getByText('0 of 3 permissions')).toBeInTheDocument();
  });

  it('the group box is named "All {group} permissions" and shows mixed', () => {
    render(<ChecklistHarness role={CUSTOM_ROLE} />);
    expect(box('All Sales permissions')).toBePartiallyChecked();
    expect(box('All Customers permissions')).not.toBeChecked();
  });

  it('the three help lines are always shown under their box; Edit workspace settings is enabled', () => {
    render(<ChecklistHarness role={CUSTOM_ROLE} />);
    expect(box('Issue credit notes without a return')).toHaveAccessibleDescription(expect.stringContaining('Gives money back to a customer when no goods come back.'));
    expect(box('Give and take away roles')).toHaveAccessibleDescription(
      'Lets them give any built-in role except owner, even one with more access than they have. A role with owner-level permissions takes effect only once an owner confirms it.',
    );
    const owner = box('Edit workspace settings, and count as an owner');
    expect(owner).not.toHaveAttribute('aria-disabled');
    expect(owner).toHaveAccessibleDescription(expect.stringMatching(/^Anyone with this counts as an owner\. .*someone who has it\.$/));
  });

  it('a code with no label shows only the server description', () => {
    render(<ChecklistHarness role={CUSTOM_ROLE} permissions={[...PERMISSIONS, UNLABELLED_PERMISSION]} />);
    expect(within(groupOf('Forecasting')).getByRole('checkbox', { name: 'example report: view' })).toBeInTheDocument();
  });

  it('the label map holds exactly the served catalogue codes (a missing label falls back; an extra one is dead copy)', () => {
    expect(Object.keys(PERMISSION_LABELS).sort()).toEqual(PERMISSIONS.map((p) => p.code).sort());
  });
});

describe('PermissionChecklist — separation of duty from served sod_rows', () => {
  it('a box that would complete a split is aria-disabled with the ruled sentence; it carries its SoD markers', async () => {
    render(<ChecklistHarness role={CUSTOM_ROLE} />);
    const approve = box('Approve and place purchase orders');
    expect(approve).toHaveAttribute('aria-disabled', 'true');
    expect(approve).toHaveAccessibleDescription(
    "SoD-1 SoD-2 Can't be in the same role as Create purchase orders: one person could then raise purchase orders and also approve or cancel them (SoD-1).",
    );
    expect(box('Record and reverse payments')).toHaveAccessibleDescription(
      "SoD-3 Can't be in the same role as Issue invoices: one person could then issue invoices and also record their payment, void them or credit them (SoD-3).",
    );
    await userEvent.setup().click(approve);
    expect(approve).not.toBeChecked();
  });

  it('only split boxes are refused: no box is disabled because the editor lacks a permission', () => {
    render(<ChecklistHarness role={CUSTOM_ROLE} />);
    const refused = screen.getAllByRole('checkbox').filter((b) => b.getAttribute('aria-disabled') === 'true').map((b) => b.closest('label')?.textContent ?? '');
    expect(refused).toEqual([
      'Approve and place purchase orders',
      'Cancel purchase orders',
      'Receive deliveries',
      'Pick and ship orders',
      'Record and reverse payments',
      'Void invoices',
      'Issue credit notes for returns',
      'Issue credit notes without a return',
    ]);
  });

  it('unticking the cause clears the reason at once', async () => {
    render(<ChecklistHarness role={CUSTOM_ROLE} />);
    await userEvent.setup().click(box('Create purchase orders'));
    const approve = box('Approve and place purchase orders');
    expect(approve).not.toHaveAttribute('aria-disabled');
    expect(screen.queryByText(/Can't be in the same role as Create purchase orders/)).toBeNull();
  });

  it('group tick: leaves out the controlled halves and says so in the group line; the group shows mixed', async () => {
    render(<ChecklistHarness role={role([])} />);
    const purchasing = groupOf('Purchasing');
    const line = within(purchasing).getByRole('status');
    expect(line).toBeEmptyDOMElement();
    await userEvent.setup().click(box('All Purchasing permissions'));
    expect(line).toHaveTextContent(
      'Left out: Approve and place purchase orders, Cancel purchase orders and Receive deliveries, because each would complete a separation-of-duty split.',
    );
    expect(box('All Purchasing permissions')).toBePartiallyChecked();
    expect(box('Create purchase orders')).toBeChecked();
    expect(box('Approve and place purchase orders')).toHaveAttribute('aria-disabled', 'true');
  });

  it('group tick: a box refused before the click stays as it was and is not listed', async () => {
    render(<ChecklistHarness role={CUSTOM_ROLE} />);
    const user = userEvent.setup();
    await user.click(box('All Purchasing permissions'));
    expect(within(groupOf('Purchasing')).getByRole('status')).toBeEmptyDOMElement();
    expect(box('Approve and place purchase orders')).not.toBeChecked();
    await user.click(box('All Sales permissions'));
    expect(within(groupOf('Sales')).getByRole('status')).toBeEmptyDOMElement();
  });

  it('every group line is mounted empty from first render (R4-4 L-2)', () => {
    render(<ChecklistHarness role={CUSTOM_ROLE} />);
    const regions = screen.getAllByRole('status');
    expect(regions).toHaveLength(MODULE_HEADINGS.length);
    for (const r of regions) expect(r).toBeEmptyDOMElement();
  });
});

describe('PermissionChecklist — built-in and read-only', () => {
  it('built-in owner: every box ticked and read-only, described by the built-in line; markers, no conflict reason', async () => {
    render(<ChecklistHarness role={OWNER_ROLE} />);
    expect(screen.getByText('Built-in')).toBeInTheDocument();
    expect(screen.getByText("Built-in role. It can't be changed or deleted.")).toBeInTheDocument();
    for (const b of screen.getAllByRole('checkbox')) {
      expect(b).toHaveAttribute('aria-disabled', 'true');
      expect(b).toHaveAccessibleDescription(expect.stringContaining("Built-in role. It can't be changed or deleted."));
    }
    expect(box('Approve and place purchase orders')).toBeChecked();
    expect(box('Create purchase orders')).toBeChecked();
    expect(box('Approve and place purchase orders')).toHaveAccessibleDescription("SoD-1 SoD-2 Built-in role. It can't be changed or deleted.");
    expect(screen.queryByText(/Can't be in the same role/)).toBeNull();
    const user = userEvent.setup();
    await user.click(box('Approve and place purchase orders'));
    await user.click(box('All Sales permissions'));
    expect(box('Approve and place purchase orders')).toBeChecked();
    expect(screen.queryByRole('button', { name: 'Save changes' })).toBeNull();
  });

  it('built-in purchasing_manager holds both halves of SoD-1: both ticked, no reason on any box (the built-in line is the one cause)', () => {
    render(<ChecklistHarness role={PURCHASING_MANAGER_ROLE} />);
    expect(box('Create purchase orders')).toBeChecked();
    expect(box('Approve and place purchase orders')).toBeChecked();
    expect(screen.queryByText(/Can't be in the same role/)).toBeNull();
    expect(box('Receive deliveries')).toHaveAccessibleDescription("SoD-2 Built-in role. It can't be changed or deleted.");
  });

  it('without role.edit no box is offered: each group lists the role\'s permissions', () => {
    render(<ChecklistHarness role={CUSTOM_ROLE} canEdit={false} />);
    expect(screen.queryAllByRole('checkbox')).toHaveLength(0);
    expect(within(groupOf('Purchasing')).getAllByRole('listitem').map((li) => li.textContent)).toEqual(['View purchase orders, including what goods cost', 'Create purchase orders']);
    expect(screen.queryByRole('status')).toBeNull();
  });
});

describe('PermissionChecklist — dirty bar and save', () => {
  it('"1 change" / "{n} changes" · Discard · Save changes; Discard puts the saved set back', async () => {
    render(<ChecklistHarness role={CUSTOM_ROLE} />);
    expect(screen.queryByRole('button', { name: 'Save changes' })).toBeNull();
    const user = userEvent.setup();
    await user.click(box('View customers'));
    expect(screen.getByText('1 change')).toBeInTheDocument();
    await user.click(box('View items'));
    expect(screen.getByText('2 changes')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Discard' }));
    expect(box('View items')).toBeChecked();
    expect(box('View customers')).not.toBeChecked();
    expect(screen.queryByRole('button', { name: 'Save changes' })).toBeNull();
  });

  it('Save sends the whole set, sorted; on success the bar goes and focus goes to the page heading', async () => {
    const sent: (readonly string[])[] = [];
    render(<ChecklistHarness role={CUSTOM_ROLE} onSave={(c) => sent.push(c)} />);
    const user = userEvent.setup();
    await user.click(box('View customers'));
    await save(user);
    expect(sent).toEqual([[...CUSTOM_ROLE.permission_codes, 'customer.view'].sort()]);
    expect(screen.queryByRole('button', { name: 'Save changes' })).toBeNull();
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveFocus());
    expect(box('View customers')).toBeChecked();
  });

  it('margin: no banner before save, whatever is ticked; after save it follows the served margin_exposure (L-10)', async () => {
    const margin = 'People with this role can see both what goods cost and what they sell for, so they can work out your margins.';
    render(<ChecklistHarness role={role(['purchase_order.view'])} answer={(codes) => ({ kind: 'saved', role: role([...codes], { margin_exposure: true }) })} />);
    const user = userEvent.setup();
    await user.click(box('View sales orders'));
    expect(screen.queryByText(margin)).toBeNull();
    await save(user);
    expect(screen.getByText(margin)).toBeInTheDocument();
  });

  it('margin from the 200 alone, before any re-read; and on load when the read role says so', async () => {
    const margin = 'People with this role can see both what goods cost and what they sell for, so they can work out your margins.';
    const { unmount } = render(<ChecklistHarness role={role(['purchase_order.view'])} reread={false} answer={(codes) => ({ kind: 'saved', role: role([...codes], { margin_exposure: true }) })} />);
    const user = userEvent.setup();
    await user.click(box('View sales orders'));
    await save(user);
    expect(screen.getByText(margin)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Save changes' })).toBeNull();
    unmount();
    render(<ChecklistHarness role={MARGIN_ROLE} />);
    expect(screen.getByText(margin)).toBeInTheDocument();
  });

  for (const refusal of SAVE_REFUSALS) {
    it(`refused (${refusal.rule}): "Not saved", the server's sentence, focus on the heading; the changes stay`, async () => {
      render(<ChecklistHarness role={CUSTOM_ROLE} answer={refusal} />);
      const user = userEvent.setup();
      await user.click(box('Correct stock levels by a count, and move stock between locations'));
      await save(user);
      const heading = screen.getByText('Not saved');
      await waitFor(() => expect(heading).toHaveFocus());
      expect(screen.getByText(refusal.message)).toBeInTheDocument();
      expect(screen.getByText('1 change')).toBeInTheDocument();
      expect(box('Correct stock levels by a count, and move stock between locations')).toBeChecked();
    });
  }

  it('a refusal with no message: R1-7 step 5', async () => {
    render(<ChecklistHarness role={CUSTOM_ROLE} answer={{ kind: 'refused', rule: 'idempotency-key-conflict', message: '' }} />);
    const user = userEvent.setup();
    await user.click(box('View customers'));
    await save(user);
    expect(screen.getByText("This change wasn't made. Reload the page to see where things stand, then try again.")).toBeInTheDocument();
  });

  const interrupted = "Your changes may not have been saved. They're still here: press Save changes to send them again.";
  for (const [label, answer, heading] of [
    ['unreachable', { kind: 'unreachable' }, "We couldn't reach the server."],
    ['thrown', 'throw', "We couldn't reach the server."],
    ['5xx', { kind: 'server-error' }, 'Something went wrong on our side.'],
  ] as const) {
    it(`interrupted (${label}): R1-1 heading, R1-3 body; the dirty bar and its count stay`, async () => {
      render(<ChecklistHarness role={CUSTOM_ROLE} answer={answer} />);
      const user = userEvent.setup();
      await user.click(box('View customers'));
      await save(user);
      await waitFor(() => expect(screen.getByText(heading)).toHaveFocus());
      expect(screen.getByText(interrupted)).toBeInTheDocument();
      expect(screen.queryByText('Not saved')).toBeNull();
      expect(screen.getByText('1 change')).toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'Save changes' })).toBeInTheDocument();
    });
  }

  it('403: "Not saved" and the fixed permission name in emphasis (R1-5)', async () => {
    render(<ChecklistHarness role={CUSTOM_ROLE} answer={{ kind: 'forbidden' }} />);
    const user = userEvent.setup();
    await user.click(box('View customers'));
    await save(user);
    expect(screen.getByText('Not saved')).toBeInTheDocument();
    const em = screen.getByText('Edit roles', { selector: 'em' });
    expect(em.parentElement).toHaveTextContent('This needs the Edit roles permission, which you no longer have. Your workspace administrator can grant it.');
  });

  it('404: "Not saved" with the ST-5 sentences (R1-6)', async () => {
    render(<ChecklistHarness role={CUSTOM_ROLE} answer={{ kind: 'not-found' }} />);
    const user = userEvent.setup();
    await user.click(box('View customers'));
    await save(user);
    expect(screen.getByText("We couldn't find that. It may have been deleted, or you may not have access.")).toBeInTheDocument();
  });

  it('401: no in-page copy (R1-4)', async () => {
    render(<ChecklistHarness role={CUSTOM_ROLE} answer={{ kind: 'session-ended' }} />);
    const user = userEvent.setup();
    await user.click(box('View customers'));
    await save(user);
    expect(screen.queryByText('Not saved')).toBeNull();
    expect(screen.queryByText("We couldn't reach the server.")).toBeNull();
  });

  it('a repeated refusal moves focus to the heading again', async () => {
    render(<ChecklistHarness role={CUSTOM_ROLE} answer={SAVE_REFUSALS[0]} />);
    const user = userEvent.setup();
    await user.click(box('View customers'));
    await save(user);
    await waitFor(() => expect(screen.getByText('Not saved')).toHaveFocus());
    await user.click(box('View items'));
    await save(user);
    await waitFor(() => expect(screen.getByText('Not saved')).toHaveFocus());
  });

  it('a new role resets the edits (derived reset), also under StrictMode', async () => {
    const { rerender } = render(
      <StrictMode>
        <ChecklistHarness key="a" role={CUSTOM_ROLE} />
      </StrictMode>,
    );
    await userEvent.setup().click(box('View customers'));
    expect(screen.getByText('1 change')).toBeInTheDocument();
    rerender(
      <StrictMode>
        <ChecklistHarness key="b" role={MARGIN_ROLE} />
      </StrictMode>,
    );
    expect(screen.queryByText('1 change')).toBeNull();
    expect(box('View customers')).not.toBeChecked();
  });

  it('no shipped server sentence is stored in src/copy or src/components/permissions', () => {
    const root = path.resolve(__dirname, '../../src');
    const read = (dir: string) => readdirSync(path.join(root, dir)).map((f) => readFileSync(path.join(root, dir, f), 'utf8')).join('\n');
    const src = read('copy') + read('components/permissions');
    for (const r of SAVE_REFUSALS) expect(src.includes(r.message), r.rule).toBe(false);
  });
});

// b4-step4-gaps.md Revision 2 (FINAL), G-c (RED F-1): a served SoD id with no clause in copy/sod.ts behaves exactly as a
// known id — refused, marked, left out by a group tick — and only the reason WORDING falls back (PROPOSED, UX to
// confirm), with one dev warning per id. `SoD-9` is a test-only row: today it is never served (version-skew guard).
describe('PermissionChecklist — an SoD id with no clause (G-c)', () => {
  const SKEW: Permission[] = [
    { code: 'zz_prep.create', module: 'Modules\\Inventory', description: 'zz prep: create', sod_rows: [{ id: 'SoD-9', half: 'preparing' }] },
    { code: 'zz_ctl.approve', module: 'Modules\\Inventory', description: 'zz ctl: approve', sod_rows: [{ id: 'SoD-9', half: 'controlled' }] },
  ];
  const withSkew = [...PERMISSIONS, ...SKEW];
  const fallback = "Can't be in the same role as zz prep: create: together they would complete a separation-of-duty split (SoD-9).";

  it('(1) the box is refused with the fallback sentence; one dev warning per id', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    const { rerender } = render(<ChecklistHarness key="x" role={role(['zz_prep.create'])} permissions={withSkew} />);
    const box9 = box('zz ctl: approve');
    expect(box9).toHaveAttribute('aria-disabled', 'true');
    expect(box9).toHaveAccessibleDescription(`SoD-9 ${fallback}`);
    rerender(<ChecklistHarness key="y" role={role(['zz_prep.create'])} permissions={withSkew} />);
    expect(warn.mock.calls.filter((c) => String(c[0]).includes('SoD-9'))).toHaveLength(1);
    warn.mockRestore();
  });

  it('(2) its SoD id marker renders and is in aria-describedby', () => {
    render(<ChecklistHarness role={role([])} permissions={withSkew} />);
    const b = box('zz prep: create');
    const ids = (b.getAttribute('aria-describedby') ?? '').split(' ');
    expect(ids.map((id) => document.getElementById(id)?.textContent)).toContain('SoD-9');
    expect(within(groupOf('Inventory')).getAllByText('SoD-9')).toHaveLength(2);
  });

  it('(3) a group tick leaves out its controlled half and announces it', async () => {
    vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    render(<ChecklistHarness role={role([])} permissions={withSkew} />);
    await userEvent.setup().click(box('All Inventory permissions'));
    expect(within(groupOf('Inventory')).getByRole('status')).toHaveTextContent('Left out: zz ctl: approve, because each would complete a separation-of-duty split.');
    expect(box('zz prep: create')).toBeChecked();
    expect(box('zz ctl: approve')).not.toBeChecked();
    vi.restoreAllMocks();
  });
});

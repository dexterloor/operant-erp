import { render, waitFor } from '@testing-library/react';
import axe from 'axe-core';
import { describe, expect, it } from 'vitest';
import { ComponentPreview } from '@/components/preview/ComponentPreview';
import RootError from '@/app/error';
import RootNotFound from '@/app/not-found';
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
import { ChecklistHarness } from './permissions/harness';
import { HELD_PAIR_PERSON, JONAS, OFFERED_HELD_PAIR_BY_ADMIN, OFFERED_JONAS_BY_ADMIN, OFFERED_SELF_ADMIN, PROPOSAL_BY_OTHER, PROPOSAL_EXPIRED, PROPOSAL_FINANCE, SAVE_REFUSALS as PICKER_REFUSALS, SELF_ADMIN } from '@/components/preview/fixtures/roles';
import { PickerHarness, scene as pickerScene } from './roles/harness';
import { CUSTOM_ROLE, MARGIN_ROLE, OWNER_ROLE, SAVE_REFUSALS } from '@/components/preview/fixtures/permissions';
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

// B-4 step 4: the permission checklist — an editable custom role (refused boxes with reasons, help lines, a Left-out
// line, the dirty bar, a Not saved banner), a built-in role (read-only, described by the built-in line), the margin
// banner, and the read-only list (no role.edit).
describe('axe — B-4 permission checklist', () => {
  const axeDoc = async () => {
    const result = await axe.run(document.body, { rules: { 'color-contrast': { enabled: false } } });
    return result.violations.map((v) => `${v.id}: ${v.nodes.length} × ${v.help}`);
  };

  it('editing: a group tick with a Left-out line, the dirty bar, then a Not saved banner: no violations', { timeout: 30_000 }, async () => {
    render(<ChecklistHarness role={{ ...CUSTOM_ROLE, permission_codes: [] }} answer={SAVE_REFUSALS[0]} />);
    const user = userEvent.setup();
    await user.click(screen.getByRole('checkbox', { name: 'All Purchasing permissions' }));
    expect(screen.getByText(/^Left out: /)).toBeInTheDocument();
    expect(await axeDoc()).toEqual([]);
    await user.click(screen.getByRole('button', { name: 'Save changes' }));
    await settled();
    expect(screen.getByText('Not saved')).toBeInTheDocument();
    expect(await axeDoc()).toEqual([]);
  });

  it('a built-in role, the margin banner, and the read-only list: no violations', { timeout: 30_000 }, async () => {
    const { unmount } = render(<ChecklistHarness role={OWNER_ROLE} />);
    expect(await axeDoc()).toEqual([]);
    unmount();
    const second = render(<ChecklistHarness role={MARGIN_ROLE} />);
    expect(await axeDoc()).toEqual([]);
    second.unmount();
    render(<ChecklistHarness role={CUSTOM_ROLE} canEdit={false} />);
    expect(await axeDoc()).toEqual([]);
  });
});

// B-4 step 5: the picker with staged ticks, the pair warning, refusals and the interrupted banner; the Waiting group with
// live and expired rows; a held conflicting pair; the own page; the no-role.view seat.
describe('axe — B-4 role picker + Waiting group', () => {
  const axeDoc = async () => {
    const result = await axe.run(document.body, { rules: { 'color-contrast': { enabled: false } } });
    return result.violations.map((v) => `${v.id}: ${v.nodes.length} × ${v.help}`);
  };

  it('staged ticks, the pair warning, a refusal banner and the interrupted banner: no violations', { timeout: 30_000 }, async () => {
    const answers = [PICKER_REFUSALS.assignProposalPending];
    render(
      <PickerHarness
        scene={pickerScene(JONAS, OFFERED_JONAS_BY_ADMIN, [PROPOSAL_FINANCE, PROPOSAL_EXPIRED, PROPOSAL_BY_OTHER])}
        canConfirm
        assign={() => answers.shift() ?? { kind: 'unreachable' }}
      />,
    );
    const user = userEvent.setup();
    await user.click(screen.getByRole('checkbox', { name: 'finance' }));
    await user.click(screen.getByRole('checkbox', { name: 'buyer' }));
    await user.click(screen.getByRole('checkbox', { name: 'viewer' }));
    expect(await axeDoc()).toEqual([]);
    await user.click(screen.getByRole('button', { name: 'Save roles' }));
    await settled();
    expect(screen.getByText('Not given: viewer')).toBeInTheDocument();
    expect(await axeDoc()).toEqual([]);
  });

  it('a held conflicting pair, the own page and the no-role.view seat: no violations', { timeout: 30_000 }, async () => {
    const a = render(<PickerHarness scene={pickerScene(HELD_PAIR_PERSON, OFFERED_HELD_PAIR_BY_ADMIN)} />);
    expect(await axeDoc()).toEqual([]);
    a.unmount();
    const b = render(<PickerHarness scene={pickerScene(SELF_ADMIN, OFFERED_SELF_ADMIN)} showProposals={false} />);
    expect(await axeDoc()).toEqual([]);
    b.unmount();
    render(<PickerHarness scene={pickerScene(JONAS, OFFERED_JONAS_BY_ADMIN)} roleView={false} />);
    await userEvent.setup().click(screen.getByRole('checkbox', { name: 'finance' }));
    expect(await axeDoc()).toEqual([]);
  });
});

// b3b-followup C-2: the page-level error and the root not-found, each as it renders frame-less (its own <main>).
describe('axe — page error and not found (b3b-followup C-2)', () => {
  it('the root error page and the root not-found page have no violations', async () => {
    for (const ui of [<RootError key="e" />, <RootNotFound key="n" />]) {
      const { container, unmount } = render(ui);
      const result = await axe.run(container, { rules: { 'color-contrast': { enabled: false } } });
      expect(result.violations.map((v) => `${v.id}: ${v.nodes.length} × ${v.help}`)).toEqual([]);
      unmount();
    }
  });
});

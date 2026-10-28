import { describe, expect, it } from 'vitest';
import { changeCount, completesSplit, groupPermissions, groupState, toggleGroup } from '@/lib/permissions/checklist';
import type { Permission } from '@/lib/api/permissions';
import { MODULE_HEADINGS, PERMISSION_LABELS } from '@/copy/permissionLabels';
import { SOD_CLAUSES } from '@/copy/sod';
import { PERMISSIONS, UNLABELLED_PERMISSION } from '@/components/preview/fixtures/permissions';

// Pure logic of lib/permissions/checklist.ts (principle 2 (b): completesSplit over served sod_rows only; Rev 3 item 6's
// group rule; round-6 §2b). Codes here are fixture data, read in a test — never in src.

const MODULE_ORDER = MODULE_HEADINGS.map((m) => m.module);
const CODE_ORDER = Object.keys(PERMISSION_LABELS);
const codesOf = (module: string) => groupPermissions(PERMISSIONS, MODULE_ORDER, CODE_ORDER).find((g) => g.module === module)!.permissions.map((p) => p.code);
const set = (...c: string[]) => new Set(c);

describe('groupPermissions', () => {
  it('groups by module in the sidebar order, each group in the label table order (served order is by code)', () => {
    const groups = groupPermissions(PERMISSIONS, MODULE_ORDER, CODE_ORDER);
    expect(groups.map((g) => g.module)).toEqual(MODULE_ORDER);
    expect(groups.flatMap((g) => g.permissions.map((p) => p.code))).toEqual(CODE_ORDER);
  });

  it('an unknown module and an unlabelled code are kept, after the known ones, in served order', () => {
    const extra: Permission = { code: 'zz.view', module: 'Modules\\Unknown', description: 'zz: view', sod_rows: [] };
    const groups = groupPermissions([extra, UNLABELLED_PERMISSION, ...PERMISSIONS], MODULE_ORDER, CODE_ORDER);
    expect(groups.at(-1)!.module).toBe('Modules\\Unknown');
    expect(groups.find((g) => g.module === 'Modules\\Analytics')!.permissions.map((p) => p.code)).toEqual(['forecast.view', 'example_report.view']);
  });
});

describe('completesSplit — the one SoD derivation (principle 2 (b))', () => {
  it('a box that would complete a split names each ticked box on the other half, by SoD id', () => {
    expect(completesSplit('purchase_order.approve', set('purchase_order.create'), PERMISSIONS)).toEqual([
      { sodId: 'SoD-1', half: 'controlled', withCode: 'purchase_order.create' },
    ]);
    // approve is preparing on SoD-2: with a receiver ticked it is refused there too.
    expect(completesSplit('purchase_order.approve', set('purchase_order.create', 'goods_receipt.create'), PERMISSIONS)).toEqual([
      { sodId: 'SoD-1', half: 'controlled', withCode: 'purchase_order.create' },
      { sodId: 'SoD-2', half: 'preparing', withCode: 'goods_receipt.create' },
    ]);
    expect(completesSplit('credit_note.issue', set('invoice.issue', 'sales_return.create'), PERMISSIONS).map((c) => c.sodId)).toEqual(['SoD-3', 'SoD-5']);
  });

  it('a ticked box, a box on no split, and a box whose other half is not ticked answer []', () => {
    expect(completesSplit('purchase_order.create', set('purchase_order.create', 'purchase_order.approve'), PERMISSIONS)).toEqual([]);
    expect(completesSplit('inventory_item.view', set('purchase_order.create'), PERMISSIONS)).toEqual([]);
    expect(completesSplit('purchase_order.cancel', set('goods_receipt.create'), PERMISSIONS)).toEqual([]);
  });

  it('every served SoD id has a clause (copy/sod.ts keeps RoleAssignment::SOD_CLAUSES)', () => {
    const ids = new Set(PERMISSIONS.flatMap((p) => p.sod_rows.map((r) => r.id)));
    expect([...ids].sort()).toEqual(Object.keys(SOD_CLAUSES).sort());
  });
});

describe('groupState', () => {
  it('none → false, some → mixed, all → true', () => {
    expect(groupState(['a', 'b'], set())).toBe(false);
    expect(groupState(['a', 'b'], set('a'))).toBe('mixed');
    expect(groupState(['a', 'b'], set('a', 'b', 'c'))).toBe(true);
  });
});

describe('toggleGroup (Rev 3 item 6; round-6 §2b; RED S-4)', () => {
  const purchasing = codesOf('Modules\\Purchasing');
  const sales = codesOf('Modules\\Sales');

  it('Purchasing from nothing: every box but the controlled halves — of the split codes, Create only', () => {
    const r = toggleGroup(purchasing, true, set(), PERMISSIONS);
    expect(r.leftOut).toEqual(['purchase_order.approve', 'purchase_order.cancel', 'goods_receipt.create']);
    expect([...r.ticked].sort()).toEqual(['goods_receipt.view', 'purchase_order.create', 'purchase_order.delete', 'purchase_order.edit', 'purchase_order.view']);
    expect(groupState(purchasing, r.ticked)).toBe('mixed');
  });

  it('a box refused before the click stays as it was and is not in Left out', () => {
    const r = toggleGroup(purchasing, true, set('purchase_order.create'), PERMISSIONS);
    // approve, cancel (SoD-1) and goods_receipt.create (SoD-2) are all refused by the ticked Create already.
    for (const c of ['purchase_order.approve', 'purchase_order.cancel', 'goods_receipt.create']) expect(r.ticked.has(c), c).toBe(false);
    expect(r.leftOut).toEqual([]);
  });

  it('a box is left out when its other half is in another group already ticked', () => {
    const r = toggleGroup(sales, true, set(), PERMISSIONS);
    expect(r.leftOut).toEqual(['fulfilment.create', 'invoice.pay', 'invoice.void', 'credit_note.issue', 'credit_note.adjust']);
  });

  it('no tick ever completes a split', () => {
    const r = toggleGroup(sales, true, toggleGroup(purchasing, true, set(), PERMISSIONS).ticked, PERMISSIONS);
    for (const code of r.ticked) {
      const others = new Set(r.ticked);
      others.delete(code);
      expect(completesSplit(code, others, PERMISSIONS), code).toEqual([]);
    }
  });

  it('unticking clears the whole group and nothing else; a tick with nothing left to tick unticks the group', () => {
    const before = set('purchase_order.create', 'inventory_item.view');
    expect([...toggleGroup(purchasing, false, before, PERMISSIONS).ticked]).toEqual(['inventory_item.view']);
    const full = toggleGroup(purchasing, true, set(), PERMISSIONS).ticked;
    const again = toggleGroup(purchasing, true, full, PERMISSIONS);
    expect(again.leftOut).toEqual([]);
    expect(purchasing.some((c) => again.ticked.has(c))).toBe(false);
  });
});

describe('changeCount', () => {
  it('counts additions and removals against the saved set', () => {
    expect(changeCount(['a', 'b'], set('a', 'b'))).toBe(0);
    expect(changeCount(['a', 'b'], set('a', 'c'))).toBe(2);
    expect(changeCount([], set('a'))).toBe(1);
  });
});

// b4-step4-gaps.md Revision 2 (FINAL), G-a residual tripwire (RED F-4 + spar substitution). The group box's dead end —
// nothing ticked and every box refused by ticks in OTHER modules, so a click changes nothing — is unreachable while every
// served module has at least one code on no SoD row (such a code is never refused, so it is always a tick candidate).
// Over the hand-mirrored fixture only: the real guard is backend carry BK-12; until it lands, re-check by hand whenever
// `SeparationOfDuty::ROWS` or the permission seed changes and the fixture is re-mirrored.
describe('tripwire — every module keeps a code on no SoD row (G-a residual)', () => {
  it('holds over the served catalogue fixture, with both anti-vacuity controls', () => {
    const modules = [...new Set(PERMISSIONS.map((p) => p.module))];
    // (1) the module set is non-empty and equals MODULE_HEADINGS' (no unknown served module today).
    expect(modules.length).toBeGreaterThan(0);
    expect([...modules].sort()).toEqual([...MODULE_ORDER].sort());
    // (2) at least one code carries SoD rows.
    expect(PERMISSIONS.some((p) => p.sod_rows.length > 0)).toBe(true);
    const deadEndPossible = modules.filter((m) => !PERMISSIONS.some((p) => p.module === m && p.sod_rows.length === 0));
    expect(
      deadEndPossible,
      'A module now has every code on an SoD row: its group box can reach a click that changes nothing. Build RED F-4\'s ' +
        "option (b4-step4-gaps-red.md): make the group box aria-disabled, described by the boxes' reasons, when groupState is " +
        'false and no box can be ticked.',
    ).toEqual([]);
  });
});

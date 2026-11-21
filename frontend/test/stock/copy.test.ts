// @vitest-environment node
import { describe, expect, it } from 'vitest';
import { ITEM_PAGE } from '@/copy/items';
import { KIND_LABELS } from '@/copy/warehouses';
// @ts-expect-error — plain ESM script without type declarations
import { readInputs } from '../../scripts/api-surface-lib.mjs';
import * as STOCK from '@/copy/stock';
import { reasonWords, STOCK_LEVELS, STOCK_LOCATION_MARKER, STOCK_MOVEMENT_PAGE, STOCK_MOVEMENT_REASON, STOCK_MOVEMENT_SOURCE, STOCK_MOVEMENTS_FILTER, STOCK_MOVEMENTS_LIST, STOCK_MOVEMENTS_PAGING, STOCK_SCOPE, STOCK_WITHHELD } from '@/copy/stock';
import type { SourceWord } from '@/lib/stock/source';

// Stock ST-1 (stock-plan.md §6.8; stock-st1-red.md L-12; UX B-0's ban): no stock-family sentence says or implies that stock
// is available, in stock, reserved or pickable (G-7, G-47's R-4), names a History or audit view (none exists), or offers to
// undo; and no button or link label offers a count, a move, an adjustment, a transfer or a record (G-51: the web app can
// do none). A past count or move named as a fact (*Count correction*, *Moved in*) is allowed: it is what happened.

const BANNED = /\b(available|in stock|reserved|pickable|history|audit|undo)\b/i;
const ACTION_WORDS = ['adjustment', 'adjust', 'transfer', 'count', 'move', 'record', 'correct'];

/** Every string a copy value can produce: strings as they are, functions called with sample arguments, objects walked. */
function strings(value: unknown): string[] {
  if (typeof value === 'string') return [value];
  if (typeof value === 'function') {
    const f = value as (...a: unknown[]) => unknown;
    // A function of a list (STOCK_WITHHELD.body) is called with one and two labels; any other with sample arguments.
    if (f.length === 1 && /labels/.test(String(f))) return [...strings(f(['S1'])), ...strings(f(['S1', 'S2']))];
    return strings(f(...['S1', 'S2', 'S3', 'S4', 'S5'].map((s, i) => (i === 0 ? 2 : s))));
  }
  if (value !== null && typeof value === 'object') return Object.values(value).flatMap(strings);
  return [];
}

/** The copy keys this slice renders as a button or link label. */
const LABELS = (page: { itemMovementsLink: (sku: string) => string; backToList: string } = STOCK_MOVEMENT_PAGE) => [
  STOCK_MOVEMENTS_FILTER.clear,
  STOCK_MOVEMENTS_LIST.noneYetLink,
  page.itemMovementsLink('WID-001'),
  page.backToList,
  STOCK_MOVEMENTS_PAGING.pager.previous,
  STOCK_MOVEMENTS_PAGING.pager.next,
  STOCK_MOVEMENTS_PAGING.noMore.action,
  STOCK_MOVEMENT_SOURCE.goodsReceipt,
  STOCK_MOVEMENT_SOURCE.matchingMoveIn,
  STOCK_MOVEMENT_SOURCE.matchingMoveOut,
  STOCK_MOVEMENT_SOURCE.receiptWithNumber('GR-000123'),
  STOCK_MOVEMENT_SOURCE.movedTo('B-02'),
  STOCK_MOVEMENT_SOURCE.movedFrom('B-02'),
  ITEM_PAGE.movementsLink,
  // Stock ST-2 (stock-st2-plan.md §7.6): the stock-on-hand links and buttons.
  ITEM_PAGE.stockLink,
  STOCK_LEVELS.changeLocation,
  STOCK_LEVELS.noneYetLink,
  STOCK_LEVELS.itemMovementsLink,
  STOCK_LEVELS.backToItem,
  STOCK_LEVELS.chooseLocationInstead,
  STOCK_SCOPE.warehouseOption('WH-1', 'Main'),
  STOCK_SCOPE.locationOption('A-01', 'Bin'),
  STOCK_SCOPE.noWarehousesYetLink,
  STOCK_SCOPE.changeWarehouse,
  STOCK_SCOPE.clearFind,
];
const offersAnAction = (label: string) => [...ACTION_WORDS, 'export', 'download'].includes(label.trim().split(/\s+/)[0]!.toLowerCase());

describe('6.8 — the stock copy’s word ban (UX B-0)', () => {
  it('no stock-family string names availability, a reservation, picking, a History or audit view, or undo', () => {
    const all = [...strings(STOCK), ITEM_PAGE.movementsLink];
    expect(all.length).toBeGreaterThan(40);
    expect(all.filter((s) => BANNED.test(s))).toEqual([]);
  });

  it('no button or link label offers a count, a move, an adjustment, a transfer or a record', () => {
    expect(LABELS().filter(offersAnAction)).toEqual([]);
  });

  it('planted control (M31): "Count again" as a link label, and "available" in a sentence, are red', () => {
    expect(LABELS({ ...STOCK_MOVEMENT_PAGE, backToList: 'Count again' }).filter(offersAnAction)).toEqual(['Count again']);
    expect(BANNED.test('Stock available in A-01.')).toBe(true);
    expect(BANNED.test('No stock movements for WID-001 yet.')).toBe(false);
  });
});

describe('the reason and source words (UX B-1, B-2)', () => {
  it('six served reasons and a fallback that never shows the raw value', () => {
    expect(Object.keys(STOCK_MOVEMENT_REASON)).toEqual(['receipt', 'fulfilment', 'return', 'adjustment', 'transfer_out', 'transfer_in', 'other']);
    expect(reasonWords('fulfilment')).toEqual({ label: 'Shipped', spoken: 'shipped' });
    expect(reasonWords('zzz')).toEqual({ label: 'Other change', spoken: 'other change' });
    expect(reasonWords('constructor')).toEqual({ label: 'Other change', spoken: 'other change' });
  });

  it('every source word the closed table can return has a copy entry', () => {
    const words: SourceWord[] = ['goodsReceipt', 'fulfilment', 'customerReturn', 'matchingMoveIn', 'matchingMoveOut', 'none', 'other'];
    for (const w of words) expect(typeof STOCK_MOVEMENT_SOURCE[w]).toBe('string');
  });
});

describe('stock ST-2 — the stock-on-hand copy (UX stock-st2-ux-copy.md Revision 1)', () => {
  const ST2 = [STOCK_LEVELS, STOCK_SCOPE, STOCK_LOCATION_MARKER, STOCK_WITHHELD, ITEM_PAGE.stockLink].flatMap(strings);

  it('the word ban widens for the new groups: also "out of stock" and "free" (UX B-0)', () => {
    expect(ST2.length).toBeGreaterThan(50);
    expect(ST2.filter((x) => BANNED.test(x) || /\b(out of stock|free)\b/i.test(x))).toEqual([]);
  });

  it('the Quarantine badge is the location kind’s label (UX rule 4)', () => {
    expect(STOCK_LOCATION_MARKER.quarantine).toBe(KIND_LABELS.quarantine!.label);
  });

  it('no copy/stock string names an operation (it backs G-47 rule (b): the movement pages import this file)', () => {
    const operations = Object.keys((readInputs() as { surface: { operations: Record<string, unknown> } }).surface.operations);
    expect(operations.length).toBeGreaterThan(100);
    expect(strings(STOCK).filter((x) => operations.some((op) => new RegExp(`\\b${op}\\b`).test(x)))).toEqual([]);
  });

  it('the parts of a badge-carrying phrase join, with no badge, into UX’s sentence', () => {
    const s = STOCK_LEVELS.scopeLocation;
    expect(`${s.lead}A-01${s.in}${s.warehouse('WH-1', 'Main warehouse')}${s.end}`).toBe("Showing what's stored in A-01 in WH-1 — Main warehouse.");
    expect(`${STOCK_LEVELS.captionLocation.lead}A-01${STOCK_LEVELS.captionLocation.end}`).toBe('Items in A-01, by SKU');
    expect(STOCK_WITHHELD.body(['View locations'])).toBe('To see stock here you also need the View locations permission.');
    expect(STOCK_WITHHELD.body(['View locations', 'View warehouses'])).toBe('To see stock here you also need the View locations and View warehouses permissions.');
    expect([STOCK_WITHHELD.heading(1), STOCK_WITHHELD.heading(2)]).toEqual(['Stock on hand needs one more permission.', 'Stock on hand needs two more permissions.']);
  });
});

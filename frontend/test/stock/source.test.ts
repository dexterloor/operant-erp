// @vitest-environment node
import { existsSync } from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import type { StockMovement } from '@/lib/api/stock';
import { LINKED_SOURCES, sourceOf, sourceWord, type SourceGates } from '@/lib/stock/source';

// Stock ST-1 (stock-plan.md §2.6, §6.2 as amended by stock-st1-red.md L-2, L-4, L-5; b4-architecture.md Revision 11 R11-4):
// the ONE closed table a movement's source is rendered from. A link only where the web app has a record page for the
// source (tied to its `[id]/page.tsx` on disk), the viewer's server-computed gate is true, and the served `source_id` is a
// record id; otherwise a plain word. Never the id as text, never the raw `source_type`.

const FRONTEND = path.resolve(__dirname, '../..');
const RECEIPT_ID = 'e1b2c3d4-0000-4000-8000-000000000003';
const PARTNER_ID = 'f1b2c3d4-0000-4000-8000-000000000004';
const ALL: SourceGates = { goodsReceipt: true, movement: true };
const NONE: SourceGates = { goodsReceipt: false, movement: false };
const m = (source_type: string | null, source_id: string | null, reason: string): Pick<StockMovement, 'source_type' | 'source_id' | 'reason'> => ({ source_type, source_id, reason });

describe('6.2-1 — each source_type row, with the gates given and withheld', () => {
  it('a goods receipt links to /goods-receipts/<id> only with the GetGoodsReceipt gate; else the plain word', () => {
    expect(sourceOf(m('goods_receipt', RECEIPT_ID, 'receipt'), ALL)).toEqual({ kind: 'link', href: `/goods-receipts/${RECEIPT_ID}`, word: 'goodsReceipt' });
    expect(sourceOf(m('goods_receipt', RECEIPT_ID, 'receipt'), { goodsReceipt: false, movement: true })).toEqual({ kind: 'text', word: 'goodsReceipt' });
  });

  it('a shipment, a customer return, a count and an unknown kind are plain words even with every gate', () => {
    expect(sourceOf(m('fulfilment', RECEIPT_ID, 'fulfilment'), ALL)).toEqual({ kind: 'text', word: 'fulfilment' });
    expect(sourceOf(m('sales_return', RECEIPT_ID, 'return'), ALL)).toEqual({ kind: 'text', word: 'customerReturn' });
    expect(sourceOf(m(null, null, 'adjustment'), ALL)).toEqual({ kind: 'text', word: 'none' });
    expect(sourceOf(m('xyz', RECEIPT_ID, 'receipt'), ALL)).toEqual({ kind: 'text', word: 'other' });
    expect(sourceWord(m('xyz', RECEIPT_ID, 'receipt'))).not.toBe('xyz');
    // An inherited key is not a table row.
    expect(sourceOf(m('constructor', RECEIPT_ID, 'receipt'), ALL)).toEqual({ kind: 'text', word: 'other' });
  });

  it('with no gate at all, nothing links', () => {
    for (const [t, r] of [['goods_receipt', 'receipt'], ['stock_movement', 'transfer_out'], ['stock_movement', 'transfer_in']] as const) {
      expect(sourceOf(m(t, RECEIPT_ID, r), NONE).kind).toBe('text');
    }
  });
});

describe('6.2-2 — a transfer leg names and links its partner', () => {
  it('transfer_out → matchingMoveIn, transfer_in → matchingMoveOut, each linked to /stock-movements/<source_id> with the GetStockMovement gate', () => {
    expect(sourceOf(m('stock_movement', PARTNER_ID, 'transfer_out'), ALL)).toEqual({ kind: 'link', href: `/stock-movements/${PARTNER_ID}`, word: 'matchingMoveIn' });
    expect(sourceOf(m('stock_movement', PARTNER_ID, 'transfer_in'), ALL)).toEqual({ kind: 'link', href: `/stock-movements/${PARTNER_ID}`, word: 'matchingMoveOut' });
    expect(sourceOf(m('stock_movement', PARTNER_ID, 'transfer_in'), { goodsReceipt: true, movement: false })).toEqual({ kind: 'text', word: 'matchingMoveOut' });
  });

  it('RED L-4: a stock_movement source on any other reason is the plain word "other", never a link', () => {
    for (const reason of ['receipt', 'adjustment', 'fulfilment', 'return', 'zzz']) {
      expect(sourceOf(m('stock_movement', PARTNER_ID, reason), ALL)).toEqual({ kind: 'text', word: 'other' });
    }
  });
});

describe('6.2 (RED L-4) — a link only for a source_id that is a record id', () => {
  it('a source_id that is not a canonical uuid (or is null) gives the plain word, never an href built from it', () => {
    for (const id of [null, '', 'not-a-uuid', '../admin/users', RECEIPT_ID.toUpperCase(), `${RECEIPT_ID}/x`]) {
      expect(sourceOf(m('goods_receipt', id, 'receipt'), ALL)).toEqual({ kind: 'text', word: 'goodsReceipt' });
      expect(sourceOf(m('stock_movement', id, 'transfer_out'), ALL)).toEqual({ kind: 'text', word: 'matchingMoveIn' });
    }
  });
});

describe('6.2-3 (RED L-5) — every linked kind opens a record page that is built', () => {
  const recordPage = (segment: string) => path.join(FRONTEND, 'src/app/(app)', segment, '[id]', 'page.tsx');
  const unbuilt = (table: Readonly<Record<string, { segment: string }>>) => Object.entries(table).filter(([, row]) => !existsSync(recordPage(row.segment))).map(([kind]) => kind);

  it('goods-receipts and stock-movements: each linked row’s src/app/(app)/<segment>/[id]/page.tsx exists on disk', () => {
    expect(Object.keys(LINKED_SOURCES).sort()).toEqual(['goods_receipt', 'stock_movement']);
    expect(unbuilt(LINKED_SOURCES)).toEqual([]);
  });

  it('planted control: a shipment row linking to /fulfilments/<id> is red (no such record page)', () => {
    expect(unbuilt({ ...LINKED_SOURCES, fulfilment: { segment: 'fulfilments' } })).toEqual(['fulfilment']);
  });
});

describe('6.2-4 — the result never carries source_id outside the href', () => {
  it('a plain-word result holds no id, and a link holds it only in its href', () => {
    const cases = [m('goods_receipt', RECEIPT_ID, 'receipt'), m('fulfilment', RECEIPT_ID, 'fulfilment'), m('sales_return', RECEIPT_ID, 'return'), m('stock_movement', RECEIPT_ID, 'adjustment'), m('xyz', RECEIPT_ID, 'receipt')];
    for (const c of cases) {
      for (const gates of [ALL, NONE]) {
        const r = sourceOf(c, gates);
        const { href, ...rest } = r as { href?: string };
        expect(JSON.stringify(rest)).not.toContain(RECEIPT_ID);
        if (r.kind === 'text') expect(href).toBeUndefined();
      }
    }
  });
});

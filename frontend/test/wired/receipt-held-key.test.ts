import { describe, expect, it } from 'vitest';
import type { ApiCaller, ApiResult, CallOptions } from '@/lib/bff/apiCall';
import { heldKey } from '@/lib/bff/heldKey';
import type { GoodsReceipt, PurchaseOrder, PurchaseOrderLine } from '@/lib/api/purchasing';
import { receiptCreate } from '@/lib/purchasing/wired';

// GR-2 H-1 (gr-2-h1-spar.md "Proposed converged text", test (f), table-driven per spar M-1): one Idempotency-Key per
// receive action, held until it is known CONSUMED. Only a well-formed 201 and a 409 `idempotency-key-conflict` give the
// next call a NEW key; every other answer — each exit of the refused arm, every failure kind, a throwing caller — keeps it.
// The fake caller answers by operation, so the table pins `receiptCreate` itself, independent of the transport.

const OID = 'd1b2c3d4-0000-4000-8000-0000000000a1';
const ORDER: PurchaseOrder = {
  id: OID,
  order_number: 'PO-000042',
  status: 'ordered',
  supplier: { id: 'b1b2c3d4-0000-4000-8000-0000000000c1', name: 'Acme Supplies' },
  warehouse: { id: 'c1b2c3d4-0000-4000-8000-0000000000b1', name: 'Main warehouse' },
  currency_code: 'GBP',
  total_amount: '29.8750',
  ordered_at: '2026-03-01T10:00:00Z',
  expected_at: null,
  created_by: { id: 'e1b2c3d4-0000-4000-8000-000000000001', display_name: 'Bea Buyer' },
  archived_at: null,
};
const RECEIVED: PurchaseOrder = { ...ORDER, status: 'received' };
const LINE: PurchaseOrderLine = {
  id: 'f1b2c3d4-0000-4000-8000-000000000001',
  line_number: 1,
  inventory_item: { id: 'a1b2c3d4-0000-4000-8000-000000000001', sku: 'SKU-1', name: 'Item 1' },
  quantity: '5.000000',
  quantity_received: '1.000000',
  quantity_outstanding: '4.000000',
  unit_cost: '12.5000',
  archived_at: null,
  unit_of_measure_code: 'EA',
};
const RECEIPT: GoodsReceipt = {
  id: '71b2c3d4-0000-4000-8000-0000000000e1',
  receipt_number: 'GR-000007',
  received_at: '2026-03-04T11:00:00Z',
  purchase_order: { id: OID, order_number: 'PO-000042' },
  received_by: { id: 'e1b2c3d4-0000-4000-8000-000000000002', display_name: 'Wanda Warehouse' },
  lines: [],
};
const SENT = [{ purchase_order_line_id: LINE.id, quantity: '1', storage_location_id: '91b2c3d4-0000-4000-8000-0000000000d1' }];

type Answer = ApiResult | 'throw';
const ok = (body: unknown): ApiResult => ({ kind: 'ok', status: 200, body });
const created = (body: unknown): ApiResult => ({ kind: 'ok', status: 201, body });
const refusal = (rule: string, extra: Partial<Extract<ApiResult, { kind: 'refused' }>> = {}): ApiResult => ({ kind: 'refused', rule, message: 'sentence', ...extra });
const CONFLICT: ApiResult = { kind: 'refused', rule: '', message: '', problem: 'idempotency-key-conflict' };
const LINES_PAGE = ok({ items: [LINE], next_cursor: null, previous_cursor: null });

/** A caller scripted per operation: each operation id has its own queue; the receipt POST records the key it was given. */
function scripted(script: Record<string, Answer[]>) {
  const keys: (string | undefined)[] = [];
  const queues = new Map(Object.entries(script).map(([k, v]) => [k, [...v]]));
  const caller: ApiCaller = {
    async call(operationId: string, _params?: Readonly<Record<string, string>>, _body?: unknown, options?: CallOptions) {
      if (operationId === 'CreateGoodsReceipt') keys.push(options?.idempotencyKey);
      const next = queues.get(operationId)?.shift();
      if (next === undefined) throw new Error(`unscripted ${operationId}`);
      if (next === 'throw') throw new TypeError('Failed to fetch');
      return next;
    },
    operation() {
      throw new Error('not used');
    },
  };
  return { caller, keys };
}

let minted = 0;
const counting = () => `00000000-0000-4000-8000-${String(++minted).padStart(12, '0')}`;

interface Row {
  name: string;
  /** The first press's answers by operation (the POST first, then whatever re-reads that answer leads to). */
  first: Record<string, Answer[]>;
  /** Whether the second press must carry a NEW key. */
  rotates: boolean;
}

const rows: Row[] = [
  { name: 'a well-formed 201', first: { CreateGoodsReceipt: [created(RECEIPT)], GetPurchaseOrder: [ok(ORDER)] }, rotates: true },
  { name: '409 idempotency-key-conflict (receivable re-read)', first: { CreateGoodsReceipt: [CONFLICT], GetPurchaseOrder: [ok(ORDER)], ListPurchaseOrderLines: [LINES_PAGE] }, rotates: true },
  { name: '409 idempotency-key-conflict (order no longer receivable)', first: { CreateGoodsReceipt: [CONFLICT], GetPurchaseOrder: [ok(RECEIVED)] }, rotates: true },
  { name: '409 idempotency-key-conflict (re-read failed)', first: { CreateGoodsReceipt: [CONFLICT], GetPurchaseOrder: [{ kind: 'server-error' }] }, rotates: true },
  { name: 'a 201 whose body is not a goods receipt (h)', first: { CreateGoodsReceipt: [created({ id: 'x' })] }, rotates: false },
  { name: '503 (server-error)', first: { CreateGoodsReceipt: [{ kind: 'server-error' }] }, rotates: false },
  { name: 'a rejected fetch (unreachable)', first: { CreateGoodsReceipt: [{ kind: 'unreachable' }] }, rotates: false },
  { name: 'a throwing caller', first: { CreateGoodsReceipt: ['throw'] }, rotates: false },
  { name: '404 (not-found)', first: { CreateGoodsReceipt: [{ kind: 'not-found' }] }, rotates: false },
  { name: '403 (forbidden)', first: { CreateGoodsReceipt: [{ kind: 'forbidden' }] }, rotates: false },
  { name: '401 (session-ended)', first: { CreateGoodsReceipt: [{ kind: 'session-ended' }] }, rotates: false },
  { name: '429 (rate-limited)', first: { CreateGoodsReceipt: [{ kind: 'rate-limited' }] }, rotates: false },
  { name: 'key-expired', first: { CreateGoodsReceipt: [{ kind: 'key-expired' }] }, rotates: false },
  { name: '422 with violations', first: { CreateGoodsReceipt: [refusal('not_a_decimal', { violations: [{ pointer: '/lines/0/quantity', code: 'not_a_decimal', message: 'm' }] })] }, rotates: false },
  { name: '409 transaction.contention', first: { CreateGoodsReceipt: [refusal('transaction.contention')] }, rotates: false },
  { name: '409 stock_level.on_hand_fits', first: { CreateGoodsReceipt: [refusal('stock_level.on_hand_fits', { message: 'Order line 1: x' })] }, rotates: false },
  { name: '409 over_received (receivable re-read)', first: { CreateGoodsReceipt: [refusal('purchase_order_line.is_not_over_received')], GetPurchaseOrder: [ok(ORDER)], ListPurchaseOrderLines: [LINES_PAGE] }, rotates: false },
  { name: '409 over_received (lines re-read failed)', first: { CreateGoodsReceipt: [refusal('purchase_order_line.is_not_over_received')], GetPurchaseOrder: [ok(ORDER)], ListPurchaseOrderLines: [{ kind: 'server-error' }] }, rotates: false },
  { name: 'any 409 whose re-read shows a non-receivable order', first: { CreateGoodsReceipt: [refusal('purchase_order.status_transition_is_legal')], GetPurchaseOrder: [ok(RECEIVED)] }, rotates: false },
  { name: 'any other 409 (receivable re-read)', first: { CreateGoodsReceipt: [refusal('some.other_rule')], GetPurchaseOrder: [ok(ORDER)] }, rotates: false },
  { name: 'any other 409 (re-read failed)', first: { CreateGoodsReceipt: [refusal('some.other_rule')], GetPurchaseOrder: [{ kind: 'server-error' }] }, rotates: false },
  { name: '409 stale-record (no discriminator, receivable re-read)', first: { CreateGoodsReceipt: [refusal('', { message: '' })], GetPurchaseOrder: [ok(ORDER)] }, rotates: false },
];

describe('GR-2 H-1 (f): receiptCreate holds its key until it is consumed', () => {
  it.each(rows)('$name → the next press carries a new key: $rotates', async ({ first, rotates }) => {
    const script: Record<string, Answer[]> = { ...first, CreateGoodsReceipt: [...(first.CreateGoodsReceipt ?? []), created(RECEIPT)], GetPurchaseOrder: [...(first.GetPurchaseOrder ?? []), ok(ORDER)] };
    const { caller, keys } = scripted(script);
    const record = receiptCreate(caller, OID, counting);
    await record(SENT).catch(() => undefined);
    await record(SENT);
    expect(keys).toHaveLength(2);
    expect(keys[0]).toMatch(/^[0-9a-f-]{36}$/);
    if (rotates) expect(keys[1]).not.toBe(keys[0]);
    else expect(keys[1]).toBe(keys[0]);
  });

  it('the conflict answers earlier-recorded in its three variants, never the plain refusal', async () => {
    const receivable = scripted({ CreateGoodsReceipt: [CONFLICT], GetPurchaseOrder: [ok(ORDER)], ListPurchaseOrderLines: [LINES_PAGE] });
    expect(await receiptCreate(receivable.caller, OID, counting)(SENT)).toEqual({ kind: 'earlier-recorded', order: ORDER, lines: [LINE] });
    const done = scripted({ CreateGoodsReceipt: [CONFLICT], GetPurchaseOrder: [ok(RECEIVED)] });
    expect(await receiptCreate(done.caller, OID, counting)(SENT)).toEqual({ kind: 'earlier-recorded', order: RECEIVED, lines: null });
    const failed = scripted({ CreateGoodsReceipt: [CONFLICT], GetPurchaseOrder: [{ kind: 'server-error' }] });
    expect(await receiptCreate(failed.caller, OID, counting)(SENT)).toEqual({ kind: 'earlier-recorded', order: null, lines: null });
    const linesFailed = scripted({ CreateGoodsReceipt: [CONFLICT], GetPurchaseOrder: [ok(ORDER)], ListPurchaseOrderLines: [{ kind: 'server-error' }] });
    expect(await receiptCreate(linesFailed.caller, OID, counting)(SENT)).toEqual({ kind: 'earlier-recorded', order: null, lines: null });
  });

  // gr-2-round2-red.md L-1: once the key is consumed, a re-read that throws must not turn into a rejection (the page would show
  // `interrupted`, whose "it won't be recorded twice" is then false).
  it('a re-read that throws after the key is consumed still answers recorded / earlier-recorded, never a rejection', async () => {
    const after201 = scripted({ CreateGoodsReceipt: [created(RECEIPT)], GetPurchaseOrder: ['throw'] });
    expect(await receiptCreate(after201.caller, OID, counting)(SENT)).toEqual({ kind: 'recorded', receipt: RECEIPT, order: null });
    const afterConflict = scripted({ CreateGoodsReceipt: [CONFLICT], GetPurchaseOrder: ['throw'] });
    expect(await receiptCreate(afterConflict.caller, OID, counting)(SENT)).toEqual({ kind: 'earlier-recorded', order: null, lines: null });
    const linesThrow = scripted({ CreateGoodsReceipt: [CONFLICT], GetPurchaseOrder: [ok(ORDER)], ListPurchaseOrderLines: ['throw'] });
    expect(await receiptCreate(linesThrow.caller, OID, counting)(SENT)).toEqual({ kind: 'earlier-recorded', order: null, lines: null });
  });

  // gr-2-round2-red.md L-5: a lines re-read that is not the whole set (a next cursor) is treated as a failed re-read.
  it('a lines re-read with a next cursor is not the whole set: conflict → earlier-recorded with neither; over_received → the refusal as sent', async () => {
    const partial = ok({ items: [LINE], next_cursor: 'c1', previous_cursor: null });
    const conflict = scripted({ CreateGoodsReceipt: [CONFLICT], GetPurchaseOrder: [ok(ORDER)], ListPurchaseOrderLines: [partial] });
    expect(await receiptCreate(conflict.caller, OID, counting)(SENT)).toEqual({ kind: 'earlier-recorded', order: null, lines: null });
    const over = refusal('purchase_order_line.is_not_over_received');
    const r1 = scripted({ CreateGoodsReceipt: [over], GetPurchaseOrder: [ok(ORDER)], ListPurchaseOrderLines: [partial] });
    expect(await receiptCreate(r1.caller, OID, counting)(SENT)).toEqual(over);
  });

  it('the body is never compared: a changed body after an unknown outcome goes out under the held key', async () => {
    const { caller, keys } = scripted({ CreateGoodsReceipt: [{ kind: 'server-error' }, created(RECEIPT)], GetPurchaseOrder: [ok(ORDER)] });
    const record = receiptCreate(caller, OID, counting);
    await record(SENT);
    await record([{ ...SENT[0]!, quantity: '2' }]);
    expect(keys[1]).toBe(keys[0]);
  });

  it('two receive actions (two page instances) never share a key', async () => {
    const a = scripted({ CreateGoodsReceipt: [{ kind: 'server-error' }] });
    const b = scripted({ CreateGoodsReceipt: [{ kind: 'server-error' }] });
    await receiptCreate(a.caller, OID, counting)(SENT);
    await receiptCreate(b.caller, OID, counting)(SENT);
    expect(a.keys[0]).not.toBe(b.keys[0]);
  });
});

describe('heldKey', () => {
  it('mints once, holds until consumed(), then mints a new one; never mints before key() is asked', () => {
    let n = 0;
    const k = heldKey(() => `k${++n}`);
    expect(n).toBe(0);
    expect(k.key()).toBe('k1');
    expect(k.key()).toBe('k1');
    k.consumed();
    expect(n).toBe(1);
    expect(k.key()).toBe('k2');
    expect(k.key()).toBe('k2');
  });
});

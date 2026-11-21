import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ push: vi.fn(), refresh: vi.fn(), replace: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ push: nav.push, refresh: nav.refresh, replace: nav.replace }), usePathname: () => '/purchase-orders', useSearchParams: () => new URLSearchParams() }));
import { takeArrivalMessage } from '@/app/(app)/arrivalMessage';
import { PurchaseOrderPage } from '@/app/(app)/purchase-orders/[id]/PurchaseOrderPage';
import { ReceivePage } from '@/app/(app)/purchase-orders/[id]/receive/ReceivePage';
import { isNotReceived } from '@/lib/purchasing/results';
import type { GoodsReceipt, PurchaseOrder, PurchaseOrderLine } from '@/lib/api/purchasing';
import { loadLocationChoices, type LocationChoice } from '@/lib/purchasing/load';
import { held, json, page, problem, refused, renderWired, stubFetch, type Recorded } from './harness';

// GR-2 — receive a delivery against a purchase order (purchase-orders-final-red.md BUILDER'S APPLY LIST GR-2; PO D-12
// items 1–8; plan §2.6, Revision 1 R1-3 / R1-5 GR-2; UX B-8 / R1-B `RECEIVE`, R1a-1 row 11). Every Receiving-now box
// starts EMPTY beside Still to come (D-12); the line's served unit (BK-33) beside each quantity; a location per row from
// the warehouse's live locations, none preselected; the zero rule (M-7 / L-9); UX's confirm dialog with the count (H-2);
// one request, a fresh Idempotency-Key per submit; the request-index → row map (M-6); R1 re-read keeping typed values
// (X-15); R2 beside the location with the locations re-read; R3 in the client's words (M-1); R6 → nothing to receive.

afterEach(() => {
  vi.unstubAllGlobals();
  nav.push.mockReset();
  takeArrivalMessage('');
});

const OID = 'd1b2c3d4-0000-4000-8000-0000000000a1';
const WID = 'c1b2c3d4-0000-4000-8000-0000000000b1';
const ORDER: PurchaseOrder = {
  id: OID,
  order_number: 'PO-000042',
  status: 'ordered',
  supplier: { id: 'b1b2c3d4-0000-4000-8000-0000000000c1', name: 'Acme Supplies' },
  warehouse: { id: WID, name: 'Main warehouse' },
  currency_code: 'GBP',
  total_amount: '29.8750',
  ordered_at: '2026-03-01T10:00:00Z',
  expected_at: null,
  created_by: { id: 'e1b2c3d4-0000-4000-8000-000000000001', display_name: 'Bea Buyer' },
  archived_at: null,
};
const line = (n: number, over: Partial<PurchaseOrderLine> = {}): PurchaseOrderLine => ({
  id: `f1b2c3d4-0000-4000-8000-${String(n).padStart(12, '0')}`,
  line_number: n,
  inventory_item: { id: `a1b2c3d4-0000-4000-8000-${String(n).padStart(12, '0')}`, sku: `SKU-${n}`, name: `Item ${n}` },
  quantity: '5.000000',
  quantity_received: '1.000000',
  quantity_outstanding: '4.000000',
  unit_cost: '12.5000',
  archived_at: null,
  unit_of_measure_code: 'EA',
  ...over,
});
/** Three lines with something to come (two units, so a cell reading another row's unit goes red) and one received in full. */
const L1 = line(1);
const L2 = line(2, { quantity: '2.500000', quantity_received: '0.000000', quantity_outstanding: '2.500000', unit_of_measure_code: 'KG' });
const L3 = line(3, { quantity: '3.000000', quantity_received: '0.000000', quantity_outstanding: '3.000000' });
const DONE = line(4, { quantity: '1.000000', quantity_received: '1.000000', quantity_outstanding: '0.000000' });
const LINES = [L1, L2, DONE, L3];
const BIN_A: LocationChoice = { id: '91b2c3d4-0000-4000-8000-0000000000d1', code: 'A-01', kind: 'bin' };
const BIN_Q: LocationChoice = { id: '91b2c3d4-0000-4000-8000-0000000000d2', code: 'Q-01', kind: 'quarantine' };
const LOCATIONS = [BIN_A, BIN_Q];
const RECEIPT: GoodsReceipt = {
  id: '71b2c3d4-0000-4000-8000-0000000000e1',
  receipt_number: 'GR-000007',
  received_at: '2026-03-04T11:00:00Z',
  purchase_order: { id: OID, order_number: 'PO-000042' },
  received_by: { id: 'e1b2c3d4-0000-4000-8000-000000000002', display_name: 'Wanda Warehouse' },
  lines: [],
};

const URL = `/bff/api/purchase-orders/${OID}`;
const POST_URL = `${URL}/goods-receipts`;
const LOCATIONS_URL = `/bff/api/warehouses/${WID}/locations`;
const show = (over: { order?: PurchaseOrder; lines?: PurchaseOrderLine[]; locations?: LocationChoice[] } = {}) =>
  renderWired(<ReceivePage order={over.order ?? ORDER} lines={over.lines ?? LINES} locations={over.locations ?? LOCATIONS} />);
const qty = (n: number) => screen.getByRole('textbox', { name: `Receiving now, line ${n}` });
const loc = (n: number) => screen.getByRole('combobox', { name: `Location, line ${n}` });
const record = () => screen.getByRole('button', { name: 'Record receipt' });
const dialog = () => screen.getByRole('dialog', { name: 'Record this receipt?' });
const posts = (calls: Recorded[]) => calls.filter((c) => c.method === 'POST');
const bodyOf = (c: Recorded) => JSON.parse(c.body ?? 'null') as { lines: { purchase_order_line_id: string; quantity: string; storage_location_id: string }[] };
const R1_SENTENCE = 'Order line 1 would reach 6 received of 5 ordered. Over-receipt is not accepted.';
// GR-2 H-1 (UX FINAL, gr-2-h1-ux.md): the interrupted sentence offers pressing again — true only with the held key.
const INTERRUPTED = "We can't tell whether the receipt was recorded. Stay on this page and press Record receipt again: it won't be recorded twice.";
// ux-batch-2026-11-19.md B-4 (L-7): "kept on the lines still listed" (an earlier attempt may have finished some lines).
const EARLIER = "Your earlier attempt was recorded, so this one wasn't. What's still to come has been updated, and your entries are kept on the lines still listed, so check them before you record again.";
const CONFLICT_DETAIL = 'This Idempotency-Key was already used with a different request.';
const conflict = () => problem(409, 'idempotency-key-conflict', { detail: CONFLICT_DETAIL });
const keysOf = (calls: Recorded[]) => posts(calls).map((c) => c.headers.get('idempotency-key'));

async function enter(u: ReturnType<typeof userEvent.setup>, n: number, quantity: string, location: LocationChoice | null = BIN_A) {
  await u.type(qty(n), quantity);
  if (location !== null) await u.selectOptions(loc(n), location.id);
}
async function confirmInDialog(u: ReturnType<typeof userEvent.setup>) {
  await u.click(record());
  await u.click(within(dialog()).getByRole('button', { name: 'Record receipt' }));
}

describe('/purchase-orders/[id]/receive — the rows (D-12 items 1–2, 8; UX B-8)', () => {
  it('h1, intro and notes; one row per line with something still to come, served order; a line received in full is not a row', () => {
    show();
    expect(screen.getByRole('heading', { level: 1, name: 'Receive a delivery for PO-000042' })).toBeInTheDocument();
    expect(screen.getByText('From Acme Supplies, into Main warehouse.')).toBeInTheDocument();
    expect(screen.getByText("Enter what arrived on each line. A line left empty or at 0 isn't recorded.")).toBeInTheDocument();
    expect(screen.getByText("Lines already received in full aren't listed.")).toBeInTheDocument();
    const rows = [...document.querySelectorAll('tbody tr')].map((r) => r.querySelector('td')?.textContent);
    expect(rows).toEqual(['1', '2', '3']);
    expect(screen.queryByRole('textbox', { name: 'Receiving now, line 4' })).toBeNull();
    expect([...document.querySelectorAll('thead th')].map((t) => t.textContent)).toEqual(['Line', 'Item', 'Ordered', 'Received so far', 'Still to come', 'Receiving now', 'Location']);
  });

  it('D-12 item 8 (a): every Receiving-now box is EMPTY on load; Still to come shows the served outstanding with the line\'s own unit (BK-33)', () => {
    show();
    for (const n of [1, 2, 3]) expect(qty(n)).toHaveValue('');
    const cells = (n: number) => [...qty(n).closest('tr')!.querySelectorAll('td')].map((td) => td.textContent);
    expect(cells(1).slice(2, 5)).toEqual(['5 EA', '1 EA', '4 EA']);
    expect(cells(2).slice(2, 5)).toEqual(['2.5 KG', '0 KG', '2.5 KG']);
    // The box's suffix is the line's served code (shown), never the item's.
    expect(qty(2).parentElement!.querySelector('[aria-hidden="true"]')?.textContent).toBe('KG');
    expect(qty(1).parentElement!.querySelector('[aria-hidden="true"]')?.textContent).toBe('EA');
  });

  it('D-12 item 8 (b) / item 2: no control fills a row or several — the form\'s only button is Record receipt', () => {
    show();
    const form = record().closest('form')!;
    expect(within(form).getAllByRole('button').map((b) => b.textContent)).toEqual(['Record receipt']);
    expect(screen.queryByRole('button', { name: /fill|still to come|all/i })).toBeNull();
  });

  it('Location: the warehouse\'s served live locations as "{code} — {kind}", after an unselected prompt, none preselected; the hint names the warehouse', () => {
    show();
    const options = within(loc(1)).getAllByRole('option');
    expect(options.map((o) => o.textContent)).toEqual(['Choose a location', 'A-01 — Bin', 'Q-01 — Quarantine']);
    for (const n of [1, 2, 3]) expect(loc(n)).toHaveValue('');
    expect(loc(1)).toHaveAccessibleDescription(expect.stringContaining("Only locations in Main warehouse that aren't archived are listed."));
  });

  it('per-row names are unique (M-9 / L-10): Receiving now, line {n} and Location, line {n}', () => {
    show();
    expect(screen.getAllByRole('textbox', { name: /^Receiving now, line \d+$/ })).toHaveLength(3);
    expect(screen.getAllByRole('combobox', { name: /^Location, line \d+$/ })).toHaveLength(3);
  });

  it('never a stock figure on the page (G-47): no "in stock", "on hand" or "available"', () => {
    show();
    expect(document.body.textContent ?? '').not.toMatch(/in stock|on hand|available/i);
  });

  it('never a cost or a total on the page (UX D-6): neither the lines\' unit cost nor the order total is rendered', () => {
    // Distinctive values: textContent joins adjacent cells with no space, so the fixture's '12.5000' could be faked by
    // "line 1" next to a quantity "2.5". 987.65 / 876.54 occur nowhere else in the fixture.
    show({ order: { ...ORDER, total_amount: '876.5432' }, lines: LINES.map((l) => ({ ...l, unit_cost: '987.6543' })) });
    expect(document.body.textContent ?? '').not.toMatch(/987\.65|876\.54/);
  });
});

describe('the zero rule (M-7 with L-9) and the person\'s own checks — nothing sent', () => {
  it('isNotReceived: empty, "0", "00", "0.000", " 0 ", "0.0" are not received; "0.5", "1", "10" are', () => {
    for (const t of ['', '0', '00', '0.000', ' 0 ', '0.0', '   ']) expect(isNotReceived(t)).toBe(true);
    for (const t of ['0.5', '1', '10', '0.0001', '00.10']) expect(isNotReceived(t)).toBe(false);
  });

  it('every row empty or zero → nothingToRecord beside the first row, focus there, no dialog and no request', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    show();
    await enter(u, 2, '0.000');
    await enter(u, 3, ' 0 ');
    await u.click(record());
    expect(qty(1)).toHaveAccessibleDescription(expect.stringContaining("Enter what arrived on at least one line. A line left empty or at 0 isn't recorded."));
    expect(qty(1)).toHaveFocus();
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(calls).toEqual([]);
  });

  it('a received row without a location → locationRequired(n) beside it, focus there, no request; a location on an empty row asks nothing', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    show();
    await u.selectOptions(loc(1), BIN_A.id);
    await enter(u, 2, '1', null);
    await u.click(record());
    expect(loc(2)).toHaveAccessibleDescription(expect.stringContaining('Choose where line 2 goes.'));
    expect(loc(2)).toHaveFocus();
    expect(loc(1)).not.toHaveAccessibleDescription(expect.stringContaining('Choose where'));
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(calls).toEqual([]);
  });
});

describe('the confirm dialog (H-2) and the request', () => {
  it('Record receipt opens the dialog with the count and the warehouse; no request until its confirm; Go back sends nothing and keeps the entries', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    show();
    await enter(u, 1, '2');
    await enter(u, 3, '1');
    await u.click(record());
    expect(within(dialog()).getByText("This records 2 lines into Main warehouse, in the locations you chose, straight away. A receipt can't be changed or deleted afterwards.")).toBeInTheDocument();
    expect(within(dialog()).getByRole('button', { name: 'Go back' })).toHaveFocus();
    expect(calls).toEqual([]);
    await u.click(within(dialog()).getByRole('button', { name: 'Go back' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(calls).toEqual([]);
    expect(qty(1)).toHaveValue('2');
  });

  it('one line → "1 line" in the dialog', async () => {
    stubFetch([]);
    const u = userEvent.setup();
    show();
    await enter(u, 2, '0.5');
    await u.click(record());
    expect(within(dialog()).getByText(/^This records 1 line into Main warehouse/)).toBeInTheDocument();
  });

  it('201: POSTs one {purchase_order_line_id, quantity as typed, storage_location_id} per received row in row order, zero/empty rows left out, never received_at, with an Idempotency-Key; re-reads the order; leaves the arrival sentence and goes to the order', async () => {
    const calls = stubFetch([json(201, RECEIPT), json(200, { ...ORDER, status: 'partially_received' })]);
    const u = userEvent.setup();
    show();
    await enter(u, 1, '2.50');
    await enter(u, 2, '0');
    await enter(u, 3, '1', BIN_Q);
    await confirmInDialog(u);
    await waitFor(() => expect(nav.push).toHaveBeenCalledWith(`/purchase-orders/${OID}`));
    const post = posts(calls);
    expect(post).toHaveLength(1);
    expect(post[0]!.url).toBe(POST_URL);
    expect(post[0]!.headers.get('idempotency-key')).toMatch(/^[0-9a-f-]{36}$/);
    expect(bodyOf(post[0]!)).toEqual({
      lines: [
        { purchase_order_line_id: L1.id, quantity: '2.50', storage_location_id: BIN_A.id },
        { purchase_order_line_id: L3.id, quantity: '1', storage_location_id: BIN_Q.id },
      ],
    });
    expect(calls.map((c) => `${c.method} ${c.url}`)).toEqual([`POST ${POST_URL}`, `GET ${URL}`]);
    expect(takeArrivalMessage(`/purchase-orders/${OID}`)).toBe('GR-000007 recorded: 2 lines received into Main warehouse, and stock there is updated. The order is now partly received.');
    // The form stays busy after a 201: no second receipt from another press.
    expect(record()).toHaveAttribute('aria-busy', 'true');
  });

  it('201 whose re-read fails → the arrival sentence without its status sentence; one line → "1 line"', async () => {
    stubFetch([json(201, RECEIPT), json(500, {})]);
    const u = userEvent.setup();
    show();
    await enter(u, 2, '2.5');
    await confirmInDialog(u);
    await waitFor(() => expect(nav.push).toHaveBeenCalled());
    expect(takeArrivalMessage(`/purchase-orders/${OID}`)).toBe('GR-000007 recorded: 1 line received into Main warehouse, and stock there is updated.');
  });

  it('(a) the SAME Idempotency-Key after an unknown outcome (H-1); never two requests at once (a second confirm press while one is out sends nothing)', async () => {
    const first = held();
    const calls = stubFetch([first.reply, json(500, {}), json(201, RECEIPT), json(200, ORDER)]);
    const u = userEvent.setup();
    show();
    await enter(u, 1, '1');
    await u.click(record());
    const confirmButton = within(dialog()).getByRole('button', { name: 'Record receipt' });
    await u.click(confirmButton);
    await u.click(confirmButton);
    expect(posts(calls)).toHaveLength(1);
    first.release(problem(503, 'service-unavailable'));
    await screen.findByText(INTERRUPTED);
    expect(qty(1)).toHaveValue('1');
    await confirmInDialog(u);
    await waitFor(() => expect(posts(calls)).toHaveLength(2));
    const [a, b] = keysOf(calls);
    expect(a).toMatch(/^[0-9a-f-]{36}$/);
    expect(b).toBe(a);
  });
});

describe('refusals (plan §2.6; R1-5 GR-2)', () => {
  it('M-6: row 1 left empty, a bad quantity on the SECOND row sent → the sentence is beside line 3 (sent at index 1), never the row at index 1 on screen', async () => {
    stubFetch([problem(422, 'validation-failed', { errors: [{ pointer: '/lines/1/quantity', code: 'not_a_decimal', message: 'Enter a number greater than 0.' }] })]);
    const u = userEvent.setup();
    show();
    await enter(u, 2, '1');
    await enter(u, 3, '1x');
    await confirmInDialog(u);
    await waitFor(() => expect(qty(3)).toHaveAccessibleDescription(expect.stringContaining('Enter a number greater than 0.')));
    expect(qty(3)).toHaveFocus();
    expect(qty(2)).not.toHaveAccessibleDescription(expect.stringContaining('Enter a number'));
    expect(qty(1)).not.toHaveAccessibleDescription(expect.stringContaining('Enter a number'));
    expect(qty(3)).toHaveValue('1x');
  });

  it('a 422 sentence the row map cannot place (index past what was sent) goes to the banner, not lost', async () => {
    stubFetch([problem(422, 'validation-failed', { errors: [{ pointer: '/lines/7/quantity', code: 'x', message: 'Something about a line.' }] })]);
    const u = userEvent.setup();
    show();
    await enter(u, 1, '1');
    await confirmInDialog(u);
    expect(await screen.findByText('Not recorded')).toHaveFocus();
    expect(screen.getByText('Something about a line.')).toBeInTheDocument();
  });

  it('R2: a location sentence goes beside that row\'s Location, and the locations are re-read (an archived one leaves the list)', async () => {
    const calls = stubFetch([
      problem(422, 'validation-failed', { errors: [{ pointer: '/lines/0/storage_location_id', code: 'storage_location.belongs_to_receiving_warehouse', message: "This location can't take goods for this order." }] }),
      json(200, { items: [BIN_A] }),
    ]);
    const u = userEvent.setup();
    show();
    await enter(u, 1, '1', BIN_Q);
    await confirmInDialog(u);
    await waitFor(() => expect(loc(1)).toHaveAccessibleDescription(expect.stringContaining("This location can't take goods for this order.")));
    await waitFor(() => expect(within(loc(1)).getAllByRole('option').map((o) => o.textContent)).toEqual(['Choose a location', 'A-01 — Bin']));
    expect(loc(1)).toHaveValue('');
    expect(calls.map((c) => `${c.method} ${c.url}`)).toEqual([`POST ${POST_URL}`, `GET ${LOCATIONS_URL}`]);
    expect(qty(1)).toHaveValue('1');
  });

  it('X-15: R1 → the order and its lines are re-read; what is still to come is updated, every typed value kept by line, the server sentence and stalePrefill shown', async () => {
    const after = [line(1, { quantity_received: '3.000000', quantity_outstanding: '2.000000' }), L2, DONE, L3];
    const calls = stubFetch([refused('purchase_order_line.is_not_over_received', R1_SENTENCE), json(200, { ...ORDER, status: 'partially_received' }), json(200, page(after))]);
    const u = userEvent.setup();
    show();
    await enter(u, 1, '4');
    await enter(u, 2, '1');
    await confirmInDialog(u);
    await screen.findByText(R1_SENTENCE);
    expect(screen.getByText("Another receipt was recorded on this order since you opened this page. What's still to come has been updated, and your entries are kept on the lines still listed, so check them before you record again.")).toBeInTheDocument();
    expect(screen.getByText('Not recorded')).toHaveFocus();
    const cells = [...qty(1).closest('tr')!.querySelectorAll('td')].map((td) => td.textContent);
    expect(cells.slice(2, 5)).toEqual(['5 EA', '3 EA', '2 EA']);
    expect(qty(1)).toHaveValue('4');
    expect(qty(2)).toHaveValue('1');
    expect(loc(1)).toHaveValue(BIN_A.id);
    expect(calls.map((c) => `${c.method} ${c.url}`)).toEqual([`POST ${POST_URL}`, `GET ${URL}`, `GET ${URL}/lines`]);
  });

  it('R1 whose lines re-read fails → the refusal as sent; typed values kept (never dropped on a failed reading)', async () => {
    stubFetch([refused('purchase_order_line.is_not_over_received', R1_SENTENCE), json(200, ORDER), json(500, {})]);
    const u = userEvent.setup();
    show();
    await enter(u, 1, '4');
    await confirmInDialog(u);
    await screen.findByText(R1_SENTENCE);
    expect(qty(1)).toHaveValue('4');
  });

  it('R6: a status refusal whose re-read shows the order received in full → orderChanged + nothingToReceive, no form, focus on the h1', async () => {
    stubFetch([refused('purchase_order.status_transition_is_legal', 'Cannot record a receipt on a received order.'), json(200, { ...ORDER, status: 'received' })]);
    const u = userEvent.setup();
    show();
    await enter(u, 1, '1');
    await confirmInDialog(u);
    await screen.findByText("PO-000042 changed while you were on this page: it's now received in full, so nothing was recorded.");
    expect(screen.getByText("There's nothing to receive on PO-000042: it's received in full.")).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Record receipt' })).toBeNull();
    expect(screen.getByRole('link', { name: 'Back to PO-000042' })).toHaveAttribute('href', `/purchase-orders/${OID}`);
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveFocus());
    expect(screen.queryByText('Cannot record a receipt on a received order.')).toBeNull();
  });

  it('M-1: R3 (stock_level.on_hand_fits) shows the client sentence for its order line, never the served words "stock count"', async () => {
    stubFetch([refused('stock_level.on_hand_fits', 'Order line 3: receiving this would take the stock of this item in this location past the largest quantity that can be recorded. Nothing was received. The figure on record there may be wrong; it can be corrected by a stock count.')]);
    const u = userEvent.setup();
    show();
    await enter(u, 3, '1');
    await confirmInDialog(u);
    await screen.findByText('Order line 3: receiving this would take the stock of this item in this location past the largest quantity that can be recorded. Nothing was received. The figure on record there may be wrong.');
    expect(document.body.textContent ?? '').not.toMatch(/stock count/);
  });

  it('any other 409 over a still receivable order → the server sentence as sent under Not recorded; focus on the banner; entries kept', async () => {
    stubFetch([refused('transaction.contention', 'Someone else is changing this. Try again.')]);
    const u = userEvent.setup();
    show();
    await enter(u, 1, '1');
    await confirmInDialog(u);
    await screen.findByText('Someone else is changing this. Try again.');
    expect(qty(1)).toHaveValue('1');
  });
});

describe('the no-form state (plan §2.6 "nothing to receive")', () => {
  it('an order nothing can be received on (cancelled) → nothingToReceive and Back to {number}; no table, no Record receipt', () => {
    show({ order: { ...ORDER, status: 'cancelled' }, lines: [], locations: [] });
    expect(screen.getByText("There's nothing to receive on PO-000042: it's cancelled.")).toBeInTheDocument();
    expect(screen.queryByRole('table')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Record receipt' })).toBeNull();
  });
});

describe('the order page\'s Receive action (R1-3; R1a-1 row 11 "+ Receive")', () => {
  const order = (over: Partial<PurchaseOrder>) => renderWired(<PurchaseOrderPage order={{ ...ORDER, ...over }} lines={{ kind: 'ok', lines: LINES, nextCursor: null }} viewerId="v" canRestore={false} canReadLines canOpenSupplier={false} canOpenWarehouse={false} canOpenItem={false} canReceive />);

  it('an ordered or partly received live order offers Receive, a link to the receive page', () => {
    for (const status of ['ordered', 'partially_received']) {
      const { unmount } = order({ status });
      expect(screen.getByRole('link', { name: 'Receive' })).toHaveAttribute('href', `/purchase-orders/${OID}/receive`);
      unmount();
    }
  });

  it('a draft, a received or a cancelled order offers no Receive; nor does a viewer without the codes (canReceive false)', () => {
    for (const status of ['draft', 'received', 'cancelled']) {
      const { unmount } = order({ status });
      expect(screen.queryByRole('link', { name: 'Receive' })).toBeNull();
      unmount();
    }
    renderWired(<PurchaseOrderPage order={ORDER} lines={{ kind: 'ok', lines: LINES, nextCursor: null }} viewerId="v" canRestore={false} canReadLines canOpenSupplier={false} canOpenWarehouse={false} canOpenItem={false} />);
    expect(screen.queryByRole('link', { name: 'Receive' })).toBeNull();
  });
});

describe('gr-2-red.md round 1', () => {
  const NO_LOCATIONS = "Main warehouse has no locations that aren't archived, so nothing can be received into it yet. A location can be added or restored on the warehouse's page.";

  it('M-1: a warehouse with no live location → noLocations and Back to {number}; no table, no Record receipt', () => {
    show({ locations: [] });
    expect(screen.getByText(NO_LOCATIONS)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Back to PO-000042' })).toHaveAttribute('href', `/purchase-orders/${OID}`);
    expect(screen.queryByRole('table')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Record receipt' })).toBeNull();
  });

  it('M-1: a location refusal whose locations re-read lists none → the same no-locations state, focus on the h1', async () => {
    stubFetch([problem(422, 'validation-failed', { errors: [{ pointer: '/lines/0/storage_location_id', code: 'storage_location.belongs_to_receiving_warehouse', message: "This location can't take goods for this order." }] }), json(200, { items: [] })]);
    const u = userEvent.setup();
    show();
    await enter(u, 1, '1');
    await confirmInDialog(u);
    await screen.findByText(NO_LOCATIONS);
    expect(screen.queryByRole('button', { name: 'Record receipt' })).toBeNull();
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveFocus());
  });

  it('M-2: a request that never reached the server (fetch rejects) → Could not reach + interrupted; the entries are kept', async () => {
    const calls = stubFetch([
      () => {
        // As the real fetch does when the network fails: the call itself rejects (the harness's mock is async).
        throw new TypeError('Failed to fetch');
      },
      json(201, RECEIPT),
      json(200, ORDER),
    ]);
    const u = userEvent.setup();
    show();
    await enter(u, 1, '2');
    await confirmInDialog(u);
    await screen.findByText(INTERRUPTED);
    expect(screen.getByText("We couldn't reach the server.")).toHaveFocus();
    expect(qty(1)).toHaveValue('2');
    expect(loc(1)).toHaveValue(BIN_A.id);
    expect(posts(calls)).toHaveLength(1);
    // (b) H-1: the identical re-confirm carries the SAME key (it may have reached the server after all).
    await confirmInDialog(u);
    await waitFor(() => expect(posts(calls)).toHaveLength(2));
    const [a, b] = keysOf(calls);
    expect(b).toBe(a);
  });

  it('L-8: a 201 that answers after the person left the page leaves the arrival sentence but does not navigate', async () => {
    const answer = held();
    stubFetch([answer.reply, json(200, ORDER)]);
    const u = userEvent.setup();
    const { unmount } = show();
    await enter(u, 1, '1');
    await confirmInDialog(u);
    unmount();
    answer.release(json(201, RECEIPT));
    await waitFor(() => expect(takeArrivalMessage(`/purchase-orders/${OID}`)).toMatch(/^GR-000007 recorded/));
    expect(nav.push).not.toHaveBeenCalled();
  });

  it('L-2: a locations answer that says there is more (a next cursor) is not the whole set → server-error', async () => {
    const read = async () => ({ kind: 'ok' as const, body: { items: [BIN_A], next_cursor: 'c1' } });
    expect(await loadLocationChoices(read as never, WID)).toEqual({ kind: 'server-error' });
    const whole = async () => ({ kind: 'ok' as const, body: { items: [BIN_A], next_cursor: null } });
    expect(await loadLocationChoices(whole as never, WID)).toEqual({ kind: 'ok', locations: [BIN_A] });
  });
});

describe('GR-2 H-1: one key per page, held until it is consumed (gr-2-h1-spar.md converged text, tests (c)…(h))', () => {
  it('(c) 503 → change one row → re-confirm: the SAME key (the body is never compared)', async () => {
    const calls = stubFetch([problem(503, 'service-unavailable'), json(201, RECEIPT), json(200, ORDER)]);
    const u = userEvent.setup();
    show();
    await enter(u, 1, '1');
    await confirmInDialog(u);
    await screen.findByText(INTERRUPTED);
    await u.clear(qty(1));
    await u.type(qty(1), '2');
    await confirmInDialog(u);
    await waitFor(() => expect(posts(calls)).toHaveLength(2));
    expect(bodyOf(posts(calls)[1]!).lines[0]!.quantity).toBe('2');
    const [a, b] = keysOf(calls);
    expect(b).toBe(a);
  });

  it('(d) 503 → re-confirm answered idempotency-key-conflict over a receivable order → earlierRecorded, Still to come updated, entries kept, never the server detail; the third confirm carries a NEW key', async () => {
    const after = [line(1, { quantity_received: '2.000000', quantity_outstanding: '3.000000' }), L2, DONE, L3];
    // Round-2 gate plant: the re-read order differs visibly from the first reading (supplier name), so the page must show the re-read one.
    const reread = { ...ORDER, status: 'partially_received', supplier: { ...ORDER.supplier, name: 'Acme Supplies Ltd' } };
    const calls = stubFetch([problem(503, 'service-unavailable'), conflict(), json(200, reread), json(200, page(after)), json(201, RECEIPT), json(200, ORDER)]);
    const u = userEvent.setup();
    show();
    await enter(u, 1, '1');
    await confirmInDialog(u);
    await screen.findByText(INTERRUPTED);
    await u.clear(qty(1));
    await u.type(qty(1), '2');
    await confirmInDialog(u);
    await screen.findByText(EARLIER);
    expect(screen.getByText('Not recorded')).toHaveFocus();
    expect(screen.getByText('From Acme Supplies Ltd, into Main warehouse.')).toBeInTheDocument();
    expect(screen.queryByText('From Acme Supplies, into Main warehouse.')).toBeNull();
    expect(document.body.textContent ?? '').not.toContain(CONFLICT_DETAIL);
    expect(document.body.textContent ?? '').not.toContain("This change wasn't made.");
    const cells = [...qty(1).closest('tr')!.querySelectorAll('td')].map((td) => td.textContent);
    expect(cells.slice(2, 5)).toEqual(['5 EA', '2 EA', '3 EA']);
    expect(qty(1)).toHaveValue('2');
    expect(loc(1)).toHaveValue(BIN_A.id);
    await confirmInDialog(u);
    await waitFor(() => expect(nav.push).toHaveBeenCalled());
    const [a, b, c] = keysOf(calls);
    expect(b).toBe(a);
    expect(c).not.toBe(b);
    expect(c).toMatch(/^[0-9a-f-]{36}$/);
  });

  it('(d2) a conflict whose re-read shows the order received in full → the no-form state with the earlier-attempt sentence above nothingToReceive; focus on the h1', async () => {
    stubFetch([problem(503, 'service-unavailable'), conflict(), json(200, { ...ORDER, status: 'received' })]);
    const u = userEvent.setup();
    show();
    await enter(u, 1, '1');
    await confirmInDialog(u);
    await screen.findByText(INTERRUPTED);
    await confirmInDialog(u);
    await screen.findByText("Your earlier attempt was recorded, so this one wasn't.");
    expect(screen.getByText("There's nothing to receive on PO-000042: it's received in full.")).toBeInTheDocument();
    expect(screen.queryByText(/changed while you were on this page/)).toBeNull();
    expect(screen.queryByRole('button', { name: 'Record receipt' })).toBeNull();
    expect(screen.getByRole('link', { name: 'Back to PO-000042' })).toHaveAttribute('href', `/purchase-orders/${OID}`);
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveFocus());
    expect(document.body.textContent ?? '').not.toContain(CONFLICT_DETAIL);
  });

  it('(d3) a conflict whose re-read fails → the earlier-attempt sentence with a reload clause, never REFUSED_WITHOUT_MESSAGE; entries kept', async () => {
    stubFetch([problem(503, 'service-unavailable'), conflict(), json(500, {})]);
    const u = userEvent.setup();
    show();
    await enter(u, 1, '1');
    await confirmInDialog(u);
    await screen.findByText(INTERRUPTED);
    await confirmInDialog(u);
    await screen.findByText("Your earlier attempt was recorded, so this one wasn't. Before you record anything else, reload the page to see what's still to come.");
    expect(screen.getByText('Not recorded')).toHaveFocus();
    expect(document.body.textContent ?? '').not.toContain("This change wasn't made.");
    expect(document.body.textContent ?? '').not.toContain(CONFLICT_DETAIL);
    expect(qty(1)).toHaveValue('1');
  });

  it('(e) 503 → re-confirm answered 422 → the third confirm: the SAME key (a refusal commits nothing and proves nothing about the first)', async () => {
    const calls = stubFetch([
      problem(503, 'service-unavailable'),
      problem(422, 'validation-failed', { errors: [{ pointer: '/lines/0/quantity', code: 'not_a_decimal', message: 'Enter a number greater than 0.' }] }),
      json(201, RECEIPT),
      json(200, ORDER),
    ]);
    const u = userEvent.setup();
    show();
    await enter(u, 1, '1');
    await confirmInDialog(u);
    await screen.findByText(INTERRUPTED);
    await confirmInDialog(u);
    await waitFor(() => expect(qty(1)).toHaveAccessibleDescription(expect.stringContaining('Enter a number greater than 0.')));
    await confirmInDialog(u);
    await waitFor(() => expect(nav.push).toHaveBeenCalled());
    const [a, b, c] = keysOf(calls);
    expect(b).toBe(a);
    expect(c).toBe(a);
  });

  it('(g) a new page instance (remount) holds a NEW key', async () => {
    const calls = stubFetch([problem(503, 'service-unavailable'), problem(503, 'service-unavailable')]);
    const u = userEvent.setup();
    const first = show();
    await enter(u, 1, '1');
    await confirmInDialog(u);
    await screen.findByText(INTERRUPTED);
    first.unmount();
    show();
    await enter(u, 1, '1');
    await confirmInDialog(u);
    await screen.findByText(INTERRUPTED);
    const [a, b] = keysOf(calls);
    expect(a).toMatch(/^[0-9a-f-]{36}$/);
    expect(b).not.toBe(a);
  });

  it('(h) a 201 whose body is not a goods receipt → Something went wrong + interrupted; the identical re-confirm carries the SAME key', async () => {
    const calls = stubFetch([json(201, { id: 'not-a-receipt' }), json(201, RECEIPT), json(200, ORDER)]);
    const u = userEvent.setup();
    show();
    await enter(u, 1, '1');
    await confirmInDialog(u);
    await screen.findByText(INTERRUPTED);
    expect(nav.push).not.toHaveBeenCalled();
    await confirmInDialog(u);
    await waitFor(() => expect(nav.push).toHaveBeenCalled());
    const [a, b] = keysOf(calls);
    expect(b).toBe(a);
  });
});

describe('ux-batch-2026-11-19.md B (GR-2 RED lows): L-3, L-4, L-6', () => {
  it('L-3: an R3 refusal whose sentence names no order line → onHandTooLargeUnnamed under Not recorded, focus on the banner; never the reload advice', async () => {
    stubFetch([refused('stock_level.on_hand_fits', 'Receiving this would overflow the figure on record.')]);
    const u = userEvent.setup();
    show();
    await enter(u, 1, '1');
    await confirmInDialog(u);
    await screen.findByText('Receiving this would take the stock of one of these items in its location past the largest quantity that can be recorded. Nothing was received. The figure on record there may be wrong.');
    expect(screen.getByText('Not recorded')).toHaveFocus();
    expect(document.body.textContent ?? '').not.toContain("This change wasn't made.");
    expect(document.body.textContent ?? '').not.toContain('overflow');
  });

  it('L-4: a deleted draft\'s receive page says "a deleted draft", never only "a draft"', () => {
    show({ order: { ...ORDER, status: 'draft', ordered_at: null, archived_at: '2026-03-05T09:00:00Z' }, lines: [], locations: [] });
    expect(screen.getByText("There's nothing to receive on PO-000042: it's a deleted draft.")).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Record receipt' })).toBeNull();
  });

  it('L-6: after R2, every row whose chosen location the re-read no longer lists is cleared; a row the server did not name says locationReset(n), the named row keeps the server sentence, focus on the named row', async () => {
    stubFetch([
      problem(422, 'validation-failed', { errors: [{ pointer: '/lines/0/storage_location_id', code: 'storage_location.belongs_to_receiving_warehouse', message: "This location can't take goods for this order." }] }),
      json(200, { items: [BIN_A] }),
    ]);
    const u = userEvent.setup();
    show();
    await enter(u, 1, '1', BIN_Q);
    await enter(u, 2, '1', BIN_A);
    await enter(u, 3, '1', BIN_Q);
    await confirmInDialog(u);
    await waitFor(() => expect(loc(3)).toHaveAccessibleDescription(expect.stringContaining('The location chosen for line 3 can no longer be used, so it was cleared. Choose another.')));
    expect(loc(3)).toHaveValue('');
    expect(loc(1)).toHaveValue('');
    expect(loc(1)).toHaveAccessibleDescription(expect.stringContaining("This location can't take goods for this order."));
    expect(loc(1)).not.toHaveAccessibleDescription(expect.stringContaining('can no longer be used'));
    expect(loc(2)).toHaveValue(BIN_A.id);
    expect(loc(2)).not.toHaveAccessibleDescription(expect.stringContaining('can no longer be used'));
    expect(loc(1)).toHaveFocus();
    expect(qty(3)).toHaveValue('1');
  });

  it('L-6: two rows the server did not name are both cleared, and each says locationReset with its own line number', async () => {
    stubFetch([
      problem(422, 'validation-failed', { errors: [{ pointer: '/lines/0/storage_location_id', code: 'storage_location.belongs_to_receiving_warehouse', message: "This location can't take goods for this order." }] }),
      json(200, { items: [BIN_A] }),
    ]);
    const u = userEvent.setup();
    show();
    await enter(u, 1, '1', BIN_Q);
    await enter(u, 2, '1', BIN_Q);
    await enter(u, 3, '1', BIN_Q);
    await confirmInDialog(u);
    await waitFor(() => expect(loc(2)).toHaveAccessibleDescription(expect.stringContaining('The location chosen for line 2 can no longer be used, so it was cleared. Choose another.')));
    expect(loc(3)).toHaveAccessibleDescription(expect.stringContaining('The location chosen for line 3 can no longer be used, so it was cleared. Choose another.'));
    expect(loc(2)).toHaveValue('');
    expect(loc(3)).toHaveValue('');
    expect(loc(1)).toHaveFocus();
  });

  it('L-6: a location picked while the locations re-read is in flight is judged as picked: a still-listed pick keeps no locationReset', async () => {
    const reread = held();
    stubFetch([
      problem(422, 'validation-failed', { errors: [{ pointer: '/lines/0/storage_location_id', code: 'storage_location.belongs_to_receiving_warehouse', message: "This location can't take goods for this order." }] }),
      reread.reply,
    ]);
    const u = userEvent.setup();
    show();
    await enter(u, 1, '1', BIN_Q);
    await enter(u, 3, '1', BIN_Q);
    await confirmInDialog(u);
    await waitFor(() => expect(loc(1)).toHaveAccessibleDescription(expect.stringContaining("This location can't take goods for this order.")));
    await u.selectOptions(loc(3), BIN_A.id);
    reread.release(json(200, { items: [BIN_A] }));
    await waitFor(() => expect(within(loc(3)).getAllByRole('option').map((o) => o.textContent)).toEqual(['Choose a location', 'A-01 — Bin']));
    expect(loc(3)).toHaveValue(BIN_A.id);
    expect(loc(3)).not.toHaveAccessibleDescription(expect.stringContaining('can no longer be used'));
    expect(loc(1)).toHaveFocus();
  });
});

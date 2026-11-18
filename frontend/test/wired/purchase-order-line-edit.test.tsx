import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: vi.fn(), refresh: vi.fn() }), usePathname: () => '/purchase-orders/x' }));
import { PurchaseOrderPage, type PurchaseOrderPageProps } from '@/app/(app)/purchase-orders/[id]/PurchaseOrderPage';
import type { PurchaseOrder, PurchaseOrderLine } from '@/lib/api/purchasing';
import { renderMoney } from '@/lib/format/money';
import type { CatalogueChoice, CatalogueChoicesLoad, LinesLoad } from '@/lib/purchasing/load';
import { empty, held, json, page, problem, refused, renderWired, stubFetch, textsOf, VIEWER_ID } from './harness';

// Purchase orders PO-4b — inline line Edit on a draft (purchase-orders-final-red.md apply list PO-4b: R1-5 PO-4b + M-6, the
// Rev 1a per-row names with ≥ 2 rows (L-10), L-11's served unit; plan Revision 1 R1-5 PO-4b (M-9, the unit suffix), Revision
// 0 §2.3 / §4 PO-4's line half, D-9 per-row Save; UX R1a-1 row 10 `EDIT_LINE.*`; po-4b-ux-unit.md (the Unit column, the
// suffixes); b4-architecture.md R10-6 (a form-attribute row, ≥ 2 rows from PO-4b); po-5-red-2.md D-18 (one home for one
// line write at a time); po-9-red.md R3-L-1). The page is the real `PurchaseOrderPage`, `LinesSection`, `EditLineRow` and
// `AddLineRow`; every write goes through the REAL `lib/purchasing/wired.ts`, `load.ts` and the browser transport.

afterEach(() => vi.unstubAllGlobals());

const OID = 'd1b2c3d4-0000-4000-8000-000000000001';
const SUPPLIER = 'c1b2c3d4-0000-4000-8000-0000000000a1';
const DRAFT: PurchaseOrder = {
  id: OID,
  order_number: 'PO-000001',
  status: 'draft',
  supplier: { id: SUPPLIER, name: 'Acme Supplies' },
  warehouse: { id: 'c1b2c3d4-0000-4000-8000-0000000000b1', name: 'Main warehouse' },
  currency_code: 'GBP',
  total_amount: '29.8750',
  ordered_at: null,
  expected_at: null,
  created_by: { id: 'e1b2c3d4-0000-4000-8000-000000000001', display_name: 'Bea Buyer' },
  archived_at: null,
};
const line = (n: number, over: Partial<PurchaseOrderLine> = {}): PurchaseOrderLine => ({
  id: `f1b2c3d4-0000-4000-8000-${String(n).padStart(12, '0')}`,
  line_number: n,
  inventory_item: { id: `a1b2c3d4-0000-4000-8000-${String(n).padStart(12, '0')}`, sku: `SKU-${n}`, name: `Item ${n}` },
  quantity: '2.000000',
  quantity_received: '0.000000',
  quantity_outstanding: '2.000000',
  unit_cost: '12.5000',
  archived_at: null,
  unit_of_measure_code: 'EA',
  ...over,
});
/** Two live lines in DIFFERENT units (po-4b-ux-unit.md test notes: a cell that reads the first row or the item goes red). */
const L1 = line(1);
const L2 = line(2, { quantity: '1.500000', quantity_outstanding: '1.500000', unit_cost: '3.2500', unit_of_measure_code: 'KG' });
const LINES: LinesLoad = { kind: 'ok', lines: [L1, L2], nextCursor: null };
const gone = (n: number, over: Partial<PurchaseOrderLine> = {}) => line(n, { archived_at: '2026-03-04T11:00:00Z', ...over });
const E8: CatalogueChoice = {
  id: 'b1b2c3d4-0000-4000-8000-000000000008',
  inventory_item: { id: 'a1b2c3d4-0000-4000-8000-000000000008', sku: 'FLOUR-8', name: 'Flour' },
  unit_cost: '3.2500',
  currency_code: 'GBP',
  unit_of_measure_code: 'KG',
};
const CATALOGUE: CatalogueChoicesLoad = { kind: 'ok', entries: [E8] };

const show = (over: Partial<PurchaseOrderPageProps> = {}) =>
  renderWired(<PurchaseOrderPage order={DRAFT} lines={LINES} viewerId={VIEWER_ID} canRestore canReadLines canOpenSupplier={false} canOpenWarehouse={false} canOpenItem={false} add={{ catalogue: CATALOGUE }} canDeleteLine canEditLine {...over} />);

const URL = `/bff/api/purchase-orders/${OID}`;
const LINES_URL = `${URL}/lines`;
const DELETED_URL = `${LINES_URL}?archived=true`;
const lineUrl = (l: PurchaseOrderLine) => `${LINES_URL}/${l.id}`;
const region = () => document.querySelector<HTMLElement>('section > [role="status"]')!;
const qty = (n: number) => screen.getByRole('textbox', { name: `Quantity, line ${n}` });
const cost = (n: number) => screen.getByRole('textbox', { name: `Unit cost, line ${n}` });
const save = (n: number) => screen.getByRole('button', { name: `Save line ${n}` });
const linesHeading = () => screen.getByRole('heading', { level: 2, name: 'Lines' });
const liveTable = () => linesHeading().parentElement!.querySelector('table')!;
const rowOf = (n: number) => qty(n).closest('tr')!;
/** The live table's line numbers (its rows other than the new line row and a row's own sentence row). */
const liveNumbers = () => [...liveTable().querySelectorAll('tbody tr')].filter((r) => r.getAttribute('aria-label') === null && r.querySelectorAll('td').length > 1).map((r) => r.querySelector('td')?.textContent);
const totalShown = () => screen.getByText('Total').nextElementSibling!.textContent;
const P9 = 'This purchase order has been placed, so it is read-only except for its transitions.';
const ARCHIVED_LINE = "This line is archived, so it can't be changed.";
const NOT_DRAFT_RULE = 'purchase_order.is_editable_only_while_draft';
const LINE_ARCHIVED_RULE = 'purchase_order_line.is_archived';
const CONTENTION = 'Another change to this purchase order was being saved at the same moment. Nothing was saved; try again.';
const SAVED_ORDER: PurchaseOrder = { ...DRAFT, total_amount: '32.0000' };
const SAVED_L1 = line(1, { quantity: '3.500000', quantity_outstanding: '3.500000' });

describe('/purchase-orders/[id] — each live line of a draft is edited in its row (PO-4b)', () => {
  it('each row: Quantity and Unit cost boxes named per line, as served (quantity trimmed, cost untrimmed), its Unit, and its own Save line', () => {
    show();
    expect(within(liveTable()).getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Line', 'Item', 'Quantity', 'Unit', 'Unit cost (GBP)', 'Actions']);
    expect(qty(1)).toHaveValue('2');
    expect(qty(2)).toHaveValue('1.5');
    expect(cost(1)).toHaveValue('12.5000');
    expect(cost(2)).toHaveValue('3.2500');
    // M-9 / L-10: the names carry the line number (two rows, so a dropped {n} finds two); the visible text stays "Save line".
    for (const n of [1, 2]) {
      expect(save(n)).toHaveTextContent(/^Save line$/);
      expect(screen.getAllByRole('textbox', { name: `Quantity, line ${n}` })).toHaveLength(1);
      expect(screen.getAllByRole('textbox', { name: `Unit cost, line ${n}` })).toHaveLength(1);
    }
    // The Unit cell is the ROW's served code (two rows in different units).
    const unitCell = (n: number) => rowOf(n).querySelectorAll('td')[3]!.textContent;
    expect(unitCell(1)).toBe('EA');
    expect(unitCell(2)).toBe('KG');
  });

  it('the boxes carry the row’s unit as a suffix: Quantity shows the code and reads its plural; Unit cost shows "per {code}" and reads "per …."', () => {
    show();
    expect(qty(2).parentElement!.querySelector('[aria-hidden="true"]')?.textContent).toBe('KG');
    expect(qty(2)).toHaveAccessibleDescription("kilograms. In the item's unit. Use a point for decimals and no commas, for example 2.5.");
    expect(cost(2).parentElement!.querySelector('[aria-hidden="true"]')?.textContent).toBe('per KG');
    expect(cost(2)).toHaveAccessibleDescription("per kilogram. What one of the item's units costs on this order.");
    expect(qty(1).parentElement!.querySelector('[aria-hidden="true"]')?.textContent).toBe('EA');
    expect(cost(1)).toHaveAccessibleDescription(expect.stringMatching(/^each\. /));
  });

  it('an untouched row: Save line says "Nothing to save." and a press sends nothing', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    show();
    expect(save(1)).toHaveAttribute('aria-disabled', 'true');
    expect(save(1)).toHaveAccessibleDescription('Nothing to save.');
    await u.click(save(1));
    expect(calls).toEqual([]);
  });

  it('only the changed field is sent, as typed (a string); an untouched cost sends nothing', async () => {
    const calls = stubFetch([json(200, SAVED_L1), json(200, SAVED_ORDER)]);
    const u = userEvent.setup();
    show();
    await u.clear(qty(1));
    await u.type(qty(1), '3.50');
    await u.click(save(1));
    await waitFor(() => expect(calls).toHaveLength(2));
    expect(calls[0]!.method).toBe('PATCH');
    expect(calls[0]!.url).toBe(lineUrl(L1));
    expect(calls[0]!.headers.get('content-type')).toBe('application/json');
    expect(calls[0]!.body).toBe('{"quantity":"3.50"}');
    expect(calls[0]!.headers.get('idempotency-key')).toBeNull();
  });

  it('M-9: type in row 2, save row 1 → row 2 keeps its text; the header Total re-reads; the lines list is not re-read; "Line 1 was saved."', async () => {
    const calls = stubFetch([json(200, SAVED_L1), json(200, SAVED_ORDER)]);
    const u = userEvent.setup();
    show();
    const { texts } = textsOf(region());
    await u.clear(cost(2));
    await u.type(cost(2), '4');
    await u.clear(qty(1));
    await u.type(qty(1), '3.5');
    await u.click(save(1));
    await waitFor(() => expect(region()).toHaveTextContent('Line 1 was saved.'));
    expect(calls.map((c) => [c.method, c.url])).toEqual([['PATCH', lineUrl(L1)], ['GET', URL]]);
    expect(cost(2)).toHaveValue('4');
    expect(totalShown()).toBe(renderMoney('32.0000', 'GBP'));
    // The row shows the 200 body's served values; nothing left to save there.
    expect(qty(1)).toHaveValue('3.5');
    expect(save(1)).toHaveAttribute('aria-disabled', 'true');
    // Focus stays on the row's Save line (it is still on the page), and the sentence is said once.
    expect(document.activeElement).toBe(save(1));
    expect(texts.filter((t) => t === 'Line 1 was saved.')).toHaveLength(1);
  });

  it('the header re-read fails → "Line 1 was saved. We couldn’t load this order again, so the total may be out of date." and the region error', async () => {
    stubFetch([json(200, SAVED_L1), json(500, {})]);
    const u = userEvent.setup();
    show();
    await u.clear(qty(1));
    await u.type(qty(1), '3.5');
    await u.click(save(1));
    await waitFor(() => expect(region()).toHaveTextContent("Line 1 was saved. We couldn't load this order again, so the total may be out of date."));
    expect(await screen.findByText("We couldn't load this purchase order again, so what's shown may be out of date.")).toBeInTheDocument();
    expect(qty(1)).toHaveValue('3.5');
  });

  it('R10-6: each row is a form-attribute form (fields join the form in its last cell, no form ancestor); Enter in row 2 sends row 2 only', async () => {
    const calls = stubFetch([json(200, line(2, { ...L2, unit_cost: '4.0000' })), json(200, SAVED_ORDER)]);
    const u = userEvent.setup();
    show();
    const forms = [1, 2].map((n) => save(n).closest('form')!);
    expect(forms[0]).not.toBe(forms[1]);
    for (const [i, n] of [1, 2].entries()) {
      const f = forms[i]!;
      expect(f.getAttribute('method')).toBe('post');
      expect(f.parentElement!.closest('form')).toBeNull();
      expect(f.closest('td')).toBe(rowOf(n).lastElementChild);
      expect((qty(n) as HTMLInputElement).form).toBe(f);
      expect((cost(n) as HTMLInputElement).form).toBe(f);
    }
    await u.clear(cost(2));
    await u.type(cost(2), '4{Enter}');
    await waitFor(() => expect(calls).toHaveLength(2));
    expect(calls[0]!.url).toBe(lineUrl(L2));
    expect(calls[0]!.body).toBe('{"unit_cost":"4"}');
  });

  it('D-18: Enter in row 1, then Save line 2, before row 1 answers → one write in flight; Add line and Delete send nothing meanwhile', async () => {
    const h = held();
    const calls = stubFetch([h.reply, json(200, SAVED_ORDER)]);
    const u = userEvent.setup();
    show();
    await u.clear(qty(1));
    await u.type(qty(1), '3.5');
    await u.clear(qty(2));
    await u.type(qty(2), '9');
    await u.type(qty(1), '{Enter}');
    await waitFor(() => expect(calls).toHaveLength(1));
    await u.click(save(2));
    await u.click(screen.getByRole('button', { name: 'Delete line 2' }));
    await u.selectOptions(screen.getByRole('combobox', { name: 'Item, new line' }), E8.inventory_item.id);
    await u.type(screen.getByRole('textbox', { name: 'Quantity, new line' }), '1');
    await u.click(screen.getByRole('button', { name: 'Add line' }));
    expect(calls.map((c) => [c.method, c.url])).toEqual([['PATCH', lineUrl(L1)]]);
    await act(async () => h.release(json(200, SAVED_L1)));
    await waitFor(() => expect(region()).toHaveTextContent('Line 1 was saved.'));
    // Row 2's typed text stands for its own press.
    expect(qty(2)).toHaveValue('9');
  });

  it('two presses of one row before its answer (Enter, then Save line) send one PATCH', async () => {
    const h = held();
    const calls = stubFetch([h.reply, json(200, SAVED_ORDER)]);
    const u = userEvent.setup();
    show();
    await u.clear(qty(1));
    await u.type(qty(1), '3.5{Enter}');
    await waitFor(() => expect(calls).toHaveLength(1));
    await u.click(save(1));
    expect(calls).toHaveLength(1);
    await act(async () => h.release(json(200, SAVED_L1)));
    await waitFor(() => expect(region()).toHaveTextContent('Line 1 was saved.'));
    expect(calls).toHaveLength(2);
  });

  it('what is typed into the row while its Save is in flight is kept after the answer (only what was sent gives way to the served value)', async () => {
    const h = held();
    stubFetch([h.reply, json(200, SAVED_ORDER)]);
    const u = userEvent.setup();
    show();
    await u.clear(qty(1));
    await u.type(qty(1), '3.5');
    await u.click(save(1));
    await u.clear(cost(1));
    await u.type(cost(1), '7');
    await act(async () => h.release(json(200, SAVED_L1)));
    await waitFor(() => expect(region()).toHaveTextContent('Line 1 was saved.'));
    expect(qty(1)).toHaveValue('3.5');
    expect(cost(1)).toHaveValue('7');
    expect(save(1)).not.toHaveAttribute('aria-disabled');
  });

  it('an emptied box: its client sentence (Add’s), focused, no request', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    show();
    await u.clear(cost(2));
    await u.click(save(2));
    expect(calls).toEqual([]);
    expect(cost(2)).toHaveAccessibleDescription(expect.stringContaining('Enter the unit cost, or 0.'));
    expect(document.activeElement).toBe(cost(2));
  });

  it('a 422: the server’s sentence beside its field, focused, the typed text kept, nothing said', async () => {
    stubFetch([problem(422, 'validation-failed', { errors: [{ pointer: '/quantity', code: 'not_a_decimal', message: 'Enter a number greater than 0.' }] })]);
    const u = userEvent.setup();
    show();
    await u.clear(qty(2));
    await u.type(qty(2), '1,5');
    await u.click(save(2));
    await waitFor(() => expect(qty(2)).toHaveAccessibleDescription(expect.stringContaining('Enter a number greater than 0.')));
    expect(document.activeElement).toBe(qty(2));
    expect(qty(2)).toHaveValue('1,5');
    expect(region()).toHaveTextContent('');
  });

  it('P1 (contention): the server’s sentence under "Not saved" in the row, focused; the typed text kept for another press', async () => {
    stubFetch([refused('transaction.contention', CONTENTION)]);
    const u = userEvent.setup();
    show();
    await u.clear(qty(2));
    await u.type(qty(2), '9');
    await u.click(save(2));
    const h = await screen.findByText('Not saved');
    expect(h.closest('tr')!.previousElementSibling).toBe(rowOf(2));
    expect(within(h.closest('tr')!).getByText(CONTENTION)).toBeInTheDocument();
    expect(document.activeElement).toBe(h);
    expect(qty(2)).toHaveValue('9');
  });

  it('a lost answer: "We can’t tell whether your changes to line 2 were saved…" in the row, never "saved"', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => Promise.reject(new TypeError('offline'))));
    const u = userEvent.setup();
    show();
    await u.clear(qty(2));
    await u.type(qty(2), '9');
    await u.click(save(2));
    const h = await screen.findByText("We couldn't reach the server.");
    expect(within(h.closest('tr')!).getByText("We can't tell whether your changes to line 2 were saved. Check this order's lines before you try again.")).toBeInTheDocument();
    expect(region()).toHaveTextContent('');
  });

  it('the line was deleted meanwhile (409 is_archived): the order and lines are read again, the row leaves, "Not saved" says the server’s sentence above the table, focused — never "saved"', async () => {
    const calls = stubFetch([refused(LINE_ARCHIVED_RULE, ARCHIVED_LINE), json(200, DRAFT), json(200, page([L1]))]);
    const u = userEvent.setup();
    show();
    await u.clear(qty(2));
    await u.type(qty(2), '9');
    await u.click(save(2));
    const h = await screen.findByText('Not saved');
    expect(calls.map((c) => [c.method, c.url])).toEqual([['PATCH', lineUrl(L2)], ['GET', URL], ['GET', LINES_URL]]);
    expect(liveNumbers()).toEqual(['1']);
    expect(within(h.parentElement!).getByText(ARCHIVED_LINE)).toBeInTheDocument();
    expect(h.compareDocumentPosition(liveTable()) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(document.activeElement).toBe(h);
    expect(region()).not.toHaveTextContent(/saved\./);
  });

  it('a 404 (the line is gone): read again, the row leaves, "Not saved" with the not-found sentence', async () => {
    stubFetch([problem(404, 'not-found'), json(200, DRAFT), json(200, page([L1]))]);
    const u = userEvent.setup();
    show();
    await u.clear(qty(2));
    await u.type(qty(2), '9');
    await u.click(save(2));
    const h = await screen.findByText('Not saved');
    expect(within(h.parentElement!).getByText("We couldn't find that. It may have been deleted, or you may not have access.")).toBeInTheDocument();
    expect(liveNumbers()).toEqual(['1']);
  });

  it('P9 (approved elsewhere): the page turns read-only and says UX D-2’s sentence under "Not saved"; no box is left', async () => {
    stubFetch([refused(NOT_DRAFT_RULE, P9), json(200, { ...DRAFT, status: 'ordered', ordered_at: '2026-03-04T11:00:00Z' }), json(200, page([L1, L2]))]);
    const u = userEvent.setup();
    show();
    await u.clear(qty(2));
    await u.type(qty(2), '9');
    await u.click(save(2));
    expect(await screen.findByText("PO-000001 is no longer a draft: it's ordered, so it can't be changed.")).toBeInTheDocument();
    expect(screen.queryAllByRole('textbox')).toEqual([]);
  });

  it('a forbidden Save says which permission is missing, in the row', async () => {
    const calls = stubFetch([problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    show();
    await u.clear(qty(2));
    await u.type(qty(2), '9');
    await u.click(save(2));
    expect(calls).toHaveLength(1);
    const h = await screen.findByText('Not saved');
    expect(h.closest('tr')!.textContent).toMatch(/^.*This needs the .+ permission, which you no longer have\./);
  });

  it('read-only for a viewer without UpdatePurchaseOrderLine, and on every order that is not a live draft (with the Unit column)', () => {
    const a = show({ canEditLine: false });
    expect(screen.queryByRole('textbox', { name: /, line \d/ })).toBeNull();
    expect(within(liveTable()).getAllByRole('columnheader').map((x) => x.textContent)).toEqual(['Line', 'Item', 'Quantity', 'Unit', 'Unit cost (GBP)', 'Actions']);
    a.unmount();
    const b = show({ order: { ...DRAFT, status: 'ordered', ordered_at: '2026-03-04T11:00:00Z' } });
    expect(screen.queryAllByRole('textbox')).toEqual([]);
    expect(within(liveTable()).getAllByRole('columnheader').map((x) => x.textContent)).toEqual(['Line', 'Item', 'Ordered', 'Received', 'Still to come', 'Unit', 'Unit cost (GBP)']);
    expect([...liveTable().querySelectorAll('tbody tr')].map((r) => r.querySelectorAll('td')[5]!.textContent)).toEqual(['EA', 'KG']);
    b.unmount();
    show({ order: { ...DRAFT, status: 'cancelled' } });
    expect(within(liveTable()).getAllByRole('columnheader').map((x) => x.textContent)).toEqual(['Line', 'Item', 'Ordered', 'Received', 'Never received', 'Unit', 'Unit cost (GBP)']);
  });

  it('itemNote under the table only when Edit, Delete and Add are all offered', () => {
    const NOTE = 'To order a different item, delete this line and add a new one.';
    const a = show();
    expect(screen.getByText(NOTE)).toBeInTheDocument();
    a.unmount();
    const b = show({ canDeleteLine: false });
    expect(screen.queryByText(NOTE)).toBeNull();
    b.unmount();
    show({ add: null });
    expect(screen.queryByText(NOTE)).toBeNull();
  });
});

describe('/purchase-orders/[id] — typed text in other rows survives every re-read of the lines (final RED M-6)', () => {
  it('type in row 2, add a line → row 2 keeps its text; the new row starts at its served values', async () => {
    const L3 = line(3, { inventory_item: E8.inventory_item, quantity: '1.000000', unit_cost: '3.2500', unit_of_measure_code: 'KG' });
    stubFetch([json(201, L3), json(200, DRAFT), json(200, page([L1, L2, L3]))]);
    const u = userEvent.setup();
    show();
    await u.clear(qty(2));
    await u.type(qty(2), '9');
    await u.selectOptions(screen.getByRole('combobox', { name: 'Item, new line' }), E8.inventory_item.id);
    await u.type(screen.getByRole('textbox', { name: 'Quantity, new line' }), '1');
    await u.click(screen.getByRole('button', { name: 'Add line' }));
    await waitFor(() => expect(region()).toHaveTextContent('Line 3 added: FLOUR-8.'));
    expect(qty(2)).toHaveValue('9');
    expect(qty(3)).toHaveValue('1');
    expect(cost(3)).toHaveValue('3.2500');
  });

  it('type in row 2, delete row 1 → row 2 keeps its text; row 1 drops its own', async () => {
    stubFetch([empty(204), json(200, DRAFT), json(200, page([L2]))]);
    const u = userEvent.setup();
    show();
    await u.clear(qty(1));
    await u.type(qty(1), '5');
    await u.clear(qty(2));
    await u.type(qty(2), '9');
    await u.click(screen.getByRole('button', { name: 'Delete line 1' }));
    await waitFor(() => expect(region()).toHaveTextContent('Line 1 (SKU-1) was deleted.'));
    expect(qty(2)).toHaveValue('9');
    expect(screen.queryByRole('textbox', { name: 'Quantity, line 1' })).toBeNull();
  });

  it('type in row 2, restore a deleted line → row 2 keeps its text', async () => {
    const G3 = gone(3);
    stubFetch([json(200, line(3)), json(200, DRAFT), json(200, page([L1, L2, line(3)])), json(200, page([]))]);
    const u = userEvent.setup();
    show({ canRestoreLine: true, deletedLines: { kind: 'ok', lines: [G3], nextCursor: null } });
    await u.clear(qty(2));
    await u.type(qty(2), '9');
    await u.click(screen.getByRole('button', { name: 'Restore line 3 (SKU-3)' }));
    await waitFor(() => expect(region()).toHaveTextContent('Line 3 (SKU-3) was restored'));
    expect(qty(2)).toHaveValue('9');
    expect(qty(3)).toHaveValue('2');
  });

  it('another tab: a newer reading (the page’s Retry) changes both lines → row 2 keeps its typed text, untouched row 1 shows the new served value', async () => {
    // A failed header re-read after a Save of row 1 leaves the page's Retry; its answer (order, lines and — Deleted lines being
    // shown — deleted lines) is the other tab's edits.
    stubFetch([json(200, SAVED_L1), json(500, {}), json(200, DRAFT), json(200, page([line(1, { quantity: '6.000000' }), line(2, { ...L2, quantity: '8.000000' })])), json(200, page([]))]);
    const u = userEvent.setup();
    show({ canRestoreLine: true, deletedLines: { kind: 'ok', lines: [], nextCursor: null } });
    await u.clear(qty(1));
    await u.type(qty(1), '3.5');
    await u.click(save(1));
    await waitFor(() => expect(region()).toHaveTextContent('Line 1 was saved.'));
    await u.clear(qty(2));
    await u.type(qty(2), '9');
    await u.click(await screen.findByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(qty(1)).toHaveValue('6'));
    expect(qty(2)).toHaveValue('9');
  });

  it('a newer lines reading than a Save’s answer wins; an older one does not (the row’s 200 body stands until a later reading)', async () => {
    // Row 1 saved (3.5). Then Delete row 2: its re-read is newer and serves row 1 at 4 → 4 shown.
    stubFetch([json(200, SAVED_L1), json(200, SAVED_ORDER), empty(204), json(200, DRAFT), json(200, page([line(1, { quantity: '4.000000' })]))]);
    const u = userEvent.setup();
    show();
    await u.clear(qty(1));
    await u.type(qty(1), '3.5');
    await u.click(save(1));
    await waitFor(() => expect(region()).toHaveTextContent('Line 1 was saved.'));
    expect(qty(1)).toHaveValue('3.5');
    await u.click(screen.getByRole('button', { name: 'Delete line 2' }));
    await waitFor(() => expect(region()).toHaveTextContent('Line 2 (SKU-2) was deleted.'));
    expect(qty(1)).toHaveValue('4');
  });
});

describe('/purchase-orders/[id] — po-9-red.md R3-L-1: a held deleted line never comes back with an older reading’s numbers', () => {
  it('a deleted line restored (hidden from Deleted lines), then edited and deleted again in another tab: after Add line, Deleted lines shows it with the newer numbers', async () => {
    const OLD3 = gone(3, { quantity: '2.000000' });
    const NEW3 = gone(3, { quantity: '7.000000' });
    const L4 = line(4, { inventory_item: E8.inventory_item, unit_of_measure_code: 'KG' });
    // First render: line 3 is live AND (an older reading) deleted — the held deleted reading predates its restore.
    stubFetch([json(201, L4), json(200, DRAFT), json(200, page([L1, L2, L4])), json(200, page([NEW3]))]);
    const u = userEvent.setup();
    show({ lines: { kind: 'ok', lines: [L1, L2, line(3)], nextCursor: null }, canRestoreLine: true, deletedLines: { kind: 'ok', lines: [OLD3], nextCursor: null } });
    await u.selectOptions(screen.getByRole('combobox', { name: 'Item, new line' }), E8.inventory_item.id);
    await u.type(screen.getByRole('textbox', { name: 'Quantity, new line' }), '1');
    await u.click(screen.getByRole('button', { name: 'Add line' }));
    await waitFor(() => expect(region()).toHaveTextContent('Line 4 added: FLOUR-8.'));
    const deletedTable = screen.getByRole('heading', { level: 3, name: 'Deleted lines' }).parentElement!.querySelector('table')!;
    expect([...deletedTable.querySelectorAll('tbody tr')].map((r) => r.querySelectorAll('td')[2]!.textContent)).toEqual(['7']);
  });

  it('the same after a dialog closes: a refused Delete purchase order (P10), Close → the re-read of a live draft takes its deleted lines too', async () => {
    const OLD3 = gone(3, { quantity: '2.000000' });
    const NEW3 = gone(3, { quantity: '7.000000' });
    const calls = stubFetch([refused('purchase_order.is_archived', 'This purchase order is already archived.'), json(200, DRAFT), json(200, page([L1, L2])), json(200, page([NEW3]))]);
    const u = userEvent.setup();
    show({ lines: { kind: 'ok', lines: [L1, L2, line(3)], nextCursor: null }, canDelete: true, canRestoreLine: true, deletedLines: { kind: 'ok', lines: [OLD3], nextCursor: null } });
    await u.click(screen.getByRole('button', { name: 'Delete purchase order' }));
    await u.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Delete purchase order' }));
    await u.click(await within(screen.getByRole('dialog')).findByRole('button', { name: 'Close' }));
    await waitFor(() => expect(calls.map((c) => c.url)).toEqual([URL, URL, LINES_URL, DELETED_URL]));
    const deletedTable = screen.getByRole('heading', { level: 3, name: 'Deleted lines' }).parentElement!.querySelector('table')!;
    await waitFor(() => expect([...deletedTable.querySelectorAll('tbody tr')].map((r) => r.querySelectorAll('td')[2]!.textContent)).toEqual(['7']));
  });

  it('the Deleted lines table has a Unit column (the row’s served code)', () => {
    show({ canRestoreLine: true, deletedLines: { kind: 'ok', lines: [gone(3, { unit_of_measure_code: 'BOX' })], nextCursor: null } });
    const t = screen.getByRole('heading', { level: 3, name: 'Deleted lines' }).parentElement!.querySelector('table')!;
    expect(within(t).getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Line', 'Item', 'Quantity', 'Unit', 'Unit cost (GBP)', 'Actions']);
    expect(t.querySelector('tbody tr')!.querySelectorAll('td')[3]!.textContent).toBe('BOX');
  });
});

describe('/purchase-orders/[id] — po-4b-red.md fixes', () => {
  const UNSAVED = 'Save or undo your changes to the lines before approving.';
  const approve = () => screen.getByRole('button', { name: 'Approve' });

  it('H-1: a row with a change not yet saved makes Approve unavailable with a reason; a press opens no dialog and sends nothing', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    show({ canApprove: true });
    expect(approve()).not.toHaveAttribute('aria-disabled');
    await u.clear(qty(2));
    await u.type(qty(2), '9');
    expect(approve()).toHaveAttribute('aria-disabled', 'true');
    expect(approve()).toHaveAccessibleDescription(UNSAVED);
    await u.click(approve());
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(calls).toEqual([]);
  });

  it('H-1: retyping the served value makes Approve available again (dirty is the boxes against the served values, never "touched")', async () => {
    stubFetch([]);
    const u = userEvent.setup();
    show({ canApprove: true });
    await u.clear(qty(2));
    await u.type(qty(2), '9');
    expect(approve()).toHaveAttribute('aria-disabled', 'true');
    await u.clear(qty(2));
    await u.type(qty(2), '1.5');
    expect(approve()).not.toHaveAttribute('aria-disabled');
  });

  it('H-1: silent while the row’s Save is in flight (the press opens nothing); available once it has answered saved', async () => {
    const h = held();
    const calls = stubFetch([h.reply, json(200, SAVED_ORDER)]);
    const u = userEvent.setup();
    show({ canApprove: true });
    await u.clear(qty(1));
    await u.type(qty(1), '3.5');
    await u.click(save(1));
    await waitFor(() => expect(calls).toHaveLength(1));
    expect(approve()).not.toHaveAttribute('aria-disabled');
    await u.click(approve());
    expect(screen.queryByRole('dialog')).toBeNull();
    await act(async () => h.release(json(200, SAVED_L1)));
    await waitFor(() => expect(region()).toHaveTextContent('Line 1 was saved.'));
    expect(approve()).not.toHaveAttribute('aria-disabled');
  });

  it('H-2: lines failed → the section’s Retry → edit row 1 → Save → the row shows the saved value; a later refusal’s re-read still replaces the Retry answer', async () => {
    stubFetch([json(200, page([L1, L2])), json(200, SAVED_L1), json(200, SAVED_ORDER), refused('transaction.other', 'Something else.'), json(200, DRAFT), json(200, page([line(1, { quantity: '6.000000' }), L2]))]);
    const u = userEvent.setup();
    show({ lines: { kind: 'server-error' } });
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(qty(1)).toHaveValue('2'));
    await u.clear(qty(1));
    await u.type(qty(1), '3.5');
    await u.click(save(1));
    await waitFor(() => expect(region()).toHaveTextContent('Line 1 was saved.'));
    expect(qty(1)).toHaveValue('3.5');
    expect(save(1)).toHaveAttribute('aria-disabled', 'true');
    await u.clear(qty(2));
    await u.type(qty(2), '9');
    await u.click(save(2));
    await screen.findByText('Not saved');
    expect(qty(1)).toHaveValue('6');
  });

  it('M-1: type in row 2, Add a line whose lines re-read fails (503), then Retry → row 2 still holds its text', async () => {
    const L3 = line(3, { inventory_item: E8.inventory_item, unit_of_measure_code: 'KG' });
    stubFetch([json(201, L3), json(200, DRAFT), json(503, {}), json(200, page([L1, L2, L3]))]);
    const u = userEvent.setup();
    show();
    await u.clear(qty(2));
    await u.type(qty(2), '9');
    await u.selectOptions(screen.getByRole('combobox', { name: 'Item, new line' }), E8.inventory_item.id);
    await u.type(screen.getByRole('textbox', { name: 'Quantity, new line' }), '1');
    await u.click(screen.getByRole('button', { name: 'Add line' }));
    await u.click(await screen.findByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(qty(2)).toHaveValue('9'));
    expect(qty(1)).toHaveValue('2');
  });

  it('S-2: typed text hidden by a failed lines reading still makes Approve unavailable; Retry shows the row (still unavailable); retyping the served value makes it available', async () => {
    const L3 = line(3, { inventory_item: E8.inventory_item, unit_of_measure_code: 'KG' });
    const calls = stubFetch([json(201, L3), json(200, DRAFT), json(503, {}), json(200, page([L1, L2, L3]))]);
    const u = userEvent.setup();
    show({ canApprove: true });
    await u.clear(qty(2));
    await u.type(qty(2), '9');
    await u.selectOptions(screen.getByRole('combobox', { name: 'Item, new line' }), E8.inventory_item.id);
    await u.type(screen.getByRole('textbox', { name: 'Quantity, new line' }), '1');
    await u.click(screen.getByRole('button', { name: 'Add line' }));
    const retryButton = await screen.findByRole('button', { name: 'Retry' });
    expect(screen.queryByRole('textbox', { name: 'Quantity, line 2' })).toBeNull();
    // Unavailable after the 503: the hidden row 2 still holds 9.
    expect(approve()).toHaveAttribute('aria-disabled', 'true');
    expect(approve()).toHaveAccessibleDescription(UNSAVED);
    await u.click(approve());
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(calls).toHaveLength(3);
    await u.click(retryButton);
    await waitFor(() => expect(qty(2)).toHaveValue('9'));
    expect(approve()).toHaveAttribute('aria-disabled', 'true');
    await u.clear(qty(2));
    await u.type(qty(2), '1.5');
    expect(approve()).not.toHaveAttribute('aria-disabled');
  });

  it('L-4: a 409 is_archived whose re-read shows the line live (restored in between) says UX D-2’s changed-since sentence in the row, never "archived"', async () => {
    stubFetch([refused(LINE_ARCHIVED_RULE, ARCHIVED_LINE), json(200, DRAFT), json(200, page([L1, L2]))]);
    const u = userEvent.setup();
    show();
    await u.clear(qty(2));
    await u.type(qty(2), '9');
    await u.click(save(2));
    const h = await screen.findByText('Not saved');
    expect(within(h.closest('tr')!).getByText('PO-000001 changed since this page was loaded, so this wasn\'t done. Reload the page to see where it stands.')).toBeInTheDocument();
    expect(screen.queryByText(ARCHIVED_LINE)).toBeNull();
    expect(qty(2)).toHaveValue('9');
  });

  it('L-9: Delete purchase order answered 204 whose re-read is a live draft (restored in between) takes its deleted lines too', async () => {
    const calls = stubFetch([empty(204), json(200, DRAFT), json(200, page([L1, L2])), json(200, page([gone(3, { quantity: '7.000000' })]))]);
    const u = userEvent.setup();
    show({ canDelete: true, canRestoreLine: true, deletedLines: { kind: 'ok', lines: [], nextCursor: null } });
    await u.click(screen.getByRole('button', { name: 'Delete purchase order' }));
    await u.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Delete purchase order' }));
    await waitFor(() => expect(calls.map((c) => [c.method, c.url])).toEqual([['DELETE', URL], ['GET', URL], ['GET', LINES_URL], ['GET', DELETED_URL]]));
  });

  it('L-9: Approve refused A1 (creator) whose re-read is still a draft takes its deleted lines too', async () => {
    const calls = stubFetch([refused('purchase_order.approver_is_not_the_creator', 'You raised this order, so someone else has to approve it.'), json(200, DRAFT), json(200, page([L1, L2])), json(200, page([]))]);
    const u = userEvent.setup();
    show({ canApprove: true, canRestoreLine: true, deletedLines: { kind: 'ok', lines: [], nextCursor: null } });
    await u.click(approve());
    await u.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Approve' }));
    await waitFor(() => expect(calls.map((c) => [c.method, c.url])).toEqual([['POST', `${URL}/approval`], ['GET', URL], ['GET', LINES_URL], ['GET', DELETED_URL]]));
  });
});


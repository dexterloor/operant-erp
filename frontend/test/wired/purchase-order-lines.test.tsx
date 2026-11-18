import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: vi.fn(), refresh: vi.fn() }), usePathname: () => '/purchase-orders/x' }));
import { PurchaseOrderPage, type PurchaseOrderPageProps } from '@/app/(app)/purchase-orders/[id]/PurchaseOrderPage';
import type { PurchaseOrder, PurchaseOrderLine } from '@/lib/api/purchasing';
import type { CatalogueChoice, CatalogueChoicesLoad, LinesLoad, WarehouseChoice } from '@/lib/purchasing/load';
import { empty, held, json, page, problem, refused, renderWired, stubFetch, textsOf, VIEWER_ID } from './harness';

// Purchase orders PO-5 — Add line (from the supplier's catalogue) and Delete line (a direct row action, no dialog)
// (purchase-orders-final-red.md apply list PO-5: H-2's client sentence, L-5, L-10; plan Revision 1a X-4 / X-17 / per-row
// names over Revision 1 R1-5 PO-5 over Revision 0 §2.3 / §4 PO-5; UX R1a-1 row 5 over R1-B `ADD_LINE` / `DELETE_LINE` /
// `ORDER_LINES`; PO D-7). The page is the real `PurchaseOrderPage`, `LinesSection` and `AddLineRow`; every write goes
// through the REAL `lib/purchasing/wired.ts`, `load.ts` and the browser transport.

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
  total_amount: '50.0000',
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
  ...over,
});
const entry = (n: number, over: Partial<CatalogueChoice> = {}): CatalogueChoice => ({
  id: `b1b2c3d4-0000-4000-8000-${String(n).padStart(12, '0')}`,
  inventory_item: { id: `a1b2c3d4-0000-4000-8000-${String(n).padStart(12, '0')}`, sku: `SKU-${n}`, name: `Item ${n}` },
  unit_cost: '12.5000',
  currency_code: 'GBP',
  unit_of_measure_code: 'EA',
  ...over,
});
/** The served catalogue (supplier SKU order): an EA entry, a KG entry, and one in another currency than the order's. */
const E7 = entry(7, { inventory_item: { id: 'a1b2c3d4-0000-4000-8000-000000000007', sku: 'BOLT-7', name: 'Bolt' } });
const E8 = entry(8, { inventory_item: { id: 'a1b2c3d4-0000-4000-8000-000000000008', sku: 'FLOUR-8', name: 'Flour' }, unit_cost: '3.2500', unit_of_measure_code: 'KG' });
const E9 = entry(9, { inventory_item: { id: 'a1b2c3d4-0000-4000-8000-000000000009', sku: 'GADGET-9', name: 'Gadget' }, unit_cost: '9.0000', currency_code: 'USD', unit_of_measure_code: 'BOX' });
const CATALOGUE: CatalogueChoicesLoad = { kind: 'ok', entries: [E7, E8, E9] };
const LINES: LinesLoad = { kind: 'ok', lines: [line(1), line(2)], nextCursor: null };
const NEW_LINE = line(3, { inventory_item: E8.inventory_item, quantity: '2.500000', quantity_outstanding: '2.500000', unit_cost: '3.2500' });

const show = (over: Partial<PurchaseOrderPageProps> = {}) =>
  renderWired(<PurchaseOrderPage order={DRAFT} lines={LINES} viewerId={VIEWER_ID} canRestore canReadLines canOpenSupplier={false} canOpenWarehouse={false} canOpenItem={false} add={{ catalogue: CATALOGUE }} canDeleteLine {...over} />);

const URL = `/bff/api/purchase-orders/${OID}`;
const LINES_URL = `${URL}/lines`;
const PRODUCTS_URL = `/bff/api/suppliers/${SUPPLIER}/products`;
const region = () => document.querySelector<HTMLElement>('section > [role="status"]')!;
const newRow = () => screen.getByRole('row', { name: 'New line' });
const item = () => screen.getByRole('combobox', { name: 'Item, new line' });
const quantity = () => screen.getByRole('textbox', { name: 'Quantity, new line' });
const unitCost = () => screen.getByRole('textbox', { name: 'Unit cost, new line' });
const addLine = () => screen.getByRole('button', { name: 'Add line' });
const linesHeading = () => screen.getByRole('heading', { level: 2, name: 'Lines' });
const bodyRows = () => [...document.querySelectorAll('tbody tr')].filter((r) => r.getAttribute('aria-label') === null);
const lineNumbers = () => bodyRows().map((r) => r.querySelector('td')?.textContent);

const ITEM_UNAVAILABLE = "This item is archived or isn't in this workspace, so it can't go on a purchase order.";
const SERVER_L2 = 'No live inventory item in this workspace has this id.';
const L1 = "This order's supplier has no catalogue entry for that item, so the line has no lead time to record.";
const P8 = "This purchase order is archived, so it can't be changed.";
const P9 = 'This purchase order has been placed, so it is read-only except for its transitions.';
const W23 = 'This line is already archived.';
const NOT_DRAFT_RULE = 'purchase_order.is_editable_only_while_draft';
const NOT_LISTED_RULE = 'purchase_order_line.supplier_lists_the_item';
const EMPTY_NOTE = "Add what you're ordering, one item per line. Only items in Acme Supplies's catalogue can be added.";

async function chooseAndType(u: ReturnType<typeof userEvent.setup>, e: CatalogueChoice, qty: string) {
  await u.selectOptions(item(), e.inventory_item.id);
  await u.type(quantity(), qty);
}

describe('/purchase-orders/[id] — the new line row (PO-5 Add line)', () => {
  it('one row named "New line": Item (no preselection, the served entries in served order, {sku} — {name}), Quantity, Unit cost (GBP), Add line', () => {
    show();
    expect(newRow()).toBeInTheDocument();
    expect(item()).toHaveValue('');
    expect(within(item()).getAllByRole('option').map((o) => o.textContent)).toEqual(['Choose an item', 'BOLT-7 — Bolt', 'FLOUR-8 — Flour', 'GADGET-9 — Gadget']);
    expect(quantity()).toHaveValue('');
    expect(unitCost()).toHaveValue('');
    expect(within(newRow()).getByText('Unit cost (GBP)')).toBeInTheDocument();
    expect(quantity()).toHaveAccessibleDescription('In the item\'s unit. Use a point for decimals and no commas, for example 2.5.');
    expect(addLine()).toBeInTheDocument();
    // The draft columns gain Actions (UX B-4: only with purchase_order.edit's actions).
    expect(screen.getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Line', 'Item', "Quantity (item's unit)", 'Unit cost (GBP)', 'Actions']);
  });

  it('the row is a form-attribute form: its fields belong to the form that holds Add line, and Enter in Quantity sends that row', async () => {
    const calls = stubFetch([json(201, NEW_LINE), json(200, DRAFT), json(200, page([line(1), line(2), NEW_LINE]))]);
    const u = userEvent.setup();
    show();
    const form = addLine().closest('form')!;
    // po-5-arch.md (b): the row's form has no form ancestor (a nested form is invalid HTML and would be dropped by the parser).
    expect(form.parentElement!.closest('form')).toBeNull();
    expect(form.getAttribute('method')).toBe('post');
    for (const f of [item(), quantity(), unitCost()]) expect((f as HTMLInputElement | HTMLSelectElement).form).toBe(form);
    await u.selectOptions(item(), E8.inventory_item.id);
    await u.type(quantity(), '2.5{Enter}');
    await waitFor(() => expect(calls.map((c) => [c.method, c.url])).toEqual([['POST', LINES_URL], ['GET', URL], ['GET', LINES_URL]]));
  });

  it('choosing a KG entry shows KG beside Quantity (spoken "kilograms."); D-7: the same currency prefills the served cost untrimmed, another starts empty', async () => {
    const u = userEvent.setup();
    show();
    await u.selectOptions(item(), E8.inventory_item.id);
    expect(quantity().parentElement!.querySelector('[aria-hidden="true"]')?.textContent).toBe('KG');
    expect(quantity()).toHaveAccessibleDescription(expect.stringMatching(/^kilograms\. In the item's unit\./));
    expect(unitCost()).toHaveValue('3.2500');
    expect(unitCost()).toHaveAccessibleDescription("What one of the item's units costs on this order. It starts at the supplier's catalogue cost; changing it here changes only this order.");
    await u.selectOptions(item(), E9.inventory_item.id);
    expect(quantity().parentElement!.querySelector('[aria-hidden="true"]')?.textContent).toBe('BOX');
    expect(unitCost()).toHaveValue('');
    expect(unitCost()).toHaveAccessibleDescription("What one of the item's units costs on this order.");
  });

  it('nothing is sent without an item (only the item’s sentence, focused), nor with an empty quantity or cost', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    show();
    await u.type(quantity(), '2');
    await u.click(addLine());
    expect(item()).toHaveAccessibleDescription('Choose an item for this line.');
    expect(item()).toHaveFocus();
    expect(within(newRow()).queryByText('Enter the unit cost, or 0.')).toBeNull();
    await u.clear(quantity());
    await u.selectOptions(item(), E9.inventory_item.id);
    await u.click(addLine());
    expect(quantity()).toHaveAccessibleDescription(expect.stringContaining('Enter a quantity greater than 0.'));
    expect(unitCost()).toHaveAccessibleDescription(expect.stringContaining('Enter the unit cost, or 0.'));
    expect(quantity()).toHaveFocus();
    expect(calls).toEqual([]);
  });

  it('added: POST …/lines with the three members as typed and a retry key; re-reads the order and lines; the new row in served order; the row empties, its Item focused; "Line 3 added: FLOUR-8."', async () => {
    const calls = stubFetch([json(201, NEW_LINE), json(200, { ...DRAFT, total_amount: '58.1300' }), json(200, page([line(1), line(2), NEW_LINE]))]);
    const u = userEvent.setup();
    show();
    const texts = textsOf(region());
    await chooseAndType(u, E8, '2.5');
    await u.click(addLine());
    await waitFor(() => expect(region()).toHaveTextContent('Line 3 added: FLOUR-8.'));
    expect(calls.map((c) => [c.method, c.url])).toEqual([['POST', LINES_URL], ['GET', URL], ['GET', LINES_URL]]);
    expect(JSON.parse(calls[0]!.body!)).toEqual({ inventory_item_id: E8.inventory_item.id, quantity: '2.5', unit_cost: '3.2500' });
    expect(calls[0]!.headers.get('Idempotency-Key')).toMatch(/^[0-9a-f-]{36}$/);
    expect(lineNumbers()).toEqual(['1', '2', '3']);
    expect(screen.getByText('58.13 GBP')).toBeInTheDocument();
    expect(item()).toHaveValue('');
    expect(quantity()).toHaveValue('');
    expect(unitCost()).toHaveValue('');
    expect(item()).toHaveFocus();
    // The first '' is the region's initial text (the press-time clear is pinned by the M-2 tests); the one sentence followed the re-read.
    expect(texts.texts).toEqual(['', 'Line 3 added: FLOUR-8.']);
    texts.stop();
  });

  it('a fresh retry key for every press', async () => {
    const calls = stubFetch([json(201, NEW_LINE), json(200, DRAFT), json(200, page([line(1), line(2), NEW_LINE])), json(201, line(4, { inventory_item: E7.inventory_item })), json(200, DRAFT), json(200, page([line(1), line(2), NEW_LINE, line(4)]))]);
    const u = userEvent.setup();
    show();
    await chooseAndType(u, E8, '2.5');
    await u.click(addLine());
    await waitFor(() => expect(region()).toHaveTextContent('Line 3 added: FLOUR-8.'));
    await chooseAndType(u, E7, '1');
    await u.click(addLine());
    await waitFor(() => expect(region()).toHaveTextContent('Line 4 added: BOLT-7.'));
    const keys = calls.filter((c) => c.method === 'POST').map((c) => c.headers.get('Idempotency-Key'));
    expect(keys).toHaveLength(2);
    expect(keys[0]).not.toBe(keys[1]);
  });

  it('added, but the lines re-read fails: the region says so ONCE ("…may be out of date"), never "added" first and corrected after', async () => {
    stubFetch([json(201, NEW_LINE), json(200, DRAFT), json(500, {})]);
    const u = userEvent.setup();
    show();
    const texts = textsOf(region());
    await chooseAndType(u, E8, '2.5');
    await u.click(addLine());
    await waitFor(() => expect(region()).toHaveTextContent("Line 3 added: FLOUR-8. We couldn't load the lines again, so they may be out of date."));
    expect(texts.texts).toEqual(['', "Line 3 added: FLOUR-8. We couldn't load the lines again, so they may be out of date."]);
    texts.stop();
    // The section shows its own load failure with Retry; focus is never left on <body>.
    expect(screen.getByText("This order's lines couldn't be loaded.")).toBeInTheDocument();
    expect(document.activeElement).not.toBe(document.body);
  });

  it('added, but the order re-read fails: the page’s region error, and the "may be out of date" sentence', async () => {
    stubFetch([json(201, NEW_LINE), json(500, {})]);
    const u = userEvent.setup();
    show();
    await chooseAndType(u, E8, '2.5');
    await u.click(addLine());
    await waitFor(() => expect(region()).toHaveTextContent("Line 3 added: FLOUR-8. We couldn't load the lines again, so they may be out of date."));
    await waitFor(() => expect(screen.getByText("We couldn't load this purchase order again, so what's shown may be out of date.")).toBeInTheDocument());
  });

  it('H-2: a 422 "unknown_reference" on /inventory_item_id shows the client sentence beside Item (never the server’s words), keeps the row, and re-reads the catalogue', async () => {
    const calls = stubFetch([problem(422, 'validation-failed', { errors: [{ pointer: '/inventory_item_id', code: 'unknown_reference', message: SERVER_L2 }] }), json(200, { items: [E7, E8, E9], next_cursor: null })]);
    const u = userEvent.setup();
    show();
    await chooseAndType(u, E7, '1');
    await u.click(addLine());
    await waitFor(() => expect(item()).toHaveAccessibleDescription(ITEM_UNAVAILABLE));
    expect(document.body.textContent).not.toContain(SERVER_L2);
    expect(item()).toHaveFocus();
    expect(item()).toHaveValue(E7.inventory_item.id);
    expect(quantity()).toHaveValue('1');
    await waitFor(() => expect(calls.map((c) => [c.method, c.url])).toEqual([['POST', LINES_URL], ['GET', PRODUCTS_URL]]));
  });

  it('another 422 field sentence (the quantity) is the server’s, beside its field, focused; nothing is re-read', async () => {
    const SHAPE = 'This field must be a positive decimal string with at most 13 integer digits and 6 fractional digits.';
    const calls = stubFetch([problem(422, 'validation-failed', { errors: [{ pointer: '/quantity', code: 'not_a_positive_decimal', message: SHAPE }] })]);
    const u = userEvent.setup();
    show();
    await chooseAndType(u, E7, '2,5');
    await u.click(addLine());
    await waitFor(() => expect(quantity()).toHaveAccessibleDescription(expect.stringContaining(SHAPE)));
    expect(quantity()).toHaveFocus();
    expect(calls).toHaveLength(1);
  });

  it('L1 (no catalogue entry any more): the server’s sentence under "Not added", focused; the catalogue is read again and a choice it no longer lists is cleared, the quantity kept', async () => {
    const calls = stubFetch([refused(NOT_LISTED_RULE, L1), json(200, { items: [E8, E9], next_cursor: null })]);
    const u = userEvent.setup();
    show();
    await chooseAndType(u, E7, '1');
    await u.click(addLine());
    await waitFor(() => expect(screen.getByText('Not added')).toHaveFocus());
    expect(screen.getByText(L1)).toBeInTheDocument();
    await waitFor(() => expect(calls.map((c) => [c.method, c.url])).toEqual([['POST', LINES_URL], ['GET', PRODUCTS_URL]]));
    await waitFor(() => expect(within(item()).getAllByRole('option').map((o) => o.textContent)).toEqual(['Choose an item', 'FLOUR-8 — Flour', 'GADGET-9 — Gadget']));
    expect(item()).toHaveValue('');
    expect(quantity()).toHaveValue('1');
  });

  it('P9 (approved elsewhere): the page re-reads, turns read-only with no new row, and says under "Not added" that it is no longer a draft', async () => {
    stubFetch([refused(NOT_DRAFT_RULE, P9), json(200, { ...DRAFT, status: 'ordered', ordered_at: '2026-03-04T11:00:00Z' }), json(200, page([line(1), line(2)]))]);
    const u = userEvent.setup();
    show();
    await chooseAndType(u, E7, '1');
    await u.click(addLine());
    await waitFor(() => expect(screen.getByText('Not added')).toHaveFocus());
    expect(screen.getByText("PO-000001 is no longer a draft: it's ordered, so it can't be changed.")).toBeInTheDocument();
    expect(document.body.textContent).not.toContain(P9);
    expect(screen.queryByRole('row', { name: 'New line' })).toBeNull();
    expect(screen.queryByRole('button', { name: /^Delete line/ })).toBeNull();
  });

  it('P9 and its re-read fails: "changed since this page was loaded" under "Not added"; P8: the deleted banner and the server’s sentence', async () => {
    stubFetch([refused(NOT_DRAFT_RULE, P9), json(500, {})]);
    const u = userEvent.setup();
    const a = show();
    await chooseAndType(u, E7, '1');
    await u.click(addLine());
    await waitFor(() => expect(screen.getByText('PO-000001 changed since this page was loaded, so this wasn\'t done. Reload the page to see where it stands.')).toBeInTheDocument());
    a.unmount();
    vi.unstubAllGlobals();
    stubFetch([refused('purchase_order.is_archived', P8), json(200, { ...DRAFT, archived_at: '2026-03-04T11:00:00Z' })]);
    show();
    await chooseAndType(u, E7, '1');
    await u.click(addLine());
    await waitFor(() => expect(screen.getByText('Not added')).toHaveFocus());
    expect(screen.getByText(P8)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Restore purchase order' })).toBeInTheDocument();
  });

  it('a 403 names "Edit draft purchase orders"; an answer lost offline says "We can’t tell whether FLOUR-8 was added…"', async () => {
    stubFetch([problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    const a = show();
    await chooseAndType(u, E8, '2');
    await u.click(addLine());
    await waitFor(() => expect(screen.getByText('Not added')).toHaveFocus());
    expect(within(newRow().parentElement!).getByText('Edit draft purchase orders')).toBeInTheDocument();
    a.unmount();
    vi.unstubAllGlobals();
    vi.stubGlobal('fetch', vi.fn(async () => Promise.reject(new TypeError('offline'))));
    show();
    await chooseAndType(u, E8, '2');
    await u.click(addLine());
    await waitFor(() => expect(screen.getByText("We can't tell whether FLOUR-8 was added. Check this order's lines before you try again.")).toBeInTheDocument());
  });

  it('an empty catalogue: no fields, the passive sentence, and "Open Acme Supplies" only with GetSupplier', () => {
    const a = show({ add: { catalogue: { kind: 'ok', entries: [] } } });
    expect(within(newRow()).getByText("Acme Supplies's catalogue lists no items, so no lines can be added yet. Items can be added to it on the supplier's page.")).toBeInTheDocument();
    expect(screen.queryByRole('combobox')).toBeNull();
    expect(screen.queryByRole('link', { name: 'Open Acme Supplies' })).toBeNull();
    a.unmount();
    show({ add: { catalogue: { kind: 'ok', entries: [] } }, canOpenSupplier: true });
    expect(within(newRow()).getByRole('link', { name: 'Open Acme Supplies' })).toHaveAttribute('href', `/suppliers/${SUPPLIER}`);
  });

  it('a failed catalogue read: the row’s own region error; Retry → the Item select, focused; a Retry answered 403 names the catalogue permission', async () => {
    stubFetch([json(200, { items: [E7], next_cursor: null })]);
    const u = userEvent.setup();
    const a = show({ add: { catalogue: { kind: 'server-error' } } });
    expect(within(newRow()).getByText("Acme Supplies's catalogue couldn't be loaded, so no line can be added yet.")).toBeInTheDocument();
    await u.click(within(newRow()).getByRole('button', { name: /Try again|Retry/ }));
    await waitFor(() => expect(item()).toHaveFocus());
    expect(within(item()).getAllByRole('option').map((o) => o.textContent)).toEqual(['Choose an item', 'BOLT-7 — Bolt']);
    a.unmount();
    vi.unstubAllGlobals();
    stubFetch([problem(403, 'permission-denied')]);
    show({ add: { catalogue: { kind: 'unreachable' } } });
    await u.click(within(newRow()).getByRole('button', { name: /Try again|Retry/ }));
    await waitFor(() => expect(within(newRow()).getByText('View supplier catalogues, including what goods cost')).toBeInTheDocument());
    expect(within(newRow()).queryByRole('button', { name: /Try again|Retry/ })).toBeNull();
  });
});

describe('/purchase-orders/[id] — an empty draft, and who sees Add and Delete (L-5; the composed shape)', () => {
  it('an empty draft with Add: "This draft has no lines yet.", then the note, then the table holding only the new row', () => {
    show({ lines: { kind: 'ok', lines: [], nextCursor: null } });
    expect(screen.getByText('This draft has no lines yet.')).toBeInTheDocument();
    expect(screen.getByText(EMPTY_NOTE)).toBeInTheDocument();
    expect(bodyRows()).toHaveLength(0);
    expect(newRow()).toBeInTheDocument();
  });

  it('L-5: the note renders exactly when Add renders — a seat that may delete lines but not read the catalogue gets Delete, no Add and no note', () => {
    const a = show({ lines: { kind: 'ok', lines: [], nextCursor: null }, add: null, canDeleteLine: true });
    expect(screen.getByText('This draft has no lines yet.')).toBeInTheDocument();
    expect(screen.queryByText(EMPTY_NOTE)).toBeNull();
    expect(screen.queryByRole('row', { name: 'New line' })).toBeNull();
    a.unmount();
    show({ add: null, canDeleteLine: true });
    expect(screen.getByRole('button', { name: 'Delete line 1' })).toBeInTheDocument();
    expect(screen.queryByRole('row', { name: 'New line' })).toBeNull();
  });

  it('no Add and no Delete without the props, nor on a deleted draft or any other status', () => {
    const a = show({ add: null, canDeleteLine: false });
    expect(screen.queryByRole('row', { name: 'New line' })).toBeNull();
    expect(screen.queryByRole('button', { name: /^Delete/ })).toBeNull();
    expect(screen.getAllByRole('columnheader').map((h) => h.textContent)).not.toContain('Actions');
    a.unmount();
    for (const status of ['ordered', 'partially_received', 'received', 'cancelled']) {
      const { container, unmount } = show({ order: { ...DRAFT, status, ordered_at: '2026-03-04T11:00:00Z' } });
      expect(container.querySelectorAll('select, input, textarea, form')).toHaveLength(0);
      expect(screen.queryByRole('button', { name: /^Delete/ })).toBeNull();
      unmount();
    }
    show({ order: { ...DRAFT, archived_at: '2026-03-04T11:00:00Z' }, lines: null });
    expect(screen.queryByRole('row', { name: 'New line' })).toBeNull();
  });
});

describe('/purchase-orders/[id] — Delete line (PO-5; X-4: direct, no dialog)', () => {
  it('every live row has its own Delete, named by its line number (L-10: two rows, two names)', () => {
    show();
    expect(screen.getByRole('button', { name: 'Delete line 1' })).toHaveTextContent('Delete');
    expect(screen.getByRole('button', { name: 'Delete line 2' })).toHaveTextContent('Delete');
    expect(screen.getAllByRole('button', { name: /^Delete line \d$/ })).toHaveLength(2);
  });

  it('deleted: DELETE …/lines/{id} (no body, no key, no dialog); the order and lines re-read; focus the Lines heading, then "Line 2 (SKU-2) was deleted." — no restore clause on a page that draws no Deleted lines (PO-9 moved this test; po-9-red.md L-2)', async () => {
    const calls = stubFetch([empty(204), json(200, { ...DRAFT, total_amount: '25.0000' }), json(200, page([line(1)]))]);
    const u = userEvent.setup();
    show();
    const texts = textsOf(region());
    await u.click(screen.getByRole('button', { name: 'Delete line 2' }));
    await waitFor(() => expect(region()).toHaveTextContent('Line 2 (SKU-2) was deleted.'));
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(calls.map((c) => [c.method, c.url])).toEqual([['DELETE', `${LINES_URL}/${line(2).id}`], ['GET', URL], ['GET', LINES_URL]]);
    expect(calls[0]!.body).toBeNull();
    expect(calls[0]!.headers.get('Idempotency-Key')).toBeNull();
    expect(lineNumbers()).toEqual(['1']);
    expect(screen.getByText('25.00 GBP')).toBeInTheDocument();
    expect(linesHeading()).toHaveFocus();
    expect(texts.texts).toEqual(['', 'Line 2 (SKU-2) was deleted.']);
    texts.stop();
    // X-4's condition held until PO-9; PO-9 moved it deliberately (final RED "Checked and held"; po-9-red.md L-2): the restore
    // clause is spoken only while the page shows Deleted lines (`purchase-order-line-restore.test.tsx` pins it). This page was
    // given no deleted lines (a seat without the restore), so no subsection is drawn, no deleted-lines read is sent (the call
    // list above) and the sentence names no place to restore from.
    expect(region().textContent).not.toMatch(/restor/i);
    expect(screen.queryByRole('heading', { name: /Deleted lines/ })).toBeNull();
  });

  it('one Delete at a time: a press on another row while one is in flight sends nothing', async () => {
    const first = held();
    const calls = stubFetch([first.reply, json(200, DRAFT), json(200, page([line(1)]))]);
    const u = userEvent.setup();
    show();
    await u.click(screen.getByRole('button', { name: 'Delete line 2' }));
    await u.click(screen.getByRole('button', { name: 'Delete line 1' }));
    expect(calls.map((c) => c.method)).toEqual(['DELETE']);
    first.release(empty(204));
    await waitFor(() => expect(region()).toHaveTextContent('Line 2 (SKU-2) was deleted.'));
    expect(calls.map((c) => [c.method, c.url])).toEqual([['DELETE', `${LINES_URL}/${line(2).id}`], ['GET', URL], ['GET', LINES_URL]]);
  });

  it('typing in the new row, then deleting another line: the typed text stays (the section is not rebuilt by the re-read)', async () => {
    stubFetch([empty(204), json(200, DRAFT), json(200, page([line(2)]))]);
    const u = userEvent.setup();
    show();
    await chooseAndType(u, E8, '7');
    await u.click(screen.getByRole('button', { name: 'Delete line 1' }));
    await waitFor(() => expect(region()).toHaveTextContent('Line 1 (SKU-1) was deleted.'));
    expect(item()).toHaveValue(E8.inventory_item.id);
    expect(quantity()).toHaveValue('7');
    expect(unitCost()).toHaveValue('3.2500');
  });

  it('refused because the line was deleted elsewhere: the re-read is shown (the row goes) and the server’s sentence under "Not deleted", focused', async () => {
    stubFetch([refused('purchase_order_line.is_archived', W23), json(200, DRAFT), json(200, page([line(1)]))]);
    const u = userEvent.setup();
    show();
    await u.click(screen.getByRole('button', { name: 'Delete line 2' }));
    await waitFor(() => expect(screen.getByText('Not deleted')).toHaveFocus());
    expect(screen.getByText(W23)).toBeInTheDocument();
    expect(lineNumbers()).toEqual(['1']);
  });

  it('P9 (approved elsewhere): read-only, and under "Not deleted" that it is no longer a draft', async () => {
    stubFetch([refused(NOT_DRAFT_RULE, P9), json(200, { ...DRAFT, status: 'cancelled' }), json(200, page([line(1), line(2)]))]);
    const u = userEvent.setup();
    show();
    await u.click(screen.getByRole('button', { name: 'Delete line 1' }));
    await waitFor(() => expect(screen.getByText('Not deleted')).toHaveFocus());
    expect(screen.getByText("PO-000001 is no longer a draft: it's cancelled, so it can't be changed.")).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^Delete line/ })).toBeNull();
  });

  it('a 403 names "Edit draft purchase orders"; offline says "We can’t tell whether line 1 was deleted…"', async () => {
    stubFetch([problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    const a = show();
    await u.click(screen.getByRole('button', { name: 'Delete line 1' }));
    await waitFor(() => expect(screen.getByText('Not deleted')).toHaveFocus());
    expect(screen.getByText('Edit draft purchase orders')).toBeInTheDocument();
    a.unmount();
    vi.unstubAllGlobals();
    vi.stubGlobal('fetch', vi.fn(async () => Promise.reject(new TypeError('offline'))));
    show();
    await u.click(screen.getByRole('button', { name: 'Delete line 1' }));
    await waitFor(() => expect(screen.getByText("We can't tell whether line 1 was deleted. Check this order's lines before you try again.")).toBeInTheDocument());
    expect(document.activeElement).not.toBe(document.body);
  });
});

// po-5-red.md fixes M-1, M-2, L-1…L-4 (applied as prescribed).
describe('/purchase-orders/[id] — the line writes and what else is on the page (po-5-red.md M-1, M-2, L-1…L-4)', () => {
  const W1: WarehouseChoice = { id: DRAFT.warehouse.id, code: 'MAIN', name: DRAFT.warehouse.name };
  const W2: WarehouseChoice = { id: 'c1b2c3d4-0000-4000-8000-0000000000b2', code: 'EAST', name: 'East depot' };
  const EDIT = { warehouses: { kind: 'ok' as const, warehouses: [W1, W2] } };
  const deliverTo = () => screen.getByRole('combobox', { name: 'Deliver to' });
  const ADDED = 'Line 3 added: FLOUR-8.';
  const DELETED_2 = 'Line 2 (SKU-2) was deleted.';
  const OUT_OF_DATE = "We couldn't load this purchase order again, so what's shown may be out of date.";

  it('M-1: an unsaved Deliver to choice survives an Add, and Save then sends it', async () => {
    const calls = stubFetch([json(201, NEW_LINE), json(200, DRAFT), json(200, page([line(1), line(2), NEW_LINE])), json(200, { ...DRAFT, warehouse: { id: W2.id, name: W2.name } }), json(200, DRAFT), json(200, page([line(1), line(2), NEW_LINE]))]);
    const u = userEvent.setup();
    show({ edit: EDIT });
    await u.selectOptions(deliverTo(), W2.id);
    await chooseAndType(u, E8, '2.5');
    await u.click(addLine());
    await waitFor(() => expect(region()).toHaveTextContent(ADDED));
    expect(deliverTo()).toHaveValue(W2.id);
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(calls.filter((c) => c.method === 'PATCH')).toHaveLength(1));
    expect(JSON.parse(calls.find((c) => c.method === 'PATCH')!.body!)).toEqual({ warehouse_id: W2.id });
  });

  it('M-1: the same with Delete line', async () => {
    const calls = stubFetch([empty(204), json(200, DRAFT), json(200, page([line(1)])), json(200, { ...DRAFT, warehouse: { id: W2.id, name: W2.name } }), json(200, DRAFT), json(200, page([line(1)]))]);
    const u = userEvent.setup();
    show({ edit: EDIT });
    await u.selectOptions(deliverTo(), W2.id);
    await u.click(screen.getByRole('button', { name: 'Delete line 2' }));
    await waitFor(() => expect(region()).toHaveTextContent(DELETED_2));
    expect(deliverTo()).toHaveValue(W2.id);
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(calls.filter((c) => c.method === 'PATCH')).toHaveLength(1));
    expect(JSON.parse(calls.find((c) => c.method === 'PATCH')!.body!)).toEqual({ warehouse_id: W2.id });
  });

  it('M-2: two Adds answered with the same line (line 3, FLOUR-8; the number reused after a delete elsewhere): the sentence is spoken both times', async () => {
    const AGAIN = { ...NEW_LINE, id: 'f1b2c3d4-0000-4000-8000-000000000333' };
    stubFetch([json(201, NEW_LINE), json(200, DRAFT), json(200, page([line(1), line(2), NEW_LINE])), json(201, AGAIN), json(200, DRAFT), json(200, page([line(1), line(2), AGAIN]))]);
    const u = userEvent.setup();
    show();
    const texts = textsOf(region());
    await chooseAndType(u, E8, '2.5');
    await u.click(addLine());
    await waitFor(() => expect(region()).toHaveTextContent(ADDED));
    await chooseAndType(u, E8, '2.5');
    await u.click(addLine());
    await waitFor(() => expect(texts.texts).toEqual(['', ADDED, '', ADDED]));
    texts.stop();
  });

  it('M-2: Delete line 2, the re-read serves a new line 2 with SKU-2, delete it: the sentence is spoken both times', async () => {
    const NEW_2 = line(2, { id: 'f1b2c3d4-0000-4000-8000-000000000222' });
    stubFetch([empty(204), json(200, DRAFT), json(200, page([line(1), NEW_2])), empty(204), json(200, DRAFT), json(200, page([line(1)]))]);
    const u = userEvent.setup();
    show();
    const texts = textsOf(region());
    await u.click(screen.getByRole('button', { name: 'Delete line 2' }));
    await waitFor(() => expect(region()).toHaveTextContent(DELETED_2));
    await u.click(screen.getByRole('button', { name: 'Delete line 2' }));
    await waitFor(() => expect(texts.texts).toEqual(['', DELETED_2, '', DELETED_2]));
    texts.stop();
  });

  it('L-1: a refused Delete, then an Add — "Not deleted" goes with the newer reading', async () => {
    stubFetch([refused('purchase_order_line.is_archived', W23), json(200, DRAFT), json(200, page([line(1)])), json(201, NEW_LINE), json(200, DRAFT), json(200, page([line(1), NEW_LINE]))]);
    const u = userEvent.setup();
    show();
    await u.click(screen.getByRole('button', { name: 'Delete line 2' }));
    await waitFor(() => expect(screen.getByText('Not deleted')).toHaveFocus());
    await chooseAndType(u, E8, '2.5');
    await u.click(addLine());
    await waitFor(() => expect(region()).toHaveTextContent(ADDED));
    expect(screen.queryByText('Not deleted')).toBeNull();
    expect(screen.queryByText(W23)).toBeNull();
  });

  it('L-1: an Add whose answer was lost, then a Delete — "Not added" goes with the newer reading', async () => {
    stubFetch([
      () => {
        throw new TypeError('offline');
      },
      empty(204), json(200, DRAFT), json(200, page([line(1)]))]);
    const u = userEvent.setup();
    show();
    await chooseAndType(u, E8, '2');
    await u.click(addLine());
    await waitFor(() => expect(screen.getByText("We can't tell whether FLOUR-8 was added. Check this order's lines before you try again.")).toBeInTheDocument());
    await u.click(screen.getByRole('button', { name: 'Delete line 2' }));
    await waitFor(() => expect(region()).toHaveTextContent(DELETED_2));
    expect(screen.queryByText("We couldn't reach the server.")).toBeNull();
    expect(screen.queryByText("We can't tell whether FLOUR-8 was added. Check this order's lines before you try again.")).toBeNull();
  });

  it('L-2: the catalogue re-read after L1 is compared with the choice when it answers, not the one Add was pressed with', async () => {
    const reread = held();
    stubFetch([refused(NOT_LISTED_RULE, L1), reread.reply]);
    const u = userEvent.setup();
    show();
    await chooseAndType(u, E7, '1');
    await u.click(addLine());
    await waitFor(() => expect(screen.getByText('Not added')).toHaveFocus());
    await u.selectOptions(item(), E8.inventory_item.id);
    reread.release(json(200, { items: [E8, E9], next_cursor: null }));
    await waitFor(() => expect(within(item()).getAllByRole('option').map((o) => o.textContent)).toEqual(['Choose an item', 'FLOUR-8 — Flour', 'GADGET-9 — Gadget']));
    expect(item()).toHaveValue(E8.inventory_item.id);
    expect(unitCost()).toHaveValue('3.2500');
  });

  it('L-3: an Add press while a Delete is in flight sends nothing; once the Delete has answered, Add sends', async () => {
    const first = held();
    const calls = stubFetch([first.reply, json(200, DRAFT), json(200, page([line(1)])), json(201, NEW_LINE), json(200, DRAFT), json(200, page([line(1), NEW_LINE]))]);
    const u = userEvent.setup();
    show();
    await chooseAndType(u, E8, '2.5');
    await u.click(screen.getByRole('button', { name: 'Delete line 2' }));
    await u.click(addLine());
    expect(calls.map((c) => c.method)).toEqual(['DELETE']);
    first.release(empty(204));
    await waitFor(() => expect(region()).toHaveTextContent(DELETED_2));
    await u.click(addLine());
    await waitFor(() => expect(region()).toHaveTextContent(ADDED));
    expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET', 'GET', 'POST', 'GET', 'GET']);
  });

  it('L-3: a Delete press while an Add is in flight sends nothing; once the Add has answered, Delete sends', async () => {
    const first = held();
    const calls = stubFetch([first.reply, json(200, DRAFT), json(200, page([line(1), line(2), NEW_LINE])), empty(204), json(200, DRAFT), json(200, page([line(1), NEW_LINE]))]);
    const u = userEvent.setup();
    show();
    await chooseAndType(u, E8, '2.5');
    await u.click(addLine());
    await u.click(screen.getByRole('button', { name: 'Delete line 2' }));
    expect(calls.map((c) => c.method)).toEqual(['POST']);
    first.release(json(201, NEW_LINE));
    await waitFor(() => expect(region()).toHaveTextContent(ADDED));
    await u.click(screen.getByRole('button', { name: 'Delete line 2' }));
    await waitFor(() => expect(region()).toHaveTextContent(DELETED_2));
    expect(calls.map((c) => c.method)).toEqual(['POST', 'GET', 'GET', 'DELETE', 'GET', 'GET']);
  });

  it('po-5-red-2 L-3: two submits before the first answer (Enter in Quantity, then Add line) send one POST; after the answer the row is empty and one sentence is spoken', async () => {
    const first = held();
    const calls = stubFetch([first.reply, json(200, DRAFT), json(200, page([line(1), line(2), NEW_LINE]))]);
    const u = userEvent.setup();
    show();
    const texts = textsOf(region());
    await u.selectOptions(item(), E8.inventory_item.id);
    await u.type(quantity(), '2.5{Enter}');
    await u.click(addLine());
    expect(calls.map((c) => c.method)).toEqual(['POST']);
    first.release(json(201, NEW_LINE));
    await waitFor(() => expect(region()).toHaveTextContent(ADDED));
    expect(calls.map((c) => c.method)).toEqual(['POST', 'GET', 'GET']);
    expect(item()).toHaveValue('');
    expect(quantity()).toHaveValue('');
    expect(texts.texts).toEqual(['', ADDED]);
    texts.stop();
  });

  it('po-5-red-2 L-4: Delete answered 404, the lines re-read fails, then the section’s Retry answers ok — "Not deleted" goes with that newer reading', async () => {
    stubFetch([problem(404, 'not-found'), json(200, DRAFT), json(500, {}), json(200, page([line(1)]))]);
    const u = userEvent.setup();
    show();
    await u.click(screen.getByRole('button', { name: 'Delete line 2' }));
    await waitFor(() => expect(screen.getByText('Not deleted')).toHaveFocus());
    expect(screen.getByText("This order's lines couldn't be loaded.")).toBeInTheDocument();
    await u.click(screen.getByRole('button', { name: /Try again|Retry/ }));
    await waitFor(() => expect(lineNumbers()).toEqual(['1']));
    expect(screen.queryByText('Not deleted')).toBeNull();
  });

  it('L-4 (today’s behaviour, pinned): a Delete answered 204 whose order re-read fails — the page’s region error, the sentence spoken once, the last reading kept, focus not on <body>', async () => {
    stubFetch([empty(204), json(500, {})]);
    const u = userEvent.setup();
    show();
    const texts = textsOf(region());
    await u.click(screen.getByRole('button', { name: 'Delete line 2' }));
    await waitFor(() => expect(region()).toHaveTextContent(DELETED_2));
    await waitFor(() => expect(screen.getByText(OUT_OF_DATE)).toBeInTheDocument());
    expect(texts.texts).toEqual(['', DELETED_2]);
    texts.stop();
    expect(lineNumbers()).toEqual(['1', '2']);
    expect(screen.getByRole('button', { name: 'Delete line 2' })).toBeInTheDocument();
    expect(document.activeElement).not.toBe(document.body);
  });
});

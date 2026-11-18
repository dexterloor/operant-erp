import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: vi.fn(), refresh: vi.fn() }), usePathname: () => '/purchase-orders/x' }));
import { PurchaseOrderPage, type PurchaseOrderPageProps } from '@/app/(app)/purchase-orders/[id]/PurchaseOrderPage';
import type { PurchaseOrder, PurchaseOrderLine } from '@/lib/api/purchasing';
import type { CatalogueChoice, CatalogueChoicesLoad, LinesLoad, WarehouseChoice } from '@/lib/purchasing/load';
import { empty, held, json, page, problem, refused, renderWired, stubFetch, textsOf, VIEWER_ID } from './harness';

// Purchase orders PO-9 — the Deleted lines subsection and Restore line on a live draft (purchase-orders-final-red.md apply
// list PO-9: Revision 0 PO-9 with Revision 1a's names; M-6's re-read test re-run for Restore; the PO-5 "no restore wording"
// test moves; UX R1a-1 row 12 — `deletedLines*`, `RESTORE_LINE.*`, `DELETE_LINE.after` final). The page is the real
// `PurchaseOrderPage` and `LinesSection`; every write goes through the REAL `lib/purchasing/wired.ts`, `load.ts` and the
// browser transport.

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
const gone = (n: number) => line(n, { archived_at: '2026-03-04T11:00:00Z' });
const E8: CatalogueChoice = {
  id: 'b1b2c3d4-0000-4000-8000-000000000008',
  inventory_item: { id: 'a1b2c3d4-0000-4000-8000-000000000008', sku: 'FLOUR-8', name: 'Flour' },
  unit_cost: '3.2500',
  currency_code: 'GBP',
  unit_of_measure_code: 'KG',
};
const CATALOGUE: CatalogueChoicesLoad = { kind: 'ok', entries: [E8] };
const LINES: LinesLoad = { kind: 'ok', lines: [line(1), line(2)], nextCursor: null };
const DELETED: LinesLoad = { kind: 'ok', lines: [gone(3), gone(4)], nextCursor: null };

const show = (over: Partial<PurchaseOrderPageProps> = {}) =>
  renderWired(<PurchaseOrderPage order={DRAFT} lines={LINES} deletedLines={DELETED} canRestoreLine viewerId={VIEWER_ID} canRestore canReadLines canOpenSupplier={false} canOpenWarehouse={false} canOpenItem={false} add={{ catalogue: CATALOGUE }} canDeleteLine {...over} />);

const URL = `/bff/api/purchase-orders/${OID}`;
const LINES_URL = `${URL}/lines`;
const DELETED_URL = `${LINES_URL}?archived=true`;
const RESTORE_3 = `${LINES_URL}/${line(3).id}/restoration`;
const region = () => document.querySelector<HTMLElement>('section > [role="status"]')!;
const linesHeading = () => screen.getByRole('heading', { level: 2, name: 'Lines' });
const deletedHeading = () => screen.getByRole('heading', { level: 3, name: 'Deleted lines' });
const tables = () => [...document.querySelectorAll('table')];
/** The live table's line numbers (its rows other than the new line row). */
const liveNumbers = () => [...tables()[0]!.querySelectorAll('tbody tr')].filter((r) => r.getAttribute('aria-label') === null).map((r) => r.querySelector('td')?.textContent);
/** The Deleted lines table's line numbers, or null when it has none. */
const deletedTable = () => deletedHeading().parentElement!.querySelector('table');
const deletedNumbers = () => [...(deletedTable()?.querySelectorAll('tbody tr') ?? [])].map((r) => r.querySelector('td')?.textContent);
/** po-9-red.md M-3: the name carries the SKU, so two deleted lines with one number are told apart (PLACEHOLDER, UX TO CONFIRM). */
const restore = (n: number, sku = `SKU-${n}`) => screen.getByRole('button', { name: `Restore line ${n} (${sku})` });

const NOTE = "Lines deleted from this draft can be restored while it's still a draft.";
const AFTER_3 = 'Line 3 (SKU-3) was restored and counts in the total again.';
/** po-9-red.md M-2 (PLACEHOLDER, UX TO CONFIRM): a 200 whose re-read failed. */
const STALE_3 = "Line 3 (SKU-3) was restored. We couldn't load this order again, so its lines and total may be out of date.";
const L8 = 'A newer line on this order now has this line\'s number, so this line cannot be restored. Delete the newer line first, or add this line again.';
const W24 = 'This line is not archived, so there is nothing to restore.';
const P9 = 'This purchase order has been placed, so it is read-only except for its transitions.';
const NOT_DRAFT_RULE = 'purchase_order.is_editable_only_while_draft';

describe('/purchase-orders/[id] — Deleted lines (PO-9)', () => {
  it('a live draft shows the Deleted lines subsection: an h3 inside the Lines section, the note, the served deleted lines struck, each with its own Restore', () => {
    show();
    const h3 = deletedHeading();
    expect(h3.closest('section')).toBe(linesHeading().closest('section'));
    expect(within(h3.parentElement!).getByText(NOTE)).toBeInTheDocument();
    expect(deletedNumbers()).toEqual(['3', '4']);
    for (const r of deletedTable()!.querySelectorAll('tbody tr')) expect(r.className).toMatch(/struck/);
    expect(within(deletedTable()!).getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Line', 'Item', "Quantity (item's unit)", 'Unit cost (GBP)', 'Actions']);
    expect(restore(3)).toHaveTextContent('Restore');
    expect(restore(4)).toHaveTextContent('Restore');
    expect(screen.getAllByRole('button', { name: /^Restore line \d \(SKU-\d\)$/ })).toHaveLength(2);
    // The live table is unchanged: the deleted lines are not in it.
    expect(liveNumbers()).toEqual(['1', '2']);
  });

  it('no deleted line: "No deleted lines." under the note, no table', () => {
    show({ deletedLines: { kind: 'ok', lines: [], nextCursor: null } });
    expect(within(deletedHeading().parentElement!).getByText('No deleted lines.')).toBeInTheDocument();
    expect(deletedTable()).toBeNull();
  });

  it('only on a live draft, and only when the page was given it: never on an ordered, partly received, received, cancelled or deleted order', () => {
    const a = show({ deletedLines: null, canRestoreLine: false });
    expect(screen.queryByRole('heading', { name: 'Deleted lines' })).toBeNull();
    a.unmount();
    for (const status of ['ordered', 'partially_received', 'received', 'cancelled']) {
      const { unmount } = show({ order: { ...DRAFT, status, ordered_at: '2026-03-04T11:00:00Z' } });
      expect(screen.queryByRole('heading', { name: 'Deleted lines' })).toBeNull();
      expect(screen.queryByRole('button', { name: /^Restore line/ })).toBeNull();
      unmount();
    }
    show({ order: { ...DRAFT, archived_at: '2026-03-04T11:00:00Z' }, lines: null });
    expect(screen.queryByRole('heading', { name: 'Deleted lines' })).toBeNull();
  });

  it('po-9-red.md L-3: the deleted lines given without RestorePurchaseOrderLine’s codes draw nothing', () => {
    show({ canRestoreLine: false });
    expect(screen.queryByRole('heading', { name: 'Deleted lines' })).toBeNull();
    expect(screen.queryByRole('button', { name: /^Restore line/ })).toBeNull();
  });

  it('po-9-red.md M-3: two deleted lines with one number (delete line 3, add a line, delete it) have two names', () => {
    const OTHER_3 = line(3, { id: 'f1b2c3d4-0000-4000-8000-000000000333', inventory_item: { id: 'a1b2c3d4-0000-4000-8000-000000000009', sku: 'SKU-9', name: 'Item 9' }, archived_at: '2026-03-05T11:00:00Z' });
    show({ deletedLines: { kind: 'ok', lines: [gone(3), OTHER_3], nextCursor: null } });
    expect(restore(3)).toBeInTheDocument();
    expect(restore(3, 'SKU-9')).toBeInTheDocument();
    expect(screen.queryAllByRole('button', { name: 'Restore line 3' })).toHaveLength(0);
  });

  it('a deleted draft restored on this page: its deleted lines are read with the order and its lines, and Deleted lines shows them in the same mount', async () => {
    const calls = stubFetch([json(200, DRAFT), json(200, DRAFT), json(200, page([line(1)])), json(200, page([gone(3)]))]);
    const u = userEvent.setup();
    show({ order: { ...DRAFT, archived_at: '2026-03-04T11:00:00Z' }, lines: null, deletedLines: null });
    expect(screen.queryByRole('heading', { name: 'Deleted lines' })).toBeNull();
    await u.click(screen.getByRole('button', { name: 'Restore purchase order' }));
    await waitFor(() => expect(deletedNumbers()).toEqual(['3']));
    expect(calls.map((c) => [c.method, c.url])).toEqual([['POST', `${URL}/restoration`], ['GET', URL], ['GET', LINES_URL], ['GET', DELETED_URL]]);
    expect(liveNumbers()).toEqual(['1']);
  });

  it('the deleted lines’ own read failed: a placeholder sentence with Retry; Retry reads ?archived=true and shows the rows', async () => {
    const calls = stubFetch([json(200, page([gone(3)]))]);
    const u = userEvent.setup();
    show({ deletedLines: { kind: 'server-error' } });
    expect(within(deletedHeading().parentElement!).getByText("This draft's deleted lines couldn't be loaded.")).toBeInTheDocument();
    await u.click(within(deletedHeading().parentElement!).getByRole('button', { name: /Try again|Retry/ }));
    await waitFor(() => expect(deletedNumbers()).toEqual(['3']));
    expect(calls.map((c) => [c.method, c.url])).toEqual([['GET', DELETED_URL]]);
  });
});

describe('/purchase-orders/[id] — Restore line (PO-9; no dialog)', () => {
  it('restored: POST …/restoration (no body, no key); order, lines and deleted lines re-read; the row returns to the live table; focus the Lines heading, then the sentence', async () => {
    const calls = stubFetch([json(200, line(3)), json(200, { ...DRAFT, total_amount: '75.0000' }), json(200, page([line(1), line(2), line(3)])), json(200, page([gone(4)]))]);
    const u = userEvent.setup();
    show();
    const texts = textsOf(region());
    await u.click(restore(3));
    await waitFor(() => expect(region()).toHaveTextContent(AFTER_3));
    expect(calls.map((c) => [c.method, c.url])).toEqual([['POST', RESTORE_3], ['GET', URL], ['GET', LINES_URL], ['GET', DELETED_URL]]);
    expect(calls[0]!.body).toBeNull();
    expect(calls[0]!.headers.get('Idempotency-Key')).toBeNull();
    expect(liveNumbers()).toEqual(['1', '2', '3']);
    expect(deletedNumbers()).toEqual(['4']);
    expect(screen.getByText('75.00 GBP')).toBeInTheDocument();
    expect(linesHeading()).toHaveFocus();
    expect(texts.texts).toEqual(['', AFTER_3]);
    texts.stop();
  });

  it('L8 (a newer line has the number): the server’s sentence under "Not restored", focused; the row and its Restore stay', async () => {
    stubFetch([refused('purchase_order_line.line_number_is_taken_by_a_live_line', L8), json(200, DRAFT), json(200, page([line(1), line(2), line(3, { id: 'f1b2c3d4-0000-4000-8000-000000000333' })])), json(200, page([gone(3), gone(4)]))]);
    const u = userEvent.setup();
    show();
    await u.click(restore(3));
    await waitFor(() => expect(screen.getByText('Not restored')).toHaveFocus());
    expect(screen.getByText(L8)).toBeInTheDocument();
    expect(deletedNumbers()).toEqual(['3', '4']);
    expect(restore(3)).toBeInTheDocument();
    expect(region().textContent).toBe('');
  });

  it('W24 (restored elsewhere): the re-read shows it live — focus the Lines heading, then "Line 3 (SKU-3) was already restored." (never the server’s words)', async () => {
    stubFetch([refused('purchase_order_line.is_not_archived', W24), json(200, DRAFT), json(200, page([line(1), line(2), line(3)])), json(200, page([gone(4)]))]);
    const u = userEvent.setup();
    show();
    await u.click(restore(3));
    await waitFor(() => expect(region()).toHaveTextContent('Line 3 (SKU-3) was already restored.'));
    expect(document.body.textContent).not.toContain(W24);
    expect(liveNumbers()).toEqual(['1', '2', '3']);
    expect(deletedNumbers()).toEqual(['4']);
    expect(linesHeading()).toHaveFocus();
  });

  it('P9 (approved elsewhere): the page turns read-only, no Deleted lines, and the page banner says under "Not restored" that it is no longer a draft', async () => {
    const calls = stubFetch([refused(NOT_DRAFT_RULE, P9), json(200, { ...DRAFT, status: 'ordered', ordered_at: '2026-03-04T11:00:00Z' }), json(200, page([line(1), line(2)]))]);
    const u = userEvent.setup();
    show();
    await u.click(restore(3));
    await waitFor(() => expect(screen.getByText('Not restored')).toHaveFocus());
    // The page's banner, not the Lines section's; and no deleted lines are read for an order that is no longer a draft.
    expect(screen.getByText('Not restored').closest('section')).not.toBe(linesHeading().closest('section'));
    expect(calls.map((c) => [c.method, c.url])).toEqual([['POST', RESTORE_3], ['GET', URL], ['GET', LINES_URL]]);
    expect(screen.getByText("PO-000001 is no longer a draft: it's ordered, so it can't be changed.")).toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Deleted lines' })).toBeNull();
    expect(screen.queryByRole('button', { name: /^Restore line/ })).toBeNull();
  });

  it('a 403 names "Edit draft purchase orders"; offline says "We can’t tell whether line 3 was restored. Press Restore to try again." and keeps the button', async () => {
    stubFetch([problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    const a = show();
    await u.click(restore(3));
    await waitFor(() => expect(screen.getByText('Not restored')).toHaveFocus());
    expect(screen.getByText('Edit draft purchase orders')).toBeInTheDocument();
    a.unmount();
    vi.unstubAllGlobals();
    vi.stubGlobal('fetch', vi.fn(async () => Promise.reject(new TypeError('offline'))));
    show();
    await u.click(restore(3));
    await waitFor(() => expect(screen.getByText("We can't tell whether line 3 was restored. Press Restore to try again.")).toBeInTheDocument());
    expect(restore(3)).toBeInTheDocument();
    expect(document.activeElement).not.toBe(document.body);
  });

  it('po-9-red.md M-2: a 200 whose order re-read failed says the lines and total may be out of date; the page Retry then reads the deleted lines with the lines', async () => {
    const calls = stubFetch([json(200, line(3)), json(500, {}), json(200, DRAFT), json(200, page([line(1), line(2), line(3)])), json(200, page([gone(4)]))]);
    const u = userEvent.setup();
    show();
    const texts = textsOf(region());
    await u.click(restore(3));
    await waitFor(() => expect(region()).toHaveTextContent(STALE_3));
    expect(texts.texts).toEqual(['', STALE_3]);
    texts.stop();
    await waitFor(() => expect(screen.getByText("We couldn't load this purchase order again, so what's shown may be out of date.")).toBeInTheDocument());
    await u.click(screen.getAllByRole('button', { name: /Try again|Retry/ })[0]!);
    await waitFor(() => expect(deletedNumbers()).toEqual(['4']));
    expect(liveNumbers()).toEqual(['1', '2', '3']);
    expect(calls.map((c) => [c.method, c.url])).toEqual([['POST', RESTORE_3], ['GET', URL], ['GET', URL], ['GET', LINES_URL], ['GET', DELETED_URL]]);
  });

  it('30d2edb: the same sentence twice (line 3 restored, its re-read failed, the page Retry shows it deleted again elsewhere, restored again) is spoken both times', async () => {
    stubFetch([
      json(200, line(3)), json(500, {}),
      json(200, DRAFT), json(200, page([line(1), line(2)])), json(200, page([gone(3), gone(4)])),
      json(200, line(3)), json(500, {}),
    ]);
    const u = userEvent.setup();
    show();
    const texts = textsOf(region());
    await u.click(restore(3));
    await waitFor(() => expect(region()).toHaveTextContent(STALE_3));
    await waitFor(() => expect(screen.getAllByRole('button', { name: /Try again|Retry/ })).toHaveLength(1));
    await u.click(screen.getByRole('button', { name: /Try again|Retry/ }));
    await waitFor(() => expect(screen.queryByRole('button', { name: /Try again|Retry/ })).toBeNull());
    // A coherent screen: line 3 is under Deleted lines only.
    expect(liveNumbers()).toEqual(['1', '2']);
    expect(deletedNumbers()).toEqual(['3', '4']);
    await u.click(restore(3));
    await waitFor(() => expect(texts.texts).toEqual(['', STALE_3, '', STALE_3]));
    texts.stop();
  });

  it('po-9-red.md L-1: W24 whose lines re-read failed still says "already restored", never the server’s W24', async () => {
    stubFetch([refused('purchase_order_line.is_not_archived', W24), json(200, DRAFT), json(500, {}), json(200, page([gone(4)]))]);
    const u = userEvent.setup();
    show();
    await u.click(restore(3));
    await waitFor(() => expect(region()).toHaveTextContent('Line 3 (SKU-3) was already restored.'));
    expect(document.body.textContent).not.toContain(W24);
  });

  it('M-6 (re-run for Restore): text typed in the new line row survives a Restore', async () => {
    stubFetch([json(200, line(3)), json(200, DRAFT), json(200, page([line(1), line(2), line(3)])), json(200, page([gone(4)]))]);
    const u = userEvent.setup();
    show();
    await u.selectOptions(screen.getByRole('combobox', { name: 'Item, new line' }), E8.inventory_item.id);
    await u.type(screen.getByRole('textbox', { name: 'Quantity, new line' }), '7');
    await u.click(restore(3));
    await waitFor(() => expect(region()).toHaveTextContent(AFTER_3));
    expect(screen.getByRole('combobox', { name: 'Item, new line' })).toHaveValue(E8.inventory_item.id);
    expect(screen.getByRole('textbox', { name: 'Quantity, new line' })).toHaveValue('7');
  });
});

describe('/purchase-orders/[id] — Restore line among the page’s other writes (one line write at a time)', () => {
  it('a Restore press while a Delete is in flight sends nothing; a Delete press while a Restore is in flight sends nothing', async () => {
    const first = held();
    const calls = stubFetch([first.reply, json(200, DRAFT), json(200, page([line(1)])), json(200, page([gone(2), gone(3), gone(4)]))]);
    const u = userEvent.setup();
    const a = show();
    await u.click(screen.getByRole('button', { name: 'Delete line 2' }));
    await u.click(restore(3));
    expect(calls.map((c) => c.method)).toEqual(['DELETE']);
    first.release(empty(204));
    await waitFor(() => expect(region()).toHaveTextContent('Line 2 (SKU-2) was deleted.'));
    a.unmount();
    vi.unstubAllGlobals();
    const second = held();
    const calls2 = stubFetch([second.reply, json(200, DRAFT), json(200, page([line(1), line(2), line(3)])), json(200, page([gone(4)]))]);
    show();
    await u.click(restore(3));
    await u.click(screen.getByRole('button', { name: 'Delete line 2' }));
    await u.click(screen.getByRole('button', { name: 'Add line' }));
    expect(calls2.map((c) => c.method)).toEqual(['POST']);
    second.release(json(200, line(3)));
    await waitFor(() => expect(region()).toHaveTextContent(AFTER_3));
    expect(calls2.map((c) => c.method)).toEqual(['POST', 'GET', 'GET', 'GET']);
  });

  it('po-8-red H-1 shape: while a Restore is in flight, Approve and Delete purchase order open nothing', async () => {
    const first = held();
    const calls = stubFetch([first.reply, json(200, DRAFT), json(200, page([line(1), line(2), line(3)])), json(200, page([gone(4)]))]);
    const u = userEvent.setup();
    show({ canApprove: true, canDelete: true });
    await u.click(restore(3));
    await u.click(screen.getByRole('button', { name: 'Approve' }));
    await u.click(screen.getByRole('button', { name: 'Delete purchase order' }));
    expect(screen.queryByRole('dialog')).toBeNull();
    first.release(json(200, line(3)));
    await waitFor(() => expect(region()).toHaveTextContent(AFTER_3));
    expect(calls.map((c) => c.method)).toEqual(['POST', 'GET', 'GET', 'GET']);
  });
});

describe('/purchase-orders/[id] — Delete line once Deleted lines exists (PO-9 moves the PO-5 test)', () => {
  it('deleted: the deleted lines are re-read with the order and lines; the row appears under Deleted lines; the sentence names where it can be restored from', async () => {
    const calls = stubFetch([empty(204), json(200, { ...DRAFT, total_amount: '25.0000' }), json(200, page([line(1)])), json(200, page([gone(2), gone(3), gone(4)]))]);
    const u = userEvent.setup();
    show();
    const texts = textsOf(region());
    await u.click(screen.getByRole('button', { name: 'Delete line 2' }));
    const AFTER = 'Line 2 (SKU-2) was deleted. It can be restored from Deleted lines.';
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    expect(calls.map((c) => [c.method, c.url])).toEqual([['DELETE', `${LINES_URL}/${line(2).id}`], ['GET', URL], ['GET', LINES_URL], ['GET', DELETED_URL]]);
    expect(liveNumbers()).toEqual(['1']);
    expect(deletedNumbers()).toEqual(['2', '3', '4']);
    expect(linesHeading()).toHaveFocus();
    expect(texts.texts).toEqual(['', AFTER]);
    texts.stop();
  });

  it('an Add re-reads no deleted lines (it deletes none)', async () => {
    const NEW = line(5, { inventory_item: E8.inventory_item });
    const calls = stubFetch([json(201, NEW), json(200, DRAFT), json(200, page([line(1), line(2), NEW]))]);
    const u = userEvent.setup();
    show();
    await u.selectOptions(screen.getByRole('combobox', { name: 'Item, new line' }), E8.inventory_item.id);
    await u.type(screen.getByRole('textbox', { name: 'Quantity, new line' }), '1');
    await u.click(screen.getByRole('button', { name: 'Add line' }));
    await waitFor(() => expect(region()).toHaveTextContent('Line 5 added: FLOUR-8.'));
    expect(calls.map((c) => [c.method, c.url])).toEqual([['POST', LINES_URL], ['GET', URL], ['GET', LINES_URL]]);
    expect(deletedNumbers()).toEqual(['3', '4']);
  });
});

// po-9-red.md M-1: a line is never shown both live and under Deleted lines, nor missing from both, whatever commit lands last.
describe('/purchase-orders/[id] — Deleted lines stays true across the page’s other readings (po-9-red.md M-1)', () => {
  const W1: WarehouseChoice = { id: DRAFT.warehouse.id, code: 'MAIN', name: DRAFT.warehouse.name };
  const W2: WarehouseChoice = { id: 'c1b2c3d4-0000-4000-8000-0000000000b2', code: 'EAST', name: 'East depot' };
  const EDIT = { warehouses: { kind: 'ok' as const, warehouses: [W1, W2] } };
  const deliverTo = () => screen.getByRole('combobox', { name: 'Deliver to' });

  it('PLANT-3: a header Save and a Restore at once, the Save’s reading landing last — line 3 is live and not under Deleted lines', async () => {
    const save = held();
    const restored = held();
    const restoreRead = held();
    const calls = stubFetch([save.reply, restored.reply, restoreRead.reply, json(200, DRAFT), json(200, page([line(1), line(2), line(3)])), json(200, page([gone(4)])), json(200, page([line(1), line(2), line(3)])), json(200, page([gone(4)]))]);
    const u = userEvent.setup();
    show({ edit: EDIT });
    await u.selectOptions(deliverTo(), W2.id);
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await u.click(restore(3));
    restored.release(json(200, line(3)));
    await waitFor(() => expect(calls).toHaveLength(3));
    save.release(json(200, { ...DRAFT, warehouse: { id: W2.id, name: W2.name } }));
    await waitFor(() => expect(region()).toHaveTextContent('PO-000001 was saved.'));
    restoreRead.release(json(200, DRAFT));
    await waitFor(() => expect(region()).toHaveTextContent(AFTER_3));
    expect(liveNumbers()).toEqual(['1', '2', '3']);
    expect(deletedNumbers()).toEqual(['4']);
  });

  it('a header Save and a Delete line at once, the Save’s reading landing last — line 2 is under Deleted lines, as the sentence says', async () => {
    const save = held();
    const deleted = held();
    const deleteRead = held();
    const calls = stubFetch([save.reply, deleted.reply, deleteRead.reply, json(200, DRAFT), json(200, page([line(1)])), json(200, page([gone(2), gone(3), gone(4)])), json(200, page([line(1)])), json(200, page([gone(2), gone(3), gone(4)]))]);
    const u = userEvent.setup();
    show({ edit: EDIT });
    await u.selectOptions(deliverTo(), W2.id);
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await u.click(screen.getByRole('button', { name: 'Delete line 2' }));
    deleted.release(empty(204));
    await waitFor(() => expect(calls).toHaveLength(3));
    save.release(json(200, { ...DRAFT, warehouse: { id: W2.id, name: W2.name } }));
    await waitFor(() => expect(region()).toHaveTextContent('PO-000001 was saved.'));
    deleteRead.release(json(200, DRAFT));
    await waitFor(() => expect(region()).toHaveTextContent('Line 2 (SKU-2) was deleted. It can be restored from Deleted lines.'));
    expect(liveNumbers()).toEqual(['1']);
    expect(deletedNumbers()).toEqual(['2', '3', '4']);
  });

  it('an Add whose reading shows a deleted line live again (restored elsewhere): it leaves Deleted lines', async () => {
    const NEW = line(5, { inventory_item: E8.inventory_item });
    stubFetch([json(201, NEW), json(200, DRAFT), json(200, page([line(1), line(2), line(3), NEW]))]);
    const u = userEvent.setup();
    show();
    await u.selectOptions(screen.getByRole('combobox', { name: 'Item, new line' }), E8.inventory_item.id);
    await u.type(screen.getByRole('textbox', { name: 'Quantity, new line' }), '1');
    await u.click(screen.getByRole('button', { name: 'Add line' }));
    await waitFor(() => expect(region()).toHaveTextContent('Line 5 added: FLOUR-8.'));
    expect(liveNumbers()).toEqual(['1', '2', '3', '5']);
    expect(deletedNumbers()).toEqual(['4']);
  });
});

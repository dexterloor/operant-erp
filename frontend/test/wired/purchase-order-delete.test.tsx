import { StrictMode, type ReactElement } from 'react';
import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: vi.fn(), refresh: nav.refresh }), usePathname: () => '/purchase-orders/x' }));
import { PurchaseOrderPage, type PurchaseOrderPageProps } from '@/app/(app)/purchase-orders/[id]/PurchaseOrderPage';
import type { PurchaseOrder, PurchaseOrderLine } from '@/lib/api/purchasing';
import type { CatalogueChoice, LinesLoad, WarehouseChoice, WarehouseChoicesLoad } from '@/lib/purchasing/load';
import { VIEWER_ID, empty, held, json, page, problem, refused, renderWired, stubFetch, textsOf } from './harness';

// Purchase orders PO-6 — Delete a draft purchase order (purchase-orders-final-red.md apply list PO-6; plan Revision 1 R1-1
// row 6 / R1-3 "Delete only on a live draft" over Revision 0 §2.2 and §4 PO-6 — the FE-D1 order, the 204 floor
// (`deletedAsOf`), `committedArchived` and the "restored in between" sentence of `WarehousePage.tsx`; UX R1a-1 row 6 over
// B-14 `DELETE_ORDER`). The page is the real `PurchaseOrderPage` with the shared `components/records/DeleteDialog`;
// Delete goes through the REAL `lib/purchasing/wired.ts`, `load.ts` and the browser transport. Its compensation,
// Restore purchase order, shipped at PO-2 (purchase-order-restore.test.tsx).

afterEach(() => {
  vi.unstubAllGlobals();
  nav.refresh.mockClear();
});

const OID = 'd1b2c3d4-0000-4000-8000-000000000001';
const AT = '2026-03-04T11:00:00Z';
const W1: WarehouseChoice = { id: 'c1b2c3d4-0000-4000-8000-0000000000b1', code: 'MAIN', name: 'Main warehouse' };
const W2: WarehouseChoice = { id: 'c1b2c3d4-0000-4000-8000-0000000000b2', code: 'EAST', name: 'East depot' };
const DRAFT: PurchaseOrder = {
  id: OID,
  order_number: 'PO-000001',
  status: 'draft',
  supplier: { id: 'c1b2c3d4-0000-4000-8000-0000000000a1', name: 'Acme Supplies' },
  warehouse: { id: W1.id, name: W1.name },
  currency_code: 'GBP',
  total_amount: '25.0000',
  ordered_at: null,
  expected_at: null,
  created_by: { id: 'e1b2c3d4-0000-4000-8000-000000000001', display_name: 'Bea Buyer' },
  archived_at: null,
};
const DELETED: PurchaseOrder = { ...DRAFT, archived_at: AT };
const ORDERED: PurchaseOrder = { ...DRAFT, status: 'ordered', ordered_at: '2026-03-05T09:00:00Z' };
const line = (n: number): PurchaseOrderLine => ({
  id: `f1b2c3d4-0000-4000-8000-${String(n).padStart(12, '0')}`,
  line_number: n,
  inventory_item: { id: `a1b2c3d4-0000-4000-8000-${String(n).padStart(12, '0')}`, sku: `SKU-${n}`, name: `Item ${n}` },
  quantity: '2.000000',
  quantity_received: '0.000000',
  quantity_outstanding: '2.000000',
  unit_cost: '12.5000',
  archived_at: null,
});
const LINES: LinesLoad = { kind: 'ok', lines: [line(1), line(2)], nextCursor: null };
const EDIT: { warehouses: WarehouseChoicesLoad } = { warehouses: { kind: 'ok', warehouses: [W1, W2] } };
const E7: CatalogueChoice = { id: 'b1b2c3d4-0000-4000-8000-000000000007', inventory_item: { id: 'a1b2c3d4-0000-4000-8000-000000000007', sku: 'BOLT-7', name: 'Bolt' }, unit_cost: '1.0000', currency_code: 'GBP', unit_of_measure_code: 'EA' };

const show = (over: Partial<PurchaseOrderPageProps> = {}) =>
  renderWired(<PurchaseOrderPage order={DRAFT} lines={LINES} viewerId={VIEWER_ID} canRestore canReadLines canOpenSupplier={false} canOpenWarehouse={false} canOpenItem={false} canDelete {...over} />);
/** The editing seat: the header editor, Add line and Delete line too. */
const showEditor = (over: Partial<PurchaseOrderPageProps> = {}) => show({ edit: EDIT, add: { catalogue: { kind: 'ok', entries: [E7] } }, canDeleteLine: true, ...over });

const URL = `/bff/api/purchase-orders/${OID}`;
const LINES_URL = `${URL}/lines`;
const region = () => document.querySelector<HTMLElement>('section > [role="status"]')!;
const dialog = () => screen.getByRole('dialog');
const deleteOrder = () => screen.getByRole('button', { name: 'Delete purchase order' });
const inDialog = (name: string) => within(dialog()).getByRole('button', { name });
const banner = () => screen.queryByText(/This draft was deleted on/);
const h1 = () => screen.getByRole('heading', { level: 1 });

const TITLE = 'Delete PO-000001?';
const BODY = 'It moves to the archived purchase orders and can be restored from there, with its lines.';
const AFTER = "PO-000001 was deleted. It's now in the archived purchase orders.";
const AFTER_RESTORED = 'PO-000001 was deleted, and has since been restored.';
const INTERRUPTED = "We can't tell whether PO-000001 was deleted. Press Delete purchase order to try again.";
const REREAD_FAILED = "We couldn't load this purchase order again, so what's shown may be out of date.";
const DRAFT_NOTE = 'This is a draft. It can be changed or deleted.';
const SUPPLIER_FIXED = "The supplier can't be changed. To order from another supplier, this draft can be deleted and a new one created.";
/** P10 (`PurchaseOrderWriter::archive()`, `purchase_order.is_archived`) and P9's rule (`refuseUnlessDraft()`; plan Response L-1: P11 is P9). */
const P10 = 'This purchase order is already archived.';
const P9 = 'This purchase order has been placed, so it is read-only except for its transitions.';
const NOT_DRAFT_RULE = 'purchase_order.is_editable_only_while_draft';
const P1 = 'Some of the same records were being changed at the same moment. Nothing was saved; try again.';
/** P8 (`PurchaseOrderWriter::refuseIfArchived`): a Save refused because the order is deleted. */
const P8 = "This purchase order is archived, so it can't be changed.";
const SAVED = 'PO-000001 was saved.';

/** Opens the dialog and presses its Delete purchase order. */
async function confirmDelete(u: ReturnType<typeof userEvent.setup>) {
  await u.click(deleteOrder());
  await u.click(inDialog('Delete purchase order'));
}

describe('/purchase-orders/[id] — Delete purchase order (PO-6; UX B-14; ST-9b)', () => {
  it('offered on a live draft to a DeletePurchaseOrder holder only: never without the code, on a deleted draft, or on any other status', () => {
    const a = show({ canDelete: false });
    expect(screen.queryByRole('button', { name: 'Delete purchase order' })).toBeNull();
    a.unmount();
    const b = show({ order: DELETED, lines: null });
    expect(screen.queryByRole('button', { name: 'Delete purchase order' })).toBeNull();
    b.unmount();
    for (const status of ['ordered', 'partially_received', 'received', 'cancelled']) {
      const { unmount } = show({ order: { ...DRAFT, status } });
      expect(screen.queryByRole('button', { name: 'Delete purchase order' })).toBeNull();
      unmount();
    }
    show();
    expect(deleteOrder()).toBeInTheDocument();
  });

  it('the dialog: "Delete {number}?", B-14 body, focus on Go back; Go back sends nothing and re-reads nothing', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    show();
    await u.click(deleteOrder());
    expect(within(dialog()).getByRole('heading', { name: TITLE })).toBeInTheDocument();
    expect(dialog()).toHaveAccessibleDescription(BODY);
    await waitFor(() => expect(inDialog('Go back')).toHaveFocus());
    await u.click(inDialog('Go back'));
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(calls).toEqual([]);
    expect(deleteOrder()).toHaveFocus();
  });

  it('a 204: DELETE with no body and no key; the re-read turns the page deleted (banner, Restore), no lines read; focus h1; "After" spoken once; one refresh', async () => {
    const calls = stubFetch([empty(204), json(200, DELETED)]);
    const u = userEvent.setup();
    showEditor();
    const spoken = textsOf(region());
    await confirmDelete(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    spoken.stop();
    expect(calls.map((c) => [c.method, c.url])).toEqual([
      ['DELETE', URL],
      ['GET', URL],
    ]);
    expect(calls[0]!.body).toBeNull();
    expect(calls[0]!.headers.get('idempotency-key')).toBeNull();
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(h1()).toHaveFocus();
    expect(banner()).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Restore purchase order' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Delete purchase order' })).toBeNull();
    expect(screen.queryByRole('heading', { level: 2, name: 'Lines' })).toBeNull();
    expect(screen.queryByRole('combobox')).toBeNull();
    expect(spoken.texts.filter((t) => t !== '')).toEqual([AFTER]);
    expect(nav.refresh).toHaveBeenCalledTimes(1);
  });

  it('a 204 whose re-read reads the order live (restored in between): the page is live with its actions and lines, and says it has since been restored', async () => {
    const calls = stubFetch([empty(204), json(200, DRAFT), json(200, page([line(1), line(2)]))]);
    const u = userEvent.setup();
    showEditor();
    const spoken = textsOf(region());
    await confirmDelete(u);
    await waitFor(() => expect(region().textContent).toBe(AFTER_RESTORED));
    spoken.stop();
    expect(spoken.texts.filter((t) => t !== '')).toEqual([AFTER_RESTORED]);
    expect(calls.map((c) => [c.method, c.url])).toEqual([
      ['DELETE', URL],
      ['GET', URL],
      ['GET', LINES_URL],
    ]);
    expect(banner()).toBeNull();
    expect(deleteOrder()).toBeInTheDocument();
    expect(screen.getByRole('combobox', { name: 'Deliver to' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Add line' })).toBeInTheDocument();
    expect(h1()).toHaveFocus();
  });

  it('a 204 whose re-read fails: "After" is still spoken, the region error shows, and the page is read-only — no Delete purchase order, no editor, no Add line, no line Delete, no draft note', async () => {
    const calls = stubFetch([empty(204), problem(503, 'x')]);
    const u = userEvent.setup();
    showEditor();
    await confirmDelete(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    await waitFor(() => expect(screen.getByText(REREAD_FAILED)).toBeInTheDocument());
    expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET']);
    expect(screen.queryByRole('button', { name: 'Delete purchase order' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Save' })).toBeNull();
    expect(screen.queryByRole('combobox')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Add line' })).toBeNull();
    expect(screen.queryByRole('button', { name: /^Delete line/ })).toBeNull();
    expect(screen.queryByText(DRAFT_NOTE)).toBeNull();
    expect(screen.queryByText(SUPPLIER_FIXED)).toBeNull();
  });

  it('…then Retry reads it deleted: the banner with Restore; a later Restore turns the page live and editable again (the 204 fact is cleared by a later commit)', async () => {
    stubFetch([empty(204), problem(503, 'x'), json(200, DELETED), json(200, DRAFT), json(200, DRAFT), json(200, page([line(1), line(2)]))]);
    const u = userEvent.setup();
    showEditor();
    await confirmDelete(u);
    await waitFor(() => expect(screen.getByText(REREAD_FAILED)).toBeInTheDocument());
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(banner()).toBeInTheDocument());
    expect(screen.queryByText(REREAD_FAILED)).toBeNull();
    await u.click(screen.getByRole('button', { name: 'Restore purchase order' }));
    await waitFor(() => expect(deleteOrder()).toBeInTheDocument());
    expect(screen.getByText(DRAFT_NOTE, { selector: 'p' })).toBeInTheDocument();
    expect(screen.getByRole('combobox', { name: 'Deliver to' })).toBeInTheDocument();
  });

  it('P10 (already deleted elsewhere): "Not deleted" + the server sentence as sent, heading focused; Delete removed, only Close; Close re-reads and the page turns deleted, focus on the h1', async () => {
    const calls = stubFetch([refused('purchase_order.is_archived', P10), json(200, DELETED)]);
    const u = userEvent.setup();
    show();
    await confirmDelete(u);
    await waitFor(() => expect(within(dialog()).getByText('Not deleted')).toHaveFocus());
    expect(dialog()).toHaveTextContent(P10);
    expect(within(dialog()).queryByRole('button', { name: 'Delete purchase order' })).toBeNull();
    expect(within(dialog()).queryByRole('button', { name: 'Go back' })).toBeNull();
    expect(calls.map((c) => c.method)).toEqual(['DELETE']);
    await u.click(inDialog('Close'));
    await waitFor(() => expect(banner()).toBeInTheDocument());
    expect(calls.map((c) => [c.method, c.url])).toEqual([
      ['DELETE', URL],
      ['GET', URL],
    ]);
    expect(h1()).toHaveFocus();
    expect(nav.refresh).toHaveBeenCalledTimes(1);
  });

  it('P11 (P9’s rule: approved elsewhere): the dialog says UX D-2’s sentence from the re-read, never the server’s; only Close; the page under it turns read-only while it stays open; Close re-reads, focus on the h1', async () => {
    const calls = stubFetch([refused(NOT_DRAFT_RULE, P9), json(200, ORDERED), json(200, page([line(1), line(2)])), json(200, ORDERED), json(200, page([line(1), line(2)]))]);
    const u = userEvent.setup();
    showEditor();
    await confirmDelete(u);
    await waitFor(() => expect(within(dialog()).getByText('Not deleted')).toHaveFocus());
    expect(dialog()).toHaveTextContent("PO-000001 is no longer a draft: it's ordered, so it can't be changed.");
    expect(dialog()).not.toHaveTextContent(P9);
    expect(within(dialog()).queryByRole('button', { name: 'Delete purchase order' })).toBeNull();
    // The dialog renders on its open state alone: the reading committed under it removed the page's own Delete.
    expect(screen.queryByRole('button', { name: 'Delete purchase order', hidden: true })).toBeNull();
    expect(screen.queryByRole('combobox', { hidden: true })).toBeNull();
    await u.click(inDialog('Close'));
    await waitFor(() => expect(calls).toHaveLength(5));
    expect(calls.map((c) => [c.method, c.url])).toEqual([
      ['DELETE', URL],
      ['GET', URL],
      ['GET', LINES_URL],
      ['GET', URL],
      ['GET', LINES_URL],
    ]);
    await waitFor(() => expect(h1()).toHaveFocus());
    expect(screen.getByText('Ordered', { selector: 'span' })).toBeInTheDocument();
  });

  it('P11 whose re-read fails: the dialog says UX D-2’s changed-since sentence; only Close; the page shows its region error', async () => {
    stubFetch([refused(NOT_DRAFT_RULE, P9), problem(503, 'x'), problem(503, 'x')]);
    const u = userEvent.setup();
    show();
    await confirmDelete(u);
    await waitFor(() => expect(within(dialog()).getByText('Not deleted')).toHaveFocus());
    expect(dialog()).toHaveTextContent('PO-000001 changed since this page was loaded, so this wasn\'t done. Reload the page to see where it stands.');
    expect(within(dialog()).queryByRole('button', { name: 'Delete purchase order' })).toBeNull();
    await u.click(inDialog('Close'));
    await waitFor(() => expect(screen.getByText(REREAD_FAILED)).toBeInTheDocument());
  });

  it('contention (P1): the sentence shows and Delete stays; pressing it again sends a second DELETE, which deletes', async () => {
    const calls = stubFetch([refused('transaction.contention', P1), empty(204), json(200, DELETED)]);
    const u = userEvent.setup();
    show();
    await confirmDelete(u);
    await waitFor(() => expect(within(dialog()).getByText('Not deleted')).toHaveFocus());
    expect(dialog()).toHaveTextContent(P1);
    await u.click(inDialog('Delete purchase order'));
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    expect(calls.map((c) => c.method)).toEqual(['DELETE', 'DELETE', 'GET']);
  });

  it('a 403 names "Delete and restore draft purchase orders" (B-12) and keeps Delete', async () => {
    stubFetch([problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    show();
    await confirmDelete(u);
    await waitFor(() => expect(within(dialog()).getByText('Not deleted')).toHaveFocus());
    expect(dialog()).toHaveTextContent('This needs the Delete and restore draft purchase orders permission, which you no longer have. Your workspace administrator can grant it.');
    expect(inDialog('Delete purchase order')).toBeInTheDocument();
  });

  it('interrupted: "We can’t tell whether PO-000001 was deleted…", Delete stays; Escape closes and re-reads; a re-read finding it deleted (the press committed) moves focus to the h1', async () => {
    const calls = stubFetch([problem(503, 'x'), json(200, DELETED)]);
    const u = userEvent.setup();
    show();
    await confirmDelete(u);
    await waitFor(() => expect(dialog()).toHaveTextContent(INTERRUPTED));
    expect(inDialog('Delete purchase order')).toBeInTheDocument();
    await u.keyboard('{Escape}');
    await waitFor(() => expect(banner()).toBeInTheDocument());
    expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET']);
    expect(h1()).toHaveFocus();
  });

  it('interrupted, then Escape: focus goes back to Delete purchase order; a re-read finding the order approved elsewhere removes it, so the h1 takes focus', async () => {
    // The re-read is held until the dialog has put focus back on its opener, so the order is deterministic (round 2: with
    // the re-read answered at once, the dialog's own fallback could reach the h1 first and mask the rule, M17 flaked).
    const reread = held();
    const calls = stubFetch([problem(503, 'x'), reread.reply, json(200, page([line(1), line(2)]))]);
    const u = userEvent.setup();
    show();
    await confirmDelete(u);
    await waitFor(() => expect(dialog()).toHaveTextContent(INTERRUPTED));
    await u.keyboard('{Escape}');
    await waitFor(() => expect(deleteOrder()).toHaveFocus());
    await act(async () => {
      reread.release(json(200, ORDERED));
      await new Promise((t) => setTimeout(t, 50));
    });
    await waitFor(() => expect(screen.getByText('Ordered', { selector: 'span' })).toBeInTheDocument());
    expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET', 'GET']);
    expect(screen.queryByRole('button', { name: 'Delete purchase order' })).toBeNull();
    await waitFor(() => expect(h1()).toHaveFocus());
  });

  it('a 404 leaves Close only; Close re-reads, and a 404 there is ST-5', async () => {
    stubFetch([problem(404, 'not-found'), problem(404, 'not-found')]);
    const u = userEvent.setup();
    show();
    await confirmDelete(u);
    await waitFor(() => expect(dialog()).toHaveTextContent("We couldn't find that. It may have been deleted, or you may not have access."));
    expect(within(dialog()).queryByRole('button', { name: 'Delete purchase order' })).toBeNull();
    await u.click(inDialog('Close'));
    await waitFor(() => expect(screen.getByRole('heading', { name: "We couldn't find that." })).toBeInTheDocument());
  });

  it('the press empties the region first, so a second identical "After" is given again (delete, restore, delete)', async () => {
    stubFetch([empty(204), json(200, DELETED), json(200, DRAFT), json(200, DRAFT), json(200, page([line(1), line(2)])), empty(204), json(200, DELETED)]);
    const u = userEvent.setup();
    show();
    const spoken = textsOf(region());
    await confirmDelete(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    await u.click(screen.getByRole('button', { name: 'Restore purchase order' }));
    await waitFor(() => expect(deleteOrder()).toBeInTheDocument());
    await confirmDelete(u);
    await waitFor(() => expect(banner()).toBeInTheDocument());
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    spoken.stop();
    const restoredAt = spoken.texts.findIndex((t) => t.startsWith('PO-000001 was restored'));
    expect(restoredAt).toBeGreaterThan(0);
    expect(spoken.texts.slice(restoredAt + 1)).toEqual(['', AFTER]);
  });

  // The 204 floor (items-i3-design.md Revision 1 — FINAL §0a; the warehouse page's): a Save whose reading was asked for
  // before the delete cannot turn the page live again, whether its re-read lands late or its own answer body is committed.
  it('an older Save re-read landing after the 204 does not turn the page live: the banner stays, no editor, no Delete', async () => {
    const saveReread = held();
    const calls = stubFetch([json(200, { ...DRAFT, warehouse: { id: W2.id, name: W2.name } }), saveReread.reply, empty(204), json(200, DELETED), json(200, page([line(1), line(2)]))]);
    const u = userEvent.setup();
    showEditor();
    const spoken = textsOf(region());
    await u.selectOptions(screen.getByRole('combobox', { name: 'Deliver to' }), W2.id);
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(calls).toHaveLength(2));
    await confirmDelete(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    await act(async () => {
      saveReread.release(json(200, { ...DRAFT, warehouse: { id: W2.id, name: W2.name } }));
      await new Promise((t) => setTimeout(t, 50));
    });
    // The Save's late re-read read a live draft, so its lines were read with it (one reading), and both were dropped.
    expect(calls.map((c) => [c.method, c.url])).toEqual([
      ['PATCH', URL],
      ['GET', URL],
      ['DELETE', URL],
      ['GET', URL],
      ['GET', LINES_URL],
    ]);
    expect(banner()).toBeInTheDocument();
    expect(screen.queryByRole('combobox')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Delete purchase order' })).toBeNull();
    // po-6-red.md M-1 (the items UX ruling M-3): the person's own Delete outranks their own Save — its "saved" is not spoken.
    spoken.stop();
    expect(region().textContent).toBe(AFTER);
    expect(spoken.texts).not.toContain(SAVED);
  });

  it("…and when the delete's own re-read fails: the older Save re-read landing after it does not bring the editor or Delete back (the 204 floor)", async () => {
    const saveReread = held();
    const calls = stubFetch([json(200, { ...DRAFT, warehouse: { id: W2.id, name: W2.name } }), saveReread.reply, empty(204), problem(503, 'x'), json(200, page([line(1), line(2)]))]);
    const u = userEvent.setup();
    showEditor();
    const spoken = textsOf(region());
    await u.selectOptions(screen.getByRole('combobox', { name: 'Deliver to' }), W2.id);
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(calls).toHaveLength(2));
    await confirmDelete(u);
    await waitFor(() => expect(screen.getByText(REREAD_FAILED)).toBeInTheDocument());
    await act(async () => {
      saveReread.release(json(200, { ...DRAFT, warehouse: { id: W2.id, name: W2.name } }));
      await new Promise((t) => setTimeout(t, 50));
    });
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET', 'DELETE', 'GET', 'GET']);
    expect(screen.queryByRole('combobox')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Delete purchase order' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Add line' })).toBeNull();
    expect(screen.getByText(REREAD_FAILED)).toBeInTheDocument();
    spoken.stop();
    expect(region().textContent).toBe(AFTER);
    expect(spoken.texts).not.toContain(SAVED);
  });

  // T1 (po-6-red.md "Spar on the :384 residual"; the items UX ruling L-1): nor does its failed re-read put the region error
  // over what the delete left — the page, focus and sentence are exactly as after any 204 of the person's own Delete.
  it('T1 — a Save answering 200 after the 204, whose re-read fails: its body is not committed and its failure is not shown; the h1 keeps focus, "After" only', async () => {
    const savePatch = held();
    const calls = stubFetch([savePatch.reply, empty(204), json(200, DELETED), problem(503, 'x'), json(200, page([line(1), line(2)]))]);
    const u = userEvent.setup();
    showEditor();
    const spoken = textsOf(region());
    await u.selectOptions(screen.getByRole('combobox', { name: 'Deliver to' }), W2.id);
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await confirmDelete(u);
    await waitFor(() => expect(banner()).toBeInTheDocument());
    await act(async () => {
      savePatch.release(json(200, { ...DRAFT, warehouse: { id: W2.id, name: W2.name } }));
      await new Promise((t) => setTimeout(t, 50));
    });
    await act(async () => {
      await new Promise((t) => setTimeout(t, 50));
    });
    // The Save's re-read failed, so its answer body was the fallback: its lines were read for it, and both were dropped.
    expect(calls.map((c) => [c.method, c.url])).toEqual([
      ['PATCH', URL],
      ['DELETE', URL],
      ['GET', URL],
      ['GET', URL],
      ['GET', LINES_URL],
    ]);
    spoken.stop();
    expect(screen.queryByText(REREAD_FAILED)).toBeNull();
    expect(h1()).toHaveFocus();
    expect(region().textContent).toBe(AFTER);
    expect(spoken.texts.filter((t) => t !== '')).toEqual([AFTER]);
    expect(banner()).toBeInTheDocument();
    expect(screen.queryByRole('combobox')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Delete purchase order' })).toBeNull();
  });

  it('T2 — a Save refused P8 after the 204, whose re-read fails: no "Not saved", no region error; the h1 keeps focus, "After" only', async () => {
    const savePatch = held();
    const calls = stubFetch([savePatch.reply, empty(204), json(200, DELETED), problem(503, 'x')]);
    const u = userEvent.setup();
    showEditor();
    const spoken = textsOf(region());
    await u.selectOptions(screen.getByRole('combobox', { name: 'Deliver to' }), W2.id);
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await confirmDelete(u);
    await waitFor(() => expect(banner()).toBeInTheDocument());
    await act(async () => {
      savePatch.release(refused('purchase_order.is_archived', P8));
      await new Promise((t) => setTimeout(t, 50));
    });
    await act(async () => {
      await new Promise((t) => setTimeout(t, 50));
    });
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'DELETE', 'GET', 'GET']);
    spoken.stop();
    expect(screen.queryByText(REREAD_FAILED)).toBeNull();
    expect(screen.queryByText('Not saved')).toBeNull();
    expect(h1()).toHaveFocus();
    expect(region().textContent).toBe(AFTER);
    expect(spoken.texts.filter((t) => t !== '')).toEqual([AFTER]);
    expect(banner()).toBeInTheDocument();
    expect(screen.queryByRole('combobox')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Delete purchase order' })).toBeNull();
  });

  it('C1 (control) — the delete’s own re-read fails too: exactly one region error (the delete’s), whose heading keeps focus after the Save’s late failure', async () => {
    const savePatch = held();
    stubFetch([savePatch.reply, empty(204), problem(503, 'x'), problem(503, 'x'), json(200, page([line(1), line(2)]))]);
    const u = userEvent.setup();
    showEditor();
    await u.selectOptions(screen.getByRole('combobox', { name: 'Deliver to' }), W2.id);
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await confirmDelete(u);
    await waitFor(() => expect(screen.getByText(REREAD_FAILED)).toBeInTheDocument());
    const errorHeading = () => screen.getByText(REREAD_FAILED).closest('section')!.querySelector('h2');
    await waitFor(() => expect(errorHeading()).toHaveFocus());
    await act(async () => {
      savePatch.release(json(200, { ...DRAFT, warehouse: { id: W2.id, name: W2.name } }));
      await new Promise((t) => setTimeout(t, 50));
    });
    await act(async () => {
      await new Promise((t) => setTimeout(t, 50));
    });
    expect(screen.getAllByText(REREAD_FAILED)).toHaveLength(1);
    expect(errorHeading()).toHaveFocus();
    expect(screen.queryByRole('combobox')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Delete purchase order' })).toBeNull();
  });

  it('C2 (control) — own delete, then Restore, then a Save sent after it whose re-read fails: the region error IS shown', async () => {
    stubFetch([empty(204), json(200, DELETED), json(200, DRAFT), json(200, DRAFT), json(200, page([line(1), line(2)])), json(200, { ...DRAFT, warehouse: { id: W2.id, name: W2.name } }), problem(503, 'x'), json(200, page([line(1), line(2)]))]);
    const u = userEvent.setup();
    showEditor();
    await confirmDelete(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    await u.click(screen.getByRole('button', { name: 'Restore purchase order' }));
    await waitFor(() => expect(screen.getByRole('combobox', { name: 'Deliver to' })).toBeInTheDocument());
    await u.selectOptions(screen.getByRole('combobox', { name: 'Deliver to' }), W2.id);
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(screen.getByText(REREAD_FAILED)).toBeInTheDocument());
  });

  it('C3 (control) — a Save answering 200 after the 204 whose re-read is answered 403: the denied state renders (the swap is kept)', async () => {
    const savePatch = held();
    stubFetch([savePatch.reply, empty(204), json(200, DELETED), problem(403, 'permission-denied'), json(200, page([line(1), line(2)]))]);
    const u = userEvent.setup();
    showEditor();
    await u.selectOptions(screen.getByRole('combobox', { name: 'Deliver to' }), W2.id);
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await confirmDelete(u);
    await waitFor(() => expect(banner()).toBeInTheDocument());
    await act(async () => {
      savePatch.release(json(200, { ...DRAFT, warehouse: { id: W2.id, name: W2.name } }));
      await new Promise((t) => setTimeout(t, 50));
    });
    await waitFor(() => expect(screen.getByText(/You don't have access to purchase orders/)).toBeInTheDocument());
  });

  // po-6-red.md M-1 (the items T-17 ruling, items-i4-design.md): a Save sent before the person's own Delete, refused P8
  // because the server took the DELETE first. Whenever that refusal settles — (a) before the 204 answers, (b) while the
  // delete's re-read is held, (c) after the dialog has closed — no Save refusal is shown or kept: the page, focus and
  // sentence are exactly as after any 204 of the person's own Delete. (d) is the control: a Save refused P8 after the
  // person's delete and a restore (deleted elsewhere) IS shown.
  const expectAsAfterOwnDelete = (spoken: { texts: string[] }) => {
    expect(screen.queryByText('Not saved')).toBeNull();
    expect(h1()).toHaveFocus();
    expect(region().textContent).toBe(AFTER);
    expect(spoken.texts.filter((t) => t === AFTER)).toHaveLength(1);
    expect(banner()).toBeInTheDocument();
  };

  it('T-17a — the Save’s P8 refusal settles before the 204: no "Not saved", the h1 focused, "After" once', async () => {
    const patch = held();
    const del = held();
    const calls = stubFetch([patch.reply, del.reply, json(200, DELETED), json(200, DELETED)]);
    const u = userEvent.setup();
    showEditor();
    const spoken = textsOf(region());
    await u.selectOptions(screen.getByRole('combobox', { name: 'Deliver to' }), W2.id);
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await confirmDelete(u);
    await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['PATCH', 'DELETE']));
    await act(async () => {
      patch.release(refused('purchase_order.is_archived', P8));
      await new Promise((t) => setTimeout(t, 50));
    });
    await act(async () => {
      del.release(empty(204));
      await new Promise((t) => setTimeout(t, 50));
    });
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'DELETE', 'GET', 'GET']);
    spoken.stop();
    expectAsAfterOwnDelete(spoken);
  });

  it('T-17b — the Save’s P8 refusal settles while the delete’s re-read is held: no "Not saved", the h1 focused, "After" once', async () => {
    const patch = held();
    const deleteReread = held();
    const calls = stubFetch([patch.reply, empty(204), deleteReread.reply, json(200, DELETED)]);
    const u = userEvent.setup();
    showEditor();
    const spoken = textsOf(region());
    await u.selectOptions(screen.getByRole('combobox', { name: 'Deliver to' }), W2.id);
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await confirmDelete(u);
    await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['PATCH', 'DELETE', 'GET']));
    await act(async () => {
      patch.release(refused('purchase_order.is_archived', P8));
      await new Promise((t) => setTimeout(t, 50));
    });
    await act(async () => {
      deleteReread.release(json(200, DELETED));
      await new Promise((t) => setTimeout(t, 50));
    });
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'DELETE', 'GET', 'GET']);
    spoken.stop();
    expectAsAfterOwnDelete(spoken);
  });

  // T-17c is po-6-red.md's PLANT-2, kept as a test.
  it('T-17c — the Save’s P8 refusal settles after the dialog has closed: no "Not saved", the h1 keeps focus, "After" once', async () => {
    const patch = held();
    const calls = stubFetch([patch.reply, empty(204), json(200, DELETED), json(200, DELETED)]);
    const u = userEvent.setup();
    showEditor();
    const spoken = textsOf(region());
    await u.selectOptions(screen.getByRole('combobox', { name: 'Deliver to' }), W2.id);
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await confirmDelete(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    await act(async () => {
      patch.release(refused('purchase_order.is_archived', P8));
      await new Promise((t) => setTimeout(t, 50));
    });
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'DELETE', 'GET', 'GET']);
    spoken.stop();
    expectAsAfterOwnDelete(spoken);
  });

  it('T-17d (control) — own delete, then Restore, then a Save refused P8 (deleted elsewhere): the "Not saved" banner IS shown and focused', async () => {
    const calls = stubFetch([empty(204), json(200, DELETED), json(200, DRAFT), json(200, DRAFT), json(200, page([line(1), line(2)])), refused('purchase_order.is_archived', P8), json(200, DELETED)]);
    const u = userEvent.setup();
    showEditor();
    await confirmDelete(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    await u.click(screen.getByRole('button', { name: 'Restore purchase order' }));
    await waitFor(() => expect(screen.getByRole('combobox', { name: 'Deliver to' })).toBeInTheDocument());
    await u.selectOptions(screen.getByRole('combobox', { name: 'Deliver to' }), W2.id);
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(screen.getByText('Not saved')).toHaveFocus());
    expect(screen.getByText(P8)).toBeInTheDocument();
    expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET', 'POST', 'GET', 'GET', 'PATCH', 'GET']);
  });

  // The brief's owed decision (po-6-progress.md D-3): Delete purchase order joins PO-5's one line write at a time.
  it('while an Add line is in flight, Delete purchase order opens nothing and sends nothing; once the Add has answered, it opens', async () => {
    const add = held();
    const NEW = { ...line(3), inventory_item: E7.inventory_item };
    const calls = stubFetch([add.reply, json(200, DRAFT), json(200, page([line(1), line(2), NEW]))]);
    const u = userEvent.setup();
    showEditor();
    await u.selectOptions(screen.getByRole('combobox', { name: 'Item, new line' }), E7.inventory_item.id);
    await u.type(screen.getByRole('textbox', { name: 'Quantity, new line' }), '2');
    await u.click(screen.getByRole('button', { name: 'Add line' }));
    await u.click(deleteOrder());
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(calls.map((c) => c.method)).toEqual(['POST']);
    add.release(json(201, NEW));
    await waitFor(() => expect(region()).toHaveTextContent('Line 3 added: BOLT-7.'));
    await u.click(deleteOrder());
    expect(within(dialog()).getByRole('heading', { name: TITLE })).toBeInTheDocument();
  });

  it('while a line Delete is in flight, Delete purchase order opens nothing; once it has answered, it opens', async () => {
    const del = held();
    const calls = stubFetch([del.reply, json(200, DRAFT), json(200, page([line(1)]))]);
    const u = userEvent.setup();
    showEditor();
    await u.click(screen.getByRole('button', { name: 'Delete line 2' }));
    await u.click(deleteOrder());
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(calls.map((c) => c.method)).toEqual(['DELETE']);
    del.release(empty(204));
    await waitFor(() => expect(region()).toHaveTextContent('Line 2 (SKU-2) was deleted.'));
    await u.click(deleteOrder());
    expect(within(dialog()).getByRole('heading', { name: TITLE })).toBeInTheDocument();
  });
});

describe('/purchase-orders/[id] — the PO-6 copy forms (UX R1a-1 row 6)', () => {
  it('a live draft: "This is a draft. It can be changed or deleted."; the editor: supplierFixed in its final form; nothing names approving', () => {
    const { container } = showEditor();
    expect(screen.getByText(DRAFT_NOTE, { selector: 'p' })).toBeInTheDocument();
    expect(screen.getByText(SUPPLIER_FIXED, { selector: 'p' })).toBeInTheDocument();
    expect(container.textContent).not.toMatch(/approv|until it's/i);
  });

  it('the draft note is the same for a viewer who cannot delete (passive, R-3)', () => {
    show({ canDelete: false });
    expect(screen.getByText(DRAFT_NOTE, { selector: 'p' })).toBeInTheDocument();
  });
});

// FE-D1 order (plan §4 PO-6: close → focus h1 → announce → refresh), plain and under StrictMode: a `focusin` listener
// records the DOM at the moment the h1 takes focus — no dialog in the document and the "After" sentence not yet written.
for (const strict of [false, true]) {
  const wrap = (ui: ReactElement) => (strict ? <StrictMode>{ui}</StrictMode> : ui);
  const suffix = strict ? ' (StrictMode)' : '';
  describe(`Delete purchase order — FE-D1 order${suffix}`, () => {
    it('204: the dialog closes, the h1 takes focus, THEN "After" is written; one refresh, after the focus', async () => {
      stubFetch([empty(204), json(200, DELETED)]);
      const u = userEvent.setup();
      const order: string[] = [];
      nav.refresh.mockImplementation(() => order.push('refresh'));
      renderWired(wrap(<PurchaseOrderPage order={DRAFT} lines={LINES} viewerId={VIEWER_ID} canRestore canReadLines canOpenSupplier={false} canOpenWarehouse={false} canOpenItem={false} canDelete />));
      await u.click(deleteOrder());
      const atFocus: { text: boolean; dialog: boolean }[] = [];
      const onFocusIn = (e: FocusEvent) => {
        if ((e.target as HTMLElement).tagName === 'H1') {
          order.push('focus');
          atFocus.push({ text: (document.body.textContent ?? '').includes(AFTER), dialog: document.querySelector('[role="dialog"]') !== null });
        }
      };
      document.addEventListener('focusin', onFocusIn);
      await u.click(inDialog('Delete purchase order'));
      await waitFor(() => expect(region()).toHaveTextContent(AFTER));
      document.removeEventListener('focusin', onFocusIn);
      expect(atFocus[0]).toEqual({ text: false, dialog: false });
      expect(h1()).toHaveFocus();
      expect(order[0]).toBe('focus');
      expect(order.filter((o) => o === 'refresh')).toEqual(['refresh']);
      expect(order.indexOf('refresh')).toBeGreaterThan(0);
      nav.refresh.mockReset();
    });

    it('409 → Close → re-read: the refusal heading had focus; after Close the re-read ran once and focus is back on Delete purchase order (still a live draft)', async () => {
      const calls = stubFetch([refused('purchase_order.is_archived', P10), json(200, DRAFT), json(200, page([line(1), line(2)]))]);
      const u = userEvent.setup();
      renderWired(wrap(<PurchaseOrderPage order={DRAFT} lines={LINES} viewerId={VIEWER_ID} canRestore canReadLines canOpenSupplier={false} canOpenWarehouse={false} canOpenItem={false} canDelete />));
      await confirmDelete(u);
      await waitFor(() => expect(within(dialog()).getByText('Not deleted')).toHaveFocus());
      await u.click(inDialog('Close'));
      await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET', 'GET']));
      await new Promise((r) => setTimeout(r, 50));
      expect(calls).toHaveLength(3);
      expect(deleteOrder()).toHaveFocus();
    });
  });
}

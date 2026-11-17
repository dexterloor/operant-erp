import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: vi.fn(), refresh: nav.refresh }), usePathname: () => '/purchase-orders/x' }));
import { PurchaseOrderPage, type PurchaseOrderPageProps } from '@/app/(app)/purchase-orders/[id]/PurchaseOrderPage';
import type { PurchaseOrder, PurchaseOrderLine } from '@/lib/api/purchasing';
import { formatDate } from '@/lib/format/dates';
import { TZ, VIEWER_ID, held, json, page, problem, refused, renderWired, stubFetch, textsOf } from './harness';

// Purchase orders PO-2 — Restore a deleted draft (purchase-orders-plan.md Revision 0 §4 PO-2; final RED apply list PO-2;
// UX B-13 `RESTORE_ORDER`, R1a-1 row 2; plan §1.4 P12–P17). The page is the real `PurchaseOrderPage` with the shared
// `RestoreActions` in its deleted-draft banner; Restore goes through the REAL `lib/purchasing/wired.ts`, `load.ts` and the
// browser transport. A restore re-reads the order and, for a ListPurchaseOrderLines holder, the order's live lines, and
// commits both at once: the page turns live without a remount and shows the lines that came back (po-2-progress.md D-1:
// a deleted draft's page still shows no lines, L-5).

afterEach(() => {
  vi.unstubAllGlobals();
  nav.refresh.mockClear();
});

const OID = 'd1b2c3d4-0000-4000-8000-000000000001';
const AT = '2026-03-04T11:00:00Z';
const LIVE: PurchaseOrder = {
  id: OID,
  order_number: 'PO-000001',
  status: 'draft',
  supplier: { id: 'c1b2c3d4-0000-4000-8000-0000000000a1', name: 'Acme Supplies' },
  warehouse: { id: 'c1b2c3d4-0000-4000-8000-0000000000b1', name: 'Main warehouse' },
  currency_code: 'GBP',
  total_amount: '25.0000',
  ordered_at: null,
  expected_at: null,
  created_by: { id: 'e1b2c3d4-0000-4000-8000-000000000001', display_name: 'Bea Buyer' },
  archived_at: null,
};
const DELETED: PurchaseOrder = { ...LIVE, archived_at: AT };
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
const show = (over: Partial<PurchaseOrderPageProps> = {}) =>
  renderWired(<PurchaseOrderPage order={DELETED} lines={null} viewerId={VIEWER_ID} canRestore canReadLines canOpenSupplier={false} canOpenWarehouse={false} canOpenItem={false} {...over} />);
const region = () => document.querySelector<HTMLElement>('section > [role="status"]')!;
const restore = () => screen.getByRole('button', { name: 'Restore purchase order' });
const banner = () => screen.queryByText(/This draft was deleted on/);
const linesHeading = () => screen.queryByRole('heading', { level: 2, name: 'Lines' });
const AFTER = 'PO-000001 was restored as a draft. Lines that were deleted on their own before it was deleted stay deleted.';
const REREAD_FAILED = "We couldn't load this purchase order again, so what's shown may be out of date.";
/** P13 / P14 (`PurchaseOrderWriter::RESTORE_WAREHOUSE_ARCHIVED` / `RESTORE_SUPPLIER_ARCHIVED`). */
const P13 = "This order's warehouse is archived, so the order can't be restored. It can be restored once its warehouse is restored.";
const P14 = "This order's supplier is archived, so the order can't be restored. It can be restored once its supplier is restored.";
/** P17 (`RESTORE_TOTAL_TOO_LARGE`). */
const P17 = 'Restoring this order would bring back lines whose total is larger than an order can hold, so it cannot be restored.';
const P1 = 'Some of the same records were being changed at the same moment. Nothing was saved; try again.';
const LINES_URL = `/bff/api/purchase-orders/${OID}/lines`;

describe('/purchase-orders/[id] — a deleted draft, and Restore purchase order (UX B-13, ST-9)', () => {
  it('the ST-9 banner with its date and Restore purchase order for a RestorePurchaseOrder holder; no button without it', () => {
    const { unmount } = show();
    expect(banner()).toHaveTextContent(`This draft was deleted on ${formatDate(AT, TZ)}.`);
    expect(restore()).toBeInTheDocument();
    expect(screen.getAllByRole('button')).toHaveLength(1);
    unmount();
    show({ canRestore: false });
    expect(banner()).toBeInTheDocument();
    expect(screen.queryByRole('button')).toBeNull();
  });

  it('a live draft never offers Restore purchase order, whatever the viewer holds (nor does any other status)', () => {
    for (const status of ['draft', 'ordered', 'partially_received', 'received', 'cancelled']) {
      const { container, unmount } = show({ order: { ...LIVE, status }, lines: { kind: 'ok', lines: [line(1)], nextCursor: null } });
      expect(container.querySelectorAll('button')).toHaveLength(0);
      unmount();
    }
  });

  it('restored: POST …/restoration with no body and no retry key, re-reads the order and its lines, says so, focuses the h1, turns live without a remount', async () => {
    const calls = stubFetch([json(200, LIVE), json(200, { ...LIVE, total_amount: '50.0000' }), json(200, page([line(1), line(2)]))]);
    const u = userEvent.setup();
    show();
    const h1 = screen.getByRole('heading', { level: 1 });
    expect(linesHeading()).toBeNull();
    await u.click(restore());
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    expect(calls.map((c) => [c.method, c.url.replace(/\?.*$/, '')])).toEqual([
      ['POST', `/bff/api/purchase-orders/${OID}/restoration`],
      ['GET', `/bff/api/purchase-orders/${OID}`],
      ['GET', LINES_URL],
    ]);
    expect(calls[0]!.body ?? null).toBeNull();
    expect(calls[0]!.headers.get('Idempotency-Key')).toBeNull();
    // The live lines, never the deleted ones: the lines read sends no `archived`.
    expect(calls[2]!.url).toBe(LINES_URL);
    expect(screen.getByRole('heading', { level: 1 })).toBe(h1);
    await waitFor(() => expect(h1).toHaveFocus());
    expect(banner()).toBeNull();
    expect(screen.queryByRole('button', { name: 'Restore purchase order' })).toBeNull();
    // PO-6 form of the draft note (R1a-1 row 6; moved from row 4's at PO-6).
    expect(screen.getByText('This is a draft. It can be changed or deleted.', { selector: 'p' })).toBeInTheDocument();
    // The re-read order (its total), and the lines that came back.
    expect(screen.getByText('50.00 GBP')).toBeInTheDocument();
    expect(linesHeading()).toBeInTheDocument();
    expect(screen.getAllByRole('row')).toHaveLength(3);
    expect(nav.refresh).toHaveBeenCalledTimes(1);
  });

  it('restored, and the lines come back empty: the draft’s empty sentence, never a stale table', async () => {
    stubFetch([json(200, LIVE), json(200, LIVE), json(200, page([]))]);
    const u = userEvent.setup();
    show();
    await u.click(restore());
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    expect(screen.getByText('This draft has no lines yet.', { selector: 'p' })).toBeInTheDocument();
  });

  it('restored, without ListPurchaseOrderLines: no lines read and no lines section', async () => {
    const calls = stubFetch([json(200, LIVE), json(200, LIVE)]);
    const u = userEvent.setup();
    show({ canReadLines: false });
    await u.click(restore());
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    expect(calls.map((c) => c.method)).toEqual(['POST', 'GET']);
    expect(linesHeading()).toBeNull();
  });

  it('restored, but the lines read fails: the section’s own region error, and its Retry reads them', async () => {
    const calls = stubFetch([json(200, LIVE), json(200, LIVE), json(500, {}), json(200, page([line(1)]))]);
    const u = userEvent.setup();
    show();
    await u.click(restore());
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    expect(screen.getByText("This order's lines couldn't be loaded.")).toBeInTheDocument();
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(region()).toHaveTextContent('1 line.'));
    expect(calls.map((c) => c.url)).toEqual([`/bff/api/purchase-orders/${OID}/restoration`, `/bff/api/purchase-orders/${OID}`, LINES_URL, LINES_URL]);
  });

  it('refused (P12), and the re-read finds it live: the page turns live with its lines and says "PO-000001 was already restored."', async () => {
    const calls = stubFetch([refused('purchase_order.is_not_archived', 'This purchase order is not archived, so there is nothing to restore.'), json(200, LIVE), json(200, page([line(1)]))]);
    const u = userEvent.setup();
    show();
    await u.click(restore());
    await waitFor(() => expect(region()).toHaveTextContent('PO-000001 was already restored.'));
    expect(region()).not.toHaveTextContent('nothing to restore');
    expect(banner()).toBeNull();
    expect(screen.queryByRole('button', { name: 'Restore purchase order' })).toBeNull();
    expect(calls.map((c) => c.method)).toEqual(['POST', 'GET', 'GET']);
    expect(linesHeading()).toBeInTheDocument();
    expect(nav.refresh).toHaveBeenCalledTimes(1);
  });

  it.each([
    ['P13 warehouse archived', 'purchase_order.warehouse_is_live', P13],
    ['P14 supplier archived', 'purchase_order.supplier_is_live', P14],
    ['P17 total too large', 'purchase_order.total_would_exceed_the_limit_on_restore', P17],
  ])('refused (%s), and the re-read still reads deleted: the server’s sentence under "Not restored", focused, the button stays, no lines read', async (_name, rule, message) => {
    const calls = stubFetch([refused(rule, message), json(200, DELETED)]);
    const u = userEvent.setup();
    show();
    await u.click(restore());
    expect(await screen.findByText(message)).toBeInTheDocument();
    await waitFor(() => expect(screen.getByText('Not restored')).toHaveFocus());
    expect(restore()).toBeInTheDocument();
    expect(banner()).toBeInTheDocument();
    expect(calls.map((c) => c.method)).toEqual(['POST', 'GET']);
    expect(linesHeading()).toBeNull();
    expect(nav.refresh).not.toHaveBeenCalled();
  });

  it('refused (P13) and its re-read fails; restored elsewhere meanwhile: the region error’s Retry finds it live and reads its lines', async () => {
    const calls = stubFetch([refused('purchase_order.warehouse_is_live', P13), json(500, {}), json(200, LIVE), json(200, page([line(1), line(2)]))]);
    const u = userEvent.setup();
    show();
    await u.click(restore());
    expect(await screen.findByText(REREAD_FAILED)).toBeInTheDocument();
    expect(banner()).toBeInTheDocument();
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(banner()).toBeNull());
    expect(calls.map((c) => [c.method, c.url])).toEqual([
      ['POST', `/bff/api/purchase-orders/${OID}/restoration`],
      ['GET', `/bff/api/purchase-orders/${OID}`],
      ['GET', `/bff/api/purchase-orders/${OID}`],
      ['GET', LINES_URL],
    ]);
    expect(linesHeading()).toBeInTheDocument();
    expect(screen.getAllByRole('row')).toHaveLength(3);
    expect(screen.queryByText(REREAD_FAILED)).toBeNull();
  });

  // po-2-red.md M-1: after a refused restore whose re-read failed, Retry and Restore are both on screen, so their reads can
  // overlap. The stamp rule keeps Retry's older "deleted" reading, answered late, from undoing the restore.
  it('Retry held, Restore succeeds, then Retry’s late "deleted" answer arrives: the page stays live (the stamp rule)', async () => {
    const retryRead = held();
    const calls = stubFetch([refused('purchase_order.warehouse_is_live', P13), json(500, {}), retryRead.reply, json(200, LIVE), json(200, LIVE), json(200, page([line(1)]))]);
    const u = userEvent.setup();
    show();
    await u.click(restore());
    expect(await screen.findByText(REREAD_FAILED)).toBeInTheDocument();
    expect(restore()).toBeInTheDocument();
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(calls).toHaveLength(3));
    await u.click(restore());
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    retryRead.release(json(200, DELETED));
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Retry' })).toBeNull());
    await new Promise((r) => setTimeout(r, 20));
    expect(banner()).toBeNull();
    expect(screen.queryByRole('button', { name: 'Restore purchase order' })).toBeNull();
    expect(linesHeading()).toBeInTheDocument();
    expect(region()).toHaveTextContent(AFTER);
    expect(calls.map((c) => c.method)).toEqual(['POST', 'GET', 'GET', 'POST', 'GET', 'GET']);
  });

  it('P1 contention, then a second press succeeds: Restore purchase order is kept after "try again"', async () => {
    stubFetch([refused('transaction.contention', P1), json(200, DELETED), json(200, LIVE), json(200, LIVE), json(200, page([]))]);
    const u = userEvent.setup();
    show();
    await u.click(restore());
    expect(await screen.findByText(P1)).toBeInTheDocument();
    await u.click(restore());
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    expect(screen.queryByRole('button', { name: 'Restore purchase order' })).toBeNull();
  });

  it('a 403 names "Delete and restore draft purchase orders"; offline says "Press Restore purchase order to try again."', async () => {
    stubFetch([problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    const { unmount } = show();
    await u.click(restore());
    expect(await screen.findByText(/which you no longer have/)).toHaveTextContent('This needs the Delete and restore draft purchase orders permission, which you no longer have. Your workspace administrator can grant it.');
    unmount();
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => {
        throw new TypeError('offline');
      }),
    );
    show();
    await u.click(restore());
    expect(await screen.findByText("We can't tell whether PO-000001 was restored. Press Restore purchase order to try again.")).toBeInTheDocument();
    expect(restore()).toBeInTheDocument();
  });

  it('restored (200) but the order re-read fails: the 200 body is committed, never "restored" over the deleted banner; the region error’s Retry re-reads the order', async () => {
    const calls = stubFetch([json(200, LIVE), json(500, {}), json(200, page([line(1)])), json(200, { ...LIVE, total_amount: '75.0000' })]);
    const u = userEvent.setup();
    show();
    await u.click(restore());
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    expect(banner()).toBeNull();
    expect(await screen.findByText(REREAD_FAILED)).toBeInTheDocument();
    // The lines were still read under the restore's deadline: the order is live by the server's own answer.
    expect(linesHeading()).toBeInTheDocument();
    expect(screen.getByText('25.00 GBP')).toBeInTheDocument();
    const retries = screen.getAllByRole('button', { name: 'Retry' });
    expect(retries).toHaveLength(1);
    await u.click(retries[0]!);
    await waitFor(() => expect(screen.getByText('75.00 GBP')).toBeInTheDocument());
    expect(screen.queryByText(REREAD_FAILED)).toBeNull();
    expect(calls.map((c) => c.method)).toEqual(['POST', 'GET', 'GET', 'GET']);
  });

  it('a re-read after a restore that finds the order deleted again turns the page back: banner, Restore, no lines section', async () => {
    stubFetch([json(200, LIVE), json(500, {}), json(200, page([line(1)])), json(200, DELETED)]);
    const u = userEvent.setup();
    show();
    await u.click(restore());
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    await u.click(await screen.findByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(banner()).toBeInTheDocument());
    expect(restore()).toBeInTheDocument();
    expect(linesHeading()).toBeNull();
  });

  it('a re-read that finds the order gone (404) shows ST-5; a 403 shows ST-4b', async () => {
    stubFetch([json(200, LIVE), json(500, {}), json(200, page([])), problem(404, 'not-found')]);
    const u = userEvent.setup();
    const { unmount } = show();
    await u.click(restore());
    await u.click(await screen.findByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(screen.getByRole('link', { name: 'Back to the list' })).toHaveAttribute('href', '/purchase-orders'));
    unmount();
    vi.unstubAllGlobals();
    stubFetch([json(200, LIVE), json(500, {}), json(200, page([])), problem(403, 'permission-denied')]);
    show();
    await u.click(restore());
    await u.click(await screen.findByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(screen.getByText(/You don't have access to purchase orders/)).toBeInTheDocument());
  });

  // FE-R9 (the shared Restore press empties the region first): a repeat of the same sentence is given again.
  it('Restore (its re-read fails), a deletion made elsewhere learned through Retry, Restore again: spoken both times', async () => {
    stubFetch([json(200, LIVE), json(500, {}), json(200, page([])), json(200, DELETED), json(200, LIVE), json(200, LIVE), json(200, page([]))]);
    const u = userEvent.setup();
    show();
    const rec = textsOf(region());
    await u.click(restore());
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    await u.click(await screen.findByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(banner()).toBeInTheDocument());
    await u.click(restore());
    await waitFor(() => expect(banner()).toBeNull());
    await waitFor(() => expect(rec.texts).toEqual(['', AFTER, '', AFTER]));
    rec.stop();
  });
});

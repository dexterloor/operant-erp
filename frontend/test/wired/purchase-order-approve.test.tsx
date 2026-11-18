import { StrictMode, type ReactElement } from 'react';
import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: vi.fn(), refresh: nav.refresh }), usePathname: () => '/purchase-orders/x' }));
import { leaveArrivalMessage } from '@/app/(app)/arrivalMessage';
import { PurchaseOrderPage, type PurchaseOrderPageProps } from '@/app/(app)/purchase-orders/[id]/PurchaseOrderPage';
import type { PurchaseOrder, PurchaseOrderLine } from '@/lib/api/purchasing';
import type { CatalogueChoicesLoad, LinesLoad, WarehouseChoice, WarehouseChoicesLoad } from '@/lib/purchasing/load';
import { VIEWER_ID, empty, held, json, page, problem, refused, renderWired, stubFetch, textsOf } from './harness';

// Purchase orders PO-8 — Approve a draft purchase order (purchase-orders-final-red.md apply list PO-8 and L-6; plan Revision 1
// R1-1 row 9 / R1-5 PO-8 over Revision 0 §2.5 and §4 PO-8; UX R1a-1 row 9 over B-6 `APPROVE_ORDER` and Rev 1a `summary`). The
// page is the real `PurchaseOrderPage` with the new `components/purchasing/PlaceOrderDialog`; Approve goes through the REAL
// `lib/purchasing/wired.ts`, `load.ts` and the browser transport. A1 (DSoD-1, `purchase_order.approver_is_not_the_creator`),
// A2 (the status guard, `purchase_order.status_transition_is_legal`), A3 (`purchase_order.has_a_live_line`) and A6
// (`purchase_order.is_archived`) are `PurchaseOrderApproval.php` / `PurchaseOrder::place()`'s. No expected date (D-4 unruled).

afterEach(() => {
  vi.unstubAllGlobals();
  nav.refresh.mockClear();
});

const OID = 'd1b2c3d4-0000-4000-8000-000000000001';
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
  expected_at: '2026-04-01T00:00:00Z',
  created_by: { id: 'e1b2c3d4-0000-4000-8000-000000000001', display_name: 'Bea Buyer' },
  archived_at: null,
};
const ORDERED: PurchaseOrder = { ...DRAFT, status: 'ordered', ordered_at: '2026-03-05T09:00:00Z' };
const PARTLY: PurchaseOrder = { ...ORDERED, status: 'partially_received' };
const RECEIVED: PurchaseOrder = { ...ORDERED, status: 'received' };
const CANCELLED: PurchaseOrder = { ...DRAFT, status: 'cancelled' };
const DELETED: PurchaseOrder = { ...DRAFT, archived_at: '2026-03-04T11:00:00Z' };
const line = (n: number): PurchaseOrderLine => ({
  id: `f1b2c3d4-0000-4000-8000-${String(n).padStart(12, '0')}`,
  line_number: n,
  inventory_item: { id: `a1b2c3d4-0000-4000-8000-${String(n).padStart(12, '0')}`, sku: `SKU-${n}`, name: `Item ${n}` },
  quantity: '2.000000',
  quantity_received: '0.000000',
  quantity_outstanding: '2.000000',
  unit_cost: '12.5000',
  archived_at: null,
  unit_of_measure_code: 'EA',
});
const LINES: LinesLoad = { kind: 'ok', lines: [line(1), line(2)], nextCursor: null };
const LINES_REPLY = () => json(200, page([line(1), line(2)]));
const EDIT: { warehouses: WarehouseChoicesLoad } = { warehouses: { kind: 'ok', warehouses: [W1, W2] } };
const ADD: { catalogue: CatalogueChoicesLoad } = { catalogue: { kind: 'ok', entries: [] } };
const EMPTY_CATALOGUE = "Acme Supplies's catalogue lists no items, so no lines can be added yet. Items can be added to it on the supplier's page.";

const show = (over: Partial<PurchaseOrderPageProps> = {}) =>
  renderWired(<PurchaseOrderPage order={DRAFT} lines={LINES} viewerId={VIEWER_ID} canRestore canReadLines canOpenSupplier={false} canOpenWarehouse={false} canOpenItem={false} canApprove {...over} />);

const URL = `/bff/api/purchase-orders/${OID}`;
const APPROVE_URL = `${URL}/approval`;
const LINES_URL = `${URL}/lines`;
const region = () => document.querySelector<HTMLElement>('section > [role="status"]')!;
const dialog = () => screen.getByRole('dialog');
const approve = () => screen.getByRole('button', { name: 'Approve' });
const inDialog = (name: string) => within(dialog()).getByRole('button', { name });
const h1 = () => screen.getByRole('heading', { level: 1 });
const badge = (label: string) => screen.queryByText(label, { selector: 'span' });

const TITLE = 'Approve PO-000001?';
/** UX Rev 1a `summary(supplier, total)` (two lines) and R1a-1 row 9's `body` (no receive clause: Receive ships at GR-2). */
const SUPPLIER_LINE = 'Supplier: Acme Supplies';
const TOTAL_LINE = 'Total: 25.00 GBP';
/** M-1 (a) (po-8-m1-m2-recommendation.md Revision 1 — FINAL; PLACEHOLDER, UX TO CONFIRM): where the order goes. */
const WAREHOUSE_LINE = 'Deliver to: Main warehouse';
const UNSAVED = 'Save or undo your change to Deliver to before approving.';
const TOTAL_CHANGED = 'It was approved with a total of 99.00 GBP: its lines changed after this page last read them.';
const WAREHOUSE_CHANGED = 'It was approved for delivery to East depot: its delivery warehouse changed after this page last read it.';
const AFTER_HEAD = 'PO-000001 is approved and now ordered.';
const AFTER_TAIL = "Remember to send the order to Acme Supplies: the app doesn't.";
const BODY = "Once it's approved it can't be changed or deleted, only cancelled. You're recorded as the person who approved it. The app doesn't send it to Acme Supplies: send it to them yourself.";
/** R1a-1 row 9's `after` (no receive clause) + B-6 `afterSupplier` (second sentence, always). */
const AFTER = 'PO-000001 is approved and now ordered. Remember to send the order to Acme Supplies: the app doesn\'t.';
const ALREADY = 'PO-000001 was already approved.';
const INTERRUPTED = "We can't tell whether PO-000001 was approved. Press Approve to try again.";
const CHANGED = "PO-000001 changed since this page was loaded, so this wasn't done. Reload the page to see where it stands.";
const NO_LONGER_CANCELLED = "PO-000001 is no longer a draft: it's cancelled, so it can't be changed.";
const REREAD_FAILED = "We couldn't load this purchase order again, so what's shown may be out of date.";
/** `draftNote` final form without its third (owner) sentence — SEC has not confirmed it (final RED L-6). */
const DRAFT_NOTE = "This is a draft. It can be changed or deleted until it's approved.";
const OWNER_SENTENCE = "It has to be approved by someone other than the person who raised it, unless that person is an owner.";
const ORDERED_NOTE = "This order has been approved, so it can't be changed. It can still be cancelled.";
/** A1 (`PurchaseOrderDutySeparation.php:112`), A2 (`IllegalTransition`, developer words until BK-34), A3, A6, P1. */
const A1_RULE = 'purchase_order.approver_is_not_the_creator';
const A1 = 'You raised this order. It needs a different approver.';
const A2_RULE = 'purchase_order.status_transition_is_legal';
const A2 = 'PurchaseOrder::place() is legal only from "draft", and this purchase_order is "ordered".';
const A3 = 'This purchase order has no lines. Add at least one line before ordering it.';
const A6 = "This purchase order is archived, so it can't be placed.";
const P1 = 'Some of the same records were being changed at the same moment. Nothing was saved; try again.';
const NOT_DRAFT_RULE = 'purchase_order.is_editable_only_while_draft';
const P9 = 'This purchase order has been placed, so it is read-only except for its transitions.';

/** Opens the dialog and presses its Approve. */
async function confirmApprove(u: ReturnType<typeof userEvent.setup>) {
  await u.click(approve());
  await u.click(inDialog('Approve'));
}

describe('/purchase-orders/[id] — Approve (PO-8; UX B-6)', () => {
  it('offered on a live draft to an ApprovePurchaseOrder holder only: never without the code, on a deleted draft, an ordered, partly received, received or cancelled order', () => {
    const a = show({ canApprove: false });
    expect(screen.queryByRole('button', { name: 'Approve' })).toBeNull();
    a.unmount();
    for (const order of [DELETED, ORDERED, PARTLY, RECEIVED, CANCELLED]) {
      const { unmount } = show({ order, lines: order.archived_at === null ? LINES : null });
      expect(screen.queryByRole('button', { name: 'Approve' })).toBeNull();
      unmount();
    }
    const { unmount } = show();
    expect(approve()).toBeInTheDocument();
    unmount();
    // A draft with no lines is offered Approve too (A3 is said after the press, never predicted).
    show({ lines: { kind: 'ok', lines: [], nextCursor: null } });
    expect(approve()).toBeInTheDocument();
  });

  it('rendered and enabled for the person who raised the order (ST-4f third arm: A1 is said after the press, never pre-empted)', () => {
    show({ order: { ...DRAFT, created_by: { id: VIEWER_ID, display_name: 'Me Myself' } } });
    expect(screen.getByText('Me Myself (you)')).toBeInTheDocument();
    expect(approve()).not.toHaveAttribute('aria-disabled');
    expect(approve()).toBeEnabled();
  });

  it('the dialog: "Approve {number}?", the summary (supplier, delivery warehouse, total) and the PO-8 body, no field (no expected date, D-4), focus on Go back; Go back sends nothing', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    show();
    await u.click(approve());
    expect(within(dialog()).getByRole('heading', { name: TITLE })).toBeInTheDocument();
    expect(dialog()).toHaveAccessibleDescription(`${SUPPLIER_LINE} ${WAREHOUSE_LINE} ${TOTAL_LINE} ${BODY}`);
    expect(within(dialog()).getByText(WAREHOUSE_LINE, { selector: 'p' })).toBeInTheDocument();
    expect(within(dialog()).getByText(SUPPLIER_LINE, { selector: 'p' })).toBeInTheDocument();
    expect(within(dialog()).getByText(TOTAL_LINE, { selector: 'p' })).toBeInTheDocument();
    expect(within(dialog()).queryByRole('textbox')).toBeNull();
    expect(dialog().querySelector('input')).toBeNull();
    expect(within(dialog()).getAllByRole('button').map((b) => b.textContent)).toEqual(['Go back', 'Approve']);
    await waitFor(() => expect(inDialog('Go back')).toHaveFocus());
    await u.click(inDialog('Go back'));
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(calls).toEqual([]);
    expect(approve()).toHaveFocus();
  });

  it('the dialog body and the after-sentence have no receive clause (R1-2: Receive ships at GR-2)', async () => {
    stubFetch([json(200, ORDERED), json(200, ORDERED), LINES_REPLY()]);
    const u = userEvent.setup();
    show();
    const spoken = textsOf(region());
    await u.click(approve());
    expect(dialog().textContent).not.toMatch(/deliveries|receiv/i);
    await u.click(inDialog('Approve'));
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    spoken.stop();
    for (const t of spoken.texts) expect(t).not.toMatch(/deliveries|receiv/i);
  });

  it('a 200: POST {} with no key; the re-read (order + lines) turns the page Ordered in the same mount — the draft’s inputs gone; focus h1; "After" spoken once; one refresh', async () => {
    const calls = stubFetch([json(200, ORDERED), json(200, ORDERED), LINES_REPLY()]);
    const u = userEvent.setup();
    show({ edit: EDIT, add: ADD, canDeleteLine: true, canDelete: true, canCancel: true });
    const heading = h1();
    expect(screen.getByRole('combobox', { name: 'Deliver to' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Delete line 1' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Delete purchase order' })).toBeInTheDocument();
    expect(screen.getByText(EMPTY_CATALOGUE)).toBeInTheDocument();
    const spoken = textsOf(region());
    await confirmApprove(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    spoken.stop();
    expect(calls.map((c) => [c.method, c.url])).toEqual([
      ['POST', APPROVE_URL],
      ['GET', URL],
      ['GET', LINES_URL],
    ]);
    // D-4 unruled: always `{}` (the order's expected date is never sent).
    expect(calls[0]!.body).toBe('{}');
    expect(calls[0]!.headers.get('idempotency-key')).toBeNull();
    expect(screen.queryByRole('dialog')).toBeNull();
    // The same mount: the h1 is the element it was.
    expect(h1()).toBe(heading);
    expect(h1()).toHaveFocus();
    expect(badge('Ordered')).toBeInTheDocument();
    expect(screen.queryByRole('combobox')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Save' })).toBeNull();
    expect(screen.queryByRole('button', { name: /^Delete line/ })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Delete purchase order' })).toBeNull();
    expect(screen.queryByText(EMPTY_CATALOGUE)).toBeNull();
    expect(screen.queryByRole('button', { name: 'Approve' })).toBeNull();
    expect(screen.queryByText(DRAFT_NOTE)).toBeNull();
    expect(screen.getByText(ORDERED_NOTE, { selector: 'p' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Cancel order' })).toBeInTheDocument();
    expect(spoken.texts.filter((t) => t !== '')).toEqual([AFTER]);
    expect(nav.refresh).toHaveBeenCalledTimes(1);
  });

  it('after an on-page approval the Receipts section stays absent until reload (ARCH 3c): no section, no receipts request', async () => {
    const calls = stubFetch([json(200, ORDERED), json(200, ORDERED), LINES_REPLY()]);
    const u = userEvent.setup();
    show({ canOpenReceipt: true });
    await confirmApprove(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    expect(screen.queryByRole('heading', { name: 'Receipts for PO-000001' })).toBeNull();
    expect(calls.some((c) => c.url.includes('goods-receipts'))).toBe(false);
  });

  it('a 200 whose re-read fails: the 200 body is shown (Ordered, no Approve), "After" is still spoken, and the region error offers Retry', async () => {
    const calls = stubFetch([json(200, ORDERED), problem(503, 'x'), LINES_REPLY()]);
    const u = userEvent.setup();
    show({ edit: EDIT });
    await confirmApprove(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    await waitFor(() => expect(screen.getByText(REREAD_FAILED)).toBeInTheDocument());
    expect(calls.map((c) => [c.method, c.url])).toEqual([
      ['POST', APPROVE_URL],
      ['GET', URL],
      ['GET', LINES_URL],
    ]);
    expect(badge('Ordered')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Approve' })).toBeNull();
    expect(screen.queryByRole('combobox')).toBeNull();
  });

  it('A1 (DSoD-1, the person who raised it): shown after the press — "Not approved" + the server sentence in the dialog, heading focused; only Go back; Go back re-reads and focus returns to Approve', async () => {
    const mine = { ...DRAFT, created_by: { id: VIEWER_ID, display_name: 'Me Myself' } };
    const calls = stubFetch([refused(A1_RULE, A1), json(200, mine), LINES_REPLY(), json(200, mine), LINES_REPLY()]);
    const u = userEvent.setup();
    show({ order: mine });
    await confirmApprove(u);
    await waitFor(() => expect(within(dialog()).getByText('Not approved')).toHaveFocus());
    expect(dialog()).toHaveTextContent(A1);
    expect(within(dialog()).queryByRole('button', { name: 'Approve' })).toBeNull();
    expect(within(dialog()).getAllByRole('button').map((b) => b.textContent)).toEqual(['Go back']);
    // L-1: A1 is followed by the stamped re-read (it still reads a draft, so A1 is said as sent).
    expect(calls.map((c) => c.method)).toEqual(['POST', 'GET', 'GET']);
    await u.click(inDialog('Go back'));
    await waitFor(() => expect(calls).toHaveLength(5));
    expect(calls.map((c) => [c.method, c.url])).toEqual([
      ['POST', APPROVE_URL],
      ['GET', URL],
      ['GET', LINES_URL],
      ['GET', URL],
      ['GET', LINES_URL],
    ]);
    await waitFor(() => expect(approve()).toHaveFocus());
    expect(region().textContent).toBe('');
  });

  it('A3 (no live line): the server sentence in the dialog, the dialog stays, only Go back', async () => {
    const calls = stubFetch([refused('purchase_order.has_a_live_line', A3)]);
    const u = userEvent.setup();
    show({ lines: { kind: 'ok', lines: [], nextCursor: null } });
    await confirmApprove(u);
    await waitFor(() => expect(within(dialog()).getByText('Not approved')).toHaveFocus());
    expect(dialog()).toHaveTextContent(A3);
    expect(within(dialog()).getByRole('heading', { name: TITLE })).toBeInTheDocument();
    expect(within(dialog()).getAllByRole('button').map((b) => b.textContent)).toEqual(['Go back']);
    expect(calls.map((c) => c.method)).toEqual(['POST']);
  });

  it('A1 whose re-read reads the order approved elsewhere (po-8-red.md L-1): UX D-2’s "already approved", not "It needs a different approver"; the page turns Ordered under it', async () => {
    stubFetch([refused(A1_RULE, A1), json(200, { ...ORDERED, created_by: { id: VIEWER_ID, display_name: 'Me Myself' } }), LINES_REPLY()]);
    const u = userEvent.setup();
    show({ order: { ...DRAFT, created_by: { id: VIEWER_ID, display_name: 'Me Myself' } } });
    await confirmApprove(u);
    await waitFor(() => expect(within(dialog()).getByText('Not approved')).toHaveFocus());
    expect(dialog()).toHaveTextContent(ALREADY);
    expect(dialog()).not.toHaveTextContent(A1);
    expect(screen.getByText('Ordered', { selector: 'span' })).toBeInTheDocument();
  });

  it('A1 whose re-read reads the draft cancelled elsewhere: UX D-2’s "no longer a draft"', async () => {
    stubFetch([refused(A1_RULE, A1), json(200, CANCELLED), LINES_REPLY()]);
    const u = userEvent.setup();
    show({ order: { ...DRAFT, created_by: { id: VIEWER_ID, display_name: 'Me Myself' } } });
    await confirmApprove(u);
    await waitFor(() => expect(within(dialog()).getByText('Not approved')).toHaveFocus());
    expect(dialog()).toHaveTextContent(NO_LONGER_CANCELLED);
    expect(dialog()).not.toHaveTextContent(A1);
  });

  it('A1 whose re-read fails: A1 as sent (still true), only Go back; the page shows its region error', async () => {
    stubFetch([refused(A1_RULE, A1), problem(503, 'x'), problem(503, 'x')]);
    const u = userEvent.setup();
    show({ order: { ...DRAFT, created_by: { id: VIEWER_ID, display_name: 'Me Myself' } } });
    await confirmApprove(u);
    await waitFor(() => expect(within(dialog()).getByText('Not approved')).toHaveFocus());
    expect(dialog()).toHaveTextContent(A1);
    expect(dialog()).not.toHaveTextContent(CHANGED);
    await u.click(inDialog('Go back'));
    await waitFor(() => expect(screen.getByText(REREAD_FAILED)).toBeInTheDocument());
  });

  it('A2, re-read ordered (approved elsewhere): UX D-2’s "already approved", never the server’s words; only Go back; the page under it turns Ordered; Go back re-reads, focus on the h1, one refresh', async () => {
    const calls = stubFetch([refused(A2_RULE, A2), json(200, ORDERED), LINES_REPLY(), json(200, ORDERED), LINES_REPLY()]);
    const u = userEvent.setup();
    show({ edit: EDIT });
    await confirmApprove(u);
    await waitFor(() => expect(within(dialog()).getByText('Not approved')).toHaveFocus());
    expect(dialog()).toHaveTextContent(ALREADY);
    expect(dialog()).not.toHaveTextContent(A2);
    expect(within(dialog()).queryByRole('button', { name: 'Approve' })).toBeNull();
    expect(screen.getByText('Ordered', { selector: 'span' })).toBeInTheDocument();
    expect(screen.queryByRole('combobox', { hidden: true })).toBeNull();
    await u.click(inDialog('Go back'));
    await waitFor(() => expect(calls).toHaveLength(5));
    expect(calls.map((c) => [c.method, c.url])).toEqual([
      ['POST', APPROVE_URL],
      ['GET', URL],
      ['GET', LINES_URL],
      ['GET', URL],
      ['GET', LINES_URL],
    ]);
    await waitFor(() => expect(h1()).toHaveFocus());
    expect(nav.refresh).toHaveBeenCalledTimes(1);
  });

  it('A2, re-read partly received or received in full: also "already approved"', async () => {
    for (const o of [PARTLY, RECEIVED]) {
      stubFetch([refused(A2_RULE, A2), json(200, o), LINES_REPLY()]);
      const u = userEvent.setup();
      const { unmount } = show();
      await confirmApprove(u);
      await waitFor(() => expect(within(dialog()).getByText('Not approved')).toHaveFocus());
      expect(dialog()).toHaveTextContent(ALREADY);
      unmount();
      vi.unstubAllGlobals();
    }
  });

  it('A2, re-read cancelled (a draft cancelled elsewhere): UX D-2’s "no longer a draft" from the re-read, never the server’s words', async () => {
    stubFetch([refused(A2_RULE, A2), json(200, CANCELLED), LINES_REPLY()]);
    const u = userEvent.setup();
    show();
    await confirmApprove(u);
    await waitFor(() => expect(within(dialog()).getByText('Not approved')).toHaveFocus());
    expect(dialog()).toHaveTextContent(NO_LONGER_CANCELLED);
    expect(dialog()).not.toHaveTextContent(A2);
    expect(screen.getByText('Cancelled', { selector: 'span' })).toBeInTheDocument();
  });

  it('A2 whose re-read fails: UX D-2’s changed-since sentence; only Go back; the page shows its region error', async () => {
    stubFetch([refused(A2_RULE, A2), problem(503, 'x'), problem(503, 'x')]);
    const u = userEvent.setup();
    show();
    await confirmApprove(u);
    await waitFor(() => expect(within(dialog()).getByText('Not approved')).toHaveFocus());
    expect(dialog()).toHaveTextContent(CHANGED);
    expect(within(dialog()).queryByRole('button', { name: 'Approve' })).toBeNull();
    await u.click(inDialog('Go back'));
    await waitFor(() => expect(screen.getByText(REREAD_FAILED)).toBeInTheDocument());
  });

  it('A6 (deleted elsewhere): the server sentence as sent, only Go back; Go back re-reads and the page shows the deleted banner', async () => {
    stubFetch([refused('purchase_order.is_archived', A6), json(200, DELETED)]);
    const u = userEvent.setup();
    show();
    await confirmApprove(u);
    await waitFor(() => expect(within(dialog()).getByText('Not approved')).toHaveFocus());
    expect(dialog()).toHaveTextContent(A6);
    expect(within(dialog()).getAllByRole('button').map((b) => b.textContent)).toEqual(['Go back']);
    await u.click(inDialog('Go back'));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Restore purchase order' })).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: 'Approve' })).toBeNull();
    await waitFor(() => expect(h1()).toHaveFocus());
  });

  it('contention (P1): the sentence shows and Approve stays; pressing it again sends a second POST, which approves', async () => {
    const calls = stubFetch([refused('transaction.contention', P1), json(200, ORDERED), json(200, ORDERED), LINES_REPLY()]);
    const u = userEvent.setup();
    show();
    await confirmApprove(u);
    await waitFor(() => expect(within(dialog()).getByText('Not approved')).toHaveFocus());
    expect(dialog()).toHaveTextContent(P1);
    expect(inDialog('Go back')).toBeInTheDocument();
    await u.click(inDialog('Approve'));
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    expect(calls.map((c) => c.method)).toEqual(['POST', 'POST', 'GET', 'GET']);
  });

  it('a 403 names "Approve and place purchase orders" (B-12) and keeps Approve', async () => {
    stubFetch([problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    show();
    await confirmApprove(u);
    await waitFor(() => expect(within(dialog()).getByText('Not approved')).toHaveFocus());
    expect(dialog()).toHaveTextContent('This needs the Approve and place purchase orders permission, which you no longer have. Your workspace administrator can grant it.');
    expect(inDialog('Approve')).toBeInTheDocument();
  });

  it('interrupted: "We can’t tell whether PO-000001 was approved…", Approve stays; Escape closes and re-reads; a re-read finding it ordered (the press committed) moves focus to the h1', async () => {
    const calls = stubFetch([problem(503, 'x'), json(200, ORDERED), LINES_REPLY()]);
    const u = userEvent.setup();
    show();
    await confirmApprove(u);
    await waitFor(() => expect(dialog()).toHaveTextContent(INTERRUPTED));
    expect(within(dialog()).getByText('Something went wrong on our side.')).toHaveFocus();
    expect(inDialog('Approve')).toBeInTheDocument();
    await u.keyboard('{Escape}');
    await waitFor(() => expect(badge('Ordered')).toBeInTheDocument());
    expect(calls.map((c) => c.method)).toEqual(['POST', 'GET', 'GET']);
    await waitFor(() => expect(h1()).toHaveFocus());
  });

  it('interrupted, then Escape: focus goes back to Approve; a re-read finding the order approved removes it, so the h1 takes focus (held re-read: no timer can mask it)', async () => {
    const reread = held();
    const calls = stubFetch([problem(503, 'x'), reread.reply, LINES_REPLY()]);
    const u = userEvent.setup();
    show();
    await confirmApprove(u);
    await waitFor(() => expect(dialog()).toHaveTextContent(INTERRUPTED));
    await u.keyboard('{Escape}');
    await waitFor(() => expect(approve()).toHaveFocus());
    await act(async () => {
      reread.release(json(200, ORDERED));
      await new Promise((t) => setTimeout(t, 50));
    });
    await waitFor(() => expect(badge('Ordered')).toBeInTheDocument());
    expect(calls.map((c) => c.method)).toEqual(['POST', 'GET', 'GET']);
    expect(screen.queryByRole('button', { name: 'Approve' })).toBeNull();
    await waitFor(() => expect(h1()).toHaveFocus());
  });

  it('a 404 leaves Go back only; Go back re-reads, and a 404 there is ST-5', async () => {
    stubFetch([problem(404, 'not-found'), problem(404, 'not-found')]);
    const u = userEvent.setup();
    show();
    await confirmApprove(u);
    await waitFor(() => expect(dialog()).toHaveTextContent("We couldn't find that. It may have been deleted, or you may not have access."));
    expect(within(dialog()).queryByRole('button', { name: 'Approve' })).toBeNull();
    await u.click(inDialog('Go back'));
    await waitFor(() => expect(screen.getByRole('heading', { name: "We couldn't find that." })).toBeInTheDocument());
  });

  it('while the request runs the dialog is busy: no Go back, Escape does nothing; the press empties the region first (FE-R9)', async () => {
    const post = held();
    leaveArrivalMessage(`/purchase-orders/${OID}`, 'Earlier sentence.');
    stubFetch([post.reply, json(200, ORDERED), LINES_REPLY()]);
    const u = userEvent.setup();
    show();
    await waitFor(() => expect(region()).toHaveTextContent('Earlier sentence.'));
    await confirmApprove(u);
    expect(region().textContent).toBe('');
    expect(within(dialog()).queryByRole('button', { name: 'Go back' })).toBeNull();
    await u.keyboard('{Escape}');
    expect(dialog()).toBeInTheDocument();
    await act(async () => {
      post.release(json(200, ORDERED));
      await new Promise((t) => setTimeout(t, 50));
    });
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
  });

  it('the dialog keeps the summary it opened with: an order approved elsewhere with a new total (A2) still reads the opened total', async () => {
    stubFetch([refused(A2_RULE, A2), json(200, { ...ORDERED, total_amount: '99.0000' }), LINES_REPLY()]);
    const u = userEvent.setup();
    show();
    await confirmApprove(u);
    await waitFor(() => expect(within(dialog()).getByText('Not approved')).toHaveFocus());
    expect(screen.getByText('99.00 GBP')).toBeInTheDocument();
    expect(within(dialog()).getByText(TOTAL_LINE, { selector: 'p' })).toBeInTheDocument();
  });

  it('an older page Retry answering after the Approve’s re-read is dropped (the stamp rule): the page stays Ordered', async () => {
    const retry = held();
    // The region error comes from an A2 whose two re-reads fail; the order is still a draft on screen. The Retry is held,
    // Approve is pressed meanwhile and answers first; the Retry's older reading (a draft) lands last.
    const calls = stubFetch([refused(A2_RULE, A2), problem(503, 'x'), problem(503, 'x'), retry.reply, json(200, ORDERED), json(200, ORDERED), LINES_REPLY()]);
    const u = userEvent.setup();
    show({ edit: EDIT });
    await confirmApprove(u);
    await waitFor(() => expect(dialog()).toHaveTextContent(CHANGED));
    await u.click(inDialog('Go back'));
    await waitFor(() => expect(screen.getByText(REREAD_FAILED)).toBeInTheDocument());
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(calls).toHaveLength(4));
    await confirmApprove(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    await act(async () => {
      retry.release(json(200, DRAFT));
      await new Promise((t) => setTimeout(t, 50));
    });
    expect(badge('Ordered')).toBeInTheDocument();
    expect(screen.queryByRole('combobox')).toBeNull();
    expect(h1()).toHaveFocus();
  });

  it('a page Retry answering after the Approve’s 200 but before its re-read is dropped too: the floor is raised at the 200, so the region error stays until the approval’s own reading lands (po-7-red.md L-1)', async () => {
    const retry = held();
    const reread = held();
    const calls = stubFetch([refused(A2_RULE, A2), problem(503, 'x'), problem(503, 'x'), retry.reply, json(200, ORDERED), reread.reply, LINES_REPLY()]);
    const u = userEvent.setup();
    show({ edit: EDIT });
    await confirmApprove(u);
    await waitFor(() => expect(dialog()).toHaveTextContent(CHANGED));
    await u.click(inDialog('Go back'));
    await waitFor(() => expect(screen.getByText(REREAD_FAILED)).toBeInTheDocument());
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(calls).toHaveLength(4));
    await confirmApprove(u);
    await waitFor(() => expect(calls).toHaveLength(6));
    await act(async () => {
      retry.release(json(200, DRAFT));
      await new Promise((t) => setTimeout(t, 50));
    });
    // The Retry's reading (asked for before the approval answered) is not committed: the error it would clear stays.
    expect(screen.getByText(REREAD_FAILED)).toBeInTheDocument();
    await act(async () => {
      reread.release(json(200, ORDERED));
      await new Promise((t) => setTimeout(t, 50));
    });
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    expect(badge('Ordered')).toBeInTheDocument();
    expect(h1()).toHaveFocus();
  });
});

// The brief's carry 6, as corrected by po-8-red.md H-1: an approval commits whatever the order holds when the server places
// it, so a header Save's outcome is part of what is approved. One write at a time: while a header Save (or a line write) is
// in flight, Approve opens nothing; a Save's refusal is shown before the dialog can open, and stays.
describe('/purchase-orders/[id] — Approve against a write still in flight from the draft', () => {
  it('while a header Save is in flight, Approve opens nothing and sends nothing; once it has answered, it opens', async () => {
    const patch = held();
    const calls = stubFetch([patch.reply, json(200, { ...DRAFT, warehouse: { id: W2.id, name: W2.name } }), LINES_REPLY()]);
    const u = userEvent.setup();
    show({ edit: EDIT });
    await u.selectOptions(screen.getByRole('combobox', { name: 'Deliver to' }), W2.id);
    await u.click(screen.getByRole('button', { name: 'Save' }));
    // Silent (H-1): while the Save is in flight Approve carries no reason; the press just opens nothing.
    expect(approve()).not.toHaveAttribute('aria-disabled');
    await u.click(approve());
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(calls.map((c) => c.method)).toEqual(['PATCH']);
    await act(async () => {
      patch.release(json(200, { ...DRAFT, warehouse: { id: W2.id, name: W2.name } }));
      await new Promise((t) => setTimeout(t, 50));
    });
    await waitFor(() => expect(region()).toHaveTextContent('PO-000001 was saved.'));
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET', 'GET']);
    await u.click(approve());
    expect(within(dialog()).getByRole('heading', { name: TITLE })).toBeInTheDocument();
  });

  it('a refused Save then Approve: the Save’s "Not saved" banner was shown and focused, and it stays; the unsaved choice gives Approve its reason (M-1 (b)), and once undone the dialog opens with the banner still under it', async () => {
    // P1 is shown as sent after the Save's re-read finds the order still an editable draft (`orderSave`).
    const calls = stubFetch([refused('transaction.contention', P1), json(200, DRAFT)]);
    const u = userEvent.setup();
    show({ edit: EDIT });
    await u.selectOptions(screen.getByRole('combobox', { name: 'Deliver to' }), W2.id);
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(screen.getByText('Not saved')).toHaveFocus());
    expect(screen.getByText(P1)).toBeInTheDocument();
    // The change is still unsaved: Approve says why it is unavailable, and a press opens nothing.
    expect(approve()).toHaveAttribute('aria-disabled', 'true');
    expect(approve()).toHaveAccessibleDescription(UNSAVED);
    await u.click(approve());
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(screen.getByText('Not saved')).toBeInTheDocument();
    await u.selectOptions(screen.getByRole('combobox', { name: 'Deliver to' }), W1.id);
    await u.click(approve());
    expect(within(dialog()).getByRole('heading', { name: TITLE })).toBeInTheDocument();
    expect(screen.getByText('Not saved')).toBeInTheDocument();
    expect(screen.getByText(P1)).toBeInTheDocument();
    await u.click(inDialog('Go back'));
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(screen.getByText(P1)).toBeInTheDocument();
    await waitFor(() => expect(approve()).toHaveFocus());
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET']);
  });

  it('(control) a Save refused P9 with no Approve pressed: the page’s stale "Not saved" banner is shown', async () => {
    stubFetch([refused(NOT_DRAFT_RULE, P9), json(200, ORDERED), LINES_REPLY()]);
    const u = userEvent.setup();
    show({ edit: EDIT });
    await u.selectOptions(screen.getByRole('combobox', { name: 'Deliver to' }), W2.id);
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(screen.getByText('Not saved')).toHaveFocus());
  });

  it('while a line Delete is in flight, Approve opens nothing and sends nothing; once it has answered, it opens', async () => {
    const del = held();
    const calls = stubFetch([del.reply, json(200, DRAFT), json(200, page([line(1)]))]);
    const u = userEvent.setup();
    show({ canDeleteLine: true });
    await u.click(screen.getByRole('button', { name: 'Delete line 2' }));
    await u.click(approve());
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(calls.map((c) => c.method)).toEqual(['DELETE']);
    await act(async () => {
      del.release(empty());
      await new Promise((t) => setTimeout(t, 50));
    });
    await waitFor(() => expect(calls).toHaveLength(3));
    await u.click(approve());
    expect(within(dialog()).getByRole('heading', { name: TITLE })).toBeInTheDocument();
  });
});

// po-8-m1-m2-recommendation.md Revision 1 — FINAL. M-1 (b): an unsaved Deliver to gives Approve a visible reason. M-2: a 200
// whose total or warehouse differs (raw served strings) from what the dialog opened with says what was approved.
describe('/purchase-orders/[id] — Approve and what the approval carries (M-1 / M-2)', () => {
  it('an unsaved Deliver to: Approve carries the reason and opens nothing; undoing the choice (back to the saved warehouse) makes it clean again', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    show({ edit: EDIT });
    expect(approve()).not.toHaveAttribute('aria-disabled');
    await u.selectOptions(screen.getByRole('combobox', { name: 'Deliver to' }), W2.id);
    expect(approve()).toHaveAttribute('aria-disabled', 'true');
    expect(approve()).toHaveAccessibleDescription(UNSAVED);
    await u.click(approve());
    expect(screen.queryByRole('dialog')).toBeNull();
    await u.selectOptions(screen.getByRole('combobox', { name: 'Deliver to' }), W1.id);
    expect(approve()).not.toHaveAttribute('aria-disabled');
    expect(screen.queryByText(UNSAVED)).toBeNull();
    await u.click(approve());
    expect(within(dialog()).getByRole('heading', { name: TITLE })).toBeInTheDocument();
    expect(calls).toEqual([]);
  });

  it('a Save that commits the change clears the reason: Approve opens, and its dialog names the new warehouse', async () => {
    const saved = { ...DRAFT, warehouse: { id: W2.id, name: W2.name } };
    stubFetch([json(200, saved), json(200, saved), LINES_REPLY()]);
    const u = userEvent.setup();
    show({ edit: EDIT });
    await u.selectOptions(screen.getByRole('combobox', { name: 'Deliver to' }), W2.id);
    expect(approve()).toHaveAttribute('aria-disabled', 'true');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(region()).toHaveTextContent('PO-000001 was saved.'));
    expect(approve()).not.toHaveAttribute('aria-disabled');
    await u.click(approve());
    expect(within(dialog()).getByText('Deliver to: East depot', { selector: 'p' })).toBeInTheDocument();
  });

  it('no editor (a viewer who may approve but not edit): Approve never carries the reason', () => {
    show();
    expect(approve()).not.toHaveAttribute('aria-disabled');
  });

  it('a 200 whose body has a different total from the one the dialog opened with: "after" + totalChanged + "afterSupplier", in one message', async () => {
    const body = { ...ORDERED, total_amount: '99.0000' };
    stubFetch([json(200, body), json(200, body), LINES_REPLY()]);
    const u = userEvent.setup();
    show();
    const spoken = textsOf(region());
    await confirmApprove(u);
    await waitFor(() => expect(region()).toHaveTextContent(TOTAL_CHANGED));
    spoken.stop();
    expect(spoken.texts.filter((t) => t !== '')).toEqual([`${AFTER_HEAD} ${TOTAL_CHANGED} ${AFTER_TAIL}`]);
  });

  it('a 200 whose body has a different warehouse: "after" + warehouseChanged + "afterSupplier"; both differences: both sentences, total first', async () => {
    const moved = { ...ORDERED, warehouse: { id: W2.id, name: W2.name } };
    stubFetch([json(200, moved), json(200, moved), LINES_REPLY()]);
    const u = userEvent.setup();
    const a = show();
    await confirmApprove(u);
    await waitFor(() => expect(region()).toHaveTextContent(WAREHOUSE_CHANGED));
    expect(region().textContent).toBe(`${AFTER_HEAD} ${WAREHOUSE_CHANGED} ${AFTER_TAIL}`);
    a.unmount();
    vi.unstubAllGlobals();
    const both = { ...moved, total_amount: '99.0000' };
    stubFetch([json(200, both), json(200, both), LINES_REPLY()]);
    show();
    await confirmApprove(u);
    await waitFor(() => expect(region()).toHaveTextContent(WAREHOUSE_CHANGED));
    expect(region().textContent).toBe(`${AFTER_HEAD} ${TOTAL_CHANGED} ${WAREHOUSE_CHANGED} ${AFTER_TAIL}`);
  });

  it('the comparison uses what the dialog OPENED with: a newer reading committed under the open dialog (total 99) does not move it; a 200 with total 99 still says it', async () => {
    const retry = held();
    const changed = { ...DRAFT, total_amount: '99.0000' };
    const body = { ...ORDERED, total_amount: '99.0000' };
    const calls = stubFetch([refused(A2_RULE, A2), problem(503, 'x'), problem(503, 'x'), retry.reply, json(200, body), json(200, body), LINES_REPLY()]);
    const u = userEvent.setup();
    show();
    await confirmApprove(u);
    await waitFor(() => expect(dialog()).toHaveTextContent(CHANGED));
    await u.click(inDialog('Go back'));
    await waitFor(() => expect(screen.getByText(REREAD_FAILED)).toBeInTheDocument());
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(calls).toHaveLength(4));
    await u.click(approve());
    await act(async () => {
      retry.release(json(200, changed));
      await new Promise((t) => setTimeout(t, 50));
    });
    expect(screen.getByText('99.00 GBP', { selector: 'dd' })).toBeInTheDocument();
    expect(within(dialog()).getByText(TOTAL_LINE, { selector: 'p' })).toBeInTheDocument();
    await u.click(inDialog('Approve'));
    await waitFor(() => expect(region()).toHaveTextContent(TOTAL_CHANGED));
    expect(region().textContent).toBe(`${AFTER_HEAD} ${TOTAL_CHANGED} ${AFTER_TAIL}`);
  });

  it('the comparison uses the 200 body only: a re-read that differs from an unchanged body adds nothing; a body that differs whose re-read fails still says it', async () => {
    stubFetch([json(200, ORDERED), json(200, { ...ORDERED, total_amount: '99.0000' }), LINES_REPLY()]);
    const u = userEvent.setup();
    const a = show();
    await confirmApprove(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER_HEAD));
    expect(region().textContent).toBe(AFTER);
    a.unmount();
    vi.unstubAllGlobals();
    const body = { ...ORDERED, total_amount: '99.0000' };
    stubFetch([json(200, body), problem(503, 'x'), LINES_REPLY()]);
    show();
    await confirmApprove(u);
    await waitFor(() => expect(region()).toHaveTextContent(TOTAL_CHANGED));
    expect(region().textContent).toBe(`${AFTER_HEAD} ${TOTAL_CHANGED} ${AFTER_TAIL}`);
  });
});

describe('/purchase-orders/[id] — the PO-8 copy forms (UX R1a-1 row 9; final RED L-6)', () => {
  it('a live draft: "draftNote" in its final form WITHOUT the owner sentence (SEC has not confirmed it), for a viewer who may approve and one who may not (passive, R-3)', () => {
    for (const canApprove of [true, false]) {
      const { container, unmount } = show({ canApprove });
      expect(screen.getByText(DRAFT_NOTE, { selector: 'p' })).toBeInTheDocument();
      expect(container.textContent).not.toContain(OWNER_SENTENCE);
      expect(container.textContent).not.toContain('someone other than');
      unmount();
    }
  });
});

// FE-D1 order (plan §4 PO-8: 200 → re-read, Ordered, focus h1), plain and under StrictMode: a `focusin` listener records the
// DOM at the moment the h1 takes focus — no dialog in the document and the "After" sentence not yet written.
for (const strict of [false, true]) {
  const wrap = (ui: ReactElement) => (strict ? <StrictMode>{ui}</StrictMode> : ui);
  const suffix = strict ? ' (StrictMode)' : '';
  describe(`Approve — FE-D1 order${suffix}`, () => {
    it('200: the dialog closes, the h1 takes focus, THEN "After" is written; one refresh, after the focus', async () => {
      stubFetch([json(200, ORDERED), json(200, ORDERED), LINES_REPLY()]);
      const u = userEvent.setup();
      const order: string[] = [];
      nav.refresh.mockImplementation(() => order.push('refresh'));
      renderWired(wrap(<PurchaseOrderPage order={DRAFT} lines={LINES} viewerId={VIEWER_ID} canRestore canReadLines canOpenSupplier={false} canOpenWarehouse={false} canOpenItem={false} canApprove />));
      await u.click(approve());
      const atFocus: { text: boolean; dialog: boolean }[] = [];
      const onFocusIn = (e: FocusEvent) => {
        if ((e.target as HTMLElement).tagName === 'H1') {
          order.push('focus');
          atFocus.push({ text: (document.body.textContent ?? '').includes(AFTER), dialog: document.querySelector('[role="dialog"]') !== null });
        }
      };
      document.addEventListener('focusin', onFocusIn);
      await u.click(inDialog('Approve'));
      await waitFor(() => expect(region()).toHaveTextContent(AFTER));
      document.removeEventListener('focusin', onFocusIn);
      expect(atFocus[0]).toEqual({ text: false, dialog: false });
      expect(h1()).toHaveFocus();
      expect(order[0]).toBe('focus');
      expect(order.filter((o) => o === 'refresh')).toEqual(['refresh']);
      expect(order.indexOf('refresh')).toBeGreaterThan(0);
      nav.refresh.mockReset();
    });

    it('A1 → its re-read → Go back → re-read: the refusal heading had focus; after Go back the re-read ran once more and focus is back on Approve (still a draft)', async () => {
      const calls = stubFetch([refused(A1_RULE, A1), json(200, DRAFT), LINES_REPLY(), json(200, DRAFT), LINES_REPLY()]);
      const u = userEvent.setup();
      renderWired(wrap(<PurchaseOrderPage order={DRAFT} lines={LINES} viewerId={VIEWER_ID} canRestore canReadLines canOpenSupplier={false} canOpenWarehouse={false} canOpenItem={false} canApprove />));
      await confirmApprove(u);
      await waitFor(() => expect(within(dialog()).getByText('Not approved')).toHaveFocus());
      expect(calls.map((c) => c.method)).toEqual(['POST', 'GET', 'GET']);
      await u.click(inDialog('Go back'));
      await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['POST', 'GET', 'GET', 'GET', 'GET']));
      await new Promise((r) => setTimeout(r, 50));
      expect(calls).toHaveLength(5);
      expect(approve()).toHaveFocus();
    });
  });
}

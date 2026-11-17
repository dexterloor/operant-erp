import { StrictMode, type ReactElement } from 'react';
import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: vi.fn(), refresh: nav.refresh }), usePathname: () => '/purchase-orders/x' }));
import { leaveArrivalMessage } from '@/app/(app)/arrivalMessage';
import { PurchaseOrderPage, type PurchaseOrderPageProps } from '@/app/(app)/purchase-orders/[id]/PurchaseOrderPage';
import type { PurchaseOrder, PurchaseOrderLine } from '@/lib/api/purchasing';
import type { LinesLoad, WarehouseChoice, WarehouseChoicesLoad } from '@/lib/purchasing/load';
import { VIEWER_ID, held, json, page, problem, refused, renderWired, stubFetch, textsOf } from './harness';

// Purchase orders PO-7 — Cancel an ordered or partly received purchase order (purchase-orders-final-red.md apply list PO-7;
// plan Revision 1 R1-1 row 7 / R1-5 "dismiss Keep order" over Revision 0 §2.5 and §4 PO-7; UX R1a-1 row 7 over B-7
// `CANCEL_ORDER`, Rev 1a "single line"). The page is the real `PurchaseOrderPage` with the new
// `components/purchasing/CancelOrderDialog`; Cancel goes through the REAL `lib/purchasing/wired.ts`, `load.ts` and the
// browser transport. C1 (DSoD-1, `purchase_order.canceller_is_not_the_creator`), C2 (the status guard,
// `purchase_order.status_transition_is_legal`) and C3 (`purchase_order.is_archived`) are `PurchaseOrderCancellation.php`'s.

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
  expected_at: null,
  created_by: { id: 'e1b2c3d4-0000-4000-8000-000000000001', display_name: 'Bea Buyer' },
  archived_at: null,
};
const ORDERED: PurchaseOrder = { ...DRAFT, status: 'ordered', ordered_at: '2026-03-05T09:00:00Z' };
const PARTLY: PurchaseOrder = { ...ORDERED, status: 'partially_received' };
const CANCELLED: PurchaseOrder = { ...ORDERED, status: 'cancelled' };
const RECEIVED: PurchaseOrder = { ...ORDERED, status: 'received' };
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
const LINES_REPLY = () => json(200, page([line(1), line(2)]));
const EDIT: { warehouses: WarehouseChoicesLoad } = { warehouses: { kind: 'ok', warehouses: [W1, W2] } };

const show = (over: Partial<PurchaseOrderPageProps> = {}) =>
  renderWired(<PurchaseOrderPage order={ORDERED} lines={LINES} viewerId={VIEWER_ID} canRestore canReadLines canOpenSupplier={false} canOpenWarehouse={false} canOpenItem={false} canCancel {...over} />);

const URL = `/bff/api/purchase-orders/${OID}`;
const CANCEL_URL = `${URL}/cancellation`;
const LINES_URL = `${URL}/lines`;
const region = () => document.querySelector<HTMLElement>('section > [role="status"]')!;
const dialog = () => screen.getByRole('dialog');
const cancelOrder = () => screen.getByRole('button', { name: 'Cancel order' });
const inDialog = (name: string) => within(dialog()).getByRole('button', { name });
const reason = () => within(dialog()).getByRole('textbox', { name: 'Reason (optional)' });
const h1 = () => screen.getByRole('heading', { level: 1 });
const badge = (label: string) => screen.queryByText(label, { selector: 'span' });

const TITLE = 'Cancel PO-000001?';
const BODY_ORDERED = "Nothing more will be expected on it, and it can't be reopened. The app doesn't tell Acme Supplies: let them know yourself.";
const BODY_PARTLY = "Goods already received stay in stock, and their receipts stay as they are. Nothing more will be expected on it, and it can't be reopened. The app doesn't tell Acme Supplies: let them know yourself.";
const HINT = 'Kept with the record of the cancellation. Nothing in the app shows it yet.';
const AFTER = 'PO-000001 was cancelled. Nothing more is expected on it.';
const AFTER_PARTLY = 'PO-000001 was cancelled. Goods already received stay in stock.';
const ALREADY_CANCELLED = 'PO-000001 was already cancelled.';
const ALREADY_RECEIVED = "PO-000001 was received in full meanwhile, so it can't be cancelled.";
const INTERRUPTED = "We can't tell whether PO-000001 was cancelled. Press Cancel order to try again.";
const CHANGED = "PO-000001 changed since this page was loaded, so this wasn't done. Reload the page to see where it stands.";
const REREAD_FAILED = "We couldn't load this purchase order again, so what's shown may be out of date.";
const ORDERED_NOTE = "This order has been approved, so it can't be changed. It can still be cancelled.";
const CLOSED_CANCELLED = 'This order is closed: it was cancelled.';
/** C1 (`PurchaseOrderDutySeparation.php:113`), C2 (`IllegalTransition`, developer words until BK-34), C3, P1. */
const C1_RULE = 'purchase_order.canceller_is_not_the_creator';
const C1 = 'You raised this order. It needs a different hand to cancel it.';
const C2_RULE = 'purchase_order.status_transition_is_legal';
const C2 = 'PurchaseOrder::cancel() is legal only from "draft", "ordered", "partially_received", and this purchase_order is "cancelled".';
const C3 = "This purchase order is archived, so it can't be cancelled.";
const P1 = 'Some of the same records were being changed at the same moment. Nothing was saved; try again.';
/** P9's rule (Save / Delete on a draft approved elsewhere). */
const NOT_DRAFT_RULE = 'purchase_order.is_editable_only_while_draft';
const P9 = 'This purchase order has been placed, so it is read-only except for its transitions.';

/** Opens the dialog and presses its Cancel order. */
async function confirmCancel(u: ReturnType<typeof userEvent.setup>) {
  await u.click(cancelOrder());
  await u.click(inDialog('Cancel order'));
}

describe('/purchase-orders/[id] — Cancel order (PO-7; UX B-7; D-2)', () => {
  it('offered on an ordered or partly received order to a CancelPurchaseOrder holder only: never without the code, on a draft, a deleted draft, a received or a cancelled order', () => {
    const a = show({ canCancel: false });
    expect(screen.queryByRole('button', { name: 'Cancel order' })).toBeNull();
    a.unmount();
    for (const order of [DRAFT, { ...DRAFT, archived_at: '2026-03-04T11:00:00Z' }, RECEIVED, CANCELLED]) {
      const { unmount } = show({ order, lines: order.archived_at === null ? LINES : null });
      expect(screen.queryByRole('button', { name: 'Cancel order' })).toBeNull();
      unmount();
    }
    for (const order of [ORDERED, PARTLY]) {
      const { unmount } = show({ order });
      expect(cancelOrder()).toBeInTheDocument();
      unmount();
    }
  });

  it('rendered and enabled for the person who raised the order (ST-4f third arm: C1 is said after the press, never pre-empted)', () => {
    show({ order: { ...ORDERED, created_by: { id: VIEWER_ID, display_name: 'Me Myself' } } });
    expect(screen.getByText('Me Myself (you)')).toBeInTheDocument();
    expect(cancelOrder()).toBeInTheDocument();
    expect(cancelOrder()).not.toHaveAttribute('aria-disabled');
    expect(cancelOrder()).toBeEnabled();
  });

  it('the dialog: "Cancel {number}?", the ordered body, one optional single-line Reason with its hint, focus on Keep order; never a bare "Cancel"; Keep order sends nothing', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    show();
    await u.click(cancelOrder());
    expect(within(dialog()).getByRole('heading', { name: TITLE })).toBeInTheDocument();
    expect(dialog()).toHaveAccessibleDescription(BODY_ORDERED);
    expect(reason()).toHaveAccessibleDescription(HINT);
    expect(reason().tagName).toBe('INPUT');
    expect(reason()).toHaveAttribute('type', 'text');
    // SEC's length cap (plan R-11) is not set and the contract declares no maxLength: the field sets none (owed).
    expect(reason()).not.toHaveAttribute('maxlength');
    expect(reason()).toHaveValue('');
    expect(within(dialog()).getAllByRole('button').map((b) => b.textContent)).toEqual(['Keep order', 'Cancel order']);
    await waitFor(() => expect(inDialog('Keep order')).toHaveFocus());
    await u.click(inDialog('Keep order'));
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(calls).toEqual([]);
    expect(cancelOrder()).toHaveFocus();
  });

  it('a partly received order: the dialog says what happens to the goods already received', async () => {
    stubFetch([]);
    const u = userEvent.setup();
    show({ order: PARTLY });
    await u.click(cancelOrder());
    expect(dialog()).toHaveAccessibleDescription(BODY_PARTLY);
  });

  it('Enter in Reason sends nothing (no form: an irreversible action is never one key away)', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    show();
    await u.click(cancelOrder());
    await u.type(reason(), 'Supplier closed{Enter}');
    expect(calls).toEqual([]);
    expect(dialog()).toBeInTheDocument();
  });

  it('a 200 with the Reason left empty: POST {} with no key; the re-read (order + lines) turns the page Cancelled; focus h1; "After" spoken once; one refresh', async () => {
    const calls = stubFetch([json(200, CANCELLED), json(200, CANCELLED), LINES_REPLY()]);
    const u = userEvent.setup();
    show();
    const spoken = textsOf(region());
    await confirmCancel(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    spoken.stop();
    expect(calls.map((c) => [c.method, c.url])).toEqual([
      ['POST', CANCEL_URL],
      ['GET', URL],
      ['GET', LINES_URL],
    ]);
    expect(calls[0]!.body).toBe('{}');
    expect(calls[0]!.headers.get('idempotency-key')).toBeNull();
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(h1()).toHaveFocus();
    expect(badge('Cancelled')).toBeInTheDocument();
    expect(screen.getByText(CLOSED_CANCELLED)).toBeInTheDocument();
    expect(screen.queryByText(ORDERED_NOTE)).toBeNull();
    expect(screen.queryByRole('button', { name: 'Cancel order' })).toBeNull();
    expect(spoken.texts.filter((t) => t !== '')).toEqual([AFTER]);
    expect(nav.refresh).toHaveBeenCalledTimes(1);
  });

  it('the reason is sent exactly as typed (the server trims; spaces kept, never trimmed here)', async () => {
    const calls = stubFetch([json(200, CANCELLED), json(200, CANCELLED), LINES_REPLY()]);
    const u = userEvent.setup();
    show();
    await u.click(cancelOrder());
    await u.type(reason(), '  Supplier closed  ');
    await u.click(inDialog('Cancel order'));
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    expect(JSON.parse(calls[0]!.body!)).toEqual({ reason: '  Supplier closed  ' });
  });

  it('a typed reason erased back to empty sends {} again', async () => {
    const calls = stubFetch([json(200, CANCELLED), json(200, CANCELLED), LINES_REPLY()]);
    const u = userEvent.setup();
    show();
    await u.click(cancelOrder());
    await u.type(reason(), 'x');
    await u.clear(reason());
    await u.click(inDialog('Cancel order'));
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    expect(calls[0]!.body).toBe('{}');
  });

  it('a partly received order: "afterPartly", chosen from the status the page held before the request (the re-read says cancelled)', async () => {
    const calls = stubFetch([json(200, { ...PARTLY, status: 'cancelled' }), json(200, { ...PARTLY, status: 'cancelled' }), LINES_REPLY()]);
    const u = userEvent.setup();
    show({ order: PARTLY });
    const spoken = textsOf(region());
    await confirmCancel(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER_PARTLY));
    spoken.stop();
    expect(spoken.texts.filter((t) => t !== '')).toEqual([AFTER_PARTLY]);
    expect(calls).toHaveLength(3);
  });

  it('a 200 whose re-read fails: the 200 body is shown (Cancelled, no Cancel order), "After" is still spoken, and the region error offers Retry', async () => {
    const calls = stubFetch([json(200, CANCELLED), problem(503, 'x'), LINES_REPLY()]);
    const u = userEvent.setup();
    show();
    await confirmCancel(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    await waitFor(() => expect(screen.getByText(REREAD_FAILED)).toBeInTheDocument());
    expect(calls.map((c) => [c.method, c.url])).toEqual([
      ['POST', CANCEL_URL],
      ['GET', URL],
      ['GET', LINES_URL],
    ]);
    expect(badge('Cancelled')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Cancel order' })).toBeNull();
    expect(screen.queryByText(ORDERED_NOTE)).toBeNull();
  });

  it('C1 (DSoD-1, the person who raised it): "Not cancelled" + the server sentence in the dialog, heading focused; the dialog stays with only Close; Close re-reads and focus returns to Cancel order', async () => {
    const calls = stubFetch([refused(C1_RULE, C1), json(200, ORDERED), LINES_REPLY()]);
    const u = userEvent.setup();
    show({ order: { ...ORDERED, created_by: { id: VIEWER_ID, display_name: 'Me Myself' } } });
    await confirmCancel(u);
    await waitFor(() => expect(within(dialog()).getByText('Not cancelled')).toHaveFocus());
    expect(dialog()).toHaveTextContent(C1);
    expect(within(dialog()).queryByRole('button', { name: 'Cancel order' })).toBeNull();
    expect(within(dialog()).queryByRole('button', { name: 'Keep order' })).toBeNull();
    expect(calls.map((c) => c.method)).toEqual(['POST']);
    await u.click(inDialog('Close'));
    await waitFor(() => expect(calls).toHaveLength(3));
    expect(calls.map((c) => [c.method, c.url])).toEqual([
      ['POST', CANCEL_URL],
      ['GET', URL],
      ['GET', LINES_URL],
    ]);
    await waitFor(() => expect(cancelOrder()).toHaveFocus());
    expect(region().textContent).toBe('');
  });

  it('C2, re-read cancelled (cancelled elsewhere): UX D-2’s "already cancelled", never the server’s words; only Close; the page under it turns Cancelled; Close re-reads, focus on the h1', async () => {
    const calls = stubFetch([refused(C2_RULE, C2), json(200, CANCELLED), LINES_REPLY(), json(200, CANCELLED), LINES_REPLY()]);
    const u = userEvent.setup();
    show();
    await confirmCancel(u);
    await waitFor(() => expect(within(dialog()).getByText('Not cancelled')).toHaveFocus());
    expect(dialog()).toHaveTextContent(ALREADY_CANCELLED);
    expect(dialog()).not.toHaveTextContent(C2);
    expect(within(dialog()).queryByRole('button', { name: 'Cancel order' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Cancel order', hidden: true })).toBeNull();
    expect(screen.getByText('Cancelled', { selector: 'span' })).toBeInTheDocument();
    await u.click(inDialog('Close'));
    await waitFor(() => expect(calls).toHaveLength(5));
    expect(calls.map((c) => [c.method, c.url])).toEqual([
      ['POST', CANCEL_URL],
      ['GET', URL],
      ['GET', LINES_URL],
      ['GET', URL],
      ['GET', LINES_URL],
    ]);
    await waitFor(() => expect(h1()).toHaveFocus());
  });

  it('C2, re-read received in full: "…was received in full meanwhile, so it can’t be cancelled."', async () => {
    stubFetch([refused(C2_RULE, C2), json(200, RECEIVED), LINES_REPLY()]);
    const u = userEvent.setup();
    show();
    await confirmCancel(u);
    await waitFor(() => expect(within(dialog()).getByText('Not cancelled')).toHaveFocus());
    expect(dialog()).toHaveTextContent(ALREADY_RECEIVED);
    expect(screen.getByText('Received', { selector: 'span' })).toBeInTheDocument();
  });

  it('the dialog keeps the body it opened with: a partly received order cancelled elsewhere (C2) still reads the partly received body', async () => {
    stubFetch([refused(C2_RULE, C2), json(200, { ...PARTLY, status: 'cancelled' }), LINES_REPLY()]);
    const u = userEvent.setup();
    show({ order: PARTLY });
    await confirmCancel(u);
    await waitFor(() => expect(within(dialog()).getByText('Not cancelled')).toHaveFocus());
    expect(screen.getByText('Cancelled', { selector: 'span' })).toBeInTheDocument();
    expect(within(dialog()).getByText(BODY_PARTLY)).toBeInTheDocument();
    expect(within(dialog()).getByRole('heading', { name: TITLE })).toBeInTheDocument();
  });

  it('C2 whose re-read fails: UX D-2’s changed-since sentence; only Close; the page shows its region error', async () => {
    stubFetch([refused(C2_RULE, C2), problem(503, 'x'), problem(503, 'x')]);
    const u = userEvent.setup();
    show();
    await confirmCancel(u);
    await waitFor(() => expect(within(dialog()).getByText('Not cancelled')).toHaveFocus());
    expect(dialog()).toHaveTextContent(CHANGED);
    expect(within(dialog()).queryByRole('button', { name: 'Cancel order' })).toBeNull();
    await u.click(inDialog('Close'));
    await waitFor(() => expect(screen.getByText(REREAD_FAILED)).toBeInTheDocument());
  });

  it('C3 (archived — unreachable from this page, D-2): the server sentence as sent, only Close', async () => {
    stubFetch([refused('purchase_order.is_archived', C3)]);
    const u = userEvent.setup();
    show();
    await confirmCancel(u);
    await waitFor(() => expect(within(dialog()).getByText('Not cancelled')).toHaveFocus());
    expect(dialog()).toHaveTextContent(C3);
    expect(within(dialog()).queryByRole('button', { name: 'Cancel order' })).toBeNull();
    expect(inDialog('Close')).toBeInTheDocument();
  });

  it('contention (P1): the sentence shows and Cancel order stays; pressing it again sends a second POST, which cancels', async () => {
    const calls = stubFetch([refused('transaction.contention', P1), json(200, CANCELLED), json(200, CANCELLED), LINES_REPLY()]);
    const u = userEvent.setup();
    show();
    await confirmCancel(u);
    await waitFor(() => expect(within(dialog()).getByText('Not cancelled')).toHaveFocus());
    expect(dialog()).toHaveTextContent(P1);
    expect(inDialog('Keep order')).toBeInTheDocument();
    await u.click(inDialog('Cancel order'));
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    expect(calls.map((c) => c.method)).toEqual(['POST', 'POST', 'GET', 'GET']);
  });

  it('a 403 names "Cancel purchase orders" (B-12) and keeps Cancel order', async () => {
    stubFetch([problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    show();
    await confirmCancel(u);
    await waitFor(() => expect(within(dialog()).getByText('Not cancelled')).toHaveFocus());
    expect(dialog()).toHaveTextContent('This needs the Cancel purchase orders permission, which you no longer have. Your workspace administrator can grant it.');
    expect(inDialog('Cancel order')).toBeInTheDocument();
  });

  it('interrupted: "We can’t tell whether PO-000001 was cancelled…", Cancel order stays; Escape closes and re-reads; a re-read finding it cancelled (the press committed) moves focus to the h1', async () => {
    const calls = stubFetch([problem(503, 'x'), json(200, CANCELLED), LINES_REPLY()]);
    const u = userEvent.setup();
    show();
    await confirmCancel(u);
    await waitFor(() => expect(dialog()).toHaveTextContent(INTERRUPTED));
    expect(within(dialog()).getByText('Something went wrong on our side.')).toHaveFocus();
    expect(inDialog('Cancel order')).toBeInTheDocument();
    await u.keyboard('{Escape}');
    await waitFor(() => expect(badge('Cancelled')).toBeInTheDocument());
    expect(calls.map((c) => c.method)).toEqual(['POST', 'GET', 'GET']);
    await waitFor(() => expect(h1()).toHaveFocus());
  });

  it('interrupted, then Escape: focus goes back to Cancel order; a re-read finding the order cancelled elsewhere removes it, so the h1 takes focus', async () => {
    const reread = held();
    const calls = stubFetch([problem(503, 'x'), reread.reply, LINES_REPLY()]);
    const u = userEvent.setup();
    show();
    await confirmCancel(u);
    await waitFor(() => expect(dialog()).toHaveTextContent(INTERRUPTED));
    await u.keyboard('{Escape}');
    await waitFor(() => expect(cancelOrder()).toHaveFocus());
    await act(async () => {
      reread.release(json(200, CANCELLED));
      await new Promise((t) => setTimeout(t, 50));
    });
    await waitFor(() => expect(badge('Cancelled')).toBeInTheDocument());
    expect(calls.map((c) => c.method)).toEqual(['POST', 'GET', 'GET']);
    expect(screen.queryByRole('button', { name: 'Cancel order' })).toBeNull();
    await waitFor(() => expect(h1()).toHaveFocus());
  });

  it('a 404 leaves Close only; Close re-reads, and a 404 there is ST-5', async () => {
    stubFetch([problem(404, 'not-found'), problem(404, 'not-found')]);
    const u = userEvent.setup();
    show();
    await confirmCancel(u);
    await waitFor(() => expect(dialog()).toHaveTextContent("We couldn't find that. It may have been deleted, or you may not have access."));
    expect(within(dialog()).queryByRole('button', { name: 'Cancel order' })).toBeNull();
    await u.click(inDialog('Close'));
    await waitFor(() => expect(screen.getByRole('heading', { name: "We couldn't find that." })).toBeInTheDocument());
  });

  it('while the request runs the dialog is busy: no Keep order, Escape does nothing; the press empties the region first (FE-R9)', async () => {
    const post = held();
    leaveArrivalMessage(`/purchase-orders/${OID}`, 'Earlier sentence.');
    stubFetch([post.reply, json(200, CANCELLED), LINES_REPLY()]);
    const u = userEvent.setup();
    show();
    await waitFor(() => expect(region()).toHaveTextContent('Earlier sentence.'));
    await confirmCancel(u);
    expect(region().textContent).toBe('');
    expect(within(dialog()).queryByRole('button', { name: 'Keep order' })).toBeNull();
    await u.keyboard('{Escape}');
    expect(dialog()).toBeInTheDocument();
    await act(async () => {
      post.release(json(200, CANCELLED));
      await new Promise((t) => setTimeout(t, 50));
    });
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
  });

  it('an older page Retry answering after the Cancel’s re-read is dropped (the stamp rule): the page stays Cancelled', async () => {
    const retry = held();
    // The region error (and its Retry) comes from a C2 whose two re-reads fail; the order is still ordered on screen. The
    // Retry is held, Cancel order is pressed meanwhile and answers first; the Retry's older reading (ordered) lands last.
    const calls = stubFetch([refused(C2_RULE, C2), problem(503, 'x'), problem(503, 'x'), retry.reply, json(200, CANCELLED), json(200, CANCELLED), LINES_REPLY()]);
    const u = userEvent.setup();
    show();
    await confirmCancel(u);
    await waitFor(() => expect(dialog()).toHaveTextContent(CHANGED));
    await u.click(inDialog('Close'));
    await waitFor(() => expect(screen.getByText(REREAD_FAILED)).toBeInTheDocument());
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(calls).toHaveLength(4));
    await confirmCancel(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    await act(async () => {
      retry.release(json(200, ORDERED));
      await new Promise((t) => setTimeout(t, 50));
    });
    expect(badge('Cancelled')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Cancel order' })).toBeNull();
    expect(h1()).toHaveFocus();
  });

  it('the stale "Not saved" banner from a Save refused because the order was approved elsewhere goes when the person cancels it', async () => {
    stubFetch([refused(NOT_DRAFT_RULE, P9), json(200, ORDERED), LINES_REPLY(), json(200, CANCELLED), json(200, CANCELLED), LINES_REPLY()]);
    const u = userEvent.setup();
    show({ order: DRAFT, edit: EDIT });
    await u.selectOptions(screen.getByRole('combobox', { name: 'Deliver to' }), W2.id);
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(screen.getByText('Not saved')).toBeInTheDocument());
    await confirmCancel(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    expect(screen.queryByText('Not saved')).toBeNull();
    expect(h1()).toHaveFocus();
  });
});

// The brief's item 6 (po-7-progress.md "The cancel floor"): a write started while the page still showed a draft can still
// be in flight when the page turns ordered. These tests drive that path (the probe for "it cannot happen").
describe('/purchase-orders/[id] — Cancel order against a write still in flight from the draft', () => {
  /** Save held on the draft; Delete purchase order refused P9 (approved elsewhere) → ordered under the dialog; Close re-reads ordered. */
  async function toOrderedWithSaveHeld(u: ReturnType<typeof userEvent.setup>) {
    await u.selectOptions(screen.getByRole('combobox', { name: 'Deliver to' }), W2.id);
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await u.click(screen.getByRole('button', { name: 'Delete purchase order' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete purchase order' }));
    await waitFor(() => expect(within(dialog()).getByText('Not deleted')).toHaveFocus());
    await u.click(inDialog('Close'));
    await waitFor(() => expect(cancelOrder()).toBeInTheDocument());
  }

  it('a Save refused after the person’s own Cancel answered 200: no "Not saved", the h1 keeps focus, "After" only', async () => {
    const patch = held();
    const calls = stubFetch([
      patch.reply,
      refused(NOT_DRAFT_RULE, P9),
      json(200, ORDERED),
      LINES_REPLY(),
      json(200, ORDERED),
      LINES_REPLY(),
      json(200, CANCELLED),
      json(200, CANCELLED),
      LINES_REPLY(),
      json(200, CANCELLED),
      LINES_REPLY(),
    ]);
    const u = userEvent.setup();
    show({ order: DRAFT, edit: EDIT, canDelete: true });
    const spoken = textsOf(region());
    await toOrderedWithSaveHeld(u);
    await confirmCancel(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    await act(async () => {
      patch.release(refused(NOT_DRAFT_RULE, P9));
      await new Promise((t) => setTimeout(t, 50));
    });
    await act(async () => {
      await new Promise((t) => setTimeout(t, 50));
    });
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'DELETE', 'GET', 'GET', 'GET', 'GET', 'POST', 'GET', 'GET', 'GET', 'GET']);
    spoken.stop();
    expect(screen.queryByText('Not saved')).toBeNull();
    expect(h1()).toHaveFocus();
    expect(region().textContent).toBe(AFTER);
    expect(spoken.texts.filter((t) => t !== '')).toEqual([AFTER]);
    expect(badge('Cancelled')).toBeInTheDocument();
  });

  it('a Save answering 200 after the Cancel, whose re-read fails: its draft body is not committed (no editor, still Cancelled) and its failure is not shown', async () => {
    const patch = held();
    stubFetch([
      patch.reply,
      refused(NOT_DRAFT_RULE, P9),
      json(200, ORDERED),
      LINES_REPLY(),
      json(200, ORDERED),
      LINES_REPLY(),
      json(200, CANCELLED),
      json(200, CANCELLED),
      LINES_REPLY(),
      problem(503, 'x'),
      LINES_REPLY(),
    ]);
    const u = userEvent.setup();
    show({ order: DRAFT, edit: EDIT, canDelete: true });
    const spoken = textsOf(region());
    await toOrderedWithSaveHeld(u);
    await confirmCancel(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    await act(async () => {
      patch.release(json(200, { ...DRAFT, warehouse: { id: W2.id, name: W2.name } }));
      await new Promise((t) => setTimeout(t, 50));
    });
    await act(async () => {
      await new Promise((t) => setTimeout(t, 50));
    });
    spoken.stop();
    expect(badge('Cancelled')).toBeInTheDocument();
    expect(screen.queryByRole('combobox')).toBeNull();
    expect(screen.queryByText(REREAD_FAILED)).toBeNull();
    expect(h1()).toHaveFocus();
    expect(spoken.texts.filter((t) => t !== '')).toEqual([AFTER]);
    expect(spoken.texts).not.toContain('PO-000001 was saved.');
  });

  it('(control) a Save refused P9 before any Cancel: the "Not saved" banner IS shown (the floor only silences a Save sent before the person’s own Cancel)', async () => {
    stubFetch([refused(NOT_DRAFT_RULE, P9), json(200, ORDERED), LINES_REPLY()]);
    const u = userEvent.setup();
    show({ order: DRAFT, edit: EDIT });
    await u.selectOptions(screen.getByRole('combobox', { name: 'Deliver to' }), W2.id);
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(screen.getByText('Not saved')).toHaveFocus());
    expect(cancelOrder()).toBeInTheDocument();
  });

  it('while a line Delete is in flight (the page turned ordered under it), Cancel order opens nothing and sends nothing; once it has answered, it opens', async () => {
    const del = held();
    const calls = stubFetch([del.reply, refused(NOT_DRAFT_RULE, P9), json(200, ORDERED), LINES_REPLY(), json(200, ORDERED), LINES_REPLY()]);
    const u = userEvent.setup();
    show({ order: DRAFT, edit: EDIT, canDeleteLine: true });
    await u.click(screen.getByRole('button', { name: 'Delete line 2' }));
    await u.selectOptions(screen.getByRole('combobox', { name: 'Deliver to' }), W2.id);
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(cancelOrder()).toBeInTheDocument());
    await u.click(cancelOrder());
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(calls.map((c) => c.method)).toEqual(['DELETE', 'PATCH', 'GET', 'GET']);
    await act(async () => {
      del.release(refused(NOT_DRAFT_RULE, P9));
      await new Promise((t) => setTimeout(t, 50));
    });
    await waitFor(() => expect(calls).toHaveLength(6));
    await u.click(cancelOrder());
    expect(within(dialog()).getByRole('heading', { name: TITLE })).toBeInTheDocument();
  });
});

describe('/purchase-orders/[id] — the PO-7 copy forms (UX R1a-1 row 7)', () => {
  it('ordered and partly received: "orderedNote" in its final form, for a viewer who may cancel and one who may not (passive, R-3)', () => {
    for (const [order, canCancel] of [
      [ORDERED, true],
      [PARTLY, true],
      [ORDERED, false],
    ] as const) {
      const { unmount } = show({ order, canCancel });
      expect(screen.getByText(ORDERED_NOTE, { selector: 'p' })).toBeInTheDocument();
      unmount();
    }
  });
});

// FE-D1 order (plan §4 PO-7: 200 → re-read, Cancelled, focus h1), plain and under StrictMode: a `focusin` listener records
// the DOM at the moment the h1 takes focus — no dialog in the document and the "After" sentence not yet written.
for (const strict of [false, true]) {
  const wrap = (ui: ReactElement) => (strict ? <StrictMode>{ui}</StrictMode> : ui);
  const suffix = strict ? ' (StrictMode)' : '';
  describe(`Cancel order — FE-D1 order${suffix}`, () => {
    it('200: the dialog closes, the h1 takes focus, THEN "After" is written; one refresh, after the focus', async () => {
      stubFetch([json(200, CANCELLED), json(200, CANCELLED), LINES_REPLY()]);
      const u = userEvent.setup();
      const order: string[] = [];
      nav.refresh.mockImplementation(() => order.push('refresh'));
      renderWired(wrap(<PurchaseOrderPage order={ORDERED} lines={LINES} viewerId={VIEWER_ID} canRestore canReadLines canOpenSupplier={false} canOpenWarehouse={false} canOpenItem={false} canCancel />));
      await u.click(cancelOrder());
      const atFocus: { text: boolean; dialog: boolean }[] = [];
      const onFocusIn = (e: FocusEvent) => {
        if ((e.target as HTMLElement).tagName === 'H1') {
          order.push('focus');
          atFocus.push({ text: (document.body.textContent ?? '').includes(AFTER), dialog: document.querySelector('[role="dialog"]') !== null });
        }
      };
      document.addEventListener('focusin', onFocusIn);
      await u.click(inDialog('Cancel order'));
      await waitFor(() => expect(region()).toHaveTextContent(AFTER));
      document.removeEventListener('focusin', onFocusIn);
      expect(atFocus[0]).toEqual({ text: false, dialog: false });
      expect(h1()).toHaveFocus();
      expect(order[0]).toBe('focus');
      expect(order.filter((o) => o === 'refresh')).toEqual(['refresh']);
      expect(order.indexOf('refresh')).toBeGreaterThan(0);
      nav.refresh.mockReset();
    });

    it('C1 → Close → re-read: the refusal heading had focus; after Close the re-read ran once and focus is back on Cancel order (still ordered)', async () => {
      const calls = stubFetch([refused(C1_RULE, C1), json(200, ORDERED), LINES_REPLY()]);
      const u = userEvent.setup();
      renderWired(wrap(<PurchaseOrderPage order={ORDERED} lines={LINES} viewerId={VIEWER_ID} canRestore canReadLines canOpenSupplier={false} canOpenWarehouse={false} canOpenItem={false} canCancel />));
      await confirmCancel(u);
      await waitFor(() => expect(within(dialog()).getByText('Not cancelled')).toHaveFocus());
      await u.click(inDialog('Close'));
      await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['POST', 'GET', 'GET']));
      await new Promise((r) => setTimeout(r, 50));
      expect(calls).toHaveLength(3);
      expect(cancelOrder()).toHaveFocus();
    });
  });
}

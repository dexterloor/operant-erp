import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: vi.fn(), refresh: nav.refresh }), usePathname: () => '/purchase-orders/x' }));
import { PurchaseOrderPage, type PurchaseOrderPageProps } from '@/app/(app)/purchase-orders/[id]/PurchaseOrderPage';
import type { PurchaseOrder, PurchaseOrderLine } from '@/lib/api/purchasing';
import type { LinesLoad, WarehouseChoice, WarehouseChoicesLoad } from '@/lib/purchasing/load';
import { BffProvider } from '@/app/(app)/BffProvider';
import { TooltipProvider } from '@/components/ui/Tooltip';
import { SID, TZ, VIEWER_ID, held, json, page, problem, refused, renderWired, stubFetch, textsOf } from './harness';

// Purchase orders PO-4a — save a draft's header (purchase-orders-final-red.md apply list PO-4a; plan Revision 1 R1-5 PO-4a
// over Revision 0 §2.2 / §4 PO-4's header half; UX R1a-1 row 4, R1-B `PURCHASE_ORDER_PAGE`, Part D D-2; Revision 10 R10-5 as
// amended by final RED L-8). The page is the real `PurchaseOrderPage` with the real `OrderHeaderForm`; Save goes through
// the REAL `lib/purchasing/wired.ts`, `load.ts` and the browser transport. The one editable field is Deliver to (D-4, the
// expected date, is unruled: no date field, `expected_at` never sent); Supplier and Currency are text for every viewer.

afterEach(() => {
  vi.unstubAllGlobals();
  nav.refresh.mockClear();
});

const OID = 'd1b2c3d4-0000-4000-8000-000000000001';
const W1: WarehouseChoice = { id: 'c1b2c3d4-0000-4000-8000-0000000000b1', code: 'MAIN', name: 'Main warehouse' };
const W2: WarehouseChoice = { id: 'c1b2c3d4-0000-4000-8000-0000000000b2', code: 'EAST', name: 'East depot' };
const W9 = { id: 'c1b2c3d4-0000-4000-8000-0000000000b9', name: 'Old site' };
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
const MOVED: PurchaseOrder = { ...DRAFT, warehouse: { id: W2.id, name: W2.name } };
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
const show = (over: Partial<PurchaseOrderPageProps> = {}) =>
  renderWired(<PurchaseOrderPage order={DRAFT} lines={LINES} viewerId={VIEWER_ID} canRestore canReadLines canOpenSupplier={false} canOpenWarehouse={false} canOpenItem={false} edit={EDIT} {...over} />);
const region = () => document.querySelector<HTMLElement>('section > [role="status"]')!;
const deliverTo = () => screen.getByRole('combobox', { name: 'Deliver to' });
const save = () => screen.getByRole('button', { name: 'Save' });
/** The page as the router refresh after a restore renders it again: the same tree, new server props. */
const wrapped = (over: Partial<PurchaseOrderPageProps>) => (
  <TooltipProvider>
    <BffProvider session={{ sid: SID, tz: TZ }} reload={() => {}}>
      <PurchaseOrderPage order={DRAFT} lines={LINES} viewerId={VIEWER_ID} canRestore canReadLines canOpenSupplier={false} canOpenWarehouse={false} canOpenItem={false} edit={EDIT} {...over} />
    </BffProvider>
  </TooltipProvider>
);
const URL = `/bff/api/purchase-orders/${OID}`;
const LINES_URL = `${URL}/lines`;
/** The order's live lines as a re-read answers them (plan §2.2: GetPurchaseOrder + lines). */
const LINES_REPLY = () => json(200, page([line(1), line(2)]));
const SAVED = 'PO-000001 was saved.';
// Moved at PO-6 (UX R1a-1 row 6; po-4a-progress.md M16/M17 planted these forms early): Delete purchase order ships there.
const DRAFT_NOTE = 'This is a draft. It can be changed or deleted.';
const SUPPLIER_FIXED = "The supplier can't be changed. To order from another supplier, this draft can be deleted and a new one created.";
const REREAD_FAILED = "We couldn't load this purchase order again, so what's shown may be out of date.";
/** P8 (`PurchaseOrderWriter::refuseIfArchived`) and P9 (`refuseUnlessDraft`), as the server sends them. */
const P8 = "This purchase order is archived, so it can't be changed.";
const P9 = 'This purchase order has been placed, so it is read-only except for its transitions.';
const P1 = 'Some of the same records were being changed at the same moment. Nothing was saved; try again.';
const P4 = 'No live warehouse in this workspace has this id.';
const NOT_DRAFT_RULE = 'purchase_order.is_editable_only_while_draft';
const optionTexts = () => within(deliverTo()).getAllByRole('option').map((o) => o.textContent);

describe('/purchase-orders/[id] — a draft’s header editor (PO-4a)', () => {
  it('Deliver to is a select over the live warehouses ({code} — {name}), holding the served one; Supplier and Currency are text; Save says "Nothing to save."', () => {
    show();
    expect(deliverTo()).toHaveValue(W1.id);
    expect(optionTexts()).toEqual(['MAIN — Main warehouse', 'EAST — East depot']);
    expect(deliverTo()).toHaveAccessibleDescription('The warehouse the goods are delivered to and received into.');
    // Supplier and Currency are never inputs (D-6, UX D-4): one combobox on the page, and no textbox.
    expect(screen.getAllByRole('combobox')).toHaveLength(1);
    expect(screen.queryByRole('textbox')).toBeNull();
    expect(screen.getByText('Acme Supplies')).toBeInTheDocument();
    expect(screen.getByText('GBP')).toBeInTheDocument();
    // UX R1a-1 row 6 (moved at PO-6 from row 4's forms): both name deleting, which ships there; nothing names approving (PO-8).
    expect(screen.getByText(SUPPLIER_FIXED, { selector: 'p' })).toBeInTheDocument();
    expect(screen.getByText(DRAFT_NOTE, { selector: 'p' })).toBeInTheDocument();
    expect(document.body.textContent).not.toMatch(/approv/i);
    expect(save()).toHaveAccessibleDescription('Nothing to save.');
    // No expected-date field (D-4 unruled).
    expect(screen.queryByLabelText(/Expected/)).toBeNull();
  });

  it('no editor without the edit prop (UpdatePurchaseOrder and ListWarehouses), nor on a deleted draft or any other status; the draft note is the same for every viewer', () => {
    const a = show({ edit: null });
    expect(screen.queryByRole('combobox')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Save' })).toBeNull();
    expect(screen.queryByText(SUPPLIER_FIXED)).toBeNull();
    expect(screen.getByText(DRAFT_NOTE, { selector: 'p' })).toBeInTheDocument();
    expect(screen.getByText('Main warehouse')).toBeInTheDocument();
    a.unmount();
    for (const status of ['ordered', 'partially_received', 'received', 'cancelled']) {
      const { container, unmount } = show({ order: { ...DRAFT, status } });
      expect(container.querySelectorAll('select, input, textarea, form')).toHaveLength(0);
      unmount();
    }
    const d = show({ order: { ...DRAFT, archived_at: '2026-03-04T11:00:00Z' }, lines: null });
    expect(screen.queryByRole('combobox')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Save' })).toBeNull();
    d.unmount();
  });

  it('Save sends only the changed warehouse (PATCH, no retry key, never supplier, currency or a date), re-reads the order and its lines, says "{number} was saved.", focuses the h1', async () => {
    const calls = stubFetch([json(200, MOVED), json(200, MOVED), json(200, page([line(1), line(2), line(3)]))]);
    const u = userEvent.setup();
    show();
    await u.selectOptions(deliverTo(), W2.id);
    expect(save()).not.toHaveAccessibleDescription('Nothing to save.');
    await u.click(save());
    await waitFor(() => expect(region()).toHaveTextContent(SAVED));
    expect(calls.map((c) => [c.method, c.url])).toEqual([
      ['PATCH', URL],
      ['GET', URL],
      ['GET', LINES_URL],
    ]);
    expect(JSON.parse(calls[0]!.body!)).toEqual({ warehouse_id: W2.id });
    expect(calls[0]!.headers.get('Idempotency-Key')).toBeNull();
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveFocus());
    expect(deliverTo()).toHaveValue(W2.id);
    expect(save()).toHaveAccessibleDescription('Nothing to save.');
    // Moved by po-4a-red.md M-2: the re-read's lines are committed with it (here a third line added elsewhere).
    await waitFor(() => expect(screen.getAllByRole('row')).toHaveLength(4));
    expect(nav.refresh).toHaveBeenCalledTimes(1);
  });

  it('a Save with nothing changed sends nothing (also after choosing the served warehouse back)', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    show();
    await u.click(save());
    await u.selectOptions(deliverTo(), W2.id);
    await u.selectOptions(deliverTo(), W1.id);
    expect(save()).toHaveAccessibleDescription('Nothing to save.');
    await u.click(save());
    expect(calls).toEqual([]);
  });

  it('the re-read after a 200 fails: the 200 body is shown, "{number} was saved." is said, and the region error offers Retry', async () => {
    stubFetch([json(200, MOVED), json(500, {}), LINES_REPLY()]);
    const u = userEvent.setup();
    show();
    await u.selectOptions(deliverTo(), W2.id);
    await u.click(save());
    await waitFor(() => expect(region()).toHaveTextContent(SAVED));
    await waitFor(() => expect(screen.getByText(REREAD_FAILED)).toBeInTheDocument());
    expect(deliverTo()).toHaveValue(W2.id);
  });

  it('two Saves of the same order in a row each say "{number} was saved.": the region empties at the second press (30d2edb)', async () => {
    stubFetch([json(200, MOVED), json(200, MOVED), LINES_REPLY(), json(200, DRAFT), json(200, DRAFT), LINES_REPLY()]);
    const u = userEvent.setup();
    show();
    const rec = textsOf(region());
    await u.selectOptions(deliverTo(), W2.id);
    await u.click(save());
    await waitFor(() => expect(region()).toHaveTextContent(SAVED));
    await u.selectOptions(deliverTo(), W1.id);
    await u.click(save());
    await waitFor(() => expect(deliverTo()).toHaveValue(W1.id));
    await waitFor(() => expect(rec.texts).toEqual(['', SAVED, '', SAVED]));
    rec.stop();
  });

  it('P9 (no longer a draft), re-read ordered: the page turns read-only in the same mount and says UX D-2’s sentence, never the server’s', async () => {
    const calls = stubFetch([refused(NOT_DRAFT_RULE, P9), json(200, { ...DRAFT, status: 'ordered', ordered_at: '2026-03-05T09:00:00Z' }), LINES_REPLY()]);
    const u = userEvent.setup();
    show();
    const h1 = screen.getByRole('heading', { level: 1 });
    await u.selectOptions(deliverTo(), W2.id);
    await u.click(save());
    const heading = await screen.findByText('Not saved');
    expect(screen.getByText("PO-000001 is no longer a draft: it's ordered, so it can't be changed.")).toBeInTheDocument();
    expect(screen.queryByText(P9)).toBeNull();
    await waitFor(() => expect(heading).toHaveFocus());
    expect(calls.map((c) => [c.method, c.url])).toEqual([
      ['PATCH', URL],
      ['GET', URL],
      ['GET', LINES_URL],
    ]);
    expect(screen.queryByRole('combobox')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Save' })).toBeNull();
    expect(screen.getByRole('heading', { level: 1 })).toBe(h1);
    expect(screen.getByText("This order has been approved, so it can't be changed. It can still be cancelled.")).toBeInTheDocument();
    expect(region()).toHaveTextContent('');
  });

  it('P9, re-read cancelled: the sentence names the status the page re-read', async () => {
    stubFetch([refused(NOT_DRAFT_RULE, P9), json(200, { ...DRAFT, status: 'cancelled' }), LINES_REPLY()]);
    const u = userEvent.setup();
    show();
    await u.selectOptions(deliverTo(), W2.id);
    await u.click(save());
    expect(await screen.findByText("PO-000001 is no longer a draft: it's cancelled, so it can't be changed.")).toBeInTheDocument();
  });

  it('P9, and the re-read fails: "{number} changed since this page was loaded…" under Not saved, the region error, the choice kept', async () => {
    stubFetch([refused(NOT_DRAFT_RULE, P9), json(500, {})]);
    const u = userEvent.setup();
    show();
    await u.selectOptions(deliverTo(), W2.id);
    await u.click(save());
    expect(await screen.findByText("PO-000001 changed since this page was loaded, so this wasn't done. Reload the page to see where it stands.")).toBeInTheDocument();
    expect(screen.queryByText(P9)).toBeNull();
    await waitFor(() => expect(screen.getByText(REREAD_FAILED)).toBeInTheDocument());
    expect(deliverTo()).toHaveValue(W2.id);
  });

  it('P8 (archived elsewhere), re-read deleted: the deleted banner in the same mount with Restore, the server’s sentence under Not saved, no editor', async () => {
    stubFetch([refused('purchase_order.is_archived', P8), json(200, { ...DRAFT, archived_at: '2026-03-04T11:00:00Z' })]);
    const u = userEvent.setup();
    show();
    await u.selectOptions(deliverTo(), W2.id);
    await u.click(save());
    const heading = await screen.findByText('Not saved');
    expect(screen.getByText(P8)).toBeInTheDocument();
    await waitFor(() => expect(heading).toHaveFocus());
    expect(screen.getByText(/This draft was deleted on/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Restore purchase order' })).toBeInTheDocument();
    expect(screen.queryByRole('combobox')).toBeNull();
  });

  it('P1 (contention), re-read still a draft: the server’s sentence in the form, the choice kept, nothing committed, Save still offered', async () => {
    const calls = stubFetch([refused('transaction.contention', P1), json(200, { ...DRAFT, total_amount: '99.0000' })]);
    const u = userEvent.setup();
    show();
    await u.selectOptions(deliverTo(), W2.id);
    await u.click(save());
    const heading = await screen.findByText('Not saved');
    expect(screen.getByText(P1)).toBeInTheDocument();
    await waitFor(() => expect(heading).toHaveFocus());
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET']);
    expect(deliverTo()).toHaveValue(W2.id);
    expect(screen.queryByText('99.00 GBP')).toBeNull();
    expect(save()).not.toHaveAccessibleDescription('Nothing to save.');
  });

  it('P1, and the re-read fails: the server’s P1 sentence, never the changed-since sentence (that is the status refusal’s)', async () => {
    stubFetch([refused('transaction.contention', P1), json(500, {})]);
    const u = userEvent.setup();
    show();
    await u.selectOptions(deliverTo(), W2.id);
    await u.click(save());
    expect(await screen.findByText(P1)).toBeInTheDocument();
    expect(screen.queryByText(/changed since this page was loaded/)).toBeNull();
  });

  it('P4 (the chosen warehouse archived meanwhile): the sentence beside Deliver to, focused; the live list is read once more and the refused one is gone, the served one chosen again', async () => {
    const calls = stubFetch([problem(422, 'validation-failed', { errors: [{ pointer: '/warehouse_id', code: 'unknown_reference', message: P4 }] }), json(200, page([W1]))]);
    const u = userEvent.setup();
    show();
    await u.selectOptions(deliverTo(), W2.id);
    await u.click(save());
    await waitFor(() => expect(deliverTo()).toHaveAccessibleDescription(expect.stringContaining(P4)));
    await waitFor(() => expect(deliverTo()).toHaveFocus());
    await waitFor(() => expect(optionTexts()).toEqual(['MAIN — Main warehouse']));
    expect(calls.map((c) => [c.method, c.url.replace(/\?.*$/, '')])).toEqual([
      ['PATCH', URL],
      ['GET', '/bff/api/warehouses'],
    ]);
    expect(deliverTo()).toHaveValue(W1.id);
    expect(within(deliverTo()).getAllByRole('option').every((o) => (o.textContent ?? '') !== '')).toBe(true);
    expect(deliverTo()).toHaveAccessibleDescription(expect.stringContaining(P4));
  });

  it('403 on Save: the lost-permission sentence naming Edit draft purchase orders, under Not saved', async () => {
    stubFetch([problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    show();
    await u.selectOptions(deliverTo(), W2.id);
    await u.click(save());
    const heading = await screen.findByText('Not saved');
    await waitFor(() => expect(heading).toHaveFocus());
    expect(heading.parentElement!.textContent).toContain('This needs the Edit draft purchase orders permission, which you no longer have. Your workspace administrator can grant it.');
    expect(deliverTo()).toHaveValue(W2.id);
  });

  it('a lost answer: "We can’t tell whether your changes to {number} were saved. Reload the page to check.", the choice kept', async () => {
    stubFetch(['hang']);
    vi.useFakeTimers({ shouldAdvanceTime: true });
    try {
      const u = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
      show();
      await u.selectOptions(deliverTo(), W2.id);
      await u.click(save());
      await vi.advanceTimersByTimeAsync(31_000);
      expect(await screen.findByText("We can't tell whether your changes to PO-000001 were saved. Reload the page to check.")).toBeInTheDocument();
      expect(deliverTo()).toHaveValue(W2.id);
    } finally {
      vi.useRealTimers();
    }
  });

  it('final RED L-8: a served warehouse missing from the live list stays first, labelled from the order’s own reference, never its id', () => {
    show({ order: { ...DRAFT, warehouse: W9 } });
    expect(deliverTo()).toHaveValue(W9.id);
    expect(optionTexts()).toEqual(['Old site', 'MAIN — Main warehouse', 'EAST — East depot']);
    expect(document.body.textContent).not.toContain(W9.id);
  });

  it('the warehouses could not be read: the field’s region error; its Retry reads them and the select takes focus', async () => {
    const calls = stubFetch([json(200, page([W1, W2]))]);
    const u = userEvent.setup();
    show({ edit: { warehouses: { kind: 'server-error' } } });
    expect(screen.queryByRole('combobox')).toBeNull();
    expect(screen.getByText("The warehouses couldn't be loaded.")).toBeInTheDocument();
    expect(save()).toHaveAccessibleDescription('Nothing to save.');
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(deliverTo()).toHaveFocus());
    expect(calls.map((c) => c.url.replace(/\?.*$/, ''))).toEqual(['/bff/api/warehouses']);
    expect(deliverTo()).toHaveValue(W1.id);
  });

  it('a field Retry answered 403: the lost-permission sentence naming View warehouses, no Retry to repeat it', async () => {
    stubFetch([problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    show({ edit: { warehouses: { kind: 'unreachable' } } });
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    expect(await screen.findByText('View warehouses')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Retry' })).toBeNull();
  });

  it('the stamp rule: a page Retry sent before a later Save, answered after it, is dropped (the Save’s re-read is newer)', async () => {
    const h = held();
    stubFetch([json(200, MOVED), json(500, {}), LINES_REPLY(), h.reply, json(200, DRAFT), json(200, DRAFT), LINES_REPLY()]);
    const u = userEvent.setup();
    show();
    // A first Save whose re-read fails: the 200 body is shown (W2) and the region error offers Retry.
    await u.selectOptions(deliverTo(), W2.id);
    await u.click(save());
    await waitFor(() => expect(screen.getByText(REREAD_FAILED)).toBeInTheDocument());
    // Retry's read is held; a second Save goes out and its re-read lands first.
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await u.selectOptions(deliverTo(), W1.id);
    await u.click(save());
    await waitFor(() => expect(region()).toHaveTextContent(SAVED));
    await waitFor(() => expect(deliverTo()).toHaveValue(W1.id));
    // The Retry's older answer (the order at W2) arrives last and is dropped.
    h.release(json(200, MOVED));
    await new Promise((r) => setTimeout(r, 50));
    expect(deliverTo()).toHaveValue(W1.id);
  });

  it('a GetSupplier holder: the supplier in the editor is a link, still never an input', () => {
    show({ canOpenSupplier: true });
    expect(screen.getByRole('link', { name: 'Acme Supplies' })).toHaveAttribute('href', `/suppliers/${DRAFT.supplier.id}`);
    expect(screen.queryByRole('textbox')).toBeNull();
  });

  it('a page that LOADED deleted (no editor from the server): after Restore the header is text until the refreshed page brings the editor', async () => {
    stubFetch([json(200, DRAFT), json(200, DRAFT), LINES_REPLY()]);
    const u = userEvent.setup();
    // The server page gives no editor to a deleted draft (purchase-order-edit-gate.test.ts).
    const { rerender } = show({ order: { ...DRAFT, archived_at: '2026-03-04T11:00:00Z' }, lines: null, edit: null });
    await u.click(screen.getByRole('button', { name: 'Restore purchase order' }));
    await waitFor(() => expect(screen.queryByText(/This draft was deleted on/)).toBeNull());
    expect(nav.refresh).toHaveBeenCalledTimes(1);
    expect(screen.queryByRole('combobox')).toBeNull();
    expect(screen.getByText('Main warehouse')).toBeInTheDocument();
    rerender(wrapped({ order: { ...DRAFT, archived_at: '2026-03-04T11:00:00Z' }, lines: null, edit: EDIT }));
    expect(deliverTo()).toHaveValue(W1.id);
  });
  it('po-4a-red.md M-2: P9 with lines changed elsewhere — the page shows the re-read lines with the re-read total; never a newer total over older lines', async () => {
    stubFetch([refused(NOT_DRAFT_RULE, P9), json(200, { ...DRAFT, status: 'ordered', total_amount: '75.0000', ordered_at: '2026-03-05T09:00:00Z' }), json(200, page([line(1), line(2), line(3)]))]);
    const u = userEvent.setup();
    show();
    expect(screen.getAllByRole('row')).toHaveLength(3);
    await u.selectOptions(deliverTo(), W2.id);
    await u.click(save());
    await screen.findByText('Not saved');
    expect(screen.getByText('75.00 GBP')).toBeInTheDocument();
    expect(screen.getAllByRole('row')).toHaveLength(4);
  });

  it('po-4a-red.md M-2: without ListPurchaseOrderLines a Save reads no lines', async () => {
    const calls = stubFetch([json(200, MOVED), json(200, MOVED)]);
    const u = userEvent.setup();
    show({ lines: null, canReadLines: false });
    await u.selectOptions(deliverTo(), W2.id);
    await u.click(save());
    await waitFor(() => expect(region()).toHaveTextContent(SAVED));
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET']);
  });

  it('po-4a-red.md M-1: the warehouses could not be read, or a Retry is refused — the order’s own warehouse is still shown, as text', async () => {
    const a = show({ edit: { warehouses: { kind: 'server-error' } } });
    expect(screen.getByText('Main warehouse')).toBeInTheDocument();
    expect(screen.queryByRole('combobox')).toBeNull();
    a.unmount();
    stubFetch([problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    show({ edit: { warehouses: { kind: 'unreachable' } }, canOpenWarehouse: true });
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await screen.findByText('View warehouses');
    expect(screen.getByRole('link', { name: 'Main warehouse' })).toHaveAttribute('href', `/warehouses/${W1.id}`);
    expect(screen.queryByRole('combobox')).toBeNull();
  });

  it('po-4a-red.md L-3: the order’s own unlisted warehouse stays an option after another is chosen, so choosing it back is Nothing to save', async () => {
    const u = userEvent.setup();
    show({ order: { ...DRAFT, warehouse: W9 } });
    await u.selectOptions(deliverTo(), W1.id);
    expect(optionTexts()).toEqual(['Old site', 'MAIN — Main warehouse', 'EAST — East depot']);
    expect(save()).not.toHaveAccessibleDescription('Nothing to save.');
    await u.selectOptions(deliverTo(), W9.id);
    expect(save()).toHaveAccessibleDescription('Nothing to save.');
  });

  it('po-4a-red.md L-2: P8 then Restore on the same page — the editor is back at once with its first list (no refresh needed), holding the order’s warehouse', async () => {
    stubFetch([refused('purchase_order.is_archived', P8), json(200, { ...DRAFT, archived_at: '2026-03-04T11:00:00Z' }), json(200, DRAFT), json(200, DRAFT), LINES_REPLY()]);
    const u = userEvent.setup();
    show();
    await u.selectOptions(deliverTo(), W2.id);
    await u.click(save());
    await screen.findByText('Not saved');
    expect(screen.queryByRole('combobox')).toBeNull();
    await u.click(screen.getByRole('button', { name: 'Restore purchase order' }));
    await waitFor(() => expect(screen.queryByText(/This draft was deleted on/)).toBeNull());
    expect(screen.queryByText('Not saved')).toBeNull();
    expect(deliverTo()).toHaveValue(W1.id);
    expect(optionTexts()).toEqual(['MAIN — Main warehouse', 'EAST — East depot']);
  });

  it('a restore clears the stale sentence for good: a later reading that is not an editable draft never brings the old one back', async () => {
    stubFetch([
      refused('purchase_order.is_archived', P8),
      json(200, { ...DRAFT, archived_at: '2026-03-04T11:00:00Z' }),
      json(200, DRAFT),
      json(500, {}),
      LINES_REPLY(),
      json(200, { ...DRAFT, status: 'ordered', ordered_at: '2026-03-05T09:00:00Z' }),
    ]);
    const u = userEvent.setup();
    show();
    await u.selectOptions(deliverTo(), W2.id);
    await u.click(save());
    await screen.findByText(P8);
    // Restore answers 200, its re-read fails: the 200 body (a live draft) is shown with the region error's Retry.
    await u.click(screen.getByRole('button', { name: 'Restore purchase order' }));
    await waitFor(() => expect(screen.getByText(REREAD_FAILED)).toBeInTheDocument());
    // The Retry reads the order approved elsewhere meanwhile: read-only, and the P8 sentence stays gone.
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(screen.getByText("This order has been approved, so it can't be changed. It can still be cancelled.")).toBeInTheDocument());
    expect(screen.queryByText(P8)).toBeNull();
    expect(screen.queryByText('Not saved')).toBeNull();
  });
});


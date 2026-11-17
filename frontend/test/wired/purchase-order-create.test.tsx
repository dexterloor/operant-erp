import { StrictMode, useLayoutEffect, useState } from 'react';
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ push: vi.fn(), refresh: vi.fn(), replace: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ push: nav.push, refresh: nav.refresh, replace: nav.replace }), usePathname: () => '/purchase-orders', useSearchParams: () => new URLSearchParams() }));
import { leaveArrivalMessage, takeArrivalMessage } from '@/app/(app)/arrivalMessage';
import { BffProvider } from '@/app/(app)/BffProvider';
import { NewPurchaseOrderPage } from '@/app/(app)/purchase-orders/new/NewPurchaseOrderPage';
import { PurchaseOrderPage } from '@/app/(app)/purchase-orders/[id]/PurchaseOrderPage';
import { PurchaseOrdersList } from '@/app/(app)/purchase-orders/PurchaseOrdersList';
import { RouteFocus } from '@/components/shell/RouteFocus';
import { TooltipProvider } from '@/components/ui/Tooltip';
import type { PurchaseOrder } from '@/lib/api/purchasing';
import type { Supplier } from '@/lib/api/suppliers';
import type { Warehouse } from '@/lib/api/warehouses';
import type { WarehouseChoicesLoad } from '@/lib/purchasing/load';
import { SID, TZ, json, page, problem, refused, renderWired, stubFetch, type Recorded, type Reply } from './harness';

// Purchase orders PO-3 (purchase-orders-final-red.md BUILDER'S APPLY LIST PO-3; plan Revision 0 §2.4 + §4 PO-3, R1-5 PO-3;
// UX B-2 `NEW_PURCHASE_ORDER`, R1a-1 row 3; PO D-5 (a); b4-architecture.md Revision 10 R10-5 per final RED L-8):
// `/purchase-orders/new` creates a DRAFT, header first, with no lines. The supplier is chosen from a paged list of live
// suppliers in a dialog (the suppliers S-5 choice-list shape, suppliers-final-red.md M-3 steps 1–5); the warehouse from a
// select over the served live warehouses (`{code} — {name}`, no preselection); the order's currency is the chosen
// supplier's, sent by the page and shown as text. A fresh Idempotency-Key per submit; the body is exactly
// `{supplier_id, warehouse_id, currency_code}`, never `lines`. A 201 leaves "{number} was created as a draft." for the
// new order's page, which speaks it once after its h1 has focus; the form stays busy after a 201.

afterEach(() => {
  vi.unstubAllGlobals();
  vi.useRealTimers();
  nav.push.mockReset();
  takeArrivalMessage('');
});

const ACME_ID = '6a1b2c3d-0000-4000-8000-00000000a001';
const BOLT_ID = '6a1b2c3d-0000-4000-8000-00000000a002';
const MAIN_ID = '7b1c2d3e-0000-4000-8000-00000000b001';
const NORTH_ID = '7b1c2d3e-0000-4000-8000-00000000b002';
const NEW_ID = '8c1d2e3f-0000-4000-8000-00000000c001';
const ACME: Supplier = { id: ACME_ID, code: 'ACME', name: 'Acme Supplies', country_code: 'GB', currency_code: 'GBP', payment_terms_days: 30, archived_at: null };
const BOLT: Supplier = { id: BOLT_ID, code: 'BOLT', name: 'Bolt Ltd', country_code: 'DE', currency_code: 'EUR', payment_terms_days: 14, archived_at: null };
const MAIN: Warehouse = { id: MAIN_ID, code: 'MAIN', name: 'Main warehouse', country_code: 'GB', archived_at: null };
const NORTH: Warehouse = { id: NORTH_ID, code: 'NTH', name: 'North', country_code: 'GB', archived_at: null };
const WAREHOUSES: WarehouseChoicesLoad = { kind: 'ok', warehouses: [MAIN, NORTH] };
const CREATED: PurchaseOrder = {
  id: NEW_ID,
  order_number: 'PO-000123',
  status: 'draft',
  supplier: { id: ACME_ID, name: 'Acme Supplies' },
  warehouse: { id: MAIN_ID, name: 'Main warehouse' },
  currency_code: 'GBP',
  total_amount: '0.0000',
  ordered_at: null,
  expected_at: null,
  created_by: { id: 'u1', display_name: 'Ada Lovelace' },
  archived_at: null,
};

const SUPPLIERS = () => json(200, page([ACME, BOLT]));
const submit = () => screen.getByRole('button', { name: 'Create purchase order' });
const chooseButton = () => screen.getByRole('button', { name: 'Choose a supplier' });
const deliverTo = () => screen.getByRole('combobox', { name: 'Deliver to' });
const dialog = () => screen.getByRole('dialog', { name: 'Choose a supplier' });
const posts = (calls: Recorded[]) => calls.filter((c) => c.method === 'POST');
const gets = (calls: Recorded[]) => calls.filter((c) => c.method === 'GET');
const INTERRUPTED = "We can't tell whether the purchase order was created. Check the Draft list before you try again.";
const P2 = () => refused('purchase_order.order_number_is_unique_per_workspace', 'Another order was raised in this workspace at the same moment. Try again.');

const show = (warehouses: WarehouseChoicesLoad = WAREHOUSES) => renderWired(<NewPurchaseOrderPage warehouses={warehouses} />);

/** Opens the supplier dialog (its first read answered by the queue) and chooses `code`. */
async function choose(u: ReturnType<typeof userEvent.setup>, code = 'ACME') {
  await u.click(chooseButton());
  await u.click(within(await screen.findByRole('dialog', { name: 'Choose a supplier' })).getByRole('button', { name: `Choose ${code}` }));
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
}
async function fill(u: ReturnType<typeof userEvent.setup>, { supplier = 'ACME', warehouse = MAIN_ID }: { supplier?: string | null; warehouse?: string | null } = {}) {
  if (supplier !== null) await choose(u, supplier);
  if (warehouse !== null) await u.selectOptions(deliverTo(), warehouse);
}

describe('/purchase-orders/new — the form (UX B-2 NEW_PURCHASE_ORDER, R1a-1 row 3)', () => {
  it('h1 New purchase order; Supplier (Choose a supplier, with its hint), the currency before a supplier, Deliver to; no date field and no line table; Go back goes to the list', () => {
    show();
    expect(screen.getByRole('heading', { level: 1, name: 'New purchase order' })).toBeInTheDocument();
    const supplier = screen.getByRole('group', { name: 'Supplier' });
    expect(within(supplier).getByRole('button', { name: 'Choose a supplier' })).toHaveAccessibleDescription("Only suppliers that aren't archived can be chosen.");
    expect(screen.getByText('The currency is the supplier\'s, once you choose one.')).toBeInTheDocument();
    expect(deliverTo()).toHaveAccessibleDescription('The warehouse the goods are delivered to and received into.');
    // D-4 is not ruled: no expected date (plan §2.4; UX B-2 "only if D-4"). Header first: no lines on this page.
    expect(screen.queryByLabelText(/Expected/)).toBeNull();
    expect(screen.queryByRole('table')).toBeNull();
    expect(screen.queryAllByRole('textbox')).toEqual([]);
    expect(screen.getByRole('link', { name: 'Go back' })).toHaveAttribute('href', '/purchase-orders');
  });

  it('R10-5: Deliver to lists the served live warehouses as "{code} — {name}", in served order, after an unselected prompt, and preselects none; no label is an id', () => {
    show();
    const options = within(deliverTo()).getAllByRole('option');
    expect(options.map((o) => o.textContent)).toEqual(['Choose a warehouse', 'MAIN — Main warehouse', 'NTH — North']);
    expect(options.map((o) => (o as HTMLOptionElement).value)).toEqual(['', MAIN_ID, NORTH_ID]);
    expect(deliverTo()).toHaveValue('');
    expect(options.some((o) => o.hasAttribute('disabled'))).toBe(false);
    expect(options.some((o) => (o.textContent ?? '').includes(MAIN_ID) || (o.textContent ?? '').includes(NORTH_ID))).toBe(false);
  });

  // L-8's premise on create (po-3-progress.md M11): the select can hold only a listed id or the prompt's '' — a <select>'s
  // value is always one of its options' — and the list is replaced only by the field's Retry, which exists only while no
  // select does, or after a warehouse P4, whose re-read clears the choice when the new list lacks it (po-3-red.md L-1; the
  // P4 re-read test below). So no value here is ever unknown, and the "never its id" fallback is not reachable before
  // PO-4a's edit.
  it('an unlisted value cannot be chosen: the select keeps the prompt, and no option is added for it', () => {
    show();
    fireEvent.change(deliverTo(), { target: { value: 'not-a-listed-id' } });
    expect(deliverTo()).toHaveValue('');
    expect(within(deliverTo()).getAllByRole('option').map((o) => o.textContent)).toEqual(['Choose a warehouse', 'MAIN — Main warehouse', 'NTH — North']);
  });

  it('no live warehouse: the select holds the prompt only and the page says so', () => {
    show({ kind: 'ok', warehouses: [] });
    expect(within(deliverTo()).getAllByRole('option').map((o) => o.textContent)).toEqual(['Choose a warehouse']);
    expect(deliverTo()).toHaveAccessibleDescription(expect.stringContaining('No warehouse can be chosen: none has been created yet, or every one is archived. Warehouses are added and restored on the Warehouses page.'));
  });

  it('a failed first warehouse read: a region error in place of the select; Retry reads the LIVE warehouses again and the select appears', async () => {
    const calls = stubFetch([json(200, { items: [MAIN], tenant_has_rows: true })]);
    const u = userEvent.setup();
    show({ kind: 'server-error' });
    expect(screen.queryByRole('combobox', { name: 'Deliver to' })).toBeNull();
    expect(screen.getByText("The warehouses couldn't be loaded.")).toBeInTheDocument();
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(deliverTo()).toBeInTheDocument());
    expect(gets(calls)).toHaveLength(1);
    const url = new URL(gets(calls)[0]!.url, 'http://x');
    expect(url.pathname).toMatch(/\/bff\/api\/warehouses$/);
    expect([...url.searchParams.keys()]).toEqual([]);
    expect(within(deliverTo()).getAllByRole('option').map((o) => o.textContent)).toEqual(['Choose a warehouse', 'MAIN — Main warehouse']);
    // po-3-red.md M-2: the pressed Retry has gone with its region; the select that replaced it has focus, never <body>.
    expect(deliverTo()).toHaveFocus();
  });

  it('po-3-red.md L-2: a Retry answered 403 says which permission is missing, with no Retry to repeat it', async () => {
    stubFetch([problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    show({ kind: 'server-error' });
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    expect(await screen.findByText(/which you no longer have/)).toHaveTextContent('This needs the View warehouses permission, which you no longer have. Your workspace administrator can grant it.');
    expect(screen.queryByRole('button', { name: 'Retry' })).toBeNull();
    expect(screen.queryByText("The warehouses couldn't be loaded.")).toBeNull();
  });
});

describe('/purchase-orders/new — creating while the warehouses could not be loaded', () => {
  it('Create with a supplier chosen and no select: "Choose where it\'s delivered to." under the region error, focused, and no request', async () => {
    const calls = stubFetch([SUPPLIERS()]);
    const u = userEvent.setup();
    show({ kind: 'unreachable' });
    await choose(u);
    await u.click(submit());
    const sentence = await screen.findByText("Choose where it's delivered to.");
    expect(sentence).toHaveFocus();
    expect(posts(calls)).toHaveLength(0);
  });
});

describe('/purchase-orders/new — the supplier choice (the S-5 shape, suppliers-final-red.md M-3)', () => {
  it('Choose a supplier reads the first page of LIVE suppliers (no archived, no q, no cursor, no limit, no sort) and opens the dialog on its answer, focus in its search box', async () => {
    const calls = stubFetch([SUPPLIERS()]);
    const u = userEvent.setup();
    show();
    await u.click(chooseButton());
    const d = await screen.findByRole('dialog', { name: 'Choose a supplier' });
    expect(gets(calls)).toHaveLength(1);
    const url = new URL(gets(calls)[0]!.url, 'http://x');
    expect(url.pathname).toMatch(/\/bff\/api\/suppliers$/);
    expect([...url.searchParams.keys()]).toEqual([]);
    expect(within(d).getByRole('searchbox')).toHaveFocus();
    // po-3-red.md L-4 (UX R1b-1 archivedNote): inside the dialog the list says why an archived supplier is missing.
    expect(within(d).getByText("Archived suppliers aren't listed.")).toBeInTheDocument();
    expect(within(d).getAllByRole('row').slice(1).map((r) => within(r).getAllByRole('cell').slice(0, 3).map((c) => c.textContent))).toEqual([
      ['ACME', 'Acme Supplies', 'GBP'],
      ['BOLT', 'Bolt Ltd', 'EUR'],
    ]);
  });

  it('Choose: the dialog closes, the chosen supplier shows as "{code} — {name}" with Change, its currency is stated, and focus moves to Deliver to', async () => {
    stubFetch([SUPPLIERS()]);
    const u = userEvent.setup();
    show();
    await choose(u, 'BOLT');
    const supplier = screen.getByRole('group', { name: 'Supplier' });
    expect(within(supplier).getByText('BOLT — Bolt Ltd')).toBeInTheDocument();
    expect(within(supplier).getByRole('button', { name: 'Change supplier' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Choose a supplier' })).toBeNull();
    expect(screen.getByText("Currency: EUR, this supplier's. Costs on this order are in it, and it can't be changed later.")).toBeInTheDocument();
    expect(deliverTo()).toHaveFocus();
  });

  it('every open reads afresh: open, Go back, open → two reads, and the second answer is the one shown', async () => {
    const calls = stubFetch([SUPPLIERS(), json(200, page([BOLT]))]);
    const u = userEvent.setup();
    show();
    await u.click(chooseButton());
    await screen.findByRole('dialog', { name: 'Choose a supplier' });
    await u.click(within(dialog()).getByRole('button', { name: 'Go back' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(chooseButton()).toHaveFocus();
    await u.click(chooseButton());
    const d = await screen.findByRole('dialog', { name: 'Choose a supplier' });
    expect(gets(calls)).toHaveLength(2);
    expect(within(d).queryByRole('button', { name: 'Choose ACME' })).toBeNull();
    expect(within(d).getByRole('button', { name: 'Choose BOLT' })).toBeInTheDocument();
  });

  it('a submit event inside the dialog never reaches the form (the dialog is a sibling after the form in the React tree, not inside it)', async () => {
    stubFetch([SUPPLIERS()]);
    const u = userEvent.setup();
    show();
    await u.click(chooseButton());
    const d = await screen.findByRole('dialog', { name: 'Choose a supplier' });
    // React carries a synthetic submit through its own tree, portals included: inside the form it would run Create's checks.
    fireEvent.submit(within(d).getByRole('searchbox'));
    await new Promise((r) => setTimeout(r, 10));
    expect(screen.queryByText('Choose a supplier.')).toBeNull();
    expect(screen.queryByText("Choose where it's delivered to.")).toBeNull();
  });

  it('Enter in the dialog search sends no create (the list is never inside the form)', async () => {
    const calls = stubFetch([SUPPLIERS(), json(200, page([ACME])), json(201, CREATED)]);
    const u = userEvent.setup();
    show();
    await u.selectOptions(deliverTo(), MAIN_ID);
    await u.click(chooseButton());
    const d = await screen.findByRole('dialog', { name: 'Choose a supplier' });
    await u.type(within(d).getByRole('searchbox'), 'AC{Enter}');
    await new Promise((r) => setTimeout(r, 500));
    expect(posts(calls)).toHaveLength(0);
    expect(screen.getByRole('dialog', { name: 'Choose a supplier' })).toBeInTheDocument();
  });

  it('choose A, Change, Go back, Create → "Choose a supplier." and no request: Change clears the choice at once and reads the list afresh', async () => {
    const calls = stubFetch([SUPPLIERS(), SUPPLIERS(), json(201, CREATED)]);
    const u = userEvent.setup();
    show();
    await fill(u);
    await u.click(screen.getByRole('button', { name: 'Change supplier' }));
    expect(screen.queryByText('ACME — Acme Supplies')).toBeNull();
    await screen.findByRole('dialog', { name: 'Choose a supplier' });
    expect(gets(calls)).toHaveLength(2);
    await u.click(within(dialog()).getByRole('button', { name: 'Go back' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(screen.getByText('The currency is the supplier\'s, once you choose one.')).toBeInTheDocument();
    await u.click(submit());
    await waitFor(() => expect(chooseButton()).toHaveAccessibleDescription(expect.stringContaining('Choose a supplier.')));
    expect(posts(calls)).toHaveLength(0);
  });

  it('a failed first read opens the dialog on the region error, its Retry focused', async () => {
    stubFetch([problem(503, 'unavailable')]);
    const u = userEvent.setup();
    show();
    await u.click(chooseButton());
    const d = await screen.findByRole('dialog', { name: 'Choose a supplier' });
    expect(within(d).getByText("The suppliers couldn't be loaded.")).toBeInTheDocument();
    await waitFor(() => expect(within(d).getByRole('button', { name: 'Retry' })).toHaveFocus());
  });

  it('a 403 on the read (permission lost meanwhile) opens the dialog on the lost-permission sentence, Go back focused', async () => {
    stubFetch([problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    show();
    await u.click(chooseButton());
    const d = await screen.findByRole('dialog', { name: 'Choose a supplier' });
    expect(within(d).getByText(/which you no longer have/)).toHaveTextContent('This needs the View suppliers permission, which you no longer have. Your workspace administrator can grant it.');
    await waitFor(() => expect(within(d).getByRole('button', { name: 'Go back' })).toHaveFocus());
  });

  it('the dialog empty states: no supplier at all, every supplier archived, and a search with no match (with Clear search)', async () => {
    stubFetch([json(200, page([], { tenant_has_rows: false })), json(200, page([], { tenant_has_rows: true })), json(200, page([ACME])), json(200, page([]))]);
    const u = userEvent.setup();
    show();
    await u.click(chooseButton());
    expect(await within(await screen.findByRole('dialog')).findByText('No suppliers yet. A purchase order needs a supplier, and suppliers are added on the Suppliers page.')).toBeInTheDocument();
    await u.click(within(dialog()).getByRole('button', { name: 'Go back' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await u.click(chooseButton());
    expect(await within(await screen.findByRole('dialog')).findByText('No suppliers to choose from. Every supplier here is archived, and an archived supplier can be restored from the archived suppliers.')).toBeInTheDocument();
    await u.click(within(dialog()).getByRole('button', { name: 'Go back' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await u.click(chooseButton());
    const d = await screen.findByRole('dialog');
    await within(d).findByRole('button', { name: 'Choose ACME' });
    await u.type(within(d).getByRole('searchbox'), 'zz');
    expect(await within(d).findByText('No supplier\'s name or code starts with “zz”.')).toBeInTheDocument();
    expect(within(d).getByRole('button', { name: 'Clear search' })).toBeInTheDocument();
  });
});

describe('/purchase-orders/new — creating (plan Revision 0 §4 PO-3 tests)', () => {
  it('201: POSTs exactly {supplier_id, warehouse_id, currency_code = the chosen supplier\'s}, never lines or a date, with an Idempotency-Key; leaves "{number} was created as a draft." and navigates to the order', async () => {
    const calls = stubFetch([SUPPLIERS(), json(201, CREATED)]);
    const u = userEvent.setup();
    show();
    await fill(u);
    await u.click(submit());
    await waitFor(() => expect(nav.push).toHaveBeenCalledWith(`/purchase-orders/${NEW_ID}`));
    expect(nav.push).toHaveBeenCalledTimes(1);
    const post = posts(calls);
    expect(post).toHaveLength(1);
    expect(post[0]!.url).toMatch(/\/bff\/api\/purchase-orders$/);
    expect(JSON.parse(post[0]!.body!)).toEqual({ supplier_id: ACME_ID, warehouse_id: MAIN_ID, currency_code: 'GBP' });
    expect(post[0]!.headers.get('idempotency-key')).toMatch(/^[0-9a-f-]{36}$/);
    expect(takeArrivalMessage(`/purchase-orders/${NEW_ID}`)).toBe('PO-000123 was created as a draft.');
  });

  it('the currency sent is the one shown: choosing BOLT sends EUR', async () => {
    const calls = stubFetch([SUPPLIERS(), json(201, { ...CREATED, currency_code: 'EUR' })]);
    const u = userEvent.setup();
    show();
    await fill(u, { supplier: 'BOLT', warehouse: NORTH_ID });
    await u.click(submit());
    await waitFor(() => expect(nav.push).toHaveBeenCalled());
    expect(JSON.parse(posts(calls)[0]!.body!)).toEqual({ supplier_id: BOLT_ID, warehouse_id: NORTH_ID, currency_code: 'EUR' });
  });

  it('nothing chosen: "Choose a supplier." and "Choose where it\'s delivered to." beside their fields, focus on the first in screen order, and no request', async () => {
    const calls = stubFetch([json(201, CREATED)]);
    const u = userEvent.setup();
    show();
    await u.click(submit());
    await waitFor(() => expect(chooseButton()).toHaveFocus());
    expect(chooseButton()).toHaveAccessibleDescription(expect.stringContaining('Choose a supplier.'));
    expect(deliverTo()).toHaveAttribute('aria-invalid', 'true');
    expect(deliverTo()).toHaveAccessibleDescription(expect.stringContaining("Choose where it's delivered to."));
    expect(screen.queryByText('Not created')).toBeNull();
    expect(calls).toHaveLength(0);
  });

  it('a supplier but no warehouse: only "Choose where it\'s delivered to.", Deliver to focused, no request', async () => {
    const calls = stubFetch([SUPPLIERS(), json(201, CREATED)]);
    const u = userEvent.setup();
    show();
    await fill(u, { warehouse: null });
    await u.click(submit());
    await waitFor(() => expect(deliverTo()).toHaveAttribute('aria-invalid', 'true'));
    expect(deliverTo()).toHaveFocus();
    expect(screen.getByRole('button', { name: 'Change supplier' })).not.toHaveAccessibleDescription(expect.stringContaining('Choose a supplier.'));
    expect(posts(calls)).toHaveLength(0);
  });

  it('a second press with nothing chosen says it again: focus leaves and returns to the field, so its sentence is read again', async () => {
    stubFetch([]);
    const u = userEvent.setup();
    show();
    await u.click(submit());
    await waitFor(() => expect(chooseButton()).toHaveFocus());
    const focused: string[] = [];
    const record = (e: FocusEvent) => focused.push((e.target as HTMLElement).textContent ?? '');
    document.addEventListener('focusin', record);
    try {
      await u.click(submit());
      await waitFor(() => expect(chooseButton()).toHaveFocus());
    } finally {
      document.removeEventListener('focusin', record);
    }
    expect(focused[focused.length - 1]).toBe('Choose a supplier');
  });

  it('a fresh key for every submit: a lost answer says the interrupted sentence (check the Draft list); a second submit carries a new key, and the P2 race shows as sent under "Not created", the form kept', async () => {
    const keys: (string | null)[] = [];
    let n = 0;
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL, init: RequestInit = {}) => {
        if ((init.method ?? 'GET') === 'GET') return SUPPLIERS();
        keys.push(new Headers(init.headers).get('idempotency-key'));
        n += 1;
        if (n === 1) throw new TypeError('offline');
        void input;
        return P2();
      }),
    );
    const u = userEvent.setup();
    show();
    await fill(u);
    await u.click(submit());
    expect(await screen.findByText(INTERRUPTED)).toBeInTheDocument();
    expect(screen.getByText("We couldn't reach the server.")).toHaveFocus();
    await u.click(submit());
    expect(await screen.findByText('Another order was raised in this workspace at the same moment. Try again.')).toBeInTheDocument();
    expect(screen.getByText('Not created')).toHaveFocus();
    expect(keys).toHaveLength(2);
    expect(keys[0]).toMatch(/^[0-9a-f-]{36}$/);
    expect(keys[0]).not.toBe(keys[1]);
    expect(screen.getByText('ACME — Acme Supplies')).toBeInTheDocument();
    expect(deliverTo()).toHaveValue(MAIN_ID);
    expect(nav.push).not.toHaveBeenCalled();
  });

  it('P4 on the supplier (archived meanwhile): the server sentence beside the chosen supplier, Change focused, no banner', async () => {
    stubFetch([SUPPLIERS(), problem(422, 'validation-failed', { errors: [{ pointer: '/supplier_id', code: 'unknown_reference', message: 'No live supplier in this workspace has this id.' }] })]);
    const u = userEvent.setup();
    show();
    await fill(u);
    await u.click(submit());
    await waitFor(() => expect(screen.getByRole('button', { name: 'Change supplier' })).toHaveFocus());
    expect(screen.getByRole('button', { name: 'Change supplier' })).toHaveAccessibleDescription(expect.stringContaining('No live supplier in this workspace has this id.'));
    expect(screen.queryByText('Not created')).toBeNull();
  });

  it('P4 on the warehouse: the server sentence beside Deliver to, which takes focus; no banner', async () => {
    stubFetch([SUPPLIERS(), problem(422, 'validation-failed', { errors: [{ pointer: '/warehouse_id', code: 'unknown_reference', message: 'No live warehouse in this workspace has this id.' }] })]);
    const u = userEvent.setup();
    show();
    await fill(u);
    await u.click(submit());
    await waitFor(() => expect(deliverTo()).toHaveAttribute('aria-invalid', 'true'));
    expect(deliverTo()).toHaveFocus();
    expect(deliverTo()).toHaveAccessibleDescription(expect.stringContaining('No live warehouse in this workspace has this id.'));
    expect(screen.queryByText('Not created')).toBeNull();
  });

  it('po-3-red.md L-1: after a warehouse P4 the live list is read again; the refused warehouse leaves the list, the choice is cleared, the sentence stays, and no option is blank', async () => {
    const calls = stubFetch([SUPPLIERS(), problem(422, 'validation-failed', { errors: [{ pointer: '/warehouse_id', code: 'unknown_reference', message: 'No live warehouse in this workspace has this id.' }] }), json(200, { items: [NORTH], tenant_has_rows: true })]);
    const u = userEvent.setup();
    show();
    await fill(u);
    await u.click(submit());
    await waitFor(() => expect(within(deliverTo()).getAllByRole('option').map((o) => o.textContent)).toEqual(['Choose a warehouse', 'NTH — North']));
    expect(deliverTo()).toHaveValue('');
    expect(deliverTo()).toHaveAccessibleDescription(expect.stringContaining('No live warehouse in this workspace has this id.'));
    expect(within(deliverTo()).getAllByRole('option').some((o) => (o.textContent ?? '') === '')).toBe(false);
    const reread = gets(calls).filter((c) => /\/bff\/api\/warehouses/.test(c.url));
    expect(reread).toHaveLength(1);
    expect([...new URL(reread[0]!.url, 'http://x').searchParams.keys()]).toEqual([]);
  });

  it('po-3-red.md L-1: a re-read that still lists the chosen warehouse keeps it; a failed re-read keeps the list shown', async () => {
    const P4W = () => problem(422, 'validation-failed', { errors: [{ pointer: '/warehouse_id', code: 'unknown_reference', message: 'No live warehouse in this workspace has this id.' }] });
    stubFetch([SUPPLIERS(), P4W(), json(200, { items: [MAIN, NORTH], tenant_has_rows: true }), P4W(), problem(503, 'unavailable')]);
    const u = userEvent.setup();
    show();
    await fill(u);
    await u.click(submit());
    await waitFor(() => expect(deliverTo()).toHaveAttribute('aria-invalid', 'true'));
    await new Promise((r) => setTimeout(r, 20));
    expect(deliverTo()).toHaveValue(MAIN_ID);
    await u.click(submit());
    await new Promise((r) => setTimeout(r, 20));
    expect(deliverTo()).toHaveValue(MAIN_ID);
    expect(within(deliverTo()).getAllByRole('option').map((o) => o.textContent)).toEqual(['Choose a warehouse', 'MAIN — Main warehouse', 'NTH — North']);
    expect(screen.queryByText("The warehouses couldn't be loaded.")).toBeNull();
  });

  it('a 422 that names no field shows in the banner under "Not created"', async () => {
    stubFetch([SUPPLIERS(), problem(422, 'validation-failed', { errors: [{ pointer: '/currency_code', code: 'unknown_reference', message: 'This is not a currency this system knows.' }] })]);
    const u = userEvent.setup();
    show();
    await fill(u);
    await u.click(submit());
    expect(await screen.findByText('This is not a currency this system knows.')).toBeInTheDocument();
    expect(screen.getByText('Not created')).toHaveFocus();
  });

  it('a 403 names the Create purchase orders permission, under "Not created", focused', async () => {
    stubFetch([SUPPLIERS(), problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    show();
    await fill(u);
    await u.click(submit());
    expect(await screen.findByText(/which you no longer have/)).toHaveTextContent('This needs the Create purchase orders permission, which you no longer have. Your workspace administrator can grant it.');
    expect(screen.getByText('Not created')).toHaveFocus();
  });

  it('a malformed 201 body (no id) → "Something went wrong on our side." + the interrupted sentence; nothing navigates', async () => {
    const { id: _id, ...noId } = CREATED;
    void _id;
    stubFetch([SUPPLIERS(), json(201, noId)]);
    const u = userEvent.setup();
    show();
    await fill(u);
    await u.click(submit());
    expect(await screen.findByText(INTERRUPTED)).toBeInTheDocument();
    expect(screen.getByText('Something went wrong on our side.')).toHaveFocus();
    expect(nav.push).not.toHaveBeenCalled();
  });

  it('an in-flight submit is sent once: a second press and a submit event while busy send nothing', async () => {
    const calls = stubFetch([SUPPLIERS(), 'hang']);
    const u = userEvent.setup();
    show();
    await fill(u);
    await u.click(submit());
    await waitFor(() => expect(submit()).toHaveAttribute('aria-busy', 'true'));
    await u.click(submit());
    fireEvent.submit(submit().closest('form')!);
    await new Promise((r) => setTimeout(r, 10));
    expect(posts(calls)).toHaveLength(1);
  });
});

// The family-wide rule (957853b, fcc0566): `created` hands over to the page, which navigates; until the new page mounts the
// form stays busy, so nothing is sent again and no false "Not created" appears. Every other answer gives the form back.
describe('/purchase-orders/new — after a 201, until the order page takes over', () => {
  it('Create purchase order stays busy for a minute: a press and a submit event send nothing, nothing says "Not created", Go back still works', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const calls = stubFetch([SUPPLIERS(), json(201, CREATED), P2()]);
    const u = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    show();
    await fill(u);
    await u.click(submit());
    await waitFor(() => expect(nav.push).toHaveBeenCalledWith(`/purchase-orders/${NEW_ID}`));
    act(() => {
      vi.advanceTimersByTime(60_000);
    });
    expect(submit()).toHaveAttribute('aria-busy', 'true');
    await u.click(submit());
    fireEvent.submit(submit().closest('form')!);
    await new Promise((r) => setTimeout(r, 10));
    expect(posts(calls)).toHaveLength(1);
    expect(screen.queryByText('Not created')).toBeNull();
    const back = screen.getByRole('link', { name: 'Go back' });
    expect(back).toHaveAttribute('href', '/purchase-orders');
    expect(back.closest('fieldset:disabled, [inert]')).toBeNull();
  });

  const OTHER_ANSWERS: [string, () => Reply, string][] = [
    ['409 refused (P2)', P2, 'Not created'],
    // A supplier field error: a warehouse one would also re-read the warehouses (po-3-red.md L-1), its own tests above.
    ['422 field', () => problem(422, 'validation-failed', { errors: [{ pointer: '/supplier_id', code: 'unknown_reference', message: 'No live supplier in this workspace has this id.' }] }), 'No live supplier in this workspace has this id.'],
    ['422 banner-only', () => problem(422, 'validation-failed', { errors: [{ pointer: '', code: 'y', message: 'Out of form.' }] }), 'Not created'],
    [
      'lost connection',
      () => () => {
        throw new TypeError('offline');
      },
      "We couldn't reach the server.",
    ],
    ['500', () => problem(500, 'internal'), 'Something went wrong on our side.'],
    ['403', () => problem(403, 'permission-denied'), 'Not created'],
    ['404', () => problem(404, 'not-found'), 'Not created'],
  ];
  it.each(OTHER_ANSWERS)('any other answer gives the form back (%s): Create purchase order is not busy and a second press sends', async (_name, reply, sign) => {
    const calls = stubFetch([SUPPLIERS(), reply(), json(201, CREATED)]);
    const u = userEvent.setup();
    show();
    await fill(u);
    await u.click(submit());
    expect(await screen.findByText(sign)).toBeInTheDocument();
    expect(submit()).not.toHaveAttribute('aria-busy');
    await u.click(submit());
    await waitFor(() => expect(nav.push).toHaveBeenCalledWith(`/purchase-orders/${NEW_ID}`));
    expect(posts(calls)).toHaveLength(2);
  });
});

// A-19 item 7: the order page speaks the arrival message once, through its one region, after RouteFocus has moved focus
// to its h1 (the supplier/warehouse/item precedent).
describe.each([
  ['plain', false],
  ['StrictMode', true],
] as const)('/purchase-orders/[id] speaks "{number} was created as a draft." once, after the h1 has focus (%s)', (_name, strict) => {
  const controls: { go: (path: string) => void } = { go: () => {} };
  const go = (path: string) => controls.go(path);
  function Frame() {
    const [path, setPath] = useState('/purchase-orders/new');
    useLayoutEffect(() => {
      controls.go = setPath;
    }, []);
    return (
      <>
        <main id="main-content">
          {path === '/purchase-orders/new' ? (
            <h1 tabIndex={-1}>New purchase order</h1>
          ) : (
            <PurchaseOrderPage order={CREATED} lines={{ kind: 'ok', lines: [], nextCursor: null }} viewerId="u1" canRestore={false} canReadLines canOpenSupplier={false} canOpenWarehouse={false} canOpenItem={false} />
          )}
        </main>
        <RouteFocus routeKey={path} />
      </>
    );
  }
  const mount = () => {
    const tree = (
      <TooltipProvider>
        <BffProvider session={{ sid: SID, tz: TZ }} reload={() => {}}>
          <Frame />
        </BffProvider>
      </TooltipProvider>
    );
    return render(strict ? <StrictMode>{tree}</StrictMode> : tree);
  };

  it('the region holds the sentence once, and the h1 is the active element when it does; a later mount says nothing', async () => {
    const first = mount();
    leaveArrivalMessage(`/purchase-orders/${NEW_ID}`, 'PO-000123 was created as a draft.');
    const seen: { text: string; focusedH1: boolean }[] = [];
    const observer = new MutationObserver(() => {
      const r = document.querySelector('[role="status"]');
      if (r && r.textContent !== '') seen.push({ text: r.textContent ?? '', focusedH1: document.activeElement?.tagName === 'H1' });
    });
    observer.observe(document.body, { subtree: true, childList: true, characterData: true });
    act(() => go(`/purchase-orders/${NEW_ID}`));
    await waitFor(() => expect(seen.length).toBeGreaterThan(0));
    await new Promise((r) => setTimeout(r, 20));
    observer.disconnect();
    expect(seen).toEqual([{ text: 'PO-000123 was created as a draft.', focusedH1: true }]);
    expect(document.activeElement).toBe(screen.getByRole('heading', { level: 1, name: 'PO-000123 — Acme Supplies' }));
    first.unmount();
    mount();
    act(() => go(`/purchase-orders/${NEW_ID}`));
    await new Promise((r) => setTimeout(r, 20));
    expect(document.querySelector('[role="status"]')!.textContent).toBe('');
  });
});

describe('/purchase-orders — New purchase order and Create purchase order (UX B-1 newOrder, createOrder; R1a-1 row 3)', () => {
  const EMPTY = (tenantHasRows: boolean) => ({ kind: 'ok' as const, items: [], nextCursor: null, previousCursor: null, tenantHasRows });

  it('the live header links New purchase order to /purchase-orders/new for a creator, before Archived; none without', () => {
    const { unmount } = renderWired(<PurchaseOrdersList initial={EMPTY(true)} canCreate />);
    const link = screen.getByRole('link', { name: 'New purchase order' });
    expect(link).toHaveAttribute('href', '/purchase-orders/new');
    unmount();
    renderWired(<PurchaseOrdersList initial={EMPTY(true)} />);
    expect(screen.queryByRole('link', { name: 'New purchase order' })).toBeNull();
  });

  it('noneYet in its final form, with Create purchase order for a creator only', () => {
    const { unmount } = renderWired(<PurchaseOrdersList initial={EMPTY(false)} canCreate />);
    expect(screen.getByText('No purchase orders yet. A purchase order is what you ask a supplier for: it appears here as a draft when someone creates it.')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Create purchase order' })).toHaveAttribute('href', '/purchase-orders/new');
    unmount();
    renderWired(<PurchaseOrdersList initial={EMPTY(false)} />);
    expect(screen.getByText('No purchase orders yet. A purchase order is what you ask a supplier for: it appears here as a draft when someone creates it.')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Create purchase order' })).toBeNull();
  });

  it('emptyDraft in its PO-3 form (no "approved" before PO-8), with Create purchase order for a creator only', () => {
    const { unmount } = renderWired(<PurchaseOrdersList initial={EMPTY(true)} initialSegment="draft" canCreate />);
    expect(screen.getByText('No drafts. A new purchase order starts as a draft.')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Create purchase order' })).toHaveAttribute('href', '/purchase-orders/new');
    expect(document.body.textContent).not.toContain('approved');
    unmount();
    renderWired(<PurchaseOrdersList initial={EMPTY(true)} initialSegment="draft" />);
    expect(screen.queryByRole('link', { name: 'Create purchase order' })).toBeNull();
  });

  it('no Create purchase order on Open, Received, Cancelled, or the Archived view (whose header has no New purchase order either)', () => {
    for (const segment of ['open', 'received', 'cancelled'] as const) {
      const { unmount } = renderWired(<PurchaseOrdersList initial={EMPTY(true)} initialSegment={segment} canCreate />);
      expect(screen.queryByRole('link', { name: 'Create purchase order' })).toBeNull();
      unmount();
    }
    const { unmount } = renderWired(<PurchaseOrdersList initial={EMPTY(true)} initialArchived canCreate />);
    expect(screen.queryByRole('link', { name: 'Create purchase order' })).toBeNull();
    expect(screen.queryByRole('link', { name: 'New purchase order' })).toBeNull();
    unmount();
  });
});

import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ refresh: nav.refresh }) }));
import { SEARCH_SETTLE_MS } from '@/app/(app)/ArchivableList';
import { leaveArrivalMessage, takeArrivalMessage } from '@/app/(app)/arrivalMessage';
import { SupplierPage } from '@/app/(app)/suppliers/[id]/SupplierPage';
import type { Item } from '@/lib/api/items';
import type { Supplier, SupplierProduct } from '@/lib/api/suppliers';
import type { ReadAnswer } from '@/lib/bff/reads';
import { loadItemChoices, type CatalogueLoad, type ItemChoice } from '@/lib/suppliers/load';
import { held, json, page, problem, refused, renderWired, stubFetch, textsOf, type Recorded } from './harness';

// Suppliers S-5 — Add to catalogue (authority, in precedence: bk31-placement-confirm.md "Revision 1 — FINAL" FINAL (e)
// item 10 and FINAL (a) R1-B / R1-D / R1-E "With cost"; judge ruling 2 (suppliers-plan.md §R1-3); suppliers-final-red.md
// S-5 row: R-3, M-3, M-4, M-7, L-2, L-3; suppliers-plan.md Revision 1 — FINAL §R1-4 S-5 tests 1–8; suppliers-ux-copy.md
// Revision 1 — FINAL S-5 table). **Add to catalogue** (the section's header and its empty state) reads the first page of
// live items, then opens a dialog over a paged choice list (SKU · Name · Unit · Choose) that sits BEFORE the form, never in
// it. Choosing an item shows it with **Change** and puts focus on Supplier SKU; the chosen item's unit is shown after
// Minimum order and, as `per {code}`, after Unit cost. After a 201 the dialog closes, focus goes to the Catalogue heading,
// and the page's region speaks one composed sentence: "{sku} was added to the catalogue.", then the currency clause when
// the 201's currency is not the one the dialog labelled (the page then re-reads the supplier), then the unit clause when
// the 201's unit is not the chosen item's, then — only through `replaceIfCurrent`, when the section's re-read fails — the
// list-stale clause.

afterEach(() => {
  vi.unstubAllGlobals();
  nav.refresh.mockClear();
  takeArrivalMessage('');
});

const SID_ = '8c2d3e4f-5a6b-4c7d-8e9f-0a1b2c3d4e5f';
const ACME: Supplier = { id: SID_, code: 'ACME', name: 'Acme Supplies', country_code: 'GB', currency_code: 'GBP', payment_terms_days: 30, archived_at: null };
const ACME_EUR: Supplier = { ...ACME, currency_code: 'EUR' };
const ARCHIVED: Supplier = { ...ACME, archived_at: '2026-03-04T10:00:00Z' };
const itemOf = (n: number, sku: string, name: string, unit: string): Item => ({
  id: `7a0b1c2d-3e4f-4a5b-8c6d-7e8f9a0b1c${String(n).padStart(2, '0')}`,
  sku,
  name,
  unit_of_measure_code: unit,
  reorder_point: '0.000000',
  reorder_quantity: '0.000000',
  safety_stock: '0.000000',
  supplier: null,
  archived_at: null,
  holds_stock: false,
});
// The changed-unit fixture (bk31-placement-confirm.md FINAL (e) item 10, L-5): NEW-1 is an item NO `UnitInUseLegs` table
// names — no stock level, movement, order line or catalogue entry on any supplier (it is in no catalogue on this page) —
// so a unit change on it is accepted by the server, which is the only way the 201's unit can differ from the chosen one.
const NEW1 = itemOf(1, 'NEW-1', 'New widget', 'EA');
const BOLT = itemOf(2, 'BOLT-10', 'Bolt 10mm', 'KG');
const NUT = itemOf(3, 'NUT-5', 'Nut 5mm', 'BOX');
const choiceOf = (i: Item): ItemChoice => ({ id: i.id, sku: i.sku, name: i.name, unit_of_measure_code: i.unit_of_measure_code, archived_at: i.archived_at });

const entry = (id: string, sku: string, over: Partial<SupplierProduct> = {}): SupplierProduct => ({
  id,
  supplier_sku: sku,
  inventory_item: { id: `9a0b1c2d-3e4f-4a5b-8c6d-7e8f9a0b1c${id.slice(-2)}`, sku: `SKU-${sku}`, name: `Item ${sku}` },
  unit_cost: '10.0000',
  currency_code: 'GBP',
  lead_time_days: 7,
  minimum_order_quantity: '2.500000',
  unit_of_measure_code: 'EA',
  ...over,
});
const A = entry('e0000000-0000-4000-8000-000000000001', 'A-100');
/** The 201 body for an item: what the server answers for the typed values (the unit and currency are the server's). */
const created = (i: Item, over: Partial<SupplierProduct> = {}): SupplierProduct => ({
  id: 'e0000000-0000-4000-8000-000000000009',
  supplier_sku: 'S-1',
  inventory_item: { id: i.id, sku: i.sku, name: i.name },
  unit_cost: '12.5000',
  currency_code: 'GBP',
  lead_time_days: 7,
  minimum_order_quantity: '1.000000',
  unit_of_measure_code: i.unit_of_measure_code,
  ...over,
});
const ok = (entries: SupplierProduct[]): CatalogueLoad => ({ kind: 'ok', entries, nextCursor: null });
const answer = (entries: SupplierProduct[]) => json(200, { items: entries, next_cursor: null, previous_cursor: null, tenant_has_rows: entries.length > 0 });
const items = (rows: Item[], over: Record<string, unknown> = {}) => json(200, page(rows, over));
const violation = (pointer: string, message: string, code = 'out_of_range') => problem(422, 'validation-failed', { errors: [{ pointer, code, message }] });

const show = (initial: CatalogueLoad, { supplier = ACME, canEditSupplier = false, canRestore = false, canEdit = false, canAdd = true } = {}) =>
  renderWired(<SupplierPage initial={supplier} canEdit={canEditSupplier} canRestore={canRestore} catalogue={{ initial, canOpenItem: false, canEdit, canAdd }} />);
const pageRegion = () => document.querySelector<HTMLElement>('section > [role="status"]')!;
const section = () => screen.getByRole('region', { name: 'Catalogue' });
const catalogueHeading = () => within(section()).getByRole('heading', { level: 2, name: 'Catalogue' });
const rows = () => within(section()).queryAllByRole('row').slice(1);
const cells = (row: HTMLElement) => within(row).getAllByRole('cell').map((c) => c.textContent);
const addButtons = () => within(section()).queryAllByRole('button', { name: 'Add to catalogue' });
const dialog = () => screen.getByRole('dialog');
const searchBox = () => within(dialog()).getByRole('searchbox', { name: 'Search items by SKU' });
const field = (name: string) => within(dialog()).getByRole('textbox', { name });
const bodyOf = (c: Recorded) => JSON.parse(c.body ?? 'null') as Record<string, unknown>;
const path = (c: Recorded) => c.url.replace(/^.*\/bff\/api/, '');
const methods = (calls: Recorded[]) => calls.map((c) => `${c.method} ${path(c).replace(/\?.*$/, '')}`);
const settle = () => act(() => new Promise((r) => setTimeout(r, SEARCH_SETTLE_MS + 100)));

type U = ReturnType<typeof userEvent.setup>;
/** Press the section's first **Add to catalogue** and wait for the dialog (it opens once the first items read answers). */
const openAdd = async (u: U) => {
  await u.click(addButtons()[0]!);
  return screen.findByRole('dialog');
};
const choose = (u: U, sku: string) => u.click(within(dialog()).getByRole('button', { name: `Choose ${sku}` }));
const setField = async (u: U, name: string, value: string) => {
  const el = field(name);
  await u.clear(el);
  if (value !== '') await u.type(el, value);
};
/** The four fields, as a person types them. */
const fill = async (u: U, { sku = 'S-1', cost = '12.5', lead = '7', moq = '1', currency = 'GBP' } = {}) => {
  await setField(u, 'Supplier SKU', sku);
  await setField(u, `Unit cost (${currency})`, cost);
  await setField(u, 'Lead time (days)', lead);
  await setField(u, 'Minimum order quantity', moq);
};
const submit = (u: U) => u.click(within(dialog()).getByRole('button', { name: 'Add to catalogue' }));
const created201 = (body: SupplierProduct) => json(201, body);

const AFTER = (sku: string) => `${sku} was added to the catalogue.`;
const CURRENCY = (c: string) => `Its cost is recorded in ${c}, because this supplier's currency changed.`;
// R1-E "With cost" at S-5: its first two sentences (option A, UX CONFIRMED, ux-r1e-confirm.md). The third,
// which names Remove, is appended by S-6, the commit that ships Remove, and this pin moves there.
const UNIT = (label: string) =>
  `The item's unit was changed to ${label} while you were adding it, so the minimum order and unit cost you entered are now in that unit. Check them and edit the entry if they're wrong.`;
const STALE = "We couldn't load the list again, so it may be out of date.";
const MOQ_HINT = "The smallest amount this supplier accepts in one order, in the item's unit. If this is the item's preferred supplier, reorder suggestions never suggest less than this. Purchase orders don't check it. Enter 1 if there's no minimum.";
const COST_HINT = "What this supplier charges for one of the item's units. Use a point for decimals and no commas, for example 12.50.";
const ARCHIVED_ON_CREATE = "This supplier is archived, so nothing can be added to its catalogue until it's restored.";
const ITEM_LISTED = 'This supplier already lists this item. Change its terms instead.';
const ITEM_UNKNOWN = 'No live inventory item in this workspace has this id.';
const LEAD_TIME_RANGE = 'Lead time must be a whole number of days, 0 or more.';
const S5 = "This supplier is archived, so it can't be changed until it's restored.";
const EMPTY_NOTE = "Add the items this supplier sells you, with what each costs and how long it takes to arrive. Only items in a supplier's catalogue can go on that supplier's purchase orders.";

describe('S-5 — where Add to catalogue is offered (plan §2.3, test 1)', () => {
  it('in the section header and in the empty state, with the empty note, for an Add seat on a live supplier', () => {
    show(ok([]));
    expect(addButtons()).toHaveLength(2);
    expect(within(section()).getByText("This supplier's catalogue is empty.")).toBeInTheDocument();
    expect(within(section()).getByText(EMPTY_NOTE)).toBeInTheDocument();
  });

  it('with rows: the header button only', () => {
    show(ok([A]));
    expect(addButtons()).toHaveLength(1);
    expect(within(section()).queryByText(EMPTY_NOTE)).toBeNull();
  });

  it('no Add and no empty note without the Add seat (CreateSupplierProduct and ListItems), nor on an archived supplier', () => {
    const { unmount } = show(ok([]), { canAdd: false });
    expect(addButtons()).toEqual([]);
    expect(within(section()).queryByText(EMPTY_NOTE)).toBeNull();
    expect(within(section()).getByText("This supplier's catalogue is empty.")).toBeInTheDocument();
    unmount();
    show(ok([]), { supplier: ARCHIVED });
    expect(addButtons()).toEqual([]);
    expect(within(section()).queryByText(EMPTY_NOTE)).toBeNull();
  });
});

describe('S-5 — opening the dialog (final RED M-3 steps 1–2)', () => {
  it('the press reads the first page of live items with the button loading and no dialog yet; the dialog opens on the answer, focus on its search box', async () => {
    const r = held();
    const calls = stubFetch([r.reply]);
    const u = userEvent.setup();
    show(ok([A]));
    await u.click(addButtons()[0]!);
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(addButtons()[0]).toHaveAttribute('aria-busy', 'true');
    expect(calls.map(path)).toEqual(['/items']);
    await act(async () => r.release(items([NEW1, BOLT])));
    const d = await screen.findByRole('dialog');
    expect(within(d).getByRole('heading', { name: "Add an item to ACME's catalogue" })).toBeInTheDocument();
    await waitFor(() => expect(document.activeElement).toBe(searchBox()));
  });

  // M-3 test 1: every open reads afresh; nothing from an earlier open is kept.
  it('open, close, open → two ListItems reads, the second\'s rows shown', async () => {
    const calls = stubFetch([items([NEW1]), items([BOLT])]);
    const u = userEvent.setup();
    show(ok([A]));
    await openAdd(u);
    expect(within(dialog()).getByRole('button', { name: 'Choose NEW-1' })).toBeInTheDocument();
    await u.click(within(dialog()).getByRole('button', { name: 'Cancel' }));
    expect(screen.queryByRole('dialog')).toBeNull();
    await openAdd(u);
    expect(within(dialog()).getByRole('button', { name: 'Choose BOLT-10' })).toBeInTheDocument();
    expect(within(dialog()).queryByRole('button', { name: 'Choose NEW-1' })).toBeNull();
    expect(calls.map(path)).toEqual(['/items', '/items']);
  });

  // M-3 test 5: a failed first read opens the dialog with the core's region error, its Retry focused.
  it('initial 503 → the region error inside the dialog, Retry focused; Retry reads the list', async () => {
    stubFetch([json(503, {}), items([NEW1])]);
    const u = userEvent.setup();
    show(ok([A]));
    const d = await openAdd(u);
    expect(within(d).getByText("The items couldn't be loaded.")).toBeInTheDocument();
    await waitFor(() => expect(document.activeElement).toBe(within(d).getByRole('button', { name: 'Retry' })));
    await u.click(within(d).getByRole('button', { name: 'Retry' }));
    expect(await within(d).findByRole('button', { name: 'Choose NEW-1' })).toBeInTheDocument();
  });

  // M-4: `ITEM_CHOICES.denied` (CONFIRMS SUPPLIER_FORBIDDEN), and M-3 step 2: Cancel focused.
  it('initial 403 → the denied sentence naming ListItems\' permission, Cancel focused', async () => {
    stubFetch([problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    show(ok([A]));
    const d = await openAdd(u);
    expect(d).toHaveTextContent('This needs the View items permission, which you no longer have. Your workspace administrator can grant it.');
    await waitFor(() => expect(document.activeElement).toBe(within(d).getByRole('button', { name: 'Cancel' })));
  });

  // L-3: Escape closes the whole dialog from any field, the search included.
  it('L-3 — Escape in the search with text typed → the dialog closes, no further request', async () => {
    const calls = stubFetch([items([NEW1])]);
    const u = userEvent.setup();
    show(ok([A]));
    await openAdd(u);
    await u.type(searchBox(), 'NE');
    await u.keyboard('{Escape}');
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(calls.map(path)).toEqual(['/items']);
  });

  // M-3 test 2: the list sits before the form, never inside it, so Enter in its search submits nothing.
  it('Enter in the search with "AB" typed → no CreateSupplierProduct request', async () => {
    const calls = stubFetch([items([NEW1])]);
    const u = userEvent.setup();
    show(ok([A]));
    await openAdd(u);
    await u.type(searchBox(), 'AB{Enter}');
    expect(calls.filter((c) => c.method === 'POST')).toEqual([]);
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(searchBox().closest('form')).toBeNull();
  });
});

describe('S-5 — the item choice list (UX S-5 ITEM_CHOICES; final RED M-4)', () => {
  it('heading, search label and hint, archived note, columns SKU · Name · Unit · Choose, unit cells by label, one status region', async () => {
    stubFetch([items([NEW1, BOLT])]);
    const u = userEvent.setup();
    show(ok([A]));
    const d = await openAdd(u);
    expect(within(d).getByRole('heading', { name: 'Choose an item' })).toBeInTheDocument();
    expect(searchBox()).toHaveAttribute('placeholder', 'Start of a SKU');
    expect(searchBox()).toHaveAccessibleDescription("Item names can't be searched. SKUs are matched as typed, so capitals and spaces count.");
    expect(within(d).getByText("Archived items aren't listed.")).toBeInTheDocument();
    expect(within(d).getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['SKU', 'Name', 'Unit', 'Choose']);
    const r = within(d).getAllByRole('row').slice(1);
    expect(r.map((x) => within(x).getAllByRole('cell').map((c) => c.textContent))).toEqual([
      ['NEW-1', 'New widget', 'Each (EA)', 'Choose'],
      ['BOLT-10', 'Bolt 10mm', 'Kilogram (KG)', 'Choose'],
    ]);
    // Plan test 8 (dom-fitness): one region per list — the dialog's list holds one; the page keeps its own.
    expect(within(d).getAllByRole('status')).toHaveLength(1);
  });

  // M-4 test 1: an empty live answer with `tenant_has_rows: true` is "every item is archived", never "no items yet".
  it('an empty answer with tenant_has_rows true → allArchived; false → noneYet', async () => {
    stubFetch([items([], { tenant_has_rows: true }), items([], { tenant_has_rows: false })]);
    const u = userEvent.setup();
    show(ok([A]));
    let d = await openAdd(u);
    expect(within(d).getByText('No items to choose from. Every item here is archived.')).toBeInTheDocument();
    expect(within(d).queryByText('No items yet.')).toBeNull();
    await u.click(within(d).getByRole('button', { name: 'Cancel' }));
    d = await openAdd(u);
    expect(within(d).getByText('No items yet.')).toBeInTheDocument();
  });

  it('a search: the read sends q as typed and no cursor, the region names the query; no match → noMatch with Clear search', async () => {
    const calls = stubFetch([items([NEW1, BOLT]), items([NEW1]), items([], { tenant_has_rows: true })]);
    const u = userEvent.setup();
    show(ok([A]));
    const d = await openAdd(u);
    await u.type(searchBox(), 'NE');
    await settle();
    await waitFor(() => expect(within(d).getByRole('status').textContent).toBe('1 item shown. SKU starts with “NE”.'));
    await u.type(searchBox(), 'X');
    await settle();
    // The empty state's heading, and the region speaking it (ST-2a).
    await waitFor(() => expect(within(d).getAllByText('No item\'s SKU starts with “NEX”.')).toHaveLength(2));
    expect(within(d).getByRole('status').textContent).toBe('No item\'s SKU starts with “NEX”.');
    expect(within(d).getByRole('button', { name: 'Clear search' })).toBeInTheDocument();
    expect(calls.map((c) => path(c))).toEqual(['/items', '/items?q=NE', '/items?q=NEX']);
  });

  // Plan test 2: q as typed, never limit, never archived, one cursor, none on a new search.
  it('test 2 — Next carries one cursor and nothing else; a new search drops it', async () => {
    const calls = stubFetch([items([NEW1, BOLT], { next_cursor: 'c-2' }), items([NUT], { previous_cursor: 'c-1' }), items([NUT])]);
    const u = userEvent.setup();
    show(ok([A]));
    const d = await openAdd(u);
    await u.click(within(d).getByRole('button', { name: 'Next' }));
    await waitFor(() => expect(within(d).getByRole('status').textContent).toBe('1 item shown, NUT-5.'));
    await u.type(searchBox(), 'N U');
    await settle();
    await waitFor(() => expect(calls).toHaveLength(3));
    expect(calls.map((c) => path(c))).toEqual(['/items', '/items?after=c-2', '/items?q=N+U']);
    for (const c of calls) {
      const q = new URL(c.url, 'http://x').searchParams;
      expect(q.has('limit')).toBe(false);
      expect(q.has('archived')).toBe(false);
    }
  });

  it('pageShown names the range of SKUs: "2 items shown, NEW-1 to BOLT-10."', async () => {
    stubFetch([items([NUT], { next_cursor: 'c-2' }), items([NEW1, BOLT], { previous_cursor: 'c-1' })]);
    const u = userEvent.setup();
    show(ok([A]));
    const d = await openAdd(u);
    await u.click(within(d).getByRole('button', { name: 'Next' }));
    await waitFor(() => expect(within(d).getByRole('status').textContent).toBe('2 items shown, NEW-1 to BOLT-10.'));
  });
});

describe('S-5 — loadItemChoices validation (plan test 3; final RED M-4, M-7)', () => {
  const reader = (body: unknown) => {
    const seen: { op: string; query: string }[] = [];
    const read = async (op: string, req: { query?: URLSearchParams } = {}): Promise<ReadAnswer> => {
      seen.push({ op, query: req.query?.toString() ?? '' });
      return { kind: 'ok', body };
    };
    return { read, seen };
  };

  it('a whole answer → the five chosen members of each row, the cursors and tenantHasRows', async () => {
    const { read, seen } = reader(page([NEW1, BOLT], { next_cursor: 'c-2' }));
    const r = await loadItemChoices(read, { q: 'B', cursor: null });
    expect(r).toEqual({ kind: 'ok', items: [NEW1, BOLT], nextCursor: 'c-2', previousCursor: null, tenantHasRows: true });
    expect(seen).toEqual([{ op: 'ListItems', query: 'q=B' }]);
    // M-7: the fixture is declared ItemChoice (a Pick of Item), never cast.
    const c: ItemChoice = choiceOf(BOLT);
    expect(c.unit_of_measure_code).toBe('KG');
  });

  it('test 3 — a row missing sku → server-error', async () => {
    const noSku: Record<string, unknown> = { ...NEW1 };
    delete noSku.sku;
    expect(await loadItemChoices(reader(page([noSku])).read, { q: '', cursor: null })).toEqual({ kind: 'server-error' });
  });

  it('a row without a string unit_of_measure_code → server-error', async () => {
    expect(await loadItemChoices(reader(page([{ ...NEW1, unit_of_measure_code: 3 }])).read, { q: '', cursor: null })).toEqual({ kind: 'server-error' });
  });

  // M-4 test 2.
  it('an answer without tenant_has_rows → server-error', async () => {
    const noFlag: Record<string, unknown> = page([NEW1]);
    delete noFlag.tenant_has_rows;
    expect(await loadItemChoices(reader(noFlag).read, { q: '', cursor: null })).toEqual({ kind: 'server-error' });
  });

  it('a failure passes through as its kind', async () => {
    const read = async (): Promise<ReadAnswer> => ({ kind: 'forbidden' });
    expect(await loadItemChoices(read, { q: '', cursor: null })).toEqual({ kind: 'forbidden' });
  });
});

describe('S-5 — Choose and Change (final RED M-3 steps 4–5)', () => {
  // M-3 test 3.
  it('Choose → the list goes, the chosen line with Change shows, focus is on Supplier SKU, nothing is announced', async () => {
    stubFetch([items([NEW1, BOLT])]);
    const u = userEvent.setup();
    leaveArrivalMessage(`/suppliers/${SID_}`, 'ACME was created.');
    show(ok([A]));
    await waitFor(() => expect(pageRegion().textContent).toBe('ACME was created.'));
    const d = await openAdd(u);
    await choose(u, 'BOLT-10');
    expect(within(d).queryByRole('table')).toBeNull();
    expect(within(d).getByText('Item: BOLT-10 — Bolt 10mm')).toBeInTheDocument();
    expect(within(d).getByRole('button', { name: 'Change item' })).toHaveTextContent('Change');
    expect(document.activeElement).toBe(field('Supplier SKU'));
    expect(pageRegion().textContent).toBe('ACME was created.');
  });

  // M-3 test 4 and step 5: Change clears the chosen item at once, then reads the list afresh, focus on its search box.
  it('choose A, Change, Add → itemRequired, no request; Change read the list again, focus on its search box', async () => {
    const calls = stubFetch([items([NEW1, BOLT]), items([NEW1, BOLT])]);
    const u = userEvent.setup();
    show(ok([A]));
    await openAdd(u);
    await choose(u, 'NEW-1');
    await fill(u);
    await u.click(within(dialog()).getByRole('button', { name: 'Change item' }));
    expect(within(dialog()).queryByText('Item: NEW-1 — New widget')).toBeNull();
    await waitFor(() => expect(document.activeElement).toBe(searchBox()));
    await submit(u);
    expect(within(dialog()).getByText('Choose an item for this catalogue.')).toBeInTheDocument();
    expect(methods(calls)).toEqual(['GET /items', 'GET /items']);
  });

  it('Change while its read is in flight: a submit is refused itemRequired, no request', async () => {
    const r = held();
    const calls = stubFetch([items([NEW1]), r.reply]);
    const u = userEvent.setup();
    show(ok([A]));
    await openAdd(u);
    await choose(u, 'NEW-1');
    await fill(u);
    await u.click(within(dialog()).getByRole('button', { name: 'Change item' }));
    await submit(u);
    expect(within(dialog()).getByText('Choose an item for this catalogue.')).toBeInTheDocument();
    expect(methods(calls)).toEqual(['GET /items', 'GET /items']);
    await act(async () => r.release(items([BOLT])));
    expect(await within(dialog()).findByRole('button', { name: 'Choose BOLT-10' })).toBeInTheDocument();
  });
});

describe('S-5 — the fields (plan tests 4, 5, 8; FINAL (a) R1-B, R1-D)', () => {
  // Plan test 4.
  it('test 4 — nothing chosen → itemRequired; every number field empty to start, each required, no request', async () => {
    const calls = stubFetch([items([NEW1])]);
    const u = userEvent.setup();
    show(ok([A]));
    const d = await openAdd(u);
    await submit(u);
    expect(within(d).getByText('Choose an item for this catalogue.')).toBeInTheDocument();
    await choose(u, 'NEW-1');
    // suppliers-s5-red.md L-1: a press with nothing chosen files only itemRequired; the fields it never showed are not
    // marked invalid when they appear.
    for (const t of within(d).getAllByRole('textbox')) expect(t).not.toHaveAttribute('aria-invalid');
    expect(within(d).queryByText('Enter the unit cost, or 0.')).toBeNull();
    expect(within(d).getAllByRole('textbox').map((t) => [t.getAttribute('name'), (t as HTMLInputElement).value])).toEqual([
      ['supplier_sku', ''],
      ['unit_cost', ''],
      ['lead_time_days', ''],
      ['minimum_order_quantity', ''],
    ]);
    await setField(u, 'Supplier SKU', 'S-1');
    await setField(u, 'Unit cost (GBP)', '12.5');
    await setField(u, 'Lead time (days)', '7');
    await submit(u);
    expect(field('Minimum order quantity')).toHaveAccessibleDescription(expect.stringContaining("Enter the minimum order, or 1 if there's none."));
    expect(document.activeElement).toBe(field('Minimum order quantity'));
    expect(methods(calls)).toEqual(['GET /items']);
  });

  it('every number field emptied: each named sentence beside its field, focus on the first', async () => {
    const calls = stubFetch([items([NEW1])]);
    const u = userEvent.setup();
    show(ok([A]));
    await openAdd(u);
    await choose(u, 'NEW-1');
    await setField(u, 'Supplier SKU', 'S-1');
    await submit(u);
    expect(field('Unit cost (GBP)')).toHaveAccessibleDescription(expect.stringContaining('Enter the unit cost, or 0.'));
    expect(field('Lead time (days)')).toHaveAccessibleDescription(expect.stringContaining('Enter a number of days, or 0.'));
    expect(field('Minimum order quantity')).toHaveAccessibleDescription(expect.stringContaining("Enter the minimum order, or 1 if there's none."));
    expect(document.activeElement).toBe(field('Unit cost (GBP)'));
    expect(methods(calls)).toEqual(['GET /items']);
  });

  // Plan test 5 + FINAL (e) item 10: the unit shown is the CHOSEN item's.
  it('test 5 — choose a KG item: Minimum order shown "KG" spoken "kilograms."; Unit cost shown "per KG" spoken "per kilogram."; the R1-B and per-unit hints', async () => {
    stubFetch([items([NEW1, BOLT])]);
    const u = userEvent.setup();
    show(ok([A]));
    await openAdd(u);
    await choose(u, 'BOLT-10');
    const moq = field('Minimum order quantity');
    const cost = field('Unit cost (GBP)');
    expect(moq.parentElement!.querySelector('[aria-hidden="true"]')?.textContent).toBe('KG');
    expect(cost.parentElement!.querySelector('[aria-hidden="true"]')?.textContent).toBe('per KG');
    expect(moq).toHaveAccessibleDescription(`kilograms. ${MOQ_HINT}`);
    expect(cost).toHaveAccessibleDescription(`per kilogram. ${COST_HINT}`);
  });

  it('choose an EA item then Change to a BOX item: the suffixes follow the new choice', async () => {
    stubFetch([items([NEW1, NUT]), items([NEW1, NUT])]);
    const u = userEvent.setup();
    show(ok([A]));
    await openAdd(u);
    await choose(u, 'NEW-1');
    expect(field('Minimum order quantity').parentElement!.querySelector('[aria-hidden="true"]')?.textContent).toBe('EA');
    expect(field('Unit cost (GBP)')).toHaveAccessibleDescription(`each. ${COST_HINT}`);
    await u.click(within(dialog()).getByRole('button', { name: 'Change item' }));
    await choose(u, 'NUT-5');
    expect(field('Minimum order quantity').parentElement!.querySelector('[aria-hidden="true"]')?.textContent).toBe('BOX');
    expect(field('Minimum order quantity')).toHaveAccessibleDescription(`boxes. ${MOQ_HINT}`);
    expect(field('Unit cost (GBP)')).toHaveAccessibleDescription(`per box. ${COST_HINT}`);
  });

  it('the Unit cost label names the SUPPLIER\'s currency', async () => {
    stubFetch([items([NEW1])]);
    const u = userEvent.setup();
    show(ok([A]), { supplier: { ...ACME, currency_code: 'JPY' } });
    await openAdd(u);
    await choose(u, 'NEW-1');
    expect(field('Unit cost (JPY)')).toHaveValue('');
  });

  // Plan test 8 (JSON types) + the body: five members copied by name, the chosen item's id, never its unit.
  it('test 8 — the body: the chosen item id, the cost and minimum as typed strings, lead time a number; never the unit', async () => {
    const calls = stubFetch([items([NEW1]), created201(created(NEW1)), answer([A, created(NEW1)])]);
    const u = userEvent.setup();
    show(ok([A]));
    await openAdd(u);
    await choose(u, 'NEW-1');
    await fill(u, { cost: '12.50', lead: '14', moq: '2.5' });
    await submit(u);
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(methods(calls)).toEqual(['GET /items', `POST /suppliers/${SID_}/products`, `GET /suppliers/${SID_}/products`]);
    expect(bodyOf(calls[1]!)).toEqual({ inventory_item_id: NEW1.id, supplier_sku: 'S-1', unit_cost: '12.50', lead_time_days: 14, minimum_order_quantity: '2.5' });
  });

  it('lead time "7.5" is sent as typed; the server\'s sentence shows beside the field, which takes focus', async () => {
    const calls = stubFetch([items([NEW1]), violation('/lead_time_days', LEAD_TIME_RANGE)]);
    const u = userEvent.setup();
    show(ok([A]));
    await openAdd(u);
    await choose(u, 'NEW-1');
    await fill(u, { lead: '7.5' });
    await submit(u);
    await waitFor(() => expect(field('Lead time (days)')).toHaveAccessibleDescription(expect.stringContaining(LEAD_TIME_RANGE)));
    expect(bodyOf(calls[1]!).lead_time_days).toBe('7.5');
    expect(document.activeElement).toBe(field('Lead time (days)'));
    expect(screen.getByRole('dialog')).toBeInTheDocument();
  });

  it('a fresh Idempotency-Key for every submit', async () => {
    const calls = stubFetch([items([NEW1]), json(503, {}), created201(created(NEW1)), answer([A, created(NEW1)])]);
    const u = userEvent.setup();
    show(ok([A]));
    await openAdd(u);
    await choose(u, 'NEW-1');
    await fill(u);
    await submit(u);
    await waitFor(() => expect(within(dialog()).getByText("We can't tell whether NEW-1 was added. Check this supplier's catalogue before you try again.")).toBeInTheDocument());
    await submit(u);
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    const keys = calls.filter((c) => c.method === 'POST').map((c) => c.headers.get('Idempotency-Key'));
    expect(keys).toHaveLength(2);
    expect(keys[0]).toBeTruthy();
    expect(keys[0]).not.toBe(keys[1]);
  });

  it('an Add press that will send empties the page region at the press; Cancel leaves it as it was (FE-R9)', async () => {
    const r = held();
    stubFetch([items([NEW1]), items([NEW1]), r.reply, answer([A, created(NEW1)])]);
    const u = userEvent.setup();
    leaveArrivalMessage(`/suppliers/${SID_}`, 'ACME was created.');
    show(ok([A]));
    await waitFor(() => expect(pageRegion().textContent).toBe('ACME was created.'));
    await openAdd(u);
    await u.click(within(dialog()).getByRole('button', { name: 'Cancel' }));
    expect(pageRegion().textContent).toBe('ACME was created.');
    await openAdd(u);
    await choose(u, 'NEW-1');
    await fill(u);
    await submit(u);
    expect(pageRegion().textContent).toBe('');
    await act(async () => r.release(created201(created(NEW1))));
    await waitFor(() => expect(pageRegion().textContent).toBe(AFTER('NEW-1')));
  });
});

describe('S-5 — refusals (plan test 6; 403; interrupted)', () => {
  // Plan test 6, C2.
  it('C2 (already listed) → the server\'s sentence under Not added; the dialog stays with Add and the typed values', async () => {
    const calls = stubFetch([items([NEW1]), refused('supplier_product.item_is_listed_once_per_supplier', ITEM_LISTED), json(200, ACME), answer([A])]);
    const u = userEvent.setup();
    show(ok([A]));
    await openAdd(u);
    await choose(u, 'NEW-1');
    await fill(u);
    await submit(u);
    await waitFor(() => expect(within(dialog()).getByText(ITEM_LISTED)).toBeInTheDocument());
    expect(within(dialog()).getByText('Not added')).toBeInTheDocument();
    expect(within(dialog()).getAllByRole('button').map((b) => b.textContent)).toEqual(['Change', 'Cancel', 'Add to catalogue']);
    expect(field('Supplier SKU')).toHaveValue('S-1');
    await u.click(within(dialog()).getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(methods(calls)).toEqual(['GET /items', `POST /suppliers/${SID_}/products`, `GET /suppliers/${SID_}`, `GET /suppliers/${SID_}/products`]));
  });

  // Plan test 6, C3: the item archived after it was chosen → the sentence beside the item, focus on Change.
  it('C3 (a 422 on the item) → the server\'s sentence beside the chosen item, focus on Change', async () => {
    stubFetch([items([NEW1]), violation('/inventory_item_id', ITEM_UNKNOWN, 'unknown_reference')]);
    const u = userEvent.setup();
    show(ok([A]));
    await openAdd(u);
    await choose(u, 'NEW-1');
    await fill(u);
    await submit(u);
    const change = within(dialog()).getByRole('button', { name: 'Change item' });
    await waitFor(() => expect(change).toHaveAccessibleDescription(ITEM_UNKNOWN));
    expect(document.activeElement).toBe(change);
  });

  // Plan test 6, C1.
  it('C1 (supplier archived meanwhile) → the server\'s sentence, Close only; Close → the page re-reads the supplier: banner, note, no Add', async () => {
    stubFetch([items([NEW1]), refused('supplier.is_archived', ARCHIVED_ON_CREATE), json(200, ARCHIVED), json(200, ARCHIVED), answer([A])]);
    const u = userEvent.setup();
    show(ok([A]), { canRestore: true });
    await openAdd(u);
    await choose(u, 'NEW-1');
    await fill(u);
    await submit(u);
    await waitFor(() => expect(within(dialog()).getByText(ARCHIVED_ON_CREATE)).toBeInTheDocument());
    // Nothing here can be changed until the supplier is restored: Change goes with Add (the S-4 "Close only" shape).
    expect(within(dialog()).getAllByRole('button').map((b) => b.textContent)).toEqual(['Close']);
    await u.click(within(dialog()).getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(screen.getByText(/This supplier was archived on/)).toBeInTheDocument());
    expect(within(section()).getByText("This catalogue can't be changed while the supplier is archived. It comes back unchanged when the supplier is restored.")).toBeInTheDocument();
    expect(addButtons()).toEqual([]);
  });

  // The supplier is gone (another workspace's, or hard-deleted): nothing to press (the S-4 DV-2 shape).
  it('a 404 → "We couldn\'t find that." under Not added, Close only; Close re-reads the section', async () => {
    const calls = stubFetch([items([NEW1]), problem(404, 'not-found'), answer([A])]);
    const u = userEvent.setup();
    show(ok([A]));
    await openAdd(u);
    await choose(u, 'NEW-1');
    await fill(u);
    await submit(u);
    await waitFor(() => expect(within(dialog()).getByText('Not added')).toBeInTheDocument());
    expect(dialog()).toHaveTextContent("We couldn't find that.");
    expect(within(dialog()).getAllByRole('button').map((b) => b.textContent)).toEqual(['Close']);
    await u.click(within(dialog()).getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(methods(calls)).toEqual(['GET /items', `POST /suppliers/${SID_}/products`, `GET /suppliers/${SID_}/products`]));
  });

  it('a 403 → the sentence names "Edit supplier catalogues"; Add stays', async () => {
    stubFetch([items([NEW1]), problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    show(ok([A]));
    await openAdd(u);
    await choose(u, 'NEW-1');
    await fill(u);
    await submit(u);
    await waitFor(() => expect(dialog()).toHaveTextContent('This needs the Edit supplier catalogues permission, which you no longer have.'));
    expect(within(dialog()).getByRole('button', { name: 'Add to catalogue' })).toBeInTheDocument();
  });

  it('interrupted (unreachable) → the interrupted sentence with the item\'s SKU; Cancel re-reads the section', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    try {
      const calls = stubFetch([items([NEW1]), 'hang', answer([A])]);
      const u = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
      show(ok([A]));
      await openAdd(u);
      await choose(u, 'NEW-1');
      await fill(u);
      await submit(u);
      await act(async () => {
        await vi.advanceTimersByTimeAsync(31_000);
      });
      await waitFor(() => expect(within(dialog()).getByText("We can't tell whether NEW-1 was added. Check this supplier's catalogue before you try again.")).toBeInTheDocument());
      await u.click(within(dialog()).getByRole('button', { name: 'Cancel' }));
      await waitFor(() => expect(methods(calls)).toEqual(['GET /items', `POST /suppliers/${SID_}/products`, `GET /suppliers/${SID_}/products`]));
    } finally {
      vi.useRealTimers();
    }
  });
});

describe('S-5 — after a 201: one composed sentence (FINAL (a) R1-E "With cost", FINAL (e) item 10)', () => {
  it('the plain case: the dialog closes, the heading takes focus while the region is still empty, then "{sku} was added to the catalogue."; the new row shows', async () => {
    stubFetch([items([NEW1]), created201(created(NEW1)), answer([A, created(NEW1)])]);
    const u = userEvent.setup();
    show(ok([A]));
    await openAdd(u);
    await choose(u, 'NEW-1');
    await fill(u);
    const seen: { el: string; region: string }[] = [];
    const real = HTMLElement.prototype.focus;
    const spy = vi.spyOn(HTMLElement.prototype, 'focus').mockImplementation(function (this: HTMLElement, opts?: FocusOptions) {
      seen.push({ el: `${this.tagName}:${this.textContent}`, region: pageRegion().textContent ?? '' });
      real.call(this, opts);
    });
    try {
      await submit(u);
      await waitFor(() => expect(pageRegion().textContent).toBe(AFTER('NEW-1')));
    } finally {
      spy.mockRestore();
    }
    const onHeading = seen.filter((c) => c.el === 'H2:Catalogue');
    expect(onHeading.length).toBeGreaterThan(0);
    expect(onHeading[0]!.region).toBe('');
    expect(screen.queryByRole('dialog')).toBeNull();
    await waitFor(() => expect(document.activeElement).toBe(catalogueHeading()));
    await waitFor(() => expect(rows().map((r) => cells(r)[0])).toEqual(['A-100', 'S-1']));
  });

  // One test per clause (item 10): the currency clause alone (M-9; plan test 7).
  it('currency clause — the 201 in another currency: "… Its cost is recorded in EUR, …", and the page re-reads the supplier', async () => {
    const calls = stubFetch([items([NEW1]), created201(created(NEW1, { currency_code: 'EUR' })), json(200, ACME_EUR), answer([A, created(NEW1, { currency_code: 'EUR' })])]);
    const u = userEvent.setup();
    show(ok([A]));
    await openAdd(u);
    await choose(u, 'NEW-1');
    await fill(u);
    await submit(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(`${AFTER('NEW-1')} ${CURRENCY('EUR')}`));
    await waitFor(() => expect(methods(calls)).toEqual(['GET /items', `POST /suppliers/${SID_}/products`, `GET /suppliers/${SID_}`, `GET /suppliers/${SID_}/products`]));
  });

  // The unit clause alone, compared against the CHOSEN item's unit (NEW-1, EA; the 201 says BOX).
  it('unit clause — the 201\'s unit differs from the chosen item\'s: the full composed string, naming Box (BOX)', async () => {
    const body = created(NEW1, { unit_of_measure_code: 'BOX' });
    const calls = stubFetch([items([NEW1]), created201(body), answer([A, body])]);
    const u = userEvent.setup();
    show(ok([A]));
    await openAdd(u);
    await choose(u, 'NEW-1');
    await fill(u);
    await submit(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(`${AFTER('NEW-1')} ${UNIT('Box (BOX)')}`));
    // No supplier re-read for the unit case: the section's re-read shows the new unit in the Unit column.
    await waitFor(() => expect(rows().map((r) => cells(r)[5])).toEqual(['EA', 'BOX']));
    expect(methods(calls)).toEqual(['GET /items', `POST /suppliers/${SID_}/products`, `GET /suppliers/${SID_}/products`]);
  });

  it('list-stale clause — the section\'s re-read fails: the sentence is replaced by "… We couldn\'t load the list again, …"; the new row stays', async () => {
    const r = held();
    stubFetch([items([NEW1]), created201(created(NEW1)), r.reply]);
    const u = userEvent.setup();
    show(ok([A]));
    const watch = textsOf(pageRegion());
    await openAdd(u);
    await choose(u, 'NEW-1');
    await fill(u);
    await submit(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(AFTER('NEW-1')));
    await act(async () => r.release(json(503, {})));
    await waitFor(() => expect(pageRegion().textContent).toBe(`${AFTER('NEW-1')} ${STALE}`));
    watch.stop();
    expect(watch.texts).toEqual(['', AFTER('NEW-1'), `${AFTER('NEW-1')} ${STALE}`]);
    expect(rows().map((x) => cells(x)[0])).toEqual(['A-100', 'S-1']);
  });

  it('all three clauses, in order: added · currency · unit · list-stale', async () => {
    const body = created(NEW1, { currency_code: 'EUR', unit_of_measure_code: 'BOX' });
    const r = held();
    stubFetch([items([NEW1]), created201(body), json(200, ACME_EUR), r.reply]);
    const u = userEvent.setup();
    show(ok([A]));
    await openAdd(u);
    await choose(u, 'NEW-1');
    await fill(u);
    await submit(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(`${AFTER('NEW-1')} ${CURRENCY('EUR')} ${UNIT('Box (BOX)')}`));
    await act(async () => r.release(json(503, {})));
    await waitFor(() => expect(pageRegion().textContent).toBe(`${AFTER('NEW-1')} ${CURRENCY('EUR')} ${UNIT('Box (BOX)')} ${STALE}`));
  });

  it('the stale clause is written only while nothing else wrote the region: a supplier Save answered meanwhile keeps its sentence', async () => {
    const r = held();
    stubFetch([items([NEW1]), created201(created(NEW1)), r.reply, json(200, { ...ACME, name: 'Acme Ltd' }), json(200, { ...ACME, name: 'Acme Ltd' })]);
    const u = userEvent.setup();
    show(ok([A]), { canEditSupplier: true });
    await openAdd(u);
    await choose(u, 'NEW-1');
    await fill(u);
    await submit(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(AFTER('NEW-1')));
    const name = screen.getByRole('textbox', { name: 'Name' });
    await u.clear(name);
    await u.type(name, 'Acme Ltd');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(pageRegion().textContent).toBe('ACME was saved.'));
    await act(async () => r.release(json(503, {})));
    await act(async () => {
      await new Promise((res) => setTimeout(res, 20));
    });
    expect(pageRegion().textContent).toBe('ACME was saved.');
  });
});

describe('S-5 — stamps and focus fallbacks (the two shapes pinned from S-4\'s review)', () => {
  // The 201 body is committed under a FRESH stamp: a catalogue re-read sent BEFORE the Add press and answered after the
  // 201 without the new entry cannot remove it.
  it('a re-read sent before the Add press answers late without the new entry: the new row stays', async () => {
    const r1 = held();
    const r2 = held();
    stubFetch([json(200, { ...A, lead_time_days: 9 }), r1.reply, items([NEW1]), created201(created(NEW1)), r2.reply]);
    const u = userEvent.setup();
    show(ok([A]), { canEdit: true });
    await u.click(within(section()).getByRole('button', { name: 'Edit SKU-A-100' }));
    await setField(u, 'Lead time (days)', '9');
    await u.click(within(dialog()).getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await openAdd(u);
    await choose(u, 'NEW-1');
    await fill(u);
    await submit(u);
    await waitFor(() => expect(rows().map((x) => cells(x)[0])).toEqual(['A-100', 'S-1']));
    await act(async () => r1.release(answer([A])));
    await act(async () => {
      await new Promise((res) => setTimeout(res, 20));
    });
    expect(rows().map((x) => cells(x)[0])).toEqual(['A-100', 'S-1']);
    await act(async () => r2.release(answer([{ ...A, lead_time_days: 9 }, created(NEW1)])));
  });

  // The opener is gone (the page read archived across the dialog: both Add buttons unmount, the dialog stays). Cancel puts
  // focus on the Catalogue heading itself — never first on the removed button.
  it('the Add opener is gone when the dialog is cancelled: focus goes to the Catalogue heading, never to the removed button', async () => {
    const s = held();
    // PATCH (held) · ListItems (the open) · the supplier's re-read after S5 (archived).
    stubFetch([s.reply, items([NEW1]), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    show(ok([A]), { canEditSupplier: true, canRestore: true });
    const name = screen.getByRole('textbox', { name: 'Name' });
    await u.clear(name);
    await u.type(name, 'Acme Ltd');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await openAdd(u);
    await act(async () => s.release(refused('supplier.is_archived', S5)));
    await waitFor(() => expect(screen.getByText(/This supplier was archived on/)).toBeInTheDocument());
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    // The page is aria-hidden behind the modal dialog: count the page's Add buttons outside it, hidden included.
    expect(screen.queryAllByRole('button', { name: 'Add to catalogue', hidden: true }).filter((b) => !dialog().contains(b))).toEqual([]);
    const seen: { el: string; connected: boolean }[] = [];
    const real = HTMLElement.prototype.focus;
    const spy = vi.spyOn(HTMLElement.prototype, 'focus').mockImplementation(function (this: HTMLElement, opts?: FocusOptions) {
      // The press itself focuses Cancel (inside the dialog); only focus moves onto the page are recorded.
      if (this.closest('[role="dialog"]') === null) seen.push({ el: `${this.tagName}:${this.textContent}`, connected: this.isConnected });
      real.call(this, opts);
    });
    try {
      await u.click(within(dialog()).getByRole('button', { name: 'Cancel' }));
      await waitFor(() => expect(document.activeElement).toBe(catalogueHeading()));
    } finally {
      spy.mockRestore();
    }
    expect(seen[0]).toEqual({ el: 'H2:Catalogue', connected: true });
    expect(seen.filter((c) => !c.connected)).toEqual([]);
  });

  it('a 201 after the opener is gone: the heading takes focus, then the sentence', async () => {
    const s = held();
    stubFetch([s.reply, items([NEW1]), json(200, ARCHIVED), created201(created(NEW1)), answer([A, created(NEW1)])]);
    const u = userEvent.setup();
    show(ok([A]), { canEditSupplier: true, canRestore: true });
    const name = screen.getByRole('textbox', { name: 'Name' });
    await u.clear(name);
    await u.type(name, 'Acme Ltd');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await openAdd(u);
    await act(async () => s.release(refused('supplier.is_archived', S5)));
    await waitFor(() => expect(screen.getByText(/This supplier was archived on/)).toBeInTheDocument());
    await choose(u, 'NEW-1');
    await fill(u);
    await submit(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(AFTER('NEW-1')));
    await waitFor(() => expect(document.activeElement).toBe(catalogueHeading()));
  });
});

describe('S-5 RED (suppliers-s5-red.md) and review plants (the review plants on 2a92006)', () => {
  // RED M-1 (probe P1): a row's Edit pressed while the Add press's first read is in flight wins; the late answer opens
  // nothing, so there is one dialog at a time and the Add button stops loading.
  it('M-1 — Edit pressed while the Add read is in flight: one dialog (the Edit\'s), and Add is no longer loading', async () => {
    const r = held();
    stubFetch([r.reply]);
    const u = userEvent.setup();
    show(ok([A]), { canEdit: true });
    await u.click(addButtons()[0]!);
    await u.click(within(section()).getByRole('button', { name: 'Edit SKU-A-100' }));
    await act(async () => r.release(items([NEW1])));
    await act(async () => {
      await new Promise((res) => setTimeout(res, 30));
    });
    const dialogs = screen.queryAllByRole('dialog', { hidden: true });
    expect(dialogs).toHaveLength(1);
    expect(within(dialogs[0]!).getByRole('heading', { name: 'Edit SKU-A-100 — Item A-100 in this catalogue' })).toBeInTheDocument();
    expect(screen.getAllByRole('button', { name: 'Add to catalogue', hidden: true })[0]).not.toHaveAttribute('aria-busy');
  });

  // RED M-2 (probe P2): while the request is in flight Change is hidden with Cancel, so the answer is shown against the
  // item that was sent.
  it('M-2 — no Change while the Add request is in flight; after C2 the line is still the sent item, with Change back', async () => {
    const p = held();
    stubFetch([items([NEW1, BOLT]), p.reply, json(200, ACME)]);
    const u = userEvent.setup();
    show(ok([A]));
    await openAdd(u);
    await choose(u, 'NEW-1');
    await fill(u);
    await submit(u);
    expect(within(dialog()).queryByRole('button', { name: 'Change item' })).toBeNull();
    await act(async () => p.release(refused('supplier_product.item_is_listed_once_per_supplier', ITEM_LISTED)));
    await waitFor(() => expect(within(dialog()).getByText(ITEM_LISTED)).toBeInTheDocument());
    expect(within(dialog()).getByText('Item: NEW-1 — New widget')).toBeInTheDocument();
    expect(within(dialog()).getByRole('button', { name: 'Change item' })).toBeInTheDocument();
  });

  // RED L-2 (probe P6): a refusal describes the item that was sent; Change drops it.
  it('L-2 — C2 on NEW-1, then Change and Choose BOLT-10: the C2 banner is gone', async () => {
    stubFetch([items([NEW1, BOLT]), refused('supplier_product.item_is_listed_once_per_supplier', ITEM_LISTED), json(200, ACME), items([NEW1, BOLT])]);
    const u = userEvent.setup();
    show(ok([A]));
    await openAdd(u);
    await choose(u, 'NEW-1');
    await fill(u);
    await submit(u);
    await waitFor(() => expect(within(dialog()).getByText(ITEM_LISTED)).toBeInTheDocument());
    await u.click(within(dialog()).getByRole('button', { name: 'Change item' }));
    await within(dialog()).findByRole('button', { name: 'Choose BOLT-10' });
    await choose(u, 'BOLT-10');
    expect(within(dialog()).getByText('Item: BOLT-10 — Bolt 10mm')).toBeInTheDocument();
    expect(within(dialog()).queryByText(ITEM_LISTED)).toBeNull();
  });

  // RED L-3 (probe P9, plant X21): a Change read answered after the dialog was closed and opened again never replaces the
  // new dialog's list.
  it('L-3 — Change read held, Escape, Add again (answers BOLT-10), then the old Change read answers NUT-5: the list stays BOLT-10', async () => {
    const r1 = held();
    stubFetch([items([NEW1]), r1.reply, items([BOLT])]);
    const u = userEvent.setup();
    show(ok([A]));
    await openAdd(u);
    await choose(u, 'NEW-1');
    await u.click(within(dialog()).getByRole('button', { name: 'Change item' }));
    await u.keyboard('{Escape}');
    await openAdd(u);
    expect(within(dialog()).getByRole('button', { name: 'Choose BOLT-10' })).toBeInTheDocument();
    await act(async () => r1.release(items([NUT])));
    await act(async () => {
      await new Promise((res) => setTimeout(res, 30));
    });
    expect(within(dialog()).getAllByRole('button', { name: /^Choose / }).map((b) => b.getAttribute('aria-label'))).toEqual(['Choose BOLT-10']);
  });

  // Review plant P-6, re-ordered (its own shape below cannot reach the section's choice reads): closing the dialog
  // invalidates a Change read in flight, so its late answer opens nothing.
  it('P-6 — Change read held, Escape, then the read answers: no dialog comes back', async () => {
    const r1 = held();
    stubFetch([items([NEW1]), r1.reply]);
    const u = userEvent.setup();
    show(ok([A]));
    await openAdd(u);
    await choose(u, 'NEW-1');
    await u.click(within(dialog()).getByRole('button', { name: 'Change item' }));
    await u.keyboard('{Escape}');
    expect(screen.queryByRole('dialog')).toBeNull();
    await act(async () => r1.release(items([NUT])));
    await act(async () => {
      await new Promise((res) => setTimeout(res, 30));
    });
    expect(screen.queryAllByRole('dialog', { hidden: true })).toEqual([]);
  });

  // Review plant P-6's own shape: a search read held inside the list, the dialog closed and opened again, then the old
  // search answers. The read is the list core's own and dies with the closed dialog's list, so this holds by construction
  // (P-6 cannot redden it; the re-ordered test above is the one that does).
  it('P-6 (as specified) — a search read held across Close and reopen never replaces the reopened list', async () => {
    const q = held();
    stubFetch([items([NEW1, BOLT]), q.reply, items([NUT])]);
    const u = userEvent.setup();
    show(ok([A]));
    await openAdd(u);
    await u.type(searchBox(), 'X');
    await settle();
    await u.keyboard('{Escape}');
    await openAdd(u);
    expect(within(dialog()).getByRole('button', { name: 'Choose NUT-5' })).toBeInTheDocument();
    await act(async () => q.release(items([BOLT])));
    await act(async () => {
      await new Promise((res) => setTimeout(res, 30));
    });
    expect(within(dialog()).getAllByRole('button', { name: /^Choose / }).map((b) => b.getAttribute('aria-label'))).toEqual(['Choose NUT-5']);
  });

  // Review plant P-5: the 201 commit drops any row with the new entry's id first. A catalogue re-read sent before the Add
  // press answers WITH the new entry before the 201 is delivered; the 201 then adds no second row.
  it('P-5 — a re-read already showing the new entry, then the 201: exactly one row for it', async () => {
    const r1 = held();
    const post = held();
    const r2 = held();
    const A9 = { ...A, lead_time_days: 9 };
    stubFetch([json(200, A9), r1.reply, items([NEW1]), post.reply, r2.reply]);
    const u = userEvent.setup();
    show(ok([A]), { canEdit: true });
    await u.click(within(section()).getByRole('button', { name: 'Edit SKU-A-100' }));
    await setField(u, 'Lead time (days)', '9');
    await u.click(within(dialog()).getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await openAdd(u);
    await choose(u, 'NEW-1');
    await fill(u);
    await submit(u);
    await act(async () => r1.release(answer([A9, created(NEW1)])));
    await act(async () => post.release(created201(created(NEW1))));
    await waitFor(() => expect(pageRegion().textContent).toBe(AFTER('NEW-1')));
    expect(rows().map((x) => cells(x)[0])).toEqual(['A-100', 'S-1']);
    expect(within(section()).getAllByRole('button', { name: 'Edit NEW-1' })).toHaveLength(1);
    await act(async () => r2.release(answer([A9, created(NEW1)])));
  });

  // RED L-4, X24 (DV-10): after a settled search, a page read by its cursor names the search too.
  it('L-4 / DV-10 — search "NE", then Next: "2 items shown, NEW-1 to NUT-5. SKU starts with “NE”."', async () => {
    stubFetch([items([NEW1, BOLT]), items([NEW1], { next_cursor: 'c-2' }), items([NEW1, NUT], { previous_cursor: 'c-1' })]);
    const u = userEvent.setup();
    show(ok([A]));
    const d = await openAdd(u);
    await u.type(searchBox(), 'NE');
    await settle();
    await waitFor(() => expect(within(d).getByRole('button', { name: 'Next' })).toBeInTheDocument());
    await u.click(within(d).getByRole('button', { name: 'Next' }));
    await waitFor(() => expect(within(d).getByRole('status').textContent).toBe('2 items shown, NEW-1 to NUT-5. SKU starts with “NE”.'));
  });

  // RED L-4, X4.
  it('L-4 — an answer whose previous_cursor is not a string or null → server-error', async () => {
    const read = async (): Promise<ReadAnswer> => ({ kind: 'ok', body: page([NEW1], { previous_cursor: 5 }) });
    expect(await loadItemChoices(read, { q: '', cursor: null })).toEqual({ kind: 'server-error' });
  });

  // RED L-4, X18: the item's sentence belongs to the choice it was given for; Change drops it.
  it('L-4 — C3, then Change: the item sentence is gone above the fresh list', async () => {
    stubFetch([items([NEW1]), violation('/inventory_item_id', ITEM_UNKNOWN, 'unknown_reference'), items([NEW1])]);
    const u = userEvent.setup();
    show(ok([A]));
    await openAdd(u);
    await choose(u, 'NEW-1');
    await fill(u);
    await submit(u);
    await waitFor(() => expect(within(dialog()).getByText(ITEM_UNKNOWN)).toBeInTheDocument());
    await u.click(within(dialog()).getByRole('button', { name: 'Change item' }));
    await within(dialog()).findByRole('button', { name: 'Choose NEW-1' });
    expect(within(dialog()).queryByText(ITEM_UNKNOWN)).toBeNull();
  });

  // RED L-4, X9: P1 (contention) says "try again"; it is never followed by a supplier re-read, and Add stays.
  it('L-4 — P1 (contention): the sentence, Add kept, and no supplier re-read', async () => {
    const calls = stubFetch([items([NEW1]), refused('transaction.contention', 'Someone else was changing this at the same moment. Try again.')]);
    const u = userEvent.setup();
    show(ok([A]));
    await openAdd(u);
    await choose(u, 'NEW-1');
    await fill(u);
    await submit(u);
    await waitFor(() => expect(within(dialog()).getByText('Someone else was changing this at the same moment. Try again.')).toBeInTheDocument());
    expect(within(dialog()).getByRole('button', { name: 'Add to catalogue' })).toBeInTheDocument();
    await act(async () => {
      await new Promise((res) => setTimeout(res, 20));
    });
    expect(methods(calls)).toEqual(['GET /items', `POST /suppliers/${SID_}/products`]);
  });

  // UX U-1 (ux-r1e-confirm.md): with nothing to choose and nothing chosen, Add is not offered; Cancel stays.
  it('U-1 — allArchived, noneYet and denied: no Add to catalogue in the dialog, Cancel stays', async () => {
    stubFetch([items([], { tenant_has_rows: true }), items([], { tenant_has_rows: false }), problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    show(ok([A]));
    for (const state of ['allArchived', 'noneYet', 'denied']) {
      const d = await openAdd(u);
      expect(within(d).queryByRole('button', { name: 'Add to catalogue' }), state).toBeNull();
      expect(within(d).getByRole('button', { name: 'Cancel' }), state).toBeInTheDocument();
      await u.click(within(d).getByRole('button', { name: 'Cancel' }));
    }
  });

  it('U-1 — noMatch and a failed first read keep Add (Clear search, Retry are in reach)', async () => {
    // The workspace has items (NEW-1), so the empty search answer serves tenant_has_rows: true (moved deliberately with
    // RED-2 L-3: `page([])` defaults it to false, which is the no-item-at-all case).
    stubFetch([items([NEW1]), items([], { tenant_has_rows: true }), json(503, {})]);
    const u = userEvent.setup();
    show(ok([A]));
    let d = await openAdd(u);
    await u.type(searchBox(), 'ZZ');
    await settle();
    await waitFor(() => expect(within(d).getByRole('button', { name: 'Clear search' })).toBeInTheDocument());
    expect(within(d).getByRole('button', { name: 'Add to catalogue' })).toBeInTheDocument();
    await u.click(within(d).getByRole('button', { name: 'Cancel' }));
    d = await openAdd(u);
    expect(within(d).getByText("The items couldn't be loaded.")).toBeInTheDocument();
    expect(within(d).getByRole('button', { name: 'Add to catalogue' })).toBeInTheDocument();
  });

  // UX DV-8 FIX (ST-1): the skeleton stands where the list will be while a Change's read is in flight.
  it('DV-8 — Change with its read held: the "Loading items" skeleton (aria-busy) is the dialog\'s one status; then the list, search focused', async () => {
    const r = held();
    stubFetch([items([NEW1]), r.reply]);
    const u = userEvent.setup();
    show(ok([A]));
    await openAdd(u);
    await choose(u, 'NEW-1');
    await u.click(within(dialog()).getByRole('button', { name: 'Change item' }));
    const sk = within(dialog()).getByRole('status', { name: 'Loading items' });
    expect(sk).toHaveAttribute('aria-busy', 'true');
    expect(within(dialog()).getAllByRole('status')).toHaveLength(1);
    // A Change read in flight keeps Add (U-1).
    expect(within(dialog()).getByRole('button', { name: 'Add to catalogue' })).toBeInTheDocument();
    await act(async () => r.release(items([BOLT])));
    await waitFor(() => expect(document.activeElement).toBe(searchBox()));
    expect(within(dialog()).queryByRole('status', { name: 'Loading items' })).toBeNull();
    expect(within(dialog()).getAllByRole('status')).toHaveLength(1);
  });

  // RED-2 L-1 (TA; review plant P-8): `nothingToChoose` is reset only when the nothing-to-choose state leaves the screen.
  it('U-1 — after allArchived, Cancel and an open on a list with items: Add is offered again', async () => {
    stubFetch([items([], { tenant_has_rows: true }), items([NEW1])]);
    const u = userEvent.setup();
    show(ok([A]));
    let d = await openAdd(u);
    expect(within(d).queryByRole('button', { name: 'Add to catalogue' })).toBeNull();
    await u.click(within(d).getByRole('button', { name: 'Cancel' }));
    d = await openAdd(u);
    await within(d).findByRole('button', { name: 'Choose NEW-1' });
    expect(within(dialog()).queryByRole('button', { name: 'Add to catalogue' })).not.toBeNull();
  });

  // RED-2 L-1 (TB), inside one dialog. Started from allArchived, not RED-2's noneYet: with RED-2 L-3 applied, a search with
  // no match in a workspace with no item at all keeps Add hidden (see the L-3 test), so the choosable shape is this one.
  it('U-1 — allArchived, then a search with no match: Add is offered (Clear search is in reach)', async () => {
    stubFetch([items([], { tenant_has_rows: true }), items([], { tenant_has_rows: true })]);
    const u = userEvent.setup();
    show(ok([A]));
    const d = await openAdd(u);
    expect(within(d).queryByRole('button', { name: 'Add to catalogue' })).toBeNull();
    await u.type(searchBox(), 'Z');
    await settle();
    await waitFor(() => expect(within(d).getByRole('button', { name: 'Clear search' })).toBeInTheDocument());
    expect(within(dialog()).queryByRole('button', { name: 'Add to catalogue' })).not.toBeNull();
  });

  // RED-2 L-3 (Q4; adopted, UX may strike): in a workspace with no item at all, a search with no match leaves nothing to
  // choose either (clearing it returns to noneYet), so Add stays hidden; Clear search is shown.
  it('U-1 / RED-2 L-3 — noneYet, then a search with no match: still no Add; Clear search shown', async () => {
    stubFetch([items([], { tenant_has_rows: false }), items([], { tenant_has_rows: false })]);
    const u = userEvent.setup();
    show(ok([A]));
    const d = await openAdd(u);
    await u.type(searchBox(), 'Z');
    await settle();
    await waitFor(() => expect(within(d).getByRole('button', { name: 'Clear search' })).toBeInTheDocument());
    expect(within(d).queryByRole('button', { name: 'Add to catalogue' })).toBeNull();
    expect(within(d).getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
  });

  // RED-2 L-2 (TC): a superseded Add read never clears the loading state of a later press.
  it('RED-2 L-2 — Add, Edit, Cancel, Add again: the first read\'s late answer leaves the second press loading; the second opens one dialog', async () => {
    const r1 = held();
    const r2 = held();
    stubFetch([r1.reply, r2.reply]);
    const u = userEvent.setup();
    show(ok([A]), { canEdit: true });
    await u.click(addButtons()[0]!);
    await u.click(within(section()).getByRole('button', { name: 'Edit SKU-A-100' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Cancel' }));
    await u.click(addButtons()[0]!);
    expect(addButtons()[0]).toHaveAttribute('aria-busy', 'true');
    await act(async () => r1.release(items([NUT])));
    await act(async () => {
      await new Promise((res) => setTimeout(res, 20));
    });
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(addButtons()[0]).toHaveAttribute('aria-busy', 'true');
    await act(async () => r2.release(items([NEW1])));
    const d = await screen.findByRole('dialog');
    expect(within(d).getByRole('button', { name: 'Choose NEW-1' })).toBeInTheDocument();
    expect(screen.queryAllByRole('dialog', { hidden: true })).toHaveLength(1);
  });

  // RED-2 L-2 (TD): the row Edit's own `setOpening(false)` — asserted before the superseded read answers.
  it('RED-2 L-2 — Edit pressed while the Add read is in flight: Add stops loading at once, before that read answers', async () => {
    const r = held();
    stubFetch([r.reply]);
    const u = userEvent.setup();
    show(ok([A]), { canEdit: true });
    await u.click(addButtons()[0]!);
    expect(screen.getAllByRole('button', { name: 'Add to catalogue', hidden: true })[0]).toHaveAttribute('aria-busy', 'true');
    await u.click(within(section()).getByRole('button', { name: 'Edit SKU-A-100' }));
    expect(screen.getAllByRole('button', { name: 'Add to catalogue', hidden: true })[0]).not.toHaveAttribute('aria-busy');
    await act(async () => r.release(items([NEW1])));
  });
});

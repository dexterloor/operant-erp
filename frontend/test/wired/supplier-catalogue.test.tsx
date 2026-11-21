import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ refresh: nav.refresh }) }));
import { leaveArrivalMessage, takeArrivalMessage } from '@/app/(app)/arrivalMessage';
import { SupplierPage } from '@/app/(app)/suppliers/[id]/SupplierPage';
import type { Supplier, SupplierProduct } from '@/lib/api/suppliers';
import { loadCatalogue, type CatalogueLoad } from '@/lib/suppliers/load';
import { held, json, problem, renderWired, stubFetch, textsOf, type Reply } from './harness';

// Suppliers S-3 (suppliers-plan.md Revision 1 — FINAL §R1-4 S-3 tests 2–7, test 7 as replaced by suppliers-final-red.md
// M-2; §R1-10; Revision 0 §2.3; suppliers-ux-copy.md Revision 1 — FINAL S-3 `CATALOGUE`), as changed by S-4
// (bk31-placement-confirm.md Revision 1 — FINAL R1-C, FINAL (e) items 2–3; final RED M-1's S-4 `archivedParent`): the
// catalogue section on the supplier page. A plain section (ARCH (a)), not the paged list core: one table over the served
// entries in served order, money as the served value and the entry's code (`renderMoney`, the m2 interim), the minimum
// order QR-2 trimmed with no suffix and the entry's own unit (`unit_of_measure_code`, BK-31) in the Unit column after it,
// an Actions column only for a seat that may Edit, the item linked only for a GetItem holder, a partial notice when the
// read answers with a cursor (G-42), the archived-parent note following the page's supplier (same mount), a 403 →
// `denied`, a failed read → the section's own region error and Retry. The count is spoken only when the section's first
// SUCCESSFUL load happens in the browser — a Retry after a failed first load — never on first paint and never on a
// re-read. Test 1 (absent and not fetched) is in `supplier-gate.test.ts`; the Edit dialog (S-4) is in
// `supplier-catalogue-edit.test.tsx`.

afterEach(() => {
  vi.unstubAllGlobals();
  nav.refresh.mockClear();
  takeArrivalMessage('');
});

const SID_ = '8c2d3e4f-5a6b-4c7d-8e9f-0a1b2c3d4e5f';
const ACME: Supplier = { id: SID_, code: 'ACME', name: 'Acme Supplies', country_code: 'GB', currency_code: 'GBP', payment_terms_days: 30, archived_at: null };
const ARCHIVED: Supplier = { ...ACME, archived_at: '2026-03-04T10:00:00Z' };
const entry = (id: string, sku: string, over: Partial<SupplierProduct> = {}): SupplierProduct => ({
  id,
  supplier_sku: sku,
  inventory_item: { id: `9a0b1c2d-3e4f-4a5b-8c6d-7e8f9a0b1c${id.slice(-2)}`, sku: `SKU-${sku}`, name: `Item ${sku}` },
  unit_cost: '12.5000',
  currency_code: 'GBP',
  lead_time_days: 7,
  minimum_order_quantity: '1.000000',
  unit_of_measure_code: 'EA',
  ...over,
});
const E1 = entry('e0000000-0000-4000-8000-000000000001', 'A-100', { unit_cost: '12.5000', currency_code: 'GBP', lead_time_days: 7, minimum_order_quantity: '2.500000' });
const E2 = entry('e0000000-0000-4000-8000-000000000002', 'B-200', { unit_cost: '250.0000', currency_code: 'JPY', lead_time_days: 1, minimum_order_quantity: '1.000000', unit_of_measure_code: 'KG' });
const E3 = entry('e0000000-0000-4000-8000-000000000003', 'C-300', { unit_cost: '123456789012345.1234', currency_code: 'EUR', lead_time_days: 0, minimum_order_quantity: '10.000000', unit_of_measure_code: 'BOX' });
const ok = (entries: SupplierProduct[], nextCursor: string | null = null): CatalogueLoad => ({ kind: 'ok', entries, nextCursor });
const answer = (entries: SupplierProduct[], next_cursor: string | null = null) => json(200, { items: entries, next_cursor, previous_cursor: null, tenant_has_rows: entries.length > 0 });

const show = (initial: CatalogueLoad, { supplier = ACME, canOpenItem = true, canRestore = false, canEdit = false } = {}) =>
  renderWired(<SupplierPage initial={supplier} canEdit={false} canRestore={canRestore} catalogue={{ initial, canOpenItem, canEdit }} />);
const pageRegion = () => document.querySelector<HTMLElement>('section > [role="status"]')!;
const section = () => screen.getByRole('region', { name: 'Catalogue' });
const rows = () => within(section()).queryAllByRole('row').slice(1);
const cells = (row: HTMLElement) => within(row).getAllByRole('cell').map((c) => c.textContent);

const S3_ARCHIVED = 'This supplier is archived. Its catalogue comes back unchanged when the supplier is restored.';
const S4_ARCHIVED = "This catalogue can't be changed while the supplier is archived. It comes back unchanged when the supplier is restored.";
const DENIED = "You don't have access to this supplier's catalogue.";
const LOAD_FAILED = "The catalogue couldn't be loaded.";

describe('/suppliers/[id] — the catalogue section (S-3 read, S-4 columns)', () => {
  // S-4 (bk31-placement-confirm.md Revision 1 — FINAL R1-C, FINAL (e) item 3): the column pin moved deliberately, for a
  // view-only seat and for a seat that may Edit. Actions only when the viewer may Edit (or, from S-6, Remove).
  it('heading "Catalogue" (h2); a view-only seat: columns Supplier SKU · Item · Unit cost · Lead time · Minimum order · Unit; no Actions column, no buttons', () => {
    show(ok([E1]));
    expect(within(section()).getByRole('heading', { level: 2, name: 'Catalogue' })).toBeInTheDocument();
    expect(within(section()).getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Supplier SKU', 'Item', 'Unit cost', 'Lead time', 'Minimum order', 'Unit']);
    expect(within(section()).queryAllByRole('button')).toEqual([]);
  });

  it('a seat that may Edit: columns Supplier SKU · Item · Unit cost · Lead time · Minimum order · Unit · Actions; one Edit per row', () => {
    show(ok([E1, E2]), { canEdit: true });
    expect(within(section()).getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Supplier SKU', 'Item', 'Unit cost', 'Lead time', 'Minimum order', 'Unit', 'Actions']);
    expect(within(section()).getAllByRole('button').map((b) => b.getAttribute('aria-label'))).toEqual(['Edit SKU-A-100', 'Edit SKU-B-200']);
    expect(within(section()).getAllByRole('button').map((b) => b.textContent)).toEqual(['Edit', 'Edit']);
  });

  // Test 3 (S-4, FINAL (e) item 3: Minimum order '2.5', Unit 'EA'; the unit is the entry's own code, never a constant).
  it('test 3 — served order; 12.5000 GBP → 12.50 GBP and 250.0000 JPY → 250 JPY (PO-1: the D1 form by dropping only zeros), 123456789012345.1234 EUR digit for digit as stored; MOQ "2.500000" → 2.5 with no suffix; the entry\'s own unit after it; lead time in days', () => {
    show(ok([E1, E2, E3]));
    expect(rows().map(cells)).toEqual([
      ['A-100', 'SKU-A-100 — Item A-100', '12.50 GBP', '7 days', '2.5', 'EA'],
      ['B-200', 'SKU-B-200 — Item B-200', '250 JPY', '1 day', '1', 'KG'],
      ['C-300', 'SKU-C-300 — Item C-300', '123456789012345.1234 EUR', '0 days', '10', 'BOX'],
    ]);
  });

  // Test 4.
  it('test 4 — the item is a link to its page only for a GetItem holder; plain text otherwise', () => {
    const { unmount } = show(ok([E1]), { canOpenItem: true });
    const link = within(section()).getByRole('link', { name: 'SKU-A-100 — Item A-100' });
    expect(link).toHaveAttribute('href', `/items/${E1.inventory_item.id}`);
    unmount();
    show(ok([E1]), { canOpenItem: false });
    expect(within(section()).queryAllByRole('link')).toEqual([]);
    expect(cells(rows()[0]!)[1]).toBe('SKU-A-100 — Item A-100');
  });

  it('an empty catalogue: "This supplier\'s catalogue is empty." and no table; no Add sentence before S-5', () => {
    show(ok([]));
    expect(within(section()).getByText("This supplier's catalogue is empty.")).toBeInTheDocument();
    expect(within(section()).queryByRole('table')).toBeNull();
    expect(section().textContent).not.toMatch(/Add/);
  });

  // Test 5 (G-42; final RED L-8: this test's name goes into the register's text).
  it('test 5 — partial: a non-null next_cursor shows CATALOGUE.partial(n) above the rows, which stay; a whole answer does not', () => {
    const { unmount } = show(ok([E1, E2], 'cursor-2'));
    expect(within(section()).getByText('Only the first 2 items in this catalogue are shown.')).toBeInTheDocument();
    expect(within(section()).getByText('Only the first 2 items in this catalogue are shown.').compareDocumentPosition(within(section()).getByRole('table')) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(rows()).toHaveLength(2);
    unmount();
    show(ok([E1, E2]));
    expect(section().textContent).not.toMatch(/Only the first/);
  });

  // Test 2.
  it('test 2 — an initial 403 → denied, not the region error', () => {
    show({ kind: 'forbidden' });
    expect(within(section()).getByText(DENIED)).toBeInTheDocument();
    expect(within(section()).queryByRole('button', { name: 'Retry' })).toBeNull();
    expect(section().textContent).not.toContain(LOAD_FAILED);
  });

  // Test 6 (final RED M-1: from S-4 the S-4 text replaces the S-3 one — the catalogue can be changed in the app once the
  // supplier is restored).
  it('test 6 — archived supplier → the S-4 archived-parent note; Restore supplier → note gone, same mount, rows kept', async () => {
    stubFetch([json(200, ACME), json(200, ACME)]);
    const u = userEvent.setup();
    show(ok([E1]), { supplier: ARCHIVED, canRestore: true });
    expect(within(section()).getByText(S4_ARCHIVED)).toBeInTheDocument();
    expect(document.body.textContent).not.toContain(S3_ARCHIVED);
    const before = section();
    await u.click(screen.getByRole('button', { name: 'Restore supplier' }));
    await waitFor(() => expect(within(section()).queryByText(S4_ARCHIVED)).toBeNull());
    expect(section()).toBe(before);
    expect(rows()).toHaveLength(1);
  });

  it('a live supplier shows no archived-parent note', () => {
    show(ok([E1]));
    expect(section().textContent).not.toMatch(/archived/);
  });
});

describe('/suppliers/[id] — the catalogue count is spoken only on the first successful load in the browser (final RED M-2)', () => {
  // Test 7 (a).
  it('test 7 (a) — a good first read on the server: after settle the page region holds nothing, or only the arrival message', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    try {
      const { unmount } = show(ok([E1, E2]));
      const t = textsOf(pageRegion());
      await act(async () => {
        await vi.advanceTimersByTimeAsync(50);
      });
      expect(t.texts).toEqual(['']);
      t.stop();
      unmount();
      leaveArrivalMessage(`/suppliers/${SID_}`, 'ACME was created.');
      show(ok([E1, E2]));
      const t2 = textsOf(pageRegion());
      await act(async () => {
        await vi.advanceTimersByTimeAsync(50);
      });
      expect(t2.texts.filter((x) => x !== '')).toEqual(['ACME was created.']);
      t2.stop();
    } finally {
      vi.useRealTimers();
    }
  });

  // Test 7 (b).
  it('test 7 (b) — a failed first read (503) → the section\'s region error; Retry → 200 with 2 rows → the page region says "2 items in this catalogue."', async () => {
    const calls = stubFetch([answer([E1, E2])]);
    const u = userEvent.setup();
    show({ kind: 'server-error' });
    expect(within(section()).getByText(LOAD_FAILED)).toBeInTheDocument();
    const t = textsOf(pageRegion());
    await u.click(within(section()).getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(rows()).toHaveLength(2));
    expect(pageRegion().textContent).toBe('2 items in this catalogue.');
    expect(t.texts.filter((x) => x !== '')).toEqual(['2 items in this catalogue.']);
    t.stop();
    expect(calls).toHaveLength(1);
    expect(calls[0]!.method).toBe('GET');
    expect(calls[0]!.url).toMatch(new RegExp(`/bff/api/suppliers/${SID_}/products$`));
    // Focus never lands on <body> when Retry goes: it moves to the section's heading.
    expect(document.activeElement).toBe(within(section()).getByRole('heading', { level: 2, name: 'Catalogue' }));
  });

  // FE-R9: empty at the press, after the guard (a second press while the first is in flight sends nothing).
  it('Retry empties the page region at the press (after its guard), then writes the count once', async () => {
    const h = held();
    const calls = stubFetch([h.reply]);
    const u = userEvent.setup();
    leaveArrivalMessage(`/suppliers/${SID_}`, 'ACME was created.');
    show({ kind: 'server-error' });
    await waitFor(() => expect(pageRegion().textContent).toBe('ACME was created.'));
    const t = textsOf(pageRegion());
    await u.click(within(section()).getByRole('button', { name: 'Retry' }));
    expect(pageRegion().textContent).toBe('');
    await u.click(within(section()).getByRole('button', { name: 'Retry' }));
    expect(calls).toHaveLength(1);
    await act(async () => h.release(answer([E1, E2])));
    await waitFor(() => expect(pageRegion().textContent).toBe('2 items in this catalogue.'));
    expect(t.texts).toEqual(['ACME was created.', '', '2 items in this catalogue.']);
    t.stop();
  });

  // S-3 RED M-1: the count is written AFTER the focus move (FE-D1), never in the same step before it.
  it('RED M-1 — after Retry → 200 the Catalogue heading takes focus while the region is still empty; the count follows', async () => {
    stubFetch([answer([E1, E2])]);
    const u = userEvent.setup();
    show({ kind: 'server-error' });
    const seen: { el: string; region: string }[] = [];
    const real = HTMLElement.prototype.focus;
    const spy = vi.spyOn(HTMLElement.prototype, 'focus').mockImplementation(function (this: HTMLElement, opts?: FocusOptions) {
      seen.push({ el: `${this.tagName}:${this.textContent}`, region: pageRegion().textContent ?? '' });
      real.call(this, opts);
    });
    try {
      await u.click(within(section()).getByRole('button', { name: 'Retry' }));
      await waitFor(() => expect(pageRegion().textContent).toBe('2 items in this catalogue.'));
    } finally {
      spy.mockRestore();
    }
    expect(seen.filter((c) => c.el === 'H2:Catalogue')).toEqual([{ el: 'H2:Catalogue', region: '' }]);
  });

  // S-3 RED L-1: focus moves to the heading only when it was lost (the Locations twin).
  it('RED L-1 — focus moved to the h1 while the Retry was in flight stays there; the count is still spoken', async () => {
    const h = held();
    stubFetch([h.reply]);
    const u = userEvent.setup();
    show({ kind: 'server-error' });
    await u.click(within(section()).getByRole('button', { name: 'Retry' }));
    const h1 = screen.getByRole('heading', { level: 1 });
    act(() => h1.focus());
    await act(async () => h.release(answer([E1, E2])));
    await waitFor(() => expect(pageRegion().textContent).toBe('2 items in this catalogue.'));
    expect(document.activeElement).toBe(h1);
  });

  // S-3 RED L-2 (UX TO CONFIRM): an empty catalogue brought in by Retry speaks the empty state's own sentence.
  it('RED L-2 — Retry → 200 with no entries → the page region says "This supplier\'s catalogue is empty."', async () => {
    stubFetch([answer([])]);
    const u = userEvent.setup();
    show({ kind: 'server-error' });
    await u.click(within(section()).getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(pageRegion().textContent).toBe("This supplier's catalogue is empty."));
    expect(within(section()).getByText("This supplier's catalogue is empty.", { selector: 'section > p' })).toBeInTheDocument();
  });

  // S-3 RED L-4: ST-2a — a failed first read is ordinary content on first paint: its heading does not take focus.
  it('RED L-4 — a failed first read: the region error is shown and focus is not inside the Catalogue section', () => {
    show({ kind: 'server-error' });
    expect(within(section()).getByText(LOAD_FAILED)).toBeInTheDocument();
    expect(section().contains(document.activeElement)).toBe(false);
  });

  it('one entry → "1 item in this catalogue."', async () => {
    stubFetch([answer([E1])]);
    const u = userEvent.setup();
    show({ kind: 'unreachable' });
    await u.click(within(section()).getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(pageRegion().textContent).toBe('1 item in this catalogue.'));
  });

  it('a Retry that fails again keeps the region error (its heading focused) and speaks no count', async () => {
    stubFetch([problem(503, 'service-unavailable')]);
    const u = userEvent.setup();
    show({ kind: 'server-error' });
    await u.click(within(section()).getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(document.activeElement?.closest('section')?.getAttribute('aria-labelledby')).not.toBe(section().getAttribute('aria-labelledby')));
    expect(document.activeElement?.tagName).toBe('H2');
    expect(within(section()).getByText(LOAD_FAILED)).toBeInTheDocument();
    expect(pageRegion().textContent).toBe('');
  });

  it('a Retry answered 403 → denied', async () => {
    stubFetch([problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    show({ kind: 'server-error' });
    await u.click(within(section()).getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(within(section()).getByText(DENIED)).toBeInTheDocument());
  });
});

describe('loadCatalogue (ListSupplierProducts)', () => {
  const run = async (replies: Reply[]) => {
    const calls: { operation: string; init: unknown }[] = [];
    const queue = [...replies];
    const read = async (operation: string, init: unknown) => {
      calls.push({ operation, init });
      const r = queue.shift();
      const res = typeof r === 'function' ? await r({ url: '', method: 'GET', headers: new Headers(), body: null }) : r;
      if (!(res instanceof Response)) throw new Error('no reply');
      return res.ok ? { kind: 'ok' as const, body: await res.json() } : { kind: 'server-error' as const };
    };
    return { result: await loadCatalogue(read as never, SID_), calls };
  };

  it('sends only the supplier id — no query, no limit, no cursor — and returns the entries and the cursor', async () => {
    const { result, calls } = await run([answer([E1], 'c')]);
    expect(calls).toEqual([{ operation: 'ListSupplierProducts', init: { params: { id: SID_ } } }]);
    expect(result).toEqual({ kind: 'ok', entries: [E1], nextCursor: 'c' });
  });

  it.each([
    ['a row without supplier_sku', { ...E1, supplier_sku: undefined }],
    ['a unit cost served as a number', { ...E1, unit_cost: 12.5 }],
    ['a lead time that is not a whole number', { ...E1, lead_time_days: 7.5 }],
    ['an item reference without its sku', { ...E1, inventory_item: { id: E1.inventory_item.id, name: 'x' } }],
    ['a row without currency_code', { ...E1, currency_code: undefined }],
    ['a minimum order served as a number', { ...E1, minimum_order_quantity: 2.5 }],
    ['an item reference without its name', { ...E1, inventory_item: { id: E1.inventory_item.id, sku: 'x' } }],
    // S-4 (FINAL (e) item 2): the unit is read from the entry, so an entry without it is malformed.
    ['a row without unit_of_measure_code', { ...E1, unit_of_measure_code: undefined }],
    ['a unit served as a number', { ...E1, unit_of_measure_code: 1 }],
  ])('%s → server-error, never a row', async (_name, row) => {
    expect((await run([json(200, { items: [row], next_cursor: null, previous_cursor: null, tenant_has_rows: true })])).result).toEqual({ kind: 'server-error' });
  });
});

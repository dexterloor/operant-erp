import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ refresh: nav.refresh }) }));
import { takeArrivalMessage } from '@/app/(app)/arrivalMessage';
import { SupplierPage } from '@/app/(app)/suppliers/[id]/SupplierPage';
import type { Item } from '@/lib/api/items';
import type { Supplier, SupplierProduct } from '@/lib/api/suppliers';
import type { CatalogueLoad } from '@/lib/suppliers/load';
import { empty, held, json, page, problem, refused, renderWired, stubFetch, textsOf, type Recorded } from './harness';

// Suppliers S-6 — Remove a catalogue entry (authority, in precedence: the S-5 carries in suppliers-s5-progress.md "Owed"
// (Remove's row press cancels an Add read in flight, RED M-1 / RED-2 L-2); suppliers-final-red.md S-6 row: M-8 (the body),
// M-6 (Remove then Edit); suppliers-plan.md Revision 1 — FINAL §R1-4 S-6 tests 1–4; suppliers-ux-copy.md Revision 1 —
// FINAL S-6 `REMOVE_CATALOGUE_ITEM`, heading `NOT_REMOVED`). Each row of a live supplier's catalogue offers **Remove** to
// a DeleteSupplierProduct holder; it opens the shared Delete dialog (danger, focus on Cancel) with the Remove copy and the
// heading **Not removed** on a refusal. After a 204 the dialog closes, focus goes to the Catalogue heading, the row goes,
// the page's region says "{sku} was removed from the catalogue." and the section re-reads, stamped. If that re-read
// fails, the sentence is replaced by its list-stale form, only while nothing else wrote the region since. A C1 (the
// supplier archived meanwhile) leaves Close only and, on Close, the page re-reads the supplier.

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
  unit_cost: '10.0000',
  currency_code: 'GBP',
  lead_time_days: 7,
  minimum_order_quantity: '2.500000',
  unit_of_measure_code: 'EA',
  ...over,
});
const A = entry('e0000000-0000-4000-8000-000000000001', 'A-100');
const B = entry('e0000000-0000-4000-8000-000000000002', 'B-200', { unit_of_measure_code: 'KG' });
const NEW1: Item = { id: '7b1c2d3e-4f5a-4b6c-8d7e-9f0a1b2c3d41', sku: 'NEW-1', name: 'New widget', unit_of_measure_code: 'EA', reorder_point: '0.000000', reorder_quantity: '0.000000', safety_stock: '0.000000', supplier: null, archived_at: null, holds_stock: false };
const ok = (entries: SupplierProduct[]): CatalogueLoad => ({ kind: 'ok', entries, nextCursor: null });
const answer = (entries: SupplierProduct[]) => json(200, { items: entries, next_cursor: null, previous_cursor: null, tenant_has_rows: entries.length > 0 });

const show = (initial: CatalogueLoad, { supplier = ACME, canEditSupplier = false, canRestore = false, canEdit = false, canAdd = false, canRemove = true } = {}) =>
  renderWired(<SupplierPage initial={supplier} canEdit={canEditSupplier} canRestore={canRestore} catalogue={{ initial, canOpenItem: false, canEdit, canAdd, canRemove }} />);
const pageRegion = () => document.querySelector<HTMLElement>('section > [role="status"]')!;
const section = () => screen.getByRole('region', { name: 'Catalogue' });
const catalogueHeading = () => within(section()).getByRole('heading', { level: 2, name: 'Catalogue' });
const rows = () => within(section()).queryAllByRole('row').slice(1);
const cells = (row: HTMLElement) => within(row).getAllByRole('cell').map((c) => c.textContent);
const skus = () => rows().map((r) => cells(r)[0]);
const removeButton = (sku: string) => within(section()).getByRole('button', { name: `Remove ${sku}` });
const dialog = () => screen.getByRole('dialog');
const buttons = () => within(dialog()).getAllByRole('button').map((b) => b.textContent);
const path = (c: Recorded) => c.url.replace(/^.*\/bff\/api/, '').replace(/\?.*$/, '');
const ENTRY = (id: string) => `/suppliers/${SID_}/products/${id}`;
const LIST = `/suppliers/${SID_}/products`;
const SUPPLIER = `/suppliers/${SID_}`;
const what = (calls: Recorded[]) => calls.map((c) => `${c.method} ${path(c)}`);

type U = ReturnType<typeof userEvent.setup>;
const openRemove = async (u: U, sku = 'SKU-A-100') => {
  await u.click(removeButton(sku));
  return dialog();
};
const confirm = (u: U) => u.click(within(dialog()).getByRole('button', { name: 'Remove' }));
const tick = () =>
  act(async () => {
    await new Promise((res) => setTimeout(res, 20));
  });

const BODY =
  "The entry is deleted for good, not archived, so it can't be restored. Until it's added again, no new purchase-order line for the item can be added for this supplier. Purchase orders that already list it, drafts included, keep their own cost and lead time. If this is the item's preferred supplier, reorder suggestions stop using these terms.";
const AFTER = (sku: string) => `${sku} was removed from the catalogue.`;
const STALE = (sku: string) => `${sku} was removed from the catalogue. We couldn't load the list again, so it may be out of date.`;
const INTERRUPTED = (sku: string) => `We can't tell whether ${sku} was removed. Close this and check the catalogue.`;
const ARCHIVED_ON_DELETE = "This supplier is archived, so nothing can be removed from its catalogue until it's restored.";
const S5 = "This supplier is archived, so it can't be changed until it's restored.";
const P1 = 'Someone else was changing this at the same moment. Try again.';
const NOT_FOUND = "We couldn't find that. It may have been deleted, or you may not have access.";
const S4_ARCHIVED = "This catalogue can't be changed while the supplier is archived. It comes back unchanged when the supplier is restored.";

describe('S-6 — where Remove is offered (plan §R1-2)', () => {
  it('a Remove seat on a live supplier: one Remove per row in an Actions column, named by the item\'s SKU, destructive', () => {
    show(ok([A, B]));
    expect(within(section()).getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Supplier SKU', 'Item', 'Unit cost', 'Lead time', 'Minimum order', 'Unit', 'Actions']);
    expect(within(section()).getAllByRole('button').map((b) => [b.getAttribute('aria-label'), b.textContent])).toEqual([
      ['Remove SKU-A-100', 'Remove'],
      ['Remove SKU-B-200', 'Remove'],
    ]);
  });

  it('with Edit too: Edit then Remove in each row', () => {
    show(ok([A]), { canEdit: true });
    expect(within(section()).getAllByRole('button').map((b) => b.getAttribute('aria-label'))).toEqual(['Edit SKU-A-100', 'Remove SKU-A-100']);
  });

  it('no Remove (and no Actions column) without DeleteSupplierProduct, nor on an archived supplier', () => {
    const { unmount } = show(ok([A]), { canRemove: false });
    expect(within(section()).queryAllByRole('button')).toEqual([]);
    expect(within(section()).getAllByRole('columnheader').map((h) => h.textContent)).not.toContain('Actions');
    unmount();
    show(ok([A]), { supplier: ARCHIVED });
    expect(within(section()).queryAllByRole('button')).toEqual([]);
    expect(within(section()).getAllByRole('columnheader').map((h) => h.textContent)).not.toContain('Actions');
  });

  it('an archived supplier: no Remove; Restore supplier → Remove appears, same mount', async () => {
    stubFetch([json(200, ACME), json(200, ACME)]);
    const u = userEvent.setup();
    show(ok([A]), { supplier: ARCHIVED, canRestore: true });
    const before = section();
    await u.click(screen.getByRole('button', { name: 'Restore supplier' }));
    await waitFor(() => expect(removeButton('SKU-A-100')).toBeInTheDocument());
    expect(section()).toBe(before);
  });
});

describe('S-6 — the Remove dialog (UX S-6 REMOVE_CATALOGUE_ITEM; final RED M-8)', () => {
  it('title "Remove {sku} — {name} from this catalogue?", the M-8 body whole, Cancel focused, then Remove', async () => {
    const u = userEvent.setup();
    show(ok([A]));
    const d = await openRemove(u);
    expect(within(d).getByRole('heading', { name: 'Remove SKU-A-100 — Item A-100 from this catalogue?' })).toBeInTheDocument();
    expect(d).toHaveAccessibleDescription(BODY);
    expect(buttons()).toEqual(['Cancel', 'Remove']);
    await waitFor(() => expect(document.activeElement).toBe(within(d).getByRole('button', { name: 'Cancel' })));
  });

  it('Cancel sends nothing, leaves the page region as it was, and puts focus back on the row\'s Remove', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    show(ok([A]));
    const t = textsOf(pageRegion());
    await openRemove(u);
    await u.click(within(dialog()).getByRole('button', { name: 'Cancel' }));
    expect(screen.queryByRole('dialog')).toBeNull();
    await waitFor(() => expect(document.activeElement).toBe(removeButton('SKU-A-100')));
    expect(calls).toEqual([]);
    expect(t.texts).toEqual(['']);
    t.stop();
  });
});

describe('S-6 — after a 204 (plan test 1, test 3)', () => {
  it('test 1 — DELETE the entry; the dialog closes, the heading takes focus while the region is still empty, the row goes, then "{sku} was removed from the catalogue."; the section re-reads', async () => {
    const calls = stubFetch([empty(204), answer([B])]);
    const u = userEvent.setup();
    show(ok([A, B]));
    const t = textsOf(pageRegion());
    const heading = catalogueHeading();
    await openRemove(u);
    const seen: { region: string; rows: (string | null | undefined)[] }[] = [];
    // The page may still be aria-hidden behind the closing dialog: read the rows from the DOM, not by role.
    const domSkus = () => [...heading.closest('section')!.querySelectorAll('tbody tr')].map((r) => r.querySelector('td')?.textContent);
    heading.addEventListener('focus', () => seen.push({ region: pageRegion().textContent ?? '', rows: domSkus() }));
    await confirm(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(AFTER('SKU-A-100')));
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(document.activeElement).toBe(heading);
    expect(seen[0]).toEqual({ region: '', rows: ['A-100', 'B-200'] });
    expect(skus()).toEqual(['B-200']);
    await waitFor(() => expect(calls).toHaveLength(2));
    expect(what(calls)).toEqual([`DELETE ${ENTRY(A.id)}`, `GET ${LIST}`]);
    expect(calls[0]!.body).toBeNull();
    expect(t.texts).toEqual(['', AFTER('SKU-A-100')]);
    t.stop();
  });

  it('test 1 — the section\'s re-read fails: the sentence is replaced by the list-stale sentence; the row stays gone', async () => {
    const r = held();
    stubFetch([empty(204), r.reply]);
    const u = userEvent.setup();
    show(ok([A, B]));
    const t = textsOf(pageRegion());
    await openRemove(u);
    await confirm(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(AFTER('SKU-A-100')));
    await act(async () => r.release(problem(503, 'service-unavailable')));
    await waitFor(() => expect(pageRegion().textContent).toBe(STALE('SKU-A-100')));
    expect(t.texts).toEqual(['', AFTER('SKU-A-100'), STALE('SKU-A-100')]);
    t.stop();
    expect(skus()).toEqual(['B-200']);
  });

  it('test 1 — the stale sentence is written only while nothing else wrote the region: a supplier Save answered meanwhile keeps its sentence', async () => {
    const r = held();
    const calls = stubFetch([empty(204), r.reply, json(200, { ...ACME, name: 'Acme Ltd' }), json(200, { ...ACME, name: 'Acme Ltd' })]);
    const u = userEvent.setup();
    show(ok([A, B]), { canEditSupplier: true });
    await openRemove(u);
    await confirm(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(AFTER('SKU-A-100')));
    const name = screen.getByRole('textbox', { name: 'Name' });
    await u.clear(name);
    await u.type(name, 'Acme Ltd');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(pageRegion().textContent).toBe('ACME was saved.'));
    await act(async () => r.release(problem(503, 'service-unavailable')));
    await tick();
    expect(pageRegion().textContent).toBe('ACME was saved.');
    expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET', 'PATCH', 'GET']);
  });

  it('a Remove press that will send empties the page region at the press (FE-R9), then the sentence', async () => {
    const d = held();
    stubFetch([empty(204), answer([B]), d.reply, answer([])]);
    const u = userEvent.setup();
    show(ok([A, B]));
    await openRemove(u);
    await confirm(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(AFTER('SKU-A-100')));
    await openRemove(u, 'SKU-B-200');
    await confirm(u);
    expect(pageRegion().textContent).toBe('');
    await act(async () => d.release(empty(204)));
    await waitFor(() => expect(pageRegion().textContent).toBe(AFTER('SKU-B-200')));
  });

  // The removal is committed at the 204 under a FRESH stamp (the S-4 RED L-1 rule applied to a body-less answer): a
  // catalogue re-read sent BEFORE the Remove press and answered after it, still holding the entry, cannot bring it back.
  it('a re-read sent before the Remove press answers late still holding the entry: the row stays gone', async () => {
    const r1 = held();
    const r2 = held();
    stubFetch([json(200, { ...B, lead_time_days: 9 }), r1.reply, empty(204), r2.reply]);
    const u = userEvent.setup();
    show(ok([A, B]), { canEdit: true });
    await u.click(within(section()).getByRole('button', { name: 'Edit SKU-B-200' }));
    const lead = within(dialog()).getByRole('textbox', { name: 'Lead time (days)' });
    await u.clear(lead);
    await u.type(lead, '9');
    await u.click(within(dialog()).getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await openRemove(u);
    await confirm(u);
    await waitFor(() => expect(skus()).toEqual(['B-200']));
    await act(async () => r1.release(answer([A, B])));
    await tick();
    expect(skus()).toEqual(['B-200']);
    await act(async () => r2.release(answer([{ ...B, lead_time_days: 9 }])));
  });

  // Final RED M-6, the S-6 half: the stamp rule across two different writes.
  it('M-6 — Remove B → 204, its re-read held; Edit A → 200, re-read answers without B; the held one answers with B → B stays gone', async () => {
    const r1 = held();
    stubFetch([empty(204), r1.reply, json(200, { ...A, lead_time_days: 9 }), answer([{ ...A, lead_time_days: 9 }])]);
    const u = userEvent.setup();
    show(ok([A, B]), { canEdit: true });
    await openRemove(u, 'SKU-B-200');
    await confirm(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(AFTER('SKU-B-200')));
    await u.click(within(section()).getByRole('button', { name: 'Edit SKU-A-100' }));
    const lead = within(dialog()).getByRole('textbox', { name: 'Lead time (days)' });
    await u.clear(lead);
    await u.type(lead, '9');
    await u.click(within(dialog()).getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(cells(rows()[0]!)[3]).toBe('9 days'));
    await act(async () => r1.release(answer([A, B])));
    await tick();
    expect(skus()).toEqual(['A-100']);
    expect(cells(rows()[0]!)[3]).toBe('9 days');
  });

  // Plan test 3: focus never falls to <body> when the row's button goes (4.6 H-1).
  it('test 3 — after a 204 focus is never on <body>, nor on the removed Remove button', async () => {
    stubFetch([empty(204), answer([B])]);
    const u = userEvent.setup();
    show(ok([A, B]));
    const opener = removeButton('SKU-A-100');
    await openRemove(u);
    await confirm(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(AFTER('SKU-A-100')));
    await tick();
    expect(opener.isConnected).toBe(false);
    expect(document.activeElement).not.toBe(document.body);
    expect(document.activeElement).toBe(catalogueHeading());
  });

  // The opener is gone while the dialog is open (the page read archived across it: every Remove unmounts, the dialog
  // stays). Cancel puts focus on the Catalogue heading itself — never first on the removed button.
  it('the Remove opener is gone when the dialog is cancelled: focus goes to the Catalogue heading, never to the removed button', async () => {
    const s = held();
    stubFetch([s.reply, json(200, ARCHIVED)]);
    const u = userEvent.setup();
    show(ok([A]), { canEditSupplier: true, canRestore: true });
    const name = screen.getByRole('textbox', { name: 'Name' });
    await u.clear(name);
    await u.type(name, 'Acme Ltd');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await openRemove(u);
    await act(async () => s.release(refused('supplier.is_archived', S5)));
    await waitFor(() => expect(screen.getByText(/This supplier was archived on/)).toBeInTheDocument());
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(screen.queryAllByRole('button', { name: 'Remove SKU-A-100', hidden: true })).toEqual([]);
    const seen: { el: string; connected: boolean }[] = [];
    const real = HTMLElement.prototype.focus;
    const spy = vi.spyOn(HTMLElement.prototype, 'focus').mockImplementation(function (this: HTMLElement, opts?: FocusOptions) {
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
});

describe('S-6 — refusals (plan test 2; heading Not removed)', () => {
  it('test 2 — 404: "We couldn\'t find that." under Not removed, Close only; Close re-reads the section, the row goes, focus on the heading', async () => {
    const calls = stubFetch([problem(404, 'not-found'), answer([B])]);
    const u = userEvent.setup();
    show(ok([A, B]));
    await openRemove(u);
    await confirm(u);
    await waitFor(() => expect(within(dialog()).getByText(NOT_FOUND)).toBeInTheDocument());
    expect(within(dialog()).getByText('Not removed')).toBeInTheDocument();
    expect(within(dialog()).queryByText('Not deleted')).toBeNull();
    expect(buttons()).toEqual(['Close']);
    await u.click(within(dialog()).getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(skus()).toEqual(['B-200']));
    expect(what(calls)).toEqual([`DELETE ${ENTRY(A.id)}`, `GET ${LIST}`]);
    // Nothing was removed by this press: nothing is said.
    expect(pageRegion().textContent).toBe('');
    await waitFor(() => expect(document.activeElement).toBe(catalogueHeading()));
  });

  // Re-ordered probe (plant R10 was green with the re-read answered at once: the dialog's own focus return covered it).
  // Here the re-read is held past that return, so focus sits on the row's Remove when the row goes.
  it('test 2 / test 3 — 404, Close with the section\'s re-read held; it answers without the row → focus moves to the heading, never <body>', async () => {
    const r = held();
    stubFetch([problem(404, 'not-found'), r.reply]);
    const u = userEvent.setup();
    show(ok([A, B]));
    const opener = removeButton('SKU-A-100');
    await openRemove(u);
    await confirm(u);
    await waitFor(() => expect(within(dialog()).getByText(NOT_FOUND)).toBeInTheDocument());
    await u.click(within(dialog()).getByRole('button', { name: 'Close' }));
    await tick();
    await tick();
    expect(document.activeElement).toBe(opener);
    await act(async () => r.release(answer([B])));
    await waitFor(() => expect(skus()).toEqual(['B-200']));
    expect(document.activeElement).toBe(catalogueHeading());
  });

  it('test 2 — P1 (contention): the sentence under Not removed, Remove kept, no supplier re-read; a second press sends again', async () => {
    const calls = stubFetch([refused('transaction.contention', P1), empty(204), answer([B])]);
    const u = userEvent.setup();
    show(ok([A, B]));
    await openRemove(u);
    await confirm(u);
    await waitFor(() => expect(within(dialog()).getByText(P1)).toBeInTheDocument());
    expect(within(dialog()).getByText('Not removed')).toBeInTheDocument();
    expect(buttons()).toEqual(['Cancel', 'Remove']);
    await confirm(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(AFTER('SKU-A-100')));
    await waitFor(() => expect(calls).toHaveLength(3));
    expect(what(calls)).toEqual([`DELETE ${ENTRY(A.id)}`, `DELETE ${ENTRY(A.id)}`, `GET ${LIST}`]);
  });

  it('test 2 — C1 (supplier archived meanwhile): the server\'s sentence under Not removed, Close only; Close → the page re-reads the supplier: banner, note, no Remove', async () => {
    const calls = stubFetch([refused('supplier.is_archived', ARCHIVED_ON_DELETE), json(200, ARCHIVED), json(200, ARCHIVED), answer([A])]);
    const u = userEvent.setup();
    show(ok([A]), { canRestore: true });
    await openRemove(u);
    await confirm(u);
    await waitFor(() => expect(within(dialog()).getByText(ARCHIVED_ON_DELETE)).toBeInTheDocument());
    expect(within(dialog()).getByText('Not removed')).toBeInTheDocument();
    expect(buttons()).toEqual(['Close']);
    expect(screen.queryByText(/This supplier was archived on/)).toBeNull();
    await u.click(within(dialog()).getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(screen.getByText(/This supplier was archived on/)).toBeInTheDocument());
    expect(within(section()).getByText(S4_ARCHIVED)).toBeInTheDocument();
    expect(within(section()).queryAllByRole('button', { name: /^Remove / })).toEqual([]);
    await waitFor(() => expect(calls).toHaveLength(4));
    expect(what(calls)).toEqual([`DELETE ${ENTRY(A.id)}`, `GET ${SUPPLIER}`, `GET ${SUPPLIER}`, `GET ${LIST}`]);
    await waitFor(() => expect(nav.refresh).toHaveBeenCalledTimes(1));
  });

  // The review plant P-12 (the C1 flag's reset at close): a C1 asks the page to re-read the supplier ONCE; a later Remove
  // that answers 204 must not ask again. The flag is reset twice (at close and at the next press), so dropping either
  // reset alone stays green by construction; dropping both turns this test red (recorded in the build note).
  it('P-12 — C1, Close, Restore supplier, then Remove another row → 204: the page does not re-read the supplier again', async () => {
    const calls = stubFetch([
      refused('supplier.is_archived', ARCHIVED_ON_DELETE),
      json(200, ARCHIVED),
      json(200, ARCHIVED),
      answer([A, B]),
      json(200, ACME),
      json(200, ACME),
      empty(204),
      answer([A]),
    ]);
    const u = userEvent.setup();
    show(ok([A, B]), { canRestore: true });
    await openRemove(u);
    await confirm(u);
    await waitFor(() => expect(within(dialog()).getByText(ARCHIVED_ON_DELETE)).toBeInTheDocument());
    await u.click(within(dialog()).getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(screen.getByText(/This supplier was archived on/)).toBeInTheDocument());
    await waitFor(() => expect(calls).toHaveLength(4));
    await u.click(screen.getByRole('button', { name: 'Restore supplier' }));
    await waitFor(() => expect(removeButton('SKU-B-200')).toBeInTheDocument());
    await openRemove(u, 'SKU-B-200');
    await confirm(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(AFTER('SKU-B-200')));
    await waitFor(() => expect(calls).toHaveLength(8));
    await tick();
    expect(what(calls)).toEqual([
      `DELETE ${ENTRY(A.id)}`,
      `GET ${SUPPLIER}`,
      `GET ${SUPPLIER}`,
      `GET ${LIST}`,
      `POST ${SUPPLIER}/restoration`,
      `GET ${SUPPLIER}`,
      `DELETE ${ENTRY(B.id)}`,
      `GET ${LIST}`,
    ]);
  });

  it('a 409 whose supplier re-read reads live: the sentence under Not removed, Close only; Close re-reads the section only (no page re-read)', async () => {
    const calls = stubFetch([refused('supplier_product.something', 'This entry is in use.'), json(200, ACME), answer([A])]);
    const u = userEvent.setup();
    show(ok([A]));
    await openRemove(u);
    await confirm(u);
    await waitFor(() => expect(within(dialog()).getByText('This entry is in use.')).toBeInTheDocument());
    expect(buttons()).toEqual(['Close']);
    await u.click(within(dialog()).getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(calls).toHaveLength(3));
    await tick();
    expect(what(calls)).toEqual([`DELETE ${ENTRY(A.id)}`, `GET ${SUPPLIER}`, `GET ${LIST}`]);
    expect(screen.queryByText(/This supplier was archived on/)).toBeNull();
  });

  it('a 403: the sentence names "Edit supplier catalogues" under Not removed; Remove stays', async () => {
    stubFetch([problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    show(ok([A]));
    await openRemove(u);
    await confirm(u);
    await waitFor(() => expect(within(dialog()).getByText(/permission, which you no longer have/)).toBeInTheDocument());
    expect(within(dialog()).getByText('Edit supplier catalogues')).toBeInTheDocument();
    expect(within(dialog()).getByText('Not removed')).toBeInTheDocument();
    expect(buttons()).toEqual(['Cancel', 'Remove']);
  });

  // UX ux-s6-rulings.md §1 (S-6 RED L-2 (a)): an interrupted Remove is final — Close only, under its own heading. This pin
  // moved on purpose from ['Cancel', 'Remove'] (DV-5, reversed for the catalogue): the sentence says "Close this", and a
  // second press after a committed removal would answer 404 under Not removed (RED probe PR-I), now unreachable here.
  const interrupted = async (reply: 'hang' | Response, rest: Response[]) => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const calls = stubFetch([reply, ...rest]);
    const u = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    show(ok([A, B]));
    await openRemove(u);
    await confirm(u);
    if (reply === 'hang') {
      await act(async () => {
        await vi.advanceTimersByTimeAsync(31_000);
      });
    }
    await waitFor(() => expect(within(dialog()).getByText(INTERRUPTED('SKU-A-100'))).toBeInTheDocument());
    return { calls, u };
  };

  it('interrupted (timeout): "Couldn\'t reach", the interrupted sentence, Close only and no Remove; Close re-reads; the row gone → focus on the heading', async () => {
    try {
      const { calls, u } = await interrupted('hang', [answer([B])]);
      expect(within(dialog()).getByText("We couldn't reach the server.")).toBeInTheDocument();
      expect(within(dialog()).queryByText('Not removed')).toBeNull();
      expect(buttons()).toEqual(['Close']);
      expect(within(dialog()).queryByRole('button', { name: 'Remove' })).toBeNull();
      await u.click(within(dialog()).getByRole('button', { name: 'Close' }));
      await waitFor(() => expect(skus()).toEqual(['B-200']));
      expect(what(calls)).toEqual([`DELETE ${ENTRY(A.id)}`, `GET ${LIST}`]);
      await waitFor(() => expect(document.activeElement).toBe(catalogueHeading()));
      // Nothing is known to have been removed: nothing is said.
      expect(pageRegion().textContent).toBe('');
    } finally {
      vi.useRealTimers();
    }
  });

  it('interrupted (a 500): "Something went wrong", Close only; Close re-reads; the entry still listed → focus on its Remove', async () => {
    try {
      const { calls, u } = await interrupted(problem(500, 'internal'), [answer([A, B])]);
      expect(within(dialog()).getByText('Something went wrong on our side.')).toBeInTheDocument();
      expect(buttons()).toEqual(['Close']);
      await u.click(within(dialog()).getByRole('button', { name: 'Close' }));
      await waitFor(() => expect(what(calls)).toEqual([`DELETE ${ENTRY(A.id)}`, `GET ${LIST}`]));
      await tick();
      expect(skus()).toEqual(['A-100', 'B-200']);
      await waitFor(() => expect(document.activeElement).toBe(removeButton('SKU-A-100')));
    } finally {
      vi.useRealTimers();
    }
  });
});

// The S-5 carries (suppliers-s5-red.md M-1, suppliers-s5-red2.md L-2): a row's Remove pressed while the Add press's
// first read is in flight wins, like Edit — the late answer opens nothing, and Add stops loading at once.
describe('S-6 — Remove pressed while an Add read is in flight (the S-5 M-1 / RED-2 L-2 twins)', () => {
  it('TD twin — Add stops loading at once, before that read answers; when it answers, one dialog (the Remove\'s)', async () => {
    const r = held();
    stubFetch([r.reply]);
    const u = userEvent.setup();
    show(ok([A]), { canAdd: true });
    await u.click(within(section()).getByRole('button', { name: 'Add to catalogue' }));
    expect(screen.getAllByRole('button', { name: 'Add to catalogue', hidden: true })[0]).toHaveAttribute('aria-busy', 'true');
    await u.click(removeButton('SKU-A-100'));
    expect(screen.getAllByRole('button', { name: 'Add to catalogue', hidden: true })[0]).not.toHaveAttribute('aria-busy');
    await act(async () => r.release(json(200, page([NEW1]))));
    await tick();
    const dialogs = screen.queryAllByRole('dialog', { hidden: true });
    expect(dialogs).toHaveLength(1);
    expect(within(dialogs[0]!).getByRole('heading', { name: 'Remove SKU-A-100 — Item A-100 from this catalogue?' })).toBeInTheDocument();
  });
});

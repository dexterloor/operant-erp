import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ refresh: nav.refresh }) }));
import { leaveArrivalMessage, takeArrivalMessage } from '@/app/(app)/arrivalMessage';
import { SupplierPage } from '@/app/(app)/suppliers/[id]/SupplierPage';
import type { Supplier, SupplierProduct } from '@/lib/api/suppliers';
import type { CatalogueLoad } from '@/lib/suppliers/load';
import { held, json, problem, refused, renderWired, stubFetch, textsOf, type Recorded } from './harness';

// Suppliers S-4 — Edit a catalogue entry (authority, in precedence: bk31-placement-confirm.md "Revision 1 — FINAL" FINAL (e)
// items 2–7; bk31-placement-confirm-judge.md rulings 1–2; bk31-cost-basis-po.md Revision 1 FINAL; suppliers-final-red.md
// S-4 row: M-1, M-5, M-6 test 10; suppliers-plan.md Revision 1 — FINAL §R1-4 S-4 tests 1–9; suppliers-s3-red.md L-3's
// case for test 8; suppliers-ux-copy.md Revision 1 — FINAL S-4 table as overridden by the FINAL). The row's Edit opens a
// dialog over Supplier SKU · Unit cost · Lead time · Minimum order, initial values as rendered; Save sends the changed
// members only (a merge patch), never the item or its unit. The Minimum order field shows the ROW's unit code after it
// (spoken by `unitSuffixSpoken`) and the Unit cost field `per {code}` (spoken by `unitPerSpoken` + "."). After a 200 the
// dialog closes, focus is back on the row's Edit, the page's region says "{sku} was saved." and the section re-reads,
// stamped, so an older re-read answered late never undoes a newer one. Every writer of the page's region is counted
// (`announce`), and a failed re-read's correction is written only while nothing else wrote since (`replaceIfCurrent`).
// A C1 (the supplier archived meanwhile) leaves Close only and, on Close, the page re-reads the supplier.

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
const B = entry('e0000000-0000-4000-8000-000000000002', 'B-200', { unit_of_measure_code: 'KG', minimum_order_quantity: '1.000000' });
const ok = (entries: SupplierProduct[]): CatalogueLoad => ({ kind: 'ok', entries, nextCursor: null });
const answer = (entries: SupplierProduct[]) => json(200, { items: entries, next_cursor: null, previous_cursor: null, tenant_has_rows: entries.length > 0 });
const violation = (pointer: string, message: string, code = 'out_of_range') => problem(422, 'validation-failed', { errors: [{ pointer, code, message }] });

const show = (initial: CatalogueLoad, { supplier = ACME, canEditSupplier = false, canRestore = false, canEdit = true, canAdd = false } = {}) =>
  renderWired(<SupplierPage initial={supplier} canEdit={canEditSupplier} canRestore={canRestore} catalogue={{ initial, canOpenItem: false, canEdit, canAdd }} />);
const pageRegion = () => document.querySelector<HTMLElement>('section > [role="status"]')!;
const section = () => screen.getByRole('region', { name: 'Catalogue' });
const rows = () => within(section()).queryAllByRole('row').slice(1);
const cells = (row: HTMLElement) => within(row).getAllByRole('cell').map((c) => c.textContent);
const editButton = (sku: string) => within(section()).getByRole('button', { name: `Edit ${sku}` });
const dialog = () => screen.getByRole('dialog');
const field = (name: string) => within(dialog()).getByRole('textbox', { name });
const bodyOf = (c: Recorded) => JSON.parse(c.body ?? 'null') as Record<string, unknown>;
const ENTRY_URL = (id: string) => new RegExp(`/bff/api/suppliers/${SID_}/products/${id}$`);
const LIST_URL = new RegExp(`/bff/api/suppliers/${SID_}/products$`);
const SUPPLIER_URL = new RegExp(`/bff/api/suppliers/${SID_}$`);

type U = ReturnType<typeof userEvent.setup>;
const openEdit = async (u: U, sku = 'SKU-A-100') => {
  await u.click(editButton(sku));
  return dialog();
};
const setField = async (u: U, name: string, value: string) => {
  const el = field(name);
  await u.clear(el);
  if (value !== '') await u.type(el, value);
};
const save = (u: U) => u.click(within(dialog()).getByRole('button', { name: 'Save' }));

const ARCHIVED_ON_UPDATE = "This supplier is archived, so its catalogue can't be changed until it's restored.";
/** S5 (`SupplierWriter::ARCHIVED`): the supplier's own Save on a stale page. */
const S5 = "This supplier is archived, so it can't be changed until it's restored.";
const P1 = 'Someone else was changing this at the same moment. Try again.';
const LEAD_TIME_RANGE = 'Lead time must be a whole number of days, 0 or more.';
const UNIT_COST_SHAPE = 'This field must be a non-negative decimal string with at most 15 integer digits and 4 fractional digits.';
const S4_ARCHIVED = "This catalogue can't be changed while the supplier is archived. It comes back unchanged when the supplier is restored.";
const MOQ_HINT = "The smallest amount this supplier accepts in one order, in the item's unit. If this is the item's preferred supplier, reorder suggestions never suggest less than this. Purchase orders don't check it. Enter 1 if there's no minimum.";
const COST_HINT = "What this supplier charges for one of the item's units. Use a point for decimals and no commas, for example 12.50.";
const SKU_HINT = 'The code this supplier uses for the item. If it has none, use your own SKU.';
const LEAD_HINT = 'How many days the goods usually take to arrive after you order. New purchase order lines take it from here; lines already on an order keep theirs.';
const SAVED_A = 'SKU-A-100 was saved.';
const STALE_A = "SKU-A-100 was saved. We couldn't load the list again, so it may be out of date.";

describe('S-4 — the Edit dialog: what it shows', () => {
  it('title, item line, the four fields with their initial values as rendered (MOQ trimmed), hints and Save unavailable while nothing changed', async () => {
    const u = userEvent.setup();
    // S-6 (UX ux-s6-rulings.md §2): the full seat (Add offered) sees `itemNote`; the no-Add seat is the next test.
    show(ok([A]), { canAdd: true });
    const d = await openEdit(u);
    expect(within(d).getByRole('heading', { name: 'Edit SKU-A-100 — Item A-100 in this catalogue' })).toBeInTheDocument();
    expect(within(d).getByText('Item: SKU-A-100 — Item A-100')).toBeInTheDocument();
    // S-6 moved this pin deliberately: `itemNote` ships with Remove (UX S-6; ux-copy Response M-2), its own paragraph
    // after the item line, both in the dialog's description.
    expect(within(d).getByText('To list a different item, add it and remove this one.')).toBeInTheDocument();
    expect(d).toHaveAccessibleDescription('Item: SKU-A-100 — Item A-100 To list a different item, add it and remove this one.');
    expect(within(d).getAllByRole('textbox').map((t) => [t.getAttribute('name'), (t as HTMLInputElement).value])).toEqual([
      ['supplier_sku', 'A-100'],
      ['unit_cost', '10.0000'],
      ['lead_time_days', '7'],
      ['minimum_order_quantity', '2.5'],
    ]);
    expect(field('Supplier SKU')).toHaveAccessibleDescription(SKU_HINT);
    expect(field('Lead time (days)')).toHaveAccessibleDescription(LEAD_HINT);
    expect(within(d).getByRole('button', { name: 'Save' })).toHaveAttribute('aria-disabled', 'true');
  });

  // S-6 RED L-4, UX ux-s6-rulings.md §2: a composed seat with supplier_product.view + .edit but no inventory_item.view has
  // Edit and Remove but no Add, so the note that says "add it" is not shown; the item line stands alone, no other sentence.
  it('RED L-4 — a seat offered no Add (no ListItems): no itemNote; the dialog\'s description is the item line alone', async () => {
    const u = userEvent.setup();
    show(ok([A]), { canAdd: false });
    const d = await openEdit(u);
    expect(within(d).queryByText('To list a different item, add it and remove this one.')).toBeNull();
    expect(d).toHaveAccessibleDescription('Item: SKU-A-100 — Item A-100');
  });

  // FINAL (e) item 5: the two ruled hints, pinned whole (the suffix's spoken word comes first in each description).
  it('item 5 — minimumOrderHint (R1-B) and the ruled per-unit unitCostHint', async () => {
    const u = userEvent.setup();
    show(ok([A]));
    await openEdit(u);
    expect(field('Minimum order quantity')).toHaveAccessibleDescription(`each. ${MOQ_HINT}`);
    expect(field('Unit cost (GBP)')).toHaveAccessibleDescription(`each. ${COST_HINT}`);
  });

  // FINAL (e) item 4: the suffixes come from the ROW's unit_of_measure_code; the cost is spoken per ONE unit.
  it('item 4 — a KG row: Minimum order shown "KG" spoken "kilograms."; Unit cost shown "per KG" spoken "per kilogram."', async () => {
    const u = userEvent.setup();
    show(ok([A, B]));
    await openEdit(u, 'SKU-B-200');
    const moq = field('Minimum order quantity');
    const cost = field('Unit cost (GBP)');
    expect(moq.parentElement!.querySelector('[aria-hidden="true"]')?.textContent).toBe('KG');
    expect(cost.parentElement!.querySelector('[aria-hidden="true"]')?.textContent).toBe('per KG');
    expect(moq).toHaveAccessibleDescription(`kilograms. ${MOQ_HINT}`);
    expect(cost).toHaveAccessibleDescription(`per kilogram. ${COST_HINT}`);
  });

  it('item 4 — an EA row: shown "EA" and "per EA", spoken "each." and "each."', async () => {
    const u = userEvent.setup();
    show(ok([A, B]));
    await openEdit(u, 'SKU-A-100');
    const moq = field('Minimum order quantity');
    const cost = field('Unit cost (GBP)');
    expect(moq.parentElement!.querySelector('[aria-hidden="true"]')?.textContent).toBe('EA');
    expect(cost.parentElement!.querySelector('[aria-hidden="true"]')?.textContent).toBe('per EA');
    expect(moq).toHaveAccessibleDescription(expect.stringMatching(/^each\. The smallest/));
    expect(cost).toHaveAccessibleDescription(expect.stringMatching(/^each\. What this supplier/));
  });

  it('the Unit cost label names the entry\'s own currency', async () => {
    const u = userEvent.setup();
    show(ok([entry('e0000000-0000-4000-8000-000000000003', 'C-300', { currency_code: 'JPY', unit_cost: '250.0000' })]));
    await openEdit(u, 'SKU-C-300');
    expect(field('Unit cost (JPY)')).toHaveValue('250.0000');
  });

  it('no Edit for a seat without UpdateSupplierProduct, nor on an archived supplier', () => {
    const { unmount } = show(ok([A]), { canEdit: false });
    expect(within(section()).queryAllByRole('button')).toEqual([]);
    unmount();
    show(ok([A]), { supplier: ARCHIVED });
    expect(within(section()).queryAllByRole('button')).toEqual([]);
    expect(within(section()).getAllByRole('columnheader').map((h) => h.textContent)).not.toContain('Actions');
  });

  it('Cancel sends nothing and leaves the page region as it was (FE-R9: never on Cancel)', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    leaveArrivalMessage(`/suppliers/${SID_}`, 'ACME was created.');
    show(ok([A]));
    await waitFor(() => expect(pageRegion().textContent).toBe('ACME was created.'));
    await openEdit(u);
    await setField(u, 'Lead time (days)', '9');
    await u.click(within(dialog()).getByRole('button', { name: 'Cancel' }));
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(calls).toEqual([]);
    expect(pageRegion().textContent).toBe('ACME was created.');
    expect(document.activeElement).toBe(editButton('SKU-A-100'));
  });
});

describe('S-4 — Save', () => {
  // Plan test 1 (+ X-8: focus back on the row's Edit).
  it('test 1 — the changed member only (PATCH), untouched cost sends nothing; then "{sku} was saved.", focus on the row\'s Edit, the section re-reads', async () => {
    const calls = stubFetch([json(200, { ...A, lead_time_days: 14 }), answer([{ ...A, lead_time_days: 14 }])]);
    const u = userEvent.setup();
    show(ok([A]));
    await openEdit(u);
    await setField(u, 'Lead time (days)', '14');
    await save(u);
    await waitFor(() => expect(cells(rows()[0]!)[3]).toBe('14 days'));
    expect(calls.map((c) => [c.method, c.url.replace(/^.*\/bff\/api/, '')])).toEqual([
      ['PATCH', `/suppliers/${SID_}/products/${A.id}`],
      ['GET', `/suppliers/${SID_}/products`],
    ]);
    expect(bodyOf(calls[0]!)).toEqual({ lead_time_days: 14 });
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(pageRegion().textContent).toBe(SAVED_A);
    await waitFor(() => expect(document.activeElement).toBe(editButton('SKU-A-100')));
  });

  // S-4 RED L-3 (FE-D1): focus is back on the row's Edit BEFORE "{sku} was saved." is written (the S-3 RED M-1 shape).
  it('RED L-3 — after a 200 the row\'s Edit takes focus while the page region is still empty; the saved sentence follows', async () => {
    stubFetch([json(200, { ...A, lead_time_days: 14 }), answer([{ ...A, lead_time_days: 14 }])]);
    const u = userEvent.setup();
    show(ok([A]));
    await openEdit(u);
    await setField(u, 'Lead time (days)', '14');
    const seen: { el: string; region: string }[] = [];
    const real = HTMLElement.prototype.focus;
    const spy = vi.spyOn(HTMLElement.prototype, 'focus').mockImplementation(function (this: HTMLElement, opts?: FocusOptions) {
      seen.push({ el: this.getAttribute('aria-label') ?? this.tagName, region: pageRegion().textContent ?? '' });
      real.call(this, opts);
    });
    try {
      await save(u);
      await waitFor(() => expect(pageRegion().textContent).toBe(SAVED_A));
      await waitFor(() => expect(document.activeElement).toBe(editButton('SKU-A-100')));
    } finally {
      spy.mockRestore();
    }
    const onEdit = seen.filter((c) => c.el === 'Edit SKU-A-100');
    expect(onEdit.length).toBeGreaterThan(0);
    expect(onEdit[0]!.region).toBe('');
    expect(pageRegion().textContent).toBe(SAVED_A);
  });

  // S-4 RED L-1: the validated 200 body is committed over the row under a fresh stamp, so a reopened Edit (before the
  // re-read answers, or after it failed) starts from what was saved.
  it('RED L-1 — cost saved as 12, its re-read held: the row shows 12.00 GBP (the PO-1 money trim) and a reopened Edit starts from 12.0000', async () => {
    const r = held();
    stubFetch([json(200, { ...A, unit_cost: '12.0000' }), r.reply]);
    const u = userEvent.setup();
    show(ok([A]));
    await openEdit(u);
    await setField(u, 'Unit cost (GBP)', '12');
    await save(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(SAVED_A));
    expect(cells(rows()[0]!)[2]).toBe('12.00 GBP');
    await openEdit(u);
    expect(field('Unit cost (GBP)')).toHaveValue('12.0000');
    await act(async () => r.release(answer([{ ...A, unit_cost: '12.0000' }])));
  });

  // Plan test 2 + FINAL (e) item 7.
  it('test 2 / item 7 — every field changed: the body holds exactly the four editable members, never inventory_item_id or unit_of_measure_code', async () => {
    const calls = stubFetch([json(200, A), answer([A])]);
    const u = userEvent.setup();
    show(ok([A]));
    await openEdit(u);
    await setField(u, 'Supplier SKU', 'A-101');
    await setField(u, 'Unit cost (GBP)', '13.25');
    await setField(u, 'Lead time (days)', '14');
    await setField(u, 'Minimum order quantity', '3');
    await save(u);
    await waitFor(() => expect(calls).toHaveLength(2));
    const body = bodyOf(calls[0]!);
    expect(Object.keys(body).sort()).toEqual(['lead_time_days', 'minimum_order_quantity', 'supplier_sku', 'unit_cost']);
    expect(body).not.toHaveProperty('inventory_item_id');
    expect(body).not.toHaveProperty('unit_of_measure_code');
    expect(calls[0]!.url).toMatch(ENTRY_URL(A.id));
  });

  // Plan test 3 (L-10, M-1).
  it('test 3 — unit_cost and minimum_order_quantity are JSON strings as typed; lead_time_days a JSON number for digits', async () => {
    const calls = stubFetch([json(200, A), answer([A])]);
    const u = userEvent.setup();
    show(ok([A]));
    await openEdit(u);
    await setField(u, 'Unit cost (GBP)', '13.25');
    await setField(u, 'Lead time (days)', '14');
    await setField(u, 'Minimum order quantity', '3');
    await save(u);
    await waitFor(() => expect(calls).toHaveLength(2));
    expect(calls[0]!.body).toBe(JSON.stringify({ unit_cost: '13.25', lead_time_days: 14, minimum_order_quantity: '3' }));
  });

  // Plan test 4 (M-1).
  it('test 4 — lead time "7.5" is sent as typed; the server\'s sentence shows beside the field, which takes focus', async () => {
    const calls = stubFetch([violation('/lead_time_days', LEAD_TIME_RANGE)]);
    const u = userEvent.setup();
    show(ok([A]));
    await openEdit(u);
    await setField(u, 'Lead time (days)', '7.5');
    await save(u);
    await waitFor(() => expect(field('Lead time (days)')).toHaveAccessibleDescription(`${LEAD_HINT} ${LEAD_TIME_RANGE}`));
    expect(bodyOf(calls[0]!)).toEqual({ lead_time_days: '7.5' });
    expect(document.activeElement).toBe(field('Lead time (days)'));
    expect(field('Lead time (days)')).toHaveAttribute('aria-invalid', 'true');
    expect(calls).toHaveLength(1);
  });

  // Plan test 7 (422 half).
  it('test 7 — a 422 on the cost: the sentence beside Unit cost, focus there, the dialog stays with Save', async () => {
    stubFetch([violation('/unit_cost', UNIT_COST_SHAPE, 'not_a_decimal')]);
    const u = userEvent.setup();
    show(ok([A]));
    await openEdit(u);
    await setField(u, 'Unit cost (GBP)', '12,50');
    await save(u);
    await waitFor(() => expect(document.activeElement).toBe(field('Unit cost (GBP)')));
    expect(field('Unit cost (GBP)')).toHaveAccessibleDescription(`each. ${COST_HINT} ${UNIT_COST_SHAPE}`);
    expect(within(dialog()).getByRole('button', { name: 'Save' })).toBeInTheDocument();
  });

  // Plan test 9 (H-2).
  it('test 9 — emptied Unit cost, Lead time and Minimum order: each named sentence beside its field, no request, focus on the first', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    leaveArrivalMessage(`/suppliers/${SID_}`, 'ACME was created.');
    show(ok([A]));
    await waitFor(() => expect(pageRegion().textContent).toBe('ACME was created.'));
    await openEdit(u);
    await setField(u, 'Unit cost (GBP)', '');
    await setField(u, 'Lead time (days)', '');
    await setField(u, 'Minimum order quantity', '');
    await save(u);
    expect(field('Unit cost (GBP)')).toHaveAccessibleDescription(`each. ${COST_HINT} Enter the unit cost, or 0.`);
    expect(field('Lead time (days)')).toHaveAccessibleDescription(`${LEAD_HINT} Enter a number of days, or 0.`);
    expect(field('Minimum order quantity')).toHaveAccessibleDescription(`each. ${MOQ_HINT} Enter the minimum order, or 1 if there's none.`);
    expect(document.activeElement).toBe(field('Unit cost (GBP)'));
    // A second press says the same and still sends nothing; the region is not emptied by a press that sends nothing.
    await save(u);
    expect(calls).toEqual([]);
    expect(pageRegion().textContent).toBe('ACME was created.');
  });

  it('test 9 — only the minimum emptied: its sentence alone, focus on it, no request', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    show(ok([A]));
    await openEdit(u);
    await setField(u, 'Minimum order quantity', '');
    await save(u);
    expect(field('Minimum order quantity')).toHaveAccessibleDescription(`each. ${MOQ_HINT} Enter the minimum order, or 1 if there's none.`);
    expect(field('Unit cost (GBP)')).not.toHaveAttribute('aria-invalid');
    expect(document.activeElement).toBe(field('Minimum order quantity'));
    expect(calls).toEqual([]);
  });

  // FE-R9: the press that will send empties the page's region, after every guard, through the counted writer.
  it('a Save press that will send empties the page region at the press, then "{sku} was saved."', async () => {
    const h = held();
    stubFetch([h.reply, answer([A])]);
    const u = userEvent.setup();
    leaveArrivalMessage(`/suppliers/${SID_}`, 'ACME was created.');
    show(ok([A]));
    await waitFor(() => expect(pageRegion().textContent).toBe('ACME was created.'));
    const t = textsOf(pageRegion());
    await openEdit(u);
    await setField(u, 'Lead time (days)', '9');
    await save(u);
    expect(pageRegion().textContent).toBe('');
    await act(async () => h.release(json(200, { ...A, lead_time_days: 9 })));
    await waitFor(() => expect(pageRegion().textContent).toBe(SAVED_A));
    expect(t.texts).toEqual(['ACME was created.', '', SAVED_A]);
    t.stop();
  });

  // FINAL (e) item 2: the update's 200 body is validated like a list row (the unit is read from the entry).
  it('item 2 — a 200 body without unit_of_measure_code is not a saved entry: "Something went wrong" with the interrupted sentence, nothing spoken', async () => {
    const { unit_of_measure_code: _drop, ...noUnit } = A;
    void _drop;
    stubFetch([json(200, noUnit)]);
    const u = userEvent.setup();
    show(ok([A]));
    await openEdit(u);
    await setField(u, 'Lead time (days)', '9');
    await save(u);
    await waitFor(() => expect(within(dialog()).getByText("We can't tell whether your changes to SKU-A-100 were saved. Close this and check the catalogue before you try again.")).toBeInTheDocument());
    expect(within(dialog()).getByText('Something went wrong on our side.')).toBeInTheDocument();
    expect(pageRegion().textContent).toBe('');
  });

  it('an interrupted Save (unreachable) says so; Close then re-reads the section', async () => {
    const calls = stubFetch(['hang', answer([A])]);
    vi.useFakeTimers({ shouldAdvanceTime: true });
    try {
      const u = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
      show(ok([A]));
      await openEdit(u);
      await setField(u, 'Lead time (days)', '9');
      await save(u);
      await act(async () => {
        await vi.advanceTimersByTimeAsync(30_001);
      });
      await waitFor(() => expect(within(dialog()).getByText("We couldn't reach the server.")).toBeInTheDocument());
      await u.click(within(dialog()).getByRole('button', { name: 'Cancel' }));
      await waitFor(() => expect(calls).toHaveLength(2));
      expect(calls[1]!.url).toMatch(LIST_URL);
    } finally {
      vi.useRealTimers();
    }
  });
});

describe('S-4 — refusals', () => {
  // Plan test 7 (404 half).
  it('test 7 — 404 (removed elsewhere): "We couldn\'t find that." under Not saved, Close only; Close re-reads the section (the row goes)', async () => {
    const calls = stubFetch([problem(404, 'not-found'), answer([])]);
    const u = userEvent.setup();
    show(ok([A]));
    await openEdit(u);
    await setField(u, 'Lead time (days)', '9');
    await save(u);
    await waitFor(() => expect(within(dialog()).getByText("We couldn't find that. It may have been deleted, or you may not have access.")).toBeInTheDocument());
    expect(within(dialog()).getByText('Not saved')).toBeInTheDocument();
    expect(within(dialog()).getAllByRole('button').map((b) => b.textContent)).toEqual(['Close']);
    await u.click(within(dialog()).getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(within(section()).getByText("This supplier's catalogue is empty.")).toBeInTheDocument());
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET']);
    expect(calls[1]!.url).toMatch(LIST_URL);
    // Focus never on <body>: the row's Edit is gone, so the section's heading.
    expect(document.activeElement).toBe(within(section()).getByRole('heading', { level: 2, name: 'Catalogue' }));
  });

  // Plan test 7 (P1 half).
  it('test 7 — P1 (contention): the sentence under Not saved, the edits kept, Save still offered; a second press sends again', async () => {
    const calls = stubFetch([refused('transaction.contention', P1), json(200, { ...A, lead_time_days: 9 }), answer([{ ...A, lead_time_days: 9 }])]);
    const u = userEvent.setup();
    show(ok([A]));
    await openEdit(u);
    await setField(u, 'Lead time (days)', '9');
    await save(u);
    await waitFor(() => expect(within(dialog()).getByText(P1)).toBeInTheDocument());
    expect(field('Lead time (days)')).toHaveValue('9');
    expect(within(dialog()).getByRole('button', { name: 'Save' })).not.toHaveAttribute('aria-disabled');
    await save(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(SAVED_A));
    // P1 proves nothing about the supplier, so no re-read follows it (only the second press's catalogue re-read).
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'PATCH', 'GET']);
    expect(calls[2]!.url).toMatch(LIST_URL);
  });

  it('a 403 (permission lost): the sentence names "Edit supplier catalogues"; Save stays', async () => {
    stubFetch([problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    show(ok([A]));
    await openEdit(u);
    await setField(u, 'Lead time (days)', '9');
    await save(u);
    await waitFor(() => expect(within(dialog()).getByText(/permission, which you no longer have/)).toBeInTheDocument());
    expect(within(dialog()).getByText('Edit supplier catalogues')).toBeInTheDocument();
    expect(within(dialog()).getByRole('button', { name: 'Save' })).toBeInTheDocument();
  });

  // Plan test 5 (M-3): C1 — the supplier was archived meanwhile.
  it('test 5 — C1: the server\'s sentence, Close only; Close → the page re-reads the supplier: its archived banner, the S-4 note, no Edit', async () => {
    const calls = stubFetch([refused('supplier.is_archived', ARCHIVED_ON_UPDATE), json(200, ARCHIVED), json(200, ARCHIVED), answer([A])]);
    const u = userEvent.setup();
    show(ok([A]), { canRestore: true });
    await openEdit(u);
    await setField(u, 'Lead time (days)', '9');
    await save(u);
    await waitFor(() => expect(within(dialog()).getByText(ARCHIVED_ON_UPDATE)).toBeInTheDocument());
    expect(within(dialog()).getAllByRole('button').map((b) => b.textContent)).toEqual(['Close']);
    // The dialog's own check read the supplier; the page shows nothing new until Close.
    expect(screen.queryByText(/This supplier was archived on/)).toBeNull();
    await u.click(within(dialog()).getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(screen.getByText(/This supplier was archived on/)).toBeInTheDocument());
    expect(within(section()).getByText(S4_ARCHIVED)).toBeInTheDocument();
    expect(within(section()).queryAllByRole('button', { name: /^Edit / })).toEqual([]);
    expect(screen.getByRole('button', { name: 'Restore supplier' })).toBeInTheDocument();
    await waitFor(() => expect(calls).toHaveLength(4));
    // S-4 RED L-2 (b), DV-12: the page reads the supplier archived, so the router cache is refreshed once (Back to the list).
    await waitFor(() => expect(nav.refresh).toHaveBeenCalledTimes(1));
    expect(calls.map((c) => [c.method, SUPPLIER_URL.test(c.url) ? 'supplier' : LIST_URL.test(c.url) ? 'list' : 'entry'])).toEqual([
      ['PATCH', 'entry'],
      ['GET', 'supplier'],
      ['GET', 'supplier'],
      ['GET', 'list'],
    ]);
  });

  // Plan test 6 (M-3).
  it('test 6 — an archived supplier: no Edit; Restore supplier → Edit appears, same mount', async () => {
    stubFetch([json(200, ACME), json(200, ACME)]);
    const u = userEvent.setup();
    show(ok([A]), { supplier: ARCHIVED, canRestore: true });
    expect(within(section()).queryAllByRole('button')).toEqual([]);
    const before = section();
    await u.click(screen.getByRole('button', { name: 'Restore supplier' }));
    await waitFor(() => expect(editButton('SKU-A-100')).toBeInTheDocument());
    expect(section()).toBe(before);
  });
});

describe('S-4 RED — refusals and the page turning archived', () => {
  // S-4 RED L-2 (c), DV-1: a 409 whose supplier re-read answers LIVE (restored meanwhile) is the refusal as sent, Save kept.
  it('RED L-2 (c) — a 409 whose supplier re-read reads live: the sentence under Not saved, Cancel and Save, edits kept; Cancel re-reads the section only', async () => {
    const calls = stubFetch([refused('supplier.is_archived', ARCHIVED_ON_UPDATE), json(200, ACME), answer([A])]);
    const u = userEvent.setup();
    show(ok([A]));
    await openEdit(u);
    await setField(u, 'Lead time (days)', '9');
    await save(u);
    await waitFor(() => expect(within(dialog()).getByText(ARCHIVED_ON_UPDATE)).toBeInTheDocument());
    expect(within(dialog()).getByText('Not saved')).toBeInTheDocument();
    expect(within(dialog()).getAllByRole('button').map((b) => b.textContent)).toEqual(['Cancel', 'Save']);
    expect(field('Lead time (days)')).toHaveValue('9');
    await u.click(within(dialog()).getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(calls).toHaveLength(3));
    expect(calls.map((c) => [c.method, SUPPLIER_URL.test(c.url) ? 'supplier' : LIST_URL.test(c.url) ? 'list' : 'entry'])).toEqual([
      ['PATCH', 'entry'],
      ['GET', 'supplier'],
      ['GET', 'list'],
    ]);
    expect(screen.queryByText(/This supplier was archived on/)).toBeNull();
  });

  // S-4 RED M-1: an Edit dialog open when the page turns archived stays open with what was typed; its Save meets C1.
  it('RED M-1 — the supplier\'s Save answers S5 while an Edit is open: the dialog stays with the typed value and focus; Save → C1, Close only; Close, then Restore → no dialog comes back', async () => {
    const s = held();
    stubFetch([
      s.reply,
      json(200, ARCHIVED),
      refused('supplier.is_archived', ARCHIVED_ON_UPDATE),
      json(200, ARCHIVED),
      json(200, ARCHIVED),
      answer([A]),
      json(200, ACME),
      json(200, ACME),
    ]);
    const u = userEvent.setup();
    show(ok([A]), { canEditSupplier: true, canRestore: true });
    const name = screen.getByRole('textbox', { name: 'Name' });
    await u.clear(name);
    await u.type(name, 'Acme Ltd');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await openEdit(u);
    await setField(u, 'Lead time (days)', '9');
    await act(async () => s.release(refused('supplier.is_archived', S5)));
    await waitFor(() => expect(screen.getByText(/This supplier was archived on/)).toBeInTheDocument());
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(field('Lead time (days)')).toHaveValue('9');
    expect(dialog().contains(document.activeElement)).toBe(true);
    await save(u);
    await waitFor(() => expect(within(dialog()).getByText(ARCHIVED_ON_UPDATE)).toBeInTheDocument());
    expect(within(dialog()).getAllByRole('button').map((b) => b.textContent)).toEqual(['Close']);
    await u.click(within(dialog()).getByRole('button', { name: 'Close' }));
    expect(screen.queryByRole('dialog')).toBeNull();
    // S-4 RED-2 L-1: the row's Edit is gone with the archived page, so focus falls back to the Catalogue heading (not <body>).
    expect(document.activeElement).toBe(within(section()).getByRole('heading', { level: 2, name: 'Catalogue' }));
    await u.click(await screen.findByRole('button', { name: 'Restore supplier' }));
    await waitFor(() => expect(pageRegion().textContent).toBe('ACME was restored.'));
    expect(screen.queryByRole('dialog')).toBeNull();
  });
});

describe('S-4 RED-2 — focus when the opener is gone', () => {
  // S-4 RED-2 L-1 (probe P2): the page reads archived across the Edit (the row's Edit is gone), but the dialog's Save answers
  // 200 (restored elsewhere meanwhile). Focus falls back to the Catalogue heading, BEFORE "{sku} was saved." is written.
  it('RED-2 L-1 — the Edit opener is gone when the dialog\'s Save answers 200: the Catalogue heading takes focus while the region is still empty; the saved sentence follows', async () => {
    const s = held();
    stubFetch([s.reply, json(200, ARCHIVED), json(200, { ...A, lead_time_days: 9 }), answer([{ ...A, lead_time_days: 9 }])]);
    const u = userEvent.setup();
    show(ok([A]), { canEditSupplier: true, canRestore: true });
    const name = screen.getByRole('textbox', { name: 'Name' });
    await u.clear(name);
    await u.type(name, 'Acme Ltd');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await openEdit(u);
    await setField(u, 'Lead time (days)', '9');
    await act(async () => s.release(refused('supplier.is_archived', S5)));
    await waitFor(() => expect(screen.getByText(/This supplier was archived on/)).toBeInTheDocument());
    const seen: { el: string; region: string }[] = [];
    const real = HTMLElement.prototype.focus;
    const spy = vi.spyOn(HTMLElement.prototype, 'focus').mockImplementation(function (this: HTMLElement, opts?: FocusOptions) {
      seen.push({ el: (this.closest('[role="dialog"]') ? 'dialog:' : '') + `${this.tagName}:${this.getAttribute('aria-label') ?? this.textContent}`, region: pageRegion().textContent ?? '' });
      real.call(this, opts);
    });
    try {
      await save(u);
      await waitFor(() => expect(pageRegion().textContent).toBe(SAVED_A));
    } finally {
      spy.mockRestore();
    }
    const onHeading = seen.filter((c) => c.el === 'H2:Catalogue');
    expect(onHeading.length).toBeGreaterThan(0);
    expect(onHeading[0]!.region).toBe('');
    await waitFor(() => expect(document.activeElement).toBe(within(section()).getByRole('heading', { level: 2, name: 'Catalogue' })));
  });
});

describe('S-4 — the page region and the section\'s re-reads', () => {
  // Plan test 8 (a).
  it('test 8 (a) — the re-read after a save fails: "{sku} was saved." is replaced by the list-stale sentence; the rows stay', async () => {
    const r = held();
    stubFetch([json(200, { ...A, lead_time_days: 9 }), r.reply]);
    const u = userEvent.setup();
    show(ok([A]));
    const t = textsOf(pageRegion());
    await openEdit(u);
    await setField(u, 'Lead time (days)', '9');
    await save(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(SAVED_A));
    await act(async () => r.release(problem(503, 'service-unavailable')));
    await waitFor(() => expect(pageRegion().textContent).toBe(STALE_A));
    expect(t.texts).toEqual(['', SAVED_A, STALE_A]);
    t.stop();
    expect(rows()).toHaveLength(1);
  });

  // S-4 RED L-2 (a): a re-read answered 404 (the supplier gone) is a failed re-read too.
  it('RED L-2 (a) — the re-read after a save answers 404: "{sku} was saved." is replaced by the list-stale sentence; the rows stay', async () => {
    const r = held();
    stubFetch([json(200, { ...A, lead_time_days: 9 }), r.reply]);
    const u = userEvent.setup();
    show(ok([A]));
    const t = textsOf(pageRegion());
    await openEdit(u);
    await setField(u, 'Lead time (days)', '9');
    await save(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(SAVED_A));
    await act(async () => r.release(problem(404, 'not-found')));
    await waitFor(() => expect(pageRegion().textContent).toBe(STALE_A));
    expect(t.texts).toEqual(['', SAVED_A, STALE_A]);
    t.stop();
    expect(rows()).toHaveLength(1);
  });

  // Plan test 8 (b) = final RED M-5's first test: the supplier's Save writes between the entry's save and its failed re-read.
  it('test 8 (b) / M-5 — Edit A → 200, its re-read held; Save the supplier → "ACME was saved."; the re-read fails → the region keeps the supplier\'s sentence', async () => {
    const r = held();
    const calls = stubFetch([json(200, { ...A, lead_time_days: 9 }), r.reply, json(200, { ...ACME, name: 'Acme Ltd' }), json(200, { ...ACME, name: 'Acme Ltd' })]);
    const u = userEvent.setup();
    show(ok([A]), { canEditSupplier: true });
    await openEdit(u);
    await setField(u, 'Lead time (days)', '9');
    await save(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(SAVED_A));
    const name = screen.getByRole('textbox', { name: 'Name' });
    await u.clear(name);
    await u.type(name, 'Acme Ltd');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(pageRegion().textContent).toBe('ACME was saved.'));
    await act(async () => r.release(problem(503, 'service-unavailable')));
    await act(async () => {
      await new Promise((res) => setTimeout(res, 20));
    });
    expect(pageRegion().textContent).toBe('ACME was saved.');
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET', 'PATCH', 'GET']);
  });

  // Final RED M-5's second test: Restore supplier writes between the entry's save and its failed re-read.
  it('M-5 — Edit A → 200, its re-read held; Edit B → C1 → Close → the page reads archived; Restore supplier → "ACME was restored."; A\'s re-read fails → the region keeps the restore sentence', async () => {
    const r = held();
    stubFetch([
      json(200, { ...A, lead_time_days: 9 }),
      r.reply,
      refused('supplier.is_archived', ARCHIVED_ON_UPDATE),
      json(200, ARCHIVED),
      json(200, ARCHIVED),
      answer([A, B]),
      json(200, ACME),
      json(200, ACME),
    ]);
    const u = userEvent.setup();
    show(ok([A, B]), { canRestore: true });
    await openEdit(u, 'SKU-A-100');
    await setField(u, 'Lead time (days)', '9');
    await save(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(SAVED_A));
    await openEdit(u, 'SKU-B-200');
    await setField(u, 'Lead time (days)', '3');
    await save(u);
    await waitFor(() => expect(within(dialog()).getByText(ARCHIVED_ON_UPDATE)).toBeInTheDocument());
    await u.click(within(dialog()).getByRole('button', { name: 'Close' }));
    await u.click(await screen.findByRole('button', { name: 'Restore supplier' }));
    await waitFor(() => expect(pageRegion().textContent).toBe('ACME was restored.'));
    await act(async () => r.release(problem(503, 'service-unavailable')));
    await act(async () => {
      await new Promise((res) => setTimeout(res, 20));
    });
    expect(pageRegion().textContent).toBe('ACME was restored.');
  });

  // S-4 RED M-2: final RED M-5's two tests in the order that can see a raw writer — the supplier press made BEFORE the Edit,
  // its request still in flight, so no counted write falls between the entry's sentence and the supplier's answer.
  it('RED M-2 / M-5 — a supplier Save in flight across Edit A: its sentence, answered after A\'s, survives A\'s failed re-read', async () => {
    const s = held();
    const r = held();
    const calls = stubFetch([s.reply, json(200, { ...A, lead_time_days: 9 }), r.reply, json(200, { ...ACME, name: 'Acme Ltd' })]);
    const u = userEvent.setup();
    show(ok([A]), { canEditSupplier: true });
    const name = screen.getByRole('textbox', { name: 'Name' });
    await u.clear(name);
    await u.type(name, 'Acme Ltd');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await openEdit(u);
    await setField(u, 'Lead time (days)', '9');
    await save(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(SAVED_A));
    await act(async () => s.release(json(200, { ...ACME, name: 'Acme Ltd' })));
    await waitFor(() => expect(pageRegion().textContent).toBe('ACME was saved.'));
    await act(async () => r.release(problem(503, 'service-unavailable')));
    await act(async () => {
      await new Promise((res) => setTimeout(res, 20));
    });
    expect(pageRegion().textContent).toBe('ACME was saved.');
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'PATCH', 'GET', 'GET']);
  });

  it('RED M-2 / M-5 — Restore after an S5 that arrived across Edit A: the restore sentence survives A\'s failed re-read', async () => {
    const s = held();
    const r = held();
    const calls = stubFetch([s.reply, json(200, { ...A, lead_time_days: 9 }), r.reply, json(200, ARCHIVED), json(200, ACME), json(200, ACME)]);
    const u = userEvent.setup();
    show(ok([A]), { canEditSupplier: true, canRestore: true });
    const name = screen.getByRole('textbox', { name: 'Name' });
    await u.clear(name);
    await u.type(name, 'Acme Ltd');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await openEdit(u);
    await setField(u, 'Lead time (days)', '9');
    await save(u);
    await waitFor(() => expect(pageRegion().textContent).toBe(SAVED_A));
    await act(async () => s.release(refused('supplier.is_archived', S5)));
    await waitFor(() => expect(screen.getByText(S5)).toBeInTheDocument());
    await u.click(await screen.findByRole('button', { name: 'Restore supplier' }));
    await waitFor(() => expect(pageRegion().textContent).toBe('ACME was restored.'));
    await act(async () => r.release(problem(503, 'service-unavailable')));
    await act(async () => {
      await new Promise((res) => setTimeout(res, 20));
    });
    expect(pageRegion().textContent).toBe('ACME was restored.');
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'PATCH', 'GET', 'GET', 'POST', 'GET']);
  });

  // Final RED M-6, new test 10: the section's stamp rule.
  it('test 10 — Edit A (cost 12) → 200, re-read R1 held; Edit A again (cost 13) → 200, R2 answers 13.0000; R1 answers late with 12.0000 → the row shows 13.00 GBP (the PO-1 money trim)', async () => {
    const r1 = held();
    stubFetch([json(200, { ...A, unit_cost: '12.0000' }), r1.reply, json(200, { ...A, unit_cost: '13.0000' }), answer([{ ...A, unit_cost: '13.0000' }])]);
    const u = userEvent.setup();
    show(ok([A]));
    await openEdit(u);
    await setField(u, 'Unit cost (GBP)', '12');
    await save(u);
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await openEdit(u);
    await setField(u, 'Unit cost (GBP)', '13');
    await save(u);
    await waitFor(() => expect(cells(rows()[0]!)[2]).toBe('13.00 GBP'));
    await act(async () => r1.release(answer([{ ...A, unit_cost: '12.0000' }])));
    await act(async () => {
      await new Promise((res) => setTimeout(res, 20));
    });
    expect(cells(rows()[0]!)[2]).toBe('13.00 GBP');
  });

  // S-4 RED L-1's stamp (plant P-2, review of ad1d395): the 200 body is committed under a FRESH stamp, so a catalogue re-read sent BEFORE the
  // Save press and answered after it with the old values cannot undo the saved values.
  it('P-2 — a re-read sent before the Save press answers late with the old values: the row and a reopened Edit keep the saved cost', async () => {
    const r1 = held();
    const r2 = held();
    stubFetch([json(200, { ...A, lead_time_days: 9 }), r1.reply, json(200, { ...A, lead_time_days: 9, unit_cost: '13.0000' }), r2.reply]);
    const u = userEvent.setup();
    show(ok([A]));
    await openEdit(u);
    await setField(u, 'Lead time (days)', '9');
    await save(u);
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    // R1 (sent by the first save's close) is still held when the second Save is pressed.
    await openEdit(u);
    await setField(u, 'Unit cost (GBP)', '13');
    await save(u);
    await waitFor(() => expect(cells(rows()[0]!)[2]).toBe('13.00 GBP'));
    await act(async () => r1.release(answer([A])));
    await act(async () => {
      await new Promise((res) => setTimeout(res, 20));
    });
    expect(cells(rows()[0]!)[2]).toBe('13.00 GBP');
    await openEdit(u);
    expect(field('Unit cost (GBP)')).toHaveValue('13.0000');
    await act(async () => r2.release(answer([{ ...A, lead_time_days: 9, unit_cost: '13.0000' }])));
  });

  // suppliers-s3-red.md L-3, owed to S-4's test 8: a Save answered while a catalogue Retry is in flight keeps its
  // sentence; the count does not replace it.
  it('S-3 RED L-3 — a supplier Save answered while a catalogue Retry is in flight keeps its sentence; the count does not replace it', async () => {
    const r = held();
    stubFetch([r.reply, json(200, { ...ACME, name: 'Acme Ltd' }), json(200, { ...ACME, name: 'Acme Ltd' })]);
    const u = userEvent.setup();
    show({ kind: 'server-error' }, { canEditSupplier: true });
    await u.click(within(section()).getByRole('button', { name: 'Retry' }));
    const name = screen.getByRole('textbox', { name: 'Name' });
    await u.clear(name);
    await u.type(name, 'Acme Ltd');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(pageRegion().textContent).toBe('ACME was saved.'));
    await act(async () => r.release(answer([A])));
    await waitFor(() => expect(rows()).toHaveLength(1));
    await act(async () => {
      await new Promise((res) => setTimeout(res, 20));
    });
    expect(pageRegion().textContent).toBe('ACME was saved.');
  });
});

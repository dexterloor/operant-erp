import { StrictMode, type ReactElement } from 'react';
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

// Suppliers S-7 — Delete supplier (authority, in precedence: suppliers-final-red.md P-2 / S-7 row — the M-3 test is S-7's own,
// S-4 having shipped first; suppliers-plan.md Revision 1 — FINAL §R1-4 S-7 = Revision 0 S-6 + M-3; suppliers-ux-copy.md
// Revision 1 — FINAL S-7 `DELETE_SUPPLIER`; the BK-28 sentence as landed in `SupplierWriter::HAS_OPEN_PURCHASE_ORDERS` at
// `795e635`). **Delete supplier** on a live supplier's page for a DeleteSupplier holder: the shared danger dialog (focus
// Cancel). After a 204 the page stays and turns archived (dialog closes, focus h1, "After" spoken, one refresh); the
// catalogue section loses its actions in the same mount (M-3), even when the delete's re-read fails. R5 (open purchase
// orders, BK-28) / S6 (already archived) / a 404 leave Close only; P1, a 403 and an interrupted press keep Delete supplier.
// Any other close after an answer re-reads: an archived answer is committed (focus h1), a live one changes nothing (the
// typed edits stay; ItemPage's M-5 rule). The person's own Delete outranks their own Save (the items UX ruling M-3 / T-17 /
// L-1, carried by the item, warehouse and purchase-order pages): a Save sent before the 204 neither speaks, nor shows its
// refusal, nor commits its answer body, nor shows its failed re-read's region error (losing access is still shown). A
// catalogue Add press whose first read is still out when Delete supplier is pressed is superseded (the later press wins).

afterEach(() => {
  vi.unstubAllGlobals();
  nav.refresh.mockClear();
  takeArrivalMessage('');
});

const SID_ = '8c2d3e4f-5a6b-4c7d-8e9f-0a1b2c3d4e5f';
const ACME: Supplier = { id: SID_, code: 'ACME', name: 'Acme Supplies', country_code: 'GB', currency_code: 'GBP', payment_terms_days: 30, archived_at: null };
const ARCHIVED: Supplier = { ...ACME, archived_at: '2026-03-04T10:00:00Z' };
const entry = (id: string, sku: string): SupplierProduct => ({
  id,
  supplier_sku: sku,
  inventory_item: { id: `9a0b1c2d-3e4f-4a5b-8c6d-7e8f9a0b1c${id.slice(-2)}`, sku: `SKU-${sku}`, name: `Item ${sku}` },
  unit_cost: '10.0000',
  currency_code: 'GBP',
  lead_time_days: 7,
  minimum_order_quantity: '2.500000',
  unit_of_measure_code: 'EA',
});
const A = entry('e0000000-0000-4000-8000-000000000001', 'A-100');
const NEW1: Item = { id: '7b1c2d3e-4f5a-4b6c-8d7e-9f0a1b2c3d41', sku: 'NEW-1', name: 'New widget', unit_of_measure_code: 'EA', reorder_point: '0.000000', reorder_quantity: '0.000000', safety_stock: '0.000000', supplier: null, archived_at: null, holds_stock: false };
const ok = (entries: SupplierProduct[]): CatalogueLoad => ({ kind: 'ok', entries, nextCursor: null });
const answer = (entries: SupplierProduct[]) => json(200, { items: entries, next_cursor: null, previous_cursor: null, tenant_has_rows: entries.length > 0 });

/** suppliers-ux-copy.md Revision 1 — FINAL S-7 `DELETE_SUPPLIER.body` (REPLACES Revision 0: the reorder clause; PO TO CONFIRM). */
const BODY =
  "It moves to the archived suppliers. Until it's restored, it can't be chosen for new purchase orders or as an item's preferred supplier, its catalogue can't be changed, and reorder suggestions don't name it. Its catalogue entries are kept and come back with it. Items and purchase orders that already name it keep showing it.";
const AFTER = "ACME was deleted. It's now in the archived suppliers.";
const AFTER_RESTORED = 'ACME was deleted, and has since been restored.';
const INTERRUPTED = "We can't tell whether ACME was deleted. Press Delete supplier to try again.";
/** BK-28 as landed (`SupplierWriter::HAS_OPEN_PURCHASE_ORDERS` at `795e635`, `%s` = `Counted::of`), shown as sent. */
const R5 = "This supplier still has 2 open purchase orders, so it can't be deleted while it has any. Drafts count as open.";
/** S6 (`SupplierWriter::ALREADY_ARCHIVED`). */
const S6 = "This supplier is already archived. It's in the archived suppliers.";
/** S5 (`SupplierWriter::ARCHIVED`): UpdateSupplier's sentence on an archived supplier. */
const S5 = "This supplier is archived, so it can't be changed until it's restored.";
const CONTENTION = 'Some of the same records were being changed at the same moment. Nothing was saved; try again.';
const REREAD_FAILED = "We couldn't load this supplier again, so what's shown may be out of date.";
const ARCHIVED_BANNER = /This supplier was archived on/;
const ARCHIVED_NOTE = "This catalogue can't be changed while the supplier is archived. It comes back unchanged when the supplier is restored.";
const SAVED = 'ACME was saved.';

const show = (o: { s?: Supplier; canDelete?: boolean; canEdit?: boolean } = {}) =>
  renderWired(<SupplierPage initial={o.s ?? ACME} canEdit={o.canEdit ?? true} canRestore canDelete={o.canDelete ?? true} />);
const showWithCatalogue = (o: { canEdit?: boolean; canAdd?: boolean; canRemove?: boolean } = {}) =>
  renderWired(<SupplierPage initial={ACME} canEdit={false} canRestore canDelete catalogue={{ initial: ok([A]), canOpenItem: false, canEdit: o.canEdit ?? true, canAdd: o.canAdd ?? true, canRemove: o.canRemove ?? true }} />);
const region = () => document.querySelector<HTMLElement>('section > [role="status"]')!;
const dialog = () => screen.getByRole('dialog');
const deleteButton = () => screen.queryByRole('button', { name: 'Delete supplier' });
const confirm = () => within(dialog()).getByRole('button', { name: 'Delete supplier' });
const field = (name: string) => screen.queryByRole('textbox', { name });
const h1 = () => screen.getByRole('heading', { level: 1 });
const path = (c: Recorded) => c.url.replace(/^.*\/bff\/api/, '').replace(/\?.*$/, '');
const what = (calls: Recorded[]) => calls.map((c) => `${c.method} ${path(c)}`);
const SUPPLIER = `/suppliers/${SID_}`;
const LIST = `/suppliers/${SID_}/products`;

type U = ReturnType<typeof userEvent.setup>;
const openAndConfirm = async (u: U) => {
  await u.click(deleteButton()!);
  await u.click(confirm());
};
const tick = async (ms = 50) => {
  await act(async () => {
    await new Promise((t) => setTimeout(t, ms));
  });
};
const rename = async (u: U) => {
  await u.type(field('Name')!, 'x');
  await u.click(screen.getByRole('button', { name: 'Save' }));
};

describe('/suppliers/[id] — Delete supplier: the offer and the dialog (UX S-7, B-6)', () => {
  it('offered on a live supplier to a DeleteSupplier holder only, never on an archived one', () => {
    const one = show();
    expect(deleteButton()).toBeInTheDocument();
    one.unmount();
    const two = show({ canDelete: false });
    expect(deleteButton()).toBeNull();
    two.unmount();
    show({ s: ARCHIVED });
    expect(deleteButton()).toBeNull();
  });

  it('the dialog: "Delete {code} — {name}?", the S-7 body verbatim, focus on Cancel; Cancel sends nothing and re-reads nothing', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    show();
    await u.click(deleteButton()!);
    expect(within(dialog()).getByRole('heading', { name: 'Delete ACME — Acme Supplies?' })).toBeInTheDocument();
    expect(dialog()).toHaveAccessibleDescription(BODY);
    expect(within(dialog()).getByRole('button', { name: 'Cancel' })).toHaveFocus();
    await u.click(within(dialog()).getByRole('button', { name: 'Cancel' }));
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(calls).toEqual([]);
  });
});

describe('/suppliers/[id] — Delete supplier: answers', () => {
  it('a 204: DELETE with no body and no key, the re-read turns the page archived, Restore supplier, no form, focus h1, "After" once, one refresh', async () => {
    const calls = stubFetch([empty(204), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    show();
    const spoken = textsOf(region());
    await openAndConfirm(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    spoken.stop();
    expect(what(calls)).toEqual([`DELETE ${SUPPLIER}`, `GET ${SUPPLIER}`]);
    expect(calls[0]!.body).toBeNull();
    expect(calls[0]!.headers.get('idempotency-key')).toBeNull();
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(h1()).toHaveFocus();
    expect(screen.getByText(ARCHIVED_BANNER)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Restore supplier' })).toBeInTheDocument();
    expect(deleteButton()).toBeNull();
    expect(field('Name')).toBeNull();
    expect(nav.refresh).toHaveBeenCalledTimes(1);
    expect(spoken.texts.filter((t) => t !== '')).toEqual([AFTER]);
  });

  it('typed edits, then a 204: the archived page shows the served name (the edits go with the form)', async () => {
    stubFetch([empty(204), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    show();
    await u.type(field('Name')!, 'Z');
    await openAndConfirm(u);
    await waitFor(() => expect(screen.getByText(ARCHIVED_BANNER)).toBeInTheDocument());
    expect(field('Name')).toBeNull();
    expect(screen.getByText('Acme Supplies', { selector: 'dd' })).toBeInTheDocument();
  });

  it('plan test 2 — a 204 whose re-read fails: region error, no Delete supplier, a read-only page; Retry → archived → Restore → editable again', async () => {
    const calls = stubFetch([empty(204), json(503, {}), json(200, ARCHIVED), json(200, ACME), json(200, ACME)]);
    const u = userEvent.setup();
    show();
    await openAndConfirm(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    expect(await screen.findByText(REREAD_FAILED)).toBeInTheDocument();
    expect(what(calls)).toEqual([`DELETE ${SUPPLIER}`, `GET ${SUPPLIER}`]);
    expect(deleteButton()).toBeNull();
    expect(field('Name')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Save' })).toBeNull();
    expect(screen.getByText('Acme Supplies', { selector: 'dd' })).toBeInTheDocument();
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await u.click(await screen.findByRole('button', { name: 'Restore supplier' }));
    await waitFor(() => expect(screen.queryByText(ARCHIVED_BANNER)).toBeNull());
    expect(field('Name')).toBeInTheDocument();
    expect(deleteButton()).toBeInTheDocument();
  });

  it('plan test 3 — R5 (BK-28): "Not deleted" + the server sentence verbatim, heading focused; Close only; Close re-reads once, and the live answer leaves the typed edit and focus on Delete supplier', async () => {
    const calls = stubFetch([refused('supplier.has_open_purchase_orders', R5), json(200, ACME)]);
    const u = userEvent.setup();
    show();
    await u.type(field('Name')!, 'Z');
    await openAndConfirm(u);
    await waitFor(() => expect(within(dialog()).getByText('Not deleted')).toHaveFocus());
    expect(dialog()).toHaveTextContent(R5);
    expect(within(dialog()).queryByRole('button', { name: 'Delete supplier' })).toBeNull();
    expect(within(dialog()).queryByRole('button', { name: 'Cancel' })).toBeNull();
    await u.click(within(dialog()).getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(what(calls)).toEqual([`DELETE ${SUPPLIER}`, `GET ${SUPPLIER}`]));
    await tick();
    expect(field('Name')).toHaveValue('Acme SuppliesZ');
    expect(deleteButton()).toHaveFocus();
    expect(nav.refresh).not.toHaveBeenCalled();
  });

  it('S6 (already archived) → Close, the re-read held until focus is back on Delete supplier: the archived answer moves focus to the h1, never <body>', async () => {
    const reread = held();
    stubFetch([refused('supplier.is_archived', S6), reread.reply]);
    const u = userEvent.setup();
    show();
    await openAndConfirm(u);
    expect(await within(dialog()).findByText(S6)).toBeInTheDocument();
    await u.click(within(dialog()).getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(deleteButton()).toHaveFocus());
    reread.release(json(200, ARCHIVED));
    await waitFor(() => expect(screen.getByText(ARCHIVED_BANNER)).toBeInTheDocument());
    await tick();
    expect(h1()).toHaveFocus();
    expect(document.activeElement).not.toBe(document.body);
    expect(field('Name')).toBeNull();
    expect(nav.refresh).toHaveBeenCalledTimes(1);
  });

  it('plan test 4 — P1 with the server sentence: Delete supplier stays; a second press sends a second DELETE', async () => {
    const calls = stubFetch([refused('transaction.contention', CONTENTION), empty(204), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    show();
    await openAndConfirm(u);
    await waitFor(() => expect(within(dialog()).getByText('Not deleted')).toHaveFocus());
    expect(dialog()).toHaveTextContent(CONTENTION);
    expect(within(dialog()).queryByRole('button', { name: 'Close' })).toBeNull();
    await u.click(confirm());
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    expect(calls.map((c) => c.method)).toEqual(['DELETE', 'DELETE', 'GET']);
  });

  it('P1 → Cancel → the live re-read: the typed edit is still in the form (nothing committed)', async () => {
    const calls = stubFetch([refused('transaction.contention', CONTENTION), json(200, ACME)]);
    const u = userEvent.setup();
    show();
    await u.type(field('Name')!, 'Z');
    await openAndConfirm(u);
    await waitFor(() => expect(dialog()).toHaveTextContent(CONTENTION));
    await u.click(within(dialog()).getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET']));
    await tick();
    expect(field('Name')).toHaveValue('Acme SuppliesZ');
    expect(screen.getByRole('button', { name: 'Save' })).not.toHaveAttribute('aria-disabled');
  });

  it('a 403 names "Delete and restore suppliers"; Delete supplier stays', async () => {
    stubFetch([problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    show();
    await openAndConfirm(u);
    await waitFor(() => expect(within(dialog()).getByText('Not deleted')).toHaveFocus());
    expect(dialog()).toHaveTextContent('This needs the Delete and restore suppliers permission, which you no longer have. Your workspace administrator can grant it.');
    expect(within(dialog()).getByRole('button', { name: 'Delete supplier' })).toBeInTheDocument();
  });

  it('a 404: "We couldn’t find that." under "Not deleted", Close only', async () => {
    stubFetch([problem(404, 'not-found')]);
    const u = userEvent.setup();
    show();
    await openAndConfirm(u);
    await waitFor(() => expect(dialog()).toHaveTextContent("We couldn't find that. It may have been deleted, or you may not have access."));
    expect(within(dialog()).queryByRole('button', { name: 'Delete supplier' })).toBeNull();
    expect(within(dialog()).getByRole('button', { name: 'Close' })).toBeInTheDocument();
  });

  it('a lost connection: "We couldn’t reach the server." + the interrupted sentence; Delete supplier stays; Escape → the re-read finds it archived → the h1', async () => {
    stubFetch([
      () => {
        throw new TypeError('Failed to fetch');
      },
      json(200, ARCHIVED),
    ]);
    const u = userEvent.setup();
    show();
    await openAndConfirm(u);
    await waitFor(() => expect(dialog()).toHaveTextContent("We couldn't reach the server."));
    expect(dialog()).toHaveTextContent(INTERRUPTED);
    expect(within(dialog()).getByRole('button', { name: 'Delete supplier' })).toBeInTheDocument();
    await u.keyboard('{Escape}');
    await waitFor(() => expect(screen.getByText(ARCHIVED_BANNER)).toBeInTheDocument());
    expect(h1()).toHaveFocus();
  });

  it('a 500: "Something went wrong on our side." + the interrupted sentence; Delete supplier stays', async () => {
    stubFetch([problem(500, 'x')]);
    const u = userEvent.setup();
    show();
    await openAndConfirm(u);
    await waitFor(() => expect(dialog()).toHaveTextContent('Something went wrong on our side.'));
    expect(dialog()).toHaveTextContent(INTERRUPTED);
    expect(within(dialog()).getByRole('button', { name: 'Delete supplier' })).toBeInTheDocument();
  });

  it('plan test 6 — a 204 whose re-read reads the supplier live (restored in between): the page is live, offers Delete, and says it has since been restored', async () => {
    stubFetch([empty(204), json(200, ACME)]);
    const u = userEvent.setup();
    show();
    const spoken = textsOf(region());
    await openAndConfirm(u);
    await waitFor(() => expect(region().textContent).toBe(AFTER_RESTORED));
    spoken.stop();
    expect(spoken.texts.filter((t) => t !== '')).toEqual([AFTER_RESTORED]);
    await tick();
    expect(screen.queryByText(ARCHIVED_BANNER)).toBeNull();
    expect(deleteButton()).toBeInTheDocument();
    expect(field('Name')).toBeInTheDocument();
  });

  it('a Save renaming the supplier lands while the delete is in flight: "After" names the code last committed', async () => {
    const patch = held();
    const del = held();
    stubFetch([patch.reply, del.reply, json(200, { ...ACME, code: 'ACME2' }), json(200, { ...ARCHIVED, code: 'ACME2' })]);
    const u = userEvent.setup();
    show();
    await u.clear(field('Code')!);
    await u.type(field('Code')!, 'ACME2');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await openAndConfirm(u);
    patch.release(json(200, { ...ACME, code: 'ACME2' }));
    await tick();
    del.release(empty(204));
    await tick();
    await tick();
    expect(region().textContent).toBe("ACME2 was deleted. It's now in the archived suppliers.");
  });
});

// suppliers-plan.md Revision 1 — FINAL §R1-4 S-7 (final RED M-3, moved to S-7 by P-2 since S-4 shipped first): after a 204
// the catalogue section's actions are gone in the SAME mount — even when the delete's re-read fails and only the 204 says
// the supplier is archived (`deletedHere`).
describe('/suppliers/[id] — Delete supplier: the catalogue section after a 204 (M-3)', () => {
  const sectionOf = () => screen.getByRole('region', { name: 'Catalogue' });
  const actions = () => within(sectionOf()).queryAllByRole('button').map((b) => b.textContent);

  it.each([
    ['archived', () => json(200, ARCHIVED)],
    ['fails (503)', () => json(503, {})],
  ] as const)('the re-read %s: no Add, Edit or Remove; the archived note; the same section element', async (_n, reread) => {
    stubFetch([empty(204), reread()]);
    const u = userEvent.setup();
    showWithCatalogue();
    const before = sectionOf();
    expect(actions()).toEqual(['Add to catalogue', 'Edit', 'Remove']);
    await openAndConfirm(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    await tick();
    expect(sectionOf()).toBe(before);
    expect(actions()).toEqual([]);
    expect(within(sectionOf()).getByText(ARCHIVED_NOTE)).toBeInTheDocument();
    expect(within(sectionOf()).getAllByRole('row')).toHaveLength(2);
  });
});

describe('/suppliers/[id] — the person\'s own Delete outranks their own Save (plan test 5; the items UX ruling M-3 / T-17 / L-1)', () => {
  // The I-A / I-Afail twins: a Save whose re-read is in flight when the supplier is deleted asked for that reading before
  // the delete, so it must not turn the page live again, and its "saved" sentence is never spoken.
  const saveThenDelete = async (deleteReread: Response) => {
    const saveReread = held();
    const calls = stubFetch([json(200, { ...ACME, name: 'Acme Suppliesx' }), saveReread.reply, empty(204), deleteReread]);
    const u = userEvent.setup();
    show();
    const spoken = textsOf(region());
    await rename(u);
    await waitFor(() => expect(calls).toHaveLength(2));
    await openAndConfirm(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    await waitFor(() => expect(nav.refresh).toHaveBeenCalledTimes(1));
    saveReread.release(json(200, { ...ACME, name: 'Acme Suppliesx' }));
    await waitFor(() => expect(nav.refresh).toHaveBeenCalledTimes(2));
    await tick(20);
    spoken.stop();
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET', 'DELETE', 'GET']);
    expect(region().textContent).toBe(AFTER);
    expect(spoken.texts.filter((t) => t !== '')).toEqual([AFTER]);
  };

  it('an older Save re-read landing after a 204 does not turn the page live; "saved" is never spoken (I-A)', async () => {
    await saveThenDelete(json(200, { ...ARCHIVED, name: 'Acme Suppliesx' }));
    expect(screen.getByText(ARCHIVED_BANNER)).toBeInTheDocument();
    expect(deleteButton()).toBeNull();
    expect(field('Name')).toBeNull();
  });

  it("…and the same when the delete's own re-read fails: no Delete supplier, no form, the delete's region error (I-Afail, the 204 floor)", async () => {
    await saveThenDelete(json(503, {}));
    expect(deleteButton()).toBeNull();
    expect(field('Name')).toBeNull();
    expect(screen.getByText(REREAD_FAILED)).toBeInTheDocument();
  });

  // T1 (po-6-red.md, spar on :384): the 200 path. Run for a Name change and for a Currency change (Currency is a Save field).
  // The delete's re-read is GBP only so the dropped Save body is observable; a real server's re-read after a 200 Save would carry the saved values (D-1's ground).
  it.each([
    ['Name', async (u: U) => u.type(field('Name')!, 'x'), { ...ACME, name: 'Acme Suppliesx' }],
    ['Currency', async (u: U) => {
      await u.clear(field('Currency')!);
      await u.type(field('Currency')!, 'eur');
    }, { ...ACME, currency_code: 'EUR' }],
  ] as const)('T1 — a %s Save answering 200 after the 204, whose re-read fails: not live again, no "saved", no region error; the h1 keeps focus', async (_n, edit, body) => {
    const patch = held();
    const calls = stubFetch([patch.reply, empty(204), json(200, ARCHIVED), json(503, {})]);
    const u = userEvent.setup();
    show();
    const spoken = textsOf(region());
    await edit(u);
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await openAndConfirm(u);
    await waitFor(() => expect(screen.getByText(ARCHIVED_BANNER)).toBeInTheDocument());
    patch.release(json(200, body));
    await tick();
    await tick();
    spoken.stop();
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'DELETE', 'GET', 'GET']);
    expect(screen.getByText(ARCHIVED_BANNER)).toBeInTheDocument();
    expect(deleteButton()).toBeNull();
    expect(field('Name')).toBeNull();
    expect(screen.getByText('GBP', { selector: 'dd' })).toBeInTheDocument();
    expect(region().textContent).toBe(AFTER);
    expect(spoken.texts.filter((t) => t !== '')).toEqual([AFTER]);
    expect(screen.queryByText(REREAD_FAILED)).toBeNull();
    expect(h1()).toHaveFocus();
  });

  it('T2 — a Save refused S5 after the 204, whose re-read fails: no "Not saved", no region error; the h1 keeps focus, "After" only', async () => {
    const patch = held();
    const calls = stubFetch([patch.reply, empty(204), json(200, ARCHIVED), json(503, {})]);
    const u = userEvent.setup();
    show();
    const spoken = textsOf(region());
    await rename(u);
    await openAndConfirm(u);
    await waitFor(() => expect(screen.getByText(ARCHIVED_BANNER)).toBeInTheDocument());
    patch.release(refused('supplier.is_archived', S5));
    await tick();
    await tick();
    spoken.stop();
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'DELETE', 'GET', 'GET']);
    expect(screen.queryByText(REREAD_FAILED)).toBeNull();
    // Belt and braces: with the re-read failing, no refusal can reach the page (the refusal half is T-17a/b/c).
    expect(screen.queryByText('Not saved')).toBeNull();
    expect(screen.queryByText(S5)).toBeNull();
    expect(h1()).toHaveFocus();
    expect(spoken.texts.filter((t) => t !== '')).toEqual([AFTER]);
  });

  it("C1 (control) — the delete's own re-read fails too: exactly one region error (the delete's), whose heading keeps focus after the Save's late failure", async () => {
    const patch = held();
    const calls = stubFetch([patch.reply, empty(204), json(503, {}), json(503, {})]);
    const u = userEvent.setup();
    show();
    await rename(u);
    await openAndConfirm(u);
    await waitFor(() => expect(screen.getByText(REREAD_FAILED)).toBeInTheDocument());
    const errorHeading = () => screen.getByText(REREAD_FAILED).closest('section')!.querySelector('h2');
    await waitFor(() => expect(errorHeading()).toHaveFocus());
    const shown = errorHeading();
    patch.release(json(200, { ...ACME, name: 'Acme Suppliesx' }));
    await tick();
    await tick();
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'DELETE', 'GET', 'GET']);
    expect(screen.getAllByText(REREAD_FAILED)).toHaveLength(1);
    expect(errorHeading()).toHaveFocus();
    expect(errorHeading()).toBe(shown);
    expect(deleteButton()).toBeNull();
    expect(field('Name')).toBeNull();
  });

  it('C2 (control) — own delete, then Restore, then a Save sent after it whose re-read fails: the region error IS shown', async () => {
    const calls = stubFetch([empty(204), json(200, ARCHIVED), json(200, ACME), json(200, ACME), json(200, { ...ACME, name: 'Acme Suppliesx' }), json(503, {})]);
    const u = userEvent.setup();
    show();
    await openAndConfirm(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    await u.click(await screen.findByRole('button', { name: 'Restore supplier' }));
    await waitFor(() => expect(field('Name')).toBeInTheDocument());
    await rename(u);
    expect(await screen.findByText(REREAD_FAILED)).toBeInTheDocument();
    await waitFor(() => expect(region()).toHaveTextContent(SAVED));
    expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET', 'POST', 'GET', 'PATCH', 'GET']);
  });

  it('C3 (control) — a Save answering 200 after the 204 whose re-read is answered 403: the denied state renders (the swap is kept)', async () => {
    const patch = held();
    const calls = stubFetch([patch.reply, empty(204), json(200, ARCHIVED), problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    show();
    await rename(u);
    await openAndConfirm(u);
    await waitFor(() => expect(screen.getByText(ARCHIVED_BANNER)).toBeInTheDocument());
    patch.release(json(200, { ...ACME, name: 'Acme Suppliesx' }));
    expect(await screen.findByRole('heading', { level: 1, name: "You don't have access to suppliers." })).toBeInTheDocument();
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'DELETE', 'GET', 'GET']);
  });

  it("…and the same 200 while the delete's re-read is in flight: no region error flashes, no \"saved\"", async () => {
    const patch = held();
    const reread = held();
    const calls = stubFetch([patch.reply, empty(204), reread.reply, json(503, {})]);
    const u = userEvent.setup();
    show();
    const spoken = textsOf(region());
    await rename(u);
    await openAndConfirm(u);
    await waitFor(() => expect(calls).toHaveLength(3));
    patch.release(json(200, { ...ACME, name: 'Acme Suppliesx' }));
    await tick();
    expect(screen.queryByText(REREAD_FAILED)).toBeNull();
    reread.release(json(200, ARCHIVED));
    await tick();
    spoken.stop();
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'DELETE', 'GET', 'GET']);
    expect(screen.getByText(ARCHIVED_BANNER)).toBeInTheDocument();
    expect(field('Name')).toBeNull();
    expect(screen.queryByText(REREAD_FAILED)).toBeNull();
    expect(spoken.texts.filter((t) => t !== '')).toEqual([AFTER]);
  });

  const expectOwnDeleteOnly = (spoken: string[]) => {
    expect(screen.queryByText('Not saved')).toBeNull();
    expect(screen.queryByText(S5)).toBeNull();
    expect(screen.getByText(ARCHIVED_BANNER)).toBeInTheDocument();
    expect(h1()).toHaveFocus();
    expect(spoken.filter((t) => t !== '')).toEqual([AFTER]);
    expect(region().textContent).toBe(AFTER);
    expect(screen.queryAllByRole('textbox')).toEqual([]);
  };

  it('T-17a — the refusal settles first (its re-read reads archived, under the dialog), then the 204: the banner that showed is cleared', async () => {
    const patch = held();
    const del = held();
    const calls = stubFetch([patch.reply, del.reply, json(200, ARCHIVED), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    show();
    const spoken = textsOf(region());
    await rename(u);
    await openAndConfirm(u);
    await waitFor(() => expect(calls).toHaveLength(2));
    patch.release(refused('supplier.is_archived', S5));
    await waitFor(() => expect(screen.getByText(S5)).toBeInTheDocument());
    del.release(empty(204));
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    await tick();
    spoken.stop();
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'DELETE', 'GET', 'GET']);
    expectOwnDeleteOnly(spoken.texts);
  });

  it("T-17b — the 204 is handled first, and the refusal settles while the delete's re-read is in flight: no banner", async () => {
    const patch = held();
    const del = held();
    const delReread = held();
    const calls = stubFetch([patch.reply, del.reply, delReread.reply, json(200, ARCHIVED)]);
    const u = userEvent.setup();
    show();
    const spoken = textsOf(region());
    await rename(u);
    await openAndConfirm(u);
    await waitFor(() => expect(calls).toHaveLength(2));
    del.release(empty(204));
    await waitFor(() => expect(calls).toHaveLength(3));
    patch.release(refused('supplier.is_archived', S5));
    await waitFor(() => expect(calls).toHaveLength(4));
    await tick();
    delReread.release(json(200, ARCHIVED));
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    await tick();
    spoken.stop();
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'DELETE', 'GET', 'GET']);
    expectOwnDeleteOnly(spoken.texts);
  });

  it('T-17c — the refusal settles after the dialog has closed and the h1 has focus: no banner, and the h1 keeps focus', async () => {
    const patch = held();
    const del = held();
    const calls = stubFetch([patch.reply, del.reply, json(200, ARCHIVED), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    show();
    const spoken = textsOf(region());
    await rename(u);
    await openAndConfirm(u);
    await waitFor(() => expect(calls).toHaveLength(2));
    del.release(empty(204));
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(h1()).toHaveFocus();
    patch.release(refused('supplier.is_archived', S5));
    await waitFor(() => expect(calls).toHaveLength(4));
    await tick();
    await tick();
    spoken.stop();
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'DELETE', 'GET', 'GET']);
    expectOwnDeleteOnly(spoken.texts);
  });

  it('T-17d (control) — after an own delete and a restore, a Save refused and re-read archived (deleted elsewhere) still shows its banner, focused', async () => {
    const calls = stubFetch([empty(204), json(200, ARCHIVED), json(200, ACME), json(200, ACME), refused('supplier.is_archived', S5), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    show();
    await openAndConfirm(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    await u.click(await screen.findByRole('button', { name: 'Restore supplier' }));
    await waitFor(() => expect(field('Name')).toBeInTheDocument());
    await rename(u);
    expect(await screen.findByText(S5)).toBeInTheDocument();
    await waitFor(() => expect(screen.getByText('Not saved')).toHaveFocus());
    expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET', 'POST', 'GET', 'PATCH', 'GET']);
    expect(screen.getByText(ARCHIVED_BANNER)).toBeInTheDocument();
  });
});

// Lesson 5 (the brief; po-8-red.md H-1, warehouse-save-after-delete-red.md M-1): the catalogue's actions that can still be
// answering when Delete supplier is pressed. Add to catalogue is a BUTTON that reads before it opens its dialog, so
// Delete supplier can be pressed while that read is out: the later press wins (D-2, the S-5 M-1 / RED-2 L-2 rule a row's
// Edit and Remove already follow). Edit, Add and Remove are modal dialogs; their post-close re-reads speak only through
// `replaceIfCurrent` and move focus only when it was lost (D-3: pinned here, no code change).
describe('/suppliers/[id] — catalogue actions still answering when Delete supplier is pressed', () => {
  const sectionOf = () => screen.getByRole('region', { name: 'Catalogue' });
  const addBusy = () => screen.getAllByRole('button', { name: 'Add to catalogue', hidden: true })[0]!;

  it('D-2 — Add pressed, its read held, then Delete supplier: Add stops loading at once; the late answer opens nothing, also after Cancel', async () => {
    const r = held();
    stubFetch([r.reply]);
    const u = userEvent.setup();
    showWithCatalogue();
    await u.click(within(sectionOf()).getByRole('button', { name: 'Add to catalogue' }));
    expect(addBusy()).toHaveAttribute('aria-busy', 'true');
    await u.click(deleteButton()!);
    expect(addBusy()).not.toHaveAttribute('aria-busy');
    await act(async () => r.release(json(200, page([NEW1]))));
    await tick();
    const dialogs = screen.queryAllByRole('dialog', { hidden: true });
    expect(dialogs).toHaveLength(1);
    expect(within(dialogs[0]!).getByRole('heading', { name: 'Delete ACME — Acme Supplies?' })).toBeInTheDocument();
    await u.click(within(dialog()).getByRole('button', { name: 'Cancel' }));
    await tick();
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(within(sectionOf()).getByRole('button', { name: 'Add to catalogue' })).not.toHaveAttribute('aria-busy');
  });

  // suppliers-s7-red.md L-1: the answer arriving AFTER the Delete dialog was cancelled still opens nothing.
  it('D-2 — Add read held → Delete supplier → Cancel → the read answers: no dialog opens, Add is not loading', async () => {
    const r = held();
    stubFetch([r.reply]);
    const u = userEvent.setup();
    showWithCatalogue();
    await u.click(within(sectionOf()).getByRole('button', { name: 'Add to catalogue' }));
    await u.click(deleteButton()!);
    await u.click(within(dialog()).getByRole('button', { name: 'Cancel' }));
    await tick();
    await act(async () => r.release(json(200, page([NEW1]))));
    await tick();
    expect(screen.queryAllByRole('dialog', { hidden: true })).toEqual([]);
    expect(within(sectionOf()).getByRole('button', { name: 'Add to catalogue' })).not.toHaveAttribute('aria-busy');
  });

  it('D-2 — Add read held → Delete supplier → 204 → the read answers: no Add dialog over the deleted supplier; "After" only; the h1 keeps focus', async () => {
    const r = held();
    const calls = stubFetch([r.reply, empty(204), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    showWithCatalogue();
    const spoken = textsOf(region());
    await u.click(within(sectionOf()).getByRole('button', { name: 'Add to catalogue' }));
    await openAndConfirm(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    await act(async () => r.release(json(200, page([NEW1]))));
    await tick();
    spoken.stop();
    expect(calls.map((c) => c.method)).toEqual(['GET', 'DELETE', 'GET']);
    expect(screen.queryAllByRole('dialog', { hidden: true })).toEqual([]);
    expect(h1()).toHaveFocus();
    expect(spoken.texts.filter((t) => t !== '')).toEqual([AFTER]);
  });

  it('D-2 (control) — Add with no Delete press: the answer opens the Add dialog', async () => {
    const r = held();
    stubFetch([r.reply]);
    const u = userEvent.setup();
    showWithCatalogue();
    await u.click(within(sectionOf()).getByRole('button', { name: 'Add to catalogue' }));
    await act(async () => r.release(json(200, page([NEW1]))));
    await tick();
    expect(screen.getAllByRole('dialog')).toHaveLength(1);
    expect(within(dialog()).getByRole('heading', { name: "Add an item to ACME's catalogue" })).toBeInTheDocument();
  });

  it('D-3 probe — Edit saved, the section re-read held, then Delete supplier 204: the re-read failing later says nothing and moves no focus', async () => {
    const reread = held();
    const calls = stubFetch([json(200, { ...A, supplier_sku: 'A-101' }), reread.reply, empty(204), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    showWithCatalogue();
    const spoken = textsOf(region());
    await u.click(within(sectionOf()).getByRole('button', { name: 'Edit SKU-A-100' }));
    const sku = within(dialog()).getByRole('textbox', { name: 'Supplier SKU' });
    await u.clear(sku);
    await u.type(sku, 'A-101');
    await u.click(within(dialog()).getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(region()).toHaveTextContent('SKU-A-100 was saved.'));
    await openAndConfirm(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    reread.release(json(503, {}));
    await tick();
    await tick();
    spoken.stop();
    expect(what(calls)).toEqual([`PATCH ${LIST}/${A.id}`, `GET ${LIST}`, `DELETE ${SUPPLIER}`, `GET ${SUPPLIER}`]);
    expect(region().textContent).toBe(AFTER);
    expect(spoken.texts.filter((t) => t !== '')).toEqual(['SKU-A-100 was saved.', AFTER]);
    expect(h1()).toHaveFocus();
  });

  it('D-3 probe — Remove 204, the section re-read held, then Delete supplier 204: the re-read failing later says nothing and moves no focus', async () => {
    const reread = held();
    const calls = stubFetch([empty(204), reread.reply, empty(204), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    showWithCatalogue();
    const spoken = textsOf(region());
    await u.click(within(sectionOf()).getByRole('button', { name: 'Remove SKU-A-100' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Remove' }));
    await waitFor(() => expect(region()).toHaveTextContent('SKU-A-100 was removed from the catalogue.'));
    await openAndConfirm(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    reread.release(json(503, {}));
    await tick();
    await tick();
    spoken.stop();
    expect(what(calls)).toEqual([`DELETE ${LIST}/${A.id}`, `GET ${LIST}`, `DELETE ${SUPPLIER}`, `GET ${SUPPLIER}`]);
    expect(region().textContent).toBe(AFTER);
    expect(spoken.texts.filter((t) => t !== '')).toEqual(['SKU-A-100 was removed from the catalogue.', AFTER]);
    expect(h1()).toHaveFocus();
  });

  it("D-3 probe — the section's Retry held, then Delete supplier 204: the Retry's late answer speaks no count and moves no focus", async () => {
    const retryRead = held();
    const calls = stubFetch([retryRead.reply, empty(204), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    renderWired(<SupplierPage initial={ACME} canEdit={false} canRestore canDelete catalogue={{ initial: { kind: 'server-error' }, canOpenItem: false, canEdit: true, canAdd: false, canRemove: true }} />);
    const spoken = textsOf(region());
    await u.click(within(sectionOf()).getByRole('button', { name: 'Retry' }));
    await openAndConfirm(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    await act(async () => retryRead.release(answer([A])));
    await tick();
    spoken.stop();
    expect(what(calls)).toEqual([`GET ${LIST}`, `DELETE ${SUPPLIER}`, `GET ${SUPPLIER}`]);
    expect(spoken.texts.filter((t) => t !== '')).toEqual([AFTER]);
    expect(h1()).toHaveFocus();
    expect(within(sectionOf()).queryAllByRole('button')).toEqual([]);
  });
});

// FE-D1 order on the supplier delete (plan test 1), plain and under StrictMode: when the h1 takes focus the dialog is gone
// and the "After" sentence is not yet written.
for (const strict of [false, true]) {
  const wrap = (ui: ReactElement) => (strict ? <StrictMode>{ui}</StrictMode> : ui);
  describe(`Delete supplier — FE-D1 order${strict ? ' (StrictMode)' : ''}`, () => {
    // "announce" is a deferred setState, so the h1 always takes focus before the text reaches the DOM whichever is called first; this test pins the flushSync close (R18b), not the call order.
    it('plan test 1 — 204: the dialog closes, the h1 takes focus, THEN "After" is written; one refresh', async () => {
      stubFetch([empty(204), json(200, ARCHIVED)]);
      const u = userEvent.setup();
      renderWired(wrap(<SupplierPage initial={ACME} canEdit={false} canRestore canDelete />));
      await u.click(deleteButton()!);
      const atFocus: { text: boolean; dialog: boolean }[] = [];
      const onFocusIn = (e: FocusEvent) => {
        if ((e.target as HTMLElement).tagName === 'H1') atFocus.push({ text: (document.body.textContent ?? '').includes(AFTER), dialog: document.querySelector('[role="dialog"]') !== null });
      };
      document.addEventListener('focusin', onFocusIn);
      await u.click(confirm());
      await waitFor(() => expect(region()).toHaveTextContent(AFTER));
      document.removeEventListener('focusin', onFocusIn);
      expect(atFocus[0]).toEqual({ text: false, dialog: false });
      expect(h1()).toHaveFocus();
      expect(nav.refresh).toHaveBeenCalledTimes(1);
    });
  });
}

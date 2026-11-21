import { Profiler, StrictMode, useState } from 'react';
import { act, fireEvent, isInaccessible, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ refresh: nav.refresh }) }));
import { leaveArrivalMessage, takeArrivalMessage } from '@/app/(app)/arrivalMessage';
import { BffProvider } from '@/app/(app)/BffProvider';
import { ItemPage } from '@/app/(app)/items/[id]/ItemPage';
import { RouteFocus } from '@/components/shell/RouteFocus';
import { TooltipProvider } from '@/components/ui/Tooltip';
import type { Item } from '@/lib/api/items';
import { formatDate } from '@/lib/format/dates';
import { wireEnum } from '@/lib/bff/surface';
import { SID, TZ, atText, empty, expectSuffix, held, json, later, problem, refused, renderWired, shownText, stubFetch, textsOf } from './harness';

// Items I-1 (items-plan.md §4 I-1 as amended by R1-1…R1-9 and Revision 1 — FINAL; items-ux-copy.md B-3, B-5 as amended
// by R1-1, R1-2, R1-6, R1-9, R1-17, F-4; Revision 8 R8-2): the item page is the editor. SKU, Name, the Unit (since I-4,
// items-i4-design.md Revision 1 — FINAL) and the three numbers are editable for an UpdateItem holder; the Preferred
// supplier is text in every state and never sent; the Unit is its saved code as text wherever there is no form. Each
// number starts as its QR-2 rendering and is sent exactly as typed; in edit the unit the form will save follows it. An
// archived item is read-only under its ST-9 banner with Restore item for a RestoreItem holder.

afterEach(() => {
  vi.unstubAllGlobals();
  nav.refresh.mockClear();
});

const IID = '7b2c3d4e-5f6a-4b7c-8d9e-0f1a2b3c4d5e';
const BOLT: Item = {
  id: IID,
  sku: 'A-1',
  name: 'Bolt',
  unit_of_measure_code: 'KG',
  reorder_point: '10.000000',
  reorder_quantity: '1234567890123.123456',
  safety_stock: '-0.500000',
  supplier: { id: 'c1b2c3d4-0000-4000-8000-000000000009', name: 'Acme Supplies' },
  archived_at: null,
  holds_stock: false,
};
const AT = '2026-03-04T10:00:00Z';
const ARCHIVED: Item = { ...BOLT, archived_at: AT };
const show = (i: Item = BOLT, canEdit = true, canRestore = false) => renderWired(<ItemPage initial={i} canEdit={canEdit} canRestore={canRestore} />);
const region = () => document.querySelector<HTMLElement>('section > [role="status"]')!;
const field = (name: string) => screen.getByRole('textbox', { name });
const save = () => screen.getByRole('button', { name: 'Save' });
const violation = (pointer: string, message: string, code = 'not_a_decimal') => problem(422, 'validation-failed', { errors: [{ pointer, code, message }] });
const I2 = "This item is archived, so it can't be changed until it's restored.";
const P1 = 'Some of the same records were being changed at the same moment. Nothing was saved; try again.';
/** UX-I8′ (`ItemWriter::NOT_A_DECIMAL`, items batch C4): the server's sentence for the three item numbers. */
const I8 = 'Enter 0 or a positive number, for example 10 or 2.5. Use only digits and at most one decimal point, with 1 to 13 digits before the point and 1 to 6 after it.';
/** The value each display site must show for each served quantity (M-5): QR-2, never a number. */
const SERVED_AS: [string, string][] = [
  ['1234567890123.123456', '1234567890123.123456'],
  ['-0.500000', '-0.5'],
  ['10.000000', '10'],
  ['0.000000', '0'],
];
const withNumbers = (base: Item, [a, b, c]: string[]): Item => ({ ...base, reorder_point: a!, reorder_quantity: b!, safety_stock: c! });
const ddAfter = (label: string) => screen.getByText(label, { selector: 'dt' }).nextElementSibling as HTMLElement;
const unit = () => screen.getByRole('combobox', { name: 'Unit of measure' });
/** items-i4-ux.md U4-6 as ruled on the final RED's M-2 ("Final RED rulings"), verbatim. */
const UNIT_HINT = "Once stock, orders or supplier catalogues record quantities of this item, its unit can't be changed. The three numbers below are in this unit, so check them when you change it.";
/** A distinctive fixture for `ItemWriter::UNIT_IN_USE`: the page shows the server's sentence as sent, whatever it is. */
const IN_USE = 'Fixture: this unit is in use, so it was refused.';

// Stock ST-1 (stock-plan.md §2.4, §6.7; stock-st1-red.md L-11, L-12): one link to this item's movements, only for a
// ListStockMovements holder, on a live AND an archived item (an archived item's movements are reachable only from here
// without a picker). A link, not a stock panel: nothing else on the page moves.
describe('/items/[id] — the link to this item’s movements (stock ST-1)', () => {
  const MOVEMENTS = 'Stock movements of this item';
  it('a ListStockMovements holder gets the link, href exactly /stock-movements?inventory_item_id=<id>; without it there is none', () => {
    const { unmount } = renderWired(<ItemPage initial={BOLT} canEdit={false} canRestore={false} canListMovements />);
    expect(screen.getByRole('link', { name: MOVEMENTS })).toHaveAttribute('href', `/stock-movements?inventory_item_id=${IID}`);
    expect(screen.getAllByRole('link')).toHaveLength(1);
    unmount();
    renderWired(<ItemPage initial={BOLT} canEdit={false} canRestore={false} />);
    expect(screen.queryByRole('link', { name: MOVEMENTS })).toBeNull();
  });

  it('the link renders on an archived item too, and in edit; it is the page’s only link (no stock-on-hand link)', () => {
    const { unmount } = renderWired(<ItemPage initial={ARCHIVED} canEdit canRestore canListMovements />);
    expect(screen.getByRole('link', { name: MOVEMENTS })).toHaveAttribute('href', `/stock-movements?inventory_item_id=${IID}`);
    unmount();
    renderWired(<ItemPage initial={BOLT} canEdit canRestore={false} canListMovements />);
    expect(screen.getByRole('link', { name: MOVEMENTS })).toBeInTheDocument();
    expect([...document.querySelectorAll('a')].map((a) => a.getAttribute('href'))).toEqual([`/stock-movements?inventory_item_id=${IID}`]);
  });
});

// Stock ST-2 (stock-st2-plan.md §2.5, §7.6; UX B-7): one link to this item's stock on hand, only for a viewer the server's
// `stockRowsVisible(codes)` admits (`canSeeStock`), on a live AND an archived item. A link, not a stock panel.
describe('/items/[id] — the link to this item’s stock on hand (stock ST-2)', () => {
  const STOCK = 'Stock on hand of this item';
  it('canSeeStock gets the link, href exactly /stock?inventory_item_id=<id>, live and archived; without it there is none', () => {
    const { unmount } = renderWired(<ItemPage initial={BOLT} canEdit={false} canRestore={false} canSeeStock />);
    expect(screen.getByRole('link', { name: STOCK })).toHaveAttribute('href', `/stock?inventory_item_id=${IID}`);
    expect(screen.getAllByRole('link')).toHaveLength(1);
    unmount();
    const b = renderWired(<ItemPage initial={ARCHIVED} canEdit canRestore canSeeStock canListMovements />);
    expect([...document.querySelectorAll('a')].map((a) => a.getAttribute('href'))).toEqual([`/stock?inventory_item_id=${IID}`, `/stock-movements?inventory_item_id=${IID}`]);
    b.unmount();
    renderWired(<ItemPage initial={BOLT} canEdit={false} canRestore={false} canListMovements />);
    expect(screen.queryByRole('link', { name: STOCK })).toBeNull();
  });
});

describe('/items/[id] — the editor (B-3 as amended)', () => {
  it('h1 "{sku} — {name}"; editable SKU, Name, Unit and the three numbers, in that order (I-4 T-0b); Preferred supplier is text', () => {
    show();
    expect(screen.getByRole('heading', { level: 1, name: 'A-1 — Bolt' })).toBeInTheDocument();
    expect([...document.querySelectorAll('form input, form select')].map((i) => i.getAttribute('name'))).toEqual(['sku', 'name', 'unit_of_measure_code', 'reorder_point', 'reorder_quantity', 'safety_stock']);
    expect(screen.queryByText('Unit of measure', { selector: 'dt' })).toBeNull();
    expect(ddAfter('Preferred supplier')).toHaveTextContent(/^Acme Supplies$/);
    expect(field('SKU')).toHaveAccessibleDescription(expect.stringContaining('A new SKU also changes it on every order'));
    expect(field('Name')).toHaveAccessibleDescription('Renaming also changes the name on every order, receipt, shipment and invoice that already names this item.');
    expect(field('Reorder point')).toHaveAttribute('inputmode', 'decimal');
    expect(field('Reorder point')).toHaveAttribute('type', 'text');
    expect(screen.getByText("Leave both reorder numbers at 0 if this item isn't reordered. Use a point for decimals, for example 2.5, and no commas.")).toBeInTheDocument();
    // No stock or links to it (G-47); a link to this item's movements for a ListStockMovements holder (stock ST-1; not
    // passed here, so no link), no History. Delete item only for a DeleteItem holder (I-3 T-5:
    // the offer rule itself is item-delete.test.tsx's; `show()` here passes no canDelete).
    expect(screen.queryAllByRole('link')).toEqual([]);
    expect(screen.queryByRole('button', { name: /delete/i })).toBeNull();
  });

  it('I-4 T-0a (i): an UpdateItem holder on a live item gets a Unit select over the served codes, in served order, with the UX labels, at the saved code', () => {
    show();
    const options = within(unit()).getAllByRole('option') as HTMLOptionElement[];
    expect(options.map((o) => o.value)).toEqual(wireEnum('Item.UpdateItemInput.jsonMergePatch', 'unit_of_measure_code'));
    expect(options.map((o) => o.textContent)).toEqual(['Each (EA)', 'Box (BOX)', 'Kilogram (KG)', 'Litre (L)', 'Metre (M)']);
    expect(unit()).toHaveValue('KG');
    expect(unit()).toHaveAttribute('name', 'unit_of_measure_code');
  });

  it('I-4 T-0a (ii) / T-11: no Unit control read-only, archived, or after a 204 whose re-read failed; the saved code as text, never its label', async () => {
    for (const [i, canEdit] of [[BOLT, false], [ARCHIVED, true]] as const) {
      const { unmount } = show(i, canEdit, true);
      expect(screen.queryByRole('combobox')).toBeNull();
      expect(document.querySelector('[name="unit_of_measure_code"]')).toBeNull();
      expect(ddAfter('Unit of measure')).toHaveTextContent(/^KG$/);
      unmount();
    }
    // deletedHere: the 204 is a fact even when its re-read fails, so the form is gone (4.6 RED M-1).
    stubFetch([empty(204), json(503, {})]);
    const u = userEvent.setup();
    renderWired(<ItemPage initial={BOLT} canEdit canRestore canDelete />);
    await u.click(screen.getByRole('button', { name: 'Delete item' }));
    await u.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Delete item' }));
    expect(await screen.findByText("We couldn't load this item again, so what's shown may be out of date.")).toBeInTheDocument();
    expect(screen.queryByRole('combobox')).toBeNull();
    expect(document.querySelector('[name="unit_of_measure_code"]')).toBeNull();
    expect(ddAfter('Unit of measure')).toHaveTextContent(/^KG$/);
  });

  it('no supplier reads "None", as text, never a control (R1-6, S-B′): the only select is the Unit (I-4)', () => {
    show({ ...BOLT, supplier: null });
    expect(ddAfter('Preferred supplier')).toHaveTextContent(/^None$/);
    expect(screen.queryByRole('combobox', { name: 'Preferred supplier' })).toBeNull();
    expect(screen.getAllByRole('combobox')).toEqual([unit()]);
  });

  it('M-5 per display site — edit inputs start at the QR-2 rendering, with the served unit code after each number', () => {
    for (const order of [0, 1, 2, 3]) {
      const four = [0, 1, 2].map((k) => SERVED_AS[(order + k) % 4]!);
      const { unmount } = show(withNumbers(BOLT, four.map(([s]) => s)));
      expect(['Reorder point', 'Reorder quantity', 'Safety stock'].map((n) => (field(n) as HTMLInputElement).value)).toEqual(four.map(([, r]) => r));
      // S-3: the spoken suffix (moved deliberately from the code).
      expectSuffix('KG');
      unmount();
    }
  });

  it('M-5 per display site — read-only page (no UpdateItem): each number is QR-2 then the unit code', () => {
    for (const order of [0, 1, 2, 3]) {
      const four = [0, 1, 2].map((k) => SERVED_AS[(order + k) % 4]!);
      const { unmount } = show(withNumbers(BOLT, four.map(([s]) => s)), false);
      expect(['Reorder point', 'Reorder quantity', 'Safety stock'].map((l) => shownText(ddAfter(l)))).toEqual(four.map(([, r]) => `${r} KG`));
      unmount();
    }
  });

  it('M-5 per display site — archived page: each number is QR-2 then the unit code, whatever the viewer holds', () => {
    for (const order of [0, 1, 2, 3]) {
      const four = [0, 1, 2].map((k) => SERVED_AS[(order + k) % 4]!);
      const { unmount } = show(withNumbers(ARCHIVED, four.map(([s]) => s)), true, true);
      expect(['Reorder point', 'Reorder quantity', 'Safety stock'].map((l) => shownText(ddAfter(l)))).toEqual(four.map(([, r]) => `${r} KG`));
      unmount();
    }
  });

  it("the suffix is the served unit code, not a constant (R1-3 / F-4): EA on an EA item, KG on a KG item", () => {
    const { unmount } = show({ ...BOLT, unit_of_measure_code: 'EA' }, false);
    expect(shownText(ddAfter('Reorder point'))).toBe('10 EA');
    unmount();
    show(BOLT, false);
    expect(shownText(ddAfter('Reorder point'))).toBe('10 KG');
  });

  it('Save is aria-disabled with "Nothing to save." until a field differs; an untouched rendered number is not a change', async () => {
    const u = userEvent.setup();
    show();
    expect(save()).toHaveAttribute('aria-disabled', 'true');
    expect(save()).toHaveAccessibleDescription('Nothing to save.');
    await u.type(field('Name'), 'x');
    expect(save()).not.toHaveAttribute('aria-disabled');
    await u.type(field('Name'), '{Backspace}');
    expect(save()).toHaveAttribute('aria-disabled', 'true');
  });

  it('Save sends only the changed fields — never supplier_id, and the unit only when it changed (not here) — re-reads, says "{sku} was saved." with the SKU as saved, focuses the h1', async () => {
    const after = { ...BOLT, sku: 'B-2', name: 'Bolt M6' };
    const calls = stubFetch([json(200, after), json(200, { ...after, name: 'Bolt M6 (re-read)' })]);
    const u = userEvent.setup();
    show();
    await u.clear(field('SKU'));
    await u.type(field('SKU'), 'B-2');
    await u.type(field('Name'), ' M6');
    await u.click(save());
    await waitFor(() => expect(region()).toHaveTextContent('B-2 was saved.'));
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET']);
    expect(calls[0]!.url).toContain(`/bff/api/items/${IID}`);
    expect(JSON.parse(calls[0]!.body!)).toEqual({ sku: 'B-2', name: 'Bolt M6' });
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('B-2 — Bolt M6 (re-read)');
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveFocus());
    expect(save()).toHaveAttribute('aria-disabled', 'true');
    expect(nav.refresh).toHaveBeenCalledTimes(1);
  });

  it('a typed number is sent exactly as typed: no trim, no client format rule ("1.50", " 2", "-3", "1,5")', async () => {
    // '  ' (spaces only) is sent too: R1-9 catches only the empty field (RED L-6 c).
    for (const typed of ['1.50', ' 2', '-3', '1,5', '  ']) {
      const calls = stubFetch([violation('/reorder_point', I8)]);
      const u = userEvent.setup();
      const { unmount } = show();
      await u.clear(field('Reorder point'));
      await u.type(field('Reorder point'), typed);
      await u.click(save());
      await waitFor(() => expect(calls.length).toBe(1));
      const body = JSON.parse(calls[0]!.body!);
      expect(body).toEqual({ reorder_point: typed });
      expect(Object.keys(body)).not.toContain('supplier_id');
      expect(Object.keys(body)).not.toContain('unit_of_measure_code');
      unmount();
      vi.unstubAllGlobals();
    }
  });

  it('every editable field changed at once: the body holds exactly those six, the unit as its code, never supplier_id (I-4 T-0c)', async () => {
    const calls = stubFetch([violation('/sku', 'x', 'blank')]);
    const u = userEvent.setup();
    show();
    for (const [n, v] of [['SKU', 'B-2'], ['Name', 'Nut'], ['Reorder point', '3'], ['Reorder quantity', '4'], ['Safety stock', '5']] as const) {
      await u.clear(field(n));
      await u.type(field(n), v);
    }
    await u.selectOptions(unit(), 'EA');
    await u.click(save());
    await waitFor(() => expect(calls.length).toBe(1));
    expect(JSON.parse(calls[0]!.body!)).toEqual({ sku: 'B-2', name: 'Nut', unit_of_measure_code: 'EA', reorder_point: '3', reorder_quantity: '4', safety_stock: '5' });
  });

  it('an emptied number: "Enter a number, or 0 for none." beside it, no request — on two consecutive presses — focus on it after the error is shown (R1-9)', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    show();
    const box = field('Safety stock');
    const invalidAtFocus: (string | null)[] = [];
    box.addEventListener('focus', () => invalidAtFocus.push(box.getAttribute('aria-invalid')));
    await u.clear(box);
    // Move focus away so the arrival of focus is observable.
    await u.click(field('Name'));
    invalidAtFocus.length = 0;
    await u.click(save());
    await waitFor(() => expect(box).toHaveAttribute('aria-invalid', 'true'));
    expect(box).toHaveAccessibleDescription(expect.stringContaining('Enter a number, or 0 for none.'));
    await waitFor(() => expect(box).toHaveFocus());
    await u.click(field('Name'));
    await u.click(save());
    await waitFor(() => expect(box).toHaveFocus());
    expect(box).toHaveAccessibleDescription(expect.stringContaining('Enter a number, or 0 for none.'));
    expect(calls).toEqual([]);
    // Focus arrived only once the error was in the DOM (focusTarget + useLayoutEffect; 4.5 RED2 M-1).
    expect(invalidAtFocus).toEqual(['true', 'true']);
  });

  it("a 422 puts the server's sentence beside its field, not in a banner, and focuses that field after the sentence is shown", async () => {
    stubFetch([violation('/reorder_quantity', I8)]);
    const u = userEvent.setup();
    show();
    const box = field('Reorder quantity');
    const invalidAtFocus: (string | null)[] = [];
    box.addEventListener('focus', () => invalidAtFocus.push(box.getAttribute('aria-invalid')));
    await u.clear(box);
    await u.type(box, '1e3');
    // Move focus away so the arrival of focus is observable.
    await u.click(field('Name'));
    invalidAtFocus.length = 0;
    await u.click(save());
    await waitFor(() => expect(box).toHaveAttribute('aria-invalid', 'true'));
    expect(box).toHaveAccessibleDescription(expect.stringContaining(I8));
    expect(screen.queryByText('Not saved')).toBeNull();
    await waitFor(() => expect(box).toHaveFocus());
    expect(invalidAtFocus).toEqual(['true']);
    expect(field('Reorder point')).not.toHaveAttribute('aria-invalid');
  });

  it('a later 422 clears an earlier refusal banner; a 409 replaces earlier field errors', async () => {
    stubFetch([refused('inventory_item.sku_is_unique_per_workspace', 'Another live item in this workspace already uses this SKU.'), json(200, BOLT), violation('/reorder_point', I8), json(200, BOLT)]);
    const u = userEvent.setup();
    show();
    await u.clear(field('SKU'));
    await u.type(field('SKU'), 'TAKEN');
    await u.click(save());
    expect(await screen.findByText('Another live item in this workspace already uses this SKU.')).toBeInTheDocument();
    await u.clear(field('Reorder point'));
    await u.type(field('Reorder point'), 'x');
    await u.click(save());
    await waitFor(() => expect(field('Reorder point')).toHaveAttribute('aria-invalid', 'true'));
    expect(screen.queryByText('Not saved')).toBeNull();
    expect(screen.queryByText('Another live item in this workspace already uses this SKU.')).toBeNull();
    // And back: a 409 now clears the field sentence.
    stubFetch([refused('inventory_item.sku_is_unique_per_workspace', 'Another live item in this workspace already uses this SKU.'), json(200, BOLT)]);
    await u.click(save());
    expect(await screen.findByText('Another live item in this workspace already uses this SKU.')).toBeInTheDocument();
    expect(field('Reorder point')).not.toHaveAttribute('aria-invalid');
  });

  it('P1 contention on Save: the server sentence under "Not saved", the edits kept and Save still offered (the re-read reads live and is not committed)', async () => {
    const calls = stubFetch([refused('transaction.contention', P1), json(200, { ...BOLT, name: 'Changed elsewhere' })]);
    const u = userEvent.setup();
    show();
    await u.type(field('Name'), ' M6');
    await u.click(save());
    expect(await screen.findByText(P1)).toBeInTheDocument();
    await waitFor(() => expect(screen.getByText('Not saved')).toHaveFocus());
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET']);
    expect(field('Name')).toHaveValue('Bolt M6');
    expect(save()).not.toHaveAttribute('aria-disabled');
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('A-1 — Bolt');
  });

  it('a refusal that proves the page stale (UX-I2 on a live page): the page re-reads, turns archived and read-only, and shows the sentence under "Not saved" (4.4 RED M-1)', async () => {
    const reread = { ...ARCHIVED, name: 'Bolt (re-read)' };
    const calls = stubFetch([refused('inventory_item.is_archived', I2), json(200, reread)]);
    const u = userEvent.setup();
    show(BOLT, true, true);
    await u.type(field('Name'), ' M6');
    await u.click(save());
    await waitFor(() => expect(screen.getByText(I2)).toBeInTheDocument());
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET']);
    await waitFor(() => expect(screen.getByText('Not saved')).toHaveFocus());
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('A-1 — Bolt (re-read)');
    expect(screen.queryAllByRole('textbox')).toEqual([]);
    expect(ddAfter('Name')).toHaveTextContent('Bolt (re-read)');
    expect(screen.getByText(/This item was archived on/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Restore item' })).toBeInTheDocument();
  });

  it('a 403 on Save: the lost-permission sentence names "Edit items" (B-9)', async () => {
    stubFetch([problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    show();
    await u.type(field('Name'), 'x');
    await u.click(save());
    expect(await screen.findByText(/which you no longer have/)).toHaveTextContent('This needs the Edit items permission, which you no longer have. Your workspace administrator can grant it.');
  });

  it('a lost connection: "We can\'t tell whether your changes to {sku} were saved. Reload the page to check."', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => {
      throw new TypeError('offline');
    }));
    const u = userEvent.setup();
    show();
    await u.type(field('Name'), 'x');
    await u.click(save());
    expect(await screen.findByText("We can't tell whether your changes to A-1 were saved. Reload the page to check.")).toBeInTheDocument();
  });

  it("saved but the re-read failed: the 200 body is committed, the region error shows, focus on its heading (4.2 RED M-1 shape; RED L-1, M-3 a)", async () => {
    stubFetch([json(200, { ...BOLT, sku: 'A-2', name: 'Bolt M6' }), json(500, {})]);
    const u = userEvent.setup();
    show();
    await u.clear(field('SKU'));
    await u.type(field('SKU'), 'A-2');
    await u.type(field('Name'), ' M6');
    await u.click(save());
    expect(await screen.findByText("We couldn't load this item again, so what's shown may be out of date.")).toBeInTheDocument();
    await waitFor(() => expect(screen.getByRole('heading', { level: 2, name: 'Something went wrong on our side.' })).toHaveFocus());
    expect(field('Name')).toHaveValue('Bolt M6');
    expect(region()).toHaveTextContent('A-2 was saved.');
    // L-1: the page shows what was saved (the 200 body), as Restore does.
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('A-2 — Bolt M6');
    expect(save()).toHaveAttribute('aria-disabled', 'true');
  });

  it('a later save whose re-read succeeds clears an earlier re-read error (RED L-2)', async () => {
    stubFetch([json(200, { ...BOLT, name: 'Bolt M6' }), json(500, {}), json(200, { ...BOLT, name: 'Bolt M7' }), json(200, { ...BOLT, name: 'Bolt M7' })]);
    const u = userEvent.setup();
    show();
    await u.type(field('Name'), ' M6');
    await u.click(save());
    expect(await screen.findByText("We couldn't load this item again, so what's shown may be out of date.")).toBeInTheDocument();
    await u.clear(field('Name'));
    await u.type(field('Name'), 'Bolt M7');
    await u.click(save());
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('A-1 — Bolt M7'));
    await waitFor(() => expect(screen.queryByText("We couldn't load this item again, so what's shown may be out of date.")).toBeNull());
  });

  // The warehouse re-read RED M-2, applied to the item page as its twin: a Retry overtaken by a newer commit is dropped.
  const staleRetry = async (release: () => Response) => {
    let releaseHeld!: (r: Response) => void;
    const held = new Promise<Response>((res) => (releaseHeld = res));
    stubFetch([json(200, { ...BOLT, name: 'Bolt M6' }), json(500, {}), () => held, json(200, { ...BOLT, name: 'Bolt M7' }), json(200, { ...BOLT, name: 'Bolt M7' })]);
    const u = userEvent.setup();
    show();
    await u.type(field('Name'), ' M6');
    await u.click(save());
    expect(await screen.findByText("We couldn't load this item again, so what's shown may be out of date.")).toBeInTheDocument();
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await u.clear(field('Name'));
    await u.type(field('Name'), 'Bolt M7');
    await u.click(save());
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('A-1 — Bolt M7'));
    await waitFor(() => expect(screen.queryByText("We couldn't load this item again, so what's shown may be out of date.")).toBeNull());
    await act(async () => {
      releaseHeld(release());
      await new Promise((t) => setTimeout(t, 50));
    });
  };

  it('a stale Retry that fails after a successful save does not bring the error back (M-2 twin, E1)', async () => {
    await staleRetry(() => json(500, {}));
    expect(screen.queryByText("We couldn't load this item again, so what's shown may be out of date.")).toBeNull();
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('A-1 — Bolt M7');
  });

  it('a stale Retry that answers the old item after a save does not overwrite it (M-2 twin, E2)', async () => {
    await staleRetry(() => json(200, { ...BOLT, name: 'Bolt M6' }));
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('A-1 — Bolt M7');
    expect(field('Name')).toHaveValue('Bolt M7');
  });

  // Commit 0a, the counter-race (the final RED's I-B, and its fail twin): a Retry pressed while a Save's PATCH is in
  // flight asked for its reading before the Save sent its re-read, so the Save's re-read is the newer reading even when
  // the Retry answers first. A write stamps when it sends its re-read, not when it sends its request.
  const REREAD_FAILED = "We couldn't load this item again, so what's shown may be out of date.";
  // The Save is over once the form has refreshed the router (`onSaved`, after the action resolved, so after any
  // region-error timer the action set); one more timer turn inside act lets that timer's update render.
  const settled = async (refreshes: number) => {
    await waitFor(() => expect(nav.refresh).toHaveBeenCalledTimes(refreshes));
    await act(async () => {
      await new Promise((t) => setTimeout(t, 20));
    });
  };
  const retryDuringSave = async (saveReread: Response) => {
    const patch = held();
    const retry = held();
    const reread = held();
    const calls = stubFetch([json(200, { ...BOLT, name: 'Boltx' }), json(503, {}), patch.reply, retry.reply, reread.reply]);
    const u = userEvent.setup();
    show();
    await u.type(field('Name'), 'x');
    await u.click(save());
    expect(await screen.findByText(REREAD_FAILED)).toBeInTheDocument();
    await u.type(field('Name'), 'y');
    await u.click(save());
    await waitFor(() => expect(calls).toHaveLength(3));
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(calls).toHaveLength(4));
    patch.release(json(200, { ...BOLT, name: 'Boltxy' }));
    await waitFor(() => expect(calls).toHaveLength(5));
    retry.release(json(200, { ...BOLT, name: 'Boltx' }));
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Retry' })).toBeNull());
    reread.release(saveReread);
    await settled(2);
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET', 'PATCH', 'GET', 'GET']);
  };

  it('a Retry started during a Save and answering first does not hide the saved value (0a, counter-race)', async () => {
    await retryDuringSave(json(200, { ...BOLT, name: 'Boltxy' }));
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('A-1 — Boltxy');
    expect(field('Name')).toHaveValue('Boltxy');
  });

  it("…and the same when the Save's re-read fails: the saved 200 body is shown, under the region error (0a, counter-race)", async () => {
    await retryDuringSave(json(503, {}));
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('A-1 — Boltxy');
    expect(screen.getByText(REREAD_FAILED)).toBeInTheDocument();
  });

  it("a write's failed re-read older than what is on screen does not raise the region error (0a)", async () => {
    const reread = held();
    const calls = stubFetch([json(200, { ...BOLT, name: 'Bolt M6' }), json(503, {}), json(200, { ...BOLT, name: 'Bolt M7' }), reread.reply, json(200, { ...BOLT, name: 'Bolt M7' })]);
    const u = userEvent.setup();
    show();
    await u.type(field('Name'), ' M6');
    await u.click(save());
    expect(await screen.findByText(REREAD_FAILED)).toBeInTheDocument();
    await u.clear(field('Name'));
    await u.type(field('Name'), 'Bolt M7');
    await u.click(save());
    await waitFor(() => expect(calls).toHaveLength(4));
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(screen.queryByText(REREAD_FAILED)).toBeNull());
    reread.release(json(503, {}));
    await settled(2);
    expect(screen.queryByText(REREAD_FAILED)).toBeNull();
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('A-1 — Bolt M7');
  });

  it('a refused Save whose re-read fails: UX-I2 under "Not saved" AND the region error (RED M-1 a)', async () => {
    stubFetch([refused('inventory_item.is_archived', I2), json(500, {})]);
    const u = userEvent.setup();
    show(BOLT, true, true);
    await u.type(field('Name'), 'x');
    await u.click(save());
    expect(await screen.findByText(I2)).toBeInTheDocument();
    expect(screen.getByText('Not saved')).toBeInTheDocument();
    expect(await screen.findByText("We couldn't load this item again, so what's shown may be out of date.")).toBeInTheDocument();
  });

  it('a refused Save whose re-read answers 403: ST-4b for items (RED M-1 b)', async () => {
    stubFetch([refused('inventory_item.is_archived', I2), problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    show(BOLT, true, true);
    await u.type(field('Name'), 'x');
    await u.click(save());
    expect(await screen.findByRole('heading', { level: 1, name: "You don't have access to items." })).toBeInTheDocument();
  });

  it('P1 contention whose re-read finds the item archived concurrently: the page turns archived, and P1 is never shown as the archived-elsewhere refusal (RED L-3)', async () => {
    stubFetch([refused('transaction.contention', P1), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    show(BOLT, true, true);
    await u.type(field('Name'), 'x');
    await u.click(save());
    await waitFor(() => expect(screen.getByText(/This item was archived on/)).toBeInTheDocument());
    // The form is gone with the live state, so its banner goes with it: no "Not saved … try again" over a page with no Save.
    expect(screen.queryByText(P1)).toBeNull();
    expect(screen.queryByText('Not saved')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Save' })).toBeNull();
    expect(screen.getByRole('button', { name: 'Restore item' })).toBeInTheDocument();
  });

  it('a 422 with two field sentences focuses the FIRST invalid field in screen order (RED L-6 b)', async () => {
    stubFetch([problem(422, 'validation-failed', { errors: [{ pointer: '/safety_stock', code: 'not_a_decimal', message: I8 }, { pointer: '/name', code: 'blank', message: 'This field may not be blank.' }] })]);
    const u = userEvent.setup();
    show();
    await u.clear(field('Safety stock'));
    await u.type(field('Safety stock'), 'x');
    await u.click(save());
    await waitFor(() => expect(field('Safety stock')).toHaveAttribute('aria-invalid', 'true'));
    expect(field('Name')).toHaveAttribute('aria-invalid', 'true');
    await waitFor(() => expect(field('Name')).toHaveFocus());
  });

  it('two emptied numbers: both refused, focus on the first in screen order (RED L-6 b)', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    show();
    await u.clear(field('Safety stock'));
    await u.clear(field('Reorder point'));
    await u.click(field('Name'));
    await u.click(save());
    await waitFor(() => expect(field('Safety stock')).toHaveAttribute('aria-invalid', 'true'));
    expect(field('Reorder point')).toHaveAttribute('aria-invalid', 'true');
    await waitFor(() => expect(field('Reorder point')).toHaveFocus());
    expect(calls).toEqual([]);
  });

  it('the re-read answers 404 → ST-5 back to /items', async () => {
    stubFetch([json(200, BOLT), problem(404, 'not-found')]);
    const u = userEvent.setup();
    show();
    await u.type(field('Name'), 'x');
    await u.click(save());
    expect(await screen.findByRole('link', { name: 'Back to the list' })).toHaveAttribute('href', '/items');
  });

  it('without UpdateItem: plain text, no field, no Save, no edit hints', () => {
    show(BOLT, false);
    expect(screen.queryAllByRole('textbox')).toEqual([]);
    expect(screen.queryByRole('button', { name: 'Save' })).toBeNull();
    expect(ddAfter('SKU')).toHaveTextContent('A-1');
    expect(screen.queryByText(/A new SKU also changes it/)).toBeNull();
  });
});

describe('/items/[id] — archived, and Restore item (B-5, ST-9)', () => {
  const restore = () => screen.getByRole('button', { name: 'Restore item' });

  it('the ST-9 banner with its date (DT-1), read-only even with UpdateItem, no Restore without RestoreItem, no edit hints', () => {
    show(ARCHIVED, true, false);
    expect(screen.getByText(/This item was archived on/)).toHaveTextContent(`This item was archived on ${formatDate(AT, TZ)}.`);
    expect(screen.queryAllByRole('textbox')).toEqual([]);
    expect(screen.queryByRole('button', { name: 'Restore item' })).toBeNull();
    expect(screen.queryByText(/Renaming also changes/)).toBeNull();
  });

  it('a live item never shows Restore item, whatever the viewer holds', () => {
    show(BOLT, true, true);
    expect(screen.queryByRole('button', { name: 'Restore item' })).toBeNull();
  });

  it('restored: POST …/restoration with no body, re-reads, says "{sku} was restored.", focuses the h1, turns live without a remount', async () => {
    const calls = stubFetch([json(200, BOLT), json(200, { ...BOLT, name: 'Bolt (re-read)' })]);
    const u = userEvent.setup();
    show(ARCHIVED, true, true);
    const h1 = screen.getByRole('heading', { level: 1 });
    await u.click(restore());
    await waitFor(() => expect(region()).toHaveTextContent('A-1 was restored.'));
    expect(calls.map((c) => c.method)).toEqual(['POST', 'GET']);
    expect(calls[0]!.url).toContain(`/bff/api/items/${IID}/restoration`);
    expect(calls[0]!.body ?? null).toBeNull();
    expect(screen.getByRole('heading', { level: 1 })).toBe(h1);
    expect(h1).toHaveTextContent('A-1 — Bolt (re-read)');
    await waitFor(() => expect(h1).toHaveFocus());
    expect(screen.queryByText(/This item was archived on/)).toBeNull();
    expect(field('Name')).toHaveValue('Bolt (re-read)');
    expect(nav.refresh).toHaveBeenCalledTimes(1);
  });

  it('refused, and the re-read finds it live: the page turns live and says "{sku} was already restored." (R1-17)', async () => {
    stubFetch([refused('inventory_item.is_not_archived', 'This item is not archived, so there is nothing to restore.'), json(200, BOLT)]);
    const u = userEvent.setup();
    show(ARCHIVED, true, true);
    await u.click(restore());
    await waitFor(() => expect(region()).toHaveTextContent('A-1 was already restored.'));
    expect(screen.queryByText(/This item was archived on/)).toBeNull();
    expect(screen.queryByRole('button', { name: 'Restore item' })).toBeNull();
  });

  it('refused (UX-I1), and the re-read still reads archived: the sentence under "Not restored", focused, the button stays', async () => {
    const i1 = 'Another live item in this workspace already uses this SKU.';
    stubFetch([refused('inventory_item.sku_is_unique_per_workspace', i1), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    show(ARCHIVED, true, true);
    await u.click(restore());
    expect(await screen.findByText(i1)).toBeInTheDocument();
    await waitFor(() => expect(screen.getByText('Not restored')).toHaveFocus());
    expect(restore()).toBeInTheDocument();
    expect(nav.refresh).not.toHaveBeenCalled();
  });

  it('P1 contention, then a second press succeeds: Restore is kept and the press after "try again" is not stuck (4.2 H-1)', async () => {
    stubFetch([refused('transaction.contention', P1), json(200, ARCHIVED), json(200, BOLT), json(200, BOLT)]);
    const u = userEvent.setup();
    show(ARCHIVED, true, true);
    await u.click(restore());
    expect(await screen.findByText(P1)).toBeInTheDocument();
    await u.click(restore());
    await waitFor(() => expect(region()).toHaveTextContent('A-1 was restored.'));
    expect(screen.queryByRole('button', { name: 'Restore item' })).toBeNull();
  });

  it('refused (UX-I1) and the re-read fails: the sentence under "Not restored" AND the region error (RED M-1 c)', async () => {
    const i1 = 'Another live item in this workspace already uses this SKU.';
    stubFetch([refused('inventory_item.sku_is_unique_per_workspace', i1), json(500, {})]);
    const u = userEvent.setup();
    show(ARCHIVED, true, true);
    await u.click(restore());
    expect(await screen.findByText(i1)).toBeInTheDocument();
    expect(screen.getByText('Not restored')).toBeInTheDocument();
    expect(await screen.findByText("We couldn't load this item again, so what's shown may be out of date.")).toBeInTheDocument();
    expect(restore()).toBeInTheDocument();
  });

  it('refused (UX-I1) and the re-read answers 403: ST-4b for items (RED-2 L-5)', async () => {
    stubFetch([refused('inventory_item.sku_is_unique_per_workspace', 'Another live item in this workspace already uses this SKU.'), problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    show(ARCHIVED, true, true);
    await u.click(restore());
    expect(await screen.findByRole('heading', { level: 1, name: "You don't have access to items." })).toBeInTheDocument();
  });

  it('refused (UX-I1) and the re-read answers 404: ST-5 back to the list (RED-2 L-5)', async () => {
    stubFetch([refused('inventory_item.sku_is_unique_per_workspace', 'Another live item in this workspace already uses this SKU.'), problem(404, 'not-found')]);
    const u = userEvent.setup();
    show(ARCHIVED, true, true);
    await u.click(restore());
    expect(await screen.findByRole('link', { name: 'Back to the list' })).toHaveAttribute('href', '/items');
  });

  it('restored (200) but the re-read fails: the 200 body is committed, never "restored" over the archived banner (RED M-3 d)', async () => {
    stubFetch([json(200, BOLT), json(500, {})]);
    const u = userEvent.setup();
    show(ARCHIVED, true, true);
    await u.click(restore());
    await waitFor(() => expect(region()).toHaveTextContent('A-1 was restored.'));
    expect(screen.queryByText(/This item was archived on/)).toBeNull();
    expect(await screen.findByText("We couldn't load this item again, so what's shown may be out of date.")).toBeInTheDocument();
    expect(field('Name')).toHaveValue('Bolt');
  });

  it('a 403 names "Delete and restore items"; offline says "Press Restore item to try again."', async () => {
    stubFetch([problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    const { unmount } = show(ARCHIVED, true, true);
    await u.click(restore());
    expect(await screen.findByText(/which you no longer have/)).toHaveTextContent('This needs the Delete and restore items permission, which you no longer have. Your workspace administrator can grant it.');
    unmount();
    vi.stubGlobal('fetch', vi.fn(async () => {
      throw new TypeError('offline');
    }));
    show(ARCHIVED, true, true);
    await u.click(restore());
    expect(await screen.findByText("We can't tell whether A-1 was restored. Press Restore item to try again.")).toBeInTheDocument();
  });

  it('after a stale Save turned the page archived, a restore removes the "Not saved" banner with the archived state', async () => {
    stubFetch([refused('inventory_item.is_archived', I2), json(200, ARCHIVED), json(200, BOLT), json(200, BOLT)]);
    const u = userEvent.setup();
    show(BOLT, true, true);
    await u.type(field('Name'), 'x');
    await u.click(save());
    expect(await screen.findByText(I2)).toBeInTheDocument();
    await u.click(restore());
    await waitFor(() => expect(region()).toHaveTextContent('A-1 was restored.'));
    expect(screen.queryByText(I2)).toBeNull();
    expect(within(document.body).queryByText('Not saved')).toBeNull();
  });

  // The restore must FORGET the stale Save's sentence, not merely hide it while the page is live: here the page turns
  // archived again by a path that sets no sentence of its own (P1 + an archived re-read, which returns `refused`, RED
  // L-3), and the old UX-I2 sentence must not come back with it.
  it('a restore forgets the stale Save refusal: when the page turns archived again by another path, the old "Not saved" does not return', async () => {
    stubFetch([
      refused('inventory_item.is_archived', I2),
      json(200, ARCHIVED),
      json(200, BOLT),
      json(200, BOLT),
      refused('transaction.contention', P1),
      json(200, ARCHIVED),
    ]);
    const u = userEvent.setup();
    show(BOLT, true, true);
    await u.type(field('Name'), 'x');
    await u.click(save());
    expect(await screen.findByText(I2)).toBeInTheDocument();
    expect(screen.getByText('Not saved')).toBeInTheDocument();
    await u.click(restore());
    await waitFor(() => expect(region()).toHaveTextContent('A-1 was restored.'));
    // Live: the banner and its sentence are gone.
    expect(screen.queryByText(I2)).toBeNull();
    expect(within(document.body).queryByText('Not saved')).toBeNull();
    expect(screen.queryByText(/This item was archived on/)).toBeNull();
    expect(field('Name')).toHaveValue('Bolt');
    // Archived again, by P1 + an archived re-read: no sentence of its own, and the old one must not reappear.
    await u.type(field('Name'), 'y');
    await u.click(save());
    await waitFor(() => expect(screen.getByText(/This item was archived on/)).toBeInTheDocument());
    expect(screen.getByRole('heading', { level: 1 })).toHaveFocus();
    expect(screen.queryByText(I2)).toBeNull();
    expect(within(document.body).queryByText('Not saved')).toBeNull();
  });

  it('a page turned live by Retry forgets the stale Save refusal too: it does not return when the page turns archived again (RED-2 L-1)', async () => {
    const i1 = 'Another live item in this workspace already uses this SKU.';
    stubFetch([
      refused('inventory_item.is_archived', I2),
      json(200, ARCHIVED),
      refused('inventory_item.sku_is_unique_per_workspace', i1),
      json(500, {}),
      json(200, BOLT),
      refused('transaction.contention', P1),
      json(200, ARCHIVED),
    ]);
    const u = userEvent.setup();
    const errors = vi.spyOn(console, 'error');
    show(BOLT, true, true);
    await u.type(field('Name'), 'x');
    await u.click(save());
    expect(await screen.findByText(I2)).toBeInTheDocument();
    await u.click(restore());
    expect(await screen.findByText("We couldn't load this item again, so what's shown may be out of date.")).toBeInTheDocument();
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(screen.queryByText(/This item was archived on/)).toBeNull());
    await u.type(field('Name'), 'y');
    await u.click(save());
    await waitFor(() => expect(screen.getByText(/This item was archived on/)).toBeInTheDocument());
    expect(screen.queryByText(I2)).toBeNull();
    expect(within(document.body).queryByText('Not saved')).toBeNull();
    expect(errors.mock.calls.flat().join(' ')).not.toMatch(/same key/);
    errors.mockRestore();
  });
});

// Items I-2 (items-i2-design.md Revision 1 — FINAL A-11, T-6; B-4 "After"): the create page leaves "{sku} was created."
// for `/items/{id}`; the item page speaks it once, after RouteFocus has focused its h1, in plain and StrictMode, and not
// again after a remount. The arrival module itself is pinned in warehouse-create.test.tsx.
describe.each([
  ['plain', false],
  ['StrictMode', true],
] as const)('/items/[id] — the arrival message after a create (%s)', (_name, strict) => {
  afterEach(() => {
    takeArrivalMessage('');
  });
  let go: (path: string) => void = () => {};
  function Frame() {
    const [path, setPath] = useState('/items/new');
    go = setPath;
    return (
      <>
        <main id="main-content">{path === '/items/new' ? <h1 tabIndex={-1}>New item</h1> : path === 'gone' ? null : <ItemPage initial={BOLT} canEdit canRestore={false} />}</main>
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

  it('the region holds "{sku} was created." once, the h1 focused when it does; a remount says nothing', async () => {
    mount();
    leaveArrivalMessage(`/items/${IID}`, 'A-1 was created.');
    const seen: { text: string; focusedH1: boolean }[] = [];
    const observer = new MutationObserver(() => {
      const r = document.querySelector('section > [role="status"]');
      if (r && r.textContent !== '') seen.push({ text: r.textContent ?? '', focusedH1: document.activeElement?.tagName === 'H1' });
    });
    observer.observe(document.body, { subtree: true, childList: true, characterData: true });
    act(() => go(`/items/${IID}`));
    await waitFor(() => expect(seen.length).toBeGreaterThan(0));
    await new Promise((r) => setTimeout(r, 20));
    expect(seen).toEqual([{ text: 'A-1 was created.', focusedH1: true }]);
    expect(document.activeElement).toBe(screen.getByRole('heading', { level: 1, name: 'A-1 — Bolt' }));
    act(() => go('gone'));
    act(() => go(`/items/${IID}`));
    await new Promise((r) => setTimeout(r, 20));
    observer.disconnect();
    expect(document.querySelector('section > [role="status"]')!.textContent).toBe('');
  });

  it('a message left for another address is not spoken here', async () => {
    mount();
    leaveArrivalMessage('/items/other', 'X was created.');
    act(() => go(`/items/${IID}`));
    await new Promise((r) => setTimeout(r, 20));
    expect(document.querySelector('section > [role="status"]')!.textContent).toBe('');
  });
});

describe('/items/[id] — the editor banner (ItemForm)', () => {
  it('two out-of-form violations with the same sentence: both shown, no duplicate-key warning', async () => {
    const errors = vi.spyOn(console, 'error').mockImplementation(() => {});
    try {
      stubFetch([problem(422, 'validation-failed', { errors: [{ pointer: '/supplier_id', code: 'x', message: 'Same.' }, { pointer: '', code: 'y', message: 'Same.' }] })]);
      const u = userEvent.setup();
      show();
      await u.type(field('Name'), 'x');
      await u.click(save());
      expect(await screen.findAllByText('Same.')).toHaveLength(2);
      expect(errors.mock.calls.filter((c) => String(c[0]).includes('same key'))).toEqual([]);
    } finally {
      errors.mockRestore();
    }
  });
});

// I-2 follow-up (items-i3-ux.md Revision 1 FINAL F-5 #1): a 422 with an out-of-form sentence AND a field sentence ends
// its banner with this line; no other banner carries it.
const MIXED_LINE = 'Check the marked fields below too.';
const bannerBody = (heading: HTMLElement) => [...heading.parentElement!.children].filter((e) => e !== heading).map((e) => e.textContent);

describe('/items/[id] (ItemForm) — the mixed-422 line (I-2 follow-up)', () => {
  it('a 422 with an out-of-form sentence and a field sentence: the banner ends with "Check the marked fields below too."', async () => {
    stubFetch([problem(422, 'validation-failed', { errors: [{ pointer: '/supplier_id', code: 'x', message: 'Out of form.' }, { pointer: '/name', code: 'blank', message: 'This field may not be blank.' }] })]);
    const u = userEvent.setup();
    show();
    await u.type(field('Name'), 'x');
    await u.click(save());
    const heading = await screen.findByText('Not saved');
    expect(bannerBody(heading)).toEqual(['Out of form.', MIXED_LINE]);
  });

  it('a banner-only 422 and a 409 carry no such line', async () => {
    stubFetch([problem(422, 'validation-failed', { errors: [{ pointer: '/supplier_id', code: 'x', message: 'Out of form.' }, { pointer: '', code: 'y', message: 'Also out of form.' }] }), refused('some.rule', 'Refused here.')]);
    const u = userEvent.setup();
    show();
    await u.type(field('Name'), 'x');
    await u.click(save());
    const heading = await screen.findByText('Not saved');
    expect(bannerBody(heading)).toEqual(['Out of form.', 'Also out of form.']);
    expect(screen.queryByText(MIXED_LINE)).toBeNull();
    await u.click(save());
    expect(await screen.findByText('Refused here.')).toBeInTheDocument();
    expect(screen.queryByText(MIXED_LINE)).toBeNull();
  });
});

// Items I-3 T-4 (ruling 1(a), I3-9): an item body whose holds_stock is missing, null or a string is malformed. A re-read
// that answers one shows the region error; an UpdateItem 200 or a RestoreItem 200 without it is server-error.
describe('/items/[id] — holds_stock is required on every item body (I-3 T-4)', () => {
  const without = (i: Item, value?: unknown) => {
    const b = { ...i } as Record<string, unknown>;
    if (value === undefined) delete b.holds_stock;
    else b.holds_stock = value;
    return b;
  };

  it.each([
    ['missing', undefined],
    ['null', null],
    ['"true"', 'true'],
  ] as const)('a Save re-read whose holds_stock is %s: the region error (the re-read failed), not a committed item', async (_, value) => {
    stubFetch([json(200, { ...BOLT, name: 'Boltx' }), json(200, without({ ...BOLT, name: 'Boltx' }, value))]);
    const u = userEvent.setup();
    show();
    await u.type(field('Name'), 'x');
    await u.click(save());
    expect(await screen.findByText("We couldn't load this item again, so what's shown may be out of date.")).toBeInTheDocument();
  });

  it('an UpdateItem 200 without holds_stock: "Something went wrong on our side." + the interrupted sentence, no re-read', async () => {
    const calls = stubFetch([json(200, without({ ...BOLT, name: 'Boltx' }))]);
    const u = userEvent.setup();
    show();
    await u.type(field('Name'), 'x');
    await u.click(save());
    expect(await screen.findByText("We can't tell whether your changes to A-1 were saved. Reload the page to check.")).toBeInTheDocument();
    expect(screen.getByText('Something went wrong on our side.')).toBeInTheDocument();
    expect(calls.map((c) => c.method)).toEqual(['PATCH']);
  });

  it('a RestoreItem 200 without holds_stock: "Something went wrong on our side." + the interrupted sentence, still archived', async () => {
    const calls = stubFetch([json(200, without(BOLT))]);
    const u = userEvent.setup();
    show(ARCHIVED, true, true);
    await u.click(screen.getByRole('button', { name: 'Restore item' }));
    expect(await screen.findByText("We can't tell whether A-1 was restored. Press Restore item to try again.")).toBeInTheDocument();
    expect(screen.getByText('Something went wrong on our side.')).toBeInTheDocument();
    expect(screen.getByText(/This item was archived on/)).toBeInTheDocument();
    expect(calls.map((c) => c.method)).toEqual(['POST']);
  });
});

// Items I-4 (items-i4-design.md Revision 1 — FINAL §11 as corrected by items-i4-red.md; items-i4-ux.md Revision 1 —
// FINAL): the Unit becomes a select on the item page. It is sent only when it differs from the saved unit; both 409s
// (in use, contention) take the shared `refused` path under **Not saved**, the server's sentence its only paragraph, the
// edits kept (option A). In edit the suffix after each number follows the select (RED H-1).
describe('/items/[id] — I-4: the Unit select', () => {

  it('T-1: changing the unit enables Save; picking the saved unit back is "Nothing to save." and two presses send nothing', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    show();
    await u.selectOptions(unit(), 'EA');
    expect(save()).not.toHaveAttribute('aria-disabled');
    await u.selectOptions(unit(), 'KG');
    expect(save()).toHaveAttribute('aria-disabled', 'true');
    expect(save()).toHaveAccessibleDescription('Nothing to save.');
    await u.click(save());
    await u.click(save());
    expect(calls).toEqual([]);
  });

  it('T-2: a unit-only change sends exactly { unit_of_measure_code: "EA" }; the re-read commits it; "{sku} was saved."; the h1 focused', async () => {
    const calls = stubFetch([json(200, { ...BOLT, unit_of_measure_code: 'EA' }), json(200, { ...BOLT, unit_of_measure_code: 'EA' })]);
    const u = userEvent.setup();
    show();
    await u.selectOptions(unit(), 'EA');
    await u.click(save());
    await waitFor(() => expect(region()).toHaveTextContent('A-1 was saved.'));
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET']);
    expect(JSON.parse(calls[0]!.body!)).toEqual({ unit_of_measure_code: 'EA' });
    expect(unit()).toHaveValue('EA');
    expect(save()).toHaveAttribute('aria-disabled', 'true');
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveFocus());
  });

  it('T-3: a Name-only change never sends the unit', async () => {
    const calls = stubFetch([json(200, { ...BOLT, name: 'Bolt M6' }), json(200, { ...BOLT, name: 'Bolt M6' })]);
    const u = userEvent.setup();
    show();
    await u.type(field('Name'), ' M6');
    await u.click(save());
    await waitFor(() => expect(region()).toHaveTextContent('A-1 was saved.'));
    expect(JSON.parse(calls[0]!.body!)).toEqual({ name: 'Bolt M6' });
  });

  it('T-M1: the select is described by U4-6 verbatim; the hint is the whole description', () => {
    show();
    expect(unit()).toHaveAccessibleDescription(UNIT_HINT);
  });

  it("T-4′ (1)(2): the suffix after each number follows the select in edit, and returns with it", async () => {
    const u = userEvent.setup();
    show();
    expectSuffix('KG');
    await u.selectOptions(unit(), 'EA');
    expectSuffix('EA');
    await u.selectOptions(unit(), 'KG');
    expectSuffix('KG');
    expect(save()).toHaveAttribute('aria-disabled', 'true');
  });

  it.each([
    ['in use', () => refused('inventory_item.unit_in_use', IN_USE), IN_USE],
    ['contention', () => refused('transaction.contention', P1), P1],
  ])('T-4′ (3): a %s 409 with a live re-read leaves the select and every suffix at the refused unit', async (_n, answer, sentence) => {
    stubFetch([answer(), json(200, BOLT)]);
    const u = userEvent.setup();
    show();
    await u.selectOptions(unit(), 'EA');
    await u.type(field('Name'), ' M6');
    await u.click(save());
    expect(await screen.findByText(sentence)).toBeInTheDocument();
    expect(unit()).toHaveValue('EA');
    expectSuffix('EA');
  });

  it('T-4′ (4): after a 200 and a re-read of an EA item, the select and the suffix read EA', async () => {
    stubFetch([json(200, { ...BOLT, unit_of_measure_code: 'EA' }), json(200, { ...BOLT, unit_of_measure_code: 'EA', name: 'Bolt (re-read)' })]);
    const u = userEvent.setup();
    show();
    await u.selectOptions(unit(), 'EA');
    await u.click(save());
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('A-1 — Bolt (re-read)'));
    expect(unit()).toHaveValue('EA');
    expectSuffix('EA');
  });

  it('T-4b: saved, the re-read fails: the 200 body (unit EA) is committed under the region error, and the suffix reads EA', async () => {
    stubFetch([json(200, { ...BOLT, unit_of_measure_code: 'EA' }), json(500, {})]);
    const u = userEvent.setup();
    show();
    await u.selectOptions(unit(), 'EA');
    await u.click(save());
    expect(await screen.findByText("We couldn't load this item again, so what's shown may be out of date.")).toBeInTheDocument();
    expect(unit()).toHaveValue('EA');
    expect(save()).toHaveAttribute('aria-disabled', 'true');
    expectSuffix('EA');
  });

  it('T-5: in use (option A): the sentence as sent is the banner\'s only paragraph under "Not saved", heading focused; Name and the unit kept; a second press sends both again', async () => {
    const calls = stubFetch([refused('inventory_item.unit_in_use', IN_USE), json(200, BOLT), refused('inventory_item.unit_in_use', IN_USE), json(200, BOLT)]);
    const u = userEvent.setup();
    show();
    await u.selectOptions(unit(), 'EA');
    await u.type(field('Name'), ' M6');
    await u.click(save());
    expect(await screen.findByText(IN_USE)).toBeInTheDocument();
    await waitFor(() => expect(screen.getByText('Not saved')).toHaveFocus());
    const heading = screen.getByText('Not saved');
    const banner = heading.parentElement!;
    expect([...banner.querySelectorAll('p')].filter((p) => p !== heading).map((p) => p.textContent)).toEqual([IN_USE]);
    expect(unit()).toHaveValue('EA');
    expect(unit()).not.toHaveAttribute('aria-invalid');
    expect(field('Name')).toHaveValue('Bolt M6');
    expect(save()).not.toHaveAttribute('aria-disabled');
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('A-1 — Bolt');
    await u.click(save());
    await waitFor(() => expect(calls).toHaveLength(4));
    expect(JSON.parse(calls[0]!.body!)).toEqual({ name: 'Bolt M6', unit_of_measure_code: 'EA' });
    expect(JSON.parse(calls[2]!.body!)).toEqual({ name: 'Bolt M6', unit_of_measure_code: 'EA' });
  });

  it('T-6: contention from a unit change: P1 under "Not saved", the unit kept, Save offered; a second press sends the unit again and saves', async () => {
    const calls = stubFetch([refused('transaction.contention', P1), json(200, BOLT), json(200, { ...BOLT, unit_of_measure_code: 'EA' }), json(200, { ...BOLT, unit_of_measure_code: 'EA' })]);
    const u = userEvent.setup();
    show();
    await u.selectOptions(unit(), 'EA');
    await u.click(save());
    expect(await screen.findByText(P1)).toBeInTheDocument();
    expect(unit()).toHaveValue('EA');
    expect(save()).not.toHaveAttribute('aria-disabled');
    await u.click(save());
    await waitFor(() => expect(region()).toHaveTextContent('A-1 was saved.'));
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET', 'PATCH', 'GET']);
    expect(JSON.parse(calls[2]!.body!)).toEqual({ unit_of_measure_code: 'EA' });
  });

  it('T-7: in use, and the re-read finds the item archived: the page turns read-only, the Unit is the archived reading\'s code as text, the sentence under "Not saved"', async () => {
    stubFetch([refused('inventory_item.unit_in_use', IN_USE), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    show(BOLT, true, true);
    await u.selectOptions(unit(), 'EA');
    await u.click(save());
    await waitFor(() => expect(screen.getByText(/This item was archived on/)).toBeInTheDocument());
    expect(screen.getByText(IN_USE)).toBeInTheDocument();
    await waitFor(() => expect(screen.getByText('Not saved')).toHaveFocus());
    expect(screen.queryByRole('combobox')).toBeNull();
    expect(ddAfter('Unit of measure')).toHaveTextContent(/^KG$/);
    expect(shownText(ddAfter('Reorder point'))).toBe('10 KG');
  });

  it('T-8: a 422 on /unit_of_measure_code sits beside the select, not in a banner, and the select takes focus once it is shown', async () => {
    stubFetch([violation('/unit_of_measure_code', 'No unit of measure has this code.', 'unknown_reference')]);
    const u = userEvent.setup();
    show();
    const select = unit();
    const invalidAtFocus: (string | null)[] = [];
    select.addEventListener('focus', () => invalidAtFocus.push(select.getAttribute('aria-invalid')));
    await u.selectOptions(select, 'EA');
    await u.click(field('Name'));
    invalidAtFocus.length = 0;
    await u.click(save());
    await waitFor(() => expect(select).toHaveAttribute('aria-invalid', 'true'));
    expect(select).toHaveAccessibleDescription(`${UNIT_HINT} No unit of measure has this code.`);
    expect(screen.queryByText('Not saved')).toBeNull();
    await waitFor(() => expect(select).toHaveFocus());
    expect(invalidAtFocus).toEqual(['true']);
  });

  it('T-8: focus follows screen order: SKU before Unit, Unit before a number', async () => {
    for (const [errors, first] of [
      [[{ pointer: '/unit_of_measure_code', code: 'unknown_reference', message: 'U.' }, { pointer: '/sku', code: 'blank', message: 'S.' }], () => field('SKU')],
      [[{ pointer: '/safety_stock', code: 'not_a_decimal', message: I8 }, { pointer: '/unit_of_measure_code', code: 'unknown_reference', message: 'U.' }], () => unit()],
    ] as const) {
      stubFetch([problem(422, 'validation-failed', { errors })]);
      const u = userEvent.setup();
      const { unmount } = show();
      await u.selectOptions(unit(), 'EA');
      await u.click(save());
      await waitFor(() => expect(unit()).toHaveAttribute('aria-invalid', 'true'));
      await waitFor(() => expect(first()).toHaveFocus());
      unmount();
      vi.unstubAllGlobals();
    }
  });

  it('T-9: a saved unit the served list lacks is offered first, as sent; untouched it is never sent; picked back after another, it is "Nothing to save." again', async () => {
    const calls = stubFetch([json(200, { ...BOLT, unit_of_measure_code: 'EA' }), json(200, { ...BOLT, unit_of_measure_code: 'EA' })]);
    const u = userEvent.setup();
    show({ ...BOLT, unit_of_measure_code: 'XX' });
    const first = () => (within(unit()).getAllByRole('option') as HTMLOptionElement[])[0]!;
    expect(first().value).toBe('XX');
    expect(first().textContent).toBe('XX');
    expect(unit()).toHaveValue('XX');
    // S-3 T-S3-3: a saved unit the list lacks is shown as sent and spoken as sent, with the field's full stop.
    expectSuffix('XX');
    expect(save()).toHaveAttribute('aria-disabled', 'true');
    await u.selectOptions(unit(), 'EA');
    expect(first().value).toBe('XX');
    await u.selectOptions(unit(), 'XX');
    expect(unit()).toHaveValue('XX');
    expect(save()).toHaveAttribute('aria-disabled', 'true');
    await u.click(save());
    expect(calls).toEqual([]);
    await u.selectOptions(unit(), 'EA');
    await u.click(save());
    await waitFor(() => expect(calls).toHaveLength(2));
    expect(JSON.parse(calls[0]!.body!)).toEqual({ unit_of_measure_code: 'EA' });
  });

  it('T-10: no empty option on the item page (an existing item always has a unit)', () => {
    show();
    expect((within(unit()).getAllByRole('option') as HTMLOptionElement[]).map((o) => o.value)).not.toContain('');
  });

  it('T-15: edits typed during a save are replaced by the saved item (recorded behaviour; RED L-5, UX confirmed for I-4)', async () => {
    const patch = held();
    const calls = stubFetch([patch.reply, json(200, { ...BOLT, unit_of_measure_code: 'EA' })]);
    const u = userEvent.setup();
    show();
    await u.selectOptions(unit(), 'EA');
    await u.click(save());
    await waitFor(() => expect(calls).toHaveLength(1));
    await u.type(field('Name'), ' typed while saving');
    expect(field('Name')).toHaveValue('Bolt typed while saving');
    patch.release(json(200, { ...BOLT, unit_of_measure_code: 'EA' }));
    await waitFor(() => expect(region()).toHaveTextContent('A-1 was saved.'));
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET']);
    expect(field('Name')).toHaveValue('Bolt');
    expect(unit()).toHaveValue('EA');
  });

  it('T-18: a refusal answered after a newer commit (a Retry during the Save): "Not saved" + the sentence over a form reset to the newer reading', async () => {
    const patch = held();
    const retry = held();
    const calls = stubFetch([json(200, { ...BOLT, name: 'Boltx' }), json(503, {}), patch.reply, retry.reply, json(200, { ...BOLT, name: 'Bolt (theirs)' })]);
    const u = userEvent.setup();
    show();
    await u.type(field('Name'), 'x');
    await u.click(save());
    expect(await screen.findByText("We couldn't load this item again, so what's shown may be out of date.")).toBeInTheDocument();
    await u.selectOptions(unit(), 'EA');
    await u.type(field('Name'), 'y');
    await u.click(save());
    await waitFor(() => expect(calls).toHaveLength(3));
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(calls).toHaveLength(4));
    retry.release(json(200, { ...BOLT, name: 'Bolt (theirs)' }));
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('A-1 — Bolt (theirs)'));
    patch.release(refused('inventory_item.unit_in_use', IN_USE));
    expect(await screen.findByText(IN_USE)).toBeInTheDocument();
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET', 'PATCH', 'GET', 'GET']);
    expect(screen.getByText('Not saved')).toBeInTheDocument();
    // The edits were lost to the newer reading (the shipped loadedKey rule), recorded.
    expect(unit()).toHaveValue('KG');
    expect(field('Name')).toHaveValue('Bolt (theirs)');
    expect(save()).toHaveAttribute('aria-disabled', 'true');
  });
});

// S-3 (items-s3-design.md Revision 1 — FINAL item 7(d), with s3-fer8-final-red.md F-1): on the read-only and archived
// pages each number is the QR-2 value, the code shown but hidden from assistive technology, then the unit's spoken word
// (bare: no full stop, no id), never inside an aria-hidden subtree.
describe('/items/[id] — S-3: the read-only and archived numbers say the unit by its name', () => {
  it.each([
    ['read-only, KG', BOLT, false, 'KG', 'kilograms'],
    ['archived, KG', ARCHIVED, true, 'KG', 'kilograms'],
    ['read-only, EA', { ...BOLT, unit_of_measure_code: 'EA' }, false, 'EA', 'each'],
    // items-s3-commit-red.md L-3: a unit the list lacks is spoken as sent at the <dd> too.
    ['read-only, a unit the list lacks', { ...BOLT, unit_of_measure_code: 'XX' }, false, 'XX', 'XX'],
  ] as const)('T-S3-4 (%s)', (_n, i, canEdit, code, word) => {
    show(i, canEdit, true);
    for (const [label, served] of [['Reorder point', '10'], ['Reorder quantity', '1234567890123.123456'], ['Safety stock', '-0.5']] as const) {
      const dd = ddAfter(label);
      expect(shownText(dd)).toBe(`${served} ${code}`);
      expect(dd.querySelector('[aria-hidden="true"]')!.textContent).toBe(code);
      const spoken = dd.querySelector('.visually-hidden')!;
      expect(spoken.textContent).toBe(word);
      expect(spoken.id).toBe('');
      expect(spoken.closest('[aria-hidden="true"]')).toBeNull();
      expect(isInaccessible(spoken)).toBe(false);
      expect(atText(dd)).toBe(`${served} ${word}`);
    }
  });
});

// fe-r6-saving-status-design.md Revision 1 — FINAL commit A (RED H-2; final RED A-1, A-3): the region is cleared at each
// Save press that sends a request, so a second identical "{sku} was saved." is a DOM change and is given again. The
// recorder is `textsOf` (harness.tsx): the initial text, then each change.
describe('/items/[id] — a second identical saved sentence is written again (FE-R6 commit A)', () => {
  const SAVED = 'A-1 was saved.';
  const saveName = async (u: ReturnType<typeof userEvent.setup>, typed: string) => {
    await u.type(field('Name'), typed);
    await u.click(save());
  };

  // Mutations A-M1 (no call in ItemForm), A-M5 (the page passes a no-op) and A-M8 (clear just before the sentence) → red.
  it('two Saves of the same item in a row each say "A-1 was saved.": the region empties at the second press', async () => {
    const one = { ...BOLT, name: 'Boltx' };
    const two = { ...BOLT, name: 'Boltxy' };
    stubFetch([json(200, one), json(200, one), json(200, two), json(200, two)]);
    const u = userEvent.setup();
    show();
    const rec = textsOf(region());
    await saveName(u, 'x');
    await waitFor(() => expect(region().textContent).toBe(SAVED));
    await waitFor(() => expect(field('Name')).toHaveValue('Boltx'));
    await saveName(u, 'y');
    await waitFor(() => expect(rec.texts).toEqual(['', SAVED, '', SAVED]));
    rec.stop();
  });

  // Final RED A-1: red under A-M7 (clear before the emptied-number check) only because the region holds a sentence at
  // the press; on a fresh page an emptied region cleared again is no change.
  it('an emptied number after a Save does not clear the region: nothing is sent, so nothing is cleared', async () => {
    const one = { ...BOLT, name: 'Boltx' };
    const calls = stubFetch([json(200, one), json(200, one)]);
    const u = userEvent.setup();
    show();
    const rec = textsOf(region());
    await saveName(u, 'x');
    await waitFor(() => expect(region().textContent).toBe(SAVED));
    await u.clear(field('Safety stock'));
    await u.click(save());
    await waitFor(() => expect(field('Safety stock')).toHaveAttribute('aria-invalid', 'true'));
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET']);
    expect(rec.texts).toEqual(['', SAVED]);
    rec.stop();
  });

  // Final RED A-1: red under A-M9 (call before the busy/nothing return) only because the region holds a sentence. The
  // aria-disabled Save's click is prevented by Button (blocked), so the form's submit is driven directly: it is the only
  // way that guard is reached.
  it('a submit with nothing changed after a Save does not clear the region', async () => {
    const one = { ...BOLT, name: 'Boltx' };
    const calls = stubFetch([json(200, one), json(200, one)]);
    const u = userEvent.setup();
    show();
    const rec = textsOf(region());
    await saveName(u, 'x');
    await waitFor(() => expect(region().textContent).toBe(SAVED));
    await waitFor(() => expect(save()).toHaveAttribute('aria-disabled', 'true'));
    await u.click(save());
    fireEvent.submit(save().closest('form')!);
    await act(async () => {
      await new Promise((t) => setTimeout(t, 20));
    });
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET']);
    expect(rec.texts).toEqual(['', SAVED]);
    rec.stop();
  });
});

// FE-R6 commit B (fe-r6-saving-status-design.md Revision 1 — FINAL B-1…B-6, as fixed by fe-r6-saving-status-final-red.md
// B-1, B-3, B-5): a Save that has waited 1.5 s says "Saving…" in the page's second, wait-only region; it empties the
// moment the Save answers, whatever the answer. Harness (final RED B-1): the precedents wired-fake-timer tests use,
// `{ shouldAdvanceTime: true }` + `waitFor` (warehouse-delete.test.tsx:799, person-roles.test.tsx:99), are not followed:
// real elapsed time would move the fake clock and race the 1.5 s delay. So: type with user-event under real timers, then
// fake setTimeout/clearTimeout, press with fireEvent inside `act` (change-password.test.tsx:13-19), move the clock only
// inside `act`, release held replies inside `act`, no `waitFor` while faked. Margins, not boundaries: nothing at 1000 ms,
// "Saving…" by 2000 ms (the 1499/1500 ms boundaries are liveregion.test.tsx's).
describe('/items/[id] — "Saving…" while a slow Save waits (FE-R6 commit B)', () => {
  afterEach(() => {
    vi.useRealTimers();
  });
  const SAVING = 'Saving…';
  const SAVED = 'A-1 was saved.';
  const AFTER_DELETE = "A-1 was deleted. It's now in the archived items.";
  const ONE = { ...BOLT, name: 'Boltx' };
  const regions = () => document.querySelectorAll<HTMLElement>('section > [role="status"]');
  /** Final RED B-5: the page's second region, after the page's own (`region()` above still reads the first). */
  const waitRegion = () => regions()[1]!;
  const fake = () => vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
  const press = () =>
    act(async () => {
      fireEvent.click(save());
    });
  const advance = (ms: number) =>
    act(async () => {
      vi.advanceTimersByTime(ms);
    });
  const release = (h: ReturnType<typeof held>, r: Response) =>
    act(async () => {
      h.release(r);
    });
  /** Render, start both recorders, type a Name change under real timers, then fake the clock. */
  const start = async (ui = <ItemPage initial={BOLT} canEdit canRestore={false} />) => {
    renderWired(ui);
    const page = textsOf(region());
    const wait = textsOf(waitRegion());
    await userEvent.setup().type(field('Name'), 'x');
    fake();
    return { page, wait };
  };

  // M1 (delay 0), M2 (no clear when the Save answers), M4 (region only while saving), M5 (written into the page region),
  // M6 (assertive, outside LiveRegion), M7 (shown) → red.
  it('a slow Save: the wait region is there and empty from first render, polite and hidden; "Saving…" by 2 s, not at 1 s; empty once saved; the page region says saved once', async () => {
    const slow = held();
    const calls = stubFetch([slow.reply, json(200, ONE)]);
    const { page, wait } = await start();
    expect(regions()).toHaveLength(2);
    expect(waitRegion()).toBeEmptyDOMElement();
    expect(waitRegion()).toHaveAttribute('role', 'status');
    expect(waitRegion()).toHaveAttribute('aria-live', 'polite');
    expect(waitRegion()).toHaveAttribute('aria-atomic', 'true');
    expect(waitRegion()).toHaveClass('visually-hidden');
    await press();
    const focused = document.activeElement;
    await advance(1000);
    expect(waitRegion().textContent).toBe('');
    await advance(1000);
    expect(waitRegion().textContent).toBe(SAVING);
    expect(region().textContent).toBe('');
    // Focus never moves for "Saving…".
    expect(document.activeElement).toBe(focused);
    await release(slow, json(200, ONE));
    expect(wait.texts).toEqual(['', SAVING, '']);
    expect(page.texts).toEqual(['', SAVED]);
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET']);
    expect(screen.getByRole('heading', { level: 1 })).toHaveFocus();
    expect(document.body.textContent).not.toContain(SAVING);
  });

  it('a fast Save (answered at 300 ms) stays silent in the wait region; the page region says saved', async () => {
    stubFetch([later(300, () => json(200, ONE)), json(200, ONE)]);
    const { page, wait } = await start();
    await press();
    await advance(300);
    expect(page.texts).toEqual(['', SAVED]);
    await advance(2000);
    expect(wait.texts).toEqual(['']);
  });

  // M3 (cleared only on saved) → red.
  it('a slow Save refused (409 transaction.contention): the wait region empties and focus is on the "Not saved" heading', async () => {
    const slow = held();
    stubFetch([slow.reply, json(200, BOLT)]);
    const { page, wait } = await start();
    await press();
    await advance(2000);
    expect(waitRegion().textContent).toBe(SAVING);
    await release(slow, refused('transaction.contention', P1));
    expect(screen.getByText(P1)).toBeInTheDocument();
    expect(screen.getByText('Not saved')).toHaveFocus();
    expect(wait.texts).toEqual(['', SAVING, '']);
    expect(page.texts).toEqual(['']);
  });

  it('a slow Save that never answers ends unreachable at its time limit: the wait region empties under the banner', async () => {
    stubFetch(['hang']);
    const { wait } = await start();
    await press();
    await advance(2000);
    expect(waitRegion().textContent).toBe(SAVING);
    await advance(30_000);
    expect(screen.getByText("We can't tell whether your changes to A-1 were saved. Reload the page to check.")).toBeInTheDocument();
    expect(wait.texts).toEqual(['', SAVING, '']);
  });

  // M10 (the busy report before the emptied-number check) → red.
  it('an emptied number never starts the wait: nothing is sent and the wait region stays empty', async () => {
    const calls = stubFetch([]);
    renderWired(<ItemPage initial={BOLT} canEdit canRestore={false} />);
    const wait = textsOf(waitRegion());
    await userEvent.setup().clear(field('Safety stock'));
    fake();
    await press();
    await advance(2000);
    expect(field('Safety stock')).toHaveAttribute('aria-invalid', 'true');
    expect(calls).toEqual([]);
    expect(wait.texts).toEqual(['']);
  });

  // M8 (timer armed at mount). Deviation D-1 (build note): this one case fakes the clock BEFORE render and types with
  // fireEvent.change, because a clock faked after mount cannot see a timer armed at mount (it would be a real timer).
  it('the delay counts from the press, not from the page opening: open 5 s, press, answered at 300 ms → silent', async () => {
    fake();
    stubFetch([later(300, () => json(200, ONE)), json(200, ONE)]);
    renderWired(<ItemPage initial={BOLT} canEdit canRestore={false} />);
    const page = textsOf(region());
    const wait = textsOf(waitRegion());
    await advance(5000);
    fireEvent.change(field('Name'), { target: { value: 'Boltx' } });
    await press();
    await advance(300);
    expect(page.texts).toEqual(['', SAVED]);
    expect(wait.texts).toEqual(['']);
  });

  // M14 (the busy report skipped once the form has unmounted) → red: the form is gone before the Save's answer is settled.
  it('a slow Save refused archived: the page turns archived, the wait region empties, no node holds "Saving…"', async () => {
    const slow = held();
    stubFetch([slow.reply, json(200, ARCHIVED)]);
    const { wait } = await start(<ItemPage initial={BOLT} canEdit canRestore />);
    await press();
    await advance(2000);
    expect(waitRegion().textContent).toBe(SAVING);
    await release(slow, refused('inventory_item.is_archived', I2));
    expect(screen.getByText(I2)).toBeInTheDocument();
    expect(screen.queryAllByRole('textbox')).toEqual([]);
    expect(wait.texts).toEqual(['', SAVING, '']);
    expect(document.body.textContent).not.toContain(SAVING);
    // Two zero-delay tasks queued by the archived turn (final RED B-1: advanced by 0 ms explicitly); then no timer is left.
    await advance(0);
    expect(vi.getTimerCount()).toBe(0);
  });

  // Final RED B-3: the wait region describes the request, not the form. A Delete answering while a Save is held leaves
  // "Saving…" in the wait region (hidden, not re-spoken) until the Save answers; then it empties. In production the
  // Delete normally answers after the Save (DELETE_LOCK waits on EDIT_LOCK); this is the reverse order. M14 → red.
  it('a Delete 204 while a Save is held past 2 s: the page region holds the delete sentence and the wait region still "Saving…"; the Save answers and it empties', async () => {
    const slow = held();
    stubFetch([slow.reply, empty(204), json(200, ARCHIVED), json(200, ARCHIVED)]);
    const { page, wait } = await start(<ItemPage initial={BOLT} canEdit canRestore canDelete />);
    await press();
    await advance(2000);
    expect(waitRegion().textContent).toBe(SAVING);
    await act(async () => {
      fireEvent.click(screen.getByRole('button', { name: 'Delete item' }));
    });
    await act(async () => {
      fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Delete item' }));
    });
    await advance(0);
    expect(region().textContent).toBe(AFTER_DELETE);
    expect(waitRegion().textContent).toBe(SAVING);
    expect(screen.queryAllByRole('textbox')).toEqual([]);
    await release(slow, json(200, ONE));
    expect(wait.texts).toEqual(['', SAVING, '']);
    expect(page.texts).toEqual(['', AFTER_DELETE]);
  });

  it('two Saves in a row, the second slow: "Saving…" once, in the wait region; the page region as commit A pins it', async () => {
    const TWO = { ...BOLT, name: 'Boltxy' };
    const slow = held();
    stubFetch([json(200, ONE), json(200, ONE), slow.reply, json(200, TWO)]);
    renderWired(<ItemPage initial={BOLT} canEdit canRestore={false} />);
    const page = textsOf(region());
    const wait = textsOf(waitRegion());
    const u = userEvent.setup();
    await u.type(field('Name'), 'x');
    await u.click(save());
    await waitFor(() => expect(region().textContent).toBe(SAVED));
    await waitFor(() => expect(field('Name')).toHaveValue('Boltx'));
    await u.type(field('Name'), 'y');
    fake();
    await press();
    await advance(2000);
    await release(slow, json(200, TWO));
    expect(wait.texts).toEqual(['', SAVING, '']);
    expect(page.texts).toEqual(['', SAVED, '', SAVED]);
  });

  it('under StrictMode: a slow Save says "Saving…" exactly once; still two regions', async () => {
    const slow = held();
    stubFetch([slow.reply, json(200, ONE)]);
    const { page, wait } = await start(
      <StrictMode>
        <ItemPage initial={BOLT} canEdit canRestore={false} />
      </StrictMode>,
    );
    expect(regions()).toHaveLength(2);
    await press();
    await advance(2000);
    await release(slow, json(200, ONE));
    expect(wait.texts).toEqual(['', SAVING, '']);
    expect(page.texts).toEqual(['', SAVED]);
    expect(regions()).toHaveLength(2);
  });

  // fe-r6-commit-b-red.md F-4: the busy report is unconditional, so every answer empties the wait region; the two common
  // answers not covered above are pinned here. `forbidden` and `not-found` swap the whole page out (ItemPage returns
  // ItemsDenied / ItemNotFound), so no region remains to check: no case for them.
  it('a slow Save answered 422 on one field: the wait region empties and focus is on that field', async () => {
    const slow = held();
    stubFetch([slow.reply]);
    const { wait } = await start();
    await press();
    await advance(2000);
    expect(waitRegion().textContent).toBe(SAVING);
    await release(slow, violation('/name', 'Fixture: this name was refused.', 'blank'));
    expect(screen.getByText('Fixture: this name was refused.')).toBeInTheDocument();
    expect(field('Name')).toHaveFocus();
    expect(wait.texts).toEqual(['', SAVING, '']);
  });

  it('a slow Save answered 500: the wait region empties under "Something went wrong on our side."', async () => {
    const slow = held();
    stubFetch([slow.reply]);
    const { wait } = await start();
    await press();
    await advance(2000);
    expect(waitRegion().textContent).toBe(SAVING);
    await release(slow, json(500, {}));
    expect(screen.getByText('Something went wrong on our side.')).toBeInTheDocument();
    expect(wait.texts).toEqual(['', SAVING, '']);
  });

  // fe-r6-commit-b-red.md F-2: one snapshot of both regions per React commit, taken in a Profiler's onRender (never a
  // MutationObserver: inside one commit it sees the page region written before the wait region is emptied, tree order,
  // and would report a state no commit had). No commit may hold the saved sentence while the wait region still says
  // "Saving…". Today `setBusy(false)`, `settle` and `onBusyChange(false)` commit together whatever their order; this case
  // goes red if a later edit flushes the answer path with the busy report still after it.
  it('no committed render holds the saved sentence while the wait region still says "Saving…"', async () => {
    const slow = held();
    stubFetch([slow.reply, json(200, ONE)]);
    const commits: [string, string][] = [];
    const snap = () => {
      const r = regions();
      if (r.length === 2) commits.push([r[0]!.textContent ?? '', r[1]!.textContent ?? '']);
    };
    await start(
      <Profiler id="item-page" onRender={snap}>
        <ItemPage initial={BOLT} canEdit canRestore={false} />
      </Profiler>,
    );
    await press();
    await advance(2000);
    await release(slow, json(200, ONE));
    expect(region().textContent).toBe(SAVED);
    // Anti-vacuity: some commit showed "Saving…".
    expect(commits.some(([, w]) => w === SAVING)).toBe(true);
    expect(commits.filter(([p, w]) => p === SAVED && w === SAVING)).toEqual([]);
  });
});

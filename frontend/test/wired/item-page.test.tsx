import { StrictMode, useState } from 'react';
import { act, render, screen, waitFor, within } from '@testing-library/react';
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
import { SID, TZ, json, problem, refused, renderWired, stubFetch } from './harness';

// Items I-1 (items-plan.md §4 I-1 as amended by R1-1…R1-9 and Revision 1 — FINAL; items-ux-copy.md B-3, B-5 as amended
// by R1-1, R1-2, R1-6, R1-9, R1-17, F-4; Revision 8 R8-2): the item page is the editor. SKU, Name and the three numbers
// are editable for an UpdateItem holder; the Unit and the Preferred supplier are text in every state and never sent. Each
// number starts as its QR-2 rendering and is sent exactly as typed; its unit code follows it. An archived item is
// read-only under its ST-9 banner with Restore item for a RestoreItem holder.

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
const I8 = 'This field must be a decimal string with at most 13 integer digits and 6 fractional digits.';
/** The value each display site must show for each served quantity (M-5): QR-2, never a number. */
const SERVED_AS: [string, string][] = [
  ['1234567890123.123456', '1234567890123.123456'],
  ['-0.500000', '-0.5'],
  ['10.000000', '10'],
  ['0.000000', '0'],
];
const withNumbers = (base: Item, [a, b, c]: string[]): Item => ({ ...base, reorder_point: a!, reorder_quantity: b!, safety_stock: c! });
const ddAfter = (label: string) => screen.getByText(label, { selector: 'dt' }).nextElementSibling as HTMLElement;

describe('/items/[id] — the editor (B-3 as amended)', () => {
  it('h1 "{sku} — {name}"; editable SKU, Name and the three numbers only; Unit and Preferred supplier are text', () => {
    show();
    expect(screen.getByRole('heading', { level: 1, name: 'A-1 — Bolt' })).toBeInTheDocument();
    expect(screen.getAllByRole('textbox').map((i) => i.getAttribute('name'))).toEqual(['sku', 'name', 'reorder_point', 'reorder_quantity', 'safety_stock']);
    expect(ddAfter('Unit of measure')).toHaveTextContent(/^KG$/);
    expect(ddAfter('Preferred supplier')).toHaveTextContent(/^Acme Supplies$/);
    expect(field('SKU')).toHaveAccessibleDescription(expect.stringContaining('A new SKU also changes it on every order'));
    expect(field('Name')).toHaveAccessibleDescription('Renaming also changes the name on every order, receipt, shipment and invoice that already names this item.');
    expect(field('Reorder point')).toHaveAttribute('inputmode', 'decimal');
    expect(field('Reorder point')).toHaveAttribute('type', 'text');
    expect(screen.getByText("Leave both reorder numbers at 0 if this item isn't reordered. Use a point for decimals, for example 2.5, and no commas.")).toBeInTheDocument();
    // No stock, no movements, no links to them (G-47), no History, no Delete item in I-1.
    expect(screen.queryAllByRole('link')).toEqual([]);
    expect(screen.queryByRole('button', { name: /delete/i })).toBeNull();
  });

  it('the item page renders no editable Unit control in any state (R1-2 b): edit, read-only, archived', () => {
    for (const [i, canEdit] of [[BOLT, true], [BOLT, false], [ARCHIVED, true]] as const) {
      const { unmount } = show(i, canEdit, true);
      expect(screen.queryByRole('textbox', { name: /unit/i })).toBeNull();
      expect(screen.queryByRole('combobox', { name: /unit/i })).toBeNull();
      expect(document.querySelector('[name="unit_of_measure_code"]')).toBeNull();
      expect(ddAfter('Unit of measure')).toHaveTextContent(/^KG$/);
      unmount();
    }
  });

  it('no supplier reads "None", as text, never a control (R1-6, S-B′)', () => {
    show({ ...BOLT, supplier: null });
    expect(ddAfter('Preferred supplier')).toHaveTextContent(/^None$/);
    expect(screen.queryByRole('combobox')).toBeNull();
  });

  it('M-5 per display site — edit inputs start at the QR-2 rendering, with the served unit code after each number', () => {
    for (const order of [0, 1, 2, 3]) {
      const four = [0, 1, 2].map((k) => SERVED_AS[(order + k) % 4]!);
      const { unmount } = show(withNumbers(BOLT, four.map(([s]) => s)));
      expect(['Reorder point', 'Reorder quantity', 'Safety stock'].map((n) => (field(n) as HTMLInputElement).value)).toEqual(four.map(([, r]) => r));
      for (const n of ['Reorder point', 'Reorder quantity', 'Safety stock']) expect(field(n)).toHaveAccessibleDescription(expect.stringMatching(/^KG /));
      unmount();
    }
  });

  it('M-5 per display site — read-only page (no UpdateItem): each number is QR-2 then the unit code', () => {
    for (const order of [0, 1, 2, 3]) {
      const four = [0, 1, 2].map((k) => SERVED_AS[(order + k) % 4]!);
      const { unmount } = show(withNumbers(BOLT, four.map(([s]) => s)), false);
      expect(['Reorder point', 'Reorder quantity', 'Safety stock'].map((l) => ddAfter(l).textContent)).toEqual(four.map(([, r]) => `${r} KG`));
      unmount();
    }
  });

  it('M-5 per display site — archived page: each number is QR-2 then the unit code, whatever the viewer holds', () => {
    for (const order of [0, 1, 2, 3]) {
      const four = [0, 1, 2].map((k) => SERVED_AS[(order + k) % 4]!);
      const { unmount } = show(withNumbers(ARCHIVED, four.map(([s]) => s)), true, true);
      expect(['Reorder point', 'Reorder quantity', 'Safety stock'].map((l) => ddAfter(l).textContent)).toEqual(four.map(([, r]) => `${r} KG`));
      unmount();
    }
  });

  it("the suffix is the served unit code, not a constant (R1-3 / F-4): EA on an EA item, KG on a KG item", () => {
    const { unmount } = show({ ...BOLT, unit_of_measure_code: 'EA' }, false);
    expect(ddAfter('Reorder point').textContent).toBe('10 EA');
    unmount();
    show(BOLT, false);
    expect(ddAfter('Reorder point').textContent).toBe('10 KG');
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

  it('Save sends only the changed fields — never supplier_id, never unit_of_measure_code — re-reads, says "{sku} was saved." with the SKU as saved, focuses the h1', async () => {
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

  it('every editable field changed at once: the body holds exactly those five, never supplier_id or unit_of_measure_code', async () => {
    const calls = stubFetch([violation('/sku', 'x', 'blank')]);
    const u = userEvent.setup();
    show();
    for (const [n, v] of [['SKU', 'B-2'], ['Name', 'Nut'], ['Reorder point', '3'], ['Reorder quantity', '4'], ['Safety stock', '5']] as const) {
      await u.clear(field(n));
      await u.type(field(n), v);
    }
    await u.click(save());
    await waitFor(() => expect(calls.length).toBe(1));
    expect(JSON.parse(calls[0]!.body!)).toEqual({ sku: 'B-2', name: 'Nut', reorder_point: '3', reorder_quantity: '4', safety_stock: '5' });
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

import { act, fireEvent, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ push: vi.fn(), refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ push: nav.push, refresh: nav.refresh }) }));
import { takeArrivalMessage } from '@/app/(app)/arrivalMessage';
import { NewItemPage } from '@/app/(app)/items/new/NewItemPage';
import type { Item } from '@/lib/api/items';
import { unitSuffixSpoken } from '@/copy/units';
import { wireEnum } from '@/lib/bff/surface';
import { classify } from '@/lib/bff/problem';
import { itemCreate } from '@/lib/items/wired';
import { json, problem, refused, renderWired, stubFetch, testCaller, type Recorded, type Reply } from './harness';

// Items I-2 (items-i2-design.md Revision 1 — FINAL A-6…A-10, T-4, T-5, as amended by its Final RED FR-M1; items-ux-copy.md
// B-4, R1-1): `/items/new` creates an item. SKU, Name, a Unit select over the SERVED units (no preselection, its prompt
// enabled) and the three numbers prefilled 0. All six are sent exactly as typed, every time, never `supplier_id`, never
// `null`; a fresh Idempotency-Key per submit (the create does not replay on it). Nothing chosen or an emptied number is
// refused here, on every press, with no request. 422 sentences beside their fields; a 409 and every other failure in the
// banner under **Not created**. On 201 the page leaves "{sku} was created." for the new item's page, THEN navigates.

afterEach(() => {
  vi.unstubAllGlobals();
  nav.push.mockReset();
  takeArrivalMessage('');
});

const IID = '8c3d4e5f-6a7b-4c8d-9e0f-1a2b3c4d5e6f';
const UNITS = wireEnum('Item.CreateItemInput', 'unit_of_measure_code');
const UNIT = UNITS[2]!;
const SERVED: Item = { id: IID, sku: 'SKU-1', name: 'Bolt', unit_of_measure_code: UNIT, reorder_point: '1.500000', reorder_quantity: '0.000000', safety_stock: '0.000000', supplier: null, archived_at: null, holds_stock: false };
const UX_I1 = 'Another live item in this workspace already uses this SKU.';
const P1 = 'Some of the same records were being changed at the same moment. Nothing was saved; try again.';
const BLANK = 'This field may not be blank.';
/** items-i4-ux.md Revision 1 — FINAL U4-7 (`NEW_ITEM.unitHint`), verbatim. */
const UNIT_HINT = "Once stock, orders or supplier catalogues record quantities of this item, its unit can't be changed.";
const INTERRUPTED = (sku: string) => `We can't tell whether ${sku} was created. Search the list for ${sku} before you try again.`;

const field = (name: string) => screen.getByRole('textbox', { name });
const unit = () => screen.getByRole('combobox', { name: 'Unit of measure' }) as HTMLSelectElement;
const submit = () => screen.getByRole('button', { name: 'Create item' });
const violation = (pointer: string, code: string, message: string) => problem(422, 'validation-failed', { errors: [{ pointer, code, message }] });
const bodyOf = (c: Recorded) => JSON.parse(c.body!) as Record<string, unknown>;
const posts = (calls: readonly Recorded[]) => calls.filter((c) => c.method === 'POST');

async function fill(u: ReturnType<typeof userEvent.setup>, sku = 'SKU-1', name = 'Bolt', code: string | null = UNIT) {
  if (sku !== '') await u.type(field('SKU'), sku);
  if (name !== '') await u.type(field('Name'), name);
  if (code !== null) await u.selectOptions(unit(), code);
}

describe('/items/new — the form (B-4)', () => {
  it('h1 New item; SKU, Name, Unit of measure, the three numbers in that order; the SKU hint alone; Cancel goes to the list', () => {
    renderWired(<NewItemPage />);
    expect(screen.getByRole('heading', { level: 1, name: 'New item' })).toBeInTheDocument();
    const controls = [...document.querySelectorAll('form input, form select')].map((e) => e.getAttribute('name'));
    expect(controls).toEqual(['sku', 'name', 'unit_of_measure_code', 'reorder_point', 'reorder_quantity', 'safety_stock']);
    expect(field('SKU')).toHaveAccessibleDescription('The code your team uses for this item. Each item needs its own, and capitals and spaces count.');
    expect(field('SKU')).toHaveAttribute('autocomplete', 'off');
    expect(field('SKU')).toHaveAttribute('spellcheck', 'false');
    // I-4 (items-i4-ux.md U4-7 = items-i3-ux.md L-10, moved deliberately from "no description"): the hint, verbatim.
    expect(unit()).toHaveAccessibleDescription(UNIT_HINT);
    expect(field('Reorder point')).toHaveAccessibleDescription(expect.stringContaining('due to be reordered'));
    expect(screen.getByText("Leave both reorder numbers at 0 if this item isn't reordered. Use a point for decimals, for example 2.5, and no commas.")).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Cancel' })).toHaveAttribute('href', '/items');
  });

  it('the numbers start at 0, as text with a decimal keypad', () => {
    renderWired(<NewItemPage />);
    for (const n of ['Reorder point', 'Reorder quantity', 'Safety stock']) {
      expect(field(n)).toHaveValue('0');
      expect(field(n)).toHaveAttribute('type', 'text');
      expect(field(n)).toHaveAttribute('inputmode', 'decimal');
    }
  });

  it('no unit suffix on the create numbers, also after each unit is chosen', async () => {
    // S-3 (s3-fer8-red.md L-3, as fixed by items-s3-commit-red.md L-1): a description starting with the code, or with
    // the spoken suffix followed by a space or the end. Built once, so the guard and its control are one copy.
    const startsWithUnit = (code: string) => new RegExp(`^(?:${code}\\b|${unitSuffixSpoken(code).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(?:\\s|$))`);
    // Positive control: the guard bites on both forms.
    expect(`${unitSuffixSpoken('KG')} Enter`).toMatch(startsWithUnit('KG'));
    expect('KG Enter').toMatch(startsWithUnit('KG'));
    const u = userEvent.setup();
    renderWired(<NewItemPage />);
    for (const code of UNITS) {
      await u.selectOptions(unit(), code);
      expect(document.querySelectorAll('[id$="-suffix"]')).toHaveLength(0);
      for (const n of ['Reorder point', 'Reorder quantity', 'Safety stock']) expect(field(n)).not.toHaveAccessibleDescription(expect.stringMatching(startsWithUnit(code)));
    }
  });

  it('the Unit starts on "Choose a unit" (value ""), that option has no disabled attribute; the options are the served codes in served order, with UX labels', () => {
    renderWired(<NewItemPage />);
    expect(unit().value).toBe('');
    const options = within(unit()).getAllByRole('option') as HTMLOptionElement[];
    expect(options[0]!.value).toBe('');
    expect(options[0]!.textContent).toBe('Choose a unit');
    expect(options[0]!).not.toHaveAttribute('disabled');
    expect(options.slice(1).map((o) => o.value)).toEqual(UNITS);
    expect(options.slice(1).map((o) => o.textContent)).toEqual(['Each (EA)', 'Box (BOX)', 'Kilogram (KG)', 'Litre (L)', 'Metre (M)']);
    expect(document.querySelectorAll('[disabled]')).toHaveLength(0);
  });
});

describe('/items/new — what is sent (B-4, A-6 step 3, A-9)', () => {
  it('201: POSTs exactly the six keys, as typed (" SKU-1 ", "1,5", "-3" unchanged), never supplier_id or null, with an Idempotency-Key not in the body', async () => {
    const calls = stubFetch([json(201, SERVED)]);
    const u = userEvent.setup();
    renderWired(<NewItemPage />);
    await fill(u, ' SKU-1 ');
    await u.clear(field('Reorder point'));
    await u.type(field('Reorder point'), '1,5');
    await u.clear(field('Reorder quantity'));
    await u.type(field('Reorder quantity'), '-3');
    await u.click(submit());
    await waitFor(() => expect(nav.push).toHaveBeenCalledTimes(1));
    expect(calls).toHaveLength(1);
    expect(calls[0]!.method).toBe('POST');
    expect(calls[0]!.url).toMatch(/\/bff\/api\/items$/);
    const body = bodyOf(calls[0]!);
    expect(body).toEqual({ sku: ' SKU-1 ', name: 'Bolt', unit_of_measure_code: UNIT, reorder_point: '1,5', reorder_quantity: '-3', safety_stock: '0' });
    expect(Object.keys(body).sort()).toEqual(['name', 'reorder_point', 'reorder_quantity', 'safety_stock', 'sku', 'unit_of_measure_code']);
    expect(Object.values(body).every((v) => typeof v === 'string')).toBe(true);
    const key = calls[0]!.headers.get('idempotency-key');
    expect(key).toMatch(/^[0-9a-f-]{36}$/);
    expect(calls[0]!.body).not.toContain(key!);
  });

  it('a quantity left at 0 is still sent (all three numbers, every time)', async () => {
    const calls = stubFetch([json(201, SERVED)]);
    const u = userEvent.setup();
    renderWired(<NewItemPage />);
    await fill(u);
    await u.click(submit());
    await waitFor(() => expect(nav.push).toHaveBeenCalled());
    expect(bodyOf(calls[0]!)).toEqual({ sku: 'SKU-1', name: 'Bolt', unit_of_measure_code: UNIT, reorder_point: '0', reorder_quantity: '0', safety_stock: '0' });
  });

  it('201: the message is left BEFORE push, for /items/{body.id}, with the served sku (read at push time: FR-M1)', async () => {
    stubFetch([json(201, SERVED)]);
    let seen: string | null | undefined;
    nav.push.mockImplementation((href: string) => {
      seen = takeArrivalMessage(href);
    });
    const u = userEvent.setup();
    renderWired(<NewItemPage />);
    await fill(u, ' SKU-1 ');
    await u.click(submit());
    await waitFor(() => expect(nav.push).toHaveBeenCalledWith(`/items/${IID}`));
    expect(nav.push).toHaveBeenCalledTimes(1);
    expect(seen).toBe('SKU-1 was created.');
  });

  it('itemCreate mints a fresh key from newKey on EVERY call, and never puts it in the body', async () => {
    const calls = stubFetch([refused('transaction.contention', P1), json(201, SERVED)]);
    let n = 0;
    const create = itemCreate(testCaller(), () => `key-${++n}`);
    const body = { sku: 'A', name: 'B', unit_of_measure_code: UNIT, reorder_point: '0', reorder_quantity: '0', safety_stock: '0' };
    expect(await create(body)).toEqual({ kind: 'refused', rule: 'transaction.contention', message: P1 });
    expect(await create(body)).toEqual({ kind: 'created', item: SERVED });
    expect(calls.map((c) => c.headers.get('idempotency-key'))).toEqual(['key-1', 'key-2']);
    for (const c of calls) expect(c.body).not.toMatch(/key-\d/);
  });

  it('a 201 whose body is not an item → "Something went wrong on our side." + interrupted, and no navigation', async () => {
    stubFetch([json(201, { id: IID, sku: 'SKU-1' })]);
    const u = userEvent.setup();
    renderWired(<NewItemPage />);
    await fill(u);
    await u.click(submit());
    expect(await screen.findByText(INTERRUPTED('SKU-1'))).toBeInTheDocument();
    expect(screen.getByText('Something went wrong on our side.')).toHaveFocus();
    expect(nav.push).not.toHaveBeenCalled();
  });

  it('an in-flight submit is sent once: a second press and an Enter while busy send nothing', async () => {
    const calls = stubFetch(['hang']);
    const u = userEvent.setup();
    renderWired(<NewItemPage />);
    await fill(u);
    await u.click(submit());
    await waitFor(() => expect(submit()).toHaveAttribute('aria-busy', 'true'));
    await u.click(submit());
    await u.type(field('Name'), '{Enter}');
    expect(posts(calls)).toHaveLength(1);
  });

  it('the form itself refuses a second submit while busy, whatever triggers it (a submit event, not the button)', async () => {
    const calls = stubFetch(['hang']);
    const u = userEvent.setup();
    renderWired(<NewItemPage />);
    await fill(u);
    await u.click(submit());
    await waitFor(() => expect(submit()).toHaveAttribute('aria-busy', 'true'));
    fireEvent.submit(submit().closest('form')!);
    await new Promise((r) => setTimeout(r, 10));
    expect(posts(calls)).toHaveLength(1);
  });
});

describe('/items/new — refused here, before any request (R1-1, R1-9)', () => {
  it('no unit chosen: "Choose a unit for this item." beside Unit, no request on two consecutive presses, focus on the select once the error is shown', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    renderWired(<NewItemPage />);
    await fill(u, 'SKU-1', 'Bolt', null);
    for (let i = 0; i < 2; i += 1) {
      await u.click(field('SKU'));
      await u.click(submit());
      await waitFor(() => expect(unit()).toHaveAttribute('aria-invalid', 'true'));
      // I-4: describedby is the hint, then the error.
      expect(unit()).toHaveAccessibleDescription(`${UNIT_HINT} Choose a unit for this item.`);
      expect(unit()).toHaveFocus();
    }
    expect(calls).toHaveLength(0);
  });

  it('an emptied number: "Enter a number, or 0 for none." beside it, no request on two consecutive presses, focus on it', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    renderWired(<NewItemPage />);
    await fill(u);
    await u.clear(field('Reorder quantity'));
    for (let i = 0; i < 2; i += 1) {
      await u.click(field('SKU'));
      await u.click(submit());
      await waitFor(() => expect(field('Reorder quantity')).toHaveAttribute('aria-invalid', 'true'));
      expect(field('Reorder quantity')).toHaveAccessibleDescription(expect.stringContaining('Enter a number, or 0 for none.'));
      expect(field('Reorder quantity')).toHaveFocus();
    }
    expect(calls).toHaveLength(0);
  });

  it('both at once: both sentences show, and focus goes to Unit (the first in screen order)', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    renderWired(<NewItemPage />);
    await fill(u, 'SKU-1', 'Bolt', null);
    await u.clear(field('Safety stock'));
    await u.click(submit());
    await waitFor(() => expect(unit()).toHaveAttribute('aria-invalid', 'true'));
    expect(field('Safety stock')).toHaveAttribute('aria-invalid', 'true');
    expect(unit()).toHaveFocus();
    expect(calls).toHaveLength(0);
  });

  it("a server 422 blank on SKU shows beside SKU with focus there; then a unit-less press shows ONLY the Unit sentence (replace, not merge) and sends nothing", async () => {
    const calls = stubFetch([violation('/sku', 'blank', BLANK)]);
    const u = userEvent.setup();
    renderWired(<NewItemPage />);
    await fill(u, '', 'Bolt');
    await u.click(submit());
    await waitFor(() => expect(field('SKU')).toHaveAttribute('aria-invalid', 'true'));
    expect(field('SKU')).toHaveAccessibleDescription(expect.stringContaining(BLANK));
    expect(field('SKU')).toHaveFocus();
    expect(screen.queryByText('Not created')).toBeNull();
    await u.selectOptions(unit(), '');
    await u.click(submit());
    await waitFor(() => expect(unit()).toHaveAttribute('aria-invalid', 'true'));
    expect(field('SKU')).not.toHaveAttribute('aria-invalid');
    expect(screen.queryByText(BLANK)).toBeNull();
    expect(calls).toHaveLength(1);
  });

  it('a client refusal after a banner clears the banner (A-6 step 2), sends nothing, and focuses Unit', async () => {
    const calls = stubFetch([refused('inventory_item.sku_is_unique_per_workspace', UX_I1)]);
    const u = userEvent.setup();
    renderWired(<NewItemPage />);
    await fill(u);
    await u.click(submit());
    expect(await screen.findByText(UX_I1)).toBeInTheDocument();
    await u.selectOptions(unit(), '');
    await u.click(submit());
    await waitFor(() => expect(unit()).toHaveAttribute('aria-invalid', 'true'));
    expect(screen.queryByText('Not created')).toBeNull();
    expect(screen.queryByText(UX_I1)).toBeNull();
    expect(unit()).toHaveFocus();
    expect(posts(calls)).toHaveLength(1);
  });
});

describe('/items/new — server answers (A-7)', () => {
  it('a violation at /supplier_id (outside the six fields) goes to the banner under "Not created"', async () => {
    stubFetch([violation('/supplier_id', 'unknown_reference', 'No supplier with this id.')]);
    const u = userEvent.setup();
    renderWired(<NewItemPage />);
    await fill(u);
    await u.click(submit());
    expect(await screen.findByText('No supplier with this id.')).toBeInTheDocument();
    expect(screen.getByText('Not created')).toHaveFocus();
  });

  it('a 422 mixing /supplier_id and /name: the Name sentence beside Name, the other under "Not created", focus on the banner heading (A-6)', async () => {
    stubFetch([problem(422, 'validation-failed', { errors: [{ pointer: '/supplier_id', code: 'unknown_reference', message: 'No supplier with this id.' }, { pointer: '/name', code: 'blank', message: BLANK }] })]);
    const u = userEvent.setup();
    renderWired(<NewItemPage />);
    await fill(u);
    await u.click(submit());
    expect(await screen.findByText('No supplier with this id.')).toBeInTheDocument();
    expect(field('Name')).toHaveAttribute('aria-invalid', 'true');
    expect(field('Name')).toHaveAccessibleDescription(BLANK);
    expect(screen.getByText('Not created')).toHaveFocus();
  });

  it('two out-of-form violations with the same sentence: both shown, no duplicate-key warning', async () => {
    const errors = vi.spyOn(console, 'error').mockImplementation(() => {});
    stubFetch([problem(422, 'validation-failed', { errors: [{ pointer: '/supplier_id', code: 'x', message: 'Same.' }, { pointer: '', code: 'y', message: 'Same.' }] })]);
    const u = userEvent.setup();
    renderWired(<NewItemPage />);
    await fill(u);
    await u.click(submit());
    expect(await screen.findAllByText('Same.')).toHaveLength(2);
    expect(errors.mock.calls.filter((c) => String(c[0]).includes('same key'))).toEqual([]);
    errors.mockRestore();
  });

  it('the interrupted sentence carries the SKU exactly as sent (untrimmed)', async () => {
    stubFetch([json(503, {})]);
    const u = userEvent.setup();
    renderWired(<NewItemPage />);
    await fill(u, ' SKU-1 ');
    await u.click(submit());
    const p = await screen.findByText((_, el) => el?.tagName === 'P' && (el.textContent ?? '').startsWith("We can't tell whether"));
    expect(p.textContent).toBe("We can't tell whether  SKU-1  was created. Search the list for  SKU-1  before you try again.");
  });

  it('a 409 UX-I1 shows verbatim under "Not created"; a later 422 clears the banner; a 409 after a 422 clears the field sentences', async () => {
    stubFetch([refused('inventory_item.sku_is_unique_per_workspace', UX_I1), violation('/name', 'blank', BLANK), refused('inventory_item.sku_is_unique_per_workspace', UX_I1)]);
    const u = userEvent.setup();
    renderWired(<NewItemPage />);
    await fill(u);
    await u.click(submit());
    expect(await screen.findByText(UX_I1)).toBeInTheDocument();
    expect(screen.getByText('Not created')).toHaveFocus();
    await u.click(submit());
    await waitFor(() => expect(field('Name')).toHaveAttribute('aria-invalid', 'true'));
    expect(screen.queryByText('Not created')).toBeNull();
    await u.click(submit());
    expect(await screen.findByText(UX_I1)).toBeInTheDocument();
    expect(field('Name')).not.toHaveAttribute('aria-invalid');
    expect(screen.queryByText(BLANK)).toBeNull();
  });

  it('P1 contention: the sentence under "Not created"; Create item can still be pressed, and the resubmit carries a new key', async () => {
    const calls = stubFetch([refused('transaction.contention', P1), json(201, SERVED)]);
    const u = userEvent.setup();
    renderWired(<NewItemPage />);
    await fill(u);
    await u.click(submit());
    expect(await screen.findByText(P1)).toBeInTheDocument();
    expect(screen.getByText('Not created')).toHaveFocus();
    expect(submit()).not.toHaveAttribute('aria-disabled');
    await u.click(submit());
    await waitFor(() => expect(nav.push).toHaveBeenCalledWith(`/items/${IID}`));
    const keys = calls.map((c) => c.headers.get('idempotency-key'));
    expect(keys).toHaveLength(2);
    expect(keys[0]).not.toBeNull();
    expect(keys[0]).not.toBe(keys[1]);
  });

  it('a 409 with an empty message: "Not created" + the no-message sentence', async () => {
    stubFetch([problem(409, 'idempotency-key-conflict')]);
    const u = userEvent.setup();
    renderWired(<NewItemPage />);
    await fill(u);
    await u.click(submit());
    expect(await screen.findByText("This change wasn't made. Reload the page to see where things stand, then try again.")).toBeInTheDocument();
  });

  it('a 403 names the Create items permission (B-9)', async () => {
    stubFetch([problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    renderWired(<NewItemPage />);
    await fill(u);
    await u.click(submit());
    expect(await screen.findByText(/which you no longer have/)).toHaveTextContent('This needs the Create items permission, which you no longer have. Your workspace administrator can grant it.');
    expect(screen.getByText('Not created')).toHaveFocus();
  });

  it('a 404: "Not created" + the inline not-found sentence', async () => {
    stubFetch([problem(404, 'not-found')]);
    const u = userEvent.setup();
    renderWired(<NewItemPage />);
    await fill(u);
    await u.click(submit());
    expect(await screen.findByText("We couldn't find that. It may have been deleted, or you may not have access.")).toBeInTheDocument();
  });

  it('a lost connection: "We couldn\'t reach the server." + the interrupted sentence; a 5xx: "Something went wrong on our side." + the same', async () => {
    let n = 0;
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => {
        n += 1;
        if (n === 1) throw new TypeError('offline');
        return json(503, {});
      }),
    );
    const u = userEvent.setup();
    renderWired(<NewItemPage />);
    await fill(u);
    await u.click(submit());
    expect(await screen.findByText(INTERRUPTED('SKU-1'))).toBeInTheDocument();
    expect(screen.getByText("We couldn't reach the server.")).toHaveFocus();
    await u.click(submit());
    await waitFor(() => expect(screen.getByText('Something went wrong on our side.')).toHaveFocus());
    expect(screen.getByText(INTERRUPTED('SKU-1'))).toBeInTheDocument();
  });

  it('a lost answer, then a resubmit: UX-I1 verbatim (the create committed and does not replay)', async () => {
    let n = 0;
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => {
        n += 1;
        if (n === 1) throw new TypeError('offline');
        return refused('inventory_item.sku_is_unique_per_workspace', UX_I1);
      }),
    );
    const u = userEvent.setup();
    renderWired(<NewItemPage />);
    await fill(u);
    await u.click(submit());
    expect(await screen.findByText(INTERRUPTED('SKU-1'))).toBeInTheDocument();
    await u.click(submit());
    expect(await screen.findByText(UX_I1)).toBeInTheDocument();
    expect(screen.getByText('Not created')).toHaveFocus();
    expect(nav.push).not.toHaveBeenCalled();
  });

  it('401 (session ended): nothing rendered in the form (the caller navigates to sign-in)', async () => {
    // The provider's navigation is a full document load, which jsdom reports on stderr as "Not implemented: navigation".
    stubFetch([new Response(JSON.stringify({ type: 'urn:erp:bff:session-ended', title: 'x', status: 401 }), { status: 401, headers: { 'content-type': 'application/problem+json' } })]);
    const u = userEvent.setup();
    renderWired(<NewItemPage />);
    await fill(u);
    await u.click(submit());
    await waitFor(() => expect(document.querySelector('[aria-busy="true"]')).toBeNull());
    expect(screen.queryByText('Not created')).toBeNull();
    expect(screen.queryByText(/We can't tell whether/)).toBeNull();
    expect(document.querySelectorAll('[aria-invalid="true"]')).toHaveLength(0);
    expect(nav.push).not.toHaveBeenCalled();
  });
});

describe('/items/new — an unexpected 429 (T-5, M-1)', () => {
  it('the fixture classifies as rate-limited, and the screen takes the shared path: "Something went wrong on our side." + interrupted', async () => {
    expect(await classify(problem(429, 'too-many-attempts'))).toEqual({ kind: 'rate-limited' });
    stubFetch([problem(429, 'too-many-attempts')]);
    const u = userEvent.setup();
    renderWired(<NewItemPage />);
    await fill(u);
    await u.click(submit());
    expect(await screen.findByText(INTERRUPTED('SKU-1'))).toBeInTheDocument();
    expect(screen.getByText('Something went wrong on our side.')).toHaveFocus();
  });
});

// I-2 follow-up (items-i3-ux.md Revision 1 FINAL F-5 #1): a 422 with an out-of-form sentence AND a field sentence ends
// its banner with this line; no other banner carries it.
const MIXED_LINE = 'Check the marked fields below too.';
const bannerBody = (heading: HTMLElement) => [...heading.parentElement!.children].filter((e) => e !== heading).map((e) => e.textContent);

describe('/items/new (ItemCreateForm) — the mixed-422 line (I-2 follow-up)', () => {
  it('a 422 with an out-of-form sentence and a field sentence: the banner ends with "Check the marked fields below too."', async () => {
    stubFetch([problem(422, 'validation-failed', { errors: [{ pointer: '/supplier_id', code: 'x', message: 'Out of form.' }, { pointer: '/name', code: 'blank', message: 'This field may not be blank.' }] })]);
    const u = userEvent.setup();
    renderWired(<NewItemPage />);
    await fill(u);
    await u.click(submit());
    const heading = await screen.findByText('Not created');
    expect(bannerBody(heading)).toEqual(['Out of form.', MIXED_LINE]);
  });

  it('a field sentence that is an empty string marks no field, so the banner carries no such line', async () => {
    stubFetch([problem(422, 'validation-failed', { errors: [{ pointer: '/supplier_id', code: 'x', message: 'Out of form.' }, { pointer: '/name', code: 'blank', message: '' }] })]);
    const u = userEvent.setup();
    renderWired(<NewItemPage />);
    await fill(u);
    await u.click(submit());
    const heading = await screen.findByText('Not created');
    expect(bannerBody(heading)).toEqual(['Out of form.']);
  });

  it('a banner-only 422 and a 409 carry no such line', async () => {
    stubFetch([problem(422, 'validation-failed', { errors: [{ pointer: '/supplier_id', code: 'x', message: 'Out of form.' }, { pointer: '', code: 'y', message: 'Also out of form.' }] }), refused('some.rule', 'Refused here.')]);
    const u = userEvent.setup();
    renderWired(<NewItemPage />);
    await fill(u);
    await u.click(submit());
    const heading = await screen.findByText('Not created');
    expect(bannerBody(heading)).toEqual(['Out of form.', 'Also out of form.']);
    expect(screen.queryByText(MIXED_LINE)).toBeNull();
    await u.click(submit());
    expect(await screen.findByText('Refused here.')).toBeInTheDocument();
    expect(screen.queryByText(MIXED_LINE)).toBeNull();
  });
});

describe('/items/new — the interrupted sentence when the SKU is empty (I-2 follow-up)', () => {
  const NO_SKU = "We can't tell whether the new item was created. Check the list of items before you try again.";
  it('a 5xx with SKU "   " (empty after trimming): the no-SKU sentence, and the request still sends the SKU as typed', async () => {
    const calls = stubFetch([json(503, {})]);
    const u = userEvent.setup();
    renderWired(<NewItemPage />);
    await fill(u, '   ');
    await u.click(submit());
    expect(await screen.findByText(NO_SKU)).toBeInTheDocument();
    expect(screen.queryByText(/We can't tell whether\s+was created/)).toBeNull();
    expect(bodyOf(posts(calls)[0]!).sku).toBe('   ');
  });

  it('a lost connection with no SKU typed at all: the no-SKU sentence', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => { throw new TypeError('offline'); }));
    const u = userEvent.setup();
    renderWired(<NewItemPage />);
    await fill(u, '');
    await u.click(submit());
    expect(await screen.findByText(NO_SKU)).toBeInTheDocument();
    expect(screen.getByText("We couldn't reach the server.")).toHaveFocus();
  });

  it('a 5xx with SKU " A1 ": B-4\'s sentence with " A1 " exactly as sent', async () => {
    stubFetch([json(503, {})]);
    const u = userEvent.setup();
    renderWired(<NewItemPage />);
    await fill(u, ' A1 ');
    await u.click(submit());
    const p = await screen.findByText((_, el) => el?.tagName === 'P' && (el.textContent ?? '').startsWith("We can't tell whether"));
    expect(p.textContent).toBe(INTERRUPTED(' A1 '));
    expect(screen.queryByText(NO_SKU)).toBeNull();
  });
});

// Items I-3 T-4 (ruling 1(a), I3-9): a CreateItem 201 without holds_stock is malformed: server-error, no navigation.
describe('/items/new — a 201 without holds_stock (I-3 T-4)', () => {
  it('"Something went wrong on our side." + the interrupted sentence; no navigation, no arrival message', async () => {
    const body = { ...SERVED } as Record<string, unknown>;
    delete body.holds_stock;
    stubFetch([json(201, body)]);
    const u = userEvent.setup();
    renderWired(<NewItemPage />);
    await fill(u);
    await u.click(submit());
    expect(await screen.findByText(INTERRUPTED('SKU-1'))).toBeInTheDocument();
    expect(screen.getByText('Something went wrong on our side.')).toBeInTheDocument();
    expect(nav.push).not.toHaveBeenCalled();
    expect(takeArrivalMessage(`/items/${IID}`)).toBeNull();
  });
});

// suppliers-s2-red.md L-1 (family-wide): `created` calls the page's `onCreated`, which navigates with `router.push`, and the
// form is still on screen until the new page mounts. Here `nav.push` is a mock, so the page never leaves: the window is held
// open. In it the form stays busy, so a press, an Enter or a submit event sends nothing: no second key is spent and no false
// "Not created" appears (the code-taken 409 queued behind the 201 would say it). Every answer other than `created` gives the
// form back, so a form that never clears busy fails here too.
describe('/items/new — after a 201, until the new page takes over (L-1)', () => {
  // A function: a Response body can be read once, so each test needs its own.
  afterEach(() => {
    vi.useRealTimers();
  });
  const TAKEN = () => refused('inventory_item.sku_is_unique_per_workspace', UX_I1);
  it('Create item stays busy: a second press and an Enter send nothing, and nothing says "Not created", while Cancel still goes to the list', async () => {
    // A real client navigation takes hundreds of ms to seconds: let a minute pass, so a form that hands Create back after any
    // delay is caught (create-repress-red.md L-1).
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const calls = stubFetch([json(201, SERVED), TAKEN()]);
    const u = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderWired(<NewItemPage />);
    await fill(u);
    await u.click(submit());
    await waitFor(() => expect(nav.push).toHaveBeenCalledWith(`/items/${IID}`));
    act(() => {
      vi.advanceTimersByTime(60_000);
    });
    expect(submit()).toHaveAttribute('aria-busy', 'true');
    await u.click(submit());
    await u.type(field('Name'), '{Enter}');
    await new Promise((r) => setTimeout(r, 10));
    expect(posts(calls)).toHaveLength(1);
    expect(screen.queryByText('Not created')).toBeNull();
    expect(nav.push).toHaveBeenCalledTimes(1);
    // D-1 rests on this: while the form is held busy the person is not trapped, Cancel still goes to the list. There is no app
    // router here, so a working link is one whose press nothing cancels (the browser follows its href); the window listener runs
    // last, records that, then stops the document load jsdom cannot do.
    const cancel = screen.getByRole('link', { name: 'Cancel' });
    expect(cancel).toHaveAttribute('href', '/items');
    expect(cancel).not.toHaveAttribute('aria-disabled');
    expect(cancel.closest('fieldset:disabled, [inert]')).toBeNull();
    let followed: boolean | null = null;
    const record = (e: Event) => {
      followed = !e.defaultPrevented;
      e.preventDefault();
    };
    window.addEventListener('click', record);
    try {
      await u.click(cancel);
    } finally {
      window.removeEventListener('click', record);
    }
    expect(followed).toBe(true);
    expect(submit()).toHaveAttribute('aria-busy', 'true');
  });

  it('the form itself refuses a submit event after a 201, not only the button', async () => {
    const calls = stubFetch([json(201, SERVED), TAKEN()]);
    const u = userEvent.setup();
    renderWired(<NewItemPage />);
    await fill(u);
    await u.click(submit());
    await waitFor(() => expect(nav.push).toHaveBeenCalledWith(`/items/${IID}`));
    fireEvent.submit(submit().closest('form')!);
    await new Promise((r) => setTimeout(r, 10));
    expect(posts(calls)).toHaveLength(1);
    expect(screen.queryByText('Not created')).toBeNull();
  });

  // Every answer the form can show other than `created`, each with the visible sign to wait for. A fresh Response per row (a
  // body is read once). `session-ended` is not here: it renders nothing and the page goes to sign-in, so no Create is left.
  const OTHER_ANSWERS: [string, () => Reply, string][] = [
    ['409 refused', TAKEN, 'Not created'],
    ['422 field-only', () => problem(422, 'validation-failed', { errors: [{ pointer: '/name', code: 'blank', message: BLANK }] }), BLANK],
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
  it.each(OTHER_ANSWERS)('any other answer gives the form back (%s): Create item is not busy and a second press sends', async (_name, reply, sign) => {
    const calls = stubFetch([reply(), json(201, SERVED)]);
    const u = userEvent.setup();
    renderWired(<NewItemPage />);
    await fill(u);
    await u.click(submit());
    expect(await screen.findByText(sign)).toBeInTheDocument();
    expect(submit()).not.toHaveAttribute('aria-busy');
    await u.click(submit());
    await waitFor(() => expect(nav.push).toHaveBeenCalledWith(`/items/${IID}`));
    expect(posts(calls)).toHaveLength(2);
  });
});

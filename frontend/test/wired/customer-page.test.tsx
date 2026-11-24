import { screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: vi.fn(), refresh: vi.fn() }), usePathname: () => '/customers/x' }));
import { CustomerPage } from '@/app/(app)/customers/[id]/CustomerPage';
import * as copy from '@/copy/customers';
import type { Customer } from '@/lib/api/customers';
import { formatDate } from '@/lib/format/dates';
import { TZ, renderWired } from './harness';

// Customers C-1 (customers-plan.md Revision 0 §8 C-1 tests 5, 6, 9 under Revision 1 — FINAL R1-6; R1-5's C-1 row): the
// customer page is read-only for everyone — Code, Name, Country, Currency and Payment terms as text, the archived banner
// with no action — and no customer-level email, phone, credit limit or active state appears anywhere (G-36, plan §4).

const LIVE: Customer = { id: 'd1b2c3d4-0000-4000-8000-000000000001', code: 'NORTH', name: 'Northwind Retail', country_code: 'GB', currency_code: 'GBP', payment_terms_days: 30, archived_at: null };
const fields = () => [...document.querySelectorAll('dl > dt')].map((dt) => [dt.textContent, dt.nextElementSibling?.textContent]);

describe('/customers/[id] — the read-only page (C-1)', () => {
  it('heading "{code} — {name}"; the five fields as text; no form, no input, no button', () => {
    renderWired(<CustomerPage customer={LIVE} />);
    expect(screen.getByRole('heading', { level: 1, name: 'NORTH — Northwind Retail' })).toBeInTheDocument();
    expect(fields()).toEqual([
      ['Code', 'NORTH'],
      ['Name', 'Northwind Retail'],
      ['Country', 'GB'],
      ['Currency', 'GBP'],
      ['Payment terms (days)', '30 days'],
    ]);
    expect(document.querySelector('form, input, select, textarea')).toBeNull();
    expect(screen.queryAllByRole('button')).toEqual([]);
  });

  it.each([
    [1, '1 day'],
    [0, '0 days'],
    [365, '365 days'],
  ])('payment terms %i read "%s"', (n, text) => {
    renderWired(<CustomerPage customer={{ ...LIVE, payment_terms_days: n }} />);
    expect(fields().at(-1)).toEqual(['Payment terms (days)', text]);
  });

  it('an archived customer: the ST-9 banner with its date (DT-1, the viewer zone) and no Restore, no other action', () => {
    const archived = { ...LIVE, archived_at: '2026-03-04T11:00:00Z' };
    renderWired(<CustomerPage customer={archived} />);
    const banner = screen.getByText(/This customer was archived on/);
    expect(banner.textContent).toBe(`This customer was archived on ${formatDate(archived.archived_at, TZ)}.`);
    expect(screen.queryAllByRole('button')).toEqual([]);
    expect(screen.queryByText(/restore/i)).toBeNull();
  });

  it('a live customer has no archived banner', () => {
    renderWired(<CustomerPage customer={LIVE} />);
    expect(screen.queryByText(/was archived on/)).toBeNull();
  });
});

// Test 9 (G-36; customers-plan.md §4, `customers-c1-ux-copy.md` §2 copy-key note): the four customer columns nothing
// writes are never shown or offered. The page shows only served fields (the parity test holds `Customer` to the spec). The
// only email and phone words are the CONTACT's, inside `CONTACTS_SECTION` and under its Contacts heading; no other group
// in the family's copy — and no top-level key — names an email or a phone, and nothing anywhere names a credit limit or an
// active state.
//
// Customers C-4a0 (customers-plan-c4c5.md Revision 1 R1-1 W-1, as fixed by customers-plan-c4a-final-red.md M-1 and L-2;
// ARCH customers-c4c5-arch.md §3.2): the copy check is a recursive walk over every export of `copy/customers.ts`. It replaces
// the two per-group loops the shipped test held, which read keys only at depth 2, read functions only at a group's top
// level, missed a top-level string export, and missed `isActive` in a nested key. The walk keeps the key path and:
// - every key at any depth must not match /active|credit/i (no word boundary);
// - every string value, and `String(fn)` of every function at any depth, must not match /active|credit/i (no word boundary);
// - /e-?mail|phone/i, in a key or a value, is allowed only when `path[0] === 'CONTACTS_SECTION'` (an exact compare of the
//   export name: `ADD_CONTACT.CONTACTS_SECTION.email` and an export `CONTACTS_SECTION_OLD` are both red);
// - a function is read as `String(fn)` and never called or descended;
// - any value that is not a string, number, boolean, null, function, plain object or array fails the test with its path, so
//   a Map, a class instance or a RegExp can never be skipped silently; inside a group, a symbol key or a non-enumerable
//   property fails too (the module's own top level is read by `Object.entries`, since a namespace object carries
//   `Symbol.toStringTag`).
// Anti-vacuity: the walk returns the leaf paths it visited. Over the real module the test asserts that a function leaf
// (`CUSTOMERS_PAGING.pager`), a string leaf (`CONTACTS_SECTION.email`) and a top-level function (`addressKindLabel`) are among
// them, and that the number of leaves equals a count taken by an independent recursive counter (`countLeaves`, not the
// walk), so a nested object of several leaves is legal and the flat shape is not forced (ARCH §3.2). On today's copy every
// leaf sits at depth 2 or less, so recursion below depth 2 is proven by the planted controls (1, 4 and 6 nest deeper), which
// run the same walk over a planted module.
// Stated limits:
// - a non-contact group's function whose source names `CONTACTS_SECTION.fieldEmail` (or any email/phone word) is red by
//   design: a function is read by its source text;
// - a module-private declaration that no export exposes (a `const`, `let`, `var`, `function` or `class`, or an `import` of
//   another copy module) is invisible to the walk, because a function that reads it shows only its name. The review check
//   is `grep -nE "^(const|let|var|function|class|import) " src/copy/customers.ts`, each name also appearing as a member
//   value. Today: `days` only, no import; `days` is exposed as `CUSTOMERS_LIST.days` (:37) and `CUSTOMER_PAGE.days` (:106).
//   C-4a adds `CONTACT_PRIMARY_NOW`, `CONTACT_PRIMARY_NO_LONGER` and `LIST_STALE`, each exposed by a `…Clause` member
//   (copy R1-1);
// - a word split across literals (`'in' + 'active'`) is not seen: the walk reads source text and values, not what they
//   would join into.
const KEY_FORBIDDEN = /active|credit/i;
const VALUE_FORBIDDEN = /active|credit/i;
const CONTACT_WORDS = /e-?mail|phone/i;
type CopyPath = readonly (string | number)[];
const shown = (p: CopyPath) => p.map((k) => (typeof k === 'number' ? `[${k}]` : k)).join('.');
const isPlainObject = (v: object) => {
  const proto = Object.getPrototypeOf(v);
  return proto === Object.prototype || proto === null;
};
/** An independent leaf count for the anti-vacuity check: every string, number, boolean, null or function is one leaf. */
const countLeaves = (v: unknown): number => (Array.isArray(v) || (typeof v === 'object' && v !== null && isPlainObject(v)) ? Object.values(v).reduce<number>((n, m) => n + countLeaves(m), 0) : 1);
/** The G-36 walk over a copy module: every breach (path + reason) and every leaf path it visited. */
function g36Walk(module: Record<string, unknown>): { breaches: string[]; leaves: CopyPath[] } {
  const breaches: string[] = [];
  const leaves: CopyPath[] = [];
  const text = (t: string, path: CopyPath, what: string) => {
    if (VALUE_FORBIDDEN.test(t)) breaches.push(`${shown(path)}: ${what} names a credit limit or an active state`);
    if (CONTACT_WORDS.test(t) && path[0] !== 'CONTACTS_SECTION') breaches.push(`${shown(path)}: ${what} names an email or a phone outside CONTACTS_SECTION`);
  };
  const visit = (v: unknown, path: CopyPath) => {
    const key = path[path.length - 1];
    if (typeof key === 'string') {
      if (KEY_FORBIDDEN.test(key)) breaches.push(`${shown(path)}: the key names a credit limit or an active state`);
      if (CONTACT_WORDS.test(key) && path[0] !== 'CONTACTS_SECTION') breaches.push(`${shown(path)}: the key names an email or a phone outside CONTACTS_SECTION`);
    }
    if (typeof v === 'string') {
      leaves.push(path);
      text(v, path, 'the value');
    } else if (typeof v === 'function') {
      leaves.push(path);
      text(String(v), path, 'the function source');
    } else if (typeof v === 'number' || typeof v === 'boolean' || v === null) {
      leaves.push(path);
    } else if (Array.isArray(v)) {
      v.forEach((m, i) => visit(m, [...path, i]));
    } else if (typeof v === 'object' && isPlainObject(v)) {
      for (const k of Reflect.ownKeys(v)) {
        if (typeof k === 'symbol') breaches.push(`${shown(path)}: a symbol key the walk does not read`);
        else if (!Object.getOwnPropertyDescriptor(v, k)?.enumerable) breaches.push(`${shown([...path, k])}: a hidden property the walk does not read`);
        else visit((v as Record<string, unknown>)[k], [...path, k]);
      }
    } else {
      breaches.push(`${shown(path)}: a value the walk does not handle (${Object.prototype.toString.call(v)})`);
    }
  };
  for (const [name, v] of Object.entries(module)) visit(v, [name]);
  return { breaches, leaves };
}

describe('G-36 — no customer-level email, phone, credit limit or active state (test 9)', () => {
  it('copy/customers.ts: email and phone only inside CONTACTS_SECTION; credit limit and active nowhere', () => {
    // Key names are checked on their own, with no word boundaries (customers-c1-commit-red.md M-3): `is_active` or
    // `isActive` is a key for an active state whatever its value says.
    expect(Object.keys(copy).filter((k) => /active|credit|e-?mail|phone/i.test(k))).toEqual([]);
    const real = g36Walk(copy);
    expect(real.breaches, 'the G-36 walk over copy/customers.ts').toEqual([]);
    const visited = real.leaves.map(shown);
    expect(visited).toEqual(expect.arrayContaining(['CUSTOMERS_PAGING.pager', 'CONTACTS_SECTION.email', 'addressKindLabel']));
    const members = Object.values(copy).reduce<number>((n, v) => n + countLeaves(v), 0);
    expect(real.leaves.length, 'the walk visits every leaf').toBe(members);
    expect(Object.keys(copy.CONTACTS_SECTION)).toEqual(expect.arrayContaining(['email', 'phone']));
    expect(String(copy.addressKindLabel)).not.toMatch(/e-?mail|phone|credit|\bactive\b|inactive/i);
  });

  it('the walk: nine planted controls go red for their own reason; a nested email under CONTACTS_SECTION stays green', () => {
    const planted = (extra: Record<string, unknown>) => g36Walk({ ...copy, ...extra });
    const cases: [string, Record<string, unknown>, string[]][] = [
      ['1 fieldEmail nested under ADD_CONTACT.fields', { ADD_CONTACT: { fields: { fieldEmail: 'Email' } } }, ['ADD_CONTACT.fields.fieldEmail: the key names an email or a phone outside CONTACTS_SECTION', 'ADD_CONTACT.fields.fieldEmail: the value names an email or a phone outside CONTACTS_SECTION']],
      ['2 pager returns { isActive }', { CUSTOMERS_PAGING: { ...copy.CUSTOMERS_PAGING, pager: () => ({ isActive: 'x' }) } }, ['CUSTOMERS_PAGING.pager: the function source names a credit limit or an active state']],
      ["3 export const X = 'inactive'", { X: 'inactive' }, ['X: the value names a credit limit or an active state']],
      ["4 an array member ['credit'] in a group", { CUSTOMER_PAGE: { ...copy.CUSTOMER_PAGE, options: ['credit'] } }, ['CUSTOMER_PAGE.options.[0]: the value names a credit limit or an active state']],
      ['5 fieldEmailInactive in CONTACTS_SECTION (active is never exempt)', { CONTACTS_SECTION: { ...copy.CONTACTS_SECTION, fieldEmailInactive: 'x' } }, ['CONTACTS_SECTION.fieldEmailInactive: the key names a credit limit or an active state']],
      ["6 a function returning 'phone' at ADDRESSES_SECTION.x.y", { ADDRESSES_SECTION: { ...copy.ADDRESSES_SECTION, x: { y: () => 'phone' } } }, ['ADDRESSES_SECTION.x.y: the function source names an email or a phone outside CONTACTS_SECTION']],
      ['7 ADD_CONTACT.CONTACTS_SECTION.email', { ADD_CONTACT: { CONTACTS_SECTION: { email: 'Email' } } }, ['ADD_CONTACT.CONTACTS_SECTION.email: the key names an email or a phone outside CONTACTS_SECTION', 'ADD_CONTACT.CONTACTS_SECTION.email: the value names an email or a phone outside CONTACTS_SECTION']],
      ['8 export CONTACTS_SECTION_OLD = { phone }', { CONTACTS_SECTION_OLD: { phone: 'Phone' } }, ['CONTACTS_SECTION_OLD.phone: the key names an email or a phone outside CONTACTS_SECTION', 'CONTACTS_SECTION_OLD.phone: the value names an email or a phone outside CONTACTS_SECTION']],
      ["9 export CONTACTS_SECTION_OLD = { label: 'Phone' } (the value branch's exact compare)", { CONTACTS_SECTION_OLD: { label: 'Phone' } }, ['CONTACTS_SECTION_OLD.label: the value names an email or a phone outside CONTACTS_SECTION']],
    ];
    for (const [name, extra, expected] of cases) expect(planted(extra).breaches, name).toEqual(expected);
    // Fail closed on a value the walk does not handle.
    expect(planted({ CUSTOMER_PAGE: { ...copy.CUSTOMER_PAGE, pattern: /x/ } }).breaches).toEqual(['CUSTOMER_PAGE.pattern: a value the walk does not handle ([object RegExp])']);
    expect(planted({ X: new Map() }).breaches).toEqual(['X: a value the walk does not handle ([object Map])']);
    expect(planted({ X: undefined }).breaches).toEqual(['X: a value the walk does not handle ([object Undefined])']);
    expect(planted({ CONTACTS_SECTION: { ...copy.CONTACTS_SECTION, [Symbol('x')]: 'Credit limit' } }).breaches).toEqual(['CONTACTS_SECTION: a symbol key the walk does not read']);
    expect(planted({ CONTACTS_SECTION: Object.defineProperty({ ...copy.CONTACTS_SECTION }, 'creditLimit', { value: 'Credit limit', enumerable: false }) }).breaches).toEqual(['CONTACTS_SECTION.creditLimit: a hidden property the walk does not read']);
    // Green control: the exemption covers the whole CONTACTS_SECTION subtree, so the walk does not force the flat shape.
    expect(planted({ CONTACTS_SECTION: { ...copy.CONTACTS_SECTION, fields: { email: 'Email' } } }).breaches).toEqual([]);
  });

  it('the page renders none of them, live or archived; Email and Phone appear only inside the Contacts section', () => {
    const contact = { id: 'c1000000-0000-4000-8000-000000000001', name: 'Ann Lee', email: 'ann@example.com', phone: '+44 20 7946 0000', is_primary: true };
    for (const c of [LIVE, { ...LIVE, archived_at: '2026-03-04T11:00:00Z' }]) {
      const { unmount } = renderWired(<CustomerPage customer={c} contacts={{ kind: 'ok', contacts: [contact] }} addresses={{ kind: 'ok', addresses: [] }} />);
      expect(document.body.textContent).not.toMatch(/credit|\bactive\b|inactive/i);
      const contacts = screen.getByRole('region', { name: 'Contacts' });
      const outside = [...document.body.querySelectorAll('section > *')].filter((n) => !n.contains(contacts) && !contacts.contains(n)).map((n) => n.textContent).join(' ');
      expect(outside).not.toMatch(/e-?mail|phone/i);
      expect(contacts.textContent).toMatch(/Email/);
      unmount();
    }
  });
});

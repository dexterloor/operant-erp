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
describe('G-36 — no customer-level email, phone, credit limit or active state (test 9)', () => {
  const flat = (group: object) => JSON.stringify(Object.entries(group).map(([k, v]) => [k, typeof v === 'function' ? String(v) : v]));
  const groups = Object.entries(copy).filter(([, g]) => typeof g === 'object') as [string, object][];
  it('copy/customers.ts: email and phone only inside CONTACTS_SECTION; credit limit and active nowhere', () => {
    // Key names are checked on their own, with no word boundaries (customers-c1-commit-red.md M-3): `is_active` or
    // `isActive` is a key for an active state whatever its value says.
    expect(Object.keys(copy).filter((k) => /active|credit|e-?mail|phone/i.test(k))).toEqual([]);
    for (const [name, group] of groups) {
      expect(Object.keys(group).filter((k) => /active|credit|e-?mail|phone/i.test(k) && !(name === 'CONTACTS_SECTION' && /e-?mail|phone/i.test(k))), name).toEqual([]);
    }
    for (const [name, group] of groups) {
      expect(flat(group), name).not.toMatch(/credit|\bactive\b|inactive/i);
      if (name !== 'CONTACTS_SECTION') expect(flat(group), name).not.toMatch(/e-?mail|phone/i);
    }
    expect(Object.keys(copy.CONTACTS_SECTION)).toEqual(expect.arrayContaining(['email', 'phone']));
    expect(String(copy.addressKindLabel)).not.toMatch(/e-?mail|phone|credit|\bactive\b|inactive/i);
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

import { isValidElement, type ReactElement } from 'react';
import { describe, expect, it, vi } from 'vitest';

// Customers C-1 (customers-plan.md Revision 0 §8 C-1 test 6 under Revision 1 — FINAL R1-6; the supplier-gate.test.ts
// shape), as moved deliberately by C-2a: the customer page takes the customer, `canEdit` (UpdateCustomer's codes — the
// editor, C-2a) and its two sections' first reads and nothing else, so no Restore, Delete, Add, Edit or Remove can be
// offered — and its gate is GetCustomer's. Each section is read on the server only for a holder of its read's codes (A-20). The list page: its gate, the address's view
// (`?archived=true` kept on reload, never a cursor or a search), and 401 / 403 / 404 / a non-record id.

// Fixed loader answers holding distinct rows (customers-c1-commit-red.md M-2): the page must hand each section EXACTLY its
// loader's answer — same object, same rows, same order — so a page that reorders or cuts a section's rows goes red.
const fixed = vi.hoisted(() => ({
  contacts: { kind: 'ok', contacts: [{ id: 'c1000000-0000-4000-8000-000000000003', name: 'Contact 3', email: null, phone: null, is_primary: true }, { id: 'c1000000-0000-4000-8000-000000000001', name: 'Contact 1', email: 'c1@example.com', phone: null, is_primary: false }] },
  addresses: { kind: 'ok', addresses: [{ id: 'a1000000-0000-4000-8000-000000000002', kind: 'shipping', line: '2 New St', city: 'Leeds', postal_code: 'LS1', country_code: 'GB' }, { id: 'a1000000-0000-4000-8000-000000000001', kind: 'billing', line: '1 Old St', city: 'York', postal_code: 'YO1', country_code: 'GB' }] },
}));
const v = vi.hoisted(() => ({ codes: [] as string[], listCalls: [] as unknown[][], childCalls: [] as unknown[][], customer: 'ok' as 'ok' | 'not-found' | 'session-ended' | 'forbidden', list: 'ok' as 'ok' | 'session-ended' | 'forbidden', contacts: 'ok' as 'ok' | 'session-ended' | 'server-error', addresses: 'ok' as 'ok' | 'session-ended' | 'server-error' }));
vi.mock('@/app/(app)/currentViewer', async (actual) => ({
  ...(await actual<typeof import('@/app/(app)/currentViewer')>()),
  currentViewer: async () => ({ session: { sid: 's' }, viewer: { id: 'v', permissionCodes: v.codes } }),
}));
vi.mock('next/navigation', () => ({
  redirect: (to: string) => {
    throw new Error(`redirect ${to}`);
  },
  notFound: () => {
    throw new Error('not found');
  },
}));
vi.mock('@/lib/bff/server/reads', () => ({ serverRead: () => 'read' }));
// A-20's per-section gate cannot be told apart by real code sets: ListCustomerContacts and ListCustomerAddresses share
// GetCustomer's code (customer.view), so every viewer of the page holds both. To prove the page reads a section only for a
// holder of ITS read's codes, one test withholds a named read's gate here; every other check is the real one.
const gate = vi.hoisted(() => ({ withheld: [] as string[] }));
vi.mock('@/lib/bff/surface', async (actual) => {
  const real = await actual<typeof import('@/lib/bff/surface')>();
  return { ...real, viewerMay: (op: string, codes: readonly string[]) => !gate.withheld.includes(op) && real.viewerMay(op, codes) };
});
const CUSTOMER = { id: '6a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4e', code: 'NORTH', name: 'Northwind Retail', country_code: 'GB', currency_code: 'GBP', payment_terms_days: 30, archived_at: null };
vi.mock('@/lib/customers/load', () => ({
  loadCustomer: async () => (v.customer === 'ok' ? { kind: 'ok', customer: CUSTOMER } : { kind: v.customer }),
  loadContacts: async (...args: unknown[]) => {
    v.childCalls.push(['contacts', ...args]);
    return v.contacts === 'ok' ? fixed.contacts : { kind: v.contacts };
  },
  loadAddresses: async (...args: unknown[]) => {
    v.childCalls.push(['addresses', ...args]);
    return v.addresses === 'ok' ? fixed.addresses : { kind: v.addresses };
  },
  loadCustomers: async (...args: unknown[]) => {
    v.listCalls.push(args);
    return v.list === 'ok' ? { kind: 'ok', items: [], nextCursor: null, previousCursor: null, tenantHasRows: false } : { kind: v.list };
  },
}));

const { default: Page, generateMetadata } = await import('@/app/(app)/customers/[id]/page');
const { default: ListPage, metadata } = await import('@/app/(app)/customers/page');
const { CustomersDenied } = await import('@/app/(app)/customers/CustomerStates');
const { CustomersList } = await import('@/app/(app)/customers/CustomersList');
const { CustomerPage } = await import('@/app/(app)/customers/[id]/CustomerPage');

async function render(codes: string[]): Promise<ReactElement> {
  v.codes = codes;
  v.customer = 'ok';
  v.contacts = 'ok';
  v.addresses = 'ok';
  v.childCalls = [];
  const el = await Page({ params: Promise.resolve({ id: CUSTOMER.id }) });
  expect(isValidElement(el)).toBe(true);
  return el as ReactElement;
}

describe('/customers/[id] — gated on GetCustomer; the editor only for UpdateCustomer (test 6, C-2a)', () => {
  it.each([
    ['the viewer shape (customer.view): read-only', ['customer.view'], false],
    ['the sales_rep shape (every customer code): the editor', ['customer.view', 'customer.create', 'customer.edit', 'customer.delete'], true],
    // Commit RED L-4: two seats that tell UpdateCustomer apart from DeleteCustomer and CreateCustomer.
    ['customer.view + customer.edit only: the editor', ['customer.view', 'customer.edit'], true],
    ['customer.view + create + delete, no edit: read-only', ['customer.view', 'customer.create', 'customer.delete'], false],
  ] as const)('%s, handed the customer, canEdit and its two sections\' first reads and nothing else', async (_, codes, canEdit) => {
    const el = await render([...codes]);
    expect(el.type).toBe(CustomerPage);
    // Customers C-4a (customers-plan-c4c5.md Revision 1 R1-0 F-8): `canAddContact` and `canEditContact` join, in the fixed order;
    // C-4b inserts `canRemoveContact` after them; C-5a inserts `canAddAddress` and `canEditAddress` after it.
    expect(Object.keys(el.props as object)).toEqual(['customer', 'canEdit', 'canAddContact', 'canEditContact', 'canRemoveContact', 'canAddAddress', 'canEditAddress', 'contacts', 'addresses']);
    const props = el.props as Record<string, unknown>;
    expect(props.canEdit).toBe(canEdit);
    // Every contact write needs customer.edit today, as UpdateCustomer does.
    expect(props.canAddContact).toBe(canEdit);
    expect(props.canEditContact).toBe(canEdit);
    expect(props.canRemoveContact).toBe(canEdit);
    // Every address write needs customer.edit today too.
    expect(props.canAddAddress).toBe(canEdit);
    expect(props.canEditAddress).toBe(canEdit);
    expect(props.customer).toEqual(CUSTOMER);
    expect(props.contacts).toBe(fixed.contacts);
    expect(props.addresses).toBe(fixed.addresses);
    expect(fixed.contacts.contacts.map((c) => c.name)).toEqual(['Contact 3', 'Contact 1']);
    expect(fixed.addresses.addresses.map((a) => a.line)).toEqual(['2 New St', '1 Old St']);
    // Each section's read is sent on the server with the customer id and nothing else.
    expect(v.childCalls).toEqual([
      ['contacts', 'read', CUSTOMER.id],
      ['addresses', 'read', CUSTOMER.id],
    ]);
  });

  // Customers C-4a test 20a (customers-plan-c4c5.md Revision 1 R1-2): each contact prop is read from ITS operation's codes.
  // Every contact write and UpdateCustomer share customer.edit, so only a withheld gate tells them apart (the A-20 shape).
  it.each([
    ['CreateCustomerContact', 'canAddContact'],
    ['UpdateCustomerContact', 'canEditContact'],
    // Customers C-4b test 20b: Remove from DeleteCustomerContact's own gate.
    ['DeleteCustomerContact', 'canRemoveContact'],
    // Customers C-5a A19: Add and Edit address from their own gates.
    ['CreateCustomerAddress', 'canAddAddress'],
    ['UpdateCustomerAddress', 'canEditAddress'],
  ] as const)('test 20a — withholding %s turns off %s alone (each from its own gate, never UpdateCustomer\'s)', async (op, prop) => {
    gate.withheld = [op];
    try {
      const el = await render(['customer.view', 'customer.edit']);
      const props = el.props as Record<string, unknown>;
      expect(props.canEdit).toBe(true);
      for (const p of ['canAddContact', 'canEditContact', 'canRemoveContact', 'canAddAddress', 'canEditAddress']) expect(props[p], p).toBe(p !== prop);
    } finally {
      gate.withheld = [];
    }
  });

  it('a 401 on either section read → the session-ended notice for this address; any other failure is the section\'s own', async () => {
    v.codes = ['customer.view'];
    v.customer = 'ok';
    for (const which of ['contacts', 'addresses'] as const) {
      v.contacts = 'ok';
      v.addresses = 'ok';
      v[which] = 'session-ended';
      await expect(Page({ params: Promise.resolve({ id: CUSTOMER.id }) })).rejects.toThrow(encodeURIComponent(`/customers/${CUSTOMER.id}`));
      v[which] = 'server-error';
      const el = (await Page({ params: Promise.resolve({ id: CUSTOMER.id }) })) as ReactElement;
      expect(el.type).toBe(CustomerPage);
      expect((el.props as Record<string, unknown>)[which]).toEqual({ kind: 'server-error' });
    }
  });

  it.each([
    ['ListCustomerContacts', 'contacts', 'addresses'],
    ['ListCustomerAddresses', 'addresses', 'contacts'],
  ] as const)('a viewer not satisfying %s\'s gate: that section is absent (null) and not read; the other is (A-20)', async (op, absent, present) => {
    gate.withheld = [op];
    try {
      const el = await render(['customer.view']);
      expect(el.type).toBe(CustomerPage);
      const props = el.props as Record<string, unknown>;
      expect(props[absent]).toBeNull();
      expect(props[present]).not.toBeNull();
      expect(v.childCalls.map((c) => c[0])).toEqual([present]);
    } finally {
      gate.withheld = [];
    }
  });

  it('without customer.view the page is ST-4b, even with every write code, and neither section is read', async () => {
    expect((await render(['customer.edit', 'customer.delete', 'customer.create'])).type).toBe(CustomersDenied);
    expect(v.childCalls).toEqual([]);
  });

  it('401 → the session-ended notice for this address; 403 → ST-4b; 404 → ST-5; a non-record id → ST-5', async () => {
    v.codes = ['customer.view'];
    v.customer = 'session-ended';
    await expect(Page({ params: Promise.resolve({ id: CUSTOMER.id }) })).rejects.toThrow(encodeURIComponent(`/customers/${CUSTOMER.id}`));
    v.customer = 'forbidden';
    expect(((await Page({ params: Promise.resolve({ id: CUSTOMER.id }) })) as ReactElement).type).toBe(CustomersDenied);
    v.customer = 'not-found';
    await expect(Page({ params: Promise.resolve({ id: CUSTOMER.id }) })).rejects.toThrow('not found');
    v.customer = 'ok';
    await expect(Page({ params: Promise.resolve({ id: 'not-a-uuid' }) })).rejects.toThrow('not found');
  });

  it('the page title is "{code} — {name}"; without the read it is the list heading', async () => {
    v.codes = ['customer.view'];
    v.customer = 'ok';
    expect((await generateMetadata({ params: Promise.resolve({ id: CUSTOMER.id }) })).title).toBe('NORTH — Northwind Retail');
    v.codes = [];
    expect((await generateMetadata({ params: Promise.resolve({ id: CUSTOMER.id }) })).title).toBe('Customers');
  });
});

describe('/customers — the list page', () => {
  const at = async (codes: string[], searchParams: Record<string, string>) => {
    v.codes = codes;
    v.list = 'ok';
    v.listCalls = [];
    return (await ListPage({ searchParams: Promise.resolve(searchParams) })) as ReactElement;
  };

  it('the title is "Customers"', () => {
    expect(metadata.title).toBe('Customers');
  });

  it('without customer.view it is ST-4b and reads nothing', async () => {
    expect((await at(['customer.edit', 'customer.create'], {})).type).toBe(CustomersDenied);
    expect(v.listCalls).toEqual([]);
  });

  it('?archived=true is kept on reload: the first read is the Archived view, page 1, no search, no cursor', async () => {
    const el = await at(['customer.view'], { archived: 'true', q: 'A', after: 'x' });
    expect(el.type).toBe(CustomersList);
    expect(el.props).toEqual(expect.objectContaining({ initialArchived: true }));
    expect(v.listCalls).toEqual([['read', true, { q: '', cursor: null }]]);
  });

  it('anything but ?archived=true is the live view, page 1; the list is handed no create offer, even to a creator', async () => {
    const el = await at(['customer.view', 'customer.create'], { archived: 'yes', q: 'A', before: 'y' });
    expect(Object.keys(el.props as object).sort()).toEqual(['initial', 'initialArchived']);
    expect((el.props as { initialArchived: boolean }).initialArchived).toBe(false);
    expect(v.listCalls).toEqual([['read', false, { q: '', cursor: null }]]);
  });

  it('401 → the session-ended notice for /customers (the view kept); 403 → ST-4b', async () => {
    v.codes = ['customer.view'];
    v.list = 'session-ended';
    await expect(ListPage({ searchParams: Promise.resolve({ archived: 'true' }) })).rejects.toThrow(encodeURIComponent('/customers?archived=true'));
    v.list = 'forbidden';
    expect(((await ListPage({ searchParams: Promise.resolve({}) })) as ReactElement).type).toBe(CustomersDenied);
  });
});

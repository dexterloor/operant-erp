import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import { NAVIGATION, activeHref, deriveNavigation } from '@/lib/navigation';
import { BUILT_PAGE_HREFS } from '@/lib/routes';
import { ROLES } from '@/components/preview/fixtures/roles';

const all = NAVIGATION.flatMap((g) => g.items.map((i) => i.permission));
const labels = (codes: Iterable<string>) => deriveNavigation([...codes]).map((g) => [g.label, g.items.map((i) => i.label)]);

describe('sidebar derivation from permission codes (L-5)', () => {
  it('all codes → five groups in IA order, sub-items in IA order', () => {
    expect(labels(all)).toEqual([
      [null, ['Overview']],
      ['Purchasing', ['Purchase orders', 'Goods receipts', 'Suppliers']],
      ['Inventory', ['Items', 'Stock on hand', 'Stock movements', 'Warehouses']],
      ['Sales', ['Sales orders', 'Fulfilments', 'Invoices', 'Customers']],
      ['Administration', ['Users', 'Roles', 'Audit log', 'Settings']],
    ]);
  });

  it('a set lacking a code has that item ABSENT, not greyed', () => {
    const codes = all.filter((c) => c !== 'stock_movement.view');
    const inventory = deriveNavigation(codes).find((g) => g.label === 'Inventory');
    expect(inventory?.items.map((i) => i.label)).toEqual(['Items', 'Stock on hand', 'Warehouses']);
  });

  it('a group with no visible item is absent entirely', () => {
    const groups = deriveNavigation(['forecast.view', 'invoice.view']);
    expect(groups.map((g) => g.label)).toEqual([null, 'Sales']);
    expect(groups[1]?.items.map((i) => i.label)).toEqual(['Invoices']);
  });

  it('only the declared codes count: role names or unknown codes render nothing', () => {
    expect(deriveNavigation(['owner', 'admin', 'purchase_order.create'])).toEqual([]);
    expect(deriveNavigation([])).toEqual([]);
  });

  it('b3b-followup C-3: with a built list, only built items remain and emptied groups are absent', () => {
    expect(deriveNavigation(all, NAVIGATION, ['/admin/users']).map((g) => [g.label, g.items.map((i) => i.label)])).toEqual([['Administration', ['Users']]]);
    expect(deriveNavigation(all, NAVIGATION, [])).toEqual([]);
  });

  it('accepts a Set as well as an array', () => {
    expect(deriveNavigation(new Set(['warehouse.view'])).map((g) => g.label)).toEqual(['Inventory']);
  });

  it('every item maps to a distinct .view code and an IA route', () => {
    expect(new Set(all).size).toBe(all.length);
    for (const g of NAVIGATION) for (const i of g.items) {
      expect(i.permission).toMatch(/^[a-z_]+\.view$/);
      expect(i.href.startsWith('/')).toBe(true);
    }
  });

  it('active item follows the deepest matching route', () => {
    expect(activeHref('/purchase-orders/42/receive')).toBe('/purchase-orders');
    expect(activeHref('/stock')).toBe('/stock');
    expect(activeHref('/stock-movements/9')).toBe('/stock-movements');
    expect(activeHref('/account/password')).toBeNull();
  });

  // Cross-check against the canonical contract when the gitignored docs are present (main checkout); the worktree
  // has no docs/, so this is skipped there and says so.
  const apiMd = path.resolve(__dirname, '../../docs/API.md');
  const mainApiMd = path.resolve(__dirname, '../../../01-erp-saas-ai-powered/docs/API.md');
  const doc = existsSync(apiMd) ? apiMd : existsSync(mainApiMd) ? mainApiMd : null;
  it.skipIf(!doc)('each item\'s operation exists in API.md §4.2 with exactly that permission code', () => {
    const text = readFileSync(doc as string, 'utf8');
    for (const g of NAVIGATION)
      for (const i of g.items) {
        const row = text.split('\n').find((l) => new RegExp(`^\\| *\`${i.operation}\` *\\|`).test(l));
        expect(row, i.operation).toBeDefined();
        const cells = (row as string).split('|').map((c) => c.trim());
        expect(cells[5], i.operation).toBe(`\`${i.permission}\``);
      }
  });
});

// Customers C-1 tests 12 and 13 (customers-plan.md Revision 1 — FINAL R1-4, R1-6, owner notice N-9; RED L-3;
// customers-c1-commit-red.md L-1): over the EIGHT built-in roles' real code sets (the preview fixture `ROLES`, `is_system`),
// with /customers built the Sales group appears for the first time, holding exactly Customers, for owner, admin, sales_rep
// and viewer; the other four (buyer, purchasing_manager, warehouse_operator, finance — no customer.view) get no Sales group.
describe('customers C-1 — the Sales group appears, holding only Customers, for the default customer.view holders', () => {
  const built = (codes: string[]) => deriveNavigation(codes, NAVIGATION, BUILT_PAGE_HREFS).map((g) => [g.label, g.items.map((i) => i.href)]);
  const SALES_HOLDERS = ['admin', 'owner', 'sales_rep', 'viewer'];
  const DEFAULT_ROLES = ROLES.filter((r) => r.is_system);
  it('there are eight built-in roles, four of them customer.view holders', () => {
    expect(DEFAULT_ROLES.map((r) => r.name).sort()).toEqual(['admin', 'buyer', 'finance', 'owner', 'purchasing_manager', 'sales_rep', 'viewer', 'warehouse_operator']);
    expect(DEFAULT_ROLES.filter((r) => r.permission_codes.includes('customer.view')).map((r) => r.name).sort()).toEqual(SALES_HOLDERS);
  });
  it.each(DEFAULT_ROLES.map((r) => [r.name, r.permission_codes] as const))('%s', (name, codes) => {
    const sales = built([...codes]).find(([label]) => label === 'Sales');
    if (SALES_HOLDERS.includes(name)) expect(sales).toEqual(['Sales', ['/customers']]);
    else expect(sales).toBeUndefined();
  });
});

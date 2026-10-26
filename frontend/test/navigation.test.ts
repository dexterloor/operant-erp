import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import { NAVIGATION, activeHref, deriveNavigation } from '@/lib/navigation';

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

// @vitest-environment node
import { describe, expect, it } from 'vitest';
import { orderKinds } from '@/lib/customers/addressKinds';

// Customers UX-1 B-2: the Kind select's order is Shipping, then Billing, then any other served kind, each once; a known
// kind appears only if served.
describe('orderKinds', () => {
  it('puts Shipping before Billing', () => {
    expect(orderKinds(['billing', 'shipping'])).toEqual(['shipping', 'billing']);
  });
  it('offers only the known kinds that are served', () => {
    expect(orderKinds(['billing'])).toEqual(['billing']);
  });
  it('puts any other served kind after the known ones, once', () => {
    expect(orderKinds(['dock', 'shipping', 'dock'])).toEqual(['shipping', 'dock']);
  });
  it('offers nothing when nothing is served', () => {
    expect(orderKinds([])).toEqual([]);
  });
});

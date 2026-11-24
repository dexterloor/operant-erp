/**
 * The Kind select's order on the customer page (Customers UX-1 B-2; `customers-c4c5-ux-copy.md` Revision 1b row 61):
 * Shipping, then Billing, then any other served kind in served order, each once.
 *
 * Re-ranked only because the same screen lists addresses grouped in this order (`IA.md:358`); other selects keep served
 * order (R8-5, `KindField`). Known kinds appear only if served.
 */
export function orderKinds(served: readonly string[]): string[] {
  return [
    ...['shipping', 'billing'].filter((k) => served.includes(k)),
    ...served.filter((k, i, all) => k !== 'shipping' && k !== 'billing' && all.indexOf(k) === i),
  ];
}

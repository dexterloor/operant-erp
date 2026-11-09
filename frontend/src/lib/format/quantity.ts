// The web app's one rendering of a system-held quantity (STATES.md QR-1/QR-2; b4-architecture.md Revision 8 R8-2;
// items-ux-copy.md R1-18): the second implementation QR-1 allows, which must agree with the backend's
// `QuantityRendering::render()` — on QR-2's table, verbatim, by test (`test/format/quantity.test.ts`), and on its domain
// and its signed-zero rule (items-i1-red.md L-4): the same pattern `QuantityRendering::QUANTITY`
// (`^(-?)(\d{1,13})(?:\.(\d{1,6}))?$`), a string outside it returned as served, and `0`, never `-0`.
//
// It works on the decimal STRING and never converts it to a number: a quantity holds up to 13 integer and 6 fractional
// digits, which a double cannot carry (`1234567890123.123456` would come back altered). Trailing zeros of the fraction are
// trimmed and a bare point is dropped (QR-2); zero is written `0`, never blank (QR-4); nothing is rounded, padded or
// grouped (QR-2, QR-7). This renders, it never validates.

const QUANTITY = /^(-?)(\d{1,13})(?:\.(\d{1,6}))?$/;

export function renderQuantity(served: string): string {
  const parts = QUANTITY.exec(served);
  if (parts === null) return served;
  let sign = parts[1] ?? '';
  const integer = parts[2] ?? '';
  let fraction = parts[3] ?? '';
  while (fraction.endsWith('0')) fraction = fraction.slice(0, -1);
  // A zero is `0`, never `-0` (the backend's rule: `numeric` has no signed zero).
  if (fraction === '' && /^0+$/.test(integer)) sign = '';
  return sign + integer + (fraction === '' ? '' : `.${fraction}`);
}

// The web app's one rendering of a system-held quantity (STATES.md QR-1/QR-2; b4-architecture.md Revision 8 R8-2;
// items-ux-copy.md R1-18): the second implementation QR-1 allows, which must agree with the backend's
// `QuantityRendering::render()` on QR-2's table, verbatim, by test (`test/format/quantity.test.ts`).
//
// It works on the decimal STRING and never converts it to a number: a quantity holds up to 13 integer and 6 fractional
// digits, which a double cannot carry (`1234567890123.123456` would come back altered). Trailing zeros of the fraction are
// trimmed and a bare point is dropped (QR-2); zero is written `0`, never blank (QR-4); nothing is rounded, padded or
// grouped (QR-2, QR-7). A string that is not a plain decimal is returned as served: this renders, it never validates.

const DECIMAL = /^-?\d+(\.\d+)?$/;

export function renderQuantity(served: string): string {
  if (!DECIMAL.test(served)) return served;
  const point = served.indexOf('.');
  if (point === -1) return served;
  let end = served.length;
  while (end > point + 1 && served.charAt(end - 1) === '0') end -= 1;
  return end === point + 1 ? served.slice(0, point) : served.slice(0, end);
}

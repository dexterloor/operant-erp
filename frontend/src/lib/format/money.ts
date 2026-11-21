// The web app's one rendering of a money amount (STATES.md QR-6; b4-architecture.md Revision 10, which amends Revision 9
// R9-2). The owner decided the form on 2026-10-23 (design decision D1): the currency's own minor-unit
// decimals followed by the ISO code, `1250.00 EUR`, `250 JPY`. On 2026-11-17 the owner answered Q-3
// (purchase-orders-ruling.md): show it now by dropping only zeros, never rounding (purchase-orders-trim-red.md C-1).
// Used for supplier catalogue costs, purchase-order totals and purchase-order line unit costs.
//
// The rule is ALL-OR-NOTHING per value. If every fractional digit past the currency's minor unit is `0`, the fraction is
// cut to the minor unit (and the point dropped when the minor unit is 0); otherwise the served string is returned
// unchanged. So each currency has exactly two forms: D1's, or the stored four-decimal form (`0.3333 GBP`, a cost typed
// finer than a penny, or a total over a weighed quantity). Nothing in between, unlike QR-2's per-digit quantity trim
// (`quantity.ts`): money's two widths are "the currency's own" and "as stored". A value with fewer decimals than the
// minor unit (`1250`) is padded with zeros, which adds zeros and never changes the value. Zero is never signed.
//
// The domain is the writers' MONEY pattern widened by a sign: 1–15 integer digits, 0–4 fractional. A string outside it,
// or a code not in the table, is returned as served (the amount, a space, the code): it fails closed, never guessing.
//
// The minor units are a table here because the backend serves none (BK-39, keyed to G-37's closing work). The table is
// held equal to the backend's one write of the `currency` table (`backend/migrations/Version20260906000100.php`) by
// `test/contract/currencyMinorUnits.test.ts`, which goes red on a second write (G-37's seed is BK-39's trigger). It
// cannot see an owner's SQL in a hosted database: a new code there still fails closed; a changed minor unit for one of
// these four would not.
//
// It works on the decimal STRING and never converts it to a number (an amount holds up to 15 integer and 4 fractional
// digits, which a double cannot carry): no `Number`, no `toFixed`, no `Intl`, no unary `+` here — the quantity scan holds
// it (`test/architecture.test.ts`, `QUANTITY_SCAN_FILES`). This renders, it never validates.

/** ISO 4217 minor units of the seeded currencies (`currency.minor_unit`). */
export const MINOR_UNITS: Readonly<Record<string, number>> = { EUR: 2, GBP: 2, USD: 2, JPY: 0 };

const MONEY = /^(-?)(\d{1,15})(?:\.(\d{1,4}))?$/;

export function renderMoney(amount: string, currencyCode: string): string {
  const parts = MONEY.exec(amount);
  if (parts === null || !Object.hasOwn(MINOR_UNITS, currencyCode)) return `${amount} ${currencyCode}`;
  const minor = MINOR_UNITS[currencyCode]!;
  let sign = parts[1] ?? '';
  const integer = parts[2] ?? '';
  const fraction = parts[3] ?? '';
  // A non-zero digit past the minor unit: the stored value, exactly as served.
  if (/[1-9]/.test(fraction.slice(minor))) return `${amount} ${currencyCode}`;
  const kept = fraction.slice(0, minor).padEnd(minor, '0');
  if (/^0+$/.test(integer) && /^0*$/.test(kept)) sign = '';
  return `${sign}${integer}${minor === 0 ? '' : `.${kept}`} ${currencyCode}`;
}

// Catalogue unit costs only (suppliers-po.md §1): the interim (m2) (STATES.md QR-6; b4-architecture.md Revision 9 R9-2;
// suppliers-plan.md §5 D-1 and §R1-10 "Money"), ruled by PO + UX. Totals, order-line amounts, invoices and selling prices
// do NOT use this; QR-6's full ruling is owed at the first of them. The rule: the served value, UNTRIMMED, a space,
// and the entry's currency code, e.g. `12.5000 GBP`, `250.0000 JPY`. Untrimmed because trimming needs the currency's
// minor unit, which is not served (JPY has none), and a per-unit cost can need all four stored decimals: never wrong,
// only wordy. This file is the rule's one home, so the owed QR-6 ruling changes one file.
//
// It works on the decimal STRING and never converts it to a number (a cost holds up to 15 integer and 4 fractional digits,
// which a double cannot carry): no `Number`, no `toFixed`, no `Intl`, no unary `+` here — the quantity scan holds it
// (`test/architecture.test.ts`, `QUANTITY_SCAN_FILES`). This renders, it never validates.

export function renderMoney(amount: string, currencyCode: string): string {
  return `${amount} ${currencyCode}`;
}

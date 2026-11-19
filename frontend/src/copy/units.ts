/**
 * R1-1 / B-6 U-C: each served unit code's option label, keys in served order (held equal to the served enum by T-1).
 * S-3 (FE-R5): `spoken` is the unit's name as read after a quantity, always plural, a bare word (items-i4-ux.md M-3;
 * fe-r8-s3-ux.md Revision 1 FINAL). Suppliers S-4 (bk31-placement-confirm.md Revision 1 — FINAL R1-D, per-unit branch;
 * bk31-cost-basis-po.md Revision 1 FINAL): `perSpoken` is read after a price of ONE of the unit ("per kilogram"; "each"
 * for EA), for a catalogue entry's unit cost; shown `per EA` vs spoken "each" is intended (R1-D L-7).
 *
 * Family-free unit vocabulary (delete-swap-arch-red.md H-1, sparred in delete-swap-arch.md; was in `copy/items.ts` under
 * bk31-placement-confirm-judge.md ruling 2, withdrawn 2026-11-19). Any family may import it; it holds no family's wording.
 */
export const UNIT_LABELS: Readonly<Record<string, { label: string; spoken: string; perSpoken: string }>> = {
  EA: { label: 'Each (EA)', spoken: 'each', perSpoken: 'each' },
  BOX: { label: 'Box (BOX)', spoken: 'boxes', perSpoken: 'per box' },
  KG: { label: 'Kilogram (KG)', spoken: 'kilograms', perSpoken: 'per kilogram' },
  L: { label: 'Litre (L)', spoken: 'litres', perSpoken: 'per litre' },
  M: { label: 'Metre (M)', spoken: 'metres', perSpoken: 'per metre' },
};

/** A served unit code's label; a code with no entry renders as sent (the `kindLabel` precedent). */
export const unitLabel = (code: string): string => UNIT_LABELS[code]?.label ?? code;

/** S-3: a unit's spoken word, bare (the read-only and archived numbers); a code with no entry is spoken as sent. */
export const unitSpoken = (code: string): string => UNIT_LABELS[code]?.spoken ?? code;

/**
 * S-3: the edit field's spoken suffix, full stop included, so the hint after it in the description starts a new
 * sentence (fe-r8-s3-ux.md B refinement).
 */
export const unitSuffixSpoken = (code: string): string => `${unitSpoken(code)}.`;

/**
 * Suppliers S-4 (R1-D): a price per ONE of the unit, spoken bare ("per kilogram", "each"); a code with no entry is
 * "per {code}". The caller adds the full stop when it is a field's suffix.
 */
export const unitPerSpoken = (code: string): string => UNIT_LABELS[code]?.perSpoken ?? `per ${code}`;

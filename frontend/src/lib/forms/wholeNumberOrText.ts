// b4-architecture.md Revision 9 R9-2 (suppliers S-1): the ONE place a typed day count becomes a JSON number. Pure and
// copy-free: nothing here imports react, next, a component or copy (architecture.test.ts). `lib/forms` is outside the
// quantity scan on purpose: this is a whole number of days, never a quantity or an amount.
//
// The rule (suppliers-red.md M-1, suppliers-final-red.md R-2): a text of 1 to 15 ASCII digits is sent as that number
// (15 digits stay exact in a double); anything else is sent exactly as typed, so the server's own 422 sentence shows
// beside the field (the served schema says integer, and the writer answers a string with its range sentence, not a bare
// 400). No client range or format rule: an empty field is the caller's own check, before anything is sent.

/** `"30"` → `30`; `"30.5"`, `" 30"`, `"-1"`, `"abc"`, a 16-digit text → returned as typed. */
export function wholeNumberOrText(text: string): number | string {
  return /^[0-9]{1,15}$/.test(text) ? Number(text) : text;
}

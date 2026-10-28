// Copy for bulk selection, word for word. Sources, later wins:
//  - ux-copy-frontend.md §3.1 (after-messages, remainders) + Revision 3 items 1 (restore remainder) and 4 (cap, dialog,
//    selection scope); R4-1 "Lists, bulk and the person pages" (checkbox names, bar region name, Dismiss message);
//  - design round 6 brief §2c ("{n} selected", Clear selection);
//  - b4-owed-copy.md Revision 2 (FINAL) > Revision 1: R1-3 interrupted bodies, R1-5 bulk 403, R1-7 step 5, R1-8 Already
//    sent, and R1-10 O-1 — the bulk action and its dialog say DELETE (rows 1–7 replace the older "Archive" strings of
//    ux-copy §3.1 / Rev 3 item 4 / R4-4 L-6 and round-6 §2c). "The archived {plural}" names the place and is unchanged.
// Templates are functions; components never concatenate. A server sentence (e.g. `bulk.contention`) is NEVER stored
// here: the bar renders the result's `message` as sent. The cap is a parameter, never a number in this file.

/** The list's nouns, supplied by the caller from its own copy (e.g. supplier / suppliers). */
export interface BulkNouns {
  singular: string;
  plural: string;
}

export const BULK = {
  /** R4-1: the header checkbox's accessible name. */
  selectPage: 'Select every row on this page',
  /** R4-1: a row checkbox's name — the row's name, code or number as shown in its first column. */
  selectRow: (firstColumn: string) => `Select ${firstColumn}`,
  /** R4-1: the bar's region name. */
  barRegion: 'Actions for the selected rows',
  /** Round-6 §2c, RULED. */
  selected: (n: number) => `${n} selected`,
  clearSelection: 'Clear selection',
  /** PROPOSED (b4-owed-copy.md Rev 2 R1-10 row 1). */
  deleteAction: 'Delete',
  restoreAction: 'Restore',
  /** PROPOSED (b4-owed-copy.md Rev 2 R1-10 row 2). The number is the served cap, passed in. */
  deleteCapReason: (max: number) => `You can delete up to ${max} at a time.`,
  /** Rev 3 item 4 (restore). */
  restoreCapReason: (max: number) => `You can restore up to ${max} at a time.`,
  /** R4-1: the after-message close button. */
  dismissMessage: 'Dismiss message',
} as const;

/**
 * PROPOSED (b4-owed-copy.md Rev 2 R1-10 rows 3–5): the bulk Delete dialog. Initial focus is Cancel (row 5). Bulk
 * Restore has no dialog (Rev 3 item 4).
 */
export const BULK_DELETE_DIALOG = {
  title: (n: number, nouns: BulkNouns) => (n === 1 ? `Delete 1 ${nouns.singular}?` : `Delete ${n} ${nouns.plural}?`),
  body: (nouns: BulkNouns) => `Each one that can be deleted moves to the archived ${nouns.plural}, where it can be restored later.`,
  confirm: 'Delete',
  cancel: 'Cancel',
} as const;

/** §3.1 after-messages, counts only (E-14). Rev 3 item 1 for the restore remainder. */
export const BULK_AFTER = {
  /** PROPOSED (b4-owed-copy.md Rev 2 R1-10 row 6). */
  deleted: (applied: number, requested: number) => `${applied} of ${requested} deleted.`,
  restored: (applied: number, requested: number) => `${applied} of ${requested} restored.`,
  /** PROPOSED (b4-owed-copy.md Rev 2 R1-10 row 6). */
  deleteRemainder: (rest: number) =>
    `The other ${rest} weren't changed. A record may already be archived, no longer exist, or still have open work.`,
  restoreRemainder: (rest: number) =>
    `The other ${rest} weren't changed. A record may no longer be archived or exist, another record may now use its code or name, or a record it depends on may be archived.`,
} as const;

/**
 * PROPOSED (b4-owed-copy.md Rev 2 R1-3): one body for lost connection and 5xx, under the R1-1 heading. Safe because the
 * key is kept (R1-9).
 */
export const BULK_INTERRUPTED = {
  deleteBody: (nouns: BulkNouns) =>
    `We can't tell whether these ${nouns.plural} were deleted. Press Delete to try again: if they were, you'll see the result.`,
  restoreBody: (nouns: BulkNouns) =>
    `We can't tell whether these ${nouns.plural} were restored. Press Restore to try again: if they were, you'll see the result.`,
} as const;

/**
 * PROPOSED (b4-owed-copy.md Rev 2 R1-5), bulk row: from the bar's nouns, no label lookup. Heading Not deleted / Not
 * restored.
 */
export const bulkForbidden = (nouns: BulkNouns) =>
  `You no longer have permission to delete and restore ${nouns.plural}. Your workspace administrator can grant it.`;

/**
 * PROPOSED (b4-owed-copy.md Rev 2 R1-8): a 422 `key_expired`. The selection is cleared and the caller re-reads the
 * list.
 */
export const BULK_ALREADY_SENT = {
  heading: 'Already sent',
  body: "These were sent more than a day ago, so we can't show that result again. The list now shows where they are.",
} as const;

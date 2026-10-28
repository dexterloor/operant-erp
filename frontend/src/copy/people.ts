// Copy for deleting and restoring a person, word for word from docs/design/ux-copy-frontend.md §2.2–§2.3 (UX FINAL,
// R1 M-3) and R4-1's person strings. Templates are functions; components never concatenate. A server refusal sentence
// is NEVER stored here (ST-3, review L-6): the dialogs render the result's `message` as the server sends it.
// Interrupted, forbidden and no-message texts: docs/design/b4-owed-copy.md Revision 2 (FINAL) > Revision 1 (R1-1, R1-3,
// R1-5, R1-7). O-1 (R1-10) changes none of the strings used here ("Deleted", "Deleted on", "This person was deleted on
// {date}." are kept by name).

/** §2.2 Delete. */
export const DELETE_PERSON = {
  action: 'Delete person',
  /** §2.2 + STATES ST-4f audit row 11 (middle arm): your own page, rendered aria-disabled with this reason. */
  ownPageReason: "You can't delete your own account. Ask someone else who manages people.",
  title: (name: string) => `Delete ${name}?`,
  body1: (name: string) =>
    `${name} will be signed out everywhere and won't be able to sign in. Every role they have will be taken away, and any role proposals for them or made by them will end.`,
  body2:
    "They'll move to the archived people. If you restore them later, they'll sign in with their old password but get no roles back: someone will have to give them their roles again.",
  confirm: 'Delete person',
  cancel: 'Cancel',
  after: (name: string) => `${name} was deleted. They're now in the archived people.`,
} as const;

/** §2.3 Restore. Exactly one body is rendered, chosen by the served `is_active` (R1, RED M-3). */
export const RESTORE_PERSON = {
  action: 'Restore person',
  title: (name: string) => `Restore ${name}?`,
  bodyActive: (name: string) =>
    `${name} will be able to sign in again, with their old password. No roles come back, so they won't be able to do anything until someone gives them a role.`,
  bodySwitchedOff: (name: string) =>
    `${name}'s account will come back switched off, so they still won't be able to sign in. No roles come back.`,
  confirm: 'Restore person',
  cancel: 'Cancel',
  after: (name: string) => `${name} was restored.`,
  /** Only when the restored person's served `is_active` is true. */
  afterActive: 'Give them a role so they can work.',
  /** Only when active AND the viewer holds `role.assign` (ST-4c). */
  giveRole: 'Give a role',
} as const;

/** §2.3 Archived banner, split around the date (DT-1, rendered by `DateTime`). */
export const ARCHIVED_PERSON = {
  bannerBefore: 'This person was deleted on ',
  bannerAfter: '.',
} as const;

/** R4-1 person strings. */
export const PERSON = {
  /** Own page h1, from the served `GetCurrentUser` id. */
  ownName: (name: string) => `${name} (you)`,
  /** Chip on an archived person's page (O-1 open). */
  deletedChip: 'Deleted',
  /** Date column on the archived people view. */
  deletedOnColumn: 'Deleted on',
  /** R4-1: the after-message close button. */
  dismissMessage: 'Dismiss message',
} as const;

/**
 * b4-owed-copy.md Rev 2 R1-3, confirmed by ux-confirm-b4.md. b4-owed-copy.md Revision 2 (FINAL) > Revision 1. R1-3: one body for lost
 * connection and 5xx, under R1-1's heading (`copy/headings.ts` COULD_NOT_REACH / WENT_WRONG). R1-4: a 401 gets no
 * in-page copy (the fetch wrapper navigates to sign-in), so there is no string for it here.
 */
export const INTERRUPTED = {
  deleteBody: (name: string) => `We can't tell whether ${name} was deleted. Press Delete person to try again.`,
  restoreBody: (name: string) => `We can't tell whether ${name} was restored. Press Restore person to try again.`,
} as const;

/**
 * b4-owed-copy.md Rev 2 R1-5, confirmed by ux-confirm-b4.md. Person dialogs (heading Not deleted / Not restored): the surface's own
 * permission named from copy, in ST-4b's italic form — split so the component can render the label in `<em>`
 * (PAGE_STATES precedent).
 */
export const PERSON_FORBIDDEN = {
  before: 'This needs the ',
  label: 'Delete and restore people',
  after: ' permission, which you no longer have. Your workspace administrator can grant it.',
} as const;

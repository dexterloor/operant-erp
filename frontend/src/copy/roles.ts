// Copy for the role picker (§5.14) and the Waiting-for-an-owner group, word for word. Sources, later wins:
// design round 6 brief §2a (Rev 2) → `ux-copy-frontend.md` §3 staged-save labels, Rev 3 items 2–3 and 5, R4-1,
// 4b-1…4b-3, 4c → `b4-owed-copy.md` Revision 2 (FINAL) > Revision 1 (R1-1, R1-2 with A-1 + A-2 adopted, R1-5, R1-6,
// R1-7, R1-11). Templates are functions; components never concatenate. A server sentence is NEVER stored here (ST-3):
// banners render the result's `message` as sent. The SoD clauses and the picker's conflict sentence are `copy/sod.ts`
// (one shared copy). `copy/*` may import only `lib/api/*` (architecture.test.ts). Every string here is confirmed by
// ux-confirm-b4.md (2026-10-28), which also sets `loadFirst`, `saveNothing` and the one confirm sentence of `WAITING`.

import { REFUSAL, type KnownRefusal } from '@/lib/api/roles';

const pluralForms = (one: (n: number) => string, other: (n: number) => string) => ({ one, other });

/** §3 staged-save labels + R4-1 + R1-11 headings. */
export const PICKER = {
  /** b4-owed-copy.md Rev 2 R1-11 item 1, ratifying the prototype. */
  rolesHeading: 'Roles',
  effectiveHeading: 'Effective permissions',
  willBeGiven: 'Will be given',
  willBeProposed: 'Will be proposed',
  willBeTakenAway: 'Will be taken away',
  /** After save, row pills from that row's own 2xx (4b-3: no Proposed pill on the row). */
  given: 'Given',
  takenAway: 'Taken away',
  /** R4-1: rendered only while something is staged. */
  changes: pluralForms((n) => `${n} change`, (n) => `${n} changes`),
  save: 'Save roles',
  /** §3: the aria-disabled Save reason while the selection holds a conflict (served `conflicts_with`, ST-4e). */
  saveBlocked: "You can't save while a conflicting set of roles is selected.",
  /** ux-confirm-b4.md §2.7 (ST-4f middle arm): the aria-disabled Save reason while nothing is staged. */
  saveNothing: 'Nothing to save.',
  /** Round-6 §2a `actor_is_subject`: one visible line above the list, tied to every control. */
  ownPage: "You can't change your own roles. Ask someone else who manages roles.",
  /** Round-6 §2a "The real rules" + s4 §8.2 help line. */
  confirmationTag: "Needs an owner's confirmation",
  confirmationHelp: 'You can propose this role; it takes effect only if an owner confirms it within 14 days.',
  /** Round-6 §2a `subject_is_inactive`: the picker is not rendered; this banner is. */
  inactiveBanner: "This person's account is not active, so they can't be given a role.",
  /**
   * b4-owed-copy.md Rev 2 R1-7: a served `refusal` code with no copy. The code is never shown; the reason is
   * never blank.
   */
  unknownRefusal: "This role can't be given to this person.",
  /** Round-6 §2a + R4-2: in place of the effective permissions for a `role.assign` holder without `role.view`. */
  permissionsHidden: "Permissions aren't shown because you can't view roles.",
  /** RULED §5.14 pair form; `{role}` = the staged role (4b-2). */
  marginPair: (role: string) => `With ${role} as well, this person could see both what goods are bought for and what they are sold for.`,
  /** RULED §5.14 held form (served `margin_exposure`; wins over the pair form). */
  marginHeld: 'This person can see both what goods are bought for and what they are sold for, so they can work out the margin on them.',
  /** R4-1 panel summary, nothing staged that changes the preview. */
  permissionCount: pluralForms((n) => `${n} permission`, (n) => `${n} permissions`),
  /** R4-1: `{n} permissions after saving: {a} added, {r} taken away.` — each part only when non-zero. */
  /** `count` is `permissionCount`'s form for `{n}`. */
  afterSaving: (count: string, added: number, takenAway: number) => {
    const parts = [added > 0 ? `${added} added` : null, takenAway > 0 ? `${takenAway} taken away` : null].filter(Boolean);
    return `${count} after saving: ${parts.join(', ')}.`;
  },
  /** R4-1 per-permission flags (a permission is not "given"). */
  addedFlag: 'Added',
  takenAwayFlag: 'Taken away',
} as const;

/**
 * What a served `refusal` code shows on an unheld row (round-6 §2a table, keyed by the FULL served code — R4-4 M-2).
 */
export type RefusalCopy =
  /** Not a refusal: the role is held (held-ness comes from `roles[]`, never from this code). */
  | { show: 'held' }
  /** The named conflict lines from `conflicts_with` (`lib/roles/conflictLines.ts`, `copy/sod.ts`). */
  | { show: 'conflicts' }
  /** The own-page line above the list explains it. */
  | { show: 'own-page' }
  /** A reason line under the role, with an optional help line under it. */
  | { show: 'reason'; reason: string; help?: string }
  /**
   * The picker is not rendered for this person (round-6 §2a). If the page is stale and it is rendered anyway, the row
   * takes R1-7's neutral reason so it is never blank.
   */
  | { show: 'not-rendered' };

export const REFUSAL_COPY = {
  [REFUSAL.alreadyHeld]: { show: 'held' },
  [REFUSAL.separationOfDuty]: { show: 'conflicts' },
  [REFUSAL.actorIsSubject]: { show: 'own-page' },
  [REFUSAL.markerNeedsOwner]: { show: 'reason', reason: 'Only an owner can give someone this role, because it makes them an owner.' },
  [REFUSAL.exceedsActorPermissions]: {
    show: 'reason',
    reason: "This role includes permissions you don't have yourself, so you can't give it to anyone.",
    help: 'Ask an owner, or someone who holds all of its permissions.',
  },
  [REFUSAL.subjectIsInactive]: { show: 'not-rendered' },
  [REFUSAL.subjectIsDeleted]: { show: 'not-rendered' },
} as const satisfies Record<KnownRefusal, RefusalCopy>;

/** The picker's one status line (R4-1 as replaced by 4b-1; R1-1; R1-2 case B). `{roles}` arrive already joined. */
export const PICKER_STATUS = {
  unavailableOne: (role: string, cause: string) => `${role} can't be given now, because it conflicts with ${cause}.`,
  unavailableMany: (roles: string, cause: string) => `${roles} can't be given now, because each conflicts with ${cause}.`,
  againOne: (role: string) => `${role} can be given again.`,
  againMany: (roles: string) => `${roles} can be given again.`,
  given: (roles: string) => `Given: ${roles}.`,
  proposed: (roles: string) => `Proposed, waiting for an owner: ${roles}.`,
  takenAway: (roles: string) => `Taken away: ${roles}.`,
  notGiven: (roles: string) => `Not given: ${roles}.`,
  notProposed: (roles: string) => `Not proposed: ${roles}.`,
  notTakenAway: (roles: string) => `Not taken away: ${roles}.`,
  reasons: "Each change that wasn't saved has a message with the reason.",
  /** b4-owed-copy.md Rev 2 R1-2. */
  didntFinish: "The save didn't finish. A message says what wasn't saved.",
  /** b4-owed-copy.md Rev 2 R1-2 case B. */
  allSaved: 'All your changes are now saved.',
  /** b4-step5-gaps.md Rev 1 P-3: Load roles again succeeded after a case D/E save. */
  reloaded: "This person's roles were loaded again.",
  /** ux-confirm-b4.md §2.7, RED M-3: announced when a toggle leaves nothing staged. The one string is `PICKER`'s. */
  saveNothing: PICKER.saveNothing,
} as const;

/** Banners after Save roles (R1-2, R1-5, R1-6). Headings `Not given: {role}` etc. are `copy/headings.ts`. */
export const PICKER_BANNERS = {
  /**
   * b4-owed-copy.md Rev 2 R1-2 case A, A-1 + A-2 adopted; R1-5: `Not saved: 1 change` / `Not saved: {n}
   * changes`.
   */
  notSaved: pluralForms((n) => `Not saved: ${n} change`, (n) => `Not saved: ${n} changes`),
  /** b4-owed-copy.md Rev 2 R1-2 case A: the body, after the R1-1 heading text as its first sentence. */
  stillMarked: pluralForms(
    () => '1 change is still marked because the save stopped before we could tell whether it went through. Check it, then press Save roles.',
    (n) => `${n} changes are still marked because the save stopped before we could tell whether they went through. Check them, then press Save roles.`,
  ),
  /**
   * b4-step5-gaps.md Rev 1 P-1 case E, RED M-1; Rev 2 item 1 P-5: the re-read failed after
   * a save that was not interrupted (refusals present), and after a 403 stop. Case E's own key (Rev 2 item 4).
   */
  marksMayBeStale: "We couldn't load this person's roles again, so the marks below may be out of date.",
  /** b4-owed-copy.md Rev 2 R1-2 case C, with the four lines that follow. */
  rereadFailedLead: "We couldn't load this person's roles again, so we can't tell what was saved.",
  cantTell: (action: string) => `We can't tell whether this went through: ${action}.`,
  notSent: (actions: string) => `Not sent: ${actions}.`,
  /** ux-confirm-b4.md §2.4: names the button in the same banner. */
  loadFirst: 'Press Load roles again before you save anything else.',
  /** b4-owed-copy.md Rev 2 R1-2: the `{action}` forms. */
  giving: (role: string) => `giving ${role}`,
  proposing: (role: string) => `proposing ${role}`,
  takingAway: (role: string) => `taking away ${role}`,
  /** b4-owed-copy.md Rev 2 R1-2 case D, A-2 adopted. */
  savedButStale:
    "Your changes were saved, but we couldn't load this person's roles again, so other roles' availability may be out of date. Press Load roles again before you change anything.",
  /** b4-owed-copy.md Rev 2 R1-2 / R1-11 item 2. */
  loadAgain: 'Load roles again',
  /** b4-owed-copy.md Rev 2 R1-6: a take-away whose grant was already gone (404, no text); client copy. */
  nothingToTakeAway: 'This person no longer has this role, so there was nothing to take away.',
} as const;

/**
 * b4-owed-copy.md Rev 2 R1-5, picker and Waiting group: the surface's own permission named from copy, split
 * for the `<em>`.
 */
export const ROLES_FORBIDDEN = {
  before: 'This needs the ',
  label: 'Give and take away roles',
  after: ' permission, which you no longer have. Your workspace administrator can grant it.',
} as const;
/** The same R1-5 sentence as one string, for the page's status region (the `<em>` form is for the banner). */
export const ROLES_FORBIDDEN_SENTENCE = `${ROLES_FORBIDDEN.before}${ROLES_FORBIDDEN.label}${ROLES_FORBIDDEN.after}`;

/**
 * NEW (ux-copy-b3b.md C6-1, final; RED round 2 R2-M1): a re-read shows that someone deleted the person while their page
 * was open. Announced through the page-level status region; after a save that did not finish, the body of the page's
 * banner under the save's heading.
 */
export const PERSON_DELETED_WHILE_OPEN = 'This person was deleted while this page was open, so their roles can no longer be changed.';

/**
 * The Waiting-for-an-owner group (round-6 §2a; R4-1; 4b-3; R1-2). Dates are `DateTime`, so templates split around them.
 */
export const WAITING = {
  heading: 'Waiting for an owner',
  line: 'These roles give no access until an owner confirms them.',
  /** `Proposed by {name} on {date}` (DT-1 date). */
  proposedBy: (name: string) => `Proposed by ${name} on `,
  /** `Expires {date, time}` (DT-3, tooltip with the full instant). */
  expires: 'Expires ',
  /** 4b-3 NEW: `Expired {date, time}`. */
  expired: 'Expired ',
  /** RULED chip (handoff :173/:478). */
  expiredChip: "Expired — can't be confirmed",
  confirm: 'Confirm',
  reject: 'Reject',
  withdraw: 'Withdraw',
  /** §3 "n shown" (TB-2): the rows on this page, never a total. */
  shown: (n: number) => `${n} shown`,
  newer: 'Newer',
  older: 'Older',
  /**
   * b4-step5-gaps.md Rev 1 P-4, RED M-4/M-5: announced in the group's own status region after a successful action;
   * `{role}` = the served `role.name`. The same sentence whether or not the re-read then worked (ux-confirm-b4.md §2.5);
   * `listStale` follows when it failed.
   */
  done: {
    confirm: (role: string) => `Proposal confirmed: ${role} is now given to this person.`,
    reject: (role: string) => `Proposal rejected: ${role}.`,
    withdraw: (role: string) => `Proposal withdrawn: ${role}.`,
  },
  /** b4-step5-gaps.md Rev 1 P-4. */
  listStale: "We couldn't load the list again, so it may be out of date.",
  /** b4-owed-copy.md Rev 2 R1-2: the group's interrupted bodies. */
  interrupted: {
    confirm: "We can't tell whether this proposal was confirmed. If it's still waiting, press Confirm to try again.",
    reject: "We can't tell whether this proposal was rejected. If it's still waiting, press Reject to try again.",
    withdraw: "We can't tell whether this proposal was withdrawn. If it's still waiting, press Withdraw to try again.",
  },
} as const;

/**
 * NEW (ux-copy-b3b.md C7-1, final per its "Summary, per commit"): the `/admin/roles` list. The h1 is the nav noun
 * (R4-1). `Built-in` is `CHECKLIST.builtInChip` (REUSE), shown from the served `is_system`, never from a name.
 */
export const ROLES_LIST = {
  heading: 'Roles',
  /** ST-4b's `{resource}` for the roles pages (C7-1 403 row). */
  resourcePlural: 'roles',
  archived: 'Archived',
  name: 'Name',
  people: 'People',
  permissions: 'Permissions',
  archivedOrder: 'Most recently deleted first',
  searchLabel: 'Search by name',
  searchPlaceholder: 'Start of a role name',
  noneLive: 'No roles yet.',
  noMatch: (q: string) => `No role's name starts with “${q}”.`,
  noneArchived: 'No archived roles. Roles that are deleted appear here.',
  noArchivedMatch: (q: string) => `No archived role's name starts with “${q}”.`,
  clearSearch: 'Clear search',
  /** ST-1a's settled announcement. Never called with 0: the empty heading is spoken instead (ST-2a). */
  shown: (n: number, q: string, archived: boolean) =>
    [n === 1 ? '1 role shown.' : `${n} roles shown.`, q !== '' ? `Name starts with “${q}”.` : null, archived ? 'Archived.' : null].filter(Boolean).join(' '),
  loadFailed: "The list of roles couldn't be loaded.",
} as const;

/** NEW (ux-copy-b3b.md C7-2, RED M-4): the role page's read failed after first paint, incl. after a save. */
export const ROLE_PAGE = {
  rereadFailed: "We couldn't load this role again, so what's shown may be out of date.",
  /**
   * CONFIRMED (ux-confirm-followup.md §4). "This role was deleted on {date}." split around the date (DT-1, rendered by
   * `DateTime` with the session zone), the shape of the person banner (`ARCHIVED_PERSON`).
   */
  deletedBannerBefore: 'This role was deleted on ',
  deletedBannerAfter: '.',
} as const;

// Copy for the credential pages, word for word from docs/design/ux-copy-frontend.md §1 (UX FINAL + Revision 2) unless
// marked PROPOSED. Server 422 sentences are never stored here: the pages render them as the server sends them.
// The 401/429 copy on the reset pages IS client copy (§1.4: those responses carry no human `detail`).
// Never anywhere in this file: "we emailed you", "email sent", "check your inbox", "we've sent" (§1.3, ST-7.1).

/** Shared ST-8a rate-limit copy (RULED), split heading/body as on the sign-in card. */
export const RATE_LIMITED = {
  heading: 'Too many attempts.',
  body: 'Try again in a few minutes.',
} as const;

/** §1.2 — the link under the sign-in card's Password field. */
export const FORGOT_LINK = { label: 'Forgot your password?', href: '/forgot-password' } as const;

/** §1.3 — `/forgot-password`. */
export const REQUEST_RESET = {
  title: 'Reset your password',
  heading: 'Reset your password',
  intro:
    "Enter the workspace name and email you sign in with. If they belong to an active account, we'll send a link to set a new password.",
  workspaceLabel: 'Workspace',
  emailLabel: 'Email',
  submit: 'Send link',
  backToSignIn: 'Back to sign in',
  /** 429 second line — confirmed by SEC (S-3). */
  rateLimitedShared: 'This limit is shared with signing in, so it can also come from other people on the same network.',
  /**
   * NEW (forgot-heading-ux.md Revision 1, RED round 1 A1–A3): the body of the interrupted notices on this page, under the
   * unchanged R1-1 headings (`COULD_NOT_REACH`, offline icon kept). A request that reached the server may already be
   * queued even though the answer was lost, so the page cannot say whether a link is on its way.
   */
  interruptedBody:
    "We can't tell whether your request got through. If an email with a link arrives, use it. If none arrives in a few minutes, press Send link again.",
  /** The one result state for every 202. */
  resultHeading: 'Check your email',
  resultBody:
    "If that email belongs to an active account in that workspace, we'll send it a link. The link works once, for 30 minutes, and we send at most three links for one account in 24 hours.",
  resultBody2:
    "Your password hasn't changed. If no email arrives in a few minutes, check the workspace name and email, or ask the person who manages your workspace.",
  tryDifferent: 'Try different details',
} as const;

/** §1.4 — `/reset-password`. */
export const SET_PASSWORD = {
  title: 'Set a new password',
  heading: 'Set a new password',
  passwordLabel: 'New password',
  passwordHint: 'At least 12 characters.',
  show: 'Show password',
  hide: 'Hide password',
  submit: 'Set password',
  /** 429: ST-8a + NEW. The second line while this page has not shown an interrupted result (unreachable, server error). */
  rateLimitedNothingChanged: 'Nothing was changed.',
  /**
   * Shown once this page has shown an interrupted result (unreachable, server error): that earlier redeem may have
   * committed. The 429's second line in place of "Nothing was changed." (M-2; CONVERGED, confirm-pass-2026-10-30-final-red.md
   * "Spar round 2", amendment 1), the not-sent banner's second line, and the line under the refusal card's heading
   * (B4, b4-doubt-ux.md Revision 1 §R1-4). One string for the three results; the text is unchanged.
   */
  earlierTryMaySet: 'An earlier try may already have set your new password: try signing in with it.',
  /** 401 — one state for every cause; also shown, without calling the API, when there is no token. */
  refusedHeading: "This link can't be used",
  refusedBody:
    'Each link works once, for 30 minutes, and setting a new password ends any others. Ask for a new link to try again.',
  refusedBody2: "If a new link doesn't arrive, ask the person who manages your workspace.",
  askForNewLink: 'Ask for a new link',
  /** 200. */
  doneHeading: 'Password set',
  doneBody: "You've been signed out everywhere, including other tabs and devices. Sign in with your new password.",
  signIn: 'Sign in',
  /** Announced politely when Sign in appears (§1.4 "The wait"). */
  signInNow: 'You can sign in now.',
  /**
   * CONFIRMED (ux-confirm-followup.md §3); supersedes CREDENTIAL_FALLBACK's bodies on the reset page only. The body of
   * BOTH interrupted notices on this page (unreachable, server error), under the unchanged R1-1 headings: a redeem that
   * reached the server may have set the password even though the answer was lost.
   */
  interruptedBody: "We can't tell whether your new password was set. Try signing in with it. If that doesn't work, press Set password again, or ask for a new link.",
} as const;

/** §1.5, D2 = yes (owner ruling 2026-10-27, package P-A) — `/account/password`. */
export const CHANGE_PASSWORD = {
  /** CONFIRMED by UX (confirm-pass-2026-10-30.md §c): page title, labels and button (design export, DECISIONS §7.1). */
  title: 'Change password',
  heading: 'Change password',
  currentLabel: 'Current password',
  newLabel: 'New password',
  submit: 'Change password',
  /** RULED under D2 = yes (Revision 2, RED S-6). */
  doneHeading: 'Password changed',
  doneBody: "You've been signed out everywhere, including here. Sign in with your new password.",
  signIn: 'Sign in',
  signInNow: SET_PASSWORD.signInNow,
  /**
   * CONFIRMED by UX (confirm-pass-2026-10-30.md Rev 1 §a; c10-design.md §6, FINAL B.5). The body of BOTH interrupted
   * notices on this page (unreachable, server error), under the unchanged headings: a change that reached the API may
   * have committed even though its answer was lost, and pressing again is safe (if it changed, the next press is sent to
   * sign-in).
   */
  interruptedBody:
    "We can't tell whether your password was changed. Press Change password again: if it was changed, you'll be asked to sign in, and your new password will work.",
  /**
   * CONFIRMED by UX (confirm-pass-2026-10-30.md Rev 1 §a) for this page: the 429 second line (c10-design.md §5, FINAL
   * B.5). Its own literal, no longer an alias of the reset page's (M-2): it stays true here after an interrupted result,
   * because a change that committed ends the calling session and the next press is the constant 401, refused before the
   * throttle.
   */
  rateLimitedNothingChanged: 'Nothing was changed.',
} as const;

/**
 * CONFIRMED (ux-copy-b3b.md C2-2; b3a2 fallback): the bodies for "the server can't be reached" and "a server failure"
 * on the credential pages and sign-in:
 * - unreachable: on the sign-in card only; the three password pages each state the doubt instead (their own
 *   `interruptedBody`), because a request that reached the server may have done its work although the answer was lost;
 * - server error (5xx): ST-3 says bounded and never the server's `detail`.
 * The headings are not repeated here: they are `COULD_NOT_REACH` / `WENT_WRONG` in `copy/headings.ts`, their one home
 * (RED L-14). A `copy/*` module may import only from `lib/api/*`, so the pages import the headings themselves.
 */
export const CREDENTIAL_FALLBACK = {
  unreachableBody: 'Check your connection and try again.',
  serverErrorBody: 'Try again in a moment.',
} as const;

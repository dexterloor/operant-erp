// Client-composed copy for the shell. RULED strings are copied word for word from the brief §9 (which quotes the UX
// docs and shipped server strings); do not edit them here without a UX ruling. PROPOSED strings are the brief's or
// the design export's proposals and are UX's to confirm. A server refusal sentence is never stored here: it is
// rendered exactly as the server sends it (ST-3, review L-6).

export const SIGN_IN = {
  title: 'Sign in',
  workspaceLabel: 'Workspace',
  emailLabel: 'Email',
  passwordLabel: 'Password',
  submit: 'Sign in',
  /** RULED (§9, ST-7): every cause, one message, followed always by the second sentence. */
  failureHeading: "Those details don't match an account.",
  failureBody: 'Check your workspace name, email and password.',
  failureNever:
    'We never explain sign-in problems on this screen. If there is something you need to know about your account, it goes to its email address.',
  /** RULED (§9, ST-8a): the IP rate limit. */
  rateLimitedHeading: 'Too many attempts.',
  rateLimitedBody: 'Try again in a few minutes.',
  /** RULED (§9, ST-8b): the account pause. */
  pausedHeading: 'Too many failed attempts.',
  pausedBody: 'Sign-in with this workspace and email is paused for 15 minutes.',
  /** CONFIRMED (ux-copy-b3b.md C2-2): the notice after a session ended. Fixed text: `reason` is settable by anyone. */
  sessionEndedHeading: 'You were signed out.',
  sessionEndedBody: 'Sign in again to continue.',
  /** CONFIRMED (b4-owed-copy.md R1-4; b3b-design.md §12, L-13): the notice's third line. */
  sessionEndedSaving: 'If you were saving changes, check them after you sign in: some may not have been saved.',
  /** PROPOSED (export DECISIONS §7.1, IA-O-2). */
  rememberedHint: 'Remembered on this device.',
  useAnotherWorkspace: 'Use another workspace',
  /** PROPOSED (export DECISIONS §7.1). */
  invitationHint: 'Filled in from your invitation link.',
} as const;

export const PAGE_STATES = {
  /** RULED (§9, ST-4b): "You don't have access to purchase orders." / "This needs the Purchasing permission. …" */
  deniedHeading: (resourcePlural: string) => `You don't have access to ${resourcePlural}.`,
  deniedBodyBefore: 'This needs the ',
  deniedBodyAfter: ' permission. Your workspace administrator can grant it.',
  /** CHANGED (ux-copy-b3b.md C2-3, SEC accepted with conditions): the link goes to the `/` start page. */
  deniedBack: 'Go to your start page',
  /** RULED (§9, ST-5): not found and not yours look identical. */
  notFoundHeading: "We couldn't find that.",
  notFoundBody: 'It may have been deleted, or you may not have access.',
  notFoundBack: 'Back to the list',
  retry: 'Retry',
  /** PROPOSED (§6 reduced motion: spinners become static text). */
  loading: 'Loading…',
} as const;

/**
 * NEW (ux-copy-b3b.md C5-1, RED M-4): a list whose refetch failed while its rows stay shown (ST-3 network row), under
 * R1-1's heading by cause. Shared by every list (C7-1 REUSE). It is true only while the view control shows the view on
 * screen (RED round 2 R2-L2), which the lists guarantee by deriving the control from the shown rows.
 */
export const LIST_STATES = {
  refetchFailed: "The list couldn't be updated, so it still shows the earlier results.",
} as const;

/** The document title: the page's own title, then the product name (root layout metadata). */
export const APP_TITLE = { default: 'ERP', template: '%s · ERP' } as const;
export const pageTitle = (title: string) => APP_TITLE.template.replace('%s', title);

/**
 * NEW (ux-copy-b3b.md C2-1, Q-6): the `/` start page, when no built page is open to the viewer. The body is chosen by
 * the served `permission_codes`: empty (no roles, or roles with no permissions) or not. The h1 ends with a full stop;
 * the `<title>` does not.
 */
export const START = {
  heading: "There's nothing here for you yet.",
  title: "There's nothing here for you yet",
  bodyNoAccess: "You don't have access to anything in this workspace yet. Your workspace administrator can give you a role.",
  bodyNotYet: "The pages your roles give you access to aren't available yet.",
} as const;

/**
 * NEW (ux-copy-b3b.md C3-1): the fixed page shown on a second session change in one tab (R2-4). It never names a
 * workspace or a person: the tab cannot tell which. The first change is a silent reload with no copy.
 */
export const SESSION_CHANGED = {
  heading: 'This tab is out of date.',
  title: 'This tab is out of date',
  body: "A newer sign-in in this browser replaced the one this tab was using, so your last action wasn't sent.",
  bodySecond: 'If you sign in again here, other tabs in this browser will switch to that sign-in.',
  signIn: 'Sign in again',
} as const;

export const SHELL = {
  skipLink: 'Skip to content',
  mainNav: 'Main',
  openMenu: 'Open menu',
  closeMenu: 'Close menu',
  /** Appended, visually hidden, to the account button's visible name, so the accessible name starts with the visible
   *  label (WCAG 2.5.3). The export used aria-label "Account · <name>", which axe flags as a label-in-name mismatch. */
  accountMenuSuffix: ' · Account',
  changePassword: 'Change password',
  theme: 'Theme',
  themeLight: 'Light',
  themeDark: 'Dark',
  themeSystem: 'Match system',
  signOut: 'Sign out',
  dismiss: 'Dismiss',
} as const;

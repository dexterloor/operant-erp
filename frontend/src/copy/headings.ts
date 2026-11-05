// "Not {participle}" banner headings (ux-copy-frontend.md §3 heading rule; Rev 3 item 2). A refused single-request
// action takes the bare heading; the body is the server's sentence, never stored here. Only the headings B-4 step 2
// uses are here; later steps add theirs.

/** §2.2 Refusals: "business-rule banner, heading 'Not deleted'". RULED. */
export const NOT_DELETED = 'Not deleted';
/** §2.3 Refusals: "heading 'Not restored'". RULED. */
export const NOT_RESTORED = 'Not restored';
/** warehouses-ux-copy.md B-4 refusal heading (RULED, the heading rule): the create page's banner. */
export const NOT_CREATED = 'Not created';

/**
 * b4-owed-copy.md Rev 2 R1-1; the B-3a fallback phrasing, F16, confirmed by ux-confirm-b4.md: where the client cannot know whether the
 * request committed (lost connection, 5xx), the heading names the failure instead of "Not {participle}". The one home
 * of these two headings for every B-4 surface (person dialogs, bulk, checklist, picker, Waiting group; b4-final-red F).
 */
export const COULD_NOT_REACH = "We couldn't reach the server.";
export const WENT_WRONG = 'Something went wrong on our side.';

/**
 * b4-owed-copy.md Rev 2 R1-7 step 5, confirmed by ux-confirm-b4.md: a 409/422 refusal that carries no `message` (incl.
 * `idempotency-key-conflict`, step 2). Steps 3–4: any other refusal renders the server's `message`. Surface-neutral, so
 * it lives here with the headings (b4-final-red F).
 */
export const REFUSED_WITHOUT_MESSAGE = "This change wasn't made. Reload the page to see where things stand, then try again.";

/** Round-6 §3.10 / ux-copy-frontend.md §3: the checklist's Save changes (B-4 step 4; R1-5 and R1-6 use it too). */
export const NOT_SAVED = 'Not saved';

/**
 * The picker (B-4 step 5): R4-1 headings carry the role, because one Save sends several requests and banners stack
 * (review L-4). Bodies are the server's sentences. RULED/NEW per ux-copy-frontend.md R4-1.
 */
export const notGiven = (role: string) => `Not given: ${role}`;
export const notProposed = (role: string) => `Not proposed: ${role}`;
export const notTakenAway = (role: string) => `Not taken away: ${role}`;
/** R4-1: the Waiting group's action headings (house heading rule). */
export const NOT_CONFIRMED = 'Not confirmed';
export const NOT_REJECTED = 'Not rejected';
export const NOT_WITHDRAWN = 'Not withdrawn';

// Pure helpers the shell needs. No fetch, no cookies, no tokens here (B-3a scope).

/**
 * D14: holders of `tenant_settings.view` see the workspace's display name; everyone else sees the workspace sign-in
 * name they typed at login (kept by the BFF in B-3b). Shown exactly as given, never re-cased.
 */
export function workspaceLabel(args: { codes: ReadonlySet<string> | readonly string[]; displayName?: string | null; signInName: string }): string {
  const held = args.codes instanceof Set ? args.codes : new Set(args.codes as readonly string[]);
  if (held.has('tenant_settings.view') && args.displayName) return args.displayName;
  return args.signInName;
}

/** First path segments that are never a return target, even when a caller lists them. */
const REFUSED_SEGMENTS: ReadonlySet<string> = new Set(['login', 'reset-password', 'forgot-password', 'bff', 'api', '_next']);

const ORIGIN = 'https://same-origin.invalid';

/**
 * The longest `return_to` the app accepts. The ONE copy: the sign-in handler refuses a longer body field outright
 * (`handlers.ts`), `safeReturnPath` falls back on one, and `sessionEndedHref` never produces one.
 */
export const RETURN_TO_MAX = 2048;

/**
 * Review L-7 / SD-1: the return target after "session ended" or sign-in must be a path on this site that names a
 * declared page. The check is made on the value AFTER the URL parser has resolved it, because dot segments can turn a
 * harmless-looking path into `//host` or into a refused page. Anything else returns `fallback`.
 *
 * `raw` is taken as given (a `searchParams.get()` value is already decoded once) and is never decoded again; only the
 * first segment of the resolved path is decoded, once, to compare it with `declaredRoutes`. The fragment is dropped.
 * `''` in `declaredRoutes` allows the start page `/`.
 */
export function safeReturnPath(
  raw: string | null | undefined,
  opts: { readonly declaredRoutes: readonly string[]; readonly fallback: string },
): string {
  const { declaredRoutes, fallback } = opts;
  // Step 1: length, no control characters, no backslash anywhere.
  if (typeof raw !== 'string' || raw.length === 0 || raw.length > RETURN_TO_MAX) return fallback;
  if (/[\u0000-\u001f\u007f\\]/.test(raw)) return fallback;
  // Step 2: exactly one leading slash (this also rules out a scheme).
  if (!raw.startsWith('/') || raw.startsWith('//')) return fallback;
  try {
    // Step 3: resolve against a fixed origin, keep path and query, drop the fragment.
    const url = new URL(raw, ORIGIN);
    if (url.origin !== ORIGIN) return fallback;
    const out = url.pathname + url.search;
    // Step 3a: after normalisation, still exactly one leading slash.
    if (!out.startsWith('/') || out.startsWith('//')) return fallback;
    // Step 4: the first segment of the resolved pathname, decoded once, must be declared and not refused.
    const rest = url.pathname.slice(1);
    const slash = rest.indexOf('/');
    const seg = slash === -1 ? rest : rest.slice(0, slash);
    if (seg === '' && !declaredRoutes.includes('')) return fallback;
    const decoded = decodeURIComponent(seg);
    if (!declaredRoutes.includes(decoded) || REFUSED_SEGMENTS.has(decoded)) return fallback;
    return out;
  } catch {
    return fallback;
  }
}

/**
 * Where a page sends a person whose session has ended (b3b-design.md §6.3, §10): the sign-in notice, carrying the page
 * they were on (path and query) as `return_to`. `here` is a request, not a destination: after sign-in the browser goes
 * only to the address the sign-in handler returns, and that handler alone checks `here` with `safeReturnPath` (SD-1).
 * So any `here` is safe to carry, and one that is refused (off-site, undeclared, an account page, `/`) lands on `/`.
 *
 * It never carries a `return_to` the sign-in handler would refuse (longer than RETURN_TO_MAX, measured on the value the
 * sign-in page reads back, i.e. before encoding): over the cap the query is dropped and the path kept; if the path
 * alone is still over the cap, no `return_to` is sent and sign-in lands on `/` (fe-session-return-red.md M-1).
 */
export function sessionEndedHref(here: string): string {
  const notice = '/login?reason=session_ended';
  let carried = here;
  if (carried.length > RETURN_TO_MAX) {
    const q = carried.indexOf('?');
    if (q !== -1) carried = carried.slice(0, q);
  }
  if (carried.length > RETURN_TO_MAX) return notice;
  return `${notice}&return_to=${encodeURIComponent(carried)}`;
}

/** Initials for the avatar: first letters of the first two words of the display name. */
export function initials(displayName: string): string {
  const parts = displayName.trim().split(/\s+/).filter(Boolean);
  return parts
    .slice(0, 2)
    .map((p) => Array.from(p)[0]?.toUpperCase() ?? '')
    .join('');
}

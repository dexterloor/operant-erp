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
  if (typeof raw !== 'string' || raw.length === 0 || raw.length > 2048) return fallback;
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

/** Initials for the avatar: first letters of the first two words of the display name. */
export function initials(displayName: string): string {
  const parts = displayName.trim().split(/\s+/).filter(Boolean);
  return parts
    .slice(0, 2)
    .map((p) => Array.from(p)[0]?.toUpperCase() ?? '')
    .join('');
}

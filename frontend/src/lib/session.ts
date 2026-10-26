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

/**
 * Review L-7: after "session ended", the return target must be a same-origin relative path. This is the client half;
 * the BFF must validate it again (B-3b). Anything else returns the fallback.
 */
export function safeReturnPath(raw: string | null | undefined, fallback = '/overview'): string {
  if (typeof raw !== 'string' || raw.length === 0 || raw.length > 2048) return fallback;
  if (!raw.startsWith('/') || raw.startsWith('//') || raw.startsWith('/\\')) return fallback;
  if (/[\u0000-\u001f\u007f\\]/.test(raw)) return fallback;
  if (raw === '/login' || raw.startsWith('/login?') || raw.startsWith('/login/')) return fallback;
  try {
    const u = new URL(raw, 'https://same-origin.invalid');
    if (u.origin !== 'https://same-origin.invalid') return fallback;
    return u.pathname + u.search + u.hash;
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

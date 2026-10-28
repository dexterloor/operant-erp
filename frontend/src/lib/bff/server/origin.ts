import 'server-only';

// The one wrapper every mutating BFF handler goes through (bff-auth-note.md §3 + R1-§3; b3b-design.md §5 item 6), and
// the BFF's own problem answers. Fail closed: Origin must be present and equal APP_PUBLIC_ORIGIN exactly (never derived
// from the request's Host); a present Sec-Fetch-Site must be `same-origin`; the body must be JSON. A refusal makes no
// API call. No CORS header is ever sent, so a cross-origin JSON request never passes its preflight.

export const NO_STORE = 'no-store, private';

/** A BFF-owned problem document. Never carries an upstream body. */
export function bffProblem(status: number, type: string, extra: Record<string, unknown> = {}): Response {
  return new Response(JSON.stringify({ type, status, ...extra }), {
    status,
    headers: { 'content-type': 'application/problem+json', 'cache-control': NO_STORE },
  });
}

/** The configured public origin, or null when it is missing or not an origin. */
function publicOrigin(): string | null {
  const raw = process.env.APP_PUBLIC_ORIGIN;
  if (typeof raw !== 'string' || raw === '') return null;
  try {
    const u = new URL(raw);
    return u.origin === raw.replace(/\/$/, '') ? u.origin : null;
  } catch {
    return null;
  }
}

/** null when the request may proceed; otherwise the refusal to send back. */
export function refuseCrossSiteMutation(request: Request): Response | null {
  const expected = publicOrigin();
  const origin = request.headers.get('origin');
  if (expected === null || origin === null || origin !== expected) return bffProblem(403, 'urn:erp:bff:request-refused');
  const site = request.headers.get('sec-fetch-site');
  if (site !== null && site !== 'same-origin') return bffProblem(403, 'urn:erp:bff:request-refused');
  const type = (request.headers.get('content-type') ?? '').split(';')[0]!.trim().toLowerCase();
  if (type !== 'application/json') return bffProblem(415, 'urn:erp:bff:unsupported-media-type');
  return null;
}

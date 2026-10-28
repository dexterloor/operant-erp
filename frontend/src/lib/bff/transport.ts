// The ONE browser fetch (b3b-design.md §6.1, §6.2 rule R-2 item 1; guard G-1 from C3). Same-origin `/bff/` paths only,
// `credentials: 'same-origin'`, `cache: 'no-store'`, and a 20 s limit (above the BFF's 15 s), built from setTimeout +
// AbortController. It never throws on a failed request, never maps a status and never navigates: callers do that.

export const CLIENT_TIMEOUT_MS = 20_000;

export type RawOutcome = { readonly ok: true; readonly response: Response } | { readonly ok: false; readonly kind: 'unreachable' };

/** One request to the web app's own server. An abort (the limit, or the caller's signal) or a network error is `unreachable`. */
export async function bffFetch(path: string, init: RequestInit = {}, signal?: AbortSignal): Promise<RawOutcome> {
  if (!path.startsWith('/bff/') || path.startsWith('//')) throw new Error(`${path} is not a path on the web app's server.`);
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), CLIENT_TIMEOUT_MS);
  const onAbort = () => controller.abort();
  if (signal) {
    if (signal.aborted) controller.abort();
    else signal.addEventListener('abort', onAbort, { once: true });
  }
  try {
    const response = await fetch(path, { ...init, credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
    return { ok: true, response };
  } catch {
    return { ok: false, kind: 'unreachable' };
  } finally {
    clearTimeout(timer);
    signal?.removeEventListener('abort', onAbort);
  }
}

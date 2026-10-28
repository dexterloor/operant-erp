// The ONE browser fetch (b3b-design.md §6.1, §6.2 rule R-2 item 1; guard G-1 from C3). Same-origin `/bff/` paths only,
// `credentials: 'same-origin'`, `cache: 'no-store'`, and a 20 s limit (above the BFF's 15 s), built from setTimeout +
// AbortController. It never throws on a failed request, never maps a status and never navigates: callers do that.
// The limit and the caller's signal cover the response headers AND the whole body (code RED M-1): the body is read here,
// inside the timed section, and handed back as a buffered Response, so a body that stalls ends as `unreachable` at the
// first bound to pass, never as a hang or a `server-error`.

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
    const text = await readText(response, controller.signal);
    const empty = response.status === 204 || response.status === 205 || response.status === 304;
    return { ok: true, response: new Response(empty ? null : text, { status: response.status, headers: response.headers }) };
  } catch {
    return { ok: false, kind: 'unreachable' };
  } finally {
    clearTimeout(timer);
    signal?.removeEventListener('abort', onAbort);
  }
}

/** The body as text, rejecting the moment `signal` aborts (whether or not the platform's reader honours it). */
async function readText(response: Response, signal: AbortSignal): Promise<string> {
  let onAbort = () => {};
  const aborted = new Promise<never>((_, reject) => {
    onAbort = () => reject(new DOMException('The time limit passed.', 'AbortError'));
    if (signal.aborted) onAbort();
    else signal.addEventListener('abort', onAbort, { once: true });
  });
  aborted.catch(() => {});
  try {
    return await Promise.race([response.text(), aborted]);
  } catch (e) {
    void response.body?.cancel().catch(() => {});
    throw e;
  } finally {
    signal.removeEventListener('abort', onAbort);
  }
}

import 'server-only';
import { isLoopbackOrigin } from '../loopback';

// The one wrapper every mutating BFF handler goes through (bff-auth-note.md §3 + R1-§3; b3b-design.md §5 item 6), and
// the BFF's own problem answers. Fail closed: Origin must be present and equal APP_PUBLIC_ORIGIN exactly (never derived
// from the request's Host); a present Sec-Fetch-Site must be `same-origin`; the body must be JSON. A refusal makes no
// API call. No CORS header is ever sent, so a cross-origin JSON request never passes its preflight.

export const NO_STORE = 'no-store, private';

const OWN = new WeakSet<Response>();

/** A BFF-owned problem document. Never carries an upstream body. */
export function bffProblem(status: number, type: string, extra: Record<string, unknown> = {}): Response {
  const r = new Response(JSON.stringify({ type, status, ...extra }), {
    status,
    headers: { 'content-type': 'application/problem+json', 'cache-control': NO_STORE },
  });
  OWN.add(r);
  return r;
}

/**
 * 503 urn:erp:bff:not-sent: this server refused the call before sending anything to the API — its configuration is
 * missing or wrong (a base URL, origin or client-address source that `apiFetch` refuses, or a session key that cannot be
 * read), or the call could not be built. Answered ONLY from a `catch` that wraps nothing but the pre-send steps (an
 * `apiFetch` call, or the session unseal before any call). A 5xx on purpose: the proxy replaces every upstream 5xx that is
 * not this module's own Response (identity, below) and the credential handlers pass no upstream 5xx, so no upstream answer
 * can ever arrive carrying this type. The type says only "not sent", never which setting is wrong.
 */
export const unsentProblem = (): Response => bffProblem(503, 'urn:erp:bff:not-sent');

/** True for a Response made by bffProblem (identity, not a header an upstream could copy). */
export function isBffProblem(r: Response): boolean {
  return OWN.has(r);
}

/**
 * The configured public origin, or null when it is missing, not an origin, or (deployment guard, run-time half, code
 * RED M-3) not exact-host loopback: until the deployment commit no write is accepted on any other origin.
 */
function publicOrigin(): string | null {
  const raw = process.env.APP_PUBLIC_ORIGIN;
  if (typeof raw !== 'string' || raw === '' || !isLoopbackOrigin(raw)) return null;
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

// Bounded body reads (code RED M-1, L-2). Every body the BFF reads, from the API or from the browser, is read to the end
// under a time limit and a size cap, so neither a stalled nor an oversized body can hold a handler open.

/** The largest request body the BFF accepts (no operation it serves takes a file). */
export const REQUEST_MAX_BYTES = 1024 * 1024;
/** How long the BFF waits for a request body to arrive. */
export const REQUEST_READ_TIMEOUT_MS = 10_000;

/**
 * Reads a body to the end while `signal` is live. Rejects when the signal aborts (the reader is cancelled); resolves null
 * when the body passes `maxBytes` (also cancelled). Used for upstream answers and, by the handlers, for request bodies.
 */
export async function readBodyWithin(stream: ReadableStream<Uint8Array> | null, signal: AbortSignal, maxBytes: number): Promise<Uint8Array<ArrayBuffer> | null> {
  if (stream === null) return new Uint8Array(0);
  const reader = stream.getReader();
  let onAbort = () => {};
  const aborted = new Promise<never>((_, reject) => {
    onAbort = () => reject(new DOMException('The time limit passed.', 'AbortError'));
    if (signal.aborted) onAbort();
    else signal.addEventListener('abort', onAbort, { once: true });
  });
  aborted.catch(() => {});
  const chunks: Uint8Array[] = [];
  let size = 0;
  try {
    for (;;) {
      const { done, value } = await Promise.race([reader.read(), aborted]);
      if (done) break;
      size += value.byteLength;
      if (size > maxBytes) {
        void reader.cancel().catch(() => {});
        return null;
      }
      chunks.push(value);
    }
  } catch (e) {
    void reader.cancel().catch(() => {});
    throw e;
  } finally {
    signal.removeEventListener('abort', onAbort);
  }
  const out = new Uint8Array(size);
  let at = 0;
  for (const c of chunks) {
    out.set(c, at);
    at += c.byteLength;
  }
  return out;
}

/**
 * The browser's request body, or the BFF problem that ends the request: 413 urn:erp:bff:payload-too-large over
 * REQUEST_MAX_BYTES (a declared Content-Length over it is refused unread), 408 urn:erp:bff:request-timeout when it has not
 * arrived within REQUEST_READ_TIMEOUT_MS.
 */
export async function readRequestBody(request: Request): Promise<{ readonly bytes: Uint8Array<ArrayBuffer> } | { readonly refused: Response }> {
  const declared = request.headers.get('content-length');
  if (declared !== null && /^\d+$/.test(declared.trim()) && Number(declared.trim()) > REQUEST_MAX_BYTES) return { refused: bffProblem(413, 'urn:erp:bff:payload-too-large') };
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), REQUEST_READ_TIMEOUT_MS);
  try {
    const bytes = await readBodyWithin(request.body, controller.signal, REQUEST_MAX_BYTES);
    return bytes === null ? { refused: bffProblem(413, 'urn:erp:bff:payload-too-large') } : { bytes };
  } catch {
    return { refused: controller.signal.aborted ? bffProblem(408, 'urn:erp:bff:request-timeout') : bffProblem(400, 'urn:erp:bff:request-refused') };
  } finally {
    clearTimeout(timer);
  }
}

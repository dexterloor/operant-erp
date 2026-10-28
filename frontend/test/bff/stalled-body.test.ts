// @vitest-environment node
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { keyringFrom, newSessionPayload, sealSession } from '@/lib/bff/server/session';

// Code RED M-1, server half (b3b-c2-c8-code-red.md): the 15 s limit stays live until the upstream body has been read.
// A fake upstream sends its headers and then never finishes the body; every server reader of an API answer must still
// end at 15 s — apiFetch and the proxy with 504 urn:erp:bff:upstream-timeout, the named handlers with their own answer.

const ORIGIN = 'https://localhost:3000';
const KEY = Buffer.from(Array.from({ length: 32 }, (_, i) => i + 1)).toString('base64url');
const ID = '0b7c1f9e-3f4a-4c8e-9d2b-5a6e7f8091a2';

/** Headers now, a body that never ends. */
const stalled = (status = 200, type = 'application/json') =>
  new Response(new ReadableStream<Uint8Array>({ start(c) { c.enqueue(new TextEncoder().encode('{"a":')); } }), { status, headers: { 'content-type': type } });

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date'] });
  vi.stubEnv('ERP_API_BASE_URL', 'http://127.0.0.1:8082');
  vi.stubEnv('APP_PUBLIC_ORIGIN', ORIGIN);
  vi.stubEnv('BFF_SESSION_KEY', `k:${KEY}`);
  vi.stubGlobal('fetch', vi.fn(async () => stalled()));
});
afterEach(() => {
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

async function settlesAt<T>(p: Promise<T>, ms: number): Promise<T> {
  let done = false;
  void p.then(() => (done = true), () => (done = true));
  await vi.advanceTimersByTimeAsync(ms - 1);
  expect(done, `settled before ${ms} ms`).toBe(false);
  await vi.advanceTimersByTimeAsync(1);
  expect(done, `still pending at ${ms} ms`).toBe(true);
  return p;
}

describe('M-1 — a stalled upstream body ends at the server bound', () => {
  it('apiFetch: headers in, body stalled → 504 urn:erp:bff:upstream-timeout at 15 s', async () => {
    vi.resetModules();
    const { apiFetch } = await import('@/lib/bff/server/apiClient');
    const r = await settlesAt(apiFetch('GetCurrentUser', { token: 't' }), 15_000);
    expect(r.status).toBe(504);
    expect((await r.json()).type).toBe('urn:erp:bff:upstream-timeout');
  });

  it('apiFetch: a body that arrives in time is returned whole, status and headers kept', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response('{"a":1}', { status: 201, headers: { 'content-type': 'application/json', location: '/api/v1/x' } })));
    vi.resetModules();
    const { apiFetch } = await import('@/lib/bff/server/apiClient');
    const r = await apiFetch('GetCurrentUser', { token: 't' });
    expect(r.status).toBe(201);
    expect(r.headers.get('location')).toBe('/api/v1/x');
    expect(await r.json()).toEqual({ a: 1 });
  });

  it('the proxy: a stalled body → 504 upstream-timeout at 15 s, never a 200 that hangs', async () => {
    vi.resetModules();
    const { handleApiProxy } = await import('@/lib/bff/server/apiProxy');
    const p = newSessionPayload({ jwt: 'h.p.s', exp: Date.now() / 1000 + 3600, workspaceTyped: 'acme', timeZone: 'UTC' });
    const cookie = `__Host-erp_session=${await sealSession(p, keyringFrom(`k:${KEY}`))}`;
    const req = new Request(`${ORIGIN}/bff/api/users/${ID}`, { headers: { cookie, 'x-erp-session': p.sid, 'sec-fetch-site': 'same-origin', 'sec-fetch-mode': 'cors' } });
    const pending = handleApiProxy(req);
    // Opening the cookie is real WebCrypto work, outside the fake clock: wait (on the real event loop) for the call.
    while ((fetch as unknown as ReturnType<typeof vi.fn>).mock.calls.length === 0) await new Promise((r) => setImmediate(r));
    const r = await settlesAt(pending, 15_000);
    expect(r.status).toBe(504);
    expect((await r.json()).type).toBe('urn:erp:bff:upstream-timeout');
  });

  it('sign-in: a stalled token body ends at 15 s with the generic problem, no cookie', async () => {
    vi.resetModules();
    const { handleSignIn } = await import('@/lib/bff/server/handlers');
    const req = new Request(`${ORIGIN}/bff/session`, {
      method: 'POST',
      headers: { origin: ORIGIN, 'content-type': 'application/json', 'sec-fetch-site': 'same-origin' },
      body: JSON.stringify({ workspace: 'acme', email: 'e@example.com', password: 'pw' }),
    });
    const r = await settlesAt(handleSignIn(req), 15_000);
    expect(r.status).toBe(502);
    expect(r.headers.get('set-cookie')).toBeNull();
  });

  it('redemption: a stalled body ends at 15 s with the generic problem, the cookie untouched', async () => {
    vi.resetModules();
    const { handleResetRedemption } = await import('@/lib/bff/server/handlers');
    const req = new Request(`${ORIGIN}/bff/password-resets/redemption`, {
      method: 'POST',
      headers: { origin: ORIGIN, 'content-type': 'application/json', 'sec-fetch-site': 'same-origin' },
      body: JSON.stringify({ token: 'tok', password: 'a long enough password' }),
    });
    const r = await settlesAt(handleResetRedemption(req), 15_000);
    expect(r.status).toBe(502);
    expect(r.headers.get('set-cookie')).toBeNull();
  });

  it('a server read (first render): a stalled body ends at 15 s as server-error', async () => {
    vi.resetModules();
    const { serverRead } = await import('@/lib/bff/server/reads');
    const { unsealSession } = await import('@/lib/bff/server/session');
    const ring = keyringFrom(`k:${KEY}`);
    const s = (await unsealSession(await sealSession(newSessionPayload({ jwt: 'h.p.s', exp: Date.now() / 1000 + 3600, workspaceTyped: 'w', timeZone: 'UTC' }), ring), ring))!;
    expect(await settlesAt(serverRead(s)('GetUser', { params: { id: ID } }), 15_000)).toEqual({ kind: 'server-error' });
  });
});

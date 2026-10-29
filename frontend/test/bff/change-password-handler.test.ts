// @vitest-environment node
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { keyringFrom, newSessionPayload, sealSession } from '@/lib/bff/server/session';

// POST /bff/me/password (c10-design.md "Revision 1 — FINAL" B.1, B.7 H-1…H-11; c10-final-red L-3, L-6;
// b4-architecture.md Revision 6 A-12). Every refusal before the API call makes none; the session is checked before the
// body is read; the cookie is deleted only when the change committed or the cookie itself is dead.

const ORIGIN = 'https://localhost:3000';
const KEY = Buffer.from(Array.from({ length: 32 }, (_, i) => (i * 11 + 3) % 256)).toString('base64url');
const JWT = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ5In0.Y2hhbmdlLXBhc3N3b3Jk';
const CURRENT = 'sentinel-current-7Qx';
const NEXT = 'sentinel-new-password-9Zk';

let fetchSpy: ReturnType<typeof vi.fn>;
function stubApi(answer: () => Response) {
  fetchSpy = vi.fn(async () => answer());
  vi.stubGlobal('fetch', fetchSpy);
}
async function handler() {
  vi.resetModules();
  return (await import('@/lib/bff/server/handlers')).handleChangePassword;
}

let cookie = '';
let sid = '';
beforeEach(async () => {
  vi.stubEnv('ERP_API_BASE_URL', 'http://127.0.0.1:8082');
  vi.stubEnv('APP_PUBLIC_ORIGIN', ORIGIN);
  vi.stubEnv('BFF_SESSION_KEY', `k:${KEY}`);
  const p = newSessionPayload({ jwt: JWT, exp: Date.now() / 1000 + 3600, workspaceTyped: 'acme', timeZone: 'UTC' });
  sid = p.sid;
  cookie = `__Host-erp_session=${await sealSession(p, keyringFrom(`k:${KEY}`))}`;
});
afterEach(() => {
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
});

const BODY = { current_password: CURRENT, new_password: NEXT };

function req(init: { headers?: Record<string, string | undefined>; body?: unknown } = {}) {
  const h: Record<string, string> = {
    origin: ORIGIN,
    'content-type': 'application/json',
    'sec-fetch-site': 'same-origin',
    'sec-fetch-mode': 'cors',
    cookie,
    'x-erp-session': sid,
  };
  for (const [k, v] of Object.entries(init.headers ?? {})) {
    if (v === undefined) delete h[k];
    else h[k] = v;
  }
  const body = init.body === undefined ? BODY : init.body;
  return new Request(`${ORIGIN}/bff/me/password`, { method: 'POST', headers: h, body: typeof body === 'string' ? body : JSON.stringify(body) });
}

const json = (status: number, body: unknown) =>
  new Response(JSON.stringify(body), { status, headers: { 'content-type': status < 300 ? 'application/json' : 'application/problem+json' } });
const CLEARED = /^__Host-erp_session=;.*Max-Age=0/;

describe('H-1 — the Origin / JSON wrapper runs first: 403 / 415, zero API calls', () => {
  it.each([
    ['cross-origin', { origin: 'https://evil.example' }, 403],
    ['no Origin', { origin: undefined }, 403],
    ['cross-site fetch', { 'sec-fetch-site': 'cross-site' }, 403],
    ['not JSON', { 'content-type': 'text/plain' }, 415],
  ])('%s → %i', async (_n, headers, status) => {
    stubApi(() => json(200, { changed_at: 'x' }));
    const r = await (await handler())(req({ headers }));
    expect(r.status).toBe(status);
    expect(r.headers.get('set-cookie')).toBeNull();
    expect(fetchSpy).not.toHaveBeenCalled();
  });
});

describe('H-2 — X-ERP-Session is required and a navigation is refused: 403, zero API calls', () => {
  it.each([
    ['no X-ERP-Session', { 'x-erp-session': undefined }],
    ['empty X-ERP-Session', { 'x-erp-session': '' }],
    ['Sec-Fetch-Mode: navigate', { 'sec-fetch-mode': 'navigate' }],
  ])('%s', async (_n, headers) => {
    stubApi(() => json(200, { changed_at: 'x' }));
    const r = await (await handler())(req({ headers }));
    expect(r.status).toBe(403);
    expect((await r.json()).type).toBe('urn:erp:bff:request-refused');
    expect(fetchSpy).not.toHaveBeenCalled();
  });
});

describe('H-3 — the page acts only for the person it was rendered for', () => {
  it('X-ERP-Session ≠ the cookie sid → 409 session-changed, zero API calls, cookie untouched', async () => {
    stubApi(() => json(200, { changed_at: 'x' }));
    const r = await (await handler())(req({ headers: { 'x-erp-session': 'another-sid' } }));
    expect(r.status).toBe(409);
    expect((await r.json()).type).toBe('urn:erp:bff:session-changed');
    expect(r.headers.get('set-cookie')).toBeNull();
    expect(fetchSpy).not.toHaveBeenCalled();
  });

  it('L-3: with a wrong sid AND an invalid body the answer is still 409 (the session is checked before the body)', async () => {
    stubApi(() => json(200, { changed_at: 'x' }));
    const r = await (await handler())(req({ headers: { 'x-erp-session': 'another-sid' }, body: 'not json' }));
    expect(r.status).toBe(409);
  });
});

describe('H-4 — the cookie', () => {
  it('none → 401 session-ended, no Set-Cookie, zero API calls', async () => {
    stubApi(() => json(200, { changed_at: 'x' }));
    const r = await (await handler())(req({ headers: { cookie: undefined } }));
    expect(r.status).toBe(401);
    expect((await r.json()).type).toBe('urn:erp:bff:session-ended');
    expect(r.headers.get('set-cookie')).toBeNull();
    expect(fetchSpy).not.toHaveBeenCalled();
  });

  it('L-3: no cookie AND an invalid body → still 401', async () => {
    stubApi(() => json(200, { changed_at: 'x' }));
    const r = await (await handler())(req({ headers: { cookie: undefined }, body: '[]' }));
    expect(r.status).toBe(401);
  });

  it('one that does not open → 401 session-ended and the cookie deleted', async () => {
    stubApi(() => json(200, { changed_at: 'x' }));
    const r = await (await handler())(req({ headers: { cookie: '__Host-erp_session=garbage' } }));
    expect(r.status).toBe(401);
    expect((await r.json()).type).toBe('urn:erp:bff:session-ended');
    expect(r.headers.get('set-cookie')).toMatch(CLEARED);
    expect(fetchSpy).not.toHaveBeenCalled();
  });

  it('one past its sealed exp → 401 session-ended and the cookie deleted', async () => {
    const p = newSessionPayload({ jwt: JWT, exp: Date.now() / 1000 - 1, workspaceTyped: 'a', timeZone: 'UTC' });
    const old = `__Host-erp_session=${await sealSession(p, keyringFrom(`k:${KEY}`))}`;
    stubApi(() => json(200, { changed_at: 'x' }));
    const r = await (await handler())(req({ headers: { cookie: old, 'x-erp-session': p.sid } }));
    expect(r.status).toBe(401);
    expect(r.headers.get('set-cookie')).toMatch(CLEARED);
    expect(fetchSpy).not.toHaveBeenCalled();
  });
});

describe('H-4b — a session key that cannot be read', () => {
  it.each([
    ['unset', undefined],
    ['malformed', 'not a key'],
  ])('BFF_SESSION_KEY %s → 502 server-error, no Set-Cookie, zero API calls', async (_n, value) => {
    vi.stubEnv('BFF_SESSION_KEY', value as string);
    stubApi(() => json(200, { changed_at: 'x' }));
    const r = await (await handler())(req());
    expect(r.status).toBe(502);
    expect((await r.json()).type).toBe('urn:erp:bff:server-error');
    expect(r.headers.get('set-cookie')).toBeNull();
    expect(fetchSpy).not.toHaveBeenCalled();
  });
});

describe('H-5 — the body', () => {
  it.each([
    ['not JSON', 'nope'],
    ['an array', '[]'],
    ['no new_password', { current_password: CURRENT }],
    ['a number', { current_password: CURRENT, new_password: 5 }],
    ['null current', { current_password: null, new_password: NEXT }],
  ])('%s → 422 request-invalid ("not sent"), zero API calls', async (_n, body) => {
    stubApi(() => json(200, { changed_at: 'x' }));
    const r = await (await handler())(req({ body }));
    expect(r.status).toBe(422);
    expect((await r.json()).type).toBe('urn:erp:bff:request-invalid');
    expect(fetchSpy).not.toHaveBeenCalled();
  });

  it('no length or emptiness rule here: a 5000-byte new password and an empty one are forwarded (the API says "too long" / "must not be empty")', async () => {
    stubApi(() => json(422, { type: 'urn:erp:problem:validation-failed', errors: [{ pointer: '/new_password', code: 'new_password_too_long', message: 'The new password is too long.' }] }));
    const h = await handler();
    for (const next of ['x'.repeat(5000), '']) {
      const r = await h(req({ body: { current_password: CURRENT, new_password: next } }));
      expect(r.status).toBe(422);
      expect(JSON.parse(fetchSpy.mock.calls.at(-1)![1].body).new_password).toBe(next);
    }
    expect(fetchSpy).toHaveBeenCalledTimes(2);
  });
});

describe('H-6 — 200: the change committed', () => {
  it('answers {changed_at}, deletes this browser\'s cookie, sent the session bearer and exactly the two fields', async () => {
    stubApi(() => json(200, { changed_at: '2026-10-29T06:00:00+00:00', extra: 'x' }));
    const r = await (await handler())(req({ body: { ...BODY, extra: 'dropped' } }));
    expect(r.status).toBe(200);
    expect(await r.json()).toEqual({ changed_at: '2026-10-29T06:00:00+00:00' });
    expect(r.headers.get('set-cookie')).toMatch(CLEARED);
    expect(r.headers.get('cache-control')).toBe('no-store, private');
    expect(fetchSpy).toHaveBeenCalledTimes(1);
    const [url, init] = fetchSpy.mock.calls[0]!;
    expect(String(url)).toBe('http://127.0.0.1:8082/api/v1/me/password');
    expect(init.method).toBe('POST');
    expect(new Headers(init.headers).get('authorization')).toBe(`Bearer ${JWT}`);
    expect(JSON.parse(init.body)).toEqual(BODY);
  });
});

describe('H-7 — a 200 the page cannot read still committed: 502 and the cookie deleted (L-6)', () => {
  it.each([
    ['no changed_at', () => json(200, {})],
    ['a non-string changed_at', () => json(200, { changed_at: 5 })],
    ['not JSON', () => new Response('<html>', { status: 200, headers: { 'content-type': 'text/html' } })],
  ])('%s', async (_n, answer) => {
    stubApi(answer);
    const r = await (await handler())(req());
    expect(r.status).toBe(502);
    expect((await r.json()).type).toBe('urn:erp:bff:server-error');
    expect(r.headers.get('set-cookie')).toMatch(CLEARED);
  });
});

describe('H-8 — API 401: the session ended, the cookie is NOT deleted (a late 401 must not wipe a newer session)', () => {
  it('authentication-failed → 401 urn:erp:bff:session-ended, no Set-Cookie', async () => {
    stubApi(() => json(401, { type: 'urn:erp:problem:authentication-failed', detail: 'internal' }));
    const r = await (await handler())(req());
    expect(r.status).toBe(401);
    const text = await r.text();
    expect(JSON.parse(text).type).toBe('urn:erp:bff:session-ended');
    expect(text).not.toContain('internal');
    expect(r.headers.get('set-cookie')).toBeNull();
  });

  it('a 401 of another type → the generic 502', async () => {
    stubApi(() => json(401, { type: 'urn:erp:problem:x' }));
    const r = await (await handler())(req());
    expect(r.status).toBe(502);
    expect(r.headers.get('set-cookie')).toBeNull();
  });
});

describe('H-9 — 422 and 429: the API answered, nothing changed, the cookie untouched', () => {
  it("422 → the field errors with the server's sentences", async () => {
    const errors = [
      { pointer: '/current_password', code: 'current_password_invalid', message: 'The current password is not correct.' },
      { pointer: '/new_password', code: 'new_password_too_short', message: 'The new password must be at least 12 characters.' },
    ];
    stubApi(() => json(422, { type: 'urn:erp:problem:validation-failed', detail: 'internal', errors }));
    const r = await (await handler())(req());
    expect(r.status).toBe(422);
    const b = await r.json();
    expect(b.type).toBe('urn:erp:problem:validation-failed');
    expect(b.errors).toEqual(errors);
    expect(JSON.stringify(b)).not.toContain('internal');
    expect(r.headers.get('set-cookie')).toBeNull();
  });

  it('429 too-many-attempts → 429', async () => {
    stubApi(() => json(429, { type: 'urn:erp:problem:too-many-attempts' }));
    const r = await (await handler())(req());
    expect(r.status).toBe(429);
    expect((await r.json()).type).toBe('urn:erp:problem:too-many-attempts');
    expect(r.headers.get('set-cookie')).toBeNull();
  });
});

describe('H-10 — anything else is "outcome unknown": the generic 502, the cookie kept', () => {
  it.each([
    ['504 upstream-timeout (the BFF gave up)', () => json(504, { type: 'urn:erp:bff:upstream-timeout' })],
    ['502', () => json(502, { type: 'urn:erp:bff:server-error' })],
    ['500', () => json(500, { type: 'urn:erp:problem:x', trace: 'secret' })],
    ['an unlisted 403', () => json(403, { type: 'urn:erp:problem:forbidden' })],
    ['a 429 of another type', () => json(429, { type: 'urn:erp:problem:sign-in-paused' })],
  ])('%s → 502, no Set-Cookie', async (_n, answer) => {
    stubApi(answer);
    const r = await (await handler())(req());
    expect(r.status).toBe(502);
    const text = await r.text();
    expect(JSON.parse(text).type).toBe('urn:erp:bff:server-error');
    expect(text).not.toContain('secret');
    expect(r.headers.get('set-cookie')).toBeNull();
  });

  it('the network failing → the generic 502, the cookie kept', async () => {
    fetchSpy = vi.fn(async () => {
      throw new TypeError('fetch failed');
    });
    vi.stubGlobal('fetch', fetchSpy);
    const r = await (await handler())(req());
    expect(r.status).toBe(502);
    expect(r.headers.get('set-cookie')).toBeNull();
  });
});

describe('H-11 — nothing is logged', () => {
  it('no console call carries either password, whatever the answer', async () => {
    const spies = (['log', 'info', 'warn', 'error', 'debug'] as const).map((m) => vi.spyOn(console, m).mockImplementation(() => {}));
    for (const a of [json(200, { changed_at: 'x' }), json(422, { type: 'urn:erp:problem:validation-failed', errors: [] }), json(500, {}), json(401, { type: 'urn:erp:problem:authentication-failed' })]) {
      stubApi(() => a.clone());
      await (await handler())(req());
    }
    for (const s of spies) {
      expect(s).not.toHaveBeenCalled();
      s.mockRestore();
    }
  });
});

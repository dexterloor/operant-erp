// @vitest-environment node
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { allOperations, proxyAllowlist } from '@/lib/bff/server/surface';
import { keyringFrom, newSessionPayload, sealSession } from '@/lib/bff/server/session';

// The authenticated proxy, /bff/api/[...path] (b3b-design.md §5 items 9–13; bff-auth-note.md R1-D4, R1-D5, R1-D6,
// R1-§6.1, R2-1): T-7, T-11, T-15, T-16, the L-1 header items, L-2, and C-4's "absent template → 404, zero calls".

const ORIGIN = 'https://localhost:3000';
const KEY = Buffer.from(Array.from({ length: 32 }, (_, i) => (i * 7 + 9) % 256)).toString('base64url');
const ID = '0b7c1f9e-3f4a-4c8e-9d2b-5a6e7f8091a2';
const JWT = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ4In0.cHJveHktc2lnbmF0dXJl';

let fetchSpy: ReturnType<typeof vi.fn>;
function stubApi(answer: () => Response) {
  fetchSpy = vi.fn(async () => answer());
  vi.stubGlobal('fetch', fetchSpy);
}

async function proxy() {
  vi.resetModules();
  return (await import('@/lib/bff/server/apiProxy')).handleApiProxy;
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

function req(path: string, init: { method?: string; headers?: Record<string, string | undefined>; body?: string } = {}) {
  const method = init.method ?? 'GET';
  const h: Record<string, string> = { cookie, 'x-erp-session': sid, 'sec-fetch-site': 'same-origin', 'sec-fetch-mode': 'cors', accept: 'application/json' };
  if (method !== 'GET') {
    h.origin = ORIGIN;
    h['content-type'] = 'application/json';
  }
  for (const [k, v] of Object.entries(init.headers ?? {})) {
    if (v === undefined) delete h[k];
    else h[k] = v;
  }
  return new Request(`${ORIGIN}${path}`, { method, headers: h, body: init.body });
}

const json = (status: number, body: unknown, headers: Record<string, string> = {}) =>
  new Response(JSON.stringify(body), { status, headers: { 'content-type': status < 300 ? 'application/json' : 'application/problem+json', ...headers } });

describe('the proxy forwards an allowed call', () => {
  it('GET: the session bearer, the template from the map, cache no-store; answers status, type and body; no-store, private', async () => {
    stubApi(() => json(200, { id: ID }, { 'set-cookie': 'x=1', 'www-authenticate': 'Bearer', 'x-debug-token': 'abc' }));
    const r = await (await proxy())(req(`/bff/api/users/${ID}?x=1&q=a b`));
    expect(r.status).toBe(200);
    expect(await r.json()).toEqual({ id: ID });
    expect(r.headers.get('cache-control')).toBe('no-store, private');
    expect(r.headers.get('content-type')).toBe('application/json');
    expect(r.headers.get('set-cookie')).toBeNull();
    expect(r.headers.get('www-authenticate')).toBeNull();
    expect(r.headers.get('x-debug-token')).toBeNull();
    const [url, init] = fetchSpy.mock.calls[0]!;
    expect(String(url)).toBe(`http://127.0.0.1:8082/api/v1/users/${ID}?x=1&q=a+b`);
    expect(new Headers(init.headers).get('authorization')).toBe(`Bearer ${JWT}`);
    expect(init.cache).toBe('no-store');
  });

  it('a write forwards only Accept, Content-Type, Idempotency-Key and the body; never the browser Cookie or forwarding headers', async () => {
    stubApi(() => json(201, { id: ID }, { location: `/api/v1/users/${ID}/roles/${ID}`, 'content-location': '/elsewhere' }));
    const r = await (await proxy())(
      req(`/bff/api/users/${ID}/roles`, {
        method: 'POST',
        body: JSON.stringify({ role_id: ID }),
        headers: { 'idempotency-key': 'k-1', authorization: 'Bearer evil', 'x-forwarded-for': '1.2.3.4', 'x-real-ip': '1.2.3.4' },
      }),
    );
    expect(r.status).toBe(201);
    expect(r.headers.get('location')).toBe(`/bff/api/users/${ID}/roles/${ID}`);
    expect(r.headers.get('content-location')).toBeNull();
    const init = fetchSpy.mock.calls[0]![1];
    const h = new Headers(init.headers);
    expect([...h.keys()].sort()).toEqual(['accept', 'authorization', 'content-type', 'idempotency-key']);
    expect(h.get('authorization')).toBe(`Bearer ${JWT}`);
    expect(new TextDecoder().decode(init.body)).toBe(JSON.stringify({ role_id: ID }));
  });

  it('an API problem below 500 passes through as a problem document', async () => {
    stubApi(() => json(409, { type: 'urn:erp:problem:business-rule-violated', rule: 'user_role.already_held', message: 'Already has it.' }));
    const r = await (await proxy())(req(`/bff/api/users/${ID}/roles`, { method: 'POST', body: '{}', headers: { 'idempotency-key': 'k' } }));
    expect(r.status).toBe(409);
    expect(r.headers.get('content-type')).toBe('application/problem+json');
    expect((await r.json()).rule).toBe('user_role.already_held');
  });
});

describe('L-2 and R1-D5 — upstream bodies the browser never sees', () => {
  it.each([
    [json(500, { type: 'urn:erp:problem:x', trace: 'secret-trace', detail: 'secret' }), 502],
    [json(503, { type: 'urn:erp:problem:ai-capacity-exhausted', detail: 'secret' }), 502],
    [new Response('<html>secret</html>', { status: 404, headers: { 'content-type': 'text/html' } }), 502],
    [new Response('secret', { status: 400, headers: { 'content-type': 'text/plain' } }), 502],
  ])('upstream %# → the generic BFF problem', async (answer, status) => {
    stubApi(() => answer);
    const r = await (await proxy())(req(`/bff/api/users/${ID}`));
    expect(r.status).toBe(status);
    const text = await r.text();
    expect(JSON.parse(text).type).toBe('urn:erp:bff:server-error');
    expect(text).not.toContain('secret');
  });
});

describe('T-7 — 401 routing through the proxy', () => {
  it('API 401 → 401 urn:erp:bff:session-ended, never the API body, and NO Set-Cookie (the cookie is not deleted)', async () => {
    stubApi(() => json(401, { type: 'urn:erp:problem:authentication-failed', detail: 'internal' }, { 'www-authenticate': 'Bearer' }));
    const r = await (await proxy())(req(`/bff/api/users/${ID}`));
    expect(r.status).toBe(401);
    expect((await r.json()).type).toBe('urn:erp:bff:session-ended');
    expect(r.headers.get('set-cookie')).toBeNull();
    expect(r.headers.get('www-authenticate')).toBeNull();
  });

  it('API 403 → passed through; the cookie is untouched', async () => {
    stubApi(() => json(403, { type: 'urn:erp:problem:permission-denied' }));
    const r = await (await proxy())(req(`/bff/api/users/${ID}`));
    expect(r.status).toBe(403);
    expect(r.headers.get('set-cookie')).toBeNull();
  });

  it.each([
    ['no cookie', { cookie: undefined }, false],
    ['a cookie that does not open', { cookie: '__Host-erp_session=garbage' }, true],
  ])('%s → 401 session-ended with zero API calls', async (_n, headers, deletes) => {
    stubApi(() => json(200, {}));
    const r = await (await proxy())(req(`/bff/api/users/${ID}`, { headers }));
    expect(r.status).toBe(401);
    expect((await r.json()).type).toBe('urn:erp:bff:session-ended');
    expect(fetchSpy).not.toHaveBeenCalled();
    if (deletes) expect(r.headers.get('set-cookie')).toMatch(/^__Host-erp_session=;.*Max-Age=0/);
    else expect(r.headers.get('set-cookie')).toBeNull();
  });

  it('a cookie past its sealed exp → 401 session-ended, the cookie deleted, zero API calls', async () => {
    const p = newSessionPayload({ jwt: JWT, exp: Date.now() / 1000 - 1, workspaceTyped: 'a', timeZone: 'UTC' });
    const old = `__Host-erp_session=${await sealSession(p, keyringFrom(`k:${KEY}`))}`;
    stubApi(() => json(200, {}));
    const r = await (await proxy())(req(`/bff/api/users/${ID}`, { headers: { cookie: old, 'x-erp-session': p.sid } }));
    expect(r.status).toBe(401);
    expect(r.headers.get('set-cookie')).toMatch(/Max-Age=0/);
    expect(fetchSpy).not.toHaveBeenCalled();
  });

  it('a call to an /auth/* path is refused 404 with zero API calls', async () => {
    stubApi(() => json(200, { access_token: 'x' }));
    for (const p of ['/bff/api/auth/token', '/bff/api/auth/password-resets', '/bff/api/auth/password-resets/redemption']) {
      const r = await (await proxy())(req(p, { method: 'POST', body: '{}' }));
      expect(r.status, p).toBe(404);
      expect((await r.json()).type).toBe('urn:erp:bff:not-routable');
    }
    expect(fetchSpy).not.toHaveBeenCalled();
  });

  it('ChangePassword (POST /me/password) is not reachable through the proxy (L-7: a named handler owns it)', async () => {
    stubApi(() => json(200, {}));
    const r = await (await proxy())(req('/bff/api/me/password', { method: 'POST', body: '{}' }));
    expect(r.status).toBe(404);
    expect(fetchSpy).not.toHaveBeenCalled();
  });
});

describe('T-7 / T-16 / R1-D4 item 5 — the session header and the request shape', () => {
  it.each([
    ['no X-ERP-Session', { 'x-erp-session': undefined }, 403, 'urn:erp:bff:request-refused'],
    ['Sec-Fetch-Mode navigate', { 'sec-fetch-mode': 'navigate' }, 403, 'urn:erp:bff:request-refused'],
    ['Sec-Fetch-Site cross-site', { 'sec-fetch-site': 'cross-site' }, 403, 'urn:erp:bff:request-refused'],
    ['x-middleware-subrequest', { 'x-middleware-subrequest': 'middleware' }, 404, 'urn:erp:bff:not-routable'],
    ['another sign-in\'s sid (T-16)', { 'x-erp-session': 'someone-else' }, 409, 'urn:erp:bff:session-changed'],
  ])('GET with %s → %i, zero API calls', async (_n, headers, status, type) => {
    stubApi(() => json(200, {}));
    const r = await (await proxy())(req(`/bff/api/users/${ID}`, { headers }));
    expect(r.status).toBe(status);
    expect((await r.json()).type).toBe(type);
    expect(fetchSpy).not.toHaveBeenCalled();
  });

  it.each([
    ['foreign Origin', { origin: 'https://evil.example' }, 403],
    ['no Origin', { origin: undefined }, 403],
    ['text/plain', { 'content-type': 'text/plain' }, 415],
  ])('a write with %s → %i, zero API calls', async (_n, headers, status) => {
    stubApi(() => json(200, {}));
    const r = await (await proxy())(req(`/bff/api/users/${ID}/roles`, { method: 'POST', body: '{}', headers }));
    expect(r.status).toBe(status);
    expect(fetchSpy).not.toHaveBeenCalled();
  });
});

describe('T-15 — paths that are not exactly an allowed template are refused 404 with zero calls; the API host never changes', () => {
  const attacks = [
    '/bff/api/%2e%2e/auth/token',
    '/bff/api/auth%2Ftoken',
    '/bff/api/%61uth/token',
    '/bff/api/..%2F..%2Fx',
    '/bff/api//evil.example/x',
    '/bff/api/@evil.example',
    '/bff/api/%5Cauth%5Ctoken',
    '/bff/api/users/..%2F..%2Fauth%2Ftoken',
    '/bff/api/users/%2e%2e',
    '/bff/api/users/not-a-uuid',
    `/bff/api/users/${ID.toUpperCase()}`,
    `/bff/api/users/${ID}/`,
    '/bff/api/users/a:b',
    '/bff/api/nothing-here',
    '/bff/api',
    '/bff/api/',
  ];
  it.each(attacks)('%s', async (p) => {
    stubApi(() => json(200, {}));
    const r = await (await proxy())(req(p));
    expect(r.status).toBe(404);
    expect(fetchSpy).not.toHaveBeenCalled();
  });

  it('a method the template does not take → 404, zero calls', async () => {
    stubApi(() => json(200, {}));
    const r = await (await proxy())(req(`/bff/api/users/${ID}`, { method: 'PUT', body: '{}' }));
    expect(r.status).toBe(404);
    expect(fetchSpy).not.toHaveBeenCalled();
  });

  it('C-4: every OPERATIONS template absent from PROXY_ALLOWLIST is refused with zero calls', async () => {
    const allowed = new Set(proxyAllowlist().map((r) => `${r.method} ${r.template}`));
    const absent = Object.values(allOperations()).filter((o) => !allowed.has(`${o.method} ${o.template}`));
    expect(absent.length).toBeGreaterThan(0);
    const handle = await proxy();
    for (const o of absent) {
      stubApi(() => json(200, {}));
      const p = '/bff/api/' + o.template.replace(/^\/api\/v1\//, '').replace(/\{[^}]+\}/g, ID);
      const r = await handle(req(p, o.method === 'GET' ? {} : { method: o.method, body: '{}' }));
      expect(r.status, `${o.method} ${o.template}`).toBe(404);
      expect(fetchSpy).not.toHaveBeenCalled();
    }
  });

  it('every allowed template reaches the API exactly once on the configured host (positive control)', async () => {
    const handle = await proxy();
    for (const route of proxyAllowlist()) {
      stubApi(() => json(200, {}));
      const p = '/bff/api/' + route.template.replace(/^\/api\/v1\//, '').replace(/\{[^}]+\}/g, ID);
      const r = await handle(req(p, route.method === 'GET' ? {} : { method: route.method, body: '{}' }));
      expect(r.status, `${route.method} ${route.template}`).toBe(200);
      expect(fetchSpy).toHaveBeenCalledTimes(1);
      expect(new URL(String(fetchSpy.mock.calls[0]![0])).host).toBe('127.0.0.1:8082');
    }
  });
});

describe('T-11 / T-3 — no caching, no logging', () => {
  it('every proxy answer, success or refusal, carries Cache-Control: no-store, private; nothing is logged', async () => {
    const spies = (['log', 'info', 'warn', 'error', 'debug'] as const).map((m) => vi.spyOn(console, m).mockImplementation(() => {}));
    const handle = await proxy();
    for (const [answer, path, headers] of [
      [json(200, {}), `/bff/api/users/${ID}`, {}],
      [json(500, { trace: 'x' }), `/bff/api/users/${ID}`, {}],
      [json(200, {}), '/bff/api/auth/token', {}],
      [json(200, {}), `/bff/api/users/${ID}`, { 'x-erp-session': undefined }],
      [json(200, {}), `/bff/api/users/${ID}`, { cookie: undefined }],
    ] as const) {
      stubApi(() => answer.clone());
      const r = await handle(req(path, { headers: { ...headers } }));
      expect(r.headers.get('cache-control'), path).toBe('no-store, private');
    }
    for (const s of spies) {
      expect(s).not.toHaveBeenCalled();
      s.mockRestore();
    }
  });
});

const problemJson = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/problem+json' } });

describe('code RED L-1 — an unreadable session key is the BFF problem, never a raw 500', () => {
  it.each([
    ['unset', undefined],
    ['malformed', 'not a key'],
  ])('BFF_SESSION_KEY %s → 502 urn:erp:bff:server-error, zero API calls', async (_n, value) => {
    vi.stubEnv('BFF_SESSION_KEY', value as string);
    stubApi(() => json(200, {}));
    const r = await (await proxy())(req(`/bff/api/users/${ID}`));
    expect(r.status).toBe(502);
    expect((await r.json()).type).toBe('urn:erp:bff:server-error');
    expect(fetchSpy).not.toHaveBeenCalled();
  });
});

describe('code RED L-2 — the request body has a size cap and a read time limit', () => {
  it('a body over 1 MiB → 413 urn:erp:bff:payload-too-large, zero API calls', async () => {
    stubApi(() => json(201, {}));
    const r = await (await proxy())(req(`/bff/api/users/${ID}/roles`, { method: 'POST', body: 'x'.repeat(1024 * 1024 + 1), headers: { 'idempotency-key': 'k' } }));
    expect(r.status).toBe(413);
    expect((await r.json()).type).toBe('urn:erp:bff:payload-too-large');
    expect(fetchSpy).not.toHaveBeenCalled();
  });

  it('a declared Content-Length over the cap → 413 without reading, zero API calls', async () => {
    stubApi(() => json(201, {}));
    const r = await (await proxy())(req(`/bff/api/users/${ID}/roles`, { method: 'POST', body: '{}', headers: { 'idempotency-key': 'k', 'content-length': String(1024 * 1024 + 1) } }));
    expect(r.status).toBe(413);
    expect(fetchSpy).not.toHaveBeenCalled();
  });

  it('a body of exactly 1 MiB is forwarded whole', async () => {
    stubApi(() => json(201, {}));
    const body = 'x'.repeat(1024 * 1024);
    const r = await (await proxy())(req(`/bff/api/users/${ID}/roles`, { method: 'POST', body, headers: { 'idempotency-key': 'k' } }));
    expect(r.status).toBe(201);
    expect(new TextDecoder().decode(fetchSpy.mock.calls[0]![1].body)).toBe(body);
  });

  it('a body that never finishes → 408 urn:erp:bff:request-timeout at 10 s, zero API calls', async () => {
    stubApi(() => json(201, {}));
    const handle = await proxy();
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    try {
      const stalled = new ReadableStream<Uint8Array>({ start(c) { c.enqueue(new TextEncoder().encode('{"a":')); } });
      const base = req(`/bff/api/users/${ID}/roles`, { method: 'POST', headers: { 'idempotency-key': 'k' } });
      const r0 = new Request(base.url, { method: 'POST', headers: base.headers, body: stalled, duplex: 'half' } as RequestInit);
      const p = handle(r0);
      let done = false;
      void p.then(() => (done = true));
      // Opening the cookie is real WebCrypto work, outside the fake clock.
      for (let i = 0; i < 50; i++) await new Promise((res) => setImmediate(res));
      await vi.advanceTimersByTimeAsync(9_999);
      expect(done).toBe(false);
      await vi.advanceTimersByTimeAsync(1);
      const r = await p;
      expect(r.status).toBe(408);
      expect((await r.json()).type).toBe('urn:erp:bff:request-timeout');
      expect(fetchSpy).not.toHaveBeenCalled();
    } finally {
      vi.useRealTimers();
    }
  });
});

describe('code RED L-3 — X-Content-Type-Options: nosniff on every proxy answer', () => {
  it.each([
    ['a 200', () => json(200, { id: ID }), `/bff/api/users/${ID}`, {}],
    ['an API problem', () => problemJson(409, { type: 'urn:erp:problem:x' }), `/bff/api/users/${ID}`, {}],
    ['an API 401', () => problemJson(401, { type: 'urn:erp:problem:authentication-failed' }), `/bff/api/users/${ID}`, {}],
    ['an upstream 500', () => problemJson(500, { type: 'x' }), `/bff/api/users/${ID}`, {}],
    ['a refusal (not routable)', () => json(200, {}), '/bff/api/auth/token', {}],
    ['a refusal (no session header)', () => json(200, {}), `/bff/api/users/${ID}`, { 'x-erp-session': undefined }],
  ] as const)('%s', async (_n, answer, path, headers) => {
    stubApi(answer);
    const r = await (await proxy())(req(path, { headers }));
    expect(r.headers.get('x-content-type-options')).toBe('nosniff');
  });
});

describe('code RED L-4 — status classes the proxy never relays', () => {
  it('an upstream 205 → 205 with no body (no crash)', async () => {
    stubApi(() => new Response(null, { status: 205 }));
    const r = await (await proxy())(req(`/bff/api/users/${ID}`));
    expect(r.status).toBe(205);
    expect(await r.text()).toBe('');
  });

  it.each([
    ['302 with a problem body', () => new Response(JSON.stringify({ type: 'urn:erp:problem:x' }), { status: 302, headers: { 'content-type': 'application/problem+json', location: '/api/v1/users' } })],
    ['304', () => new Response(null, { status: 304 })],
    ['307 with a problem body', () => problemJson(307, { type: 'urn:erp:problem:x' })],
  ])('an upstream %s → 502, never a redirect the browser would follow', async (_n, answer) => {
    stubApi(answer);
    const r = await (await proxy())(req(`/bff/api/users/${ID}`));
    expect(r.status).toBe(502);
    expect(r.headers.get('location')).toBeNull();
    expect((await r.json()).type).toBe('urn:erp:bff:server-error');
  });

  it('an upstream 1xx → 502', async () => {
    stubApi(() => ({ status: 103, headers: new Headers(), body: null }) as unknown as Response);
    const r = await (await proxy())(req(`/bff/api/users/${ID}`));
    expect(r.status).toBe(502);
  });
});

describe('code RED L-6 — Location on the configured API origin is rewritten, whatever its form', () => {
  it.each([
    ['relative', `/api/v1/users/${ID}/roles/${ID}`, `/bff/api/users/${ID}/roles/${ID}`],
    ['absolute, on the API origin', `http://127.0.0.1:8082/api/v1/users/${ID}/roles/${ID}`, `/bff/api/users/${ID}/roles/${ID}`],
    ['absolute, on the API origin, with a query', `http://127.0.0.1:8082/api/v1/users?x=1`, `/bff/api/users?x=1`],
    ['absolute, another origin', `http://evil.example/api/v1/users/${ID}`, null],
    ['absolute, another port', `http://127.0.0.1:9999/api/v1/users/${ID}`, null],
    ['absolute, with credentials', `http://u:p@127.0.0.1:8082/api/v1/users/${ID}`, null],
    ['absolute, an auth path', 'http://127.0.0.1:8082/api/v1/auth/token', null],
    ['relative, an auth path', '/api/v1/auth/token', null],
    ['scheme-relative', `//evil.example/api/v1/users/${ID}`, null],
    ['outside /api/v1/', 'http://127.0.0.1:8082/other', null],
  ])('%s', async (_n, location, expected) => {
    stubApi(() => json(201, { id: ID }, { location }));
    const r = await (await proxy())(req(`/bff/api/users/${ID}/roles`, { method: 'POST', body: '{}', headers: { 'idempotency-key': 'k' } }));
    expect(r.status).toBe(201);
    expect(r.headers.get('location')).toBe(expected);
  });
});

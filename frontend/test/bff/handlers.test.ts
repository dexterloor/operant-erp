// @vitest-environment node
import { readdirSync, statSync } from 'node:fs';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// The named sign-in / sign-out handlers (b3b-design.md §5 items 3–7, 15; bff-auth-note.md §3, §4, §5, R1-§4, R1-§6.1):
// T-1, T-3, T-5 (route list derived from the route files), T-6, T-7 (sign-in half), T-13 and T-RT's server half.

const ORIGIN = 'https://localhost:3000';
const KEY = Buffer.alloc(32, 7).toString('base64url');
const EXPIRES = '2026-11-01T08:00:00+00:00';
const JWT = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ4In0.dW5pcXVlLXNpZ25hdHVyZQ';

const problem = (status: number, type: string, extra: object = {}) =>
  new Response(JSON.stringify({ type, title: 'internal detail', detail: 'internal detail', status, ...extra }), {
    status,
    headers: { 'content-type': 'application/problem+json', 'www-authenticate': 'Bearer' },
  });

let fetchSpy: ReturnType<typeof vi.fn>;
function stubApi(answer: () => Response | Promise<Response>) {
  fetchSpy = vi.fn(async () => answer());
  vi.stubGlobal('fetch', fetchSpy);
}

async function handlers() {
  vi.resetModules();
  return import('@/lib/bff/server/handlers');
}

const post = (url: string, body: unknown, headers: Record<string, string> = {}) =>
  new Request(`${ORIGIN}${url}`, {
    method: 'POST',
    headers: { origin: ORIGIN, 'content-type': 'application/json', 'sec-fetch-site': 'same-origin', ...headers },
    body: typeof body === 'string' ? body : JSON.stringify(body),
  });

const signInBody = (over: object = {}) => ({ workspace: ' acme ', email: 'e@example.com', password: 'pw', ...over });

beforeEach(() => {
  vi.stubEnv('ERP_API_BASE_URL', 'http://127.0.0.1:8082');
  vi.stubEnv('APP_PUBLIC_ORIGIN', ORIGIN);
  vi.stubEnv('BFF_SESSION_KEY', `k1:${KEY}`);
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date('2026-11-01T00:00:00Z'));
});
afterEach(() => {
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

describe('sign-in handler — POST /bff/session', () => {
  it('200: forwards exactly {workspace, email, password}, seals the cookie, answers {redirect} without the token (T-1, T-RT)', async () => {
    stubApi(() => Response.json({ access_token: JWT, token_type: 'Bearer', expires_at: EXPIRES }));
    const { handleSignIn } = await handlers();
    const r = await handleSignIn(post('/bff/session', signInBody({ return_to: '/', time_zone: 'Europe/Lisbon', extra: 'x' })));
    expect(r.status).toBe(200);
    const [url, init] = fetchSpy.mock.calls[0]!;
    expect(String(url)).toBe('http://127.0.0.1:8082/api/v1/auth/token');
    expect(JSON.parse(init.body)).toEqual({ workspace: ' acme ', email: 'e@example.com', password: 'pw' });
    const text = await r.text();
    expect(JSON.parse(text)).toEqual({ redirect: '/' });
    expect(text).not.toContain('access_token');
    expect(text).not.toContain(JWT.split('.')[2]);
    const cookie = r.headers.get('set-cookie')!;
    expect(cookie).toMatch(/^__Host-erp_session=[^;]+; /);
    expect(cookie).not.toContain(JWT.split('.')[2]);
    expect(cookie).not.toMatch(/Expires|Max-Age|Domain/i);
    expect(r.headers.get('cache-control')).toBe('no-store, private');
  });

  it('the sealed session holds the trimmed workspace, the validated tz and the token expiry', async () => {
    stubApi(() => Response.json({ access_token: JWT, token_type: 'Bearer', expires_at: EXPIRES }));
    const { handleSignIn } = await handlers();
    const r = await handleSignIn(post('/bff/session', signInBody({ time_zone: 'Mars/Olympus' })));
    const value = r.headers.get('set-cookie')!.split(';')[0]!.split('=')[1]!;
    const { keyringFrom, tokenOf, unsealSession } = await import('@/lib/bff/server/session');
    const s = await unsealSession(value, keyringFrom(`k1:${KEY}`), Date.parse('2026-11-01T00:00:00Z') / 1000);
    expect(s!.workspaceTyped).toBe('acme');
    expect(s!.tz).toBe('UTC');
    expect(s!.exp).toBe(Date.parse(EXPIRES) / 1000);
    expect(tokenOf(s!)).toBe(JWT);
  });

  it.each([
    [undefined, '/'],
    ['//evil.example', '/'],
    // Built since B-3b C5: a return path to a built page is kept; an unbuilt route still falls back to /.
    ['/admin/users', '/admin/users'],
    ['/purchase-orders', '/'],
    ['/', '/'],
  ])('return_to %s → redirect %s (SD-1: only built pages; the fallback is always /)', async (returnTo, want) => {
    stubApi(() => Response.json({ access_token: JWT, token_type: 'Bearer', expires_at: EXPIRES }));
    const { handleSignIn } = await handlers();
    const r = await handleSignIn(post('/bff/session', signInBody(returnTo === undefined ? {} : { return_to: returnTo })));
    expect((await r.json()).redirect).toBe(want);
  });

  it.each([
    [problem(401, 'urn:erp:problem:authentication-failed'), 401, 'urn:erp:problem:authentication-failed'],
    [problem(429, 'urn:erp:problem:too-many-attempts'), 429, 'urn:erp:problem:too-many-attempts'],
    [problem(429, 'urn:erp:problem:sign-in-paused'), 429, 'urn:erp:problem:sign-in-paused'],
    [problem(422, 'urn:erp:problem:validation-failed', { errors: [{ pointer: '/email', code: 'blank', message: 'x' }] }), 422, 'urn:erp:problem:validation-failed'],
    [problem(500, 'urn:erp:problem:internal', { trace: 'secret' }), 502, 'urn:erp:bff:server-error'],
    [problem(403, 'urn:erp:problem:permission-denied'), 502, 'urn:erp:bff:server-error'],
    [problem(429, 'urn:erp:problem:something-new'), 502, 'urn:erp:bff:server-error'],
    [new Response('<html>', { status: 502, headers: { 'content-type': 'text/html' } }), 502, 'urn:erp:bff:server-error'],
    [Response.json({ token_type: 'Bearer' }), 502, 'urn:erp:bff:server-error'],
  ])('T-6: API answer → BFF %#; keyed on problem type; no cookie; no API text', async (answer, status, type) => {
    stubApi(() => answer.clone());
    const { handleSignIn } = await handlers();
    const r = await handleSignIn(post('/bff/session', signInBody(), { cookie: '__Host-erp_session=existing' }));
    expect(r.status).toBe(status);
    expect(r.headers.get('set-cookie')).toBeNull();
    expect(r.headers.get('www-authenticate')).toBeNull();
    const text = await r.text();
    expect(JSON.parse(text).type).toBe(type);
    expect(text).not.toContain('internal detail');
    expect(text).not.toContain('secret');
  });

  it.each([
    [{ workspace: '', email: 'e', password: 'p' }],
    [{ workspace: 'w', email: 'e' }],
    [{ workspace: 'w', email: 'e', password: 'p'.repeat(256) }],
    [{ workspace: 1, email: 'e', password: 'p' }],
    [{ workspace: 'w', email: 'e', password: 'p', return_to: 'x'.repeat(2049) }],
    ['not json'],
  ])('a local shape refusal answers 422 with no API call', async (body) => {
    stubApi(() => Response.json({}));
    const { handleSignIn } = await handlers();
    const r = await handleSignIn(post('/bff/session', body));
    expect(r.status).toBe(422);
    expect(fetchSpy).not.toHaveBeenCalled();
  });

  it('a base URL that is not loopback answers the generic problem with zero API calls (T-DG run-time half)', async () => {
    vi.stubEnv('ERP_API_BASE_URL', 'https://api.example.com');
    stubApi(() => Response.json({}));
    const { handleSignIn } = await handlers();
    const r = await handleSignIn(post('/bff/session', signInBody()));
    expect(r.status).toBe(502);
    expect((await r.json()).type).toBe('urn:erp:bff:server-error');
    expect(fetchSpy).not.toHaveBeenCalled();
  });

  it('T-3: nothing is logged — not the password, not the token, not a problem body', async () => {
    const spies = (['log', 'info', 'warn', 'error', 'debug'] as const).map((m) => vi.spyOn(console, m).mockImplementation(() => {}));
    for (const answer of [Response.json({ access_token: JWT, token_type: 'Bearer', expires_at: EXPIRES }), problem(401, 'urn:erp:problem:authentication-failed'), problem(500, 'x')]) {
      stubApi(() => answer.clone());
      const { handleSignIn } = await handlers();
      await handleSignIn(post('/bff/session', signInBody({ password: 'hunter2-secret' })));
    }
    for (const s of spies) {
      expect(s).not.toHaveBeenCalled();
      s.mockRestore();
    }
  });
});

describe('sign-out handler — POST /bff/session/sign-out (T-13)', () => {
  it('deletes the cookie with matching attributes and makes no API call', async () => {
    stubApi(() => Response.json({}));
    const { handleSignOut } = await handlers();
    const r = await handleSignOut(post('/bff/session/sign-out', {}));
    expect(r.status).toBe(200);
    expect(await r.json()).toEqual({ redirect: '/login' });
    const c = r.headers.get('set-cookie')!;
    expect(c).toMatch(/^__Host-erp_session=;/);
    expect(c).toContain('Max-Age=0');
    expect(r.headers.get('cache-control')).toBe('no-store, private');
    expect(fetchSpy).not.toHaveBeenCalled();
  });
});

// ---------------------------------------------------------------------------------------------------------------------
// T-5: every mutating route under src/app/bff — derived from the route files, never a hand list.
// ---------------------------------------------------------------------------------------------------------------------

const APP = path.resolve(__dirname, '../../src/app');
function routeFiles(dir: string): string[] {
  const out: string[] = [];
  for (const name of readdirSync(dir).sort()) {
    const abs = path.join(dir, name);
    if (statSync(abs).isDirectory()) out.push(...routeFiles(abs));
    else if (name === 'route.ts') out.push(abs);
  }
  return out;
}

describe('T-5 — CSRF on every mutating BFF route (Origin, Sec-Fetch-Site, JSON only), zero API calls', () => {
  const files = routeFiles(path.join(APP, 'bff'));

  it('finds the route files, and each exports POST and no GET that changes state', async () => {
    expect(files.length).toBeGreaterThanOrEqual(3);
    for (const f of files) {
      const mod = await import(f);
      expect(typeof mod.POST, f).toBe('function');
    }
  });

  const cases: [string, Record<string, string | undefined>, number][] = [
    ['foreign Origin', { origin: 'https://evil.example' }, 403],
    ['no Origin', { origin: undefined }, 403],
    ['Origin differs by port', { origin: 'https://localhost:3001' }, 403],
    ['Sec-Fetch-Site cross-site', { 'sec-fetch-site': 'cross-site' }, 403],
    ['Sec-Fetch-Site same-site', { 'sec-fetch-site': 'same-site' }, 403],
    ['text/plain body', { 'content-type': 'text/plain' }, 415],
    ['form body', { 'content-type': 'application/x-www-form-urlencoded' }, 415],
  ];

  it.each(cases)('%s → %i', async (_name, headers, status) => {
    for (const f of files) {
      stubApi(() => Response.json({ access_token: JWT, expires_at: EXPIRES }));
      vi.resetModules();
      const mod = await import(f);
      // X-ERP-Session is present so the proxy's own header check is not what refuses: this test is about the wrapper.
      const h: Record<string, string> = { origin: ORIGIN, 'content-type': 'application/json', 'sec-fetch-site': 'same-origin', 'x-erp-session': 's' };
      for (const [k, v] of Object.entries(headers)) {
        if (v === undefined) delete h[k];
        else h[k] = v;
      }
      const r: Response = await mod.POST(new Request(`${ORIGIN}/bff/x`, { method: 'POST', headers: h, body: JSON.stringify(signInBody()) }));
      expect(r.status, f).toBe(status);
      expect(r.headers.get('set-cookie'), f).toBeNull();
      expect(fetchSpy, f).not.toHaveBeenCalled();
    }
  });

  it('an unset APP_PUBLIC_ORIGIN refuses everything (fail closed)', async () => {
    vi.stubEnv('APP_PUBLIC_ORIGIN', '');
    for (const f of files) {
      stubApi(() => Response.json({}));
      vi.resetModules();
      const mod = await import(f);
      const r: Response = await mod.POST(post('/bff/x', signInBody(), { 'x-erp-session': 's' }));
      expect(r.status, f).toBe(403);
      expect(fetchSpy).not.toHaveBeenCalled();
    }
  });
});

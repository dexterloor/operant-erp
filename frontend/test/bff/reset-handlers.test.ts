// @vitest-environment node
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// The two reset handlers (b3b-design.md §9.6; bff-auth-note.md §8, R1-§6.1, R1-§8): POST /bff/password-resets and
// POST /bff/password-resets/redemption. Exact fields forwarded; mapping keyed on problem type; a redemption 401 is a bad
// link (never "session ended", the cookie untouched); a successful redemption deletes this browser's session cookie.

const ORIGIN = 'https://localhost:3000';
const TOKEN = 'tok_9f8e7d6c5b4a39281706f5e4d3c2b1a0ZZZ';

const problem = (status: number, type: string, extra: object = {}) =>
  new Response(JSON.stringify({ type, status, detail: 'internal detail', ...extra }), { status, headers: { 'content-type': 'application/problem+json' } });

let fetchSpy: ReturnType<typeof vi.fn>;
function stubApi(answer: () => Response) {
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
    headers: { origin: ORIGIN, 'content-type': 'application/json', 'sec-fetch-site': 'same-origin', cookie: '__Host-erp_session=existing', ...headers },
    body: typeof body === 'string' ? body : JSON.stringify(body),
  });

beforeEach(() => {
  vi.stubEnv('ERP_API_BASE_URL', 'http://127.0.0.1:8082');
  vi.stubEnv('APP_PUBLIC_ORIGIN', ORIGIN);
});
afterEach(() => {
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
});

describe('POST /bff/password-resets', () => {
  it('202: forwards exactly {workspace, email} to the reset request, answers 202 with no body, touches no cookie', async () => {
    stubApi(() => new Response(null, { status: 202 }));
    const { handleResetRequest } = await handlers();
    const r = await handleResetRequest(post('/bff/password-resets', { workspace: 'acme', email: 'e@example.com', extra: 'x' }));
    expect(r.status).toBe(202);
    expect(await r.text()).toBe('');
    expect(r.headers.get('set-cookie')).toBeNull();
    expect(r.headers.get('cache-control')).toBe('no-store, private');
    const [url, init] = fetchSpy.mock.calls[0]!;
    expect(String(url)).toBe('http://127.0.0.1:8082/api/v1/auth/password-resets');
    expect(JSON.parse(init.body)).toEqual({ workspace: 'acme', email: 'e@example.com' });
    expect(new Headers(init.headers).has('authorization')).toBe(false);
  });

  it.each([
    [problem(422, 'urn:erp:problem:validation-failed', { errors: [{ pointer: '/email', code: 'invalid', message: 'Enter an email address.' }] }), 422, 'urn:erp:problem:validation-failed'],
    [problem(429, 'urn:erp:problem:too-many-attempts'), 429, 'urn:erp:problem:too-many-attempts'],
    [problem(500, 'urn:erp:problem:x', { trace: 'secret' }), 502, 'urn:erp:bff:server-error'],
    [problem(401, 'urn:erp:problem:authentication-failed'), 502, 'urn:erp:bff:server-error'],
  ])('API answer %# → BFF status and type; no internal detail', async (answer, status, type) => {
    stubApi(() => answer);
    const { handleResetRequest } = await handlers();
    const r = await handleResetRequest(post('/bff/password-resets', { workspace: 'acme', email: 'e' }));
    expect(r.status).toBe(status);
    const text = await r.text();
    expect(JSON.parse(text).type).toBe(type);
    expect(text).not.toContain('internal detail');
    expect(text).not.toContain('secret');
  });

  it("a 422 carries the server's field sentences (the page renders them verbatim on their field)", async () => {
    stubApi(() => problem(422, 'urn:erp:problem:validation-failed', { errors: [{ pointer: '/email', code: 'invalid', message: 'Enter an email address.' }] }));
    const { handleResetRequest } = await handlers();
    const r = await handleResetRequest(post('/bff/password-resets', { workspace: 'acme', email: 'e' }));
    expect((await r.json()).errors).toEqual([{ pointer: '/email', code: 'invalid', message: 'Enter an email address.' }]);
  });

  it('a body that is not two strings ≤ 255 is refused 422 with no API call', async () => {
    stubApi(() => new Response(null, { status: 202 }));
    const { handleResetRequest } = await handlers();
    for (const body of [{ workspace: 'a' }, { workspace: 1, email: 'e' }, { workspace: 'a', email: 'e'.repeat(256) }, 'nope']) {
      expect((await handleResetRequest(post('/bff/password-resets', body))).status).toBe(422);
    }
    expect(fetchSpy).not.toHaveBeenCalled();
  });
});

describe('POST /bff/password-resets/redemption', () => {
  it('200: forwards exactly {token, password}, answers {changed_at} and DELETES this browser\'s session cookie', async () => {
    stubApi(() => Response.json({ changed_at: '2026-10-28T10:00:00+00:00' }));
    const { handleResetRedemption } = await handlers();
    const r = await handleResetRedemption(post('/bff/password-resets/redemption', { token: TOKEN, password: 'pw-long-enough', extra: 1 }));
    expect(r.status).toBe(200);
    expect(await r.json()).toEqual({ changed_at: '2026-10-28T10:00:00+00:00' });
    expect(r.headers.get('set-cookie')).toMatch(/^__Host-erp_session=;.*Max-Age=0/);
    const [url, init] = fetchSpy.mock.calls[0]!;
    expect(String(url)).toBe('http://127.0.0.1:8082/api/v1/auth/password-resets/redemption');
    expect(JSON.parse(init.body)).toEqual({ token: TOKEN, password: 'pw-long-enough' });
  });

  it('401 → a bad link (authentication-failed), NEVER session-ended, and the cookie is not touched', async () => {
    stubApi(() => problem(401, 'urn:erp:problem:authentication-failed'));
    const { handleResetRedemption } = await handlers();
    const r = await handleResetRedemption(post('/bff/password-resets/redemption', { token: TOKEN, password: 'p' }));
    expect(r.status).toBe(401);
    expect((await r.json()).type).toBe('urn:erp:problem:authentication-failed');
    expect(r.headers.get('set-cookie')).toBeNull();
  });

  it.each([
    [problem(422, 'urn:erp:problem:validation-failed', { errors: [{ pointer: '/password', code: 'too_short', message: 'Use at least 12 characters.' }] }), 422],
    [problem(429, 'urn:erp:problem:too-many-attempts'), 429],
    [problem(503, 'urn:erp:problem:x'), 502],
    [Response.json({ nope: true }), 502],
  ])('answer %# → %i, no cookie change', async (answer, status) => {
    stubApi(() => answer);
    const { handleResetRedemption } = await handlers();
    const r = await handleResetRedemption(post('/bff/password-resets/redemption', { token: TOKEN, password: 'p' }));
    expect(r.status).toBe(status);
    expect(r.headers.get('set-cookie')).toBeNull();
  });

  it('T-3: the token and the password are never logged', async () => {
    const spies = (['log', 'info', 'warn', 'error', 'debug'] as const).map((m) => vi.spyOn(console, m).mockImplementation(() => {}));
    for (const a of [Response.json({ changed_at: 'x' }), problem(401, 'urn:erp:problem:authentication-failed'), problem(500, 'x')]) {
      stubApi(() => a.clone());
      const { handleResetRedemption } = await handlers();
      await handleResetRedemption(post('/bff/password-resets/redemption', { token: TOKEN, password: 'hunter2-secret' }));
    }
    for (const s of spies) {
      expect(s).not.toHaveBeenCalled();
      s.mockRestore();
    }
  });
});

describe('code RED M-2 — the redemption bound is measured in UTF-8 bytes', () => {
  it('4096 bytes is forwarded; 1366 three-byte characters (4098 bytes) is refused locally', async () => {
    stubApi(() => Response.json({ changed_at: '2026-11-01T00:00:00+00:00' }));
    const { handleResetRedemption } = await handlers();
    expect((await handleResetRedemption(post('/bff/password-resets/redemption', { token: TOKEN, password: 'é'.repeat(2048) }))).status).toBe(200);
    expect(fetchSpy).toHaveBeenCalledTimes(1);
    const r = await handleResetRedemption(post('/bff/password-resets/redemption', { token: TOKEN, password: '€'.repeat(1366) }));
    expect(r.status).toBe(422);
    expect(fetchSpy).toHaveBeenCalledTimes(1);
  });
});

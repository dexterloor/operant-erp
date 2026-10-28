// @vitest-environment node
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// apiUrl / apiFetch (b3b-design.md §5 items 8, 12, 14, 17, 18; bff-auth-note.md R2-1, R1-D4 items 1–3, R1-§10):
// T-DG's run-time half, T-12 (exact-host loopback), the 15 s limit of T-R2 and the module-load refusals.

const ID = '0b7c1f9e-3f4a-4c8e-9d2b-5a6e7f8091a2';

async function load() {
  vi.resetModules();
  return import('@/lib/bff/server/apiClient');
}

const ok = () => new Response('{}', { status: 200, headers: { 'content-type': 'application/json' } });

beforeEach(() => {
  vi.stubEnv('ERP_API_BASE_URL', 'http://127.0.0.1:8082');
});
afterEach(() => {
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

describe('apiUrl — one builder of API URLs', () => {
  it('builds the template from OPERATIONS with a validated, encoded id, on the configured origin', async () => {
    const { apiUrl } = await load();
    const u = apiUrl('GetUser', { id: ID });
    expect(u.toString()).toBe(`http://127.0.0.1:8082/api/v1/users/${ID}`);
    expect(apiUrl('IssueToken').pathname).toBe('/api/v1/auth/token');
  });

  it('refuses a non-UUID, upper-case or traversal id, a missing or extra parameter, and an unknown operation', async () => {
    const { apiUrl } = await load();
    for (const bad of ['..%2F..%2Fauth%2Ftoken', '%2e%2e', '..', 'abc', ID.toUpperCase(), `${ID}/x`, '']) {
      expect(() => apiUrl('GetUser', { id: bad }), bad).toThrow();
    }
    expect(() => apiUrl('GetUser', {})).toThrow();
    expect(() => apiUrl('GetUser', { id: ID, other: ID })).toThrow();
    expect(() => apiUrl('NoSuchOperation')).toThrow();
  });

  it('passes a query through re-encoded by URLSearchParams', async () => {
    const { apiUrl } = await load();
    const u = apiUrl('ListUsers', {}, new URLSearchParams('q=a b&archived=true&x=%2F'));
    expect(u.search).toBe('?q=a+b&archived=true&x=%2F');
    expect(u.pathname).toBe('/api/v1/users');
  });
});

describe('T-DG run-time half and T-12 — the base URL is checked lazily, exact-host loopback only', () => {
  it.each(['http://127.0.0.1:8082', 'https://localhost:9', 'http://[::1]:8082'])('%s is accepted', async (base) => {
    vi.stubEnv('ERP_API_BASE_URL', base);
    const { apiUrl } = await load();
    expect(() => apiUrl('GetCurrentUser')).not.toThrow();
  });

  it.each([undefined, '', 'https://api.example.com', 'http://localhost.evil.example', 'http://127.0.0.1.nip.io', 'http://10.0.0.5', 'not a url'])(
    '%s makes the first call throw (and loading the module does not)',
    async (base) => {
      if (base === undefined) vi.stubEnv('ERP_API_BASE_URL', undefined as unknown as string);
      else vi.stubEnv('ERP_API_BASE_URL', base);
      const mod = await load();
      const fetchSpy = vi.fn(async () => ok());
      vi.stubGlobal('fetch', fetchSpy);
      expect(() => mod.apiUrl('GetCurrentUser')).toThrow();
      await expect(mod.apiFetch('GetCurrentUser', { token: 't' })).rejects.toThrow();
      expect(fetchSpy).not.toHaveBeenCalled();
    },
  );

  it('loading the module refuses NODE_TLS_REJECT_UNAUTHORIZED=0, and BFF_CLIENT_IP_SOURCE=test-header on Vercel', async () => {
    vi.stubEnv('NODE_TLS_REJECT_UNAUTHORIZED', '0');
    await expect(load()).rejects.toThrow();
    vi.unstubAllEnvs();
    vi.stubEnv('ERP_API_BASE_URL', 'http://127.0.0.1:8082');
    vi.stubEnv('BFF_CLIENT_IP_SOURCE', 'test-header');
    vi.stubEnv('VERCEL_ENV', 'preview');
    await expect(load()).rejects.toThrow();
  });
});

describe('apiFetch — the one API fetch', () => {
  it('sends Authorization only when a token is given, JSON body, Idempotency-Key, cache no-store, nothing else', async () => {
    const { apiFetch } = await load();
    const fetchSpy = vi.fn<(u: URL | string, i?: RequestInit) => Promise<Response>>(async () => ok());
    vi.stubGlobal('fetch', fetchSpy);
    await apiFetch('AssignRole', { token: 'tok', params: { id: ID }, body: { role_id: ID }, idempotencyKey: 'k-1' });
    const [url, init] = fetchSpy.mock.calls[0]!;
    expect(String(url)).toBe(`http://127.0.0.1:8082/api/v1/users/${ID}/roles`);
    expect(init!.method).toBe('POST');
    expect(init!.cache).toBe('no-store');
    expect(init!.redirect).toBe('manual');
    const h = new Headers(init!.headers);
    expect(h.get('authorization')).toBe('Bearer tok');
    expect(h.get('content-type')).toBe('application/json');
    expect(h.get('accept')).toBe('application/json');
    expect(h.get('idempotency-key')).toBe('k-1');
    expect([...h.keys()].sort()).toEqual(['accept', 'authorization', 'content-type', 'idempotency-key']);
    expect(init!.body).toBe(JSON.stringify({ role_id: ID }));

    fetchSpy.mockClear();
    await apiFetch('IssueToken', { body: { workspace: 'w', email: 'e', password: 'p' } });
    expect(new Headers(fetchSpy.mock.calls[0]![1]!.headers).has('authorization')).toBe(false);
  });

  it('a network failure answers the generic problem, not a throw', async () => {
    const { apiFetch } = await load();
    vi.stubGlobal('fetch', vi.fn(async () => Promise.reject(new TypeError('fetch failed'))));
    const r = await apiFetch('GetCurrentUser', { token: 't' });
    expect(r.status).toBe(502);
    expect((await r.json()).type).toBe('urn:erp:bff:server-error');
  });

  it('T-R2: the API call is aborted at 15 s and answers 504 urn:erp:bff:upstream-timeout; it never retries', async () => {
    vi.useFakeTimers();
    const { apiFetch, API_TIMEOUT_MS } = await load();
    expect(API_TIMEOUT_MS).toBe(15_000);
    const fetchSpy = vi.fn(
      (_u: URL | string, init?: RequestInit) =>
        new Promise<Response>((_, reject) => init!.signal!.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')))),
    );
    vi.stubGlobal('fetch', fetchSpy);
    const p = apiFetch('GetCurrentUser', { token: 't' });
    await vi.advanceTimersByTimeAsync(14_999);
    let settled = false;
    void p.then(() => (settled = true));
    await Promise.resolve();
    expect(settled).toBe(false);
    await vi.advanceTimersByTimeAsync(1);
    const r = await p;
    expect(r.status).toBe(504);
    expect(r.headers.get('content-type')).toBe('application/problem+json');
    expect((await r.json()).type).toBe('urn:erp:bff:upstream-timeout');
    expect(fetchSpy).toHaveBeenCalledTimes(1);
  });

  it('supports only BFF_CLIENT_IP_SOURCE=none (the signed client-address pair is not built yet)', async () => {
    vi.stubEnv('BFF_CLIENT_IP_SOURCE', 'vercel');
    const { apiFetch } = await load();
    const fetchSpy = vi.fn(async () => ok());
    vi.stubGlobal('fetch', fetchSpy);
    await expect(apiFetch('GetCurrentUser', { token: 't' })).rejects.toThrow();
    expect(fetchSpy).not.toHaveBeenCalled();
  });
});

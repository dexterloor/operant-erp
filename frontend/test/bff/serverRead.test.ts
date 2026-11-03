// @vitest-environment node
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// The server transport the RSC pages run the pure loaders over (b3b-design.md §10 "First render"): apiFetch with the
// session's token; 2xx → the JSON body; 401 → session-ended (the page redirects); 403 permission-denied → forbidden
// (ST-4b); 404 not-found → not-found (ST-5); anything else, a BFF 502/504 or an unbuildable URL → server-error.

const ID = '0b7c1f9e-3f4a-4c8e-9d2b-5a6e7f8091a2';
const prob = (status: number, type: string) => new Response(JSON.stringify({ type }), { status, headers: { 'content-type': 'application/problem+json' } });

beforeEach(() => {
  vi.stubEnv('ERP_API_BASE_URL', 'http://127.0.0.1:8082');
  vi.stubEnv('APP_PUBLIC_ORIGIN', 'https://localhost:3000');
});
afterEach(() => {
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
});

async function reader() {
  vi.resetModules();
  const { serverRead } = await import('@/lib/bff/server/reads');
  const { newSessionPayload, sealSession, unsealSession, keyringFrom } = await import('@/lib/bff/server/session');
  const ring = keyringFrom(`k1:${Buffer.from(Array.from({ length: 32 }, (_, i) => (i * 7 + 7) % 256)).toString('base64url')}`);
  const sealed = await sealSession(newSessionPayload({ jwt: 'h.p.s', exp: Date.now() / 1000 + 3600, workspaceTyped: 'w', timeZone: 'UTC' }), ring);
  const session = (await unsealSession(sealed, ring))!;
  return serverRead(session);
}

describe('serverRead', () => {
  it('sends the bearer token to the operation URL, with the query; a 200 is its JSON body', async () => {
    const fetchMock = vi.fn(async () => new Response('{"a":1}', { status: 200, headers: { 'content-type': 'application/json' } }));
    vi.stubGlobal('fetch', fetchMock);
    const read = await reader();
    expect(await read('GetUser', { params: { id: ID }, query: new URLSearchParams({ archived: 'true' }) })).toEqual({ kind: 'ok', body: { a: 1 } });
    const [url, init] = fetchMock.mock.calls[0] as unknown as [URL, RequestInit];
    expect(String(url)).toBe(`http://127.0.0.1:8082/api/v1/users/${ID}?archived=true`);
    expect(new Headers(init.headers).get('authorization')).toBe('Bearer h.p.s');
  });

  it.each([
    [prob(401, 'urn:erp:problem:authentication-failed'), 'session-ended'],
    [prob(403, 'urn:erp:problem:permission-denied'), 'forbidden'],
    [prob(404, 'urn:erp:problem:not-found'), 'not-found'],
    [prob(403, 'urn:erp:problem:other'), 'server-error'],
    [prob(409, 'urn:erp:problem:business-rule-violated'), 'server-error'],
    [new Response('not json', { status: 200 }), 'server-error'],
    [new Response('', { status: 502 }), 'server-error'],
  ] as const)('maps an answer to its kind (%#)', async (res, kind) => {
    vi.stubGlobal('fetch', vi.fn(async () => res));
    expect(await (await reader())('GetUser', { params: { id: ID } })).toEqual({ kind });
  });

  it('an unbuildable request (bad id) is server-error, and nothing is sent', async () => {
    const fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);
    expect(await (await reader())('GetUser', { params: { id: 'nope' } })).toEqual({ kind: 'server-error' });
    expect(fetchMock).not.toHaveBeenCalled();
  });
});

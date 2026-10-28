import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { bffFetch } from '@/lib/bff/transport';
import { createApiCaller, type CallerDeps } from '@/lib/bff/apiCall';
import { redeemReset, signIn } from '@/lib/bff/authCalls';
import { startDeadline } from '@/lib/bff/deadline';

// Code RED M-1, browser half (b3b-c2-c8-code-red.md): the 20 s limit and the caller's action deadline stay live until
// the BFF's body has been read. A fake BFF sends its headers and never finishes the body; every caller ends at its
// bound, and the result is `unreachable` (an aborted write may have committed: R1-1's copy), never `server-error`.

const ID = '0b7c1f9e-3f4a-4c8e-9d2b-5a6e7f8091a2';
const stalled = (status = 200) =>
  new Response(new ReadableStream<Uint8Array>({ start(c) { c.enqueue(new TextEncoder().encode('{"a":')); } }), { status, headers: { 'content-type': 'application/json' } });

const deps = (): CallerDeps => ({ sid: 's', navigate: vi.fn(), reload: vi.fn(), onFixedError: vi.fn(), here: () => '/', storage: new Map(), development: true });

beforeEach(() => {
  vi.useFakeTimers();
  vi.stubGlobal('fetch', vi.fn(async () => stalled()));
});
afterEach(() => {
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

describe('M-1 — a stalled BFF body ends at the client bound', () => {
  it('bffFetch: headers in, body stalled → unreachable at 20 s', async () => {
    expect(await settlesAt(bffFetch('/bff/api/me'), 20_000)).toEqual({ ok: false, kind: 'unreachable' });
  });

  it('bffFetch: the caller signal still reaches the body read (a 5 s deadline ends it at 5 s)', async () => {
    const d = startDeadline(5_000);
    expect(await settlesAt(bffFetch('/bff/api/me', {}, d.signal), 5_000)).toEqual({ ok: false, kind: 'unreachable' });
  });

  it('bffFetch: a body that arrives in time is readable by the caller, status and headers kept', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response('{"a":1}', { status: 201, headers: { 'content-type': 'application/json' } })));
    const out = await bffFetch('/bff/api/me');
    expect(out.ok).toBe(true);
    if (!out.ok) return;
    expect(out.response.status).toBe(201);
    expect(out.response.headers.get('content-type')).toBe('application/json');
    expect(await out.response.json()).toEqual({ a: 1 });
  });

  it('the API caller: a write whose body stalls resolves unreachable at the action deadline, not server-error', async () => {
    const d = startDeadline(7_000);
    const call = createApiCaller(deps());
    const r = await settlesAt(call.call('DeleteUser', { id: ID }, undefined, { signal: d.signal }), 7_000);
    expect(r).toEqual({ kind: 'unreachable' });
  });

  it('the API caller with no action deadline: unreachable at 20 s', async () => {
    const r = await settlesAt(createApiCaller(deps()).call('ListUsers'), 20_000);
    expect(r).toEqual({ kind: 'unreachable' });
  });

  it('sign-in and redemption: a stalled body is unreachable at 20 s', async () => {
    expect(await settlesAt(signIn({ workspace: 'w', email: 'e', password: 'p', returnTo: null, timeZone: 'UTC' } as never), 20_000)).toEqual({ kind: 'unreachable' });
    expect(await settlesAt(redeemReset({ token: 't', password: 'p' }), 20_000)).toEqual({ kind: 'unreachable' });
  });
});

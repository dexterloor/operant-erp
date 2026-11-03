import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// /login wired to POST /bff/session (b3b-design.md §5 items 5, 7, 15; §6.1 authCalls; §12; T-RT client half, T-AU's
// sign-in half, the transport's 20 s limit of T-R2, and the sign-in notices of ux-copy-b3b.md C2-2).

let params = new URLSearchParams();
vi.mock('next/navigation', () => ({ useSearchParams: () => params }));

const { LoginRoute } = await import('@/app/(auth)/login/LoginRoute');
const { bffFetch, CLIENT_TIMEOUT_MS } = await import('@/lib/bff/transport');

let fetchSpy: ReturnType<typeof vi.fn>;
const answer = (status: number, body: unknown) =>
  new Response(JSON.stringify(body), { status, headers: { 'content-type': status === 200 ? 'application/json' : 'application/problem+json' } });

function fill() {
  fireEvent.change(screen.getByLabelText('Workspace'), { target: { value: 'acme' } });
  fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'e@example.com' } });
  fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'pw' } });
}

async function submit() {
  await act(async () => {
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }));
  });
}

beforeEach(() => {
  params = new URLSearchParams();
});
afterEach(() => {
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

describe('LoginRoute — T-RT and the sign-in mapping', () => {
  it('posts the three fields plus return_to (from useSearchParams) and time_zone as JSON to /bff/session, without X-ERP-Session', async () => {
    params = new URLSearchParams('return_to=%2Fadmin%2Fusers%3Fq%3Da&reason=session_ended');
    fetchSpy = vi.fn(async () => answer(200, { redirect: '/' }));
    vi.stubGlobal('fetch', fetchSpy);
    const navigate = vi.fn();
    render(<LoginRoute navigate={navigate} />);
    fill();
    await submit();
    await waitFor(() => expect(navigate).toHaveBeenCalled());
    const [url, init] = fetchSpy.mock.calls[0]!;
    expect(url).toBe('/bff/session');
    expect(init.method).toBe('POST');
    const h = new Headers(init.headers);
    expect(h.get('content-type')).toBe('application/json');
    expect(h.has('x-erp-session')).toBe(false);
    const body = JSON.parse(init.body);
    expect(body).toEqual({ workspace: 'acme', email: 'e@example.com', password: 'pw', return_to: '/admin/users?q=a', time_zone: Intl.DateTimeFormat().resolvedOptions().timeZone });
  });

  it('navigates only to the server\'s redirect, never to ?return_to itself, even when that value is valid', async () => {
    params = new URLSearchParams('return_to=/admin/users');
    vi.stubGlobal('fetch', vi.fn(async () => answer(200, { redirect: '/' })));
    const navigate = vi.fn();
    render(<LoginRoute navigate={navigate} />);
    fill();
    await submit();
    await waitFor(() => expect(navigate).toHaveBeenCalledTimes(1));
    expect(navigate).toHaveBeenCalledWith('/');
  });

  it('a redirect that is not a same-site path is replaced by "/"', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => answer(200, { redirect: '//evil.example' })));
    const navigate = vi.fn();
    render(<LoginRoute navigate={navigate} />);
    fill();
    await submit();
    await waitFor(() => expect(navigate).toHaveBeenCalledWith('/'));
  });

  it.each([
    [answer(401, { type: 'urn:erp:problem:authentication-failed' }), "Those details don't match an account."],
    [answer(429, { type: 'urn:erp:problem:too-many-attempts' }), 'Too many attempts.'],
    [answer(429, { type: 'urn:erp:problem:sign-in-paused' }), 'Too many failed attempts.'],
    [answer(422, { type: 'urn:erp:problem:validation-failed' }), "Those details don't match an account."],
    [answer(502, { type: 'urn:erp:bff:server-error' }), 'Something went wrong on our side.'],
    [answer(429, { type: 'urn:erp:problem:other' }), 'Something went wrong on our side.'],
    [new Response('nope', { status: 500 }), 'Something went wrong on our side.'],
  ])('answer %# shows its notice; a 401 never navigates (T-AU)', async (res, heading) => {
    vi.stubGlobal('fetch', vi.fn(async () => res));
    const navigate = vi.fn();
    render(<LoginRoute navigate={navigate} />);
    fill();
    await submit();
    expect(await screen.findByText(heading)).toBeInTheDocument();
    expect(navigate).not.toHaveBeenCalled();
  });

  it('the server cannot be reached → the unreachable notice, never the failure sentence (C2-2)', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => Promise.reject(new TypeError('Failed to fetch'))));
    render(<LoginRoute navigate={vi.fn()} />);
    fill();
    await submit();
    expect(await screen.findByText("We couldn't reach the server.")).toBeInTheDocument();
    expect(screen.getByText('Check your connection and try again.')).toBeInTheDocument();
    expect(screen.queryByText("Those details don't match an account.")).toBeNull();
  });

  it('reason=session_ended shows the three-line notice (L-13); the form is shown even if a cookie exists', () => {
    params = new URLSearchParams('reason=session_ended');
    render(<LoginRoute navigate={vi.fn()} />);
    expect(screen.getByText('You were signed out.')).toBeInTheDocument();
    expect(screen.getByText('Sign in again to continue.')).toBeInTheDocument();
    expect(screen.getByText('If you were saving changes, check them after you sign in: some may not have been saved.')).toBeInTheDocument();
    expect(screen.getByLabelText('Password')).toBeInTheDocument();
  });

  it('any other reason value shows no notice (the parameter is attacker-settable; the notice is fixed text)', () => {
    params = new URLSearchParams('reason=%3Cb%3Ehi');
    render(<LoginRoute navigate={vi.fn()} />);
    expect(screen.queryByText('You were signed out.')).toBeNull();
    expect(document.body.textContent).not.toContain('<b>hi');
  });
});

describe('bffFetch — T-R2 per-request limit', () => {
  it('a request that never settles resolves unreachable at 20 s', async () => {
    vi.useFakeTimers();
    expect(CLIENT_TIMEOUT_MS).toBe(20_000);
    vi.stubGlobal(
      'fetch',
      vi.fn((_u: string, init: RequestInit) => new Promise((_, reject) => init.signal!.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError'))))),
    );
    const p = bffFetch('/bff/session', { method: 'POST' });
    let out: unknown = null;
    void p.then((o) => (out = o));
    await vi.advanceTimersByTimeAsync(19_999);
    expect(out).toBeNull();
    await vi.advanceTimersByTimeAsync(1);
    expect(out).toEqual({ ok: false, kind: 'unreachable' });
  });

  it('refuses a path outside /bff/ without sending anything', async () => {
    const f = vi.fn();
    vi.stubGlobal('fetch', f);
    await expect(bffFetch('/api/v1/me')).rejects.toThrow();
    await expect(bffFetch('https://evil.example/bff/x')).rejects.toThrow();
    expect(f).not.toHaveBeenCalled();
  });

  it('sends same-origin credentials and no-store', async () => {
    const f = vi.fn<(u: string, i: RequestInit) => Promise<Response>>(async () => answer(200, {}));
    vi.stubGlobal('fetch', f);
    await bffFetch('/bff/session', { method: 'POST' });
    const init = f.mock.calls[0]![1];
    expect(init.credentials).toBe('same-origin');
    expect(init.cache).toBe('no-store');
  });
});

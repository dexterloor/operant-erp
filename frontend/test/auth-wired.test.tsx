import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { wiredRedeemReset, wiredRequestReset } from '@/lib/auth/wired';
import { ResetPasswordRoute } from '@/app/(auth)/reset-password/ResetPasswordRoute';

// The wired credential actions (b3b-design.md §9.6, §6.1 authCalls, T-AU's redemption half, T-R8): each maps the BFF's
// answer onto the page's result union; neither sends X-ERP-Session; a redemption 401 is "link refused" and never
// navigates; the token reaches the server only in the POST body, as the argument it was given.

vi.mock('next/link', () => ({ default: ({ href, children }: { href: string; children: React.ReactNode }) => <a href={href}>{children}</a> }));

const TOKEN = 'tok_9f8e7d6c5b4a39281706f5e4d3c2b1a0ZZZ';
type Call = [string, RequestInit];
function stub(answer: () => Response | Promise<Response>) {
  const calls: Call[] = [];
  vi.stubGlobal(
    'fetch',
    vi.fn(async (u: string, i: RequestInit) => {
      calls.push([u, i]);
      return answer();
    }),
  );
  return calls;
}
const problem = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/problem+json' } });

afterEach(() => {
  vi.unstubAllGlobals();
  window.history.replaceState(null, '', '/');
});

describe('wiredRequestReset — POST /bff/password-resets', () => {
  it('202 → accepted; JSON body {workspace, email}; no X-ERP-Session', async () => {
    const calls = stub(() => new Response(null, { status: 202 }));
    expect(await wiredRequestReset({ workspace: 'acme', email: 'e@example.com' })).toEqual({ kind: 'accepted' });
    const [url, init] = calls[0]!;
    expect(url).toBe('/bff/password-resets');
    expect(init.method).toBe('POST');
    expect(JSON.parse(init.body as string)).toEqual({ workspace: 'acme', email: 'e@example.com' });
    expect(new Headers(init.headers).has('x-erp-session')).toBe(false);
  });

  it.each([
    [() => problem(422, { type: 'urn:erp:problem:validation-failed', errors: [{ pointer: '/email', code: 'x', message: 'Enter an email address.' }] }), { kind: 'invalid', fieldErrors: { email: 'Enter an email address.' } }],
    [() => problem(422, { type: 'urn:erp:problem:validation-failed', errors: [{ pointer: '/other', code: 'x', message: 'm' }] }), { kind: 'server-error' }],
    [() => problem(429, { type: 'urn:erp:problem:too-many-attempts' }), { kind: 'rate-limited' }],
    [() => problem(502, { type: 'urn:erp:bff:server-error' }), { kind: 'server-error' }],
    [() => problem(401, { type: 'urn:erp:problem:authentication-failed' }), { kind: 'server-error' }],
    [() => Promise.reject(new TypeError('x')), { kind: 'unreachable' }],
  ])('answer %# → its kind', async (answer, want) => {
    stub(answer);
    expect(await wiredRequestReset({ workspace: 'a', email: 'e' })).toEqual(want);
  });
});

describe('wiredRedeemReset — POST /bff/password-resets/redemption', () => {
  it('200 → changed, with changedAt and receivedAt = performance.now() at arrival', async () => {
    stub(() => Response.json({ changed_at: '2026-10-28T10:00:00+00:00' }));
    const now = vi.spyOn(performance, 'now').mockReturnValue(4242);
    expect(await wiredRedeemReset({ token: TOKEN, password: 'p' })).toEqual({ kind: 'changed', changedAt: '2026-10-28T10:00:00+00:00', receivedAt: 4242 });
    now.mockRestore();
  });

  it.each([
    [() => problem(401, { type: 'urn:erp:problem:authentication-failed' }), { kind: 'link-refused' }],
    [() => problem(401, { type: 'urn:erp:bff:session-ended' }), { kind: 'server-error' }],
    [() => problem(422, { type: 'urn:erp:problem:validation-failed', errors: [{ pointer: '/password', code: 'x', message: 'Use at least 12 characters.' }] }), { kind: 'invalid', fieldErrors: { password: 'Use at least 12 characters.' } }],
    [() => problem(429, { type: 'urn:erp:problem:too-many-attempts' }), { kind: 'rate-limited' }],
    [() => problem(502, { type: 'urn:erp:bff:server-error' }), { kind: 'server-error' }],
    [() => Response.json({}), { kind: 'server-error' }],
    [() => Promise.reject(new TypeError('x')), { kind: 'unreachable' }],
  ])('answer %# → its kind; a 401 never navigates', async (answer, want) => {
    stub(answer);
    // jsdom's location cannot be spied; a navigation would change href (or log jsdom's "not implemented" error).
    const before = window.location.href;
    const err = vi.spyOn(console, 'error').mockImplementation(() => {});
    expect(await wiredRedeemReset({ token: TOKEN, password: 'p' })).toEqual(want);
    expect(window.location.href).toBe(before);
    expect(err).not.toHaveBeenCalled();
    err.mockRestore();
  });
});

describe('T-R8 — the wired page sends the token only in the redemption body, as the argument', () => {
  it('the token never appears in the request URL or headers; the body is exactly {token, password}', async () => {
    window.history.replaceState(null, '', `/reset-password#token=${TOKEN}`);
    const calls = stub(() => problem(401, { type: 'urn:erp:problem:authentication-failed' }));
    render(<ResetPasswordRoute />);
    expect(window.location.href).not.toContain(TOKEN);
    fireEvent.change(screen.getByLabelText('New password'), { target: { value: 'correct horse battery' } });
    await act(async () => {
      fireEvent.click(screen.getByRole('button', { name: 'Set password' }));
    });
    expect(calls).toHaveLength(1);
    const [url, init] = calls[0]!;
    expect(url).toBe('/bff/password-resets/redemption');
    expect(url).not.toContain(TOKEN);
    expect(JSON.stringify([...new Headers(init.headers).entries()])).not.toContain(TOKEN);
    expect(JSON.parse(init.body as string)).toEqual({ token: TOKEN, password: 'correct horse battery' });
  });
});

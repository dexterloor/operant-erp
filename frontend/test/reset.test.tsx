import { StrictMode, useEffect, useLayoutEffect } from 'react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ResetPasswordForm } from '@/components/auth/ResetPasswordForm';
import type { RedeemResetAction, RedeemResetResult } from '@/lib/auth/results';

// Copy typed here from docs/design/ux-copy-frontend.md §1.4, independently of src/copy.
const REFUSED_BODY = 'Each link works once, for 30 minutes, and setting a new password ends any others. Ask for a new link to try again.';
const REFUSED_BODY_2 = "If a new link doesn't arrive, ask the person who manages your workspace.";
const DONE_BODY = "You've been signed out everywhere, including other tabs and devices. Sign in with your new password.";
const NEVER_SENT_CLAIM = /emailed|email sent|we've sent|we have sent|check your inbox|sent you/i;

const TOKEN = 'tok_9f8e7d6c5b4a39281706f5e4d3c2b1a0ZZZ';
const ROUTER_STATE = { __NA: true, __PRIVATE_NEXTJS_INTERNALS_TREE: 'tree' };

function openLink(url = `/reset-password?utm=x#token=${TOKEN}`) {
  window.history.replaceState(ROUTER_STATE, '', url);
}

/** Everything a token could leak into from this page. */
function expectTokenNowhere() {
  expect(window.location.href).not.toContain(TOKEN);
  expect(window.location.hash).toBe('');
  expect(document.documentElement.outerHTML).not.toContain(TOKEN);
  expect(document.cookie).not.toContain(TOKEN);
  for (const store of [window.localStorage, window.sessionStorage]) {
    for (let i = 0; i < store.length; i++) {
      const k = store.key(i) ?? '';
      expect(k + (store.getItem(k) ?? '')).not.toContain(TOKEN);
    }
  }
}

let consoleSpies: ReturnType<typeof vi.spyOn>[] = [];
beforeEach(() => {
  window.localStorage.clear();
  window.sessionStorage.clear();
  consoleSpies = (['log', 'info', 'warn', 'error', 'debug'] as const).map((m) => vi.spyOn(console, m));
});
afterEach(() => {
  // No log line may carry the token (§1.4 R1).
  for (const spy of consoleSpies) {
    for (const call of spy.mock.calls) expect(JSON.stringify(call)).not.toContain(TOKEN);
    spy.mockRestore();
  }
  vi.useRealTimers();
  vi.restoreAllMocks();
  window.history.replaceState(null, '', '/');
});

function renderPage(redeem: RedeemResetAction, extra: Partial<Parameters<typeof ResetPasswordForm>[0]> = {}) {
  return render(<ResetPasswordForm redeem={redeem} {...extra} />);
}

async function submit(password = 'correct horse battery') {
  fireEvent.change(screen.getByLabelText('New password'), { target: { value: password } });
  await act(async () => {
    fireEvent.click(screen.getByRole('button', { name: 'Set password' }));
  });
}

describe('ResetPasswordForm (/reset-password) — the token', () => {
  it('strips the fragment before any later effect runs, keeping history.state and the query', () => {
    openLink();
    const seen: string[] = [];
    // A sibling after the form: its layout effect and effect run after the form's layout effect, i.e. before paint.
    function Probe() {
      useLayoutEffect(() => {
        seen.push(`layout:${window.location.hash}`);
      });
      useEffect(() => {
        seen.push(`effect:${window.location.hash}`);
      });
      return null;
    }
    render(
      <>
        <ResetPasswordForm redeem={vi.fn()} />
        <Probe />
      </>,
    );
    expect(seen.length).toBeGreaterThan(0);
    for (const s of seen) expect(s).toMatch(/:$/);
    expect(window.location.pathname + window.location.search).toBe('/reset-password?utm=x');
    expect(window.history.state).toEqual(ROUTER_STATE);
    expectTokenNowhere();
    expect(screen.getByLabelText('New password')).toHaveFocus();
  });

  it('calls replaceState exactly with (history.state, "", pathname + search) — BFF note R1-§8', () => {
    openLink();
    const spy = vi.spyOn(window.history, 'replaceState');
    renderPage(vi.fn());
    expect(spy).toHaveBeenCalledTimes(1);
    expect(spy).toHaveBeenCalledWith(ROUTER_STATE, '', '/reset-password?utm=x');
  });

  it("brings the App Router's own URL in line once its history patch is installed (Next keeps the hash otherwise)", async () => {
    // A stand-in for Next 16.4's patch (app-router.js): a call whose state carries `__NA` passes straight through and
    // the router keeps its old URL; any other call copies Next's internals across and restores the router to `url`.
    openLink();
    const original = History.prototype.replaceState;
    const routerUrls: string[] = [];
    let routerUrl = window.location.pathname + window.location.search + window.location.hash; // seeded with the hash
    window.history.replaceState = function patched(this: History, data: unknown, unused: string, url?: string | URL | null) {
      const state = (data ?? {}) as Record<string, unknown>;
      if (state.__NA) return original.call(window.history, data, unused, url);
      const current = window.history.state as Record<string, unknown> | null;
      if (url != null) {
        routerUrl = String(url);
        routerUrls.push(routerUrl);
      }
      return original.call(window.history, { ...state, __NA: current?.__NA, __PRIVATE_NEXTJS_INTERNALS_TREE: current?.__PRIVATE_NEXTJS_INTERNALS_TREE }, unused, url);
    };
    try {
      vi.useFakeTimers();
      renderPage(vi.fn());
      // Before the sync: the address bar is clean, but the router still holds the token.
      expect(window.location.hash).toBe('');
      expect(routerUrl).toContain(TOKEN);
      await act(async () => {
        vi.runAllTimers();
      });
      expect(routerUrls).toEqual(['/reset-password?utm=x']);
      expect(routerUrl).not.toContain(TOKEN);
      expect(window.history.state).toEqual(ROUTER_STATE);
      expectTokenNowhere();
    } finally {
      delete (window.history as { replaceState?: unknown }).replaceState; // back to History.prototype's
    }
    expect(window.history.replaceState).toBe(original);
  });

  it('keeps the token under StrictMode (effects run twice) and posts exactly {token, password}', async () => {
    openLink();
    const redeem = vi.fn<RedeemResetAction>(async () => ({ kind: 'link-refused' }));
    render(
      <StrictMode>
        <ResetPasswordForm redeem={redeem} />
      </StrictMode>,
    );
    await submit('a long enough password');
    expect(redeem).toHaveBeenCalledWith({ token: TOKEN, password: 'a long enough password' });
  });

  it('the token is never in the DOM, storage, a cookie, the URL or a log — before submit, after a 422 and after success', async () => {
    openLink();
    let reply: RedeemResetResult = { kind: 'invalid', fieldErrors: { password: 'The new password must be at least 12 characters.' } };
    renderPage(async () => reply);
    expectTokenNowhere();
    await submit('short');
    expectTokenNowhere();
    reply = { kind: 'changed', changedAt: '2026-10-27T10:00:00+00:00' };
    await submit();
    expectTokenNowhere();
  });

  it('opens with no token: the link-refusal state, without calling the API', () => {
    window.history.replaceState(null, '', '/reset-password');
    const redeem = vi.fn();
    renderPage(redeem);
    expect(screen.getByRole('heading', { level: 1, name: "This link can't be used" })).toHaveFocus();
    expect(redeem).not.toHaveBeenCalled();
  });

  it('an empty token is no token', () => {
    openLink('/reset-password#token=');
    renderPage(vi.fn());
    expect(screen.getByRole('heading', { level: 1, name: "This link can't be used" })).toBeInTheDocument();
    expect(window.location.hash).toBe('');
  });
});

describe('ResetPasswordForm — the form and its results', () => {
  it('renders the form word for word', () => {
    openLink();
    renderPage(vi.fn());
    expect(screen.getByRole('heading', { level: 1, name: 'Set a new password' })).toBeInTheDocument();
    const field = screen.getByLabelText('New password');
    expect(field).toHaveAttribute('autocomplete', 'new-password');
    expect(field).toHaveAttribute('type', 'password');
    expect(field).toHaveAccessibleDescription('At least 12 characters.');
    expect(screen.getByRole('button', { name: 'Set password' })).toBeInTheDocument();
  });

  it('F-2: once the token is read, the form submits as a post, so a native submit never puts the password in the address bar', () => {
    openLink();
    renderPage(vi.fn());
    const form = screen.getByRole('button', { name: 'Set password' }).closest('form');
    expect(form?.method).toBe('post');
    expect(form).not.toHaveAttribute('action');
  });

  it('Show password / Hide password is a real toggle button with aria-pressed', () => {
    openLink();
    renderPage(vi.fn());
    const toggle = screen.getByRole('button', { name: 'Show password' });
    expect(toggle).toHaveAttribute('aria-pressed', 'false');
    fireEvent.click(toggle);
    expect(screen.getByRole('button', { name: 'Hide password' })).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByLabelText('New password')).toHaveAttribute('type', 'text');
  });

  it("422: the server's sentence on the field; the link stays usable — the retry sends the SAME token", async () => {
    openLink();
    const redeem = vi
      .fn<RedeemResetAction>()
      .mockResolvedValueOnce({ kind: 'invalid', fieldErrors: { password: 'The new password must be at least 12 characters.' } })
      .mockResolvedValueOnce({ kind: 'link-refused' });
    renderPage(redeem);
    await submit('short');
    const field = screen.getByLabelText('New password');
    expect(field).toHaveFocus();
    expect(field).toHaveAttribute('aria-invalid', 'true');
    expect(field).toHaveAccessibleDescription('At least 12 characters. The new password must be at least 12 characters.');
    expect(screen.queryByText("This link can't be used")).toBeNull();
    expect(screen.queryByRole('link', { name: 'Ask for a new link' })).toBeNull();
    await submit('now long enough indeed');
    expect(redeem).toHaveBeenNthCalledWith(2, { token: TOKEN, password: 'now long enough indeed' });
  });

  it('401: one link-refusal state, word for word, focus on its heading, "Ask for a new link" → /forgot-password', async () => {
    openLink();
    renderPage(async () => ({ kind: 'link-refused' }));
    await submit();
    expect(screen.getByRole('heading', { level: 1, name: "This link can't be used" })).toHaveFocus();
    expect(screen.getByText(REFUSED_BODY)).toBeInTheDocument();
    expect(screen.getByText(REFUSED_BODY_2)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Ask for a new link' })).toHaveAttribute('href', '/forgot-password');
    // Never the shell's "session ended" notice.
    expect(document.body.textContent).not.toMatch(/signed out|session/i);
    expect(screen.queryByLabelText('New password')).toBeNull();
  });

  it('429: ruled copy + "Nothing was changed."; the form and the link stay', async () => {
    openLink();
    const redeem = vi.fn<RedeemResetAction>().mockResolvedValueOnce({ kind: 'rate-limited' }).mockResolvedValueOnce({ kind: 'link-refused' });
    renderPage(redeem);
    await submit();
    expect(screen.getByRole('status')).toHaveTextContent('Too many attempts.Try again in a few minutes.Nothing was changed.');
    await submit();
    expect(redeem).toHaveBeenNthCalledWith(2, expect.objectContaining({ token: TOKEN }));
  });

  it('network failure and 5xx keep the form and the token', async () => {
    openLink();
    const redeem = vi
      .fn<RedeemResetAction>()
      .mockRejectedValueOnce(new TypeError('Failed to fetch'))
      .mockResolvedValueOnce({ kind: 'server-error' })
      .mockResolvedValueOnce({ kind: 'link-refused' });
    renderPage(redeem);
    await submit();
    expect(screen.getByRole('status')).toHaveTextContent("We couldn't reach the server.Check your connection and try again.");
    await submit();
    expect(screen.getByRole('status')).toHaveTextContent('Something went wrong on our side.Try again in a moment.');
    await submit();
    expect(redeem).toHaveBeenNthCalledWith(3, expect.objectContaining({ token: TOKEN }));
  });

  it('no wording anywhere on the page claims an email was sent', async () => {
    openLink();
    renderPage(async () => ({ kind: 'link-refused' }));
    expect(document.body.textContent).not.toMatch(NEVER_SENT_CLAIM);
    await submit();
    expect(document.body.textContent).not.toMatch(NEVER_SENT_CLAIM);
  });
});

describe('ResetPasswordForm — success and the 1.1 s wait (DT-6)', () => {
  let clock = 0;
  beforeEach(() => {
    vi.useFakeTimers();
    clock = 50_000;
    vi.spyOn(performance, 'now').mockImplementation(() => clock);
  });

  async function advance(ms: number, clockMs = ms) {
    clock += clockMs;
    await act(async () => {
      vi.advanceTimersByTime(ms);
    });
  }

  it('success copy word for word; Sign in only once 1100 ms of performance.now() have passed since the response arrived', async () => {
    openLink();
    const navigate = vi.fn();
    renderPage(async () => ({ kind: 'changed', changedAt: '2026-10-27T10:00:00+00:00' }), { navigate });
    await submit();
    expect(screen.getByRole('heading', { level: 1, name: 'Password set' })).toHaveFocus();
    expect(screen.getByText(DONE_BODY)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Sign in' })).toBeNull();
    expect(screen.getByRole('status')).toHaveTextContent('');

    await advance(1099);
    expect(screen.queryByRole('button', { name: 'Sign in' })).toBeNull();
    await advance(1);
    expect(screen.getByRole('button', { name: 'Sign in' })).toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveTextContent('You can sign in now.');

    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }));
    // A full document load (location.assign), never router.push.
    expect(navigate).toHaveBeenCalledWith('/login');
  });

  it('a timer that fires early does not offer Sign in: performance.now() is the authority', async () => {
    openLink();
    renderPage(async () => ({ kind: 'changed', changedAt: '2026-10-27T10:00:00+00:00' }));
    await submit();
    await advance(1100, 600); // timers say 1100 ms, the monotonic clock says 600 ms
    expect(screen.queryByRole('button', { name: 'Sign in' })).toBeNull();
    await advance(500);
    expect(screen.getByRole('button', { name: 'Sign in' })).toBeInTheDocument();
  });

  it('never compares changed_at with the browser clock: a wall clock far ahead or behind changes nothing', async () => {
    openLink();
    vi.setSystemTime(new Date('2030-01-01T00:00:00Z'));
    renderPage(async () => ({ kind: 'changed', changedAt: '2026-10-27T10:00:00+00:00' }));
    await submit();
    await advance(1000);
    expect(screen.queryByRole('button', { name: 'Sign in' })).toBeNull();
    vi.setSystemTime(new Date('2001-01-01T00:00:00Z'));
    await advance(100);
    expect(screen.getByRole('button', { name: 'Sign in' })).toBeInTheDocument();
  });

  it("measures from the caller's receivedAt when the result carries one", async () => {
    openLink();
    renderPage(async () => ({ kind: 'changed', changedAt: '2026-10-27T10:00:00+00:00', receivedAt: clock - 1000 }));
    await submit();
    await advance(99);
    expect(screen.queryByRole('button', { name: 'Sign in' })).toBeNull();
    await advance(1);
    expect(screen.getByRole('button', { name: 'Sign in' })).toBeInTheDocument();
  });
});

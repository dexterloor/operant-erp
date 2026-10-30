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
/** CONFIRMED (ux-confirm-followup.md §3; first proposed as b3b-followup C-6), typed here independently of src/copy. */
const INTERRUPTED_BODY = "We can't tell whether your new password was set. Try signing in with it. If that doesn't work, press Set password again, or ask for a new link.";
/** The 429 lines (confirm-pass-2026-10-30-final-red.md Spar round 2, amendment 1), typed here independently of src/copy. */
const RATE_LIMITED_NOTHING_CHANGED = 'Too many attempts.Try again in a few minutes.Nothing was changed.';
const RATE_LIMITED_EARLIER_TRY = 'Too many attempts.Try again in a few minutes.An earlier try may already have set your new password: try signing in with it.';
/** B4 (b4-doubt-ux.md Revision 1 + RED round-1 amendments): the earlier-try sentence, typed here independently of src/copy. */
const EARLIER_TRY = 'An earlier try may already have set your new password: try signing in with it.';
const NOT_SENT = 'Something went wrong on our side.Try again in a moment.';

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

  describe('B3: the 429 second line follows what this page has shown', () => {
    type Kind = RedeemResetResult['kind'];
    const field = { kind: 'invalid', fieldErrors: { password: 'The new password must be at least 12 characters.' } } as const;
    const as = (k: Kind): RedeemResetResult => (k === 'invalid' ? field : ({ kind: k } as RedeemResetResult));

    /** Presses once per result; the last result is a 429. Returns the 429 banner's text. */
    async function after(...kinds: Kind[]): Promise<string> {
      openLink();
      const redeem = vi.fn<RedeemResetAction>();
      for (const k of [...kinds, 'rate-limited' as const]) redeem.mockResolvedValueOnce(as(k));
      const { unmount } = renderPage(redeem);
      for (let i = 0; i <= kinds.length; i++) await submit();
      const text = screen.getByRole('status').textContent ?? '';
      unmount();
      return text;
    }

    it('"Nothing was changed." until the page has shown an interrupted result', async () => {
      expect(await after()).toBe(RATE_LIMITED_NOTHING_CHANGED);
      expect(await after('not-sent')).toBe(RATE_LIMITED_NOTHING_CHANGED);
      expect(await after('invalid')).toBe(RATE_LIMITED_NOTHING_CHANGED);
      expect(await after('rate-limited')).toBe(RATE_LIMITED_NOTHING_CHANGED);
      expect(await after('not-sent', 'invalid', 'rate-limited')).toBe(RATE_LIMITED_NOTHING_CHANGED);
    });

    it('after a network failure or a 5xx: the earlier try may have set the password', async () => {
      expect(await after('unreachable')).toBe(RATE_LIMITED_EARLIER_TRY);
      expect(await after('server-error')).toBe(RATE_LIMITED_EARLIER_TRY);
      expect(document.body.textContent).not.toContain('Nothing was changed.');
    });

    it('a later not-sent, 429 or 422 does not clear it', async () => {
      expect(await after('unreachable', 'not-sent')).toBe(RATE_LIMITED_EARLIER_TRY);
      expect(await after('server-error', 'rate-limited')).toBe(RATE_LIMITED_EARLIER_TRY);
      // A 422 proves the link was unused when it was checked, but the interrupted request may still commit after it.
      expect(await after('unreachable', 'invalid')).toBe(RATE_LIMITED_EARLIER_TRY);
      expect(await after('not-sent', 'server-error', 'invalid', 'not-sent', 'rate-limited')).toBe(RATE_LIMITED_EARLIER_TRY);
    });

    it('a thrown call counts as interrupted too', async () => {
      openLink();
      const redeem = vi.fn<RedeemResetAction>().mockRejectedValueOnce(new TypeError('Failed to fetch')).mockResolvedValueOnce({ kind: 'rate-limited' });
      renderPage(redeem);
      await submit();
      await submit();
      expect(screen.getByRole('status').textContent).toBe(RATE_LIMITED_EARLIER_TRY);
    });
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
    // b3b-followup C-6 (R-2): a redeem that may have committed says the outcome is unknown, never "try again" alone.
    expect(screen.getByRole('status')).toHaveTextContent(`We couldn't reach the server.${INTERRUPTED_BODY}`);
    await submit();
    expect(screen.getByRole('status')).toHaveTextContent(`Something went wrong on our side.${INTERRUPTED_BODY}`);
    await submit();
    expect(redeem).toHaveBeenNthCalledWith(3, expect.objectContaining({ token: TOKEN }));
  });

  it('F-1: a request the server refused before sending says "Try again in a moment.", never the "can\'t tell" body', async () => {
    openLink();
    const redeem = vi.fn<RedeemResetAction>().mockResolvedValueOnce({ kind: 'not-sent' }).mockResolvedValueOnce({ kind: 'link-refused' });
    renderPage(redeem);
    await submit();
    expect(screen.getByRole('status')).toHaveTextContent('Something went wrong on our side.Try again in a moment.');
    expect(document.body.textContent).not.toContain(INTERRUPTED_BODY);
    expect(screen.getByLabelText('New password')).toBeInTheDocument();
    await submit();
    expect(redeem).toHaveBeenNthCalledWith(2, expect.objectContaining({ token: TOKEN }));
  });

  it('B1 + B4 (T1): an interrupted result, then not-sent: "Try again in a moment.", then the earlier-try line; never "Nothing was changed."', async () => {
    // Guards the not-sent body after an interrupted result (confirm-pass-2026-10-30.md Rev 1 §b: the earlier press may
    // have set the password, so "Nothing was changed." is not known to be true) plus B4's earlier-try line as the banner's
    // second line (b4-doubt-ux.md Revision 1 §R1-4.1). The exact pin moved with B4; it covers a thrown call too.
    for (const interrupted of ['unreachable', 'server-error', 'thrown'] as const) {
      openLink();
      const redeem = vi.fn<RedeemResetAction>();
      if (interrupted === 'thrown') redeem.mockRejectedValueOnce(new TypeError('Failed to fetch'));
      else redeem.mockResolvedValueOnce({ kind: interrupted });
      redeem.mockResolvedValueOnce({ kind: 'not-sent' });
      const { unmount } = renderPage(redeem);
      await submit();
      expect(screen.getByRole('status')).toHaveTextContent(INTERRUPTED_BODY);
      await submit();
      expect(screen.getByRole('status')).toHaveTextContent(/^Something went wrong on our side\.Try again in a moment\.An earlier try may already have set your new password: try signing in with it\.$/);
      expect(document.body.textContent).not.toContain('Nothing was changed.');
      unmount();
    }
  });

  describe('B4: the earlier-try doubt stays on screen after a later, different result (b4-doubt-ux.md Revision 1)', () => {
    type Step = RedeemResetResult['kind'] | 'thrown';
    const fieldError = 'The new password must be at least 12 characters.';

    /** Renders the page with one result per press queued; returns the press function and the navigate spy. */
    function page(...steps: Step[]) {
      openLink();
      const redeem = vi.fn<RedeemResetAction>();
      for (const k of steps) {
        if (k === 'thrown') redeem.mockRejectedValueOnce(new TypeError('Failed to fetch'));
        else if (k === 'invalid') redeem.mockResolvedValueOnce({ kind: 'invalid', fieldErrors: { password: fieldError } });
        else if (k === 'changed') redeem.mockResolvedValueOnce({ kind: 'changed', changedAt: '2026-10-27T10:00:00+00:00' });
        else redeem.mockResolvedValueOnce({ kind: k } as RedeemResetResult);
      }
      const navigate = vi.fn();
      const view = renderPage(redeem, { navigate });
      return { ...view, navigate };
    }
    async function pressAll(n: number) {
      for (let i = 0; i < n; i++) await submit();
    }
    const refusedCard = () => screen.getByRole('heading', { level: 1, name: "This link can't be used" }).parentElement as HTMLElement;

    it('T2: never interrupted, not-sent has no earlier-try line (first press, after a 422, after a 429, twice)', async () => {
      for (const steps of [['not-sent'], ['invalid', 'not-sent'], ['rate-limited', 'not-sent'], ['not-sent', 'not-sent']] as Step[][]) {
        const { unmount } = page(...steps);
        await pressAll(steps.length);
        expect(screen.getByRole('status')).toHaveTextContent(/^Something went wrong on our side\.Try again in a moment\.$/);
        expect(document.body.textContent).not.toContain(EARLIER_TRY);
        unmount();
      }
    });

    it('T3: interrupted, then link-refused: focus on the h1; heading, line, body, second line, Sign in, Ask for a new link — in that order', async () => {
      for (const first of ['unreachable', 'server-error', 'thrown'] as const) {
        const { unmount } = page(first, 'link-refused');
        await pressAll(2);
        const h1 = screen.getByRole('heading', { level: 1, name: "This link can't be used" });
        expect(h1).toHaveFocus();
        expect(refusedCard().textContent).toBe(`This link can't be used${EARLIER_TRY}${REFUSED_BODY}${REFUSED_BODY_2}Sign inAsk for a new link`);
        unmount();
      }
    });

    it('T3b: the flagged card has Sign in, a full document load to /login, before "Ask for a new link"; unflagged cards have none', async () => {
      const { navigate, unmount } = page('unreachable', 'link-refused');
      await pressAll(2);
      const signIn = screen.getByRole('button', { name: 'Sign in' });
      const ask = screen.getByRole('link', { name: 'Ask for a new link' });
      expect(signIn.compareDocumentPosition(ask) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
      expect(ask).toHaveAttribute('href', '/forgot-password');
      fireEvent.click(signIn);
      expect(navigate).toHaveBeenCalledTimes(1);
      expect(navigate).toHaveBeenCalledWith('/login');
      unmount();

      // Unflagged: a first-press 401, and opening with no token.
      const second = page('link-refused');
      await pressAll(1);
      expect(screen.queryByRole('button', { name: 'Sign in' })).toBeNull();
      expect(screen.queryByText('Sign in')).toBeNull();
      second.unmount();
      window.history.replaceState(null, '', '/reset-password');
      renderPage(vi.fn());
      expect(screen.getByRole('heading', { level: 1, name: "This link can't be used" })).toBeInTheDocument();
      expect(screen.queryByRole('button', { name: 'Sign in' })).toBeNull();
      expect(screen.queryByText('Sign in')).toBeNull();
    });

    it('T4: never interrupted, a refusal has no earlier-try line (first press, after 422 / not-sent / 429, and no token)', async () => {
      for (const steps of [['link-refused'], ['invalid', 'link-refused'], ['not-sent', 'link-refused'], ['rate-limited', 'link-refused']] as Step[][]) {
        const { unmount } = page(...steps);
        await pressAll(steps.length);
        expect(refusedCard().textContent).toBe(`This link can't be used${REFUSED_BODY}${REFUSED_BODY_2}Ask for a new link`);
        unmount();
      }
      window.history.replaceState(null, '', '/reset-password');
      renderPage(vi.fn());
      expect(refusedCard().textContent).toBe(`This link can't be used${REFUSED_BODY}${REFUSED_BODY_2}Ask for a new link`);
    });

    it('T5: a 422 shows nothing extra and keeps the flag', async () => {
      const a = page('unreachable', 'invalid', 'link-refused');
      await pressAll(2);
      expect(screen.getByLabelText('New password')).toHaveAccessibleDescription(`At least 12 characters. ${fieldError}`);
      expect(screen.queryByRole('status')).toBeNull();
      expect(document.body.textContent).not.toContain(EARLIER_TRY);
      await pressAll(1);
      expect(refusedCard().textContent).toContain(EARLIER_TRY);
      a.unmount();

      const b = page('unreachable', 'invalid', 'not-sent');
      await pressAll(3);
      expect(screen.getByRole('status').textContent).toBe(`${NOT_SENT}${EARLIER_TRY}`);
      b.unmount();
    });

    it('T6: server-error → 429 → not-sent → link-refused: the line is in each of the three later results', async () => {
      page('server-error', 'rate-limited', 'not-sent', 'link-refused');
      await pressAll(2);
      expect(screen.getByRole('status').textContent).toBe(RATE_LIMITED_EARLIER_TRY);
      await pressAll(1);
      expect(screen.getByRole('status').textContent).toBe(`${NOT_SENT}${EARLIER_TRY}`);
      await pressAll(1);
      expect(refusedCard().textContent).toContain(EARLIER_TRY);
    });

    it('T7: a second interrupted result shows only its own body, never the earlier-try line on top', async () => {
      for (const steps of [['unreachable', 'server-error'], ['server-error', 'unreachable'], ['thrown', 'thrown']] as Step[][]) {
        const { unmount } = page(...steps);
        await pressAll(2);
        const heading = steps[1] === 'server-error' ? 'Something went wrong on our side.' : "We couldn't reach the server.";
        expect(screen.getByRole('status').textContent).toBe(`${heading}${INTERRUPTED_BODY}`);
        expect(document.body.textContent).not.toContain(EARLIER_TRY);
        unmount();
      }
    });

    it('T8: success ends it — the done phase has no earlier-try line and no extra Sign in', async () => {
      page('unreachable', 'changed');
      await pressAll(2);
      expect(screen.getByRole('heading', { level: 1, name: 'Password set' })).toBeInTheDocument();
      expect(document.body.textContent).not.toContain(EARLIER_TRY);
      expect(screen.queryByRole('button', { name: 'Sign in' })).toBeNull(); // not before the 1.1 s wait
    });

    it('T9: the flag is memory only — never stored; a reload (fragment already stripped) shows the plain refusal', async () => {
      const { unmount } = page('unreachable');
      await pressAll(1);
      expect(window.localStorage.length).toBe(0);
      expect(window.sessionStorage.length).toBe(0);
      expect(document.cookie).toBe('');
      unmount();
      // Named residual (b4-doubt-ux.md §4.3 "Reload"): the doubt is lost; the plain copy is still true.
      window.history.replaceState(null, '', '/reset-password');
      renderPage(vi.fn());
      expect(refusedCard().textContent).toBe(`This link can't be used${REFUSED_BODY}${REFUSED_BODY_2}Ask for a new link`);
    });

    it('T10: one live region on flagged not-sent, with the line inside; on the flagged card nothing new is live or focused', async () => {
      const a = page('unreachable', 'not-sent');
      await pressAll(2);
      const regions = screen.getAllByRole('status');
      expect(regions).toHaveLength(1);
      expect(document.querySelectorAll('[aria-live]')).toHaveLength(1);
      const line = screen.getByText(EARLIER_TRY);
      expect(regions[0]).toContainElement(line);
      expect(line).not.toHaveAttribute('role');
      expect(line).not.toHaveAttribute('tabindex');
      a.unmount();

      page('server-error', 'link-refused');
      await pressAll(2);
      const cardLine = screen.getByText(EARLIER_TRY);
      const signIn = screen.getByRole('button', { name: 'Sign in' });
      for (const el of [cardLine, signIn]) expect(el.closest('[role="status"],[role="alert"],[aria-live]')).toBeNull();
      expect(document.querySelectorAll('[role="status"],[role="alert"],[aria-live]')).toHaveLength(0);
      expect(screen.getByRole('heading', { level: 1, name: "This link can't be used" })).toHaveFocus();
      expect(signIn).not.toHaveFocus();
    });
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

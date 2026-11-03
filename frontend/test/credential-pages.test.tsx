import { readdirSync, readFileSync, statSync } from 'node:fs';
import path from 'node:path';
import { act, fireEvent, render, screen } from '@testing-library/react';
import axe from 'axe-core';
import { afterEach, describe, expect, it, vi } from 'vitest';
import nextConfig, { CREDENTIAL_PAGE_ROUTES } from '../next.config';
import * as forgotPage from '@/app/(auth)/forgot-password/page';
import * as resetPage from '@/app/(auth)/reset-password/page';
import { ForgotPasswordForm } from '@/components/auth/ForgotPasswordForm';
import { ResetPasswordForm } from '@/components/auth/ResetPasswordForm';
import { ChangePasswordForm } from '@/components/auth/ChangePasswordForm';
import { SignInCard } from '@/components/auth/SignInCard';
import * as passwordCopy from '@/copy/password';

const ROOT = path.resolve(__dirname, '..');
const NEVER_SENT_CLAIM = /emailed|email sent|we've sent|we have sent|check your inbox|sent you/i;

function filesUnder(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const p = path.join(dir, name);
    return statSync(p).isDirectory() ? filesUnder(p) : [p];
  });
}

describe('credential routes — response headers (§1.4, BFF note §8)', () => {
  it.each([...CREDENTIAL_PAGE_ROUTES])('%s sends Referrer-Policy: no-referrer and Cache-Control: no-store', async (route) => {
    const rules = (await nextConfig.headers?.()) ?? [];
    const rule = rules.find((r) => r.source === route);
    expect(rule, `no headers rule for ${route}`).toBeDefined();
    const headers = Object.fromEntries((rule?.headers ?? []).map((h) => [h.key.toLowerCase(), h.value]));
    expect(headers['referrer-policy']).toBe('no-referrer');
    expect(headers['cache-control']).toBe('no-store');
  });

  it.each([
    ['forgot-password', forgotPage],
    ['reset-password', resetPage],
  ] as const)('%s is rendered per request (Next then sends no-store itself) and carries the no-referrer meta', (_name, mod) => {
    expect(mod.dynamic).toBe('force-dynamic');
    expect(mod.metadata.referrer).toBe('no-referrer');
  });
});

describe('credential pages — what they must never contain', () => {
  const sources = [
    ...filesUnder(path.join(ROOT, 'src/app/(auth)')),
    ...filesUnder(path.join(ROOT, 'src/components/auth')),
    ...filesUnder(path.join(ROOT, 'src/lib/auth')),
    path.join(ROOT, 'src/copy/password.ts'),
  ].filter((f) => /\.(ts|tsx)$/.test(f));

  it('scans a non-empty set of sources', () => {
    expect(sources.length).toBeGreaterThanOrEqual(10);
  });

  it.each(sources.map((f) => [path.relative(ROOT, f), f]))(
    '%s: no fetch, no storage, no cookie, no third-party or analytics script',
    (_rel, file) => {
      const src = readFileSync(file, 'utf8').replace(/^\s*(\/\/|\*|\/\*).*$/gm, ''); // code, not comments
      expect(src).not.toMatch(/\bfetch\s*\(|XMLHttpRequest|sendBeacon/);
      expect(src).not.toMatch(/localStorage|sessionStorage|indexedDB|document\.cookie/);
      expect(src).not.toMatch(/next\/script|<script|https?:\/\//i);
      expect(src).not.toMatch(/analytics|sentry|datadog|gtag|posthog/i);
    },
  );

  it('no string in the credential copy claims an email was sent', () => {
    const all = JSON.stringify(passwordCopy);
    expect(all).not.toMatch(NEVER_SENT_CLAIM);
  });
});

describe('axe — credential pages', () => {
  afterEach(() => {
    window.history.replaceState(null, '', '/');
  });

  async function expectNoViolations(container: HTMLElement) {
    const result = await axe.run(container, { rules: { 'color-contrast': { enabled: false } } });
    expect(result.violations.map((v) => `${v.id}: ${v.nodes.length} × ${v.help}`)).toEqual([]);
  }

  it('a signed-out credential page is the main landmark (no app frame around it)', () => {
    window.history.replaceState(null, '', '/reset-password');
    for (const ui of [
      <SignInCard key="s" onSubmit={vi.fn()} />,
      <ForgotPasswordForm key="f" requestReset={vi.fn()} />,
      <ResetPasswordForm key="r" redeem={vi.fn()} />,
    ]) {
      const { unmount } = render(ui);
      expect(screen.getAllByRole('main')).toHaveLength(1);
      unmount();
    }
    // In-app: the page provides <main>; the card must not add a second one.
    render(<ChangePasswordForm changePassword={vi.fn()} onSessionEnded={vi.fn()} />);
    expect(screen.queryByRole('main')).toBeNull();
  });

  it('sign-in card with the new link', async () => {
    const { container } = render(<SignInCard onSubmit={vi.fn()} />);
    await expectNoViolations(container);
  });

  it('request page: form, 422, 429, result', async () => {
    const action = vi
      .fn()
      .mockResolvedValueOnce({ kind: 'invalid', fieldErrors: { workspace: 'Enter the workspace name you sign in with.', email: 'Enter an email address.' } })
      .mockResolvedValueOnce({ kind: 'rate-limited' })
      .mockResolvedValueOnce({ kind: 'accepted' });
    const { container } = render(<ForgotPasswordForm requestReset={action} />);
    await expectNoViolations(container);
    for (let i = 0; i < 3; i++) {
      await act(async () => {
        fireEvent.click(screen.getByRole('button', { name: 'Send link' }));
      });
      await expectNoViolations(container);
    }
    expect(screen.getByRole('heading', { name: 'Check your email' })).toBeInTheDocument();
  });

  // forgot-heading-ux.md Revision 1 T10: the three interrupted / not-sent banners, each with its described heading.
  it('request page: unreachable, server error, not sent', async () => {
    const action = vi
      .fn()
      .mockResolvedValueOnce({ kind: 'unreachable' })
      .mockResolvedValueOnce({ kind: 'server-error' })
      .mockResolvedValueOnce({ kind: 'not-sent' });
    const { container } = render(<ForgotPasswordForm requestReset={action} />);
    for (const heading of ["We couldn't reach the server.", 'Something went wrong on our side.', 'Something went wrong on our side.']) {
      await act(async () => {
        fireEvent.click(screen.getByRole('button', { name: 'Send link' }));
      });
      expect(screen.getByRole('status').textContent).toContain(heading);
      await expectNoViolations(container);
    }
    expect(action).toHaveBeenCalledTimes(3);
  });

  it('set-new-password page: form, 422, refusal; and success', async () => {
    window.history.replaceState(null, '', '/reset-password#token=abc');
    const action = vi
      .fn()
      .mockResolvedValueOnce({ kind: 'invalid', fieldErrors: { password: 'The new password must not be empty.' } })
      .mockResolvedValueOnce({ kind: 'link-refused' });
    const first = render(<ResetPasswordForm redeem={action} />);
    await expectNoViolations(first.container);
    for (let i = 0; i < 2; i++) {
      await act(async () => {
        fireEvent.click(screen.getByRole('button', { name: 'Set password' }));
      });
      await expectNoViolations(first.container);
    }
    first.unmount();

    window.history.replaceState(null, '', '/reset-password#token=abc');
    const done = render(<ResetPasswordForm redeem={async () => ({ kind: 'changed', changedAt: '2026-10-27T10:00:00+00:00' })} />);
    await act(async () => {
      fireEvent.click(screen.getByRole('button', { name: 'Set password' }));
    });
    await expectNoViolations(done.container);
  });

  it('change-password page: form, 422, success', async () => {
    const action = vi
      .fn()
      .mockResolvedValueOnce({ kind: 'invalid', fieldErrors: { current_password: 'The current password is not correct.' } })
      .mockResolvedValueOnce({ kind: 'changed' });
    const { container } = render(<ChangePasswordForm changePassword={action} onSessionEnded={vi.fn()} />);
    await expectNoViolations(container);
    for (let i = 0; i < 2; i++) {
      await act(async () => {
        fireEvent.click(screen.getByRole('button', { name: 'Change password' }));
      });
      await expectNoViolations(container);
    }
  });
});

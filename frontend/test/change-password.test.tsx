import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ChangePasswordForm } from '@/components/auth/ChangePasswordForm';
import type { ChangePasswordAction } from '@/lib/auth/results';

// ux-copy-frontend.md §1.5, the D2 = yes variant (owner ruling 2026-10-27, P-A), typed independently of src/copy.
const DONE_BODY = "You've been signed out everywhere, including here. Sign in with your new password.";
const NEVER_SENT_CLAIM = /emailed|email sent|we've sent|we have sent|check your inbox|sent you/i;

async function fillAndSubmit(current = 'old password here', next = 'a brand new password') {
  fireEvent.change(screen.getByLabelText('Current password'), { target: { value: current } });
  fireEvent.change(screen.getByLabelText('New password'), { target: { value: next } });
  await act(async () => {
    fireEvent.click(screen.getByRole('button', { name: 'Change password' }));
  });
}

describe('ChangePasswordForm (/account/password)', () => {
  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
  });

  it('F-2: the form submits as a post, so a native submit never puts either password in the address bar', () => {
    render(<ChangePasswordForm changePassword={vi.fn<ChangePasswordAction>()} onSessionEnded={vi.fn()} />);
    const form = screen.getByRole('button', { name: 'Change password' }).closest('form');
    expect(form?.method).toBe('post');
    expect(form).not.toHaveAttribute('action');
  });

  it('two fields with the right autocomplete; posts {current_password, new_password}', async () => {
    const action = vi.fn<ChangePasswordAction>(async () => ({ kind: 'session-ended' }));
    render(<ChangePasswordForm changePassword={action} onSessionEnded={vi.fn()} />);
    expect(screen.getByRole('heading', { level: 1, name: 'Change password' })).toBeInTheDocument();
    expect(screen.getByLabelText('Current password')).toHaveAttribute('autocomplete', 'current-password');
    expect(screen.getByLabelText('New password')).toHaveAttribute('autocomplete', 'new-password');
    await fillAndSubmit('old', 'new one!');
    expect(action).toHaveBeenCalledWith({ current_password: 'old', new_password: 'new one!' });
  });

  it("422: the server's sentences on their fields; focus to the first invalid one", async () => {
    render(
      <ChangePasswordForm
        changePassword={async () => ({
          kind: 'invalid',
          fieldErrors: { current_password: 'The current password is not correct.', new_password: 'The new password must be at least 12 characters.' },
        })}
        onSessionEnded={vi.fn()}
      />,
    );
    await fillAndSubmit();
    expect(screen.getByLabelText('Current password')).toHaveFocus();
    expect(screen.getByLabelText('Current password')).toHaveAccessibleDescription('The current password is not correct.');
    expect(screen.getByLabelText('New password')).toHaveAccessibleDescription('The new password must be at least 12 characters.');
  });

  it('401 hands over to the caller as "session ended"', async () => {
    const ended = vi.fn();
    render(<ChangePasswordForm changePassword={async () => ({ kind: 'session-ended' })} onSessionEnded={ended} />);
    await fillAndSubmit();
    expect(ended).toHaveBeenCalledTimes(1);
  });

  it('429, network failure and 5xx show their notices and keep the form', async () => {
    const action = vi
      .fn<ChangePasswordAction>()
      .mockResolvedValueOnce({ kind: 'rate-limited' })
      .mockRejectedValueOnce(new TypeError('Failed to fetch'))
      .mockResolvedValueOnce({ kind: 'server-error' });
    render(<ChangePasswordForm changePassword={action} onSessionEnded={vi.fn()} />);
    await fillAndSubmit();
    expect(screen.getByRole('status')).toHaveTextContent('Too many attempts.Try again in a few minutes.');
    await fillAndSubmit();
    expect(screen.getByRole('status')).toHaveTextContent("We couldn't reach the server.Check your connection and try again.");
    await fillAndSubmit();
    expect(screen.getByRole('status')).toHaveTextContent('Something went wrong on our side.Try again in a moment.');
  });

  describe('success under D2 = yes', () => {
    let clock = 0;
    beforeEach(() => {
      vi.useFakeTimers();
      clock = 10_000;
      vi.spyOn(performance, 'now').mockImplementation(() => clock);
    });

    it('"Password changed" + signed out everywhere, including here; Sign in ≥ 1.1 s after the response; full load to /login', async () => {
      const navigate = vi.fn();
      render(<ChangePasswordForm changePassword={async () => ({ kind: 'changed' })} onSessionEnded={vi.fn()} navigate={navigate} />);
      await fillAndSubmit();
      expect(screen.getByRole('heading', { level: 1, name: 'Password changed' })).toHaveFocus();
      expect(screen.getByText(DONE_BODY)).toBeInTheDocument();
      expect(screen.queryByLabelText('Current password')).toBeNull();
      expect(screen.queryByRole('button', { name: 'Sign in' })).toBeNull();

      clock += 1099;
      await act(async () => {
        vi.advanceTimersByTime(1099);
      });
      expect(screen.queryByRole('button', { name: 'Sign in' })).toBeNull();
      clock += 1;
      await act(async () => {
        vi.advanceTimersByTime(1);
      });
      expect(screen.getByRole('status')).toHaveTextContent('You can sign in now.');
      fireEvent.click(screen.getByRole('button', { name: 'Sign in' }));
      expect(navigate).toHaveBeenCalledWith('/login');
      expect(document.body.textContent).not.toMatch(NEVER_SENT_CLAIM);
    });
  });
});

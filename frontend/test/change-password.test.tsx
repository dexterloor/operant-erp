import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ChangePasswordForm } from '@/components/auth/ChangePasswordForm';
import type { ChangePasswordAction } from '@/lib/auth/results';

// ux-copy-frontend.md §1.5, the D2 = yes variant (owner ruling 2026-10-27, P-A), typed independently of src/copy.
const DONE_BODY = "You've been signed out everywhere, including here. Sign in with your new password.";
const NEVER_SENT_CLAIM = /emailed|email sent|we've sent|we have sent|check your inbox|sent you/i;
/** PROPOSED (c10-design.md §6, FINAL B.5), typed here independently of src/copy. */
const INTERRUPTED_BODY =
  "We can't tell whether your password was changed. Press Change password again: if it was changed, you'll be asked to sign in, and your new password will work.";

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

  it('F-3: 429 shows the ruled two lines plus "Nothing was changed."; the form and typed values stay', async () => {
    render(<ChangePasswordForm changePassword={async () => ({ kind: 'rate-limited' })} onSessionEnded={vi.fn()} />);
    await fillAndSubmit('kept current', 'kept new password');
    expect(screen.getByRole('status')).toHaveTextContent('Too many attempts.Try again in a few minutes.Nothing was changed.');
    expect(screen.getByLabelText('Current password')).toHaveValue('kept current');
    expect(screen.getByLabelText('New password')).toHaveValue('kept new password');
  });

  it('F-1: a lost answer (network failure or 5xx) says the outcome is unknown and how to resolve it; values kept', async () => {
    const action = vi.fn<ChangePasswordAction>().mockRejectedValueOnce(new TypeError('Failed to fetch')).mockResolvedValueOnce({ kind: 'unreachable' }).mockResolvedValueOnce({ kind: 'server-error' });
    render(<ChangePasswordForm changePassword={action} onSessionEnded={vi.fn()} />);
    await fillAndSubmit('kept current', 'kept new password');
    expect(screen.getByRole('status')).toHaveTextContent(`We couldn't reach the server.${INTERRUPTED_BODY}`);
    await fillAndSubmit('kept current', 'kept new password');
    expect(screen.getByRole('status')).toHaveTextContent(`We couldn't reach the server.${INTERRUPTED_BODY}`);
    await fillAndSubmit('kept current', 'kept new password');
    expect(screen.getByRole('status')).toHaveTextContent(`Something went wrong on our side.${INTERRUPTED_BODY}`);
    expect(screen.getByLabelText('Current password')).toHaveValue('kept current');
  });

  it('F-1: a request the web app\'s server refused before sending says "Try again in a moment.", never the "can\'t tell" body', async () => {
    render(<ChangePasswordForm changePassword={async () => ({ kind: 'not-sent' })} onSessionEnded={vi.fn()} />);
    await fillAndSubmit();
    expect(screen.getByRole('status')).toHaveTextContent('Something went wrong on our side.Try again in a moment.');
    expect(document.body.textContent).not.toContain(INTERRUPTED_BODY);
  });

  it('F-4: session-changed → the injected reload, once; no banner, no session-ended hand-over', async () => {
    const reload = vi.fn();
    const ended = vi.fn();
    render(<ChangePasswordForm changePassword={async () => ({ kind: 'session-changed' })} onSessionEnded={ended} onSessionChanged={reload} />);
    await fillAndSubmit();
    expect(reload).toHaveBeenCalledTimes(1);
    expect(ended).not.toHaveBeenCalled();
    expect(screen.queryByRole('status')).toBeNull();
  });

  it('F-7 (M-1): one request at a time, and the done card ignores anything after it', async () => {
    let resolve: (r: Awaited<ReturnType<ChangePasswordAction>>) => void = () => {};
    const action = vi.fn<ChangePasswordAction>(() => new Promise((r) => (resolve = r)));
    const ended = vi.fn();
    const { container } = render(<ChangePasswordForm changePassword={action} onSessionEnded={ended} />);
    fireEvent.change(screen.getByLabelText('Current password'), { target: { value: 'old password here' } });
    fireEvent.change(screen.getByLabelText('New password'), { target: { value: 'a brand new password' } });
    const form = container.querySelector('form')!;
    // Two clicks, then Enter in a field (a submit event on the form), all before the first answer.
    fireEvent.click(screen.getByRole('button', { name: 'Change password' }));
    fireEvent.click(screen.getByRole('button', { name: 'Change password' }));
    fireEvent.submit(form);
    expect(action).toHaveBeenCalledTimes(1);
    await act(async () => resolve({ kind: 'changed' }));
    expect(screen.getByRole('heading', { level: 1, name: 'Password changed' })).toBeInTheDocument();
    // The form is gone; a late submit event (a stale handler, a second press that raced the answer) changes nothing.
    expect(container.querySelector('form')).toBeNull();
    fireEvent.submit(form);
    expect(action).toHaveBeenCalledTimes(1);
    expect(ended).not.toHaveBeenCalled();
    expect(screen.getByRole('heading', { level: 1, name: 'Password changed' })).toBeInTheDocument();
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

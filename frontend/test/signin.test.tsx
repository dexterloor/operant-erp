import { act, fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { SignInCard } from '@/components/auth/SignInCard';

// RULED copy from the brief §9, typed here independently of src/copy so a drift in either is caught.
const FAILURE = [
  "Those details don't match an account.",
  'Check your workspace name, email and password.',
  'We never explain sign-in problems on this screen. If there is something you need to know about your account, it goes to its email address.',
];

describe('SignInCard', () => {
  it('has exactly three fields — Workspace, Email, Password — a Sign in button, and one link: "Forgot your password?"', () => {
    render(<SignInCard onSubmit={vi.fn()} />);
    expect(screen.getByLabelText('Workspace')).toBeInTheDocument();
    expect(screen.getByLabelText('Email')).toBeInTheDocument();
    expect(screen.getByLabelText('Password')).toHaveAttribute('type', 'password');
    expect(document.querySelectorAll('input')).toHaveLength(3);
    expect(screen.getByRole('button', { name: 'Sign in' })).toBeInTheDocument();
    // ux-copy-frontend.md §1.2 (supersedes the brief's "no Forgot password link"); still no sign-up link.
    const links = screen.getAllByRole('link');
    expect(links).toHaveLength(1);
    expect(links[0]).toHaveAccessibleName('Forgot your password?');
  });

  it('the "Forgot your password?" link carries nothing in the URL — not the workspace, not the email (§1.2)', async () => {
    const user = userEvent.setup();
    render(<SignInCard onSubmit={vi.fn()} workspace={{ value: 'acme', source: 'remembered' }} />);
    await user.type(screen.getByLabelText('Email'), 'e@example.com');
    expect(screen.getByRole('link', { name: 'Forgot your password?' })).toHaveAttribute('href', '/forgot-password');
  });

  it('focuses the first empty field on arrival', () => {
    render(<SignInCard onSubmit={vi.fn()} workspace={{ value: 'ws', source: 'invitation' }} />);
    expect(screen.getByLabelText('Email')).toHaveFocus();
    expect(screen.getByText('Filled in from your invitation link.')).toBeInTheDocument();
  });

  it('failure: one message for every cause, word for word; focus to the first field; password cleared', async () => {
    const { rerender } = render(<SignInCard onSubmit={vi.fn()} />);
    // fireEvent rather than userEvent here, and the rerender in an awaited act: with userEvent's per-keystroke sync act
    // followed by this refusal's effects (password clear + focus move), React 19.3 found work still queued after a sync
    // act and printed "A component suspended inside an `act` scope, but the `act` call was not awaited" (non-failing,
    // left by B-3a). Typing behaviour is covered by the submit test below; this test is about the refusal.
    fireEvent.change(screen.getByLabelText('Workspace'), { target: { value: 'w' } });
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'e@example.com' } });
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'secret' } });
    await act(async () => {
      rerender(<SignInCard onSubmit={vi.fn()} notice="failure" attempt={1} />);
    });
    const status = screen.getByRole('status');
    for (const s of FAILURE) expect(status).toHaveTextContent(s);
    expect(screen.getByLabelText('Workspace')).toHaveFocus();
    expect(screen.getByLabelText('Workspace')).toHaveValue('w');
    expect(screen.getByLabelText('Email')).toHaveValue('e@example.com');
    expect(screen.getByLabelText('Password')).toHaveValue('');
    expect(document.body.textContent).not.toMatch(/attempts? (left|remaining)|minutes? left/i);
  });

  it('IP rate limit is a polite status with the ruled copy', () => {
    render(<SignInCard onSubmit={vi.fn()} notice="rate-limited" />);
    expect(screen.getByRole('status')).toHaveTextContent('Too many attempts.Try again in a few minutes.');
  });

  it('account pause is an alert with the ruled copy', () => {
    render(<SignInCard onSubmit={vi.fn()} notice="paused" />);
    expect(screen.getByRole('alert')).toHaveTextContent('Too many failed attempts.Sign-in with this workspace and email is paused for 15 minutes.');
  });

  it('session ended shows the info notice and is not announced as an error', () => {
    render(<SignInCard onSubmit={vi.fn()} notice="session-ended" />);
    expect(screen.getByText('You were signed out.')).toBeInTheDocument();
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('T-L13: the session-ended notice is exactly three lines of fixed text (b4-owed-copy.md R1-4; design §12)', () => {
    render(<SignInCard onSubmit={vi.fn()} notice="session-ended" />);
    const text = screen.getByText('You were signed out.').parentElement!;
    const lines = [...text.querySelectorAll('p')].map((p) => p.textContent);
    expect(lines).toEqual([
      'You were signed out.',
      'Sign in again to continue.',
      'If you were saving changes, check them after you sign in: some may not have been saved.',
    ]);
  });

  it('C2-2: the server cannot be reached → the connection notice, never the failure sentence', () => {
    render(<SignInCard onSubmit={vi.fn()} notice="unreachable" />);
    expect(screen.getByRole('status')).toHaveTextContent("We couldn't reach the server.Check your connection and try again.");
    expect(screen.queryByText(FAILURE[0]!)).toBeNull();
  });

  it('C2-2: a server failure or an unexpected answer → the "on our side" notice, never the failure sentence', () => {
    render(<SignInCard onSubmit={vi.fn()} notice="server-error" />);
    expect(screen.getByRole('status')).toHaveTextContent('Something went wrong on our side.Try again in a moment.');
    expect(screen.queryByText(FAILURE[0]!)).toBeNull();
  });

  it('submits the three values; while submitting the button is busy, not disabled, and ignores clicks', async () => {
    const user = userEvent.setup();
    const onSubmit = vi.fn();
    const { rerender } = render(<SignInCard onSubmit={onSubmit} />);
    await user.type(screen.getByLabelText('Workspace'), 'w');
    await user.type(screen.getByLabelText('Email'), 'e@example.com');
    await user.type(screen.getByLabelText('Password'), 'pw');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));
    expect(onSubmit).toHaveBeenCalledWith({ workspace: 'w', email: 'e@example.com', password: 'pw' });
    rerender(<SignInCard onSubmit={onSubmit} submitting />);
    const btn = screen.getByRole('button', { name: 'Sign in' });
    expect(btn).toHaveAttribute('aria-busy', 'true');
    expect(btn).not.toBeDisabled();
    await user.click(btn);
    expect(onSubmit).toHaveBeenCalledTimes(1);
  });

  it('remembered workspace offers "Use another workspace"', async () => {
    const user = userEvent.setup();
    const forget = vi.fn();
    render(<SignInCard onSubmit={vi.fn()} workspace={{ value: 'ws', source: 'remembered' }} onForgetWorkspace={forget} />);
    expect(screen.getByText(/Remembered on this device\./)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Use another workspace' }));
    expect(forget).toHaveBeenCalled();
  });
});

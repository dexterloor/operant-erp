import { render, screen, waitFor } from '@testing-library/react';
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
  it('has exactly three fields — Workspace, Email, Password — and a Sign in button; no reset or sign-up link yet', () => {
    render(<SignInCard onSubmit={vi.fn()} />);
    expect(screen.getByLabelText('Workspace')).toBeInTheDocument();
    expect(screen.getByLabelText('Email')).toBeInTheDocument();
    expect(screen.getByLabelText('Password')).toHaveAttribute('type', 'password');
    expect(document.querySelectorAll('input')).toHaveLength(3);
    expect(screen.getByRole('button', { name: 'Sign in' })).toBeInTheDocument();
    expect(screen.queryByRole('link')).toBeNull();
  });

  it('focuses the first empty field on arrival', () => {
    render(<SignInCard onSubmit={vi.fn()} workspace={{ value: 'ws', source: 'invitation' }} />);
    expect(screen.getByLabelText('Email')).toHaveFocus();
    expect(screen.getByText('Filled in from your invitation link.')).toBeInTheDocument();
  });

  it('failure: one message for every cause, word for word; focus to the first field; password cleared', async () => {
    const user = userEvent.setup();
    const { rerender } = render(<SignInCard onSubmit={vi.fn()} />);
    await user.type(screen.getByLabelText('Workspace'), 'w');
    await user.type(screen.getByLabelText('Email'), 'e@example.com');
    await user.type(screen.getByLabelText('Password'), 'secret');
    rerender(<SignInCard onSubmit={vi.fn()} notice="failure" attempt={1} />);
    const status = screen.getByRole('status');
    for (const s of FAILURE) expect(status).toHaveTextContent(s);
    expect(screen.getByLabelText('Workspace')).toHaveFocus();
    expect(screen.getByLabelText('Workspace')).toHaveValue('w');
    expect(screen.getByLabelText('Email')).toHaveValue('e@example.com');
    await waitFor(() => expect(screen.getByLabelText('Password')).toHaveValue(''));
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

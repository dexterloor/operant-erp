import { act, fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ForgotPasswordForm } from '@/components/auth/ForgotPasswordForm';
import type { RequestResetResult } from '@/lib/auth/results';

// Copy typed here from docs/design/ux-copy-frontend.md §1.3, independently of src/copy, so a drift in either is caught.
const INTRO = "Enter the workspace name and email you sign in with. If they belong to an active account, we'll send a link to set a new password.";
const RESULT_BODY =
  "If that email belongs to an active account in that workspace, we'll send it a link. The link works once, for 30 minutes, and we send at most three links for one account in 24 hours.";
const RESULT_BODY_2 =
  "Your password hasn't changed. If no email arrives in a few minutes, check the workspace name and email, or ask the person who manages your workspace.";
const SHARED_LIMIT = 'This limit is shared with signing in, so it can also come from other people on the same network.';
/** §1.3 "Never on this page". */
const NEVER_SENT_CLAIM = /emailed|email sent|we've sent|we have sent|check your inbox|sent you/i;

async function submitWith(result: RequestResetResult | Error, values = { workspace: 'acme', email: 'pat@example.com' }) {
  const action = vi.fn(async () => {
    if (result instanceof Error) throw result;
    return result;
  });
  const view = render(<ForgotPasswordForm requestReset={action} />);
  fireEvent.change(screen.getByLabelText('Workspace'), { target: { value: values.workspace } });
  fireEvent.change(screen.getByLabelText('Email'), { target: { value: values.email } });
  await act(async () => {
    fireEvent.click(screen.getByRole('button', { name: 'Send link' }));
  });
  return { action, ...view };
}

describe('ForgotPasswordForm (/forgot-password)', () => {
  it('renders the request form word for word: heading, intro, Workspace, Email (autocomplete=email), Send link, Back to sign in', () => {
    render(<ForgotPasswordForm requestReset={vi.fn()} />);
    expect(screen.getByRole('heading', { level: 1, name: 'Reset your password' })).toBeInTheDocument();
    expect(screen.getByText(INTRO)).toBeInTheDocument();
    expect(screen.getByLabelText('Workspace')).toHaveFocus();
    expect(screen.getByLabelText('Email')).toHaveAttribute('autocomplete', 'email');
    expect(screen.getByRole('button', { name: 'Send link' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Back to sign in' })).toHaveAttribute('href', '/login');
  });

  it('pre-fills only the remembered workspace and then focuses Email', () => {
    render(<ForgotPasswordForm requestReset={vi.fn()} rememberedWorkspace="acme" />);
    expect(screen.getByLabelText('Workspace')).toHaveValue('acme');
    expect(screen.getByLabelText('Email')).toHaveFocus();
  });

  it('sends exactly {workspace, email}', async () => {
    const { action } = await submitWith({ kind: 'accepted' });
    expect(action).toHaveBeenCalledWith({ workspace: 'acme', email: 'pat@example.com' });
  });

  it('202: one result state, word for word, focus on its heading; never says mail was sent', async () => {
    await submitWith({ kind: 'accepted' });
    const h1 = screen.getByRole('heading', { level: 1, name: 'Check your email' });
    expect(h1).toHaveFocus();
    expect(screen.getByText(RESULT_BODY)).toBeInTheDocument();
    expect(screen.getByText(RESULT_BODY_2)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Back to sign in' })).toHaveAttribute('href', '/login');
    expect(screen.getByRole('button', { name: 'Try different details' })).toBeInTheDocument();
    expect(document.body.textContent).not.toMatch(NEVER_SENT_CLAIM);
    // The address is never echoed back as a confirmation.
    expect(document.body.textContent).not.toContain('pat@example.com');
    expect(document.body.textContent).not.toContain('acme');
  });

  it('202: the result is identical whatever was typed (no oracle, ST-7.1)', async () => {
    const a = await submitWith({ kind: 'accepted' }, { workspace: 'acme', email: 'real@example.com' });
    const first = a.container.innerHTML.replace(/_r_[a-z0-9]+_|«[^»]+»|:r[0-9a-z]+:/g, 'ID');
    a.unmount();
    const b = await submitWith({ kind: 'accepted' }, { workspace: 'nope', email: 'nobody@example.org' });
    const second = b.container.innerHTML.replace(/_r_[a-z0-9]+_|«[^»]+»|:r[0-9a-z]+:/g, 'ID');
    expect(second).toBe(first);
  });

  it('"Try different details" returns to the form with the fields kept', async () => {
    await submitWith({ kind: 'accepted' });
    await act(async () => {
      fireEvent.click(screen.getByRole('button', { name: 'Try different details' }));
    });
    expect(screen.getByLabelText('Workspace')).toHaveValue('acme');
    expect(screen.getByLabelText('Email')).toHaveValue('pat@example.com');
  });

  it("422: the server's sentences on their fields, tied by aria-describedby, focus to the first invalid field", async () => {
    await submitWith({
      kind: 'invalid',
      fieldErrors: { workspace: 'Enter the workspace name you sign in with.', email: 'Enter an email address.' },
    });
    const ws = screen.getByLabelText('Workspace');
    const email = screen.getByLabelText('Email');
    expect(ws).toHaveFocus();
    expect(ws).toHaveAttribute('aria-invalid', 'true');
    expect(ws).toHaveAccessibleDescription('Enter the workspace name you sign in with.');
    expect(email).toHaveAccessibleDescription('Enter an email address.');
  });

  it('422 on email only focuses Email', async () => {
    await submitWith({ kind: 'invalid', fieldErrors: { email: 'Enter an email address.' } });
    expect(screen.getByLabelText('Email')).toHaveFocus();
    expect(screen.getByLabelText('Workspace')).not.toHaveAttribute('aria-invalid');
  });

  it('429: the ruled two lines plus the shared-limit line, as a polite status', async () => {
    await submitWith({ kind: 'rate-limited' });
    const status = screen.getByRole('status');
    expect(status).toHaveTextContent('Too many attempts.');
    expect(status).toHaveTextContent('Try again in a few minutes.');
    expect(status).toHaveTextContent(SHARED_LIMIT);
    expect(document.body.textContent).not.toMatch(NEVER_SENT_CLAIM);
  });

  it('network failure (rejected action or "unreachable"): nothing was sent', async () => {
    await submitWith(new TypeError('Failed to fetch'));
    expect(screen.getByRole('status')).toHaveTextContent("We couldn't reach the server, so nothing was sent.Check your connection and try again.");
  });

  it('5xx: a bounded message, never the server detail', async () => {
    await submitWith({ kind: 'server-error' });
    expect(screen.getByRole('status')).toHaveTextContent('Something went wrong on our side.Try again in a moment.');
  });
});

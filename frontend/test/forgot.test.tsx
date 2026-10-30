import { act, fireEvent, render, screen } from '@testing-library/react';
import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { describe, expect, it, vi } from 'vitest';
import { ForgotPasswordForm } from '@/components/auth/ForgotPasswordForm';
import { ResetPasswordForm } from '@/components/auth/ResetPasswordForm';
import type { RedeemResetResult, RequestResetResult } from '@/lib/auth/results';

// Copy typed here from docs/design/ux-copy-frontend.md §1.3, independently of src/copy, so a drift in either is caught.
const INTRO = "Enter the workspace name and email you sign in with. If they belong to an active account, we'll send a link to set a new password.";
const RESULT_BODY =
  "If that email belongs to an active account in that workspace, we'll send it a link. The link works once, for 30 minutes, and we send at most three links for one account in 24 hours.";
const RESULT_BODY_2 =
  "Your password hasn't changed. If no email arrives in a few minutes, check the workspace name and email, or ask the person who manages your workspace.";
const SHARED_LIMIT = 'This limit is shared with signing in, so it can also come from other people on the same network.';
/** The R1-1 house headings, typed here (forgot-heading-ux.md §3). */
const COULD_NOT_REACH = "We couldn't reach the server.";
const WENT_WRONG = 'Something went wrong on our side.';
/** forgot-heading-ux.md §3 / Revision 1: the body of the interrupted notices on this page. */
const INTERRUPTED_BODY =
  "We can't tell whether your request got through. If an email with a link arrives, use it. If none arrives in a few minutes, press Send link again.";
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
  it('F-2: the form submits as a post, so a submit before hydration never puts the workspace or email in the address bar', () => {
    render(<ForgotPasswordForm requestReset={vi.fn()} />);
    const form = screen.getByRole('button', { name: 'Send link' }).closest('form');
    expect(form?.method).toBe('post');
    expect(form).not.toHaveAttribute('action');
  });

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

  // forgot-heading-ux.md Revision 1 T1: the unreachable banner never claims nothing was sent (a request that reached the
  // server may already be queued), and its full text is exactly the R1-1 heading plus the interrupted body.
  it.each<[string, RequestResetResult | Error]>([
    ['a rejected call', new TypeError('Failed to fetch')],
    ['a resolved "unreachable"', { kind: 'unreachable' }],
  ])('unreachable (%s): exactly the R1-1 heading and the interrupted body; never "nothing was sent"', async (_, result) => {
    await submitWith(result);
    // The negatives first, so a claim that mail was sent fails here by name rather than at the exact-text check.
    expect(document.body.textContent).not.toMatch(NEVER_SENT_CLAIM);
    expect(document.body.textContent).not.toMatch(/nothing was sent/i);
    expect(screen.getByRole('status').textContent).toBe(COULD_NOT_REACH + INTERRUPTED_BODY);
  });

  // T4: the body names the button it tells the person to press; renaming either alone goes red.
  it('the interrupted body names the button: "Send link" and "press Send link again"', async () => {
    await submitWith({ kind: 'unreachable' });
    expect(screen.getByRole('button', { name: 'Send link' })).toBeInTheDocument();
    expect(screen.getByRole('status').textContent).toContain('press Send link again');
  });

  // T5: after an interrupted result the fields are kept, and a second press sends exactly the same request.
  it('unreachable and server error keep the fields; the next press sends exactly {workspace, email} again', async () => {
    const action = vi
      .fn()
      .mockResolvedValueOnce({ kind: 'unreachable' })
      .mockResolvedValueOnce({ kind: 'server-error' })
      .mockResolvedValueOnce({ kind: 'server-error' });
    render(<ForgotPasswordForm requestReset={action} />);
    fireEvent.change(screen.getByLabelText('Workspace'), { target: { value: 'acme' } });
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'pat@example.com' } });
    for (let i = 0; i < 3; i++) {
      await act(async () => {
        fireEvent.click(screen.getByRole('button', { name: 'Send link' }));
      });
      expect(screen.getByLabelText('Workspace')).toHaveValue('acme');
      expect(screen.getByLabelText('Email')).toHaveValue('pat@example.com');
    }
    expect(action.mock.calls).toEqual([
      [{ workspace: 'acme', email: 'pat@example.com' }],
      [{ workspace: 'acme', email: 'pat@example.com' }],
      [{ workspace: 'acme', email: 'pat@example.com' }],
    ]);
  });

  // T6: the false claim cannot come back through any copy module, comments included.
  it('no src/copy file says "nothing was sent" (file text, comments included)', () => {
    const dir = path.resolve(__dirname, '..', 'src', 'copy');
    const files = readdirSync(dir).filter((f) => f.endsWith('.ts'));
    expect(files).toContain('password.ts');
    for (const f of files) expect(readFileSync(path.join(dir, f), 'utf8'), f).not.toMatch(/nothing was sent/i);
  });

  // T7: the offline icon belongs to the unreachable banner only (class read from lucide-react 1.55.0's output).
  it.each<[RequestResetResult, boolean]>([
    [{ kind: 'unreachable' }, true],
    [{ kind: 'server-error' }, false],
    [{ kind: 'not-sent' }, false],
  ])('%o: offline icon shown = %s', async (result, offline) => {
    await submitWith(result);
    const status = screen.getByRole('status');
    expect(status.querySelectorAll('svg')).toHaveLength(1);
    expect(status.querySelector('svg.lucide-wifi-off') !== null).toBe(offline);
  });

  // T2: a server error may still have queued the request, so it gets the interrupted body under its own heading.
  it('server error: exactly "Something went wrong on our side." and the interrupted body', async () => {
    await submitWith({ kind: 'server-error' });
    expect(screen.getByRole('status').textContent).toBe(WENT_WRONG + INTERRUPTED_BODY);
  });

  // T3: not-sent is known to have sent nothing (the web app's server refused before calling the API), so it keeps its
  // shipped text. The 504 pin is in auth-wired.test.tsx (T11).
  it("not sent (refused by the web app's server): exactly the shipped bounded message, never the interrupted body", async () => {
    await submitWith({ kind: 'not-sent' });
    expect(screen.getByRole('status').textContent).toBe(WENT_WRONG + 'Try again in a moment.');
  });

  // T8 (RED A1): focus lands on the banner heading after each outcome, and again after the SAME outcome a second time,
  // with focus moved away first (fireEvent.click does not move focus in jsdom).
  it.each<RequestResetResult['kind']>(['unreachable', 'server-error', 'not-sent'])(
    '%s: focus goes to the banner heading, and back to it when the same outcome repeats',
    async (kind) => {
      const action = vi.fn(async () => ({ kind }) as RequestResetResult);
      render(<ForgotPasswordForm requestReset={action} />);
      fireEvent.change(screen.getByLabelText('Workspace'), { target: { value: 'acme' } });
      fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'pat@example.com' } });
      const button = screen.getByRole('button', { name: 'Send link' });
      await act(async () => {
        fireEvent.click(button);
      });
      const heading = () => screen.getByRole('status').querySelector('p[tabindex="-1"]');
      expect(heading()).not.toBeNull();
      expect(heading()).toHaveFocus();
      button.focus();
      expect(button).toHaveFocus();
      await act(async () => {
        fireEvent.click(button);
      });
      expect(action).toHaveBeenCalledTimes(2);
      expect(heading()).toHaveFocus();
    },
  );

  // T9: the focused heading is described by exactly its banner's body (opt-in `describeBody` on the three Forgot banners).
  it.each<[RequestResetResult['kind'], string]>([
    ['unreachable', INTERRUPTED_BODY],
    ['server-error', INTERRUPTED_BODY],
    ['not-sent', 'Try again in a moment.'],
  ])('%s: the focused heading is described by exactly the body', async (kind, body) => {
    await submitWith({ kind } as RequestResetResult);
    const heading = screen.getByRole('status').querySelector('p[tabindex="-1"]') as HTMLElement;
    expect(heading).toHaveFocus();
    const id = heading.getAttribute('aria-describedby');
    expect(id).toBeTruthy();
    expect(document.getElementById(id as string)?.textContent).toBe(body);
    expect(heading).toHaveAccessibleDescription(body);
  });

  it('describeBody is opt-in: the reset page not-sent banner and this page\'s 429 banner carry no description', async () => {
    window.history.replaceState(null, '', '/reset-password#token=abc');
    const reset = render(<ResetPasswordForm redeem={vi.fn(async () => ({ kind: 'not-sent' }) as RedeemResetResult)} />);
    fireEvent.change(screen.getByLabelText('New password'), { target: { value: 'correct horse battery' } });
    await act(async () => {
      fireEvent.click(screen.getByRole('button', { name: 'Set password' }));
    });
    const resetHeading = screen.getByRole('status').querySelector('p[tabindex="-1"]');
    expect(resetHeading).not.toBeNull();
    expect(resetHeading).not.toHaveAttribute('aria-describedby');
    reset.unmount();
    window.history.replaceState(null, '', '/');
    await submitWith({ kind: 'rate-limited' });
    const limitHeading = screen.getByRole('status').querySelector('p[tabindex="-1"]');
    expect(limitHeading).not.toBeNull();
    expect(limitHeading).not.toHaveAttribute('aria-describedby');
  });
});

'use client';

import { useEffect, useId, useRef, useState, type ChangeEvent, type FormEvent } from 'react';
import { SIGN_IN } from '@/copy/shell';
import { Button } from '@/components/ui/Button';
import { Banner } from '@/components/ui/Banner';
import { PlainLink, type LinkComponent } from '@/components/shell/types';
import { CREDENTIAL_FALLBACK, FORGOT_LINK } from '@/copy/password';
import { COULD_NOT_REACH, WENT_WRONG } from '@/copy/headings';
import { AuthCard } from './AuthCard';
import styles from './AuthCard.module.css';

/**
 * The ruled sign-in outcomes the card can show (ST-7, ST-8). There is exactly ONE failure state for every cause
 * (unknown workspace, unknown email, wrong password, switched-off account…) — never a variant per cause, never
 * attempts remaining or pause time remaining. Only the IP rate limit and the account pause differ.
 */
export type SignInNotice = 'failure' | 'rate-limited' | 'paused' | 'session-ended' | 'unreachable' | 'server-error';

export interface SignInValues {
  workspace: string;
  email: string;
  password: string;
}

export interface SignInCardProps {
  notice?: SignInNotice | null;
  /** Bump on every refused attempt so focus returns to the first field and the password clears even if the notice is unchanged. */
  attempt?: number;
  submitting?: boolean;
  /** Prefilled workspace and why (IA-O-2 remembered on this device; or filled from an invitation link). */
  workspace?: { value: string; source: 'remembered' | 'invitation' } | null;
  onSubmit: (values: SignInValues) => void;
  /** "Use another workspace": the caller forgets the remembered workspace. */
  onForgetWorkspace?: () => void;
  /** 'embedded' drops the full-viewport backdrop (component preview only). */
  layout?: 'page' | 'embedded';
  /** Move focus on arrival and after a refusal (§10). Off only where several cards share a page (preview). */
  manageFocus?: boolean;
  /** The app passes next/link; tests and the preview use a plain anchor. */
  linkComponent?: LinkComponent;
}

/** Three fields — Workspace, Email, Password — one Sign in button, and the "Forgot your password?" link (D-10, §7.1;
 * ux-copy-frontend.md §1.2, which supersedes the brief's "no Forgot password link"). */
export function SignInCard({ notice = null, attempt = 0, submitting = false, workspace = null, onSubmit, onForgetWorkspace, layout = 'page', manageFocus = true, linkComponent: Link = PlainLink }: SignInCardProps) {
  const ids = { ws: useId(), email: useId(), pw: useId(), wsHint: useId() };
  const [values, setValues] = useState<SignInValues>({ workspace: workspace?.value ?? '', email: '', password: '' });
  const wsRef = useRef<HTMLInputElement>(null);
  const emailRef = useRef<HTMLInputElement>(null);
  const pwRef = useRef<HTMLInputElement>(null);
  const arrived = useRef(false);

  // On arrival: focus the first empty field (DECISIONS §10).
  useEffect(() => {
    if (arrived.current || !manageFocus) return;
    arrived.current = true;
    const order = [
      [wsRef, values.workspace],
      [emailRef, values.email],
      [pwRef, values.password],
    ] as const;
    const target = order.find(([, v]) => v === '')?.[0] ?? wsRef;
    target.current?.focus();
  }, [values, manageFocus]);

  // After a refused attempt: password cleared, workspace and email kept, focus to the first field (§10).
  const refused = notice === 'failure' || notice === 'rate-limited' || notice === 'paused' || notice === 'unreachable' || notice === 'server-error';
  useEffect(() => {
    if (!refused) return;
    // Clearing the password is a reaction to the caller's new outcome prop, not derived render state.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setValues((v) => ({ ...v, password: '' }));
    if (manageFocus) wsRef.current?.focus();
  }, [refused, notice, attempt, manageFocus]);

  const submit = (e: FormEvent) => {
    e.preventDefault();
    if (submitting) return;
    onSubmit(values);
  };

  const set = (k: keyof SignInValues) => (e: ChangeEvent<HTMLInputElement>) => setValues((v) => ({ ...v, [k]: e.target.value }));

  return (
    <AuthCard layout={layout}>
        <h1 className={styles.title}>{SIGN_IN.title}</h1>

        {notice === 'failure' ? (
          <Banner tone="danger" live="status" heading={SIGN_IN.failureHeading}>
            <p>{SIGN_IN.failureBody}</p>
            <p className={styles.secondary}>{SIGN_IN.failureNever}</p>
          </Banner>
        ) : null}
        {notice === 'rate-limited' ? (
          <Banner tone="warning" live="status" heading={SIGN_IN.rateLimitedHeading}>
            <p>{SIGN_IN.rateLimitedBody}</p>
          </Banner>
        ) : null}
        {notice === 'paused' ? (
          <Banner tone="warning" live="alert" heading={SIGN_IN.pausedHeading}>
            <p>{SIGN_IN.pausedBody}</p>
          </Banner>
        ) : null}
        {notice === 'session-ended' ? (
          <Banner tone="info" heading={SIGN_IN.sessionEndedHeading}>
            <p>{SIGN_IN.sessionEndedBody}</p>
            <p>{SIGN_IN.sessionEndedSaving}</p>
          </Banner>
        ) : null}
        {/* ux-copy-b3b.md C2-2: never the failure sentence, which would tell a person their details are wrong when the
            server is down (bff-auth-note.md §4's last row). */}
        {notice === 'unreachable' ? (
          <Banner tone="danger" icon="offline" live="status" heading={COULD_NOT_REACH}>
            <p>{CREDENTIAL_FALLBACK.unreachableBody}</p>
          </Banner>
        ) : null}
        {notice === 'server-error' ? (
          <Banner tone="danger" live="status" heading={WENT_WRONG}>
            <p>{CREDENTIAL_FALLBACK.serverErrorBody}</p>
          </Banner>
        ) : null}

        <form className={styles.form} onSubmit={submit} noValidate>
          <div className={styles.field}>
            <label htmlFor={ids.ws}>{SIGN_IN.workspaceLabel}</label>
            <input
              ref={wsRef}
              id={ids.ws}
              name="workspace"
              autoComplete="organization"
              autoCapitalize="none"
              spellCheck={false}
              value={values.workspace}
              onChange={set('workspace')}
              aria-describedby={workspace ? ids.wsHint : undefined}
            />
            {workspace ? (
              <span className={styles.hint} id={ids.wsHint}>
                {workspace.source === 'remembered' ? SIGN_IN.rememberedHint : SIGN_IN.invitationHint}
                {workspace.source === 'remembered' && onForgetWorkspace ? (
                  <>
                    {' '}
                    <button type="button" className={styles.inlineLink} onClick={onForgetWorkspace}>
                      {SIGN_IN.useAnotherWorkspace}
                    </button>
                  </>
                ) : null}
              </span>
            ) : null}
          </div>
          <div className={styles.field}>
            <label htmlFor={ids.email}>{SIGN_IN.emailLabel}</label>
            <input ref={emailRef} id={ids.email} name="email" type="email" autoComplete="username" autoCapitalize="none" spellCheck={false} value={values.email} onChange={set('email')} />
          </div>
          <div className={styles.field}>
            <label htmlFor={ids.pw}>{SIGN_IN.passwordLabel}</label>
            <input ref={pwRef} id={ids.pw} name="password" type="password" autoComplete="current-password" value={values.password} onChange={set('password')} />
            {/* §1.2: carries nothing in the URL — not the workspace, not the email. */}
            <Link href={FORGOT_LINK.href} className={styles.link}>
              {FORGOT_LINK.label}
            </Link>
          </div>
          <Button type="submit" variant="primary" size="lg" loading={submitting} className={styles.submit}>
            {SIGN_IN.submit}
          </Button>
        </form>
    </AuthCard>
  );
}

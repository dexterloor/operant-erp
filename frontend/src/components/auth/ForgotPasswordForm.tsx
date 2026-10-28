'use client';

import { WENT_WRONG } from '@/copy/headings';
import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { Button } from '@/components/ui/Button';
import { Banner } from '@/components/ui/Banner';
import { PlainLink, type LinkComponent } from '@/components/shell/types';
import { CREDENTIAL_FALLBACK, RATE_LIMITED, REQUEST_RESET } from '@/copy/password';
import type { FieldErrors, RequestResetAction, RequestResetResult } from '@/lib/auth/results';
import { AuthCard, AuthField, authStyles as styles } from './AuthCard';

type Notice = Exclude<RequestResetResult['kind'], 'accepted' | 'invalid'> | null;

export interface ForgotPasswordFormProps {
  /** Injected; B-3b wires it to the BFF. */
  requestReset: RequestResetAction;
  /** Pre-fill from the device's remembered workspace only (IA-O-2) — never from the URL. */
  rememberedWorkspace?: string;
  signInHref?: string;
  linkComponent?: LinkComponent;
  layout?: 'page' | 'embedded';
  manageFocus?: boolean;
}

/**
 * `/forgot-password` (ux-copy-frontend.md §1.3). One result state for EVERY 202 — it never says mail was sent, never
 * echoes the address, never varies by input or by how often this browser asked (ST-7.1).
 */
export function ForgotPasswordForm({ requestReset, rememberedWorkspace = '', signInHref = '/login', linkComponent: Link = PlainLink, layout = 'page', manageFocus = true }: ForgotPasswordFormProps) {
  const base = useId();
  const ids = { ws: `${base}-ws`, email: `${base}-email` };
  const [values, setValues] = useState({ workspace: rememberedWorkspace, email: '' });
  const [errors, setErrors] = useState<FieldErrors<'workspace' | 'email'>>({});
  const [notice, setNotice] = useState<Notice>(null);
  const [submitting, setSubmitting] = useState(false);
  const [accepted, setAccepted] = useState(false);
  const wsRef = useRef<HTMLInputElement>(null);
  const emailRef = useRef<HTMLInputElement>(null);
  const resultRef = useRef<HTMLHeadingElement>(null);
  const noticeRef = useRef<HTMLParagraphElement>(null);
  // Bumped after each response so the focus effect runs even when the outcome repeats.
  const [settled, setSettled] = useState(0);

  // Arrival: first empty field (as sign-in, §10).
  const arrived = useRef(false);
  useEffect(() => {
    if (arrived.current || !manageFocus) return;
    arrived.current = true;
    (values.workspace === '' ? wsRef : emailRef).current?.focus();
  }, [values.workspace, manageFocus]);

  // After a response: the result heading (a commit that navigates), the first invalid field (ST-3), or the banner.
  useEffect(() => {
    if (settled === 0 || !manageFocus) return;
    if (accepted) resultRef.current?.focus();
    else if (errors.workspace) wsRef.current?.focus();
    else if (errors.email) emailRef.current?.focus();
    else if (notice) noticeRef.current?.focus();
  }, [settled, accepted, errors, notice, manageFocus]);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (submitting) return;
    setSubmitting(true);
    let result: RequestResetResult;
    try {
      result = await requestReset({ workspace: values.workspace, email: values.email });
    } catch {
      result = { kind: 'unreachable' };
    }
    setSubmitting(false);
    setErrors(result.kind === 'invalid' ? result.fieldErrors : {});
    setNotice(result.kind === 'accepted' || result.kind === 'invalid' ? null : result.kind);
    setAccepted(result.kind === 'accepted');
    setSettled((n) => n + 1);
  };

  const tryDifferent = () => {
    // Fields kept (§1.3 "Try different details").
    setAccepted(false);
    setNotice(null);
    setErrors({});
    requestAnimationFrame(() => wsRef.current?.focus());
  };

  if (accepted) {
    return (
      <AuthCard layout={layout}>
        <div className={styles.result}>
          <h1 ref={resultRef} tabIndex={-1} className={styles.title}>
            {REQUEST_RESET.resultHeading}
          </h1>
          <p>{REQUEST_RESET.resultBody}</p>
          <p>{REQUEST_RESET.resultBody2}</p>
          <div className={styles.actions}>
            <Link href={signInHref} className={styles.link}>
              {REQUEST_RESET.backToSignIn}
            </Link>
            <Button variant="secondary" onClick={tryDifferent}>
              {REQUEST_RESET.tryDifferent}
            </Button>
          </div>
        </div>
      </AuthCard>
    );
  }

  return (
    <AuthCard layout={layout}>
      <h1 className={styles.title}>{REQUEST_RESET.heading}</h1>
      <p className={styles.intro}>{REQUEST_RESET.intro}</p>

      {notice === 'rate-limited' ? (
        <Banner ref={noticeRef} tone="warning" live="status" heading={RATE_LIMITED.heading}>
          <p>{RATE_LIMITED.body}</p>
          <p className={styles.secondary}>{REQUEST_RESET.rateLimitedShared}</p>
        </Banner>
      ) : null}
      {notice === 'unreachable' ? (
        <Banner ref={noticeRef} tone="danger" icon="offline" live="status" heading={REQUEST_RESET.unreachableHeading}>
          <p>{REQUEST_RESET.unreachableBody}</p>
        </Banner>
      ) : null}
      {notice === 'server-error' ? (
        <Banner ref={noticeRef} tone="danger" live="status" heading={WENT_WRONG}>
          <p>{CREDENTIAL_FALLBACK.serverErrorBody}</p>
        </Banner>
      ) : null}

      <form className={styles.form} onSubmit={submit} noValidate>
        <AuthField
          ref={wsRef}
          id={ids.ws}
          label={REQUEST_RESET.workspaceLabel}
          name="workspace"
          autoComplete="organization"
          autoCapitalize="none"
          spellCheck={false}
          value={values.workspace}
          onChange={(e) => setValues((v) => ({ ...v, workspace: e.target.value }))}
          error={errors.workspace}
        />
        <AuthField
          ref={emailRef}
          id={ids.email}
          label={REQUEST_RESET.emailLabel}
          name="email"
          type="email"
          autoComplete="email"
          autoCapitalize="none"
          spellCheck={false}
          value={values.email}
          onChange={(e) => setValues((v) => ({ ...v, email: e.target.value }))}
          error={errors.email}
        />
        <Button type="submit" variant="primary" size="lg" loading={submitting} className={styles.submit}>
          {REQUEST_RESET.submit}
        </Button>
      </form>
      <Link href={signInHref} className={`${styles.link} ${styles.centered}`}>
        {REQUEST_RESET.backToSignIn}
      </Link>
    </AuthCard>
  );
}

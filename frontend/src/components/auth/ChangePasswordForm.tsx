'use client';

import { COULD_NOT_REACH, WENT_WRONG } from '@/copy/headings';
import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { Button } from '@/components/ui/Button';
import { Banner } from '@/components/ui/Banner';
import { CHANGE_PASSWORD, CREDENTIAL_FALLBACK, RATE_LIMITED } from '@/copy/password';
import { assignLocation, type ChangePasswordAction, type ChangePasswordResult, type ChangePasswordValues, type DocumentNavigate, type FieldErrors } from '@/lib/auth/results';
import { AuthCard, AuthField, authStyles as styles, useElapsedSince } from './AuthCard';

type Notice = 'rate-limited' | 'not-sent' | 'unreachable' | 'server-error' | null;

export interface ChangePasswordFormProps {
  /** Injected; wired to `POST /bff/me/password` with the frame's sid (`wiredChangePassword`). */
  changePassword: ChangePasswordAction;
  /** A 401 means the session has ended: the caller shows sign-in with the "session ended" notice (BFF note §6.1). */
  onSessionEnded: () => void;
  /**
   * A 409: this browser is now signed in as someone else than the page was rendered for. The caller reloads once, with no
   * message (c10-design.md FINAL B.3, departure D-5); after the reload the identities match, so it cannot loop.
   */
  onSessionChanged?: () => void;
  signInHref?: string;
  navigate?: DocumentNavigate;
  layout?: 'in-app' | 'embedded';
  manageFocus?: boolean;
}

/**
 * `/account/password` (ux-copy-frontend.md §1.5, the D2 = yes variant — owner ruling 2026-10-27, package P-A: changing a
 * password signs out everywhere, this session included). Success offers sign-in no sooner than 1.1 s after the response
 * arrived and leaves by a full document load; the BFF deletes the session cookie in the same response (B-3b).
 */
export function ChangePasswordForm({ changePassword, onSessionEnded, onSessionChanged = () => {}, signInHref = '/login', navigate = assignLocation, layout = 'in-app', manageFocus = true }: ChangePasswordFormProps) {
  const base = useId();
  const ids = { current: `${base}-current`, next: `${base}-new` };
  const [values, setValues] = useState<ChangePasswordValues>({ current_password: '', new_password: '' });
  const [errors, setErrors] = useState<FieldErrors<'current_password' | 'new_password'>>({});
  const [notice, setNotice] = useState<Notice>(null);
  const [submitting, setSubmitting] = useState(false);
  const [receivedAt, setReceivedAt] = useState<number | null>(null);
  const [settled, setSettled] = useState(0);
  const canSignIn = useElapsedSince(receivedAt);

  const currentRef = useRef<HTMLInputElement>(null);
  const nextRef = useRef<HTMLInputElement>(null);
  const headingRef = useRef<HTMLHeadingElement>(null);
  const noticeRef = useRef<HTMLParagraphElement>(null);
  const done = receivedAt !== null;

  useEffect(() => {
    if (settled === 0 || !manageFocus) return;
    if (done) headingRef.current?.focus();
    else if (errors.current_password) currentRef.current?.focus();
    else if (errors.new_password) nextRef.current?.focus();
    else if (notice) noticeRef.current?.focus();
  }, [settled, done, errors, notice, manageFocus]);

  const apply = (result: ChangePasswordResult, arrivedAt: number) => {
    setErrors(result.kind === 'invalid' ? result.fieldErrors : {});
    setNotice(result.kind === 'rate-limited' || result.kind === 'not-sent' || result.kind === 'unreachable' || result.kind === 'server-error' ? result.kind : null);
    if (result.kind === 'changed') {
      setValues({ current_password: '', new_password: '' });
      setReceivedAt(result.receivedAt ?? arrivedAt);
    } else if (result.kind === 'session-ended') {
      onSessionEnded();
    } else if (result.kind === 'session-changed') {
      onSessionChanged();
    }
  };

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (submitting) return;
    setSubmitting(true);
    let result: ChangePasswordResult;
    try {
      result = await changePassword(values);
    } catch {
      result = { kind: 'unreachable' };
    }
    const arrivedAt = performance.now();
    setSubmitting(false);
    apply(result, arrivedAt);
    setSettled((n) => n + 1);
  };

  if (done) {
    return (
      <AuthCard layout={layout}>
        <div className={styles.result}>
          <h1 ref={headingRef} tabIndex={-1} className={styles.title}>
            {CHANGE_PASSWORD.doneHeading}
          </h1>
          <p>{CHANGE_PASSWORD.doneBody}</p>
          {canSignIn ? (
            <div className={styles.actions}>
              <Button variant="primary" size="lg" onClick={() => navigate(signInHref)}>
                {CHANGE_PASSWORD.signIn}
              </Button>
            </div>
          ) : null}
          <p role="status" aria-live="polite" className={styles.hint}>
            {canSignIn ? CHANGE_PASSWORD.signInNow : ''}
          </p>
        </div>
      </AuthCard>
    );
  }

  const set = (k: keyof ChangePasswordValues) => (e: { target: { value: string } }) => setValues((v) => ({ ...v, [k]: e.target.value }));

  return (
    <AuthCard layout={layout}>
      <h1 className={styles.title}>{CHANGE_PASSWORD.heading}</h1>

      {notice === 'rate-limited' ? (
        <Banner ref={noticeRef} tone="warning" live="status" heading={RATE_LIMITED.heading}>
          <p>{RATE_LIMITED.body}</p>
          <p className={styles.secondary}>{CHANGE_PASSWORD.rateLimitedNothingChanged}</p>
        </Banner>
      ) : null}
      {notice === 'not-sent' ? (
        // not-sent → C2-2 body: pairing CONFIRMED by UX (confirm-pass-2026-10-30.md Rev 1 §b; c10 FINAL A.2)
        <Banner ref={noticeRef} tone="danger" live="status" heading={WENT_WRONG}>
          <p>{CREDENTIAL_FALLBACK.serverErrorBody}</p>
        </Banner>
      ) : null}
      {notice === 'unreachable' ? (
        <Banner ref={noticeRef} tone="danger" icon="offline" live="status" heading={COULD_NOT_REACH}>
          <p>{CHANGE_PASSWORD.interruptedBody}</p>
        </Banner>
      ) : null}
      {notice === 'server-error' ? (
        <Banner ref={noticeRef} tone="danger" live="status" heading={WENT_WRONG}>
          <p>{CHANGE_PASSWORD.interruptedBody}</p>
        </Banner>
      ) : null}

      <form className={styles.form} method="post" onSubmit={submit} noValidate>
        <AuthField
          ref={currentRef}
          id={ids.current}
          label={CHANGE_PASSWORD.currentLabel}
          name="current-password"
          type="password"
          autoComplete="current-password"
          value={values.current_password}
          onChange={set('current_password')}
          error={errors.current_password}
        />
        <AuthField
          ref={nextRef}
          id={ids.next}
          label={CHANGE_PASSWORD.newLabel}
          name="new-password"
          type="password"
          autoComplete="new-password"
          value={values.new_password}
          onChange={set('new_password')}
          error={errors.new_password}
        />
        <Button type="submit" variant="primary" loading={submitting}>
          {CHANGE_PASSWORD.submit}
        </Button>
      </form>
    </AuthCard>
  );
}

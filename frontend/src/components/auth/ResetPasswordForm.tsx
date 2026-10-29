'use client';

import { COULD_NOT_REACH, WENT_WRONG } from '@/copy/headings';
import { useEffect, useId, useLayoutEffect, useRef, useState, type FormEvent } from 'react';
import { Button } from '@/components/ui/Button';
import { Banner } from '@/components/ui/Banner';
import { PlainLink, type LinkComponent } from '@/components/shell/types';
import { CREDENTIAL_FALLBACK, RATE_LIMITED, SET_PASSWORD } from '@/copy/password';
import { assignLocation, type DocumentNavigate, type RedeemResetAction, type RedeemResetResult } from '@/lib/auth/results';
import { AuthCard, AuthField, authStyles as styles, useElapsedSince } from './AuthCard';

type Phase = 'reading' | 'ready' | 'refused' | 'done';
type Notice = 'rate-limited' | 'unreachable' | 'server-error' | null;

/**
 * Reads `token` from the URL fragment and removes the fragment from the address bar and the current history entry at
 * once. Keeps the App Router's own `history.state` and the query (BFF note R1-§8). Returns null when there is none.
 * The fragment is never sent to a server, so only this client code ever sees the token.
 */
export function takeTokenFromFragment(): string | null {
  const { hash, pathname, search } = window.location;
  if (hash === '' || hash === '#') return null;
  const token = new URLSearchParams(hash.slice(1)).get('token');
  window.history.replaceState(window.history.state, '', pathname + search);
  return token !== null && token !== '' ? token : null;
}

/**
 * Tells the App Router the fragment is gone. Next seeds its own `canonicalUrl` from `location` with the hash
 * (`create-initial-router-state`), and its patched `replaceState` ignores a call whose state carries its `__NA` marker —
 * which `history.state` always does — so the strip above leaves `#token=` in the router's state, and the router's next
 * history write would put it back in the address bar. Calling the patched `replaceState` once with a state WITHOUT the
 * marker makes Next copy its own internals across and restore its URL to path + query. Does nothing when
 * `replaceState` is not patched (no App Router — tests), and the URL it writes has no fragment.
 */
export function syncRouterUrl(): void {
  if (window.history.replaceState === History.prototype.replaceState) return;
  const { pathname, search } = window.location;
  window.history.replaceState({}, '', pathname + search);
}

export interface ResetPasswordFormProps {
  /** Injected; B-3b wires it to `POST /bff/password-resets/redemption`. */
  redeem: RedeemResetAction;
  forgotHref?: string;
  signInHref?: string;
  /** After success, sign-in is reached by a full document load (BFF note R2-4). Injectable for tests. */
  navigate?: DocumentNavigate;
  linkComponent?: LinkComponent;
  manageFocus?: boolean;
}

/**
 * `/reset-password` (ux-copy-frontend.md §1.4). Build rules: the token lives only in this component's memory (a ref —
 * never state that renders, storage, a cookie, the URL, a log line or an error report); a 422 keeps the link usable; a
 * 401 is the one link-refusal state (never the shell's "session ended"); success offers sign-in no sooner than 1.1 s
 * after the response arrived (performance.now()) and leaves by a full document load.
 */
export function ResetPasswordForm({ redeem, forgotHref = '/forgot-password', signInHref = '/login', navigate = assignLocation, linkComponent: Link = PlainLink, manageFocus = true }: ResetPasswordFormProps) {
  const fieldId = `${useId()}-password`;
  const tokenRef = useRef<string | null>(null);
  const [phase, setPhase] = useState<Phase>('reading');
  const [password, setPassword] = useState('');
  const [shown, setShown] = useState(false);
  const [error, setError] = useState<string | undefined>(undefined);
  const [notice, setNotice] = useState<Notice>(null);
  const [submitting, setSubmitting] = useState(false);
  const [receivedAt, setReceivedAt] = useState<number | null>(null);
  const [settled, setSettled] = useState(0);
  const canSignIn = useElapsedSince(receivedAt);

  const fieldRef = useRef<HTMLInputElement>(null);
  const headingRef = useRef<HTMLHeadingElement>(null);
  const noticeRef = useRef<HTMLParagraphElement>(null);

  // Before paint: take the token and strip the fragment. A layout effect runs before the browser paints and before
  // any child effect could read location; the ref guard keeps StrictMode's second run from discarding the token.
  useLayoutEffect(() => {
    if (phase !== 'reading') return;
    if (tokenRef.current === null) tokenRef.current = takeTokenFromFragment();
    // A one-time transition driven by an external source (the URL), not derived render state.
    setPhase(tokenRef.current === null ? 'refused' : 'ready');
  }, [phase]);

  // The App Router patches `history` in a passive effect of an ancestor, which runs after this component's effects in
  // the same commit; a zero timer runs after all of them. Then bring the router's own URL in line with the strip.
  useEffect(() => {
    const timer = setTimeout(syncRouterUrl, 0);
    return () => clearTimeout(timer);
  }, []);

  // Focus: the field when ready; the heading of a result that replaces the form; the field after a 422; the banner.
  useEffect(() => {
    if (!manageFocus || phase === 'reading') return;
    if (phase === 'refused' || phase === 'done') headingRef.current?.focus();
    else if (notice) noticeRef.current?.focus();
    else fieldRef.current?.focus();
  }, [phase, notice, settled, manageFocus]);

  const apply = (result: RedeemResetResult, arrivedAt: number) => {
    switch (result.kind) {
      case 'changed':
        tokenRef.current = null;
        setPassword('');
        setReceivedAt(result.receivedAt ?? arrivedAt);
        setPhase('done');
        return;
      case 'link-refused':
        tokenRef.current = null;
        setPassword('');
        setPhase('refused');
        return;
      case 'invalid':
        // The link is still good: keep the token, keep the form, show the server's sentence on the field.
        setError(result.fieldErrors.password);
        setNotice(null);
        return;
      default:
        setError(undefined);
        setNotice(result.kind);
    }
  };

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const token = tokenRef.current;
    if (submitting || token === null) return;
    setSubmitting(true);
    let result: RedeemResetResult;
    try {
      result = await redeem({ token, password });
    } catch {
      result = { kind: 'unreachable' };
    }
    const arrivedAt = performance.now();
    setSubmitting(false);
    apply(result, arrivedAt);
    setSettled((n) => n + 1);
  };

  if (phase === 'reading') {
    // Nothing to show until the fragment is read (one layout pass; the browser never paints this).
    return <AuthCard>{null}</AuthCard>;
  }

  if (phase === 'refused') {
    return (
      <AuthCard>
        <div className={styles.result}>
          <h1 ref={headingRef} tabIndex={-1} className={styles.title}>
            {SET_PASSWORD.refusedHeading}
          </h1>
          <p>{SET_PASSWORD.refusedBody}</p>
          <p>{SET_PASSWORD.refusedBody2}</p>
          <div className={styles.actions}>
            <Link href={forgotHref} className={styles.link}>
              {SET_PASSWORD.askForNewLink}
            </Link>
          </div>
        </div>
      </AuthCard>
    );
  }

  if (phase === 'done') {
    return (
      <AuthCard>
        <div className={styles.result}>
          <h1 ref={headingRef} tabIndex={-1} className={styles.title}>
            {SET_PASSWORD.doneHeading}
          </h1>
          <p>{SET_PASSWORD.doneBody}</p>
          {canSignIn ? (
            <div className={styles.actions}>
              <Button variant="primary" size="lg" onClick={() => navigate(signInHref)}>
                {SET_PASSWORD.signIn}
              </Button>
            </div>
          ) : null}
          {/* Present from the start so the polite announcement is reliably read when it fills (§1.4). */}
          <p role="status" aria-live="polite" className={styles.hint}>
            {canSignIn ? SET_PASSWORD.signInNow : ''}
          </p>
        </div>
      </AuthCard>
    );
  }

  return (
    <AuthCard>
      <h1 className={styles.title}>{SET_PASSWORD.heading}</h1>

      {notice === 'rate-limited' ? (
        <Banner ref={noticeRef} tone="warning" live="status" heading={RATE_LIMITED.heading}>
          <p>{RATE_LIMITED.body}</p>
          <p className={styles.secondary}>{SET_PASSWORD.rateLimitedNothingChanged}</p>
        </Banner>
      ) : null}
      {notice === 'unreachable' ? (
        <Banner ref={noticeRef} tone="danger" icon="offline" live="status" heading={COULD_NOT_REACH}>
          <p>{CREDENTIAL_FALLBACK.unreachableBody}</p>
        </Banner>
      ) : null}
      {notice === 'server-error' ? (
        <Banner ref={noticeRef} tone="danger" live="status" heading={WENT_WRONG}>
          <p>{CREDENTIAL_FALLBACK.serverErrorBody}</p>
        </Banner>
      ) : null}

      <form className={styles.form} method="post" onSubmit={submit} noValidate>
        <AuthField
          ref={fieldRef}
          id={fieldId}
          label={SET_PASSWORD.passwordLabel}
          hint={SET_PASSWORD.passwordHint}
          error={error}
          name="new-password"
          type={shown ? 'text' : 'password'}
          autoComplete="new-password"
          autoCapitalize="none"
          spellCheck={false}
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          labelAction={
            <button type="button" className={styles.toggle} aria-pressed={shown} aria-controls={fieldId} onClick={() => setShown((s) => !s)}>
              {shown ? SET_PASSWORD.hide : SET_PASSWORD.show}
            </button>
          }
        />
        <Button type="submit" variant="primary" size="lg" loading={submitting} className={styles.submit}>
          {SET_PASSWORD.submit}
        </Button>
      </form>
    </AuthCard>
  );
}

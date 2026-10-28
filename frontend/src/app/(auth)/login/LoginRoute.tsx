'use client';

import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { useState } from 'react';
import { SignInCard, type SignInNotice, type SignInValues } from '@/components/auth/SignInCard';
import { assignLocation, type DocumentNavigate } from '@/lib/auth/results';
import { signIn } from '@/lib/bff/authCalls';

/**
 * `/login` wired to `POST /bff/session` (b3b-design.md §5 items 5, 7, 15; bff-auth-note.md §4, R1-§6.1).
 * - `return_to` is read from `useSearchParams()` and sent as a body field; the page navigates ONLY to the server's
 *   `redirect`, with a full document load (R2-4), never to `?return_to` itself.
 * - `reason=session_ended` shows the fixed three-line notice; any other value shows nothing (the parameter is settable
 *   by anyone, so the notice carries none of its content).
 * - The form is always shown, even when a session cookie exists (R1-§6.1: `/login` never redirects away).
 */
export function LoginRoute({ navigate = assignLocation }: { navigate?: DocumentNavigate }) {
  const params = useSearchParams();
  const [notice, setNotice] = useState<SignInNotice | null>(params.get('reason') === 'session_ended' ? 'session-ended' : null);
  const [attempt, setAttempt] = useState(0);
  const [submitting, setSubmitting] = useState(false);

  const submit = async (values: SignInValues) => {
    setSubmitting(true);
    const result = await signIn({ ...values, returnTo: params.get('return_to'), timeZone: Intl.DateTimeFormat().resolvedOptions().timeZone });
    if (result.kind === 'signed-in') {
      navigate(result.redirect);
      return;
    }
    setSubmitting(false);
    setAttempt((a) => a + 1);
    setNotice(result.kind);
  };

  return <SignInCard notice={notice} attempt={attempt} submitting={submitting} onSubmit={(v) => void submit(v)} linkComponent={Link} />;
}

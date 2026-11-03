'use client';

import { useEffect, useRef } from 'react';
import { assignLocation, type DocumentNavigate } from '@/lib/auth/results';
import { sessionEndedHref } from '@/lib/session';

export interface SessionEndedReturnProps {
  /** Injected in tests; a full document load otherwise. */
  navigate?: DocumentNavigate;
  /** Injected in tests; the browser's own path and query otherwise (the fragment is not sent to a server anyway). */
  here?: () => string;
}

/**
 * What the (app) layout renders instead of the frame when the session has ended (b3b-design.md §6.3, §10): no frame, no
 * page, nothing on screen — one full load of the session-ended notice, carrying the page the person was on, exactly as
 * the client hook does after an API 401 (`apiCall.ts`). The layout cannot know that page on the server (Next layouts get
 * no pathname). The address is only a request: the sign-in handler alone decides where the browser goes after sign-in,
 * through `safeReturnPath` (SD-1), so a hostile address lands on `/`.
 */
export function SessionEndedReturn({ navigate = assignLocation, here = () => window.location.pathname + window.location.search }: SessionEndedReturnProps) {
  const sent = useRef(false);
  useEffect(() => {
    if (sent.current) return;
    sent.current = true;
    navigate(sessionEndedHref(here()));
  }, [navigate, here]);
  return null;
}

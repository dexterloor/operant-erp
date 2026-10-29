'use client';

import { createContext, useContext, useEffect, useRef, useState, type ReactNode } from 'react';
import { SESSION_CHANGED, pageTitle } from '@/copy/shell';
import { createApiCaller, type ApiCaller, type MarkerStore } from '@/lib/bff/apiCall';
import styles from '@/components/states/PageStates.module.css';

/**
 * One API caller per document (b3b-design.md §6.1, §6.3; M-11): it is given only the session's client view `{sid, tz}`
 * by the server layout. It renders the fixed page on a second session change (R2-4; ux-copy-b3b.md C3-1) in place of the
 * page content: focus to the h1 after commit, the title updated, no live role, and a full-document link to /login.
 */

const CallerContext = createContext<ApiCaller | null>(null);
const TimeZoneContext = createContext<string | null>(null);
const SessionIdContext = createContext<string | null>(null);

export function useApiCaller(): ApiCaller {
  const c = useContext(CallerContext);
  if (c === null) throw new Error('useApiCaller() is used outside BffProvider.');
  return c;
}

/**
 * The viewer's IANA zone, from the session (`tz`, captured at sign-in; b4-architecture.md Revision 5 A-9): the one
 * source of every `timeZone` prop below the frame. Never read from the browser in render.
 */
export function useViewerTimeZone(): string {
  const tz = useContext(TimeZoneContext);
  if (tz === null) throw new Error('useViewerTimeZone() is used outside BffProvider.');
  return tz;
}

/**
 * The frame's session id (`sid`, from the client view; b4-architecture.md Revision 6 A-14). Its only consumer is the
 * account-page route under src/app/(app)/account/ (guard G-8): every other authenticated browser call goes through
 * useApiCaller(), which handles a 401 and a 409 for it.
 */
export function useSessionId(): string {
  const sid = useContext(SessionIdContext);
  if (sid === null) throw new Error('useSessionId() is used outside BffProvider.');
  return sid;
}

/** sessionStorage, tolerating a browser that refuses it (the marker then lasts only for this document). */
function browserStore(): MarkerStore {
  const memory = new Map<string, string>();
  const safe = <T,>(f: () => T, fallback: T): T => {
    try {
      return f();
    } catch {
      return fallback;
    }
  };
  return {
    get: (k) => safe(() => window.sessionStorage.getItem(k), memory.get(k) ?? null),
    set: (k, v) => {
      memory.set(k, v);
      safe(() => window.sessionStorage.setItem(k, v), undefined);
    },
    delete: (k) => {
      memory.delete(k);
      safe(() => window.sessionStorage.removeItem(k), undefined);
    },
  };
}

export interface BffProviderProps {
  /** clientSessionView(session), and nothing else from the session. */
  session: { readonly sid: string; readonly tz: string };
  children: ReactNode;
  /** Injected in tests; the browser's reload otherwise. */
  reload?: () => void;
}

export function BffProvider({ session, children, reload }: BffProviderProps) {
  const [fixed, setFixed] = useState(false);
  const [caller] = useState<ApiCaller>(() =>
    createApiCaller({
      sid: session.sid,
      navigate: (url) => window.location.assign(url),
      reload: reload ?? (() => window.location.reload()),
      onFixedError: () => setFixed(true),
      here: () => window.location.pathname + window.location.search,
      storage: browserStore(),
      development: process.env.NODE_ENV !== 'production',
    }),
  );
  return (
    <CallerContext.Provider value={caller}>
      <TimeZoneContext.Provider value={session.tz}>
        <SessionIdContext.Provider value={session.sid}>{fixed ? <SessionChangedPage /> : children}</SessionIdContext.Provider>
      </TimeZoneContext.Provider>
    </CallerContext.Provider>
  );
}

function SessionChangedPage() {
  const h1 = useRef<HTMLHeadingElement>(null);
  useEffect(() => {
    document.title = pageTitle(SESSION_CHANGED.title);
    h1.current?.focus();
  }, []);
  return (
    <section className={styles.full}>
      <h1 ref={h1} tabIndex={-1}>
        {SESSION_CHANGED.heading}
      </h1>
      <p className={styles.body}>{SESSION_CHANGED.body}</p>
      <p className={styles.body}>{SESSION_CHANGED.bodySecond}</p>
      {/* A plain anchor: a full document load (R1-§4), never a client navigation. */}
      <a href="/login" className={styles.back}>
        {SESSION_CHANGED.signIn}
      </a>
    </section>
  );
}

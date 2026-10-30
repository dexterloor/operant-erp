import type { ReactNode } from 'react';
import { clientSessionView } from '@/lib/bff/server/session';
import { AppShell } from './AppShell';
import { BffProvider } from './BffProvider';
import { currentViewer, sessionEndedPath, sessionHasEnded } from './currentViewer';
import { SessionEndedReturn } from './SessionEndedReturn';

// The authenticated frame (b3b-design.md §3 rule R-TK, M-11): cookie → session → GetCurrentUser → frame. Client
// components receive only clientSessionView(session) = {sid, tz}, the served viewer, and the typed workspace name the top
// bar shows (D14; the one named exception, deviation D-9). Rendered per request.
//
// An ended session renders NO frame and no BffProvider: the session never reaches a client component. The layout cannot
// know the page it wraps (Next layouts get no pathname), so it lets the page answer: every (app) page awaits
// `currentViewer(sessionEndedPath(<its own path and query>))` before anything else, which redirects (HTTP 307) to the
// session-ended notice carrying that page, so sign-in returns there. A page navigated to in the browser is answered the
// same way, because the layout is not re-rendered then. `SessionEndedReturn` is the backstop for a page that rendered
// anyway: it sends the browser to the notice with its own address (fe-session-return-progress.md D-1).
export const dynamic = 'force-dynamic';

export default async function AppLayout({ children }: { children: ReactNode }) {
  if (await sessionHasEnded()) {
    return (
      <>
        {children}
        <SessionEndedReturn />
      </>
    );
  }
  const { session, viewer } = await currentViewer(sessionEndedPath('/'));
  return (
    <AppShell viewer={viewer} workspaceSignInName={session.workspaceTyped}>
      <BffProvider session={clientSessionView(session)}>{children}</BffProvider>
    </AppShell>
  );
}

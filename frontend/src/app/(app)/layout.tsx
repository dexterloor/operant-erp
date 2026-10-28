import type { ReactNode } from 'react';
import { clientSessionView } from '@/lib/bff/server/session';
import { AppShell } from './AppShell';
import { BffProvider } from './BffProvider';
import { currentViewer } from './currentViewer';

// The authenticated frame (b3b-design.md §3 rule R-TK, M-11): cookie → session → GetCurrentUser → frame. Client
// components receive only clientSessionView(session) = {sid, tz}, the served viewer, and the typed workspace name the top
// bar shows (D14; the one named exception, deviation D-9). Rendered per request.
export const dynamic = 'force-dynamic';

export default async function AppLayout({ children }: { children: ReactNode }) {
  const { session, viewer } = await currentViewer();
  return (
    <AppShell viewer={viewer} workspaceSignInName={session.workspaceTyped}>
      <BffProvider session={clientSessionView(session)}>{children}</BffProvider>
    </AppShell>
  );
}

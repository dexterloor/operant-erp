import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { StartPage } from '@/components/shell/StartPage';
import { START } from '@/copy/shell';
import { startDestination } from '@/lib/routes';
import { currentViewer, sessionEndedPath } from './currentViewer';

// The `/` start page (ruling Q-6; b3b-design.md §10a). Sign-in always returns here; this server component sends each
// person to the first built page, in navigation order, whose gate they hold, and otherwise renders the start page.
export const dynamic = 'force-dynamic';

export const metadata: Metadata = { title: START.title };

export default async function Page() {
  const { viewer } = await currentViewer(sessionEndedPath('/'));
  const destination = startDestination(viewer.permissionCodes);
  if (destination !== null) redirect(destination);
  return <StartPage permissionCodes={viewer.permissionCodes} />;
}

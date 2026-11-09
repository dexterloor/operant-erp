import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { ITEMS_LIST } from '@/copy/items';
import { serverRead } from '@/lib/bff/server/reads';
import { viewerMay } from '@/lib/bff/surface';
import { loadItems } from '@/lib/items/load';
import { currentViewer, sessionEndedPath } from '../currentViewer';
import { ItemsDenied } from './ItemStates';
import { ItemsList } from './ItemsList';

// `/items` (items-ux-copy.md B-1, B-2; Revision 8 R8-1, R8-2). The gate is the contract's; the first read is the first
// page of the view the address names (no search, no cursor: cursors and `q` are never in the address, R8-1 item 6), run
// by the pure loader over the server transport. 401 → the session-ended notice; 403 → ST-4b; any other failure renders
// the list's region error (first load) with Retry.
export const dynamic = 'force-dynamic';

export const metadata: Metadata = { title: ITEMS_LIST.heading(false) };

/** The address names the view: exactly `?archived=true` is the Archived view, anything else live. */
export default async function Page({ searchParams }: { searchParams: Promise<Record<string, string | string[] | undefined>> }) {
  const query = await searchParams;
  const { session, viewer } = await currentViewer(sessionEndedPath('/items', query));
  const archived = query.archived === 'true';
  if (!viewerMay('ListItems', viewer.permissionCodes)) return <ItemsDenied />;
  const first = await loadItems(serverRead(session), archived, { q: '', cursor: null });
  if (first.kind === 'session-ended') redirect(sessionEndedPath('/items', query));
  if (first.kind === 'forbidden') return <ItemsDenied />;
  return <ItemsList initial={first} initialArchived={archived} />;
}

import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { WAREHOUSES_LIST } from '@/copy/warehouses';
import { serverRead } from '@/lib/bff/server/reads';
import { viewerMay } from '@/lib/bff/surface';
import { loadWarehouses } from '@/lib/warehouses/load';
import { currentViewer, sessionEndedPath } from '../currentViewer';
import { WarehousesDenied } from './WarehouseStates';
import { WarehousesList } from './WarehousesList';

// `/warehouses` (warehouses-ux-copy.md B-1, B-2; Revision 7 A-15). The gate is the contract's; the first read runs the
// pure loader over the server transport. 401 → the session-ended notice; 403 → ST-4b; any other failure renders the
// list's region error (first load) with Retry.
export const dynamic = 'force-dynamic';

export const metadata: Metadata = { title: WAREHOUSES_LIST.heading(false) };

/** The address names the view: exactly `?archived=true` is the Archived view, anything else live. */
export default async function Page({ searchParams }: { searchParams: Promise<Record<string, string | string[] | undefined>> }) {
  const query = await searchParams;
  const { session, viewer } = await currentViewer(sessionEndedPath('/warehouses', query));
  const archived = query.archived === 'true';
  if (!viewerMay('ListWarehouses', viewer.permissionCodes)) return <WarehousesDenied />;
  const first = await loadWarehouses(serverRead(session), archived);
  if (first.kind === 'session-ended') redirect(sessionEndedPath('/warehouses', query));
  if (first.kind === 'forbidden') return <WarehousesDenied />;
  return <WarehousesList initial={first} initialArchived={archived} />;
}

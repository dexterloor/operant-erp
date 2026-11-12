import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { SUPPLIERS_LIST } from '@/copy/suppliers';
import { serverRead } from '@/lib/bff/server/reads';
import { viewerMay } from '@/lib/bff/surface';
import { loadSuppliers } from '@/lib/suppliers/load';
import { currentViewer, sessionEndedPath } from '../currentViewer';
import { SuppliersDenied } from './SupplierStates';
import { SuppliersList } from './SuppliersList';

// `/suppliers` (suppliers-ux-copy.md Revision 1 S-1; suppliers-plan.md Revision 0 §2.1; Revision 8 R8-1, R8-2). The gate
// is the contract's; the first read is the first page of the view the address names (no search, no cursor: cursors and
// `q` are never in the address, R8-1 item 6), run by the pure loader over the server transport. 401 → the session-ended
// notice; 403 → ST-4b; any other failure renders the list's region error (first load) with Retry. No New supplier until
// S-2 (the create page).
export const dynamic = 'force-dynamic';

export const metadata: Metadata = { title: SUPPLIERS_LIST.heading(false) };

/** The address names the view: exactly `?archived=true` is the Archived view, anything else live. */
export default async function Page({ searchParams }: { searchParams: Promise<Record<string, string | string[] | undefined>> }) {
  const query = await searchParams;
  const { session, viewer } = await currentViewer(sessionEndedPath('/suppliers', query));
  const archived = query.archived === 'true';
  if (!viewerMay('ListSuppliers', viewer.permissionCodes)) return <SuppliersDenied />;
  const first = await loadSuppliers(serverRead(session), archived, { q: '', cursor: null });
  if (first.kind === 'session-ended') redirect(sessionEndedPath('/suppliers', query));
  if (first.kind === 'forbidden') return <SuppliersDenied />;
  return <SuppliersList initial={first} initialArchived={archived} />;
}

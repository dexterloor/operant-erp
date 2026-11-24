import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { CUSTOMERS_LIST } from '@/copy/customers';
import { serverRead } from '@/lib/bff/server/reads';
import { viewerMay } from '@/lib/bff/surface';
import { loadCustomers } from '@/lib/customers/load';
import { currentViewer, sessionEndedPath } from '../currentViewer';
import { CustomersDenied } from './CustomerStates';
import { CustomersList } from './CustomersList';

// `/customers` (customers-plan.md Revision 1 — FINAL R1-5 C-1; STATES.md ST-9a; Revision 8 R8-1, R8-2; the /suppliers
// shape). The gate is the contract's; the first read is the first page of the view the address names (no search, no cursor:
// cursors and `q` are never in the address, R8-1 item 6), run by the pure loader over the server transport. 401 → the
// session-ended notice; 403 → ST-4b; any other failure renders the list's region error (first load) with Retry. No create
// action until C-3 (the create page, with G-48's shared-name dialog).
export const dynamic = 'force-dynamic';

export const metadata: Metadata = { title: CUSTOMERS_LIST.heading(false) };

/** The address names the view: exactly `?archived=true` is the Archived view, anything else live. */
export default async function Page({ searchParams }: { searchParams: Promise<Record<string, string | string[] | undefined>> }) {
  const query = await searchParams;
  const { session, viewer } = await currentViewer(sessionEndedPath('/customers', query));
  const archived = query.archived === 'true';
  if (!viewerMay('ListCustomers', viewer.permissionCodes)) return <CustomersDenied />;
  const first = await loadCustomers(serverRead(session), archived, { q: '', cursor: null });
  if (first.kind === 'session-ended') redirect(sessionEndedPath('/customers', query));
  if (first.kind === 'forbidden') return <CustomersDenied />;
  return <CustomersList initial={first} initialArchived={archived} />;
}

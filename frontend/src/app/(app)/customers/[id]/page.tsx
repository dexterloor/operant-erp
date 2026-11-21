import type { Metadata } from 'next';
import { notFound, redirect } from 'next/navigation';
import { cache } from 'react';
import { CUSTOMER_PAGE, CUSTOMERS_LIST } from '@/copy/customers';
import { isRecordId } from '@/lib/bff/paths';
import { serverRead } from '@/lib/bff/server/reads';
import { viewerMay } from '@/lib/bff/surface';
import { loadAddresses, loadContacts, loadCustomer } from '@/lib/customers/load';
import { currentViewer, sessionEndedPath } from '../../currentViewer';
import { CustomersDenied } from '../CustomerStates';
import { CustomerPage } from './CustomerPage';

// `/customers/[id]` (customers-plan.md Revision 1 — FINAL R1-5 C-1; the /suppliers/[id] shape; Revision 8 R8-2; Revision 7
// A-15, A-20). First render: GetCustomer over the server transport, once per request (shared with the title). Read-only in
// C-1: no editor, Restore or Delete is offered to anyone. The Contacts and Addresses sections are each read and rendered
// only for a viewer holding their read's codes (ListCustomerContacts', ListCustomerAddresses'; A-20): for anyone else the
// section is absent and not fetched. A 401 on either read → the session-ended notice; any other failure is the section's own
// state. 401 → the session-ended notice; 403 → ST-4b; 404 or a non-record id → ST-5 (`not-found.tsx`); any other failure
// throws to the error page.
export const dynamic = 'force-dynamic';

type Params = { params: Promise<{ id: string }> };

const customerFor = cache(async (id: string) => {
  const { session } = await currentViewer(sessionEndedPath(`/customers/${encodeURIComponent(id)}`));
  return loadCustomer(serverRead(session), id);
});

export async function generateMetadata({ params }: Params): Promise<Metadata> {
  const { id } = await params;
  const { viewer } = await currentViewer(sessionEndedPath(`/customers/${encodeURIComponent(id)}`));
  if (!isRecordId(id) || !viewerMay('GetCustomer', viewer.permissionCodes)) return { title: CUSTOMERS_LIST.heading(false) };
  const c = await customerFor(id);
  return { title: c.kind === 'ok' ? CUSTOMER_PAGE.title(c.customer.code, c.customer.name) : CUSTOMERS_LIST.heading(false) };
}

export default async function Page({ params }: Params) {
  const { id } = await params;
  const { viewer } = await currentViewer(sessionEndedPath(`/customers/${encodeURIComponent(id)}`));
  if (!viewerMay('GetCustomer', viewer.permissionCodes)) return <CustomersDenied />;
  if (!isRecordId(id)) notFound();
  const first = await customerFor(id);
  if (first.kind === 'session-ended') redirect(sessionEndedPath(`/customers/${encodeURIComponent(id)}`));
  if (first.kind === 'forbidden') return <CustomersDenied />;
  if (first.kind === 'not-found') notFound();
  if (first.kind !== 'ok') throw new Error('The customer could not be read.');
  const codes = viewer.permissionCodes;
  const back = sessionEndedPath(`/customers/${encodeURIComponent(id)}`);
  let contacts = null;
  if (viewerMay('ListCustomerContacts', codes)) {
    const { session } = await currentViewer(back);
    contacts = await loadContacts(serverRead(session), id);
    if (contacts.kind === 'session-ended') redirect(back);
  }
  let addresses = null;
  if (viewerMay('ListCustomerAddresses', codes)) {
    const { session } = await currentViewer(back);
    addresses = await loadAddresses(serverRead(session), id);
    if (addresses.kind === 'session-ended') redirect(back);
  }
  return <CustomerPage customer={first.customer} contacts={contacts} addresses={addresses} />;
}

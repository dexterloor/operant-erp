'use client';

import { Banner } from '@/components/ui/Banner';
import { DateTime } from '@/components/ui/DateTime';
import { LiveRegion, useLiveRegion } from '@/components/ui/LiveRegion';
import { CUSTOMER_PAGE } from '@/copy/customers';
import type { Customer } from '@/lib/api/customers';
import type { AddressesLoad, ContactsLoad } from '@/lib/customers/load';
import { useViewerTimeZone } from '../../BffProvider';
import listStyles from '../../Lists.module.css';
import styles from '../Customers.module.css';
import { AddressesSection } from './AddressesSection';
import { ContactsSection } from './ContactsSection';

export interface CustomerPageProps {
  /** GetCustomer on first render. */
  customer: Customer;
  /** `ListCustomerContacts` on first render, or null when the viewer does not hold its codes: then absent, not fetched (A-20). */
  contacts?: ContactsLoad | null;
  /** `ListCustomerAddresses` on first render, or null when the viewer does not hold its codes: then absent, not fetched (A-20). */
  addresses?: AddressesLoad | null;
}

/**
 * `/customers/[id]` in C-1 (customers-plan.md Revision 1 — FINAL R1-5): read-only for everyone. Code, Name, Country,
 * Currency and Payment terms as text; an archived customer shows the ST-9 banner with no action (Restore ships at C-2b, with
 * G-48's shared-name dialog). It becomes the editor at C-2a (IA L-4). The four `customer` columns nothing writes (G-36) are
 * not served and are never shown. Below the fields: the read-only Contacts section, then the read-only Addresses section
 * (`customers-c1-ux-copy.md` R1-5 L-4), each for a holder of its read's codes. The page owns the one status region, in
 * the DOM from first render and empty; the sections write to it only for the count a successful Retry speaks (ST-2a).
 */
export function CustomerPage({ customer, contacts = null, addresses = null }: CustomerPageProps) {
  const timeZone = useViewerTimeZone();
  const region = useLiveRegion();
  return (
    <section className={listStyles.page}>
      <LiveRegion message={region.message} visuallyHidden />
      <header className={listStyles.header}>
        <h1 tabIndex={-1}>{CUSTOMER_PAGE.title(customer.code, customer.name)}</h1>
      </header>
      {customer.archived_at !== null ? (
        <Banner
          tone="neutral"
          heading={
            <>
              {CUSTOMER_PAGE.archivedOn}
              <DateTime value={customer.archived_at} form="date" timeZone={timeZone} />
              {CUSTOMER_PAGE.archivedAfter}
            </>
          }
        />
      ) : null}
      <dl className={styles.fields}>
        <dt>{CUSTOMER_PAGE.code}</dt>
        <dd>{customer.code}</dd>
        <dt>{CUSTOMER_PAGE.name}</dt>
        <dd>{customer.name}</dd>
        <dt>{CUSTOMER_PAGE.country}</dt>
        <dd>{customer.country_code}</dd>
        <dt>{CUSTOMER_PAGE.currency}</dt>
        <dd>{customer.currency_code}</dd>
        <dt>{CUSTOMER_PAGE.paymentTerms}</dt>
        <dd>{CUSTOMER_PAGE.days(customer.payment_terms_days)}</dd>
      </dl>
      {contacts !== null ? <ContactsSection customerId={customer.id} initial={contacts} announce={region.announce} clearRegion={region.clear} /> : null}
      {addresses !== null ? <AddressesSection customerId={customer.id} initial={addresses} announce={region.announce} clearRegion={region.clear} /> : null}
    </section>
  );
}

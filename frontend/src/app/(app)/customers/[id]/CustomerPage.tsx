'use client';

import { useCallback, useLayoutEffect, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import { CustomerForm } from '@/components/customers/CustomerForm';
import { RegionError } from '@/components/states/PageStates';
import { Banner } from '@/components/ui/Banner';
import { DateTime } from '@/components/ui/DateTime';
import { LiveRegion, useLiveRegion } from '@/components/ui/LiveRegion';
import { COULD_NOT_REACH, NOT_SAVED, WENT_WRONG } from '@/copy/headings';
import { CUSTOMER_PAGE } from '@/copy/customers';
import type { Customer } from '@/lib/api/customers';
import { ACTION_DEADLINES_MS, startDeadline } from '@/lib/bff/deadline';
import { readOf, type ReadFailure } from '@/lib/bff/reads';
import { gateCode } from '@/lib/bff/surface';
import { loadCustomer, type AddressesLoad, type ContactsLoad } from '@/lib/customers/load';
import type { CustomerChange } from '@/lib/customers/results';
import { customerSave } from '@/lib/customers/wired';
import { useApiCaller, useViewerTimeZone } from '../../BffProvider';
import { useCommittedState } from '../../useCommittedState';
import listStyles from '../../Lists.module.css';
import styles from '../Customers.module.css';
import { CustomerNotFound, CustomersDenied } from '../CustomerStates';
import { AddressesSection } from './AddressesSection';
import { ContactsSection } from './ContactsSection';

export interface CustomerPageProps {
  /** GetCustomer on first render. */
  customer: Customer;
  /** Holds UpdateCustomer's codes (from the contract): the editor on a live customer (C-2a). Without it the values are plain text. */
  canEdit?: boolean;
  /** Holds CreateCustomerContact's codes (from the contract): **Add contact** on a live customer (C-4a). */
  canAddContact?: boolean;
  /** Holds UpdateCustomerContact's codes (from the contract): each contact row's **Edit** on a live customer (C-4a). */
  canEditContact?: boolean;
  /** Holds DeleteCustomerContact's codes (from the contract): each contact row's **Remove** on a live customer (C-4b). */
  canRemoveContact?: boolean;
  /** Holds CreateCustomerAddress's codes (from the contract): **Add address** on a live customer (C-5a). */
  canAddAddress?: boolean;
  /** Holds UpdateCustomerAddress's codes (from the contract): each address row's **Edit** on a live customer (C-5a). */
  canEditAddress?: boolean;
  /** Holds DeleteCustomerAddress's codes (from the contract): each address row's **Remove** on a live customer (C-5b). */
  canRemoveAddress?: boolean;
  /** `ListCustomerContacts` on first render, or null when the viewer does not hold its codes: then absent, not fetched (A-20). */
  contacts?: ContactsLoad | null;
  /** `ListCustomerAddresses` on first render, or null when the viewer does not hold its codes: then absent, not fetched (A-20). */
  addresses?: AddressesLoad | null;
}

type Cause = 'unreachable' | 'server-error';

/**
 * `/customers/[id]` (customers-plan.md Revision 1 — FINAL R1-5; IA L-4: the detail page is the editor). From C-2a it is the
 * editor for an UpdateCustomer holder on a live customer: Code, Country, Currency and Payment terms are fields, Name is
 * plain text (`components/customers/CustomerForm`); for anyone else, and for an archived customer whatever the viewer
 * holds, C-1's read-only page: the five values as text, no hints, no Save (B-3). An archived customer shows the ST-9 banner
 * with no action (Restore ships at C-2b, with G-48's shared-name dialog). The page holds the customer in state and passes
 * the re-read one to the form without a remount (the `SupplierPage` shape, without Restore or Delete). A refused Save whose
 * re-read finds the customer archived turns the page archived and shows **Not saved** + `archivedOnSave` (K-15) above the
 * ST-9 banner, its heading focused — never the server's R3 (copy Revision 1a D-6) — unless a newer live reading was
 * committed first (FE-R7). A failed re-read → the region error (focus on its heading), Retry re-reads; 403 → ST-4b; 404 →
 * ST-5. The four `customer` columns nothing writes (G-36) are not served and are never shown. Below the fields: the
 * Contacts section (Add and Edit contact from C-4a, Remove from C-4b), then the Addresses section (`customers-c1-ux-copy.md` R1-5 L-4; Add and Edit address from C-5a, Remove from C-5b), each
 * for a holder of its read's codes. The page owns the one status region, in the DOM from first render and empty; every
 * write to it goes through `announce`, which counts it (the page write counter, R1-5; ARCH K-9: one counter for the whole
 * page, both sections included), and a late correction is written through `replaceIfCurrent` only while the write it
 * corrects is still the region's last. After a save's message is on screen the router cache is refreshed once. A contact
 * or address write whose answer says the customer may be stale has the page re-read it (`onParentStale`, stamped, through `commit` /
 * `failed`; it moves no focus); a re-read that commits an archived customer refreshes the router cache (ARCH K9-2).
 */
export function CustomerPage({ customer: initial, canEdit = false, canAddContact = false, canEditContact = false, canRemoveContact = false, canAddAddress = false, canEditAddress = false, canRemoveAddress = false, contacts = null, addresses = null }: CustomerPageProps) {
  const caller = useApiCaller();
  const router = useRouter();
  const timeZone = useViewerTimeZone();
  // The page write counter (customers-plan.md Revision 1 — FINAL R1-5; the `SupplierPage` shape): every write to this page's
  // region — Save's sentence and its press's clear, the sections' Retry count and clear — goes through `announce`, which
  // counts it. Decided by the count, never by the text: the same sentence can be written twice.
  const regionWrites = useRef(0);
  const { message: regionMessage, announce: writeRegion } = useLiveRegion();
  const announce = useCallback(
    (text: string) => {
      regionWrites.current += 1;
      writeRegion(text);
      return regionWrites.current;
    },
    [writeRegion],
  );
  // A late correction (a section's list-stale form) is written only while write number `gen` is still the region's last.
  // Decided by the count, never by the text.
  const replaceIfCurrent = useCallback(
    (gen: number, text: string) => {
      if (regionWrites.current === gen) announce(text);
    },
    [announce],
  );
  const [customer, setCustomer] = useCommittedState(initial);
  const [swap, setSwap] = useState<'forbidden' | 'not-found' | null>(null);
  const [rereadError, setRereadError] = useState<{ gen: number; cause: Cause } | null>(null);
  const [retrying, setRetrying] = useState(false);
  const [staleRefusal, setStaleRefusal] = useState<{ gen: number } | null>(null);
  const staleHeading = useRef<HTMLHeadingElement>(null);
  const h1 = useRef<HTMLHeadingElement>(null);
  const id = initial.id;

  // The stamp rule (the supplier page's, without its delete floor: C-2a has no Delete): every commit is stamped with the
  // moment its reading was requested. A page-owned re-read (Retry) takes its stamp when its request is sent; Save takes its
  // stamp when it sends its re-read, after the write's answer has arrived. A commit or failure whose stamp is older than
  // what is on screen is dropped, so an older re-read landing late never undoes a newer one.
  const stamps = useRef(0);
  const committedAsOf = useRef(0);
  // FE-R7: whether the reading last committed is archived, for the stale-refusal rule.
  const committedArchived = useRef(initial.archived_at !== null);
  const stamp = useCallback(() => ++stamps.current, []);
  const failed = useCallback((f: ReadFailure, asOf?: number) => {
    if (asOf !== undefined && asOf < committedAsOf.current) return;
    if (f === 'session-ended') return;
    if (f === 'forbidden' || f === 'not-found') {
      setSwap(f);
      return;
    }
    // After the form's own focus, so the region error's heading takes it.
    setTimeout(() => setRereadError((cur) => ({ gen: (cur?.gen ?? 0) + 1, cause: f })), 0);
  }, []);
  const commit = useCallback(
    async (c: Customer, asOf: number) => {
      if (asOf < committedAsOf.current) return;
      committedAsOf.current = asOf;
      committedArchived.current = c.archived_at !== null;
      setRereadError(null);
      // Live by any path (a Retry): the stale Save's sentence describes nothing on screen.
      if (c.archived_at === null) setStaleRefusal(null);
      await setCustomer(c);
    },
    [setCustomer],
  );
  // The wiring is built when the action runs, not during render: `commit` writes the stamp refs (react-hooks/refs).
  const save = useCallback((changes: CustomerChange) => customerSave({ caller, id, stamp, setCustomer: commit, onRereadFailed: failed })(changes), [caller, id, stamp, commit, failed]);
  const onSaved = useCallback(() => router.refresh(), [router]);
  // Copy R1a-1 (C-4a; addresses from C-5a): a contact or address write's answer says the page's customer may be stale. The page re-reads it under its own
  // stamp (the supplier page's `onParentStale`), so the archived banner, the sections' note and the loss of their actions
  // follow from what is read, not from the refusal. It moves no focus. An archived commit refreshes the router cache (K9-2).
  const onParentStale = useCallback(async () => {
    const mine = ++stamps.current;
    const d = startDeadline(ACTION_DEADLINES_MS.reread);
    const r = await loadCustomer(readOf(caller), id, d.signal);
    d.cancel();
    if (committedAsOf.current > mine) return;
    if (r.kind === 'ok') {
      await commit(r.customer, mine);
      if (r.customer.archived_at !== null) router.refresh();
    } else failed(r.kind, mine);
  }, [caller, id, commit, failed, router]);
  const onRefusedArchived = useCallback(() => {
    // FE-R7: the page is live (a newer reading committed after this Save's archived reading was asked for): K-15 describes
    // nothing on screen and is not kept for a later archived reading.
    if (!committedArchived.current) return;
    setStaleRefusal((cur) => ({ gen: (cur?.gen ?? 0) + 1 }));
  }, []);

  // After the commit that renders the banner (focus never runs ahead of what it lands on).
  const staleGen = staleRefusal?.gen ?? null;
  useLayoutEffect(() => {
    if (staleGen !== null) staleHeading.current?.focus();
  }, [staleGen]);

  // A commit that turns the page archived unmounts the form (and its Save) under the person's focus: when focus is then
  // lost, it moves to the h1. Effects that focus something themselves run after this one.
  const archived = customer.archived_at !== null;
  useLayoutEffect(() => {
    if (!archived) return;
    const a = document.activeElement;
    if (a === null || a === document.body || !a.isConnected) h1.current?.focus();
  }, [archived]);

  const retry = async () => {
    if (retrying) return;
    setRetrying(true);
    const mine = ++stamps.current;
    const d = startDeadline(ACTION_DEADLINES_MS.reread);
    const r = await loadCustomer(readOf(caller), id, d.signal);
    d.cancel();
    setRetrying(false);
    if (committedAsOf.current > mine) return;
    if (r.kind === 'ok') {
      await commit(r.customer, mine);
      setRereadError(null);
      h1.current?.focus();
    } else failed(r.kind, mine);
  };

  if (swap === 'forbidden') return <CustomersDenied />;
  if (swap === 'not-found') return <CustomerNotFound />;

  return (
    <section className={listStyles.page}>
      <LiveRegion message={regionMessage} visuallyHidden />
      <header className={listStyles.header}>
        <h1 ref={h1} tabIndex={-1}>
          {CUSTOMER_PAGE.title(customer.code, customer.name)}
        </h1>
      </header>
      {rereadError ? (
        <RegionError key={`reread-${rereadError.gen}`} heading={rereadError.cause === 'unreachable' ? COULD_NOT_REACH : WENT_WRONG} body={CUSTOMER_PAGE.rereadFailed} retrying={retrying} onRetry={() => void retry()} />
      ) : null}
      {staleRefusal && archived ? (
        <Banner key={`stale-${staleRefusal.gen}`} tone="danger" live="none" heading={NOT_SAVED} ref={staleHeading}>
          <p>{CUSTOMER_PAGE.archivedOnSave}</p>
        </Banner>
      ) : null}
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
      {canEdit && !archived ? (
        <CustomerForm customer={customer} save={save} editCode={gateCode('UpdateCustomer')} announce={announce} fallbackFocusRef={h1} onSaved={onSaved} onSaveStarted={() => void announce('')} onRefusedArchived={onRefusedArchived} />
      ) : (
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
      )}
      {contacts !== null ? (
        <ContactsSection
          customerId={id}
          initial={contacts}
          customerArchived={archived}
          customerCode={customer.code}
          canAdd={canAddContact}
          canEdit={canEditContact}
          canRemove={canRemoveContact}
          announce={announce}
          replaceIfCurrent={replaceIfCurrent}
          onParentStale={() => void onParentStale()}
        />
      ) : null}
      {addresses !== null ? (
        <AddressesSection
          customerId={id}
          initial={addresses}
          customerArchived={archived}
          customerCode={customer.code}
          canAdd={canAddAddress}
          canEdit={canEditAddress}
          canRemove={canRemoveAddress}
          announce={announce}
          replaceIfCurrent={replaceIfCurrent}
          onParentStale={() => void onParentStale()}
        />
      ) : null}
    </section>
  );
}

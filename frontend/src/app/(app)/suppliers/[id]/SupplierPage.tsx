'use client';

import { useCallback, useLayoutEffect, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import { SupplierForm } from '@/components/suppliers/SupplierForm';
import styles from '@/components/suppliers/Suppliers.module.css';
import { RestoreActions } from '@/components/records/RestoreActions';
import { RegionError } from '@/components/states/PageStates';
import { Banner } from '@/components/ui/Banner';
import { LiveRegion, useLiveRegion } from '@/components/ui/LiveRegion';
import { COULD_NOT_REACH, NOT_SAVED, WENT_WRONG } from '@/copy/headings';
import { RESTORE_SUPPLIER, SUPPLIER_FORBIDDEN, SUPPLIER_PAGE } from '@/copy/suppliers';
import type { Supplier } from '@/lib/api/suppliers';
import type { CatalogueLoad } from '@/lib/suppliers/load';
import { ACTION_DEADLINES_MS, startDeadline } from '@/lib/bff/deadline';
import { readOf, type ReadFailure } from '@/lib/bff/reads';
import { gateCode } from '@/lib/bff/surface';
import { loadSupplier } from '@/lib/suppliers/load';
import type { SupplierChange } from '@/lib/suppliers/results';
import { supplierRestore, supplierSave } from '@/lib/suppliers/wired';
import { useArrivalMessage } from '../../arrivalMessage';
import { useApiCaller, useViewerTimeZone } from '../../BffProvider';
import { useCommittedState } from '../../useCommittedState';
import { SupplierNotFound, SuppliersDenied } from '../SupplierStates';
import { CatalogueSection } from './CatalogueSection';
import listStyles from '../../Lists.module.css';

export interface SupplierPageProps {
  /** GetSupplier on first render. */
  initial: Supplier;
  /** Holds UpdateSupplier's codes (from the contract): ST-4c. Without it the values are plain text. */
  canEdit: boolean;
  /** Holds RestoreSupplier's codes (from the contract): ST-4c. Without it an archived supplier shows its banner, no button. */
  canRestore: boolean;
  /**
   * The catalogue section (S-3), or null when the viewer does not hold ListSupplierProducts' codes: then it is absent and
   * was not fetched (A-20). `canOpenItem` = GetItem's codes.
   */
  catalogue?: { initial: CatalogueLoad; canOpenItem: boolean } | null;
}

type Cause = 'unreachable' | 'server-error';

/**
 * The supplier page (suppliers-ux-copy.md Revision 1 — FINAL S-1 over Revision 0 B-3, B-5; IA L-4: the detail page is the
 * editor; Revision 8 R8-2; the `ItemPage` shape without Delete, which arrives at S-7). It holds the supplier in state and
 * passes the re-read one to the form without a remount. Editable (Code, Name, Country, Payment terms) for an
 * UpdateSupplier holder on a live supplier; the same values as plain text otherwise, with no hints. Currency is plain text
 * in every state of S-1…S-5. An archived supplier is read-only whatever the viewer holds, under the ST-9 banner
 * *"This supplier was archived on {date}."* with **Restore supplier** for a RestoreSupplier holder
 * (`components/records/RestoreActions`): a restore sets the re-read supplier here, so the page turns live without a
 * remount. A refused Save whose re-read finds the supplier archived (S5 on a stale page) turns the page archived and
 * shows the server's sentence under **Not saved**, its heading focused — unless a newer live reading was committed first
 * (FE-R7): the refusal is not kept. A failed re-read → the region error (focus on its heading), Retry re-reads; 403 →
 * ST-4b; 404 → ST-5. After a create (S-2) the page speaks *"{code} was created."* once, in its region. The catalogue section (S-3, read-only) follows the editor for a ListSupplierProducts holder, its archived-parent note following the supplier on screen. No History. After a save's or restore's message is on screen the router cache is
 * refreshed once.
 */
export function SupplierPage({ initial, canEdit, canRestore, catalogue = null }: SupplierPageProps) {
  const caller = useApiCaller();
  const router = useRouter();
  const timeZone = useViewerTimeZone();
  const region = useLiveRegion();
  const [supplier, setSupplier] = useCommittedState(initial);
  const [swap, setSwap] = useState<'forbidden' | 'not-found' | null>(null);
  const [rereadError, setRereadError] = useState<{ gen: number; cause: Cause } | null>(null);
  const [retrying, setRetrying] = useState(false);
  const [staleRefusal, setStaleRefusal] = useState<{ gen: number; message: string } | null>(null);
  const staleHeading = useRef<HTMLHeadingElement>(null);
  const h1 = useRef<HTMLHeadingElement>(null);
  const id = initial.id;
  // After a create (S-2; S-1 D-3): the create page left *"{code} was created."* for this address (A-19 item 7). Written to
  // this page's one region, like Save's and Restore's sentences: a later writer simply replaces it.
  useArrivalMessage(`/suppliers/${id}`, region.announce);

  // The stamp rule (items-i3-design.md Revision 1 — FINAL §0a, as on the item page, without its delete floor): every
  // commit is stamped with the moment its reading was requested. A page-owned re-read (Retry) takes its stamp when its
  // request is sent; a write (Save, Restore) takes its stamp when it sends its re-read, after the write's answer has
  // arrived, and its re-read — or, when that fails, the write's own answer body — commits under that stamp. A commit or
  // failure whose stamp is older than what is on screen is dropped, so an older re-read landing late never undoes a
  // newer one. Known limit (the item page's): when a write's re-read fails, its answer body carries the re-read's stamp,
  // so it can overwrite a Retry, sent in between, that read someone else's later change; the next reading corrects it.
  const stamps = useRef(0);
  const committedAsOf = useRef(0);
  // FE-R7: whether the reading last committed is archived, for the stale-refusal rule. Decided from what was committed,
  // never from the render the Save was pressed in.
  const committedArchived = useRef(initial.archived_at !== null);
  const stamp = useCallback(() => ++stamps.current, []);
  const failed = useCallback((f: ReadFailure, asOf?: number) => {
    if (asOf !== undefined && asOf < committedAsOf.current) return;
    if (f === 'session-ended') return;
    if (f === 'forbidden' || f === 'not-found') {
      setSwap(f);
      return;
    }
    // After the form's own after-save focus, so the region error's heading takes it.
    setTimeout(() => setRereadError((cur) => ({ gen: (cur?.gen ?? 0) + 1, cause: f })), 0);
  }, []);

  const commit = useCallback(
    async (s: Supplier, asOf: number) => {
      if (asOf < committedAsOf.current) return;
      committedAsOf.current = asOf;
      committedArchived.current = s.archived_at !== null;
      setRereadError(null);
      // Live by any path (a restore, an already-live re-read, a Retry): the stale Save's sentence describes nothing on screen.
      if (s.archived_at === null) setStaleRefusal(null);
      await setSupplier(s);
    },
    [setSupplier],
  );
  // The wiring is built when an action runs, not during render: `commit` writes the stamp refs, which may only be
  // touched outside render (react-hooks/refs).
  const save = useCallback((changes: SupplierChange) => supplierSave({ caller, id, stamp, setSupplier: commit, onRereadFailed: failed })(changes), [caller, id, stamp, commit, failed]);
  const restore = useCallback(() => supplierRestore({ caller, id, stamp, setSupplier: commit, onRereadFailed: failed })(), [caller, id, stamp, commit, failed]);
  const onSaved = useCallback(() => router.refresh(), [router]);
  const onRefusedArchived = useCallback((message: string) => {
    // FE-R7: the page is live (a restore committed after this Save's archived reading was asked for, so that reading was
    // dropped): the refusal describes nothing on screen and is not kept for a later archived reading.
    if (!committedArchived.current) return;
    setStaleRefusal((cur) => ({ gen: (cur?.gen ?? 0) + 1, message }));
  }, []);
  // A restore turns the page live: the stale Save's sentence no longer describes what is on screen.
  const onRestored = useCallback(() => {
    setStaleRefusal(null);
    router.refresh();
  }, [router]);

  // After the commit that renders the banner (focus never runs ahead of what it lands on).
  const staleGen = staleRefusal?.gen ?? null;
  useLayoutEffect(() => {
    if (staleGen !== null) staleHeading.current?.focus();
  }, [staleGen]);

  // A commit that turns the page archived unmounts the form (and its Save) under the person's focus: when focus is then
  // lost (on <body> or a removed element), it moves to the h1. Effects that focus something themselves run after this one.
  const archived = supplier.archived_at !== null;
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
    const r = await loadSupplier(readOf(caller), id, d.signal);
    d.cancel();
    setRetrying(false);
    if (committedAsOf.current > mine) return;
    if (r.kind === 'ok') {
      await commit(r.supplier, mine);
      setRereadError(null);
      h1.current?.focus();
    } else failed(r.kind, mine);
  };

  if (swap === 'forbidden') return <SuppliersDenied />;
  if (swap === 'not-found') return <SupplierNotFound />;

  return (
    <section className={listStyles.page}>
      <header className={listStyles.header}>
        <h1 ref={h1} tabIndex={-1}>
          {SUPPLIER_PAGE.title(supplier.code, supplier.name)}
        </h1>
      </header>
      {/* Mounted from first render, above every branch below, so it never arrives already speaking. */}
      <LiveRegion message={region.message} visuallyHidden />
      {rereadError ? (
        <RegionError key={`reread-${rereadError.gen}`} heading={rereadError.cause === 'unreachable' ? COULD_NOT_REACH : WENT_WRONG} body={SUPPLIER_PAGE.rereadFailed} retrying={retrying} onRetry={() => void retry()} />
      ) : null}
      {staleRefusal && archived ? (
        <Banner key={`stale-${staleRefusal.gen}`} tone="danger" live="none" heading={NOT_SAVED} ref={staleHeading}>
          <p>{staleRefusal.message}</p>
        </Banner>
      ) : null}
      {supplier.archived_at !== null ? (
        <RestoreActions
          archivedAt={supplier.archived_at}
          copy={{
            archivedBefore: SUPPLIER_PAGE.archivedOn,
            archivedAfter: SUPPLIER_PAGE.archivedAfter,
            action: RESTORE_SUPPLIER.action,
            after: (r) => RESTORE_SUPPLIER.after(r.supplier.code),
            alreadyLive: (r) => RESTORE_SUPPLIER.alreadyRestored(r.supplier.code),
            interrupted: RESTORE_SUPPLIER.interrupted(supplier.code),
            forbidden: SUPPLIER_FORBIDDEN,
          }}
          canRestore={canRestore}
          restore={restore}
          restoreCode={gateCode('RestoreSupplier')}
          timeZone={timeZone}
          announce={region.announce}
          fallbackFocusRef={h1}
          onRestored={onRestored}
        />
      ) : null}
      {canEdit && !archived ? (
        <SupplierForm supplier={supplier} save={save} editCode={gateCode('UpdateSupplier')} announce={region.announce} fallbackFocusRef={h1} onSaved={onSaved} onSaveStarted={region.clear} onRefusedArchived={onRefusedArchived} />
      ) : (
        <dl className={styles.fields}>
          <dt>{SUPPLIER_PAGE.code}</dt>
          <dd>{supplier.code}</dd>
          <dt>{SUPPLIER_PAGE.name}</dt>
          <dd>{supplier.name}</dd>
          <dt>{SUPPLIER_PAGE.country}</dt>
          <dd>{supplier.country_code}</dd>
          <dt>{SUPPLIER_PAGE.currency}</dt>
          <dd>{supplier.currency_code}</dd>
          <dt>{SUPPLIER_PAGE.paymentTerms}</dt>
          <dd>{SUPPLIER_PAGE.days(supplier.payment_terms_days)}</dd>
        </dl>
      )}
      {catalogue ? <CatalogueSection supplierId={id} initial={catalogue.initial} supplierArchived={archived} canOpenItem={catalogue.canOpenItem} announce={region.announce} clearRegion={region.clear} /> : null}
    </section>
  );
}

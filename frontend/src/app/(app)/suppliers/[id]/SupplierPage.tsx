'use client';

import { useCallback, useLayoutEffect, useRef, useState } from 'react';
import { flushSync } from 'react-dom';
import { useRouter } from 'next/navigation';
import { DeleteDialog } from '@/components/records/DeleteDialog';
import { SupplierForm } from '@/components/suppliers/SupplierForm';
import styles from '@/components/suppliers/Suppliers.module.css';
import { RestoreActions } from '@/components/records/RestoreActions';
import { RegionError } from '@/components/states/PageStates';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { LiveRegion, useLiveRegion } from '@/components/ui/LiveRegion';
import { COULD_NOT_REACH, NOT_SAVED, WENT_WRONG } from '@/copy/headings';
import { DELETE_SUPPLIER, RESTORE_SUPPLIER, SUPPLIER_FORBIDDEN, SUPPLIER_PAGE } from '@/copy/suppliers';
import type { Supplier } from '@/lib/api/suppliers';
import type { CatalogueLoad } from '@/lib/suppliers/load';
import { ACTION_DEADLINES_MS, startDeadline } from '@/lib/bff/deadline';
import { readOf, type ReadFailure } from '@/lib/bff/reads';
import { gateCode } from '@/lib/bff/surface';
import { loadSupplier } from '@/lib/suppliers/load';
import type { SupplierChange } from '@/lib/suppliers/results';
import { supplierDelete, supplierRestore, supplierSave } from '@/lib/suppliers/wired';
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
  /** Holds DeleteSupplier's codes (from the contract): **Delete supplier** in the header of a live supplier (S-7). */
  canDelete?: boolean;
  /**
   * The catalogue section (S-3), or null when the viewer does not hold ListSupplierProducts' codes: then it is absent and
   * was not fetched (A-20). `canOpenItem` = GetItem's codes; `canEdit` = UpdateSupplierProduct's (S-4); `canAdd` =
   * CreateSupplierProduct's and ListItems' together (S-5); `canRemove` = DeleteSupplierProduct's (S-6).
   */
  catalogue?: { initial: CatalogueLoad; canOpenItem: boolean; canEdit: boolean; canAdd?: boolean; canRemove?: boolean } | null;
}

type Cause = 'unreachable' | 'server-error';

/**
 * The supplier page (suppliers-ux-copy.md Revision 1 — FINAL S-1 over Revision 0 B-3, B-5; IA L-4: the detail page is the
 * editor; Revision 8 R8-2; the `ItemPage` shape, its Delete from S-7). It holds the supplier in state and
 * passes the re-read one to the form without a remount. Editable (Code, Name, Country, Currency, Payment terms) for an
 * UpdateSupplier holder on a live supplier; the same values as plain text otherwise, with no hints. Currency was plain
 * text in S-1…S-5 and is editable from S-6, with one unconditional hint. An archived supplier is read-only whatever the viewer holds, under the ST-9 banner
 * *"This supplier was archived on {date}."* with **Restore supplier** for a RestoreSupplier holder
 * (`components/records/RestoreActions`): a restore sets the re-read supplier here, so the page turns live without a
 * remount. A refused Save whose re-read finds the supplier archived (S5 on a stale page) turns the page archived and
 * shows the server's sentence under **Not saved**, its heading focused — unless a newer live reading was committed first
 * (FE-R7): the refusal is not kept. A failed re-read → the region error (focus on its heading), Retry re-reads; 403 →
 * ST-4b; 404 → ST-5. After a create (S-2) the page speaks *"{code} was created."* once, in its region. The catalogue
 * section (S-3; Edit from S-4) follows the editor for a ListSupplierProducts holder, its archived-parent note and its
 * actions following the supplier on screen; an entry write refused because the supplier was archived meanwhile has the
 * page re-read the supplier (`onParentStale`, final RED M-3). No History. After a save's or restore's message is on screen
 * the router cache is refreshed once.
 *
 * **Delete supplier** (S-7, suppliers-plan.md Revision 1 — FINAL §R1-4 S-7 = Revision 0 S-6 + M-3; UX S-7; the `ItemPage`
 * shape, FE-D1) on a live supplier for a DeleteSupplier holder: the shared danger dialog. After a 204 the page stays and
 * turns archived (focus h1, "After" spoken, one refresh), or stays live and says it has since been restored (S-2). The 204
 * is a fact that outranks a stale snapshot (`deletedHere`): until a commit that is not dropped says otherwise, there is no
 * Delete supplier, no form, and the catalogue section has no actions — in the same mount (M-3) — even when the delete's
 * re-read failed. Any other close after an answer re-reads: an archived answer is committed (focus h1, one refresh); a
 * live one is not committed, so the typed edits stay (ItemPage's M-5). The person's own Delete outranks their own Save
 * (the items UX ruling M-3 / T-17 / L-1, carried here): a Save sent before the 204 neither speaks "saved", nor shows its
 * refusal, nor commits its answer body, nor puts its failed re-read's region error over the delete (a 403 / 404 swap is
 * still shown); a refusal banner already showing is cleared by the 204. Known limit: an own delete whose answer was lost
 * (interrupted) raises no 204 floor, so a Save sent before it still speaks or shows as before.
 */
export function SupplierPage({ initial, canEdit, canRestore, canDelete = false, catalogue = null }: SupplierPageProps) {
  const caller = useApiCaller();
  const router = useRouter();
  const timeZone = useViewerTimeZone();
  // Final RED M-5 (the FE-R8 location half, `WarehousePage.tsx` precedent; s3-fer8-final-red.md F-3): from S-4 every write
  // to this page's region goes through `announce`, which counts it and returns the count — the arrival message, the
  // supplier's Save (and its press's clear), Restore supplier (and its press's clear), and the catalogue section (its Retry
  // press's clear and count, Edit's press clear and saved sentence). A late correction (`replaceIfCurrent`) is written only
  // while nothing has written the region since the write it corrects, by anyone. Decided by the count, never by the text:
  // the same sentence can be written twice.
  const regionWrites = useRef(0);
  // fe-r8-location-red.md L-6: only the message and the raw writer are taken (no `region` object in scope), and the raw
  // writer is used only inside `announce` below.
  const { message: regionMessage, announce: writeRegion } = useLiveRegion();
  const announce = useCallback(
    (text: string) => {
      regionWrites.current += 1;
      writeRegion(text);
      return regionWrites.current;
    },
    [writeRegion],
  );
  const replaceIfCurrent = useCallback(
    (gen: number, text: string) => {
      if (regionWrites.current === gen) announce(text);
    },
    [announce],
  );
  const [supplier, setSupplier] = useCommittedState(initial);
  const [swap, setSwap] = useState<'forbidden' | 'not-found' | null>(null);
  const [rereadError, setRereadError] = useState<{ gen: number; cause: Cause } | null>(null);
  const [retrying, setRetrying] = useState(false);
  const [staleRefusal, setStaleRefusal] = useState<{ gen: number; message: string } | null>(null);
  const [deleting, setDeleting] = useState(false);
  // 4.6 RED M-1 (the item and warehouse pages): a 204 here is a fact that outranks a stale snapshot. If the re-read after
  // it failed, the supplier is still treated as archived for the actions until a commit that is not dropped says otherwise.
  const [deletedHere, setDeletedHere] = useState(false);
  // D-2 (lesson 5): the number of Delete supplier presses. The catalogue section's Add press reads before it opens its
  // dialog; a Delete supplier press while that read is out supersedes it (the later press wins, suppliers-s5-red.md M-1).
  const [deletePresses, setDeletePresses] = useState(0);
  const staleHeading = useRef<HTMLHeadingElement>(null);
  const h1 = useRef<HTMLHeadingElement>(null);
  const id = initial.id;
  // After a create (S-2; S-1 D-3): the create page left *"{code} was created."* for this address (A-19 item 7). Written to
  // this page's one region, like Save's and Restore's sentences: a later writer simply replaces it.
  useArrivalMessage(`/suppliers/${id}`, announce);

  // The stamp rule (items-i3-design.md Revision 1 — FINAL §0a, as on the item page, without its delete floor): every
  // commit is stamped with the moment its reading was requested. A page-owned re-read (Retry) takes its stamp when its
  // request is sent; a write (Save, Restore) takes its stamp when it sends its re-read, after the write's answer has
  // arrived, and its re-read — or, when that fails, the write's own answer body — commits under that stamp. A commit or
  // failure whose stamp is older than what is on screen is dropped, so an older re-read landing late never undoes a
  // newer one. Known limit (the item page's): when a write's re-read fails, its answer body carries the re-read's stamp,
  // so it can overwrite a Retry, sent in between, that read someone else's later change; the next reading corrects it.
  // S-7 (the item page's rule): a 204 raises what is on screen to the delete's re-read stamp, even when that re-read fails,
  // so no reading requested before it can undo the delete; a write's own answer body, its "saved" sentence, its refusal
  // and its failed re-read's region error are dropped when a 204 arrived after that write was sent (`sentAsOf`, compared
  // only with the 204 floor, never with the commit stamp). Known limit: the floor is never lowered, so after a Restore a
  // Save sent before the delete is still silenced; safe while such a Save's 200 means it was processed before the delete.
  // Why the silence loses nothing (po-8-red.md H-1 contrast): a PATCH processed after the DELETE is refused archived, so the
  // delete's own re-read shows the fields the server kept, read-only, and "After" names the kept code; the delete is undone by
  // Restore supplier (supplier.delete, Delete's own code). An approval is not undone, which is why PO-8 blocks instead.
  const stamps = useRef(0);
  const committedAsOf = useRef(0);
  const deletedAsOf = useRef(0);
  // The code last committed, for the "After" sentence: a Save may have renamed it after Delete was pressed.
  const committedCode = useRef(initial.code);
  // FE-R7: whether the reading last committed is archived, for the stale-refusal rule. Decided from what was committed,
  // never from the render the Save was pressed in.
  const committedArchived = useRef(initial.archived_at !== null);
  const stamp = useCallback(() => ++stamps.current, []);
  const failed = useCallback((f: ReadFailure, asOf?: number, sentAsOf?: number) => {
    if (asOf !== undefined && asOf < committedAsOf.current) return;
    if (f === 'session-ended') return;
    if (f === 'forbidden' || f === 'not-found') {
      setSwap(f);
      return;
    }
    // po-6-red.md (spar on :384; the items UX ruling L-1): a Save sent before a 204 of the person's own Delete does not
    // put its failed re-read over what the delete left. Swaps are kept: they say what the viewer may see now.
    if (sentAsOf !== undefined && sentAsOf < deletedAsOf.current) return;
    // After the form's own after-save focus, so the region error's heading takes it.
    setTimeout(() => setRereadError((cur) => ({ gen: (cur?.gen ?? 0) + 1, cause: f })), 0);
  }, []);

  const commit = useCallback(
    async (s: Supplier, asOf: number, sentAsOf?: number) => {
      if (asOf < committedAsOf.current) return;
      if (sentAsOf !== undefined && sentAsOf < deletedAsOf.current) return;
      committedAsOf.current = asOf;
      committedCode.current = s.code;
      committedArchived.current = s.archived_at !== null;
      setDeletedHere(false);
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
  // The 204 floor: raised before the delete's re-read is sent, so a reading requested earlier is dropped even when that
  // re-read fails. Without Math (the QR-2 backstop reads app files).
  const onDeleted = useCallback((s: number) => {
    if (s > committedAsOf.current) committedAsOf.current = s;
    deletedAsOf.current = s;
    committedArchived.current = true;
    setDeletedHere(true);
    // T-17: the supplier is archived by the person's own act; a stale Save refusal describes an edit they can no longer make.
    setStaleRefusal(null);
  }, []);
  const remove = useCallback(() => supplierDelete({ caller, id, stamp, setSupplier: commit, onRereadFailed: failed, onDeleted })(), [caller, id, stamp, commit, failed, onDeleted]);
  const onSaved = useCallback(() => router.refresh(), [router]);
  // UX M-3: a Save's sentence is not spoken over the person's own delete when the 204 came after it was sent.
  const announceSaved = useCallback(
    (text: string, sentAsOf: number) => {
      if (sentAsOf < deletedAsOf.current) return;
      announce(text);
    },
    [announce],
  );
  const onRefusedArchived = useCallback((message: string, sentAsOf: number) => {
    // T-17: a Save sent before a 204 of the person's own Delete shows no refusal.
    if (sentAsOf < deletedAsOf.current) return;
    // FE-R7: the page is live (a restore committed after this Save's archived reading was asked for, so that reading was
    // dropped): the refusal describes nothing on screen and is not kept for a later archived reading.
    if (!committedArchived.current) return;
    setStaleRefusal((cur) => ({ gen: (cur?.gen ?? 0) + 1, message }));
  }, []);
  // Final RED M-3: an entry write was refused because the supplier is archived (C1 on a stale page). The page re-reads
  // the supplier under its own stamp (`closeDelete`'s re-read shape on the warehouse page), so the archived banner, the
  // section's note and the loss of its actions follow from what is read, not from the refusal.
  const onParentStale = useCallback(async () => {
    const mine = ++stamps.current;
    const d = startDeadline(ACTION_DEADLINES_MS.reread);
    const r = await loadSupplier(readOf(caller), id, d.signal);
    d.cancel();
    if (committedAsOf.current > mine) return;
    if (r.kind === 'ok') {
      await commit(r.supplier, mine);
      if (r.supplier.archived_at !== null) router.refresh();
    } else failed(r.kind, mine);
  }, [caller, id, commit, failed, router]);
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

  // S-7 (the ItemPage.closeDelete shape, FE-D1). After a 204 `supplierDelete` has already committed (or reported) the
  // re-read: commit the close, focus the h1 (the opener is gone with the live state), speak "After" with the code last
  // committed — or the S-2 sentence when that re-read read the supplier live (restored in between) — refresh the router
  // cache once. `deletedHere` was set by `onDeleted`; only a commit that is not dropped clears it. Any other close after an
  // answer re-reads: an archived answer is committed (R5 is not it; S6, an interrupted press that committed, a delete
  // elsewhere) and the h1 takes focus; a live one changes nothing — not committed, so the typed edits stay (D-4).
  const closeDelete = async ({ deleted, reread }: { deleted: boolean; reread: boolean }) => {
    flushSync(() => setDeleting(false));
    if (deleted) {
      h1.current?.focus();
      announce(committedArchived.current ? DELETE_SUPPLIER.after(committedCode.current) : DELETE_SUPPLIER.afterRestored(committedCode.current));
      router.refresh();
      return;
    }
    if (!reread) return;
    const mine = ++stamps.current;
    const d = startDeadline(ACTION_DEADLINES_MS.reread);
    const r = await loadSupplier(readOf(caller), id, d.signal);
    d.cancel();
    if (committedAsOf.current > mine) return;
    if (r.kind === 'ok') {
      if (r.supplier.archived_at === null) return;
      await commit(r.supplier, mine);
      h1.current?.focus();
      router.refresh();
    } else failed(r.kind, mine);
  };

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

  const actionsArchived = archived || deletedHere;

  return (
    <section className={listStyles.page}>
      <header className={listStyles.header}>
        <h1 ref={h1} tabIndex={-1}>
          {SUPPLIER_PAGE.title(supplier.code, supplier.name)}
        </h1>
        {canDelete && !actionsArchived ? (
          <Button
            variant="destructive"
            onClick={() => {
              setDeletePresses((n) => n + 1);
              setDeleting(true);
            }}
          >
            {DELETE_SUPPLIER.action}
          </Button>
        ) : null}
      </header>
      {/* Mounted from first render, above every branch below, so it never arrives already speaking. */}
      <LiveRegion message={regionMessage} visuallyHidden />
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
          announce={announce}
          fallbackFocusRef={h1}
          onRestored={onRestored}
        />
      ) : null}
      {canEdit && !actionsArchived ? (
        <SupplierForm supplier={supplier} save={save} editCode={gateCode('UpdateSupplier')} announce={announceSaved} fallbackFocusRef={h1} onSaved={onSaved} onSaveStarted={() => announce('')} onRefusedArchived={onRefusedArchived} />
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
      {catalogue ? (
        <CatalogueSection
          supplierId={id}
          initial={catalogue.initial}
          supplierArchived={actionsArchived}
          deletePresses={deletePresses}
          canOpenItem={catalogue.canOpenItem}
          canEdit={catalogue.canEdit}
          canAdd={catalogue.canAdd ?? false}
          canRemove={catalogue.canRemove ?? false}
          supplierCode={supplier.code}
          supplierCurrency={supplier.currency_code}
          announce={announce}
          replaceIfCurrent={replaceIfCurrent}
          onParentStale={() => void onParentStale()}
        />
      ) : null}
      {deleting ? (
        <DeleteDialog
          copy={{
            title: DELETE_SUPPLIER.title(supplier.code, supplier.name),
            body: DELETE_SUPPLIER.body,
            confirm: DELETE_SUPPLIER.confirm,
            cancel: DELETE_SUPPLIER.cancel,
            close: DELETE_SUPPLIER.close,
            interrupted: DELETE_SUPPLIER.interrupted(supplier.code),
            forbidden: SUPPLIER_FORBIDDEN,
          }}
          run={remove}
          onDeleteStarted={() => announce('')}
          deleteCode={gateCode('DeleteSupplier')}
          onClose={(o) => void closeDelete(o)}
          fallbackFocusRef={h1}
        />
      ) : null}
    </section>
  );
}

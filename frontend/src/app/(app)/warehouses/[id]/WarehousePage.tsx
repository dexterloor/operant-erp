'use client';

import { useCallback, useMemo, useRef, useState } from 'react';
import { flushSync } from 'react-dom';
import { useRouter } from 'next/navigation';
import { RegionError } from '@/components/states/PageStates';
import { Button } from '@/components/ui/Button';
import { LiveRegion, useLiveRegion } from '@/components/ui/LiveRegion';
import { DeleteDialog } from '@/components/warehouses/DeleteDialog';
import { WarehouseForm } from '@/components/warehouses/WarehouseForm';
import { WarehouseRestoreActions } from '@/components/warehouses/WarehouseRestoreActions';
import styles from '@/components/warehouses/Warehouses.module.css';
import { COULD_NOT_REACH, WENT_WRONG } from '@/copy/headings';
import { DELETE_WAREHOUSE, WAREHOUSE_PAGE } from '@/copy/warehouses';
import type { Warehouse } from '@/lib/api/warehouses';
import { ACTION_DEADLINES_MS, startDeadline } from '@/lib/bff/deadline';
import { readOf, type ReadFailure } from '@/lib/bff/reads';
import { gateCode } from '@/lib/bff/surface';
import { loadWarehouse, type LocationsLoad } from '@/lib/warehouses/load';
import { warehouseDelete, warehouseRestore, warehouseSave } from '@/lib/warehouses/wired';
import { useApiCaller, useViewerTimeZone } from '../../BffProvider';
import { useArrivalMessage } from '../../arrivalMessage';
import { useCommittedState } from '../../useCommittedState';
import { WarehouseNotFound, WarehousesDenied } from '../WarehouseStates';
import { LocationsSection } from './LocationsSection';
import listStyles from '../../Lists.module.css';

export interface WarehousePageProps {
  /** GetWarehouse on first render. */
  initial: Warehouse;
  /** Holds UpdateWarehouse's codes (from the contract): ST-4c. Without it the fields are plain text. */
  canEdit: boolean;
  /** Holds RestoreWarehouse's codes (from the contract): ST-4c. Without it an archived warehouse shows its banner, no button. */
  canRestore: boolean;
  /** Holds DeleteWarehouse's codes (from the contract): **Delete warehouse** on a live warehouse (§2 step 4.6). */
  canDelete?: boolean;
  /**
   * The Locations section (§2 step 4.4): its first read and its action gates, or `null` when the viewer lacks
   * ListStorageLocations' codes (A-20: absent and not fetched).
   */
  locations?: { initial: LocationsLoad; canEdit: boolean; canRestore: boolean; canCreate: boolean; canDelete?: boolean } | null;
}

type Cause = 'unreachable' | 'server-error';

/**
 * The warehouse page (warehouses-ux-copy.md B-3; IA L-4: the detail page is the editor). It holds the warehouse in
 * state and passes the re-read one to the form without a remount. A failed re-read → the region error (focus on its
 * heading), Retry re-reads; 403 → ST-4b; 404 → ST-5. An archived warehouse is read-only whatever the viewer holds (the
 * API refuses its update, W3), under the persistent ST-9b banner *"This warehouse was archived on {date}."* with **Restore warehouse** for a
 * `RestoreWarehouse` holder (§2 step 4.2, `WarehouseRestoreActions`): a restore sets the re-read warehouse here, so the
 * page turns live without a remount. After a save's or restore's message is on screen the router cache is refreshed once,
 * so Back to the list shows the saved code and name, or the warehouse in the live list. Under the fields, the Locations
 * section (§2 step 4.4, `LocationsSection`) for a ListStorageLocations holder only; it is read-only while the warehouse is
 * archived and turns live with it. Its row-action messages are spoken in this page's region.
 */
export function WarehousePage({ initial, canEdit, canRestore, canDelete = false, locations = null }: WarehousePageProps) {
  const caller = useApiCaller();
  const router = useRouter();
  const timeZone = useViewerTimeZone();
  const region = useLiveRegion();
  const [warehouse, setCommitted] = useCommittedState(initial);
  const [swap, setSwap] = useState<'forbidden' | 'not-found' | null>(null);
  const [rereadError, setRereadError] = useState<{ gen: number; cause: Cause } | null>(null);
  const [retrying, setRetrying] = useState(false);
  const [deleting, setDeleting] = useState(false);
  // RED M-1: a 204 here is a fact that outranks a stale snapshot. If the re-read after it failed, the warehouse is still
  // treated as archived for the actions (no Delete warehouse, a read-only section) until a read says otherwise.
  const [deletedHere, setDeletedHere] = useState(false);
  // Every read the page commits is newer than that fact, so committing one clears it (a restore then turns it live).
  const setWarehouse = useCallback(
    async (w: Warehouse) => {
      setDeletedHere(false);
      await setCommitted(w);
    },
    [setCommitted],
  );
  const h1 = useRef<HTMLHeadingElement>(null);
  const id = initial.id;
  // After a create (§2 step 4.3): the create page left *"{code} was created."* for this address (A-19 item 7, S-5).
  useArrivalMessage(`/warehouses/${id}`, region.announce);

  const failed = useCallback((f: ReadFailure) => {
    if (f === 'session-ended') return;
    if (f === 'forbidden' || f === 'not-found') {
      setSwap(f);
      return;
    }
    // After the form's own after-save focus, so the region error's heading takes it.
    setTimeout(() => setRereadError((cur) => ({ gen: (cur?.gen ?? 0) + 1, cause: f })), 0);
  }, []);

  const save = useMemo(() => warehouseSave({ caller, id, setWarehouse, onRereadFailed: failed }), [caller, id, setWarehouse, failed]);
  const restore = useMemo(() => warehouseRestore({ caller, id, setWarehouse, onRereadFailed: failed }), [caller, id, setWarehouse, failed]);
  const remove = useMemo(() => warehouseDelete({ caller, id, setWarehouse, onRereadFailed: failed }), [caller, id, setWarehouse, failed]);
  const onSaved = useCallback(() => router.refresh(), [router]);

  // §2 step 4.6. After a 204 the page stays: the warehouse is archived, not gone, and `warehouseDelete` has already
  // committed the re-read (archived) warehouse, so the ST-9b banner and Restore warehouse are on screen. FE-D1: commit
  // the close, focus the h1 (the opener is gone with the live state), speak B-7's "After", refresh the router cache once
  // (Back to the list then shows it under Archived). Any other close after an answer re-reads the warehouse, so the page
  // under the dialog is the server's (an interrupted press may have committed; a W6 means it was deleted elsewhere).
  const closeDelete = async ({ deleted, reread }: { deleted: boolean; reread: boolean }) => {
    const code = warehouse.code;
    flushSync(() => setDeleting(false));
    if (deleted) {
      setDeletedHere(true);
      h1.current?.focus();
      region.announce(DELETE_WAREHOUSE.after(code));
      router.refresh();
      return;
    }
    if (!reread) return;
    const d = startDeadline(ACTION_DEADLINES_MS.reread);
    const r = await loadWarehouse(readOf(caller), id, d.signal);
    d.cancel();
    if (r.kind === 'ok') {
      await setWarehouse(r.warehouse);
      // RED H-1: the re-read archived the page (W6 deleted elsewhere, or an interrupted press that committed), which
      // unmounts the Delete warehouse button the dialog had returned focus to: focus the h1, as the 204 path does.
      if (r.warehouse.archived_at !== null) {
        h1.current?.focus();
        router.refresh();
      }
    } else failed(r.kind);
  };

  const retry = async () => {
    if (retrying) return;
    setRetrying(true);
    const d = startDeadline(ACTION_DEADLINES_MS.reread);
    const r = await loadWarehouse(readOf(caller), id, d.signal);
    d.cancel();
    setRetrying(false);
    if (r.kind === 'ok') {
      await setWarehouse(r.warehouse);
      setRereadError(null);
      h1.current?.focus();
    } else failed(r.kind);
  };

  if (swap === 'forbidden') return <WarehousesDenied />;
  if (swap === 'not-found') return <WarehouseNotFound />;

  const archived = warehouse.archived_at !== null;
  const actionsArchived = archived || deletedHere;
  return (
    <section className={listStyles.page}>
      <header className={listStyles.header}>
        <h1 ref={h1} tabIndex={-1}>
          {WAREHOUSE_PAGE.title(warehouse.code, warehouse.name)}
        </h1>
        {canDelete && !actionsArchived ? (
          <Button variant="destructive" onClick={() => setDeleting(true)}>
            {DELETE_WAREHOUSE.action}
          </Button>
        ) : null}
      </header>
      {/* Mounted from first render, above every branch below, so it never arrives already speaking. */}
      <LiveRegion message={region.message} visuallyHidden />
      {rereadError ? (
        <RegionError key={rereadError.gen} heading={rereadError.cause === 'unreachable' ? COULD_NOT_REACH : WENT_WRONG} body={WAREHOUSE_PAGE.rereadFailed} retrying={retrying} onRetry={() => void retry()} />
      ) : null}
      {warehouse.archived_at !== null ? (
        <WarehouseRestoreActions
          warehouse={{ ...warehouse, archived_at: warehouse.archived_at }}
          canRestore={canRestore}
          restoreWarehouse={restore}
          restoreCode={gateCode('RestoreWarehouse')}
          timeZone={timeZone}
          announce={region.announce}
          fallbackFocusRef={h1}
          onRestored={onSaved}
        />
      ) : null}
      {canEdit && !actionsArchived ? (
        <WarehouseForm warehouse={warehouse} save={save} editCode={gateCode('UpdateWarehouse')} announce={region.announce} fallbackFocusRef={h1} onSaved={onSaved} />
      ) : (
        <dl className={styles.fields}>
          <dt>{WAREHOUSE_PAGE.code}</dt>
          <dd>{warehouse.code}</dd>
          <dt>{WAREHOUSE_PAGE.name}</dt>
          <dd>{warehouse.name}</dd>
          <dt>{WAREHOUSE_PAGE.country}</dt>
          <dd>{warehouse.country_code}</dd>
        </dl>
      )}
      {locations !== null ? (
        <div className={styles.locations}>
          <LocationsSection warehouseId={id} warehouseCode={warehouse.code} initial={locations.initial} warehouseArchived={actionsArchived} canEdit={locations.canEdit} canRestore={locations.canRestore} canCreate={locations.canCreate} canDelete={locations.canDelete ?? false} announce={region.announce} />
        </div>
      ) : null}
      {deleting ? (
        <DeleteDialog
          copy={{ title: DELETE_WAREHOUSE.title(warehouse.code, warehouse.name), body: DELETE_WAREHOUSE.body, confirm: DELETE_WAREHOUSE.confirm, cancel: DELETE_WAREHOUSE.cancel, close: DELETE_WAREHOUSE.close, interrupted: DELETE_WAREHOUSE.interrupted(warehouse.code) }}
          run={remove}
          deleteCode={gateCode('DeleteWarehouse')}
          onClose={(o) => void closeDelete(o)}
          fallbackFocusRef={h1}
        />
      ) : null}
    </section>
  );
}

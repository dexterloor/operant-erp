'use client';

import { useCallback, useLayoutEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { flushSync } from 'react-dom';
import { ArchiveRestore } from 'lucide-react';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { AddLocationForm } from '@/components/warehouses/AddLocationForm';
import { DeleteDialog } from '@/components/warehouses/DeleteDialog';
import { LocationForm } from '@/components/warehouses/LocationForm';
import { COULD_NOT_REACH, NOT_RESTORED, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { PAGE_STATES } from '@/copy/shell';
import { ADD_LOCATION, DELETE_LOCATION, EDIT_LOCATION, kindLabel, LOCATIONS_SECTION, RESTORE_LOCATION, WAREHOUSE_FORBIDDEN } from '@/copy/warehouses';
import type { StorageLocation } from '@/lib/api/warehouses';
import { ACTION_DEADLINES_MS, startDeadline } from '@/lib/bff/deadline';
import { readOf, type Read } from '@/lib/bff/reads';
import { gateCode, wireEnum } from '@/lib/bff/surface';
import { loadLocations, type LocationsLoad } from '@/lib/warehouses/load';
import type { RestoreLocationResult } from '@/lib/warehouses/results';
import { locationCreate, locationDelete, locationRestore, locationSave } from '@/lib/warehouses/wired';
import { useApiCaller } from '../../BffProvider';
import { ArchivableList, ArchivedToggle, type ArchivableListHandle, type Column, type EmptyState, type HeaderView, type ListLoad } from '../../ArchivableList';

const HEADING_ID = 'locations-heading';
/** B-5: ordered as served (by code), no caption; archived rows struck through (ST-9b). */
const ARCHIVED_VIEW = { order: 'as-served', rows: 'struck' } as const;
const COPY = { heading: null, archived: LOCATIONS_SECTION.archived, loadFailed: LOCATIONS_SECTION.loadFailed, shown: LOCATIONS_SECTION.shown };

export const asLocationList = (r: LocationsLoad): ListLoad<StorageLocation> => (r.kind === 'ok' ? { kind: 'ok', items: r.locations } : r);

/** The served kinds the dialogs offer, in served order (A-21; read through the one contract reader, G-2). */
const CREATE_INPUT = 'StorageLocation.CreateStorageLocationInput';
const UPDATE_INPUT = 'StorageLocation.UpdateStorageLocationInput.jsonMergePatch';

/**
 * B-5 empty states. Live: the heading **+ Add location** when `add` is given (the viewer holds `CreateStorageLocation`'s
 * codes and the warehouse is live); §2 step 4.5 ends 4.4's heading-alone interim. Archived: the heading alone.
 */
export const locationsEmpty =
  (add: ReactNode | null): EmptyState =>
  ({ archived }) =>
    archived ? { heading: LOCATIONS_SECTION.noneArchived } : add !== null ? { heading: LOCATIONS_SECTION.noneLive, actions: add } : { heading: LOCATIONS_SECTION.noneLive };

export interface LocationsSectionProps {
  warehouseId: string;
  /** The warehouse's code, for Add location's title. */
  warehouseCode: string;
  /** `ListStorageLocations` on first render (the page issues it only for a viewer it gates, A-20). */
  initial: LocationsLoad;
  /** The warehouse is archived: read-only, no row actions, ST-9b's notes above the rows. */
  warehouseArchived: boolean;
  /** Holds `UpdateStorageLocation`'s codes: the Edit row action (code only, step 4.4). */
  canEdit: boolean;
  /** Holds `RestoreStorageLocation`'s codes: Restore location on an archived row. */
  canRestore: boolean;
  /** Holds `CreateStorageLocation`'s codes: Add location in the header and the live empty state (§2 step 4.5). */
  canCreate: boolean;
  /** Holds `DeleteStorageLocation`'s codes: **Delete** on a live row of a live warehouse (§2 step 4.6). */
  canDelete?: boolean;
  /** Writes the page's status region (row-action messages; the section's own region speaks only its switches). */
  announce: (text: string) => void;
}

interface Notice {
  id: number;
  heading: string;
  body: ReactNode;
}

/**
 * The Locations section of the warehouse page (warehouses-ux-copy.md B-5, ST-9b; Revision 7 §2 step 4.4, A-17 section use,
 * A-20, A-21). No search, never in the address. Columns Code · Kind (the label through `KIND_LABELS`, or the value as
 * sent; nothing is decided from a kind). Live rows offer **Edit** (code only); Archived rows offer **Restore location**;
 * an archived warehouse's section offers neither and carries ST-9b's note. **Add location** (§2 step 4.5) is in the live
 * view's header and its empty state, for a `CreateStorageLocation` holder on a live warehouse only. After a dialog or a
 * row action: close → focus → announce → re-read the view (FE-D1). **Delete** (§2 step 4.6) is a live row's action
 * for a `DeleteStorageLocation` holder on a live warehouse; after it the row is in the Archived view, where Restore
 * location brings it back.
 */
export function LocationsSection({ warehouseId, warehouseCode, initial, warehouseArchived, canEdit, canRestore, canCreate, canDelete = false, announce }: LocationsSectionProps) {
  const caller = useApiCaller();
  const list = useRef<ArchivableListHandle>(null);
  const heading = useRef<HTMLHeadingElement>(null);
  const noticeHeading = useRef<HTMLParagraphElement>(null);
  const [editing, setEditing] = useState<StorageLocation | null>(null);
  const [adding, setAdding] = useState(false);
  const [deleting, setDeleting] = useState<StorageLocation | null>(null);
  const [restoring, setRestoring] = useState<string | null>(null);
  const [notice, setNotice] = useState<Notice | null>(null);
  const save = useMemo(() => locationSave(caller, warehouseId), [caller, warehouseId]);
  const restore = useMemo(() => locationRestore(caller, warehouseId), [caller, warehouseId]);
  const create = useMemo(() => locationCreate(caller, warehouseId), [caller, warehouseId]);
  const remove = useMemo(() => locationDelete(caller, warehouseId), [caller, warehouseId]);
  const mayAdd = canCreate && !warehouseArchived;
  const load = useCallback(async (read: Read, archived: boolean, signal: AbortSignal) => asLocationList(await loadLocations(read, warehouseId, archived, signal)), [warehouseId]);

  const noticeId = notice?.id ?? null;
  useLayoutEffect(() => {
    if (noticeId !== null) noticeHeading.current?.focus();
  }, [noticeId]);

  const failureNotice = (r: Exclude<RestoreLocationResult, { kind: 'restored' }>, code: string, id: number): Notice | null => {
    switch (r.kind) {
      case 'refused':
        return { id, heading: NOT_RESTORED, body: r.message !== '' ? r.message : REFUSED_WITHOUT_MESSAGE };
      case 'not-found':
        return { id, heading: NOT_RESTORED, body: PAGE_STATES.notFoundInline };
      case 'forbidden': {
        const c = gateCode('RestoreStorageLocation');
        const label = (c !== null ? PERMISSION_LABELS[c]?.label : undefined) ?? '';
        return {
          id,
          heading: NOT_RESTORED,
          body: (
            <>
              {WAREHOUSE_FORBIDDEN.before}
              <em>{label}</em>
              {WAREHOUSE_FORBIDDEN.after}
            </>
          ),
        };
      }
      case 'unreachable':
        return { id, heading: COULD_NOT_REACH, body: RESTORE_LOCATION.interrupted(code) };
      case 'server-error':
        return { id, heading: WENT_WRONG, body: RESTORE_LOCATION.interrupted(code) };
      case 'session-ended':
        return null;
    }
  };

  const runRestore = async (l: StorageLocation) => {
    if (restoring !== null) return;
    setNotice(null);
    setRestoring(l.id);
    let r: RestoreLocationResult;
    try {
      r = await restore(l.id);
    } catch {
      r = { kind: 'unreachable' };
    }
    setRestoring(null);
    if (r.kind === 'restored') {
      // The row leaves the Archived view, and its button with it: focus the section heading, speak, then re-read.
      heading.current?.focus();
      announce(RESTORE_LOCATION.after(r.location.code));
      await list.current?.reread();
      return;
    }
    if (r.kind === 'session-ended') return;
    if (r.kind === 'refused') {
      // RED L-5 (the step 4.2 H-1 precedent, W4 ↔ L11): a refusal after which the Archived view no longer holds this row
      // was restored already (another tab or person, or an interrupted press that committed). Treat it as restored and
      // speak the server's sentence as sent. No refusal code is compared.
      const d = startDeadline(ACTION_DEADLINES_MS.reread);
      const again = await loadLocations(readOf(caller), warehouseId, true, d.signal);
      d.cancel();
      if (again.kind === 'ok' && !again.locations.some((x) => x.id === l.id)) {
        heading.current?.focus();
        announce(r.message !== '' ? r.message : RESTORE_LOCATION.after(l.code));
        await list.current?.reread();
        return;
      }
    }
    // Any other answer: re-read first, so the rows under the notice are the server's (an interrupted press may have committed).
    await list.current?.reread();
    const next = failureNotice(r, l.code, (notice?.id ?? 0) + 1);
    if (next) setNotice(next);
  };

  const closeEdit = async ({ saved, reread }: { saved: StorageLocation | null; reread: boolean }) => {
    // FE-D1: commit the close, let the dialog return focus to the row's Edit button (else the heading), speak, refresh.
    flushSync(() => setEditing(null));
    if (saved !== null) announce(EDIT_LOCATION.saved(saved.code));
    if (reread) await list.current?.reread();
  };

  const closeAdd = async ({ created, reread }: { created: StorageLocation | null; reread: boolean }) => {
    // FE-D1: commit the close, let the dialog return focus to its opener (else the heading), speak, refresh.
    flushSync(() => setAdding(false));
    if (created !== null) announce(ADD_LOCATION.after(created.code));
    if (reread) await list.current?.reread();
  };

  const closeDelete = async (l: StorageLocation, { deleted, reread }: { deleted: boolean; reread: boolean }) => {
    // FE-D1: commit the close. On a 204 the row (and its Delete button) leaves the live view, so focus the section
    // heading, speak B-7's "After", then re-read. Any other close after an answer re-reads too (the delete may have
    // committed, or the row was archived elsewhere: L7), with focus returned to the opener by the dialog.
    flushSync(() => setDeleting(null));
    if (deleted) {
      setNotice(null);
      heading.current?.focus();
      announce(DELETE_LOCATION.after(l.code));
    }
    if (reread) await list.current?.reread();
  };

  const addButton = (size?: 'sm') => (
    <Button size={size} onClick={() => setAdding(true)}>
      {LOCATIONS_SECTION.addLocation}
    </Button>
  );

  const header = ({ archived, busy, loaded, switchTo, viewControlRef }: HeaderView) => (
    <>
      <h2 id={HEADING_ID} ref={heading} tabIndex={-1}>
        {LOCATIONS_SECTION.heading(archived)}
      </h2>
      {archived ? (
        loaded ? (
          <Button ref={viewControlRef} loading={busy} onClick={() => switchTo(false)}>
            {LOCATIONS_SECTION.backToList}
          </Button>
        ) : null
      ) : (
        <>
          {mayAdd ? addButton() : null}
          <ArchivedToggle ref={viewControlRef} archived={false} busy={busy} onClick={() => switchTo(true)} label={LOCATIONS_SECTION.archived} />
        </>
      )}
    </>
  );

  const columns = (archived: boolean): Column<StorageLocation>[] => {
    const base: Column<StorageLocation>[] = [
      { header: LOCATIONS_SECTION.code, cell: (l) => l.code },
      { header: LOCATIONS_SECTION.kind, cell: (l) => kindLabel(l.kind) },
    ];
    if (warehouseArchived) return base;
    if (!archived && (canEdit || canDelete)) {
      return [
        ...base,
        {
          header: LOCATIONS_SECTION.actions,
          cell: (l) => (
            <>
              {canEdit ? (
                <Button size="sm" aria-label={`${LOCATIONS_SECTION.edit} ${l.code}`} onClick={() => setEditing(l)}>
                  {LOCATIONS_SECTION.edit}
                </Button>
              ) : null}
              {canDelete ? (
                <Button size="sm" variant="destructive" aria-label={`${DELETE_LOCATION.action} ${l.code}`} onClick={() => setDeleting(l)}>
                  {DELETE_LOCATION.action}
                </Button>
              ) : null}
            </>
          ),
        },
      ];
    }
    if (archived && canRestore) {
      return [
        ...base,
        {
          header: LOCATIONS_SECTION.actions,
          cell: (l) => (
            <Button size="sm" icon={<ArchiveRestore aria-hidden="true" width={16} height={16} strokeWidth={1.5} />} aria-label={`${RESTORE_LOCATION.action} ${l.code}`} loading={restoring === l.id} unavailableReason={restoring !== null && restoring !== l.id ? RESTORE_LOCATION.busyOther : undefined} onClick={() => void runRestore(l)}>
              {RESTORE_LOCATION.action}
            </Button>
          ),
        },
      ];
    }
    return base;
  };

  const noticeFor = (archived: boolean): ReactNode => (
    <>
      {warehouseArchived ? <p>{archived ? LOCATIONS_SECTION.archivedParentArchived : LOCATIONS_SECTION.archivedParentLive}</p> : null}
      {notice ? (
        <Banner key={notice.id} tone="danger" live="none" heading={notice.heading} ref={noticeHeading}>
          <p>{notice.body}</p>
        </Banner>
      ) : null}
    </>
  );

  return (
    <>
      <ArchivableList<StorageLocation>
        initial={asLocationList(initial)}
        load={load}
        columns={columns}
        copy={COPY}
        headingId={HEADING_ID}
        search={false}
        header={header}
        empty={locationsEmpty(mayAdd ? addButton('sm') : null)}
        archivedView={ARCHIVED_VIEW}
        notice={noticeFor}
        controller={list}
        denied={<p>{LOCATIONS_SECTION.denied}</p>}
      />
      {adding && mayAdd ? <AddLocationForm warehouseCode={warehouseCode} kinds={wireEnum(CREATE_INPUT, 'kind')} create={create} createCode={gateCode('CreateStorageLocation')} onClose={(o) => void closeAdd(o)} fallbackFocusRef={heading} /> : null}
      {deleting !== null && canDelete && !warehouseArchived ? (
        <DeleteDialog
          key={deleting.id}
          copy={{ title: DELETE_LOCATION.title(deleting.code), body: DELETE_LOCATION.body, confirm: DELETE_LOCATION.confirm, cancel: DELETE_LOCATION.cancel, close: DELETE_LOCATION.close, interrupted: DELETE_LOCATION.interrupted(deleting.code) }}
          run={() => remove(deleting.id)}
          deleteCode={gateCode('DeleteStorageLocation')}
          onClose={(o) => void closeDelete(deleting, o)}
          fallbackFocusRef={heading}
        />
      ) : null}
      {editing !== null && canEdit && !warehouseArchived ? <LocationForm key={editing.id} location={editing} kinds={wireEnum(UPDATE_INPUT, 'kind')} save={save} editCode={gateCode('UpdateStorageLocation')} onClose={(o) => void closeEdit(o)} fallbackFocusRef={heading} /> : null}
    </>
  );
}

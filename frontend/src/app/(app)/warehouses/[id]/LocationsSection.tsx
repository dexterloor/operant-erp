'use client';

import { useCallback, useLayoutEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { flushSync } from 'react-dom';
import { ArchiveRestore } from 'lucide-react';
import { DeleteDialog } from '@/components/records/DeleteDialog';
import { Banner } from '@/components/ui/Banner';
import { RefetchFailedBanner } from '@/components/states/PageStates';
import { Button } from '@/components/ui/Button';
import { AddLocationForm } from '@/components/warehouses/AddLocationForm';
import { LocationForm } from '@/components/warehouses/LocationForm';
import { COULD_NOT_REACH, NOT_RESTORED, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { LIST_STATES, PAGE_STATES } from '@/copy/shell';
import { ADD_LOCATION, DELETE_LOCATION, EDIT_LOCATION, kindLabel, LOCATIONS_SECTION, RESTORE_LOCATION, WAREHOUSE_FORBIDDEN } from '@/copy/warehouses';
import type { StorageLocation } from '@/lib/api/warehouses';
import { ACTION_DEADLINES_MS, startDeadline } from '@/lib/bff/deadline';
import { readOf, type Read, type ReadFailure } from '@/lib/bff/reads';
import { gateCode, wireEnum } from '@/lib/bff/surface';
import { loadLocations, type LocationsLoad } from '@/lib/warehouses/load';
import type { RestoreLocationResult } from '@/lib/warehouses/results';
import { locationCreate, locationDelete, locationRestore, locationSave } from '@/lib/warehouses/wired';
import { useApiCaller } from '../../BffProvider';
import { ArchivableList, ArchivedToggle, focusWasLost, refetchHeading, type ArchivableListHandle, type Column, type EmptyState, type HeaderView, type ListLoad, type RereadResult } from '../../ArchivableList';

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

/** Inert controls for the empty-state call that only reads its heading (the section's own; a no-op is not a list-core concept). */
const NOOP = () => {};

/** A failed read after which the rows on screen are the earlier ones (a 403 replaces the list; an ended session leaves). */
type StaleCause = Extract<ReadFailure, 'unreachable' | 'server-error' | 'not-found'>;
const isStale = (kind: 'ok' | ReadFailure): kind is StaleCause => kind === 'unreachable' || kind === 'server-error' || kind === 'not-found';

/**
 * The section's re-read banner (locations-stale-banner-design.md Revision 1, F-2): which read failed (`seq`), why, over
 * which view, and the outcome sentence of the write that owns it (`null`: the generic body).
 */
interface Stale {
  seq: number;
  cause: StaleCause;
  archived: boolean;
  body: string | null;
}

/** The view answer a re-read last committed: which view was read, and its rows. */
interface Answer {
  archived: boolean;
  items: readonly StorageLocation[];
}

/**
 * FE-R8 location half (fe-r8-s3-ux.md "Revision 1 — FINAL"; first RED H-2; s3-fer8-final-red.md L-7, L-9): what replaces
 * `DELETE_LOCATION.after` once the delete's view re-read has resolved, or `null` to leave the region as it is. "Restored"
 * is said when the re-read shows the row is live: the live view holds its id (named by the code on screen now), or the
 * whole Archived view does not (the mirror arm, named by the code deleted: a rename in between is not seen). The mirror arm
 * rests on nothing hard-deleting a location; revisit it at the first DELETE grant on `storage_location`, or the first
 * purge of archived locations. A failed re-read adds the stale sentence; a 403 (the list shows its denied state) and an
 * ended session leave the region.
 */
function correctionAfterDelete(l: StorageLocation, r: RereadResult, answer: Answer | null): string | null {
  switch (r.kind) {
    case 'ok': {
      if (answer === null) return null; // unreachable after an ok re-read; kept for the type
      const row = answer.items.find((x) => x.id === l.id);
      if (!answer.archived) return row !== undefined ? DELETE_LOCATION.afterRestored(row.code) : null;
      return row === undefined ? DELETE_LOCATION.afterRestored(l.code) : null;
    }
    case 'unreachable':
    case 'server-error':
    case 'not-found':
      return DELETE_LOCATION.afterListStale(l.code);
    case 'forbidden':
    case 'session-ended':
      return null;
  }
}

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
  /**
   * Writes the page's status region (row-action messages; the section's own region speaks only its switches). Returns the
   * write's number: the page counts every write to its region, whoever makes it (s3-fer8-final-red.md F-3).
   */
  announce: (text: string) => number;
  /** Writes `text` only while write number `gen` is still the region's last (FE-R8: a late correction never overwrites a later sentence). */
  replaceIfCurrent: (gen: number, text: string) => void;
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
 * row action: close → focus → announce → re-read the view (FE-D1). An Edit location, Add location or Delete location dialog
 * that is open when the page turns archived stays open with what was typed: its Save, Add or Delete then meets the archived
 * refusal and says so, and it never comes back by itself after Restore warehouse (the supplier catalogue's S-4 RED M-1,
 * found here too; S-4 RED-2 M-1, L-2). Each renders on its own open state alone: no button that opens it renders on an
 * archived warehouse or without its operation's codes, so that state is never set without them. **Delete** (§2 step 4.6) is a live row's action
 * for a `DeleteStorageLocation` holder on a live warehouse; after it the row is in the Archived view, where Restore
 * location brings it back.
 *
 * The stale banner (locations-stale-banner-design.md Revision 1 as corrected by its final RED and ARCH, option (a′)). A
 * view re-read that fails (unreachable, a server error, not found, the read deadline) over the view on screen leaves the
 * earlier rows there, so the section shows a warning banner above them: R1-1's heading by cause, a Retry, and as body
 * the outcome of the write that owns the read (`DELETE_LOCATION.afterListStale`, `EDIT_LOCATION.savedListStale`,
 * `ADD_LOCATION.afterListStale`, `RESTORE_LOCATION.afterListStale`, also spoken in place of the write's sentence), else
 * `LIST_STATES.refetchFailed` (a refused or interrupted dialog's Close: shown, not spoken, FE-R8 R-2). Only the `load`
 * wrapper sets it or clears it, decided by reads in queue order. Two other writes exist: the owning write's body attach,
 * gated on the read's number, and Retry's own clear on `ok`. That clear repeats the wrapper's so the banner has unmounted
 * before focus is checked. While the list's own refetch banner is up (`listBannerUp`, a failed switch) this banner is
 * hidden: the list's banner owns the screen. The banner is never keyed, so it stays one node and its Retry keeps focus.
 * With nothing on screen (a failed first load), `reread()` resolves `server-error` without reading: a write still speaks
 * its stale sentence, and no banner shows (the first-load region error is up; final RED L-11, accepted).
 */
export function LocationsSection({ warehouseId, warehouseCode, initial, warehouseArchived, canEdit, canRestore, canCreate, canDelete = false, announce, replaceIfCurrent }: LocationsSectionProps) {
  const caller = useApiCaller();
  const list = useRef<ArchivableListHandle>(null);
  const heading = useRef<HTMLHeadingElement>(null);
  const noticeHeading = useRef<HTMLParagraphElement>(null);
  const [editing, setEditing] = useState<StorageLocation | null>(null);
  // The button that opened the Edit or Add dialog, so the close can put focus back on it before anything is spoken (FE-D1).
  // The dialog's own focus return runs on a timer after it unmounts, which is later than the sentence (S-4 RED-2 M-2).
  const opener = useRef<HTMLElement | null>(null);
  const focusOpener = () => {
    const back = opener.current;
    opener.current = null;
    (back !== null && back.isConnected ? back : heading.current)?.focus();
  };
  const [adding, setAdding] = useState(false);
  const [deleting, setDeleting] = useState<StorageLocation | null>(null);
  const [restoring, setRestoring] = useState<string | null>(null);
  const [notice, setNotice] = useState<Notice | null>(null);
  const save = useMemo(() => locationSave(caller, warehouseId), [caller, warehouseId]);
  const restore = useMemo(() => locationRestore(caller, warehouseId), [caller, warehouseId]);
  const create = useMemo(() => locationCreate(caller, warehouseId), [caller, warehouseId]);
  const remove = useMemo(() => locationDelete(caller, warehouseId), [caller, warehouseId]);
  const mayAdd = canCreate && !warehouseArchived;
  // FE-R8 location half (first RED M-5 option (i), no change to the list core): every answered view is recorded here, so
  // the delete can read what its own re-read answered. `reread()` resolves once its read has committed, and the list's
  // queue starts no later read before the awaiting caller resumes, so after an `ok` this holds that re-read's answer.
  // This holds only while `reread()` returns `run`'s promise unwrapped and nothing is awaited between it and
  // `correctionAfterDelete`; the queued-switch test pins it.
  const lastAnswer = useRef<Answer | null>(null);
  const [stale, setStale] = useState<Stale | null>(null);
  const [retrying, setRetrying] = useState(false);
  // The view whose rows are on screen, as this section has seen reads land (`null`: nothing loaded). The section never
  // passes `initialArchived` (it always opens on the live view), so it starts `false` after a good first read; if it ever
  // does, `onScreen` must start at that view. Kept inline beside `lastAnswer`, like it, not as a `lib/` reducer: a reducer
  // would add a file, and so an architecture Revision (locations-stale-banner-po-arch.md item 7).
  const onScreen = useRef<boolean | null>(initial.kind === 'ok' ? false : null);
  /** Every read through `load` is numbered, so a write's body attach can tell its own read from a later one. */
  const readSeq = useRef(0);
  const load = useCallback(
    async (read: Read, archived: boolean, signal: AbortSignal) => {
      const seq = ++readSeq.current;
      // A read of the other view (a switch, the list banner's Retry) or with nothing on screen: the list owns its outcome.
      if (archived !== onScreen.current) setStale(null);
      const r = asLocationList(await loadLocations(read, warehouseId, archived, signal));
      if (r.kind === 'ok') {
        lastAnswer.current = { archived, items: r.items };
        onScreen.current = archived;
        setStale(null);
      } else if (isStale(r.kind)) {
        // A failed read of the view on screen: the rows are the earlier ones. A banner already up over this view keeps its
        // body (the rows are still the same earlier rows, and the outcome is still true).
        const cause = r.kind;
        if (archived === onScreen.current) setStale((cur) => ({ seq, cause, archived, body: cur?.archived === archived ? cur.body : null }));
      } else if (r.kind === 'forbidden') setStale(null);
      return r;
    },
    [warehouseId],
  );
  /**
   * The write that owns a failed re-read gives the banner its outcome sentence. Call it straight after that write's own
   * `reread()` resolves, with no `await` between: the list's queue starts no later read before the awaiting caller
   * resumes (the property `lastAnswer` relies on), so `readSeq` still names that read, and a later read's banner is left alone.
   */
  const attachBody = (body: string) => {
    const seq = readSeq.current;
    setStale((cur) => (cur?.seq === seq ? { ...cur, body } : cur));
  };
  /** A write's re-read failed: show its stale sentence in the banner and speak it in place of the write's (F-3). */
  const staleAfter = (said: number, sentence: string) => {
    attachBody(sentence);
    replaceIfCurrent(said, sentence);
  };
  /** The settled sentence for an answer (the same `COPY` and empty states the section hands the list). */
  const settledSentence = (a: Answer) =>
    a.items.length > 0 ? COPY.shown(a.items.length, '', a.archived) : (locationsEmpty(null)({ archived: a.archived, q: '', tenantHasRows: undefined, busy: false, switchTo: NOOP, clearSearch: NOOP })?.heading ?? '');

  /**
   * The banner's Retry (F-5): re-read the view on screen. The press blanks the page's region (counted), so a repeat
   * heading is a text change. On success the banner unmounts, focus goes to the section heading only if it was lost,
   * then the settled sentence is spoken, only for the view the press was for (a switch in between spoke its own). On a
   * failure the banner stays one node, focus stays on Retry, and the heading is spoken, again only for that view; a 403 (the denied state) and an
   * ended session say nothing more. A write that speaks meanwhile keeps its sentence. A Retry pressed while a write's
   * re-read is in flight supersedes that write's pending correction (FE-R8 (2)).
   */
  const retryStale = async () => {
    if (retrying) return;
    const forView = stale?.archived;
    const gen = announce('');
    setRetrying(true);
    const r = await list.current?.reread();
    setRetrying(false);
    if (r === undefined) return;
    if (r.kind === 'ok') {
      // So the banner holding the focused Retry has unmounted before focus is checked: the list's flushSync of its rows
      // does not promise the caller's state (RED M-5).
      flushSync(() => setStale(null));
      if (focusWasLost()) heading.current?.focus();
      const a = lastAnswer.current;
      if (a === null || a.archived !== forView) return;
      replaceIfCurrent(gen, settledSentence(a));
      return;
    }
    if (!isStale(r.kind)) return;
    // Spoken only while the view on screen is still the one the press was for: a switch queued in between re-pointed the
    // re-read at the switched view, and the switch spoke (L-10's rule on both paths; commit RED L-4 (a)).
    if (onScreen.current === forView) replaceIfCurrent(gen, refetchHeading(r.kind));
    // Only when the banner unmounted during the Retry (a write's re-read cleared it first) and came back as a new node.
    if (focusWasLost()) heading.current?.focus();
  };

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
    // FE-R9: after the guard, before the request, through the page's counted writer (never around it). The row's
    // sentence is then a change even when it repeats the last one word for word (a row archived again elsewhere, seen
    // through a view switch, which writes only to the list's own status line), so it is given again.
    announce('');
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
      const code = r.location.code;
      const said = announce(RESTORE_LOCATION.after(code));
      const again = await list.current?.reread();
      if (again !== undefined && isStale(again.kind)) staleAfter(said, RESTORE_LOCATION.afterListStale(code));
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
        const said = announce(r.message !== '' ? r.message : RESTORE_LOCATION.after(l.code));
        const reread = await list.current?.reread();
        // UX: our true sentence replaces the server's here, because components never join copy.
        if (reread !== undefined && isStale(reread.kind)) staleAfter(said, RESTORE_LOCATION.afterListStale(l.code));
        return;
      }
    }
    // Any other answer: re-read first, so the rows under the notice are the server's (an interrupted press may have committed).
    await list.current?.reread();
    const next = failureNotice(r, l.code, (notice?.id ?? 0) + 1);
    if (next) setNotice(next);
  };

  const closeEdit = async ({ saved, reread }: { saved: StorageLocation | null; reread: boolean }) => {
    // FE-D1: commit the close, put focus back on the opener (else the heading), then speak, then re-read.
    flushSync(() => setEditing(null));
    focusOpener();
    const said = saved !== null ? announce(EDIT_LOCATION.saved(saved.code)) : null;
    if (!reread) return;
    const r = await list.current?.reread();
    if (saved === null || said === null || r === undefined) return;
    if (isStale(r.kind)) staleAfter(said, EDIT_LOCATION.savedListStale(saved.code));
  };

  const closeAdd = async ({ created, reread }: { created: StorageLocation | null; reread: boolean }) => {
    // FE-D1: commit the close, put focus back on the opener (else the heading), then speak, then re-read.
    flushSync(() => setAdding(false));
    focusOpener();
    const said = created !== null ? announce(ADD_LOCATION.after(created.code)) : null;
    if (!reread) return;
    const r = await list.current?.reread();
    if (created === null || said === null || r === undefined) return;
    if (isStale(r.kind)) staleAfter(said, ADD_LOCATION.afterListStale(created.code));
  };

  const closeDelete = async (l: StorageLocation, { deleted, reread }: { deleted: boolean; reread: boolean }) => {
    // FE-D1: commit the close. On a 204 the row (and its Delete button) leaves the live view, so focus the section
    // heading, speak B-7's "After", then re-read. Any other close after an answer re-reads too (the delete may have
    // committed, or the row was archived elsewhere: L7), with focus returned to the opener by the dialog.
    // FE-R8 location half (ruling (a)): the 204's "After" is spoken at once, as shipped; when the re-read resolves it is
    // replaced only by `correctionAfterDelete`'s sentence, and only if nothing has written the page's region since.
    flushSync(() => setDeleting(null));
    let said: number | null = null;
    if (deleted) {
      setNotice(null);
      heading.current?.focus();
      said = announce(DELETE_LOCATION.after(l.code));
    }
    if (!reread) return;
    const r = await list.current?.reread();
    if (said === null || r === undefined) return;
    if (isStale(r.kind)) attachBody(DELETE_LOCATION.afterListStale(l.code));
    const correction = correctionAfterDelete(l, r, lastAnswer.current);
    if (correction !== null) replaceIfCurrent(said, correction);
  };

  const addButton = (size?: 'sm') => (
    <Button
      size={size}
      onClick={(ev) => {
        opener.current = ev.currentTarget;
        setAdding(true);
      }}
    >
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
                <Button
                  size="sm"
                  aria-label={`${LOCATIONS_SECTION.edit} ${l.code}`}
                  onClick={(ev) => {
                    opener.current = ev.currentTarget;
                    setEditing(l);
                  }}
                >
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

  const noticeFor = (archived: boolean, listBannerUp: boolean): ReactNode => (
    <>
      {warehouseArchived ? <p>{archived ? LOCATIONS_SECTION.archivedParentArchived : LOCATIONS_SECTION.archivedParentLive}</p> : null}
      {/* No `key`: one node, so a failed Retry keeps focus on it (F-4). Hidden while the list's own banner is up (H-1 (a′)). */}
      {stale !== null && !listBannerUp && stale.archived === archived ? <RefetchFailedBanner heading={refetchHeading(stale.cause)} body={stale.body ?? LIST_STATES.refetchFailed} retrying={retrying} onRetry={() => void retryStale()} /> : null}
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
      {adding ? <AddLocationForm warehouseCode={warehouseCode} kinds={wireEnum(CREATE_INPUT, 'kind')} create={create} createCode={gateCode('CreateStorageLocation')} onClose={(o) => void closeAdd(o)} fallbackFocusRef={heading} /> : null}
      {deleting !== null ? (
        <DeleteDialog
          key={deleting.id}
          copy={{ title: DELETE_LOCATION.title(deleting.code), body: DELETE_LOCATION.body, confirm: DELETE_LOCATION.confirm, cancel: DELETE_LOCATION.cancel, close: DELETE_LOCATION.close, interrupted: DELETE_LOCATION.interrupted(deleting.code), forbidden: WAREHOUSE_FORBIDDEN }}
          run={() => remove(deleting.id)}
          deleteCode={gateCode('DeleteStorageLocation')}
          onClose={(o) => void closeDelete(deleting, o)}
          fallbackFocusRef={heading}
          onDeleteStarted={() => announce('')}
        />
      ) : null}
      {editing !== null ? <LocationForm key={editing.id} location={editing} kinds={wireEnum(UPDATE_INPUT, 'kind')} save={save} editCode={gateCode('UpdateStorageLocation')} onClose={(o) => void closeEdit(o)} fallbackFocusRef={heading} onSaveStarted={() => announce('')} /> : null}
    </>
  );
}

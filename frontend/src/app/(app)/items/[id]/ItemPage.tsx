'use client';

import { useCallback, useLayoutEffect, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import { ItemForm } from '@/components/items/ItemForm';
import styles from '@/components/items/Items.module.css';
import { RestoreActions } from '@/components/records/RestoreActions';
import { RegionError } from '@/components/states/PageStates';
import { Banner } from '@/components/ui/Banner';
import { LiveRegion, useLiveRegion } from '@/components/ui/LiveRegion';
import { COULD_NOT_REACH, NOT_SAVED, WENT_WRONG } from '@/copy/headings';
import { ITEM_FORBIDDEN, ITEM_PAGE, ITEMS_LIST, RESTORE_ITEM } from '@/copy/items';
import type { Item } from '@/lib/api/items';
import { ACTION_DEADLINES_MS, startDeadline } from '@/lib/bff/deadline';
import { readOf, type ReadFailure } from '@/lib/bff/reads';
import { gateCode } from '@/lib/bff/surface';
import { renderQuantity } from '@/lib/format/quantity';
import { loadItem } from '@/lib/items/load';
import type { ItemChange } from '@/lib/items/results';
import { itemRestore, itemSave } from '@/lib/items/wired';
import { useApiCaller, useViewerTimeZone } from '../../BffProvider';
import { useCommittedState } from '../../useCommittedState';
import { ItemNotFound, ItemsDenied } from '../ItemStates';
import listStyles from '../../Lists.module.css';

export interface ItemPageProps {
  /** GetItem on first render. */
  initial: Item;
  /** Holds UpdateItem's codes (from the contract): ST-4c. Without it the values are plain text. */
  canEdit: boolean;
  /** Holds RestoreItem's codes (from the contract): ST-4c. Without it an archived item shows its banner, no button. */
  canRestore: boolean;
}

type Cause = 'unreachable' | 'server-error';

/** A number as the page shows it read-only: QR-2's rendering, then the item's unit code (R1-2, F-4). */
const withUnit = (served: string, unit: string) => `${renderQuantity(served)} ${unit}`;

/**
 * The item page (items-ux-copy.md B-3, B-5; IA L-4: the detail page is the editor; Revision 8 R8-2). It holds the item in
 * state and passes the re-read one to the form without a remount. Editable (SKU, Name, the three numbers) for an
 * UpdateItem holder on a live item; the same values as plain text otherwise. An archived item is read-only whatever the
 * viewer holds, under the ST-9 banner *"This item was archived on {date}."* with **Restore item** for a RestoreItem holder
 * (`components/records/RestoreActions`): a restore sets the re-read item here, so the page turns live without a remount.
 * A refused Save whose re-read finds the item archived (UX-I2 on a stale page) turns the page archived and shows the
 * server's sentence under **Not saved**, its heading focused. A failed re-read → the region error (focus on its heading),
 * Retry re-reads; 403 → ST-4b; 404 → ST-5. No stock, movements or links to them (G-47); no History (plan §2e); no Delete
 * item until I-3. After a save's or restore's message is on screen the router cache is refreshed once.
 */
export function ItemPage({ initial, canEdit, canRestore }: ItemPageProps) {
  const caller = useApiCaller();
  const router = useRouter();
  const timeZone = useViewerTimeZone();
  const region = useLiveRegion();
  const [item, setItem] = useCommittedState(initial);
  const [swap, setSwap] = useState<'forbidden' | 'not-found' | null>(null);
  const [rereadError, setRereadError] = useState<{ gen: number; cause: Cause } | null>(null);
  const [retrying, setRetrying] = useState(false);
  const [staleRefusal, setStaleRefusal] = useState<{ gen: number; message: string } | null>(null);
  const staleHeading = useRef<HTMLHeadingElement>(null);
  const h1 = useRef<HTMLHeadingElement>(null);
  const id = initial.id;

  const failed = useCallback((f: ReadFailure) => {
    if (f === 'session-ended') return;
    if (f === 'forbidden' || f === 'not-found') {
      setSwap(f);
      return;
    }
    // After the form's own after-save focus, so the region error's heading takes it.
    setTimeout(() => setRereadError((cur) => ({ gen: (cur?.gen ?? 0) + 1, cause: f })), 0);
  }, []);

  // L-2: every read the page commits is newer than an earlier failed re-read, so committing one clears that error.
  // A page-owned re-read (Retry) that a newer commit overtook says nothing about what is on screen: its answer, success
  // or failure, is dropped (the warehouse RED M-2, applied here as its twin).
  const commits = useRef(0);
  const commit = useCallback(
    async (i: Item) => {
      commits.current += 1;
      setRereadError(null);
      await setItem(i);
    },
    [setItem],
  );
  // The wiring is built when an action runs, not during render: `commit` bumps the `commits` ref, which may only be
  // touched outside render (react-hooks/refs).
  const save = useCallback((changes: ItemChange) => itemSave({ caller, id, setItem: commit, onRereadFailed: failed })(changes), [caller, id, commit, failed]);
  const restore = useCallback(() => itemRestore({ caller, id, setItem: commit, onRereadFailed: failed })(), [caller, id, commit, failed]);
  const onSaved = useCallback(() => router.refresh(), [router]);
  const onRefusedArchived = useCallback((message: string) => setStaleRefusal((cur) => ({ gen: (cur?.gen ?? 0) + 1, message })), []);
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

  const retry = async () => {
    if (retrying) return;
    setRetrying(true);
    const seen = commits.current;
    const d = startDeadline(ACTION_DEADLINES_MS.reread);
    const r = await loadItem(readOf(caller), id, d.signal);
    d.cancel();
    setRetrying(false);
    if (commits.current !== seen) return;
    if (r.kind === 'ok') {
      await commit(r.item);
      setRereadError(null);
      h1.current?.focus();
    } else failed(r.kind);
  };

  if (swap === 'forbidden') return <ItemsDenied />;
  if (swap === 'not-found') return <ItemNotFound />;

  const archived = item.archived_at !== null;
  return (
    <section className={listStyles.page}>
      <header className={listStyles.header}>
        <h1 ref={h1} tabIndex={-1}>
          {ITEM_PAGE.title(item.sku, item.name)}
        </h1>
      </header>
      {/* Mounted from first render, above every branch below, so it never arrives already speaking. */}
      <LiveRegion message={region.message} visuallyHidden />
      {rereadError ? (
        <RegionError key={rereadError.gen} heading={rereadError.cause === 'unreachable' ? COULD_NOT_REACH : WENT_WRONG} body={ITEM_PAGE.rereadFailed} retrying={retrying} onRetry={() => void retry()} />
      ) : null}
      {staleRefusal && archived ? (
        <Banner key={staleRefusal.gen} tone="danger" live="none" heading={NOT_SAVED} ref={staleHeading}>
          <p>{staleRefusal.message}</p>
        </Banner>
      ) : null}
      {item.archived_at !== null ? (
        <RestoreActions
          archivedAt={item.archived_at}
          copy={{
            archivedBefore: ITEM_PAGE.archivedOn,
            archivedAfter: ITEM_PAGE.archivedAfter,
            action: RESTORE_ITEM.action,
            after: (r) => RESTORE_ITEM.after(r.item.sku),
            alreadyLive: (r) => RESTORE_ITEM.alreadyRestored(r.item.sku),
            interrupted: RESTORE_ITEM.interrupted(item.sku),
            forbidden: ITEM_FORBIDDEN,
          }}
          canRestore={canRestore}
          restore={restore}
          restoreCode={gateCode('RestoreItem')}
          timeZone={timeZone}
          announce={region.announce}
          fallbackFocusRef={h1}
          onRestored={onRestored}
        />
      ) : null}
      {canEdit && !archived ? (
        <ItemForm item={item} save={save} editCode={gateCode('UpdateItem')} announce={region.announce} fallbackFocusRef={h1} onSaved={onSaved} onRefusedArchived={onRefusedArchived} />
      ) : (
        <dl className={styles.fields}>
          <dt>{ITEM_PAGE.sku}</dt>
          <dd>{item.sku}</dd>
          <dt>{ITEM_PAGE.name}</dt>
          <dd>{item.name}</dd>
          <dt>{ITEM_PAGE.unit}</dt>
          <dd>{item.unit_of_measure_code}</dd>
          <dt>{ITEM_PAGE.supplier}</dt>
          <dd>{item.supplier !== null ? item.supplier.name : ITEMS_LIST.noSupplier}</dd>
          <dt>{ITEM_PAGE.reorderPoint}</dt>
          <dd>{withUnit(item.reorder_point, item.unit_of_measure_code)}</dd>
          <dt>{ITEM_PAGE.reorderQuantity}</dt>
          <dd>{withUnit(item.reorder_quantity, item.unit_of_measure_code)}</dd>
          <dt>{ITEM_PAGE.safetyStock}</dt>
          <dd>{withUnit(item.safety_stock, item.unit_of_measure_code)}</dd>
        </dl>
      )}
    </section>
  );
}

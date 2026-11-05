'use client';

import { useCallback, useMemo, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import { RegionError } from '@/components/states/PageStates';
import { Banner } from '@/components/ui/Banner';
import { DateTime } from '@/components/ui/DateTime';
import { LiveRegion, useLiveRegion } from '@/components/ui/LiveRegion';
import { WarehouseForm } from '@/components/warehouses/WarehouseForm';
import styles from '@/components/warehouses/Warehouses.module.css';
import { COULD_NOT_REACH, WENT_WRONG } from '@/copy/headings';
import { WAREHOUSE_PAGE } from '@/copy/warehouses';
import type { Warehouse } from '@/lib/api/warehouses';
import { ACTION_DEADLINES_MS, startDeadline } from '@/lib/bff/deadline';
import { readOf, type ReadFailure } from '@/lib/bff/reads';
import { gateCode } from '@/lib/bff/surface';
import { loadWarehouse } from '@/lib/warehouses/load';
import { warehouseSave } from '@/lib/warehouses/wired';
import { useApiCaller, useViewerTimeZone } from '../../BffProvider';
import { useCommittedState } from '../../useCommittedState';
import { WarehouseNotFound, WarehousesDenied } from '../WarehouseStates';
import listStyles from '../../Lists.module.css';

export interface WarehousePageProps {
  /** GetWarehouse on first render. */
  initial: Warehouse;
  /** Holds UpdateWarehouse's codes (from the contract): ST-4c. Without it the fields are plain text. */
  canEdit: boolean;
}

type Cause = 'unreachable' | 'server-error';

/**
 * The warehouse page (warehouses-ux-copy.md B-3; IA L-4: the detail page is the editor). It holds the warehouse in
 * state and passes the re-read one to the form without a remount. A failed re-read → the region error (focus on its
 * heading), Retry re-reads; 403 → ST-4b; 404 → ST-5. An archived warehouse is read-only whatever the viewer holds (the
 * API refuses its update, W3), under the persistent ST-9b banner *"This warehouse was archived on {date}."*, which names
 * no action and so holds without **Restore warehouse** (R3-8 "Silent"; Restore arrives at §2 step 4.2). After a save's
 * message is on screen the router cache is refreshed once, so Back to the list shows the saved code and name.
 */
export function WarehousePage({ initial, canEdit }: WarehousePageProps) {
  const caller = useApiCaller();
  const router = useRouter();
  const timeZone = useViewerTimeZone();
  const region = useLiveRegion();
  const [warehouse, setWarehouse] = useCommittedState(initial);
  const [swap, setSwap] = useState<'forbidden' | 'not-found' | null>(null);
  const [rereadError, setRereadError] = useState<{ gen: number; cause: Cause } | null>(null);
  const [retrying, setRetrying] = useState(false);
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

  const save = useMemo(() => warehouseSave({ caller, id, setWarehouse, onRereadFailed: failed }), [caller, id, setWarehouse, failed]);
  const onSaved = useCallback(() => router.refresh(), [router]);

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
  return (
    <section className={listStyles.page}>
      <header className={listStyles.header}>
        <h1 ref={h1} tabIndex={-1}>
          {WAREHOUSE_PAGE.title(warehouse.code, warehouse.name)}
        </h1>
      </header>
      {/* Mounted from first render, above every branch below, so it never arrives already speaking. */}
      <LiveRegion message={region.message} visuallyHidden />
      {rereadError ? (
        <RegionError key={rereadError.gen} heading={rereadError.cause === 'unreachable' ? COULD_NOT_REACH : WENT_WRONG} body={WAREHOUSE_PAGE.rereadFailed} retrying={retrying} onRetry={() => void retry()} />
      ) : null}
      {archived ? (
        <Banner tone="neutral" heading={<>{WAREHOUSE_PAGE.archivedOn}<DateTime value={warehouse.archived_at!} form="date" timeZone={timeZone} />{WAREHOUSE_PAGE.archivedAfter}</>} />
      ) : null}
      {canEdit && !archived ? (
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
    </section>
  );
}

'use client';

import { useMemo } from 'react';
import Link from 'next/link';
import { Button } from '@/components/ui/Button';
import { DateTime } from '@/components/ui/DateTime';
import { WAREHOUSES_LIST } from '@/copy/warehouses';
import type { Warehouse } from '@/lib/api/warehouses';
import type { Read } from '@/lib/bff/reads';
import { loadWarehouses, type WarehousesLoad } from '@/lib/warehouses/load';
import { useViewerTimeZone } from '../BffProvider';
import { ArchivableList, ArchivedToggle, type Column, type EmptyState, type HeaderView, type ListLoad } from '../ArchivableList';
import { useArchivedAddress } from '../useArchivedAddress';
import { WarehousesDenied } from './WarehouseStates';

/** B-1: case-insensitive prefix on the code or the name, client-side only (the server's `q` is not sent, L-2). */
const matches = (w: Warehouse, lowerQ: string) => w.code.toLowerCase().startsWith(lowerQ) || w.name.toLowerCase().startsWith(lowerQ);
const asList = (r: WarehousesLoad): ListLoad<Warehouse> => (r.kind === 'ok' ? { kind: 'ok', items: r.warehouses, tenantHasRows: r.tenantHasRows } : r);
const load = async (read: Read, archived: boolean, signal: AbortSignal) => asList(await loadWarehouses(read, archived, signal));
/** B-2: ordered by code as served, no caption; archived rows struck through (ST-9b). */
const ARCHIVED_VIEW = { order: 'as-served', rows: 'struck' } as const;
const SEARCH = { label: WAREHOUSES_LIST.searchFor, placeholder: WAREHOUSES_LIST.searchPlaceholder };

/** B-1's Create warehouse empty-state action: a link to `/warehouses/new`, shown only with `CreateWarehouse`'s codes. */
const createAction = (canCreate: boolean) => (canCreate ? <Link href="/warehouses/new">{WAREHOUSES_LIST.createWarehouse}</Link> : null);

/**
 * B-1's empty states (ST-2). `tenant_has_rows` counts archived rows too (U-6). **Create warehouse** (§2 step 4.3) on the
 * two live, unsearched rows, for a viewer holding `CreateWarehouse`'s codes only.
 */
export const emptyFor = (canCreate: boolean): EmptyState => ({ archived, q, tenantHasRows, clearSearch, switchTo }) => {
  if (q !== '') {
    return {
      heading: archived ? WAREHOUSES_LIST.noArchivedMatch(q) : WAREHOUSES_LIST.noMatch(q),
      actions: (
        <Button size="sm" onClick={clearSearch}>
          {WAREHOUSES_LIST.clearSearch}
        </Button>
      ),
    };
  }
  if (archived) return { heading: WAREHOUSES_LIST.noneArchived };
  if (tenantHasRows === true) {
    return {
      heading: WAREHOUSES_LIST.allArchived,
      actions: (
        <>
          {createAction(canCreate)}
          <Button size="sm" onClick={() => switchTo(true)}>
            {WAREHOUSES_LIST.viewArchived}
          </Button>
        </>
      ),
    };
  }
  const create = createAction(canCreate);
  return create !== null ? { heading: WAREHOUSES_LIST.noneYet, actions: create } : { heading: WAREHOUSES_LIST.noneYet };
};

/** The empty states for a viewer without `CreateWarehouse`. */
export const EMPTY: EmptyState = emptyFor(false);

/**
 * The header controls (B-1, B-2): **New warehouse** (with `CreateWarehouse`'s codes, §2 step 4.3) and **Archived** in
 * the live view; **Back to warehouses** replaces both in the Archived view, and only once a view has loaded (R3-1: no
 * Back to… before a view has loaded; D-3).
 */
export const headerFor = (canCreate: boolean) =>
  function header({ archived, busy, loaded, switchTo, viewControlRef }: HeaderView) {
    if (!archived) {
      const toggle = <ArchivedToggle ref={viewControlRef} archived={false} busy={busy} onClick={() => switchTo(true)} label={WAREHOUSES_LIST.archived} />;
      return canCreate ? (
        <>
          <Link href="/warehouses/new">{WAREHOUSES_LIST.newWarehouse}</Link>
          {toggle}
        </>
      ) : (
        toggle
      );
    }
    return archivedHeader({ busy, loaded, switchTo, viewControlRef });
  };

function archivedHeader({ busy, loaded, switchTo, viewControlRef }: Pick<HeaderView, 'busy' | 'loaded' | 'switchTo' | 'viewControlRef'>) {
  if (!loaded) return null;
  return (
    <Button ref={viewControlRef} loading={busy} onClick={() => switchTo(false)}>
      {WAREHOUSES_LIST.backToList}
    </Button>
  );
}

/** `/warehouses` (B-1, B-2): Code · Name · Country live; + Archived on archived. The code is the row's link (B-1 Row). */
export function WarehousesList({ initial, initialArchived = false, canCreate = false }: { initial: WarehousesLoad; initialArchived?: boolean; canCreate?: boolean }) {
  const timeZone = useViewerTimeZone();
  const onViewLoaded = useArchivedAddress();
  const header = useMemo(() => headerFor(canCreate), [canCreate]);
  const empty = useMemo(() => emptyFor(canCreate), [canCreate]);
  const base: Column<Warehouse>[] = [
    { header: WAREHOUSES_LIST.code, cell: (w) => <Link href={`/warehouses/${w.id}`}>{w.code}</Link> },
    { header: WAREHOUSES_LIST.name, cell: (w) => w.name },
    { header: WAREHOUSES_LIST.country, cell: (w) => w.country_code },
  ];
  const columns = (archived: boolean): Column<Warehouse>[] =>
    archived ? [...base, { header: WAREHOUSES_LIST.archivedOn, cell: (w) => (w.archived_at !== null ? <DateTime value={w.archived_at} form="date" timeZone={timeZone} /> : null) }] : base;
  return (
    <ArchivableList
      initial={asList(initial)}
      initialArchived={initialArchived}
      onViewLoaded={onViewLoaded}
      load={load}
      matches={matches}
      search={SEARCH}
      columns={columns}
      copy={WAREHOUSES_LIST}
      header={header}
      empty={empty}
      archivedView={ARCHIVED_VIEW}
      denied={<WarehousesDenied />}
    />
  );
}

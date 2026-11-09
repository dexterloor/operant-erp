'use client';

import Link from 'next/link';
import { Button } from '@/components/ui/Button';
import { DateTime } from '@/components/ui/DateTime';
import { ITEMS_LIST, ITEMS_PAGING } from '@/copy/items';
import type { Item } from '@/lib/api/items';
import type { PageRequest, Read } from '@/lib/bff/reads';
import { formatDate } from '@/lib/format/dates';
import { loadItems, type ItemsLoad } from '@/lib/items/load';
import { useViewerTimeZone } from '../BffProvider';
import { ArchivableList, ArchivedToggle, type Column, type EmptyState, type HeaderView, type PagedLoad, type Paging } from '../ArchivableList';
import { useArchivedAddress } from '../useArchivedAddress';
import listStyles from '../Lists.module.css';
import { ItemsDenied } from './ItemStates';

const asPaged = (r: ItemsLoad): PagedLoad<Item> => r;
const load = async (read: Read, archived: boolean, page: PageRequest, signal: AbortSignal) => asPaged(await loadItems(read, archived, page, signal));
/** B-2: the server's one order, newest archived first, named by the caption; archived rows struck through (ST-9a). */
const ARCHIVED_VIEW = { order: 'as-served', caption: ITEMS_LIST.archivedCaption, rows: 'struck' } as const;
const SEARCH = { label: ITEMS_LIST.searchFor, placeholder: ITEMS_LIST.searchPlaceholder, hint: ITEMS_LIST.searchHint };

/**
 * R1-16's empty states, as I-1 offers them: no **Create item** anywhere until `/items/new` exists (I-2), so a live,
 * unsearched empty list offers nothing, or **View archived items** when the workspace's items are all archived.
 */
export const EMPTY: EmptyState = ({ archived, q, tenantHasRows, clearSearch, switchTo }) => {
  if (q !== '') {
    const clear = (
      <Button size="sm" onClick={clearSearch}>
        {ITEMS_LIST.clearSearch}
      </Button>
    );
    if (archived) return { heading: ITEMS_LIST.noArchivedMatch(q), actions: clear };
    return {
      heading: ITEMS_LIST.noMatch(q),
      actions: (
        <>
          <p className={listStyles.emptyBody}>{ITEMS_LIST.noMatchNote}</p>
          {clear}
        </>
      ),
    };
  }
  if (archived) return { heading: ITEMS_LIST.noneArchived };
  if (tenantHasRows === true) {
    return {
      heading: ITEMS_LIST.allArchived,
      actions: (
        <Button size="sm" onClick={() => switchTo(true)}>
          {ITEMS_LIST.viewArchived}
        </Button>
      ),
    };
  }
  return { heading: ITEMS_LIST.noneYet };
};

/** B-1, B-2: **Archived** in the live view; **Back to items** replaces it in the Archived view once a view has loaded (R3-1). No **New item** in I-1 (R1-16). */
export function header({ archived, busy, loaded, switchTo, viewControlRef }: HeaderView) {
  if (!archived) return <ArchivedToggle ref={viewControlRef} archived={false} busy={busy} onClick={() => switchTo(true)} label={ITEMS_LIST.archived} />;
  if (!loaded) return null;
  return (
    <Button ref={viewControlRef} loading={busy} onClick={() => switchTo(false)}>
      {ITEMS_LIST.backToList}
    </Button>
  );
}

/**
 * The paged list's words (R8-1; UX R1-3, R1-7, R1-8, F-2, F-3). The page sentence's position cue is per view: the live
 * view (ordered by SKU) names its first and last SKU; the Archived view (ordered by date) names its archive dates, DT-1,
 * in the viewer's zone, and its first SKU as an identifier, never an SKU range.
 */
export function pagingFor(timeZone: string): Paging<Item> {
  return {
    pager: ITEMS_PAGING.pager,
    noMore: ITEMS_PAGING.noMore,
    pageShown: (rows, q, archived) => {
      const first = rows[0]!;
      const last = rows[rows.length - 1]!;
      let sentence: string;
      if (!archived) sentence = ITEMS_PAGING.liveRange(rows.length, first.sku, last.sku);
      else {
        const newest = formatDate(first.archived_at ?? '', timeZone);
        const oldest = formatDate(last.archived_at ?? '', timeZone);
        sentence = rows.length === 1 ? ITEMS_PAGING.archivedOne(first.sku, newest) : newest === oldest ? ITEMS_PAGING.archivedOneDate(rows.length, newest, first.sku) : ITEMS_PAGING.archivedRange(rows.length, newest, oldest, first.sku);
      }
      return q !== '' ? `${sentence} ${ITEMS_PAGING.searched(q)}` : sentence;
    },
  };
}

/**
 * `/items` (B-1, B-2 as amended by R1-5, R1-6): SKU · Name · Unit · Preferred supplier live; SKU · Name · Archived on
 * archived. No quantity column (R1-5). The SKU is the row's link. Paged and searched by the server (R8-1): the box sends
 * `q`, a case-sensitive SKU prefix, as typed.
 */
export function ItemsList({ initial, initialArchived = false }: { initial: ItemsLoad; initialArchived?: boolean }) {
  const timeZone = useViewerTimeZone();
  const onViewLoaded = useArchivedAddress();
  const sku: Column<Item> = { header: ITEMS_LIST.sku, cell: (i) => <Link href={`/items/${i.id}`}>{i.sku}</Link> };
  const name: Column<Item> = { header: ITEMS_LIST.name, cell: (i) => i.name };
  const columns = (archived: boolean): Column<Item>[] =>
    archived
      ? [sku, name, { header: ITEMS_LIST.archivedOn, cell: (i) => (i.archived_at !== null ? <DateTime value={i.archived_at} form="date" timeZone={timeZone} /> : null) }]
      : [sku, name, { header: ITEMS_LIST.unit, cell: (i) => i.unit_of_measure_code }, { header: ITEMS_LIST.supplier, cell: (i) => (i.supplier !== null ? i.supplier.name : ITEMS_LIST.noSupplier) }];
  return (
    <ArchivableList
      paging={pagingFor(timeZone)}
      initial={asPaged(initial)}
      initialArchived={initialArchived}
      onViewLoaded={onViewLoaded}
      load={load}
      search={SEARCH}
      columns={columns}
      copy={ITEMS_LIST}
      header={header}
      empty={EMPTY}
      archivedView={ARCHIVED_VIEW}
      footer={(rows) => (rows.length > 0 ? <p className={listStyles.footer}>{ITEMS_LIST.footer(rows.length)}</p> : null)}
      denied={<ItemsDenied />}
    />
  );
}

'use client';

import Link from 'next/link';
import { Button } from '@/components/ui/Button';
import { DateTime } from '@/components/ui/DateTime';
import { SUPPLIERS_LIST, SUPPLIERS_PAGING } from '@/copy/suppliers';
import type { Supplier } from '@/lib/api/suppliers';
import type { PageRequest, Read } from '@/lib/bff/reads';
import { formatDate } from '@/lib/format/dates';
import { loadSuppliers, type SuppliersLoad } from '@/lib/suppliers/load';
import { useViewerTimeZone } from '../BffProvider';
import { ArchivableList, ArchivedToggle, type Column, type EmptyState, type HeaderView, type PagedLoad, type Paging } from '../ArchivableList';
import { useArchivedAddress } from '../useArchivedAddress';
import listStyles from '../Lists.module.css';
import { SuppliersDenied } from './SupplierStates';

const asPaged = (r: SuppliersLoad): PagedLoad<Supplier> => r;
const load = async (read: Read, archived: boolean, page: PageRequest, signal: AbortSignal) => asPaged(await loadSuppliers(read, archived, page, signal));
/** The server's one Archived order, newest archived first, named by the caption; archived rows struck through (ST-9a). */
const ARCHIVED_VIEW = { order: 'as-served', caption: SUPPLIERS_LIST.archivedCaption, rows: 'struck' } as const;
const SEARCH = { label: SUPPLIERS_LIST.searchFor, placeholder: SUPPLIERS_LIST.searchPlaceholder, hint: SUPPLIERS_LIST.searchHint };

/**
 * ST-9a's five rows (UX S-1). S-1 offers no create action anywhere: the create page arrives at S-2, so `noneYet` and
 * `allArchived` name none (UX `noneYet` "no action until S-2").
 */
export const empty: EmptyState = ({ archived, q, tenantHasRows, clearSearch, switchTo }) => {
  if (q !== '') {
    const clear = (
      <Button size="sm" onClick={clearSearch}>
        {SUPPLIERS_LIST.clearSearch}
      </Button>
    );
    return { heading: archived ? SUPPLIERS_LIST.noArchivedMatch(q) : SUPPLIERS_LIST.noMatch(q), actions: clear };
  }
  if (archived) return { heading: SUPPLIERS_LIST.noneArchived };
  if (tenantHasRows === true) {
    return {
      heading: SUPPLIERS_LIST.allArchived,
      actions: (
        <Button size="sm" onClick={() => switchTo(true)}>
          {SUPPLIERS_LIST.viewArchived}
        </Button>
      ),
    };
  }
  return { heading: SUPPLIERS_LIST.noneYet };
};

/** **Archived** in the live view; **Back to suppliers** replaces it in the Archived view, and only once a view has loaded (R3-1). */
export function header({ archived, busy, loaded, switchTo, viewControlRef }: HeaderView) {
  if (!archived) return <ArchivedToggle ref={viewControlRef} archived={false} busy={busy} onClick={() => switchTo(true)} label={SUPPLIERS_LIST.archived} />;
  if (!loaded) return null;
  return (
    <Button ref={viewControlRef} loading={busy} onClick={() => switchTo(false)}>
      {SUPPLIERS_LIST.backToList}
    </Button>
  );
}

/**
 * The paged list's words (R8-1; UX S-1 `SUPPLIERS_PAGING`). The page sentence's position cue is per view: the live view
 * (ordered by name, the served default) names its first and last names; the Archived view (ordered by date) names its
 * archive dates, DT-1, in the viewer's zone, and its first name as an identifier.
 */
export function pagingFor(timeZone: string): Paging<Supplier> {
  return {
    pager: SUPPLIERS_PAGING.pager,
    noMore: SUPPLIERS_PAGING.noMore,
    pageShown: (rows, q, archived) => {
      const first = rows[0]!;
      const last = rows[rows.length - 1]!;
      let sentence: string;
      if (!archived) sentence = SUPPLIERS_PAGING.liveRange(rows.length, first.name, last.name);
      else {
        const newest = formatDate(first.archived_at ?? '', timeZone);
        const oldest = formatDate(last.archived_at ?? '', timeZone);
        sentence = rows.length === 1 ? SUPPLIERS_PAGING.archivedOne(first.name, newest) : newest === oldest ? SUPPLIERS_PAGING.archivedOneDate(rows.length, newest, first.name) : SUPPLIERS_PAGING.archivedRange(rows.length, newest, oldest, first.name);
      }
      return q !== '' ? `${sentence} ${SUPPLIERS_PAGING.searched(q)}` : sentence;
    },
  };
}

/**
 * `/suppliers` (UX S-1): Name · Code · Country · Currency · Payment terms live; Name · Code · Archived on archived. The
 * name is the row's link. Paged and searched by the server (R8-1): the box sends `q` as typed (a name prefix ignoring
 * capitals, or a code prefix as stored). No sort control (suppliers-plan.md §0): the order is the served default.
 */
export function SuppliersList({ initial, initialArchived = false }: { initial: SuppliersLoad; initialArchived?: boolean }) {
  const timeZone = useViewerTimeZone();
  const onViewLoaded = useArchivedAddress();
  const name: Column<Supplier> = { header: SUPPLIERS_LIST.name, cell: (s) => <Link href={`/suppliers/${s.id}`}>{s.name}</Link> };
  const code: Column<Supplier> = { header: SUPPLIERS_LIST.code, cell: (s) => s.code };
  const columns = (archived: boolean): Column<Supplier>[] =>
    archived
      ? [name, code, { header: SUPPLIERS_LIST.archivedOn, cell: (s) => (s.archived_at !== null ? <DateTime value={s.archived_at} form="date" timeZone={timeZone} /> : null) }]
      : [
          name,
          code,
          { header: SUPPLIERS_LIST.country, cell: (s) => s.country_code },
          { header: SUPPLIERS_LIST.currency, cell: (s) => s.currency_code },
          { header: SUPPLIERS_LIST.paymentTerms, cell: (s) => SUPPLIERS_LIST.days(s.payment_terms_days) },
        ];
  return (
    <ArchivableList
      paging={pagingFor(timeZone)}
      initial={asPaged(initial)}
      initialArchived={initialArchived}
      onViewLoaded={onViewLoaded}
      load={load}
      search={SEARCH}
      columns={columns}
      copy={SUPPLIERS_LIST}
      header={header}
      empty={empty}
      archivedView={ARCHIVED_VIEW}
      footer={(rows) => (rows.length > 0 ? <p className={listStyles.footer}>{SUPPLIERS_LIST.footer(rows.length)}</p> : null)}
      denied={<SuppliersDenied />}
    />
  );
}

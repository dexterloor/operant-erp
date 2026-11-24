'use client';

import Link from 'next/link';
import { Button } from '@/components/ui/Button';
import { DateTime } from '@/components/ui/DateTime';
import { CUSTOMERS_LIST, CUSTOMERS_PAGING } from '@/copy/customers';
import type { Customer } from '@/lib/api/customers';
import type { PageRequest, Read } from '@/lib/bff/reads';
import { formatDate } from '@/lib/format/dates';
import { loadCustomers, type CustomersLoad } from '@/lib/customers/load';
import { useViewerTimeZone } from '../BffProvider';
import { ArchivableList, ArchivedToggle, type Column, type EmptyState, type HeaderView, type PagedLoad, type Paging } from '../ArchivableList';
import { useArchivedAddress } from '../useArchivedAddress';
import listStyles from '../Lists.module.css';
import { CustomersDenied } from './CustomerStates';

const asPaged = (r: CustomersLoad): PagedLoad<Customer> => r;
const load = async (read: Read, archived: boolean, page: PageRequest, signal: AbortSignal) => asPaged(await loadCustomers(read, archived, page, signal));
/** The server's one Archived order, newest archived first, named by the caption; archived rows struck through (ST-9a). */
const ARCHIVED_VIEW = { order: 'as-served', caption: CUSTOMERS_LIST.archivedCaption, rows: 'struck' } as const;
const SEARCH = { label: CUSTOMERS_LIST.searchFor, placeholder: CUSTOMERS_LIST.searchPlaceholder, hint: CUSTOMERS_LIST.searchHint };

/**
 * ST-9a's five rows. C-1 offers no create action anywhere (it joins the two live, unsearched rows at C-3, with the create
 * page and G-48's shared-name dialog — customers-plan.md Revision 1 R1-5).
 */
export const empty: EmptyState = ({ archived, q, tenantHasRows, clearSearch, switchTo }) => {
  if (q !== '') {
    const clear = (
      <Button size="sm" onClick={clearSearch}>
        {CUSTOMERS_LIST.clearSearch}
      </Button>
    );
    return { heading: archived ? CUSTOMERS_LIST.noArchivedMatch(q) : CUSTOMERS_LIST.noMatch(q), actions: clear };
  }
  if (archived) return { heading: CUSTOMERS_LIST.noneArchived };
  if (tenantHasRows === true) {
    return {
      heading: CUSTOMERS_LIST.allArchived,
      actions: (
        <Button size="sm" onClick={() => switchTo(true)}>
          {CUSTOMERS_LIST.viewArchived}
        </Button>
      ),
    };
  }
  return { heading: CUSTOMERS_LIST.noneYet };
};

/** **Archived** in the live view; **Back to customers** replaces it in the Archived view, once a view has loaded (R3-1). */
export function header({ archived, busy, loaded, switchTo, viewControlRef }: HeaderView) {
  if (!archived) return <ArchivedToggle ref={viewControlRef} archived={false} busy={busy} onClick={() => switchTo(true)} label={CUSTOMERS_LIST.archived} />;
  if (!loaded) return null;
  return (
    <Button ref={viewControlRef} loading={busy} onClick={() => switchTo(false)}>
      {CUSTOMERS_LIST.backToList}
    </Button>
  );
}

/**
 * The paged list's words (R8-1). The page sentence's position cue is per view: the live view (ordered by name, the served
 * default) names its first and last names; the Archived view (ordered by date) names its archive dates, DT-1, in the
 * viewer's zone, and its first name as an identifier.
 */
export function pagingFor(timeZone: string): Paging<Customer> {
  return {
    pager: CUSTOMERS_PAGING.pager,
    noMore: CUSTOMERS_PAGING.noMore,
    pageShown: (rows, q, archived) => {
      const first = rows[0]!;
      const last = rows[rows.length - 1]!;
      let sentence: string;
      if (!archived) sentence = CUSTOMERS_PAGING.liveRange(rows.length, first.name, last.name);
      else {
        const newest = formatDate(first.archived_at ?? '', timeZone);
        const oldest = formatDate(last.archived_at ?? '', timeZone);
        sentence = rows.length === 1 ? CUSTOMERS_PAGING.archivedOne(first.name, newest) : newest === oldest ? CUSTOMERS_PAGING.archivedOneDate(rows.length, newest, first.name) : CUSTOMERS_PAGING.archivedRange(rows.length, newest, oldest, first.name);
      }
      return q !== '' ? `${sentence} ${CUSTOMERS_PAGING.searched(q)}` : sentence;
    },
  };
}

/**
 * `/customers` (customers-plan.md Revision 1 R1-5 C-1; ST-9a): Name · Code · Country · Currency · Payment terms live;
 * Name · Code · Archived on archived. The name is the row's link. Paged and searched by the server (R8-1): the box sends
 * `q` as typed (a name prefix ignoring capitals, or a code prefix as stored, TB-5a). No sort control: the order is the
 * served default.
 */
export function CustomersList({ initial, initialArchived = false }: { initial: CustomersLoad; initialArchived?: boolean }) {
  const timeZone = useViewerTimeZone();
  const onViewLoaded = useArchivedAddress();
  const name: Column<Customer> = { header: CUSTOMERS_LIST.name, cell: (c) => <Link href={`/customers/${c.id}`}>{c.name}</Link> };
  const code: Column<Customer> = { header: CUSTOMERS_LIST.code, cell: (c) => c.code };
  const columns = (archived: boolean): Column<Customer>[] =>
    archived
      ? [name, code, { header: CUSTOMERS_LIST.archivedOn, cell: (c) => (c.archived_at !== null ? <DateTime value={c.archived_at} form="date" timeZone={timeZone} /> : null) }]
      : [
          name,
          code,
          { header: CUSTOMERS_LIST.country, cell: (c) => c.country_code },
          { header: CUSTOMERS_LIST.currency, cell: (c) => c.currency_code },
          { header: CUSTOMERS_LIST.paymentTerms, cell: (c) => CUSTOMERS_LIST.days(c.payment_terms_days) },
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
      copy={CUSTOMERS_LIST}
      header={header}
      empty={empty}
      archivedView={ARCHIVED_VIEW}
      footer={(rows) => (rows.length > 0 ? <p className={listStyles.footer}>{CUSTOMERS_LIST.footer(rows.length)}</p> : null)}
      denied={<CustomersDenied />}
    />
  );
}

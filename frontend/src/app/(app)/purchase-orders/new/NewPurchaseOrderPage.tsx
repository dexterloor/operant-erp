'use client';

import { useCallback, useMemo } from 'react';
import { useRouter } from 'next/navigation';
import { NewOrderForm } from '@/components/purchasing/NewOrderForm';
import { Button } from '@/components/ui/Button';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { NEW_PURCHASE_ORDER, PURCHASING_FORBIDDEN, SUPPLIER_CHOICES } from '@/copy/purchasing';
import type { PurchaseOrder } from '@/lib/api/purchasing';
import { ACTION_DEADLINES_MS, startDeadline } from '@/lib/bff/deadline';
import { readOf, type PageRequest } from '@/lib/bff/reads';
import { gateCode } from '@/lib/bff/surface';
import { loadSupplierChoices, loadWarehouseChoices, type SupplierChoice, type SupplierChoicesLoad, type WarehouseChoicesLoad } from '@/lib/purchasing/load';
import { orderCreate } from '@/lib/purchasing/wired';
import { ArchivableList, type Column, type EmptyState, type Paging } from '../../ArchivableList';
import { leaveArrivalMessage } from '../../arrivalMessage';
import { useApiCaller } from '../../BffProvider';
import listStyles from '../../Lists.module.css';

/** The first page of live suppliers, no search (final RED M-3 step 1: every open, and every Change, reads it afresh). */
const FIRST_SUPPLIERS: PageRequest = { q: '', cursor: null };
/** The id of the choice list's heading inside the dialog (the core's focus target after a Retry, FE-R3 H-2). */
const CHOICES_HEADING_ID = 'new-order-supplier-choices-heading';

/**
 * The create page (UX B-2; plan §2.4). On `created` it leaves *"{number} was created as a draft."* for the new order's
 * page (A-19 item 7) and navigates there with `router.push`; that page speaks it after `RouteFocus` has moved focus to its
 * `h1`. It supplies the form's two reads over the browser transport (the supplier choice's first page, the warehouses'
 * Retry), each under the `reread` deadline, and the supplier choice list, which is the paged list core (a component
 * imports nothing from `app/`).
 */
export function NewPurchaseOrderPage({ warehouses }: { warehouses: WarehouseChoicesLoad }) {
  const caller = useApiCaller();
  const router = useRouter();
  const create = useMemo(() => orderCreate(caller), [caller]);
  const readSuppliers = useCallback(async () => {
    const d = startDeadline(ACTION_DEADLINES_MS.reread);
    try {
      return await loadSupplierChoices(readOf(caller), FIRST_SUPPLIERS, d.signal);
    } finally {
      d.cancel();
    }
  }, [caller]);
  const reloadWarehouses = useCallback(async () => {
    const d = startDeadline(ACTION_DEADLINES_MS.reread);
    try {
      return await loadWarehouseChoices(readOf(caller), d.signal);
    } finally {
      d.cancel();
    }
  }, [caller]);
  const onCreated = useCallback(
    (order: PurchaseOrder) => {
      const href = `/purchase-orders/${order.id}`;
      leaveArrivalMessage(href, NEW_PURCHASE_ORDER.after(order.order_number));
      router.push(href);
    },
    [router],
  );
  return (
    <section className={listStyles.page}>
      <header className={listStyles.header}>
        <h1 tabIndex={-1}>{NEW_PURCHASE_ORDER.heading}</h1>
      </header>
      <NewOrderForm
        create={create}
        createCode={gateCode('CreatePurchaseOrder')}
        warehouses={warehouses}
        warehousesViewCode={gateCode('ListWarehouses')}
        reloadWarehouses={reloadWarehouses}
        readSuppliers={readSuppliers}
        supplierList={(load, onChoose) => <SupplierChoiceList initial={load} onChoose={onChoose} />}
        onCreated={onCreated}
      />
    </section>
  );
}

/**
 * The supplier choice list inside the dialog (plan §2.4; the CatalogueSection `ItemChoiceList` shape): the paged list core
 * over `loadSupplierChoices`, LIVE suppliers only — no Archived control, no view switch — under its own heading, columns
 * Code · Name · Currency · **Choose**, the archived note above the rows, and its own status region (the core's, inside the dialog). Its first page is the
 * answer the open read, whatever its kind: ok → the rows; a failure → the core's region error with Retry; a 403 → the
 * lost-permission sentence naming ListSuppliers' permission.
 */
function SupplierChoiceList({ initial, onChoose }: { initial: SupplierChoicesLoad; onChoose: (s: SupplierChoice) => void }) {
  const columns = (): Column<SupplierChoice>[] => [
    { header: SUPPLIER_CHOICES.code, cell: (s) => s.code },
    { header: SUPPLIER_CHOICES.name, cell: (s) => s.name },
    { header: SUPPLIER_CHOICES.currency, cell: (s) => s.currency_code },
    {
      header: SUPPLIER_CHOICES.choose,
      cell: (s) => (
        <Button size="sm" aria-label={SUPPLIER_CHOICES.chooseSupplier(s.code)} onClick={() => onChoose(s)}>
          {SUPPLIER_CHOICES.choose}
        </Button>
      ),
    },
  ];
  const empty: EmptyState = ({ q, tenantHasRows, clearSearch }) =>
    q !== ''
      ? {
          heading: SUPPLIER_CHOICES.noMatch(q),
          actions: (
            <Button size="sm" onClick={clearSearch}>
              {SUPPLIER_CHOICES.clearSearch}
            </Button>
          ),
        }
      : { heading: tenantHasRows === true ? SUPPLIER_CHOICES.allArchived : SUPPLIER_CHOICES.noneYet };
  const paging: Paging<SupplierChoice> = {
    pager: () => SUPPLIER_CHOICES.pager,
    noMore: () => SUPPLIER_CHOICES.noMore,
    pageShown: (rows, q) => {
      const sentence = SUPPLIER_CHOICES.pageShown(rows.length, rows[0]!.name, rows[rows.length - 1]!.name);
      return q !== '' ? `${sentence} ${SUPPLIER_CHOICES.searched(q)}` : sentence;
    },
  };
  const viewCode = gateCode('ListSuppliers');
  return (
    <ArchivableList<SupplierChoice>
      paging={paging}
      initial={initial}
      load={(read, _archived, page, signal) => loadSupplierChoices(read, page, signal)}
      columns={columns}
      copy={{ heading: null, archived: '', loadFailed: SUPPLIER_CHOICES.loadFailed, shown: (n, q) => SUPPLIER_CHOICES.shown(n, q) }}
      headingId={CHOICES_HEADING_ID}
      header={() => (
        <h3 id={CHOICES_HEADING_ID} tabIndex={-1}>
          {SUPPLIER_CHOICES.heading}
        </h3>
      )}
      search={{ label: SUPPLIER_CHOICES.search, placeholder: SUPPLIER_CHOICES.placeholder, hint: SUPPLIER_CHOICES.hint }}
      // UX R1b-1 `archivedNote` (po-3-red.md L-4): inside the dialog the list is all the person sees.
      notice={() => <p>{SUPPLIER_CHOICES.archivedNote}</p>}
      empty={empty}
      archivedView={{ order: 'as-served', caption: '', rows: 'plain' }}
      denied={
        <p>
          {PURCHASING_FORBIDDEN.before}
          <em>{(viewCode !== null ? PERMISSION_LABELS[viewCode]?.label : undefined) ?? ''}</em>
          {PURCHASING_FORBIDDEN.after}
        </p>
      }
    />
  );
}

import Link from 'next/link';
import { PermissionDenied } from '@/components/states/PageStates';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { PAGE_STATES } from '@/copy/shell';
import { STOCK_LEVELS, STOCK_SCOPE, STOCK_WITHHELD } from '@/copy/stock';
import { gateCode } from '@/lib/bff/surface';
import listStyles from '../Lists.module.css';
import styles from './Stock.module.css';
import { StockLoadFailed } from './StockLevelsView';

// Stock on hand's page states (stock-st2-plan.md §2.6; UX `stock-st2-ux-copy.md` §B-6, Part C). A server file. It imports
// nothing from lib/stock: the page decides every state and passes what it needs as props (plan L-14), so no state here
// can compute a gate of its own. The permission named by ST-4b is the stock read's gate, read from the contract.

/** S-14 (ST-4b): the viewer lacks the stock read's code. Decided before any read. */
export function StockDenied() {
  const code = gateCode('ListStockLevels');
  return <PermissionDenied resourcePlural={STOCK_LEVELS.resourcePlural} permissionName={(code !== null ? PERMISSION_LABELS[code]?.label : undefined) ?? ''} />;
}

/**
 * S-7 / S-15 (UX §B-6): the viewer may read stock on hand but lacks a marker read's code, so no row is shown and nothing
 * is read. `missing` is computed by the page, never empty: the codes the viewer lacks (S-7), or the gates of the reads
 * that answered 403 (S-15).
 */
export function StockWithheld({ missing }: { missing: readonly string[] }) {
  const labels = missing.map((code) => PERMISSION_LABELS[code]?.label ?? code);
  return (
    <section className={listStyles.page}>
      <header className={listStyles.header}>
        <h1 tabIndex={-1}>{STOCK_WITHHELD.heading(labels.length)}</h1>
      </header>
      <p>{STOCK_WITHHELD.body(labels)}</p>
      <p>{STOCK_WITHHELD.why}</p>
      <p className={styles.hint}>{STOCK_LEVELS.onHandHint}</p>
      <p>
        <Link href="/">{PAGE_STATES.deniedBack}</Link>
      </p>
    </section>
  );
}

/** A not-found state of this page (S-8, S-9, S-10): its heading, ST-5's body, and the one way on. */
function StockNotFound({ heading, href, link }: { heading: string; href: string; link: string }) {
  return (
    <section className={listStyles.page}>
      <header className={listStyles.header}>
        <h1 tabIndex={-1}>{heading}</h1>
      </header>
      <div className={listStyles.empty}>
        <p className={listStyles.emptyBody}>{PAGE_STATES.notFoundBody}</p>
        <Link href={href}>{link}</Link>
      </div>
    </section>
  );
}

/** S-8: a well-formed warehouse id the warehouse read answers 404 for. */
export function StockUnknownWarehouse() {
  return <StockNotFound heading={STOCK_SCOPE.unknownWarehouse} href="/stock" link={STOCK_SCOPE.changeWarehouse} />;
}

/** S-9: the location is in neither of the warehouse's lists, both loaded. */
export function StockUnknownLocation({ warehouseId, warehouse }: { warehouseId: string; warehouse: string }) {
  return <StockNotFound heading={STOCK_SCOPE.unknownLocation(warehouse)} href={`/stock?warehouse_id=${warehouseId}`} link={STOCK_LEVELS.changeLocation} />;
}

/** S-10: the item read answered 404. */
export function StockUnknownItem() {
  return <StockNotFound heading={STOCK_SCOPE.unknownItem} href="/stock" link={STOCK_LEVELS.chooseLocationInstead} />;
}

/**
 * S-9a, S-12, S-13: a read failed. The page's heading, then the shared error (its heading by cause, the failed read's
 * sentence) with Retry, which re-runs this page on the server. Nothing below the failure is read.
 */
export function StockFailed({ heading, body, unreachable }: { heading: string; body: string; unreachable: boolean }) {
  return (
    <section className={listStyles.page}>
      <header className={listStyles.header}>
        <h1 tabIndex={-1}>{heading}</h1>
      </header>
      <StockLoadFailed body={body} unreachable={unreachable} />
    </section>
  );
}

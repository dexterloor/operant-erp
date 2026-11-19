import Link from 'next/link';
import { NotFound, PermissionDenied } from '@/components/states/PageStates';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { STOCK_MOVEMENTS_FILTER, STOCK_MOVEMENTS_LIST } from '@/copy/stock';
import { gateCode } from '@/lib/bff/surface';
import listStyles from '../Lists.module.css';

// The movement pages' ST-4b and ST-5 (stock-ux-copy.md B-0 `resourcePlural`: "stock movements"), REUSING the RULED
// page-state templates, and the list's "unknown item" state (UX S-5, S-9). The permission named is the list's gate, read
// from the contract (`ListStockMovements` and `GetStockMovement` share it). Revision 11 R11-1: the `stock` family's route
// folder; it imports no other family's `components/`, `lib/` or copy.

export function StockMovementsDenied() {
  const code = gateCode('ListStockMovements');
  return <PermissionDenied resourcePlural={STOCK_MOVEMENTS_LIST.resourcePlural} permissionName={(code !== null ? PERMISSION_LABELS[code]?.label : undefined) ?? ''} />;
}

export function StockMovementNotFound() {
  return <NotFound listHref="/stock-movements" />;
}

/**
 * The list filtered to an item it cannot find (stock-st1-red.md M-1 steps 1 and 5): the address names a value that is
 * not a record id (no read is sent), or the item's own read answered 404. No table: the heading, why, and the way back to
 * every movement.
 */
export function StockMovementsUnknownItem() {
  return (
    <section className={listStyles.page}>
      <header className={listStyles.header}>
        <h1 tabIndex={-1}>{STOCK_MOVEMENTS_LIST.heading}</h1>
      </header>
      <div className={listStyles.empty}>
        <p className={listStyles.emptyHeading}>{STOCK_MOVEMENTS_FILTER.unknownItem}</p>
        <Link href="/stock-movements">{STOCK_MOVEMENTS_FILTER.clear}</Link>
      </div>
    </section>
  );
}

import Link from 'next/link';
import { STOCK_LEVELS, STOCK_LOCATION_MARKER, STOCK_SCOPE } from '@/copy/stock';
import type { MarkerLocation, MarkerWarehouse } from '@/lib/stock/load';
import listStyles from '../Lists.module.css';
import styles from './Stock.module.css';
import { LocationLinks } from './LocationLinks';

// Stock on hand's picker (stock-st2-plan.md §2.2; UX `stock-st2-ux-copy.md` §B-1, §B-2): step 1 chooses a warehouse,
// step 2 a location in it. A server file. Each choice is a link (a change of scope is a navigation, R11-2); only live
// warehouses and live locations are offered. Rows-free: nothing here shows stock. The location links (and their kind
// labels) are `LocationLinks`', the one /stock file that reads the kind vocabulary (Revision 12 R12-5). `LocationLinks` is keyed by the warehouse, so its search text never outlives its address (R12-3).

/** Step 1, `/stock` (or S-11's bad address, which shows `badAddress` above it). */
export function StockWarehouseStep({ warehouses, tenantHasRows, badAddress }: { warehouses: readonly MarkerWarehouse[]; tenantHasRows: boolean; badAddress: boolean }) {
  return (
    <section className={listStyles.page}>
      <header className={listStyles.header}>
        <h1 tabIndex={-1}>{STOCK_LEVELS.heading}</h1>
      </header>
      {badAddress ? <p className={styles.notice}>{STOCK_SCOPE.badAddress}</p> : null}
      <p>{STOCK_SCOPE.intro}</p>
      <h2 className={styles.step}>{STOCK_SCOPE.chooseWarehouse}</h2>
      {warehouses.length === 0 ? (
        <div className={listStyles.empty}>
          <p className={listStyles.emptyHeading}>{tenantHasRows ? STOCK_SCOPE.allWarehousesArchived : STOCK_SCOPE.noWarehousesYet}</p>
          <Link href="/warehouses">{STOCK_SCOPE.noWarehousesYetLink}</Link>
        </div>
      ) : (
        <ul className={styles.choices}>
          {warehouses.map((w) => (
            <li key={w.id}>
              <Link className={styles.choice} href={`/stock?warehouse_id=${w.id}`}>
                {STOCK_SCOPE.warehouseOption(w.code, w.name)}
              </Link>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

/** Step 2, `/stock?warehouse_id=W`: W's live locations, the search box, and the archived note (always). */
export function StockLocationStep({ warehouse, locations }: { warehouse: MarkerWarehouse; locations: readonly MarkerLocation[] }) {
  const links = locations.map((l) => ({ id: l.id, code: l.code, kind: l.kind, href: `/stock?warehouse_id=${warehouse.id}&storage_location_id=${l.id}` }));
  return (
    <section className={listStyles.page}>
      <header className={listStyles.header}>
        <h1 tabIndex={-1}>{STOCK_LEVELS.heading}</h1>
      </header>
      <h2 className={styles.step}>
        {STOCK_SCOPE.chooseLocation(warehouse.name)}
        {warehouse.archived_at !== null ? (
          <>
            {' '}
            <span className={styles.badge}>{STOCK_LOCATION_MARKER.archived}</span>
          </>
        ) : null}
      </h2>
      <p>
        <Link href="/stock">{STOCK_SCOPE.changeWarehouse}</Link>
      </p>
      {links.length === 0 ? <p className={listStyles.emptyHeading}>{STOCK_SCOPE.noLiveLocations(warehouse.name)}</p> : <LocationLinks key={warehouse.id} links={links} />}
      <p className={listStyles.muted}>{STOCK_SCOPE.archivedNote}</p>
    </section>
  );
}

'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useId, useLayoutEffect, useRef, useState, useTransition, type ReactNode } from 'react';
import { flushSync } from 'react-dom';
import { RegionError, Skeleton } from '@/components/states/PageStates';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { LiveRegion, useLiveRegion } from '@/components/ui/LiveRegion';
import { COULD_NOT_REACH, WENT_WRONG } from '@/copy/headings';
import { PAGE_STATES } from '@/copy/shell';
import { STOCK_LEVELS, STOCK_LOCATION_MARKER, STOCK_SCOPE } from '@/copy/stock';
import type { StockLevel } from '@/lib/api/stock';
import { readOf } from '@/lib/bff/reads';
import { renderQuantity } from '@/lib/format/quantity';
import { loadMarkerInputs, type MarkerScope } from '@/lib/stock/levels';
import { stockRowMarker, type Marker } from '@/lib/stock/marker';
import { focusWasLost } from '../ArchivableList';
import { useApiCaller } from '../BffProvider';
import listStyles from '../Lists.module.css';
import styles from './Stock.module.css';

// Stock on hand's two views (stock-st2-plan.md §2.3, §2.4, §2.6, §3.5; UX `stock-st2-ux-copy.md` §B-3…§B-10). A client
// file. It receives rows that already carry their marker, computed on the server after the server decided the gate, so
// no row reaches the screen without one (MarkedRow; G-47). It computes no gate: it reaches no gate helper and no server
// file of this folder (Revision 12 R12-4). Its one read is the marker Retry (`loadMarkerInputs`), which re-reads the
// location and warehouse facts only, never the rows. A 403 on that Retry means the viewer lost a code: the view asks the
// server to decide again (`router.refresh()`) and shows the loading blocks in place of the rows until new props arrive.
// The page keys this view by its scope, so nothing here outlives its address (R12-3). After a Retry that resolves every
// Status unknown (rule R-F, stock-st2-ux-d4.md as corrected by stock-st2-ux-d4-red.md): the new markers are committed with
// flushSync, focus moves to this view's own <h1> only if it was lost (the removed Retry), then `markerChecked` is announced
// once (FE-D1's order, the ArchivableList precedent). A Retry blanks the status region when it starts, so a repeat failure
// is spoken again; a Retry whose rows were replaced meanwhile moves no focus and announces nothing.

/** One item-scope row: its marker and its warehouse's code (known only where the warehouse's state loaded). */
export interface ItemRow {
  readonly row: StockLevel;
  readonly marker: Marker;
  readonly warehouseCode: string | null;
}

export interface LocationViewProps {
  readonly scope: 'location';
  readonly warehouse: { readonly id: string; readonly code: string; readonly name: string };
  readonly location: { readonly id: string; readonly code: string };
  /** The one location's marker, computed on the server. */
  readonly marker: Marker;
  readonly rows: readonly StockLevel[];
  readonly tenantHasRows: boolean;
  /** Holds GetItem's codes: each row's item is a link to its page only then. */
  readonly canOpenItem: boolean;
  /** Holds ListPurchaseOrders' codes: `noneYetLink` only then. */
  readonly canListOrders: boolean;
}

export interface ItemViewProps {
  readonly scope: 'item';
  readonly itemId: string;
  /** The item's own read (GetItem), when the viewer holds it and it answered; else null (the interim heading, no Unit). */
  readonly item: { readonly sku: string; readonly name: string; readonly unit: string } | null;
  readonly rows: readonly ItemRow[];
  readonly tenantHasRows: boolean;
  /** `backToItem`: holds GetItem's codes, and `GetItem` did not answer 404 or 403. */
  readonly canOpenItem: boolean;
  /** Holds ListStockMovements' codes: `itemMovementsLink`. */
  readonly canListMovements: boolean;
  readonly canListOrders: boolean;
}

export type StockLevelsViewProps = LocationViewProps | ItemViewProps;

/** Code-unit order, the same on the server render and in the browser (no locale; plan §3.4). */
const byCodeUnits = (a: string, b: string) => (a < b ? -1 : a > b ? 1 : 0);

/** A badge: its own text element after the code it qualifies; colour, if any, only on top (§B-5 rule 3). */
const Badge = ({ children }: { children: ReactNode }) => (
  <>
    {' '}
    <span className={styles.badge}>{children}</span>
  </>
);

/** A location's badges, in the pinned display order: Quarantine, Archived, Status unknown. */
const LocationBadges = ({ marker }: { marker: Marker }) => (
  <>
    {marker.quarantine ? <Badge>{STOCK_LOCATION_MARKER.quarantine}</Badge> : null}
    {marker.locationArchived ? <Badge>{STOCK_LOCATION_MARKER.archived}</Badge> : null}
    {marker.unknown ? <Badge>{STOCK_LOCATION_MARKER.unknown}</Badge> : null}
  </>
);

/** A warehouse's badge: Archived. */
const WarehouseBadge = ({ marker }: { marker: Marker }) => (marker.warehouseArchived ? <Badge>{STOCK_LOCATION_MARKER.archived}</Badge> : null);

/** S-9a, S-12, S-13's shared error (its heading by cause), whose Retry re-runs the page on the server. */
export function StockLoadFailed({ body, unreachable }: { body: string; unreachable: boolean }) {
  const router = useRouter();
  const [pending, start] = useTransition();
  return <RegionError heading={unreachable ? COULD_NOT_REACH : WENT_WRONG} body={body} retrying={pending} autoFocus={false} onRetry={() => start(() => router.refresh())} />;
}

export function StockLevelsView(props: StockLevelsViewProps) {
  const router = useRouter();
  const caller = useApiCaller();
  const region = useLiveRegion();
  const hintId = useId();
  const h1Ref = useRef<HTMLHeadingElement>(null);
  // The rows on screen now (not the click-time props), for the stale-result clause.
  const rowsRef = useRef<unknown>(props.rows);
  useLayoutEffect(() => {
    rowsRef.current = props.rows;
  });
  // A Retry's result and a refresh in progress are tied to the rows they were made for: new rows (a refresh answered, or a
  // new address) make both stale by identity, so a refresh answered with rows shows them (plan L-12).
  const [retried, setRetried] = useState<{ readonly forRows: unknown; readonly markers: readonly Marker[]; readonly codes: readonly (string | null)[] } | null>(null);
  const [refreshingFor, setRefreshingFor] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const refreshing = refreshingFor !== null && refreshingFor === props.rows;
  const fresh = retried !== null && retried.forRows === props.rows ? retried : null;

  const scope: MarkerScope = props.scope === 'location' ? { kind: 'location', warehouseId: props.warehouse.id } : { kind: 'item', warehouseIds: props.rows.map((r) => r.row.warehouse.id) };
  const places = props.scope === 'location' ? [{ warehouse: { id: props.warehouse.id }, storage_location: { id: props.location.id } }] : props.rows.map((r) => r.row);
  const markers: readonly Marker[] = fresh !== null ? fresh.markers : props.scope === 'location' ? [props.marker] : props.rows.map((r) => r.marker);
  // The banner shows whenever a marker says Status unknown, rows or not (copy doc Rev 1c, RED R2-L1): in the item scope no
  // rows means no markers. Its heading and a failing Retry's announcement are one sentence, by scope (R2-M1).
  const anyUnknown = markers.some((m) => m.unknown);
  const bannerSentence = props.scope === 'location' ? STOCK_LOCATION_MARKER.unknownBannerLocation(props.location.code) : STOCK_LOCATION_MARKER.unknownBanner;

  const retry = async () => {
    const forRows = props.rows;
    region.clear();
    setBusy(true);
    const r = await loadMarkerInputs(readOf(caller), scope);
    setBusy(false);
    // The rows were replaced while the read was pending: nothing here is about them any more.
    if (rowsRef.current !== forRows) return;
    if (r.kind === 'forbidden') {
      setRefreshingFor(forRows);
      router.refresh();
      return;
    }
    // A 401: the caller's session hooks show the session-ended notice.
    if (r.kind === 'session-ended') return;
    const next = places.map((p) => stockRowMarker(p, r.inputs));
    const result = { forRows, markers: next, codes: places.map((p) => r.warehouseCodes.get(p.warehouse.id) ?? null) };
    if (next.some((m) => m.unknown)) {
      // Rule R-F 2: the banner stays and focus stays on its Retry.
      setRetried(result);
      region.announce(bannerSentence);
      return;
    }
    // Rule R-F 1: commit, then focus the h1 if focus was lost with the banner, then announce.
    flushSync(() => setRetried(result));
    if (focusWasLost()) h1Ref.current?.focus();
    region.announce(STOCK_LOCATION_MARKER.markerChecked);
  };

  const banner = anyUnknown ? (
    <Banner
      tone="warning"
      live="none"
      heading={bannerSentence}
      action={
        <Button size="sm" variant="secondary" loading={busy} onClick={() => void retry()}>
          {PAGE_STATES.retry}
        </Button>
      }
    />
  ) : null;
  const notes = (
    <>
      <p id={hintId} className={styles.hint}>
        {STOCK_LEVELS.onHandHint}
      </p>
      {props.rows.length > 0 ? <p className={styles.hint}>{STOCK_LOCATION_MARKER.markerNote}</p> : null}
      {banner}
    </>
  );
  const noneYet = (
    <div className={listStyles.empty}>
      <p className={listStyles.emptyHeading}>{STOCK_LEVELS.noneYet}</p>
      {props.canListOrders ? <Link href="/purchase-orders">{STOCK_LEVELS.noneYetLink}</Link> : null}
    </div>
  );

  if (props.scope === 'location') {
    const { warehouse, location } = props;
    const marker = markers[0]!;
    const rows = [...props.rows].sort((a, b) => byCodeUnits(a.inventory_item.sku, b.inventory_item.sku) || byCodeUnits(a.id, b.id));
    return (
      <section className={listStyles.page}>
        <LiveRegion message={region.message} visuallyHidden />
        <header className={listStyles.header}>
          <h1 ref={h1Ref} tabIndex={-1}>
            {STOCK_LEVELS.titleLocation(location.code, warehouse.name)}
          </h1>
        </header>
        <p>
          {STOCK_LEVELS.scopeLocation.lead}
          {location.code}
          <LocationBadges marker={marker} />
          {STOCK_LEVELS.scopeLocation.in}
          {STOCK_LEVELS.scopeLocation.warehouse(warehouse.code, warehouse.name)}
          <WarehouseBadge marker={marker} />
          {STOCK_LEVELS.scopeLocation.end}
        </p>
        <p className={styles.links}>
          <Link href={`/stock?warehouse_id=${warehouse.id}`}>{STOCK_LEVELS.changeLocation}</Link>
          <Link href="/stock">{STOCK_SCOPE.changeWarehouse}</Link>
        </p>
        {notes}
        {refreshing ? (
          <Skeleton label={PAGE_STATES.loading} />
        ) : rows.length === 0 ? (
          props.tenantHasRows ? (
            <div className={listStyles.empty}>
              <p className={listStyles.emptyHeading}>{STOCK_LEVELS.noneInLocation(location.code)}</p>
            </div>
          ) : (
            noneYet
          )
        ) : (
          <table className={`${listStyles.table} ${styles.stacked}`}>
            <caption>
              {STOCK_LEVELS.captionLocation.lead}
              {location.code}
              <LocationBadges marker={marker} />
              {STOCK_LEVELS.captionLocation.end}
            </caption>
            <thead>
              <tr>
                <th scope="col">{STOCK_LEVELS.item}</th>
                <th scope="col" aria-describedby={hintId}>
                  {STOCK_LEVELS.onHandInItemUnit}
                </th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => {
                const text = STOCK_LEVELS.itemCell(r.inventory_item.sku, r.inventory_item.name);
                return (
                  <tr key={r.id}>
                    <td data-label={STOCK_LEVELS.item}>{props.canOpenItem ? <Link href={`/items/${r.inventory_item.id}`}>{text}</Link> : text}</td>
                    <td data-label={STOCK_LEVELS.onHandInItemUnit}>{renderQuantity(r.qty_on_hand)}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        )}
      </section>
    );
  }

  const { itemId, item } = props;
  const sku = item?.sku ?? props.rows[0]?.row.inventory_item.sku ?? null;
  const name = item?.name ?? props.rows[0]?.row.inventory_item.name ?? null;
  const shown = props.rows.map((r, n) => ({ row: r.row, marker: markers[n]!, code: fresh !== null ? (fresh.codes[n] ?? null) : r.warehouseCode }));
  shown.sort((a, b) => byCodeUnits(a.row.storage_location.code, b.row.storage_location.code) || byCodeUnits(a.code ?? '', b.code ?? '') || byCodeUnits(a.row.id, b.row.id));
  const onHandHeading = item !== null ? STOCK_LEVELS.onHand : STOCK_LEVELS.onHandInItemUnit;
  return (
    <section className={listStyles.page}>
      <LiveRegion message={region.message} visuallyHidden />
      <header className={listStyles.header}>
        <h1 ref={h1Ref} tabIndex={-1}>
          {sku !== null ? STOCK_LEVELS.titleItem(sku) : STOCK_LEVELS.heading}
        </h1>
      </header>
      {sku !== null && name !== null ? <p>{STOCK_LEVELS.scopeItem(sku, name)}</p> : null}
      <p className={styles.links}>
        {props.canListMovements ? <Link href={`/stock-movements?inventory_item_id=${itemId}`}>{STOCK_LEVELS.itemMovementsLink}</Link> : null}
        {props.canOpenItem ? <Link href={`/items/${itemId}`}>{STOCK_LEVELS.backToItem}</Link> : null}
        <Link href="/stock">{STOCK_LEVELS.chooseLocationInstead}</Link>
      </p>
      {notes}
      {refreshing ? (
        <Skeleton label={PAGE_STATES.loading} />
      ) : shown.length === 0 ? (
        props.tenantHasRows ? (
          <div className={listStyles.empty}>
            <p className={listStyles.emptyHeading}>{item !== null ? STOCK_LEVELS.noneForItem(item.sku) : STOCK_LEVELS.noneForThisItem}</p>
          </div>
        ) : (
          noneYet
        )
      ) : (
        <table className={`${listStyles.table} ${styles.stacked}`}>
          <caption>{STOCK_LEVELS.captionItem(sku!)}</caption>
          <thead>
            <tr>
              <th scope="col">{STOCK_LEVELS.warehouse}</th>
              <th scope="col">{STOCK_LEVELS.location}</th>
              <th scope="col" aria-describedby={hintId}>
                {onHandHeading}
              </th>
              {item !== null ? <th scope="col">{STOCK_LEVELS.unit}</th> : null}
            </tr>
          </thead>
          <tbody>
            {shown.map(({ row, marker, code }) => (
              <tr key={row.id}>
                <td data-label={STOCK_LEVELS.warehouse}>
                  {code !== null ? STOCK_LEVELS.warehouseCell(code, row.warehouse.name) : row.warehouse.name}
                  <WarehouseBadge marker={marker} />
                </td>
                <td data-label={STOCK_LEVELS.location}>
                  <Link href={`/stock?warehouse_id=${row.warehouse.id}&storage_location_id=${row.storage_location.id}`}>{row.storage_location.code}</Link>
                  <LocationBadges marker={marker} />
                </td>
                <td data-label={onHandHeading}>{renderQuantity(row.qty_on_hand)}</td>
                {item !== null ? <td data-label={STOCK_LEVELS.unit}>{item.unit}</td> : null}
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}

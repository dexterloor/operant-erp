'use client';

import Link from 'next/link';
import { DateTime } from '@/components/ui/DateTime';
import { reasonWords, STOCK_MOVEMENT_PAGE, STOCK_MOVEMENT_SOURCE, STOCK_MOVEMENTS_LIST } from '@/copy/stock';
import type { StockMovement } from '@/lib/api/stock';
import { renderSignedQuantity } from '@/lib/format/quantity';
import type { Source } from '@/lib/stock/source';
import { useViewerTimeZone } from '../../BffProvider';
import listStyles from '../../Lists.module.css';
import styles from '../StockMovements.module.css';

export interface StockMovementPageProps {
  /** GetStockMovement on first render. */
  movement: StockMovement;
  /** The source's one rendering (`sourceOf`, R11-4), computed on the server from the viewer's gates. */
  source: Source;
  /** A receipt movement's receipt number, from the page's one allowed secondary read; null when not read or it failed. */
  receiptNumber: string | null;
  /** A transfer leg's partner location code, from the page's one allowed secondary read; null when not read or it failed. */
  partnerLocation: string | null;
  /** Holds GetItem's codes (ST-4c): the item is a link to its page only then. */
  canOpenItem: boolean;
  /** Holds ListStockMovements' codes (stock-st1-red.md L-11): the link to this item's movements only then. */
  canListMovements: boolean;
}

/** The Source field's text (UX B-2's detail column): the number or the partner's location after their read, else the word. */
function sourceText(source: Source, receiptNumber: string | null, partnerLocation: string | null): { text: string; moved: boolean } {
  if (source.word === 'goodsReceipt' && receiptNumber !== null) return { text: STOCK_MOVEMENT_SOURCE.receiptWithNumber(receiptNumber), moved: false };
  if (source.word === 'matchingMoveIn' && partnerLocation !== null) return { text: STOCK_MOVEMENT_SOURCE.movedTo(partnerLocation), moved: true };
  if (source.word === 'matchingMoveOut' && partnerLocation !== null) return { text: STOCK_MOVEMENT_SOURCE.movedFrom(partnerLocation), moved: true };
  return { text: STOCK_MOVEMENT_SOURCE[source.word], moved: false };
}

/**
 * The movement page (stock-plan.md §2.2; UX B-6 `STOCK_MOVEMENT_PAGE`; IA L-5a; b4-architecture.md Revision 11 R11-3):
 * read-only, no action — a movement is never changed or deleted, which `readOnlyNote` says without naming a remedy (the
 * remedies are not in the web app, G-51). The h1 is the reason and the SKU (a movement has no number). The facts: Date
 * (date and time, the viewer's zone) · What happened · Item (`{sku} — {name}`, a link only for a GetItem holder) · Location
 * (the code, never a link, with the standing hint that it names no warehouse and no quarantine) · Change (signed, the same
 * string as the list's cell, R11-5; its label says the figure is in the item's unit until BK-41) · Source (R11-4; a moved-to
 * or moved-from location carries the same hint, RED L-10). Then **All movements of {sku}** for a ListStockMovements holder
 * (the filter, R11-2) and **Back to stock movements**. No money, no History, no button, no input.
 */
export function StockMovementPage({ movement, source, receiptNumber, partnerLocation, canOpenItem, canListMovements }: StockMovementPageProps) {
  const timeZone = useViewerTimeZone();
  const item = movement.inventory_item;
  const itemText = STOCK_MOVEMENTS_LIST.itemCell(item.sku, item.name);
  const words = reasonWords(movement.reason);
  const shownSource = sourceText(source, receiptNumber, partnerLocation);
  return (
    <section className={listStyles.page}>
      <header className={listStyles.header}>
        <h1 tabIndex={-1}>{STOCK_MOVEMENT_PAGE.title(words.label, item.sku)}</h1>
      </header>
      <dl className={styles.fields}>
        <dt>{STOCK_MOVEMENTS_LIST.date}</dt>
        <dd>
          <DateTime value={movement.occurred_at} form="date-time" timeZone={timeZone} />
        </dd>
        <dt>{STOCK_MOVEMENTS_LIST.whatHappened}</dt>
        <dd>{words.label}</dd>
        <dt>{STOCK_MOVEMENTS_LIST.item}</dt>
        <dd>{canOpenItem ? <Link href={`/items/${item.id}`}>{itemText}</Link> : itemText}</dd>
        <dt>{STOCK_MOVEMENTS_LIST.location}</dt>
        <dd>
          {movement.storage_location.code}
          <p className={styles.hint}>{STOCK_MOVEMENT_PAGE.locationHint}</p>
        </dd>
        <dt>{STOCK_MOVEMENTS_LIST.changeInItemUnit}</dt>
        <dd>{renderSignedQuantity(movement.quantity)}</dd>
        <dt>{STOCK_MOVEMENTS_LIST.source}</dt>
        <dd>
          {source.kind === 'link' ? <Link href={source.href}>{shownSource.text}</Link> : shownSource.text}
          {shownSource.moved ? <p className={styles.hint}>{STOCK_MOVEMENT_PAGE.locationHint}</p> : null}
        </dd>
      </dl>
      <div className={styles.notes}>
        <p>{STOCK_MOVEMENT_PAGE.readOnlyNote}</p>
      </div>
      <p className={styles.links}>
        {canListMovements ? <Link href={`/stock-movements?inventory_item_id=${item.id}`}>{STOCK_MOVEMENT_PAGE.itemMovementsLink(item.sku)}</Link> : null}
        <Link href="/stock-movements">{STOCK_MOVEMENT_PAGE.backToList}</Link>
      </p>
    </section>
  );
}

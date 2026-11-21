import type { StockMovement } from '../api/stock';
import { isRecordId } from '../bff/paths';

// A movement's source, rendered from ONE closed table (stock-plan.md §2.6; b4-architecture.md Revision 11 R11-4; RED M-4,
// stock-st1-red.md L-2, L-4, L-5). This is the only place a served `source_type` is compared. A source is a link only
// where the web app has a record page for it (`LINKED_SOURCES`, each tied to its page on disk by test), the viewer's gate
// for that page is true (server-computed booleans, passed down as props: no client file asks the contract), and the
// served `source_id` is a record id (the wire declares no uuid format for it). Otherwise it is a plain word. The id is
// never text, and no document number is ever shown here (stock-reads N-2): a movement page shows one only after its own
// allowed read. The words are copy keys (lib imports no copy, A-6): `copy/stock.ts` STOCK_MOVEMENT_SOURCE holds them.

/** The source words, as `STOCK_MOVEMENT_SOURCE` keys. */
export type SourceWord = 'goodsReceipt' | 'fulfilment' | 'customerReturn' | 'matchingMoveIn' | 'matchingMoveOut' | 'none' | 'other';

/** The viewer's gates for the linked sources' pages: GetGoodsReceipt's and GetStockMovement's, read on the server. */
export interface SourceGates {
  readonly goodsReceipt: boolean;
  readonly movement: boolean;
}

export type Source = { readonly kind: 'link'; readonly href: string; readonly word: SourceWord } | { readonly kind: 'text'; readonly word: SourceWord };

/**
 * The linked kinds: each served `source_type` whose record has a page in the web app, that page's first path segment, and
 * the gate that opens it. A test holds every entry to an `[id]/page.tsx` on disk, so a link to an unbuilt page (a
 * shipment's, a customer return's) is red until that page exists; adding one is a deliberate edit in that page's commit.
 */
export const LINKED_SOURCES: Readonly<Record<string, { readonly segment: string; readonly gate: keyof SourceGates }>> = {
  goods_receipt: { segment: 'goods-receipts', gate: 'goodsReceipt' },
  stock_movement: { segment: 'stock-movements', gate: 'movement' },
};

/** The word for a movement's source. A transfer leg names its partner; any value outside the table is `other`, never the raw value. */
export function sourceWord(m: Pick<StockMovement, 'source_type' | 'reason'>): SourceWord {
  switch (m.source_type) {
    case null:
      return 'none';
    case 'goods_receipt':
      return 'goodsReceipt';
    case 'fulfilment':
      return 'fulfilment';
    case 'sales_return':
      return 'customerReturn';
    case 'stock_movement':
      // The partner of the move out is the move in, and the other way round; on any other reason the pair is unknown.
      return m.reason === 'transfer_out' ? 'matchingMoveIn' : m.reason === 'transfer_in' ? 'matchingMoveOut' : 'other';
    default:
      return 'other';
  }
}

/** The one rendering of a movement's source (list cell and movement page alike). */
export function sourceOf(m: Pick<StockMovement, 'source_type' | 'source_id' | 'reason'>, gates: SourceGates): Source {
  const word = sourceWord(m);
  const linked = m.source_type !== null && Object.hasOwn(LINKED_SOURCES, m.source_type) ? LINKED_SOURCES[m.source_type] : undefined;
  if (word !== 'other' && linked !== undefined && gates[linked.gate] && m.source_id !== null && isRecordId(m.source_id)) {
    return { kind: 'link', href: `/${linked.segment}/${m.source_id}`, word };
  }
  return { kind: 'text', word };
}

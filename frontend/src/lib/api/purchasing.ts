// Hand-mirrored from `backend/src/Modules/Purchasing/ApiResource/` (served schemas `PurchaseOrder`, `PurchaseOrderLine`,
// `NamedReference`, `ActorReference` in `contracts/openapi.json`). Checked both ways by C-1
// (`test/contract/wireParity.test.ts`; names only). This file imports nothing (A-18). Wire field names stay snake_case,
// as served. Purchase orders PO-1 mirrors the three shapes the list and the read-only order page read
// (purchase-orders-plan.md §3; Revision 10 R10-2: one `purchasing` family for the order and receipt folders); each later
// commit adds the input it first sends. It also holds the served enum of `ListPurchaseOrders`' `status` parameter (the
// list's segments, Revision 10 R10-1), so copy may name them (copy imports only lib/api).

/** `PurchaseOrder.supplier` / `.warehouse` (served `NamedReference`, PC-15 display fields): the record's id and name. */
export interface NamedReference {
  id: string;
  name: string;
}

/** `PurchaseOrder.created_by` (served `ActorReference`): who raised the order (O-4: visible to `purchase_order.view`). */
export interface ActorReference {
  id: string;
  display_name: string;
}

/**
 * `GetPurchaseOrder` / `ListPurchaseOrders` item. `status` is a plain string (no enum is served; five values from the
 * CHECK, labelled from `copy/purchasing.ts` with the raw value as fallback). `total_amount` is a decimal string (scale 4),
 * never a number (QR-1; rendered by `lib/format/money.ts`). `ordered_at` is null until approved, and on an order
 * cancelled as a draft; `expected_at` is the buyer's one date for the order, or null.
 */
export interface PurchaseOrder {
  id: string;
  order_number: string;
  status: string;
  supplier: NamedReference;
  warehouse: NamedReference;
  currency_code: string;
  total_amount: string;
  ordered_at: string | null;
  expected_at: string | null;
  created_by: ActorReference | null;
  archived_at: string | null;
}

/** `PurchaseOrderLine.inventory_item` (served inline, no component): the item's id, SKU and name. No unit (BK-33). */
export interface PurchaseOrderItemReference {
  id: string;
  sku: string;
  name: string;
}

/**
 * One order line (served `PurchaseOrderLine`, the item of `ListPurchaseOrderLines`). The three quantities are decimal
 * strings (scale 6, rendered by `lib/format/quantity.ts`) and `unit_cost` a decimal string (scale 4, `lib/format/money.ts`
 * in the order's currency): never numbers. `quantity_outstanding` is served as ordered − received with no status clause,
 * so on a cancelled order it is what was never received (D-3: relabelled, never recomputed).
 */
export interface PurchaseOrderLine {
  id: string;
  line_number: number;
  inventory_item: PurchaseOrderItemReference;
  quantity: string;
  quantity_received: string;
  quantity_outstanding: string;
  unit_cost: string;
  archived_at: string | null;
}

/**
 * The live segments of `ListPurchaseOrders` (the `status` query parameter's served enum; IA TB-4; UX B-1), in the order
 * of the segment control. Held equal to the served enum by `test/wired/purchase-orders-list.test.tsx`, so no live read
 * can be refused `unknown_segment`. The first is the default: the address omits it (`?status=` only for the others).
 */
export const PURCHASE_ORDER_SEGMENTS = ['open', 'draft', 'received', 'cancelled'] as const;
export type PurchaseOrderSegment = (typeof PURCHASE_ORDER_SEGMENTS)[number];
export const isPurchaseOrderSegment = (x: unknown): x is PurchaseOrderSegment => typeof x === 'string' && (PURCHASE_ORDER_SEGMENTS as readonly string[]).includes(x);

/** One member of `CreatePurchaseOrderInput.lines` (served inline). Mirrored only so the input's names are whole; never sent. */
export interface CreatePurchaseOrderNestedLine {
  inventory_item_id: string;
  quantity: string;
  unit_cost: string;
}

/**
 * `CreatePurchaseOrder`'s body (served `PurchaseOrder.CreatePurchaseOrderInput`; PO-3). The create page sends exactly
 * `supplier_id`, `warehouse_id` and `currency_code` = the chosen supplier's served code (PO D-5 (a)). `expected_at` is not
 * sent while D-4 (which instant a chosen day sends) is unruled, and `lines` NEVER: the draft is created header-first and its
 * lines are added on its page (plan §2.4; a `lines: []` would be refused by the server's minimum of one).
 */
export interface CreatePurchaseOrderInput {
  supplier_id: string;
  warehouse_id: string;
  currency_code?: string | null;
  expected_at?: string | null;
  lines?: CreatePurchaseOrderNestedLine[] | null;
}

/**
 * `UpdatePurchaseOrder`'s body (served `PurchaseOrder.UpdatePurchaseOrderInput.jsonMergePatch`; PO-4a). Mirrored whole so
 * C-1's names-both-ways check holds; the draft's header editor sends exactly the changed `warehouse_id` and nothing else:
 * never `supplier_id` (the supplier is never changed in the app, PO D-6) and never `expected_at` (D-4 unruled). No
 * `currency_code` is served: an order's currency is fixed at create.
 */
export interface UpdatePurchaseOrderInput {
  supplier_id?: string;
  warehouse_id?: string;
  expected_at?: string | null;
}

/**
 * `CreatePurchaseOrderLine`'s body (served `PurchaseOrderLine.CreatePurchaseOrderLineInput`; PO-5). The new line row sends
 * exactly these three members: the chosen catalogue entry's item id, and the quantity and unit cost as typed (QR-1: strings,
 * never numbers). No line number (the server mints it) and no lead time (snapshotted from the catalogue by the server).
 */
export interface CreatePurchaseOrderLineInput {
  inventory_item_id: string;
  quantity: string;
  unit_cost: string;
}

/**
 * The purchase-order refusal rules the family branches on (UX D-2: a status refusal is matched by rule code, the page
 * re-reads and says what it found). `notDraft` (`PurchaseOrderWriter::refuseUnlessDraft`, P9): an edit of an order that
 * is no longer a draft. `notListed` (PO-5, plan L1, `PurchaseOrderWriter::insertLineInside`): the order's supplier has no
 * catalogue entry for the item any more, so the new line row reads the catalogue again. Compared in
 * `lib/purchasing/wired.ts` (convention; not pinned — the roles precedent; po-4a-red.md L-4).
 */
export const PURCHASE_ORDER_REFUSAL = { notDraft: 'purchase_order.is_editable_only_while_draft', notListed: 'purchase_order_line.supplier_lists_the_item' } as const;

/**
 * PO-5 (purchase-orders-final-red.md H-2): the one 422 violation the new line row does not render as served — an item that
 * is archived or not in this workspace (`insertLineInside`'s `unknown_reference` at `/inventory_item_id`, W17/L2, whose
 * served words are developer words until BK-34's BE-P6). The row shows UX's client sentence beside Item instead and reads
 * the catalogue again. Matched on both members in `lib/purchasing/wired.ts`; deleted in the commit that merges BE-P6 back.
 */
export const ITEM_UNAVAILABLE = { pointer: '/inventory_item_id', code: 'unknown_reference' } as const;

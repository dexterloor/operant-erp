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

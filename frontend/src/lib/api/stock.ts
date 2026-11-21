// Hand-mirrored from `backend/src/Modules/Inventory/ApiResource/StockMovementResource.php` (served schema `StockMovement`
// and its two inline references in `contracts/openapi.json`). Checked both ways by C-1 (`test/contract/wireParity.test.ts`;
// names only). This file imports nothing (A-18; Revision 11 R11-1). Wire field names stay snake_case, as served. Stock
// ST-1 (stock-plan.md §1.1) mirrors the one shape the movement pages read; nothing is written from the web app.
// Stock ST-2 (stock-st2-plan.md §4) adds a stock-on-hand row (`StockLevel`, served inline in its collection; mirrored
// from `StockLevelResource.php`). This file never names the stock read: its importers include the movement pages (G-47).

/** `StockMovement.inventory_item` (served inline): the item that moved, by id, SKU and name. No unit (owner Q-A). */
export interface StockMovementItemReference {
  id: string;
  sku: string;
  name: string;
}

/**
 * `StockMovement.storage_location` (served inline): the location the stock moved into or out of, by id and code. No
 * warehouse and no kind are served, and a code is unique only within its warehouse (stock-plan.md §2.7).
 */
export interface StockMovementLocationReference {
  id: string;
  code: string;
}

/**
 * `GetStockMovement` / `ListStockMovements` item. `quantity` is a signed decimal string (scale 6, never zero), never a
 * number (QR-1; rendered by `renderSignedQuantity`). `reason` and `source_type` are plain strings: no enum is served, so
 * every comparison of them lives in `lib/stock/source.ts` and `copy/stock.ts`, with a fallback that never shows the raw
 * value. `source_id` is served bare (stock-reads N-2) and is never shown as text. No unit is served yet (BK-41).
 */
export interface StockMovement {
  id: string;
  quantity: string;
  occurred_at: string;
  reason: string;
  source_type: string | null;
  source_id: string | null;
  inventory_item: StockMovementItemReference;
  storage_location: StockMovementLocationReference;
}

/** A stock-on-hand row's `inventory_item` (served inline): the item stored, by id, SKU and name. No unit (BK-42). */
export interface StockLevelItemReference {
  id: string;
  sku: string;
  name: string;
}

/**
 * A stock-on-hand row's `storage_location` (served inline): the location, by id and code. No kind and no archived state
 * are served on the row: the page reads them from the location lists (G-47).
 */
export interface StockLevelLocationReference {
  id: string;
  code: string;
}

/** A stock-on-hand row's `warehouse` (served inline): by id and name only. No code and no archived state on the row. */
export interface StockLevelWarehouseReference {
  id: string;
  name: string;
}

/**
 * A stock-on-hand row: what is physically in one location for one item. `qty_on_hand` is a decimal string (scale 6),
 * never a number (QR-1; rendered by `renderQuantity`), never "available". No unit is served on the row yet (BK-42).
 */
export interface StockLevel {
  id: string;
  inventory_item: StockLevelItemReference;
  storage_location: StockLevelLocationReference;
  warehouse: StockLevelWarehouseReference;
  qty_on_hand: string;
  version: number;
}

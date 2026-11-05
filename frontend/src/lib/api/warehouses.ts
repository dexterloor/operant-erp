// Hand-mirrored from `backend/src/Modules/Warehouses/ApiResource/WarehouseResource.php` (served schemas `Warehouse` and
// `Warehouse.UpdateWarehouseInput.jsonMergePatch` in `contracts/openapi.json`). Checked both ways by C-1
// (`test/contract/wireParity.test.ts`): a member the server adds or drops fails there before a form can send the wrong
// set. This file imports nothing (A-18). Wire field names stay snake_case, as served. The location shapes and their
// inputs join with the commits that wire their operations (§2 steps 4.4–4.5).

/** `GetWarehouse` / `ListWarehouses` item / `UpdateWarehouse`'s 200 body. Exactly these five (G-36: nothing else is served). */
export interface Warehouse {
  id: string;
  code: string;
  name: string;
  /** Two capital letters, as stored (`GB`); no country name is served (M-2, G-37). */
  country_code: string;
  archived_at: string | null;
}

/**
 * `UpdateWarehouse`'s merge-patch body. Every member is optional; the served schema marks each nullable, but the form
 * never sends `null` (W15: a cleared field is sent as the empty string and refused beside its field).
 */
export interface UpdateWarehouseInput {
  code?: string;
  name?: string;
  country_code?: string;
}

/**
 * `CreateWarehouse`'s body (served schema `Warehouse.CreateWarehouseInput`, §2 step 4.3). The served schema marks each
 * member nullable; the form always sends all three as strings, never `null` (W13: an empty field is refused beside it).
 */
export interface CreateWarehouseInput {
  code?: string;
  name?: string;
  country_code?: string;
}

# Bulk archive and restore

Generated from `contracts/openapi.json` and `contracts/api-surface.json` by `make contracts`. Do not edit by hand.

## Archive in bulk

`POST /api/v1/bulk/archivals` · `CreateBulkArchival`

Archives several records of one kind in one request.

**Who may call it:** The permission depends on `resource`:

| `resource` | Permission |
| --- | --- |
| `customer` | `customer.delete` |
| `inventory_item` | `inventory_item.delete` |
| `purchase_order` | `purchase_order.delete` |
| `sales_order` | `sales_order.delete` |
| `storage_location` | `storage_location.delete` |
| `supplier` | `supplier.delete` |
| `warehouse` | `warehouse.delete` |

**Idempotency-Key:** required: send a fresh key with each new request, and the same key to retry it.

**Request body:** [BulkArchival.CreateBulkArchivalInput](schemas.md#bulkarchivalcreatebulkarchivalinput) (`application/json`)

**Responses**

- **200** How many records were asked for and how many were archived. Which ones, and why the rest were not, is never said. `application/json`
- **Refusals** [401](README.md#401) · [403](README.md#403) · [409](README.md#409) · [422](README.md#422) · [428](README.md#428)

## Restore in bulk

`POST /api/v1/bulk/restorations` · `CreateBulkRestoration`

Restores several archived records of one kind in one request.

**Who may call it:** The permission depends on `resource`:

| `resource` | Permission |
| --- | --- |
| `customer` | `customer.delete` |
| `inventory_item` | `inventory_item.delete` |
| `purchase_order` | `purchase_order.delete` |
| `sales_order` | `sales_order.delete` |
| `storage_location` | `storage_location.delete` |
| `supplier` | `supplier.delete` |
| `warehouse` | `warehouse.delete` |

**Idempotency-Key:** required: send a fresh key with each new request, and the same key to retry it.

**Request body:** [BulkRestoration.CreateBulkRestorationInput](schemas.md#bulkrestorationcreatebulkrestorationinput) (`application/json`)

**Responses**

- **200** How many records were asked for and how many were restored. Which ones, and why the rest were not, is never said. `application/json`
- **Refusals** [401](README.md#401) · [403](README.md#403) · [409](README.md#409) · [422](README.md#422) · [428](README.md#428)

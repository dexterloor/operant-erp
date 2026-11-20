# Warehouses and locations

Generated from `contracts/openapi.json` and `contracts/api-surface.json` by `make contracts`. Do not edit by hand.

## List warehouses

`GET /api/v1/warehouses` · `ListWarehouses`

Lists warehouses.

**Who may call it:** `warehouse.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `archived` | query | boolean | no | Return the archived warehouses instead of the live ones. |
| `q` | query | string | no | Search: a prefix of the `code`, matched exactly as stored. Nothing else is searched. |

**Responses**

- **200** The warehouse list. It is not paged: `next_cursor` and `previous_cursor` are always null. [WarehouseCollection](schemas.md#warehousecollection) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [422](README.md#422)

## Create warehouse

`POST /api/v1/warehouses` · `CreateWarehouse`

Creates one warehouse.

**Who may call it:** `warehouse.create`

**Idempotency-Key:** required: send a fresh key with each new request, and the same key to retry it.

**Request body:** [Warehouse.CreateWarehouseInput](schemas.md#warehousecreatewarehouseinput) (`application/json`) — The warehouse to create.

**Responses**

- **201** The warehouse this request created. `Location` gives its address. [Warehouse](schemas.md#warehouse) (`application/json`) Header `Location`: The address of what this request created.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [409](README.md#409) · [422](README.md#422) · [428](README.md#428)

## Get warehouse

`GET /api/v1/warehouses/{id}` · `GetWarehouse`

Returns one warehouse.

**Who may call it:** `warehouse.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The warehouse's id. |

**Responses**

- **200** The warehouse. [Warehouse](schemas.md#warehouse) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [422](README.md#422)

## Update warehouse

`PATCH /api/v1/warehouses/{id}` · `UpdateWarehouse`

Changes one warehouse.

**Who may call it:** `warehouse.edit`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The warehouse's id. |

**Request body:** [Warehouse.UpdateWarehouseInput](schemas.md#warehouseupdatewarehouseinput) (`application/json`) — Only the fields to change. A field left out is not changed.

**Responses**

- **200** The warehouse, as saved. [Warehouse](schemas.md#warehouse) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Delete warehouse

`DELETE /api/v1/warehouses/{id}` · `DeleteWarehouse`

Archives one warehouse. `RestoreWarehouse` brings it back.

**Who may call it:** `warehouse.delete`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The warehouse's id. |

**Responses**

- **204** Done. The answer has no body.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## List storage locations

`GET /api/v1/warehouses/{id}/locations` · `ListStorageLocations`

Lists storage locations.

**Who may call it:** `storage_location.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The warehouse's id. |
| `archived` | query | boolean | no | Return the archived locations instead of the live ones. |

**Responses**

- **200** The storage location list. It is not paged: `next_cursor` and `previous_cursor` are always null. [StorageLocationCollection](schemas.md#storagelocationcollection) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [422](README.md#422)

## Create storage location

`POST /api/v1/warehouses/{id}/locations` · `CreateStorageLocation`

Creates one storage location.

**Who may call it:** `storage_location.create`

**Idempotency-Key:** required: send a fresh key with each new request, and the same key to retry it.

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The warehouse's id. |

**Request body:** [StorageLocation.CreateStorageLocationInput](schemas.md#storagelocationcreatestoragelocationinput) (`application/json`) — The storage location to create.

**Responses**

- **201** The storage location this request created. `Location` gives its address. [StorageLocation](schemas.md#storagelocation) (`application/json`) Header `Location`: The address of what this request created.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422) · [428](README.md#428)

## Update storage location

`PATCH /api/v1/warehouses/{id}/locations/{locationId}` · `UpdateStorageLocation`

Changes one storage location.

**Who may call it:** `storage_location.edit`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The warehouse's id. |
| `locationId` | path | string (`uuid`) | yes | The location's id. |

**Request body:** [StorageLocation.UpdateStorageLocationInput](schemas.md#storagelocationupdatestoragelocationinput) (`application/json`) — Only the fields to change. A field left out is not changed.

**Responses**

- **200** The storage location, as saved. [StorageLocation](schemas.md#storagelocation) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Delete storage location

`DELETE /api/v1/warehouses/{id}/locations/{locationId}` · `DeleteStorageLocation`

Archives one storage location. `RestoreStorageLocation` brings it back.

**Who may call it:** `storage_location.delete`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The warehouse's id. |
| `locationId` | path | string (`uuid`) | yes | The location's id. |

**Responses**

- **204** Done. The answer has no body.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Restore storage location

`POST /api/v1/warehouses/{id}/locations/{locationId}/restoration` · `RestoreStorageLocation`

Restores one archived storage location.

**Who may call it:** `storage_location.delete`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The warehouse's id. |
| `locationId` | path | string (`uuid`) | yes | The location's id. |

**Responses**

- **200** The storage location, as saved. [StorageLocation](schemas.md#storagelocation) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Restore warehouse

`POST /api/v1/warehouses/{id}/restoration` · `RestoreWarehouse`

Restores one archived warehouse.

**Who may call it:** `warehouse.delete`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The warehouse's id. |

**Responses**

- **200** The warehouse, as saved. [Warehouse](schemas.md#warehouse) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

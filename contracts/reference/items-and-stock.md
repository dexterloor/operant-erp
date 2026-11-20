# Items and stock

Generated from `contracts/openapi.json` and `contracts/api-surface.json` by `make contracts`. Do not edit by hand.

## List items

`GET /api/v1/items` · `ListItems`

Lists items, one page at a time.

**Who may call it:** `inventory_item.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `supplier_id` | query | string (`uuid`) | no | Only the items whose `supplier` is this supplier. |
| `archived` | query | boolean | no | Return the archived items instead of the live ones, most recently archived first. Not with `supplier_id`: sending both is refused 422 `mutually_exclusive`. |
| `q` | query | string | no | Search: a prefix of the `sku`, matched exactly as stored. The `name` is not searched. |
| `limit` | query | integer | no | How many rows to return: 50 if not sent, at most 200. |
| `after` | query | string | no | The `next_cursor` of the previous page, to fetch the next one. Not with `before`. |
| `before` | query | string | no | The `previous_cursor` of a later page, to fetch the one before it. Not with `after`. |

**Responses**

- **200** One page of the item list. `next_cursor` is null on the last page; otherwise send it as `after` for the next page. [ItemCollection](schemas.md#itemcollection) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [422](README.md#422)

## Create item

`POST /api/v1/items` · `CreateItem`

Creates one item.

**Who may call it:** `inventory_item.create`

**Idempotency-Key:** required: send a fresh key with each new request, and the same key to retry it.

**Request body:** [Item.CreateItemInput](schemas.md#itemcreateiteminput) (`application/json`) — The item to create.

**Responses**

- **201** The item this request created. `Location` gives its address. [Item](schemas.md#item) (`application/json`) Header `Location`: The address of what this request created.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [409](README.md#409) · [422](README.md#422) · [428](README.md#428)

## Get item

`GET /api/v1/items/{id}` · `GetItem`

Returns one item.

**Who may call it:** `inventory_item.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The item's id. |

**Responses**

- **200** The item. [Item](schemas.md#item) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [422](README.md#422)

## Update item

`PATCH /api/v1/items/{id}` · `UpdateItem`

Changes one item.

**Who may call it:** `inventory_item.edit`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The item's id. |

**Request body:** [Item.UpdateItemInput](schemas.md#itemupdateiteminput) (`application/json`) — Only the fields to change. A field left out is not changed.

**Responses**

- **200** The item, as saved. [Item](schemas.md#item) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Delete item

`DELETE /api/v1/items/{id}` · `DeleteItem`

Archives one item. `RestoreItem` brings it back.

**Who may call it:** `inventory_item.delete`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The item's id. |

**Responses**

- **204** Done. The answer has no body.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Restore item

`POST /api/v1/items/{id}/restoration` · `RestoreItem`

Restores one archived item.

**Who may call it:** `inventory_item.delete`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The item's id. |

**Responses**

- **200** The item, as saved. [Item](schemas.md#item) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## List stock levels

`GET /api/v1/stock` · `ListStockLevels`

Lists stock levels.

**Who may call it:** `stock_level.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `storage_location_id` | query | string (`uuid`) | no | One location's stock, ordered by item SKU. Send exactly one of `storage_location_id` and `inventory_item_id`. A location this workspace cannot see matches nothing: the answer is an empty page, never a 404. |
| `inventory_item_id` | query | string (`uuid`) | no | One item's stock in each location, ordered by location code, with each location's warehouse. Send exactly one of `storage_location_id` and `inventory_item_id`. An item this workspace cannot see matches nothing: the answer is an empty page, never a 404. |

**Responses**

- **200** The stock level list. It is not paged: `next_cursor` and `previous_cursor` are always null. [StockLevelCollection](schemas.md#stocklevelcollection) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [422](README.md#422)

## List stock movements

`GET /api/v1/stock-movements` · `ListStockMovements`

Lists stock movements, one page at a time.

**Who may call it:** `stock_movement.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `inventory_item_id` | query | string (`uuid`) | no | Only one item's movements, newest first. An item this workspace cannot see matches nothing: the answer is an empty page, never a 404. |
| `limit` | query | integer | no | How many rows to return: 50 if not sent, at most 200. |
| `after` | query | string | no | The `next_cursor` of the previous page, to fetch the next one. Not with `before`. |
| `before` | query | string | no | The `previous_cursor` of a later page, to fetch the one before it. Not with `after`. |

**Responses**

- **200** One page of the stock movement list. `next_cursor` is null on the last page; otherwise send it as `after` for the next page. [StockMovementCollection](schemas.md#stockmovementcollection) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [422](README.md#422)

## Record a stock count

`POST /api/v1/stock-movements/adjustments` · `CreateStockAdjustment`

Counts one item in one location. If the count differs from the stock on record, the difference is recorded as a movement; a count that matches is refused.

**Who may call it:** `stock_movement.adjust`

**Idempotency-Key:** required: send a fresh key with each new request, and the same key to retry it.

**Request body:** [StockMovement.CreateStockAdjustmentInput](schemas.md#stockmovementcreatestockadjustmentinput) (`application/json`) — The count to record, and the version of the stock it was counted against.

**Responses**

- **201** The stock movement this request created. `Location` gives its address. [StockMovement](schemas.md#stockmovement) (`application/json`) Header `Location`: The address of what this request created.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [409](README.md#409) · [422](README.md#422) · [428](README.md#428)

## Transfer stock

`POST /api/v1/stock-movements/transfers` · `CreateStockTransfer`

Moves stock of one item from one location to another, recording a movement out and a movement in.

**Who may call it:** `stock_movement.adjust`

**Idempotency-Key:** required: send a fresh key with each new request, and the same key to retry it.

**Request body:** [StockMovement.CreateStockTransferInput](schemas.md#stockmovementcreatestocktransferinput) (`application/json`) — The item, how much of it, and the locations to move it from and to.

**Responses**

- **201** The movement that took the stock out of the source location. The matching movement into the destination location was recorded with it. `Location` gives the outgoing movement's address. [StockMovement](schemas.md#stockmovement) (`application/json`) Header `Location`: The address of what this request created.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [409](README.md#409) · [422](README.md#422) · [428](README.md#428)

## Get stock movement

`GET /api/v1/stock-movements/{id}` · `GetStockMovement`

Returns one stock movement.

**Who may call it:** `stock_movement.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The stock movement's id. |

**Responses**

- **200** The stock movement. [StockMovement](schemas.md#stockmovement) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [422](README.md#422)

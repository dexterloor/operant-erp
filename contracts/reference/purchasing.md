# Purchasing

Generated from `contracts/openapi.json` and `contracts/api-surface.json` by `make contracts`. Do not edit by hand.

## List goods receipts

`GET /api/v1/goods-receipts` · `ListGoodsReceipts`

Lists goods receipts, one page at a time.

**Who may call it:** `goods_receipt.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `purchase_order_id` | query | string (`uuid`) | no | Only one purchase order's receipts, newest receipt first. An order this workspace cannot see matches nothing: the answer is an empty page, never a 404. |
| `q` | query | string | no | Search: a prefix of the `receipt_number`. Nothing else is searched. |
| `limit` | query | integer | no | How many rows to return: 50 if not sent, at most 200. |
| `after` | query | string | no | The `next_cursor` of the previous page, to fetch the next one. Not with `before`. |
| `before` | query | string | no | The `previous_cursor` of a later page, to fetch the one before it. Not with `after`. |

**Responses**

- **200** One page of the goods receipt list. `next_cursor` is null on the last page; otherwise send it as `after` for the next page. [GoodsReceiptCollection](schemas.md#goodsreceiptcollection) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [422](README.md#422)

## Get goods receipt

`GET /api/v1/goods-receipts/{id}` · `GetGoodsReceipt`

Returns one goods receipt.

**Who may call it:** `goods_receipt.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The goods receipt's id. |

**Responses**

- **200** The goods receipt. [GoodsReceipt](schemas.md#goodsreceipt) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [422](README.md#422)

## List purchase orders

`GET /api/v1/purchase-orders` · `ListPurchaseOrders`

Lists purchase orders, one page at a time.

**Who may call it:** `purchase_order.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `status` | query | string: `open`, `draft`, `received`, `cancelled` | no | Which orders to list. `open` covers `ordered` and `partially_received`. |
| `supplier_id` | query | string (`uuid`) | no | Only one supplier's orders. |
| `archived` | query | boolean | no | Return the archived (deleted) orders instead of the live ones, most recently archived first. Not with `status` or `supplier_id`: sending either is refused 422 `mutually_exclusive`. |
| `q` | query | string | no | Search: a prefix of the `order_number`. Nothing else is searched. |
| `sort` | query | string: `order_number`, `ordered_at`, `archived_at` | no | The order. `order_number` is always allowed. `ordered_at` is allowed only with `status=open`, or with `supplier_id` together with `status=open` or `status=received`; where it is allowed it is the default, elsewhere `order_number` is. With `archived=true`, only `archived_at`, newest first. |
| `limit` | query | integer | no | How many rows to return: 50 if not sent, at most 200. |
| `after` | query | string | no | The `next_cursor` of the previous page, to fetch the next one. Not with `before`. |
| `before` | query | string | no | The `previous_cursor` of a later page, to fetch the one before it. Not with `after`. |

**Responses**

- **200** One page of the purchase order list. `next_cursor` is null on the last page; otherwise send it as `after` for the next page. [PurchaseOrderCollection](schemas.md#purchaseordercollection) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [422](README.md#422)

## Create purchase order

`POST /api/v1/purchase-orders` · `CreatePurchaseOrder`

Creates one purchase order.

**Who may call it:** `purchase_order.create`

**Idempotency-Key:** required: send a fresh key with each new request, and the same key to retry it.

**Request body:** [PurchaseOrder.CreatePurchaseOrderInput](schemas.md#purchaseordercreatepurchaseorderinput) (`application/json`) — The purchase order to create.

**Responses**

- **201** The purchase order this request created. `Location` gives its address. [PurchaseOrder](schemas.md#purchaseorder) (`application/json`) Header `Location`: The address of what this request created.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [409](README.md#409) · [422](README.md#422) · [428](README.md#428)

## Get purchase order

`GET /api/v1/purchase-orders/{id}` · `GetPurchaseOrder`

Returns one purchase order.

**Who may call it:** `purchase_order.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The purchase order's id. |

**Responses**

- **200** The purchase order. [PurchaseOrder](schemas.md#purchaseorder) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [422](README.md#422)

## Update purchase order

`PATCH /api/v1/purchase-orders/{id}` · `UpdatePurchaseOrder`

Changes one purchase order.

**Who may call it:** `purchase_order.edit`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The purchase order's id. |

**Request body:** [PurchaseOrder.UpdatePurchaseOrderInput](schemas.md#purchaseorderupdatepurchaseorderinput) (`application/json`) — Only the fields to change. A field left out is not changed.

**Responses**

- **200** The purchase order, as saved. [PurchaseOrder](schemas.md#purchaseorder) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Delete purchase order

`DELETE /api/v1/purchase-orders/{id}` · `DeletePurchaseOrder`

Archives one purchase order. `RestorePurchaseOrder` brings it back.

**Who may call it:** `purchase_order.delete`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The purchase order's id. |

**Responses**

- **204** Done. The answer has no body.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Approve purchase order

`POST /api/v1/purchase-orders/{id}/approval` · `ApprovePurchaseOrder`

Approves one purchase order.

**Who may call it:** `purchase_order.approve`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The purchase order's id. |

**Request body:** [PurchaseOrder.ApprovePurchaseOrderInput](schemas.md#purchaseorderapprovepurchaseorderinput) (`application/json`)

**Responses**

- **200** The purchase order, as saved. [PurchaseOrder](schemas.md#purchaseorder) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Cancel purchase order

`POST /api/v1/purchase-orders/{id}/cancellation` · `CancelPurchaseOrder`

Cancels one purchase order.

**Who may call it:** `purchase_order.cancel`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The purchase order's id. |

**Request body:** [PurchaseOrder.CancelPurchaseOrderInput](schemas.md#purchaseordercancelpurchaseorderinput) (`application/json`)

**Responses**

- **200** The purchase order, as saved. [PurchaseOrder](schemas.md#purchaseorder) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Create goods receipt

`POST /api/v1/purchase-orders/{id}/goods-receipts` · `CreateGoodsReceipt`

Creates one goods receipt.

**Who may call it:** `goods_receipt.create`

**Idempotency-Key:** required: send a fresh key with each new request, and the same key to retry it.

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The purchase order's id. |

**Request body:** [GoodsReceipt.CreateGoodsReceiptInput](schemas.md#goodsreceiptcreategoodsreceiptinput) (`application/json`) — The goods receipt to create.

**Responses**

- **201** The goods receipt this request created. `Location` gives its address. [GoodsReceipt](schemas.md#goodsreceipt) (`application/json`) Header `Location`: The address of what this request created.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422) · [428](README.md#428)

## List purchase order lines

`GET /api/v1/purchase-orders/{id}/lines` · `ListPurchaseOrderLines`

Lists purchase order lines.

**Who may call it:** `purchase_order.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The purchase order's id. |
| `archived` | query | boolean | no | Return the archived lines instead of the live ones. |

**Responses**

- **200** The purchase order line list. It is not paged: `next_cursor` and `previous_cursor` are always null. [PurchaseOrderLineCollection](schemas.md#purchaseorderlinecollection) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [422](README.md#422)

## Create purchase order line

`POST /api/v1/purchase-orders/{id}/lines` · `CreatePurchaseOrderLine`

Creates one purchase order line.

**Who may call it:** `purchase_order.edit`

**Idempotency-Key:** required: send a fresh key with each new request, and the same key to retry it.

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The purchase order's id. |

**Request body:** [PurchaseOrderLine.CreatePurchaseOrderLineInput](schemas.md#purchaseorderlinecreatepurchaseorderlineinput) (`application/json`) — The purchase order line to create.

**Responses**

- **201** The purchase order line this request created. `Location` gives its address. [PurchaseOrderLine](schemas.md#purchaseorderline) (`application/json`) Header `Location`: The address of what this request created.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422) · [428](README.md#428)

## Update purchase order line

`PATCH /api/v1/purchase-orders/{id}/lines/{lineId}` · `UpdatePurchaseOrderLine`

Changes one purchase order line.

**Who may call it:** `purchase_order.edit`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The purchase order's id. |
| `lineId` | path | string (`uuid`) | yes | The line's id. |

**Request body:** [PurchaseOrderLine.UpdatePurchaseOrderLineInput](schemas.md#purchaseorderlineupdatepurchaseorderlineinput) (`application/json`) — Only the fields to change. A field left out is not changed.

**Responses**

- **200** The purchase order line, as saved. [PurchaseOrderLine](schemas.md#purchaseorderline) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Delete purchase order line

`DELETE /api/v1/purchase-orders/{id}/lines/{lineId}` · `DeletePurchaseOrderLine`

Archives one purchase order line. `RestorePurchaseOrderLine` brings it back.

**Who may call it:** `purchase_order.edit`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The purchase order's id. |
| `lineId` | path | string (`uuid`) | yes | The line's id. |

**Responses**

- **204** Done. The answer has no body.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Restore purchase order line

`POST /api/v1/purchase-orders/{id}/lines/{lineId}/restoration` · `RestorePurchaseOrderLine`

Restores one archived purchase order line.

**Who may call it:** `purchase_order.edit`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The purchase order's id. |
| `lineId` | path | string (`uuid`) | yes | The line's id. |

**Responses**

- **200** The purchase order line, as saved. [PurchaseOrderLine](schemas.md#purchaseorderline) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Restore purchase order

`POST /api/v1/purchase-orders/{id}/restoration` · `RestorePurchaseOrder`

Restores one archived purchase order.

**Who may call it:** `purchase_order.delete`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The purchase order's id. |

**Responses**

- **200** The purchase order, as saved. [PurchaseOrder](schemas.md#purchaseorder) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

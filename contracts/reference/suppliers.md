# Suppliers

Generated from `contracts/openapi.json` and `contracts/api-surface.json` by `make contracts`. Do not edit by hand.

## List suppliers

`GET /api/v1/suppliers` · `ListSuppliers`

Lists suppliers, one page at a time.

**Who may call it:** `supplier.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `archived` | query | boolean | no | Return the archived suppliers instead of the live ones, most recently archived first. |
| `q` | query | string | no | Search: a prefix of the `name`, ignoring case, or a prefix of the `code`, matched exactly as stored. |
| `sort` | query | string: `name`, `code`, `archived_at` | no | The order. Live suppliers: `name` (the default) or `code`. Archived suppliers: only `archived_at`, newest first. |
| `limit` | query | integer | no | How many rows to return: 50 if not sent, at most 200. |
| `after` | query | string | no | The `next_cursor` of the previous page, to fetch the next one. Not with `before`. |
| `before` | query | string | no | The `previous_cursor` of a later page, to fetch the one before it. Not with `after`. |

**Responses**

- **200** One page of the supplier list. `next_cursor` is null on the last page; otherwise send it as `after` for the next page. [SupplierCollection](schemas.md#suppliercollection) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [422](README.md#422)

## Create supplier

`POST /api/v1/suppliers` · `CreateSupplier`

Creates one supplier.

**Who may call it:** `supplier.create`

**Idempotency-Key:** required: send a fresh key with each new request, and the same key to retry it.

**Request body:** [Supplier.CreateSupplierInput](schemas.md#suppliercreatesupplierinput) (`application/json`) — The supplier to create.

**Responses**

- **201** The supplier this request created. `Location` gives its address. [Supplier](schemas.md#supplier) (`application/json`) Header `Location`: The address of what this request created.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [409](README.md#409) · [422](README.md#422) · [428](README.md#428)

## Get supplier

`GET /api/v1/suppliers/{id}` · `GetSupplier`

Returns one supplier.

**Who may call it:** `supplier.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The supplier's id. |

**Responses**

- **200** The supplier. [Supplier](schemas.md#supplier) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [422](README.md#422)

## Update supplier

`PATCH /api/v1/suppliers/{id}` · `UpdateSupplier`

Changes one supplier.

**Who may call it:** `supplier.edit`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The supplier's id. |

**Request body:** [Supplier.UpdateSupplierInput](schemas.md#supplierupdatesupplierinput) (`application/json`) — Only the fields to change. A field left out is not changed.

**Responses**

- **200** The supplier, as saved. [Supplier](schemas.md#supplier) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Delete supplier

`DELETE /api/v1/suppliers/{id}` · `DeleteSupplier`

Archives one supplier. `RestoreSupplier` brings it back.

**Who may call it:** `supplier.delete`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The supplier's id. |

**Responses**

- **204** Done. The answer has no body.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## List supplier products

`GET /api/v1/suppliers/{id}/products` · `ListSupplierProducts`

Lists supplier products.

**Who may call it:** `supplier_product.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The supplier's id. |

**Responses**

- **200** The supplier product list. It is not paged: `next_cursor` and `previous_cursor` are always null. [SupplierProductCollection](schemas.md#supplierproductcollection) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [422](README.md#422)

## Create supplier product

`POST /api/v1/suppliers/{id}/products` · `CreateSupplierProduct`

Creates one supplier product.

**Who may call it:** `supplier_product.edit`

**Idempotency-Key:** required: send a fresh key with each new request, and the same key to retry it.

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The supplier's id. |

**Request body:** [SupplierProduct.CreateSupplierProductInput](schemas.md#supplierproductcreatesupplierproductinput) (`application/json`) — The supplier product to create.

**Responses**

- **201** The supplier product this request created. `Location` gives its address. [SupplierProduct](schemas.md#supplierproduct) (`application/json`) Header `Location`: The address of what this request created.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422) · [428](README.md#428)

## Update supplier product

`PATCH /api/v1/suppliers/{id}/products/{productId}` · `UpdateSupplierProduct`

Changes one supplier product.

**Who may call it:** `supplier_product.edit`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The supplier's id. |
| `productId` | path | string (`uuid`) | yes | The catalogue entry's id. |

**Request body:** [SupplierProduct.UpdateSupplierProductInput](schemas.md#supplierproductupdatesupplierproductinput) (`application/json`) — Only the fields to change. A field left out is not changed.

**Responses**

- **200** The supplier product, as saved. [SupplierProduct](schemas.md#supplierproduct) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Delete supplier product

`DELETE /api/v1/suppliers/{id}/products/{productId}` · `DeleteSupplierProduct`

Removes one supplier's catalogue entry for good.

**Who may call it:** `supplier_product.edit`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The supplier's id. |
| `productId` | path | string (`uuid`) | yes | The catalogue entry's id. |

**Responses**

- **204** Done. The answer has no body.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Restore supplier

`POST /api/v1/suppliers/{id}/restoration` · `RestoreSupplier`

Restores one archived supplier.

**Who may call it:** `supplier.delete`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The supplier's id. |

**Responses**

- **200** The supplier, as saved. [Supplier](schemas.md#supplier) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

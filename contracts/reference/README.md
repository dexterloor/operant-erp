# API reference

Generated from `contracts/openapi.json` and `contracts/api-surface.json` by `make contracts`. Do not edit by hand.

## About this API

The JSON API of Operant ERP. Every path starts with `/api/v1`. Sign in with `IssueToken` (`POST /api/v1/auth/token`) and send the `access_token` it returns as `Authorization: Bearer <token>` on every operation outside `/api/v1/auth/`. Outside `/api/v1/auth/`, the token decides which workspace a request acts in, and no request names one. Each operation lists the refusals it can answer. An operation that lists 428 needs an `Idempotency-Key` header.

Any request can also be refused for how it was sent rather than what it asks: 405 when the address does not serve that method (`allow` and the `Allow` header list the methods it does), 406 when the `Accept` header excludes the operation's format (`application/json` for every operation except the export, which is `text/csv`), and 415 when a body is sent with a `Content-Type` other than `application/json`. These three, like every refusal listed on an operation, are sent as `application/problem+json`. An `Accept` that names only `application/problem+json` is itself answered 406, so name the operation's format as well: `Accept: application/json, application/problem+json`, or `Accept: text/csv, application/problem+json` for the export. One refusal is outside that shape: a request whose `Host` header is malformed is refused 400 before any operation runs. None of these is listed on each operation.

## Signing in

The `access_token` that `IssueToken` returns, sent as `Authorization: Bearer <token>`. It stops working at its `expires_at`, or sooner, for example if the account is deactivated or deleted, or its password is changed or reset.

These operations take no token:

- [Request a password reset](users-and-sign-in.md#request-a-password-reset)
- [Set a new password](users-and-sign-in.md#set-a-new-password)
- [Sign in](users-and-sign-in.md#sign-in)

## Refusals

Every refusal is sent as `application/problem+json` in the shape below. Each operation lists the statuses it can answer; where an operation says nothing more, the sentence here is what that status means.

**[Problem](schemas.md#problem)** — A refusal (RFC 9457 problem details). `type`, `title` and `status` are always present; each other member is present only on the refusals that set it.

| Field | Type | Description |
| --- | --- | --- |
| `allow` | string | On 405: the methods this address serves. |
| `current` | object | On a 409 about stock figures that changed: the figures as they are now. |
| `detail` | string | A sentence about this refusal, when there is one. |
| `errors` | array of [ProblemFieldError](schemas.md#problemfielderror) | On 422: what was refused. |
| `message` | string | On a 409 refused by a rule: the rule in plain words, fit to show a person. |
| `required_permission` | string | On 403: the permission the request needed. |
| `rule` | string | On a 409 refused by a rule: the rule's name, which stays the same if its wording changes. |
| `status` | integer | The HTTP status, repeated. |
| `title` | string | A short summary of the kind, the same for every refusal of that kind. |
| `type` | string: `urn:erp:problem:validation-failed`, `urn:erp:problem:business-rule-violated`, `urn:erp:problem:permission-denied`, `urn:erp:problem:not-found`, `urn:erp:problem:stale-record`, `urn:erp:problem:too-many-attempts`, `urn:erp:problem:sign-in-paused`, `urn:erp:problem:authentication-failed`, `urn:erp:problem:idempotency-key-conflict`, `urn:erp:problem:idempotency-key-required`, `urn:erp:problem:ai-capacity-exhausted`, `urn:erp:problem:unsupported-media-type`, `urn:erp:problem:not-acceptable`, `urn:erp:problem:method-not-allowed` | Which kind of refusal this is. Read this, not `title`. |

**[ProblemFieldError](schemas.md#problemfielderror)** — One field defect.

| Field | Type | Description |
| --- | --- | --- |
| `code` | string | A short name for the problem that does not change. |
| `message` | string | A sentence fit to show a person. |
| `pointer` | string | Where the problem is: a JSON pointer into the body (`/name`, or an empty string for the whole body), `?` and a name for a query parameter (`?limit`), or `#` and a name for a header (`#Idempotency-Key`). |

### 401

No usable sign-in. The bearer token is missing, not valid or expired, or is no longer accepted, for example because the account was deactivated or deleted, or its password was changed or reset after the token was issued. A new token comes from signing in (`IssueToken`).

### 403

The signed-in account does not hold the permission this request needs. `required_permission` names it.

### 404

Not found: what this address names does not exist in this workspace. An archived record is still found.

### 409

The request was not carried out. A business rule does not allow it now (for example because of the record's current state), or another change to the same data happened at the same moment, in which case trying again may work. `rule` names the reason and `message` says it in plain words. If this operation also lists 428, a 409 can instead mean the `Idempotency-Key` was already used for a different request. If its request body has a `version`, a 409 can instead mean the figures changed since they were read; `current` then holds the new ones. Neither of these two carries `rule` or `message`.

### 422

Something in the request cannot be accepted as sent. `errors` lists what was refused, each with where it is (`pointer`), a code (`code`) and a sentence (`message`). It may not list every problem at once.

### 428

This request needs an `Idempotency-Key` header, and it was missing or empty. The key lets the request be sent again safely: a repeat with the same key and the same body is not carried out twice.

### 429

Too many attempts from this network. This limit is shared with signing in. Wait before trying again; no wait time is given.

## Paging and retries

- `limit`: How many rows to return: 50 if not sent, at most 200.
- `after`: The `next_cursor` of the previous page, to fetch the next one. Not with `before`.
- `before`: The `previous_cursor` of a later page, to fetch the one before it. Not with `after`.
- `Idempotency-Key`: This request needs an `Idempotency-Key` header, and it was missing or empty. The key lets the request be sent again safely: a repeat with the same key and the same body is not carried out twice.

A `PATCH` body holds only the fields to change: a field left out is not changed. The spec names each such body's schema with a `.jsonMergePatch` suffix; this reference leaves the suffix out.

## Operations

### Audit log and export

Page: [audit-log-and-export.md](audit-log-and-export.md#audit-log-and-export)

| Operation | Method and path | Permission |
| --- | --- | --- |
| [List audit log entries](audit-log-and-export.md#list-audit-log-entries) | `GET /api/v1/audit-log` | `audit_log.view` |
| [List audit records](audit-log-and-export.md#list-audit-records) | `GET /api/v1/audit-log/records` | `audit_log.view_record` |
| [Export a list](audit-log-and-export.md#export-a-list) | `POST /api/v1/exports` | The permission depends on `resource`: |

### Bulk archive and restore

Page: [bulk.md](bulk.md#bulk-archive-and-restore)

| Operation | Method and path | Permission |
| --- | --- | --- |
| [Archive in bulk](bulk.md#archive-in-bulk) | `POST /api/v1/bulk/archivals` | The permission depends on `resource`: |
| [Restore in bulk](bulk.md#restore-in-bulk) | `POST /api/v1/bulk/restorations` | The permission depends on `resource`: |

### Customers

Page: [customers.md](customers.md#customers)

| Operation | Method and path | Permission |
| --- | --- | --- |
| [List customers](customers.md#list-customers) | `GET /api/v1/customers` | `customer.view` |
| [Create customer](customers.md#create-customer) | `POST /api/v1/customers` | `customer.create` |
| [Get customer](customers.md#get-customer) | `GET /api/v1/customers/{id}` | `customer.view` |
| [Update customer](customers.md#update-customer) | `PATCH /api/v1/customers/{id}` | `customer.edit` |
| [Delete customer](customers.md#delete-customer) | `DELETE /api/v1/customers/{id}` | `customer.delete` |
| [List customer addresses](customers.md#list-customer-addresses) | `GET /api/v1/customers/{id}/addresses` | `customer.view` |
| [Create customer address](customers.md#create-customer-address) | `POST /api/v1/customers/{id}/addresses` | `customer.edit` |
| [Update customer address](customers.md#update-customer-address) | `PATCH /api/v1/customers/{id}/addresses/{addressId}` | `customer.edit` |
| [Delete customer address](customers.md#delete-customer-address) | `DELETE /api/v1/customers/{id}/addresses/{addressId}` | `customer.edit` |
| [List customer contacts](customers.md#list-customer-contacts) | `GET /api/v1/customers/{id}/contacts` | `customer.view` |
| [Create customer contact](customers.md#create-customer-contact) | `POST /api/v1/customers/{id}/contacts` | `customer.edit` |
| [Update customer contact](customers.md#update-customer-contact) | `PATCH /api/v1/customers/{id}/contacts/{contactId}` | `customer.edit` |
| [Delete customer contact](customers.md#delete-customer-contact) | `DELETE /api/v1/customers/{id}/contacts/{contactId}` | `customer.edit` |
| [Restore customer](customers.md#restore-customer) | `POST /api/v1/customers/{id}/restoration` | `customer.delete` |

### Items and stock

Page: [items-and-stock.md](items-and-stock.md#items-and-stock)

| Operation | Method and path | Permission |
| --- | --- | --- |
| [List items](items-and-stock.md#list-items) | `GET /api/v1/items` | `inventory_item.view` |
| [Create item](items-and-stock.md#create-item) | `POST /api/v1/items` | `inventory_item.create` |
| [Get item](items-and-stock.md#get-item) | `GET /api/v1/items/{id}` | `inventory_item.view` |
| [Update item](items-and-stock.md#update-item) | `PATCH /api/v1/items/{id}` | `inventory_item.edit` |
| [Delete item](items-and-stock.md#delete-item) | `DELETE /api/v1/items/{id}` | `inventory_item.delete` |
| [Restore item](items-and-stock.md#restore-item) | `POST /api/v1/items/{id}/restoration` | `inventory_item.delete` |
| [List stock levels](items-and-stock.md#list-stock-levels) | `GET /api/v1/stock` | `stock_level.view` |
| [List stock movements](items-and-stock.md#list-stock-movements) | `GET /api/v1/stock-movements` | `stock_movement.view` |
| [Record a stock count](items-and-stock.md#record-a-stock-count) | `POST /api/v1/stock-movements/adjustments` | `stock_movement.adjust` |
| [Transfer stock](items-and-stock.md#transfer-stock) | `POST /api/v1/stock-movements/transfers` | `stock_movement.adjust` |
| [Get stock movement](items-and-stock.md#get-stock-movement) | `GET /api/v1/stock-movements/{id}` | `stock_movement.view` |

### Purchasing

Page: [purchasing.md](purchasing.md#purchasing)

| Operation | Method and path | Permission |
| --- | --- | --- |
| [List goods receipts](purchasing.md#list-goods-receipts) | `GET /api/v1/goods-receipts` | `goods_receipt.view` |
| [Get goods receipt](purchasing.md#get-goods-receipt) | `GET /api/v1/goods-receipts/{id}` | `goods_receipt.view` |
| [List purchase orders](purchasing.md#list-purchase-orders) | `GET /api/v1/purchase-orders` | `purchase_order.view` |
| [Create purchase order](purchasing.md#create-purchase-order) | `POST /api/v1/purchase-orders` | `purchase_order.create` |
| [Get purchase order](purchasing.md#get-purchase-order) | `GET /api/v1/purchase-orders/{id}` | `purchase_order.view` |
| [Update purchase order](purchasing.md#update-purchase-order) | `PATCH /api/v1/purchase-orders/{id}` | `purchase_order.edit` |
| [Delete purchase order](purchasing.md#delete-purchase-order) | `DELETE /api/v1/purchase-orders/{id}` | `purchase_order.delete` |
| [Approve purchase order](purchasing.md#approve-purchase-order) | `POST /api/v1/purchase-orders/{id}/approval` | `purchase_order.approve` |
| [Cancel purchase order](purchasing.md#cancel-purchase-order) | `POST /api/v1/purchase-orders/{id}/cancellation` | `purchase_order.cancel` |
| [Create goods receipt](purchasing.md#create-goods-receipt) | `POST /api/v1/purchase-orders/{id}/goods-receipts` | `goods_receipt.create` |
| [List purchase order lines](purchasing.md#list-purchase-order-lines) | `GET /api/v1/purchase-orders/{id}/lines` | `purchase_order.view` |
| [Create purchase order line](purchasing.md#create-purchase-order-line) | `POST /api/v1/purchase-orders/{id}/lines` | `purchase_order.edit` |
| [Update purchase order line](purchasing.md#update-purchase-order-line) | `PATCH /api/v1/purchase-orders/{id}/lines/{lineId}` | `purchase_order.edit` |
| [Delete purchase order line](purchasing.md#delete-purchase-order-line) | `DELETE /api/v1/purchase-orders/{id}/lines/{lineId}` | `purchase_order.edit` |
| [Restore purchase order line](purchasing.md#restore-purchase-order-line) | `POST /api/v1/purchase-orders/{id}/lines/{lineId}/restoration` | `purchase_order.edit` |
| [Restore purchase order](purchasing.md#restore-purchase-order) | `POST /api/v1/purchase-orders/{id}/restoration` | `purchase_order.delete` |

### Reorder forecast

Page: [reorder-forecast.md](reorder-forecast.md#reorder-forecast)

| Operation | Method and path | Permission |
| --- | --- | --- |
| [List reorder suggestions](reorder-forecast.md#list-reorder-suggestions) | `GET /api/v1/forecast/reorder` | `forecast.view`, `inventory_item.view`, `purchase_order.view`, `stock_level.view`, `supplier.view`, `supplier_product.view` |
| [Get reorder summary](reorder-forecast.md#get-reorder-summary) | `GET /api/v1/forecast/reorder/summary` | `forecast.view` |

### Sales

Page: [sales.md](sales.md#sales)

| Operation | Method and path | Permission |
| --- | --- | --- |
| [List credit notes](sales.md#list-credit-notes) | `GET /api/v1/credit-notes` | `invoice.view` |
| [Get credit note](sales.md#get-credit-note) | `GET /api/v1/credit-notes/{id}` | `invoice.view` |
| [List fulfilments](sales.md#list-fulfilments) | `GET /api/v1/fulfilments` | `fulfilment.view` |
| [Get fulfilment](sales.md#get-fulfilment) | `GET /api/v1/fulfilments/{id}` | `fulfilment.view` |
| [Issue invoice](sales.md#issue-invoice) | `POST /api/v1/fulfilments/{id}/invoices` | `invoice.issue` |
| [Create sales return](sales.md#create-sales-return) | `POST /api/v1/fulfilments/{id}/sales-returns` | `sales_return.create` |
| [List invoices](sales.md#list-invoices) | `GET /api/v1/invoices` | `invoice.view` |
| [Get invoice](sales.md#get-invoice) | `GET /api/v1/invoices/{id}` | `invoice.view` |
| [Issue adjustment credit note](sales.md#issue-adjustment-credit-note) | `POST /api/v1/invoices/{id}/credit-note-adjustments` | `credit_note.adjust` |
| [Issue credit note](sales.md#issue-credit-note) | `POST /api/v1/invoices/{id}/credit-notes` | `credit_note.issue` |
| [List invoice lines](sales.md#list-invoice-lines) | `GET /api/v1/invoices/{id}/lines` | `invoice.view` |
| [Record invoice payment](sales.md#record-invoice-payment) | `POST /api/v1/invoices/{id}/payment` | `invoice.pay` |
| [Reverse invoice payment](sales.md#reverse-invoice-payment) | `POST /api/v1/invoices/{id}/payment-reversal` | `invoice.pay` |
| [Void invoice](sales.md#void-invoice) | `POST /api/v1/invoices/{id}/void` | `invoice.void` |
| [List pick queue entries](sales.md#list-pick-queue-entries) | `GET /api/v1/pick-queue` | `fulfilment.create` |
| [List sales orders](sales.md#list-sales-orders) | `GET /api/v1/sales-orders` | `sales_order.view` |
| [Create sales order](sales.md#create-sales-order) | `POST /api/v1/sales-orders` | `sales_order.create` |
| [Get sales order](sales.md#get-sales-order) | `GET /api/v1/sales-orders/{id}` | `sales_order.view` |
| [Update sales order](sales.md#update-sales-order) | `PATCH /api/v1/sales-orders/{id}` | `sales_order.edit` |
| [Delete sales order](sales.md#delete-sales-order) | `DELETE /api/v1/sales-orders/{id}` | `sales_order.delete` |
| [Cancel sales order](sales.md#cancel-sales-order) | `POST /api/v1/sales-orders/{id}/cancellation` | `sales_order.cancel` |
| [Confirm sales order](sales.md#confirm-sales-order) | `POST /api/v1/sales-orders/{id}/confirmation` | `sales_order.confirm` |
| [Create fulfilment](sales.md#create-fulfilment) | `POST /api/v1/sales-orders/{id}/fulfilments` | `fulfilment.create` |
| [List sales order lines](sales.md#list-sales-order-lines) | `GET /api/v1/sales-orders/{id}/lines` | `sales_order.view` |
| [Create sales order line](sales.md#create-sales-order-line) | `POST /api/v1/sales-orders/{id}/lines` | `sales_order.edit` |
| [Update sales order line](sales.md#update-sales-order-line) | `PATCH /api/v1/sales-orders/{id}/lines/{lineId}` | `sales_order.edit` |
| [Delete sales order line](sales.md#delete-sales-order-line) | `DELETE /api/v1/sales-orders/{id}/lines/{lineId}` | `sales_order.edit` |
| [Restore sales order line](sales.md#restore-sales-order-line) | `POST /api/v1/sales-orders/{id}/lines/{lineId}/restoration` | `sales_order.edit` |
| [Get pick list](sales.md#get-pick-list) | `GET /api/v1/sales-orders/{id}/pick-list` | `fulfilment.view` |
| [Restore sales order](sales.md#restore-sales-order) | `POST /api/v1/sales-orders/{id}/restoration` | `sales_order.delete` |
| [List sales returns](sales.md#list-sales-returns) | `GET /api/v1/sales-returns` | `sales_return.view` |
| [Get sales return](sales.md#get-sales-return) | `GET /api/v1/sales-returns/{id}` | `sales_return.view` |
| [Record an adjustment decision](sales.md#record-an-adjustment-decision) | `POST /api/v1/sales-returns/{id}/adjustment-decisions` | `credit_note.issue` |

### Suppliers

Page: [suppliers.md](suppliers.md#suppliers)

| Operation | Method and path | Permission |
| --- | --- | --- |
| [List suppliers](suppliers.md#list-suppliers) | `GET /api/v1/suppliers` | `supplier.view` |
| [Create supplier](suppliers.md#create-supplier) | `POST /api/v1/suppliers` | `supplier.create` |
| [Get supplier](suppliers.md#get-supplier) | `GET /api/v1/suppliers/{id}` | `supplier.view` |
| [Update supplier](suppliers.md#update-supplier) | `PATCH /api/v1/suppliers/{id}` | `supplier.edit` |
| [Delete supplier](suppliers.md#delete-supplier) | `DELETE /api/v1/suppliers/{id}` | `supplier.delete` |
| [List supplier products](suppliers.md#list-supplier-products) | `GET /api/v1/suppliers/{id}/products` | `supplier_product.view` |
| [Create supplier product](suppliers.md#create-supplier-product) | `POST /api/v1/suppliers/{id}/products` | `supplier_product.edit` |
| [Update supplier product](suppliers.md#update-supplier-product) | `PATCH /api/v1/suppliers/{id}/products/{productId}` | `supplier_product.edit` |
| [Delete supplier product](suppliers.md#delete-supplier-product) | `DELETE /api/v1/suppliers/{id}/products/{productId}` | `supplier_product.edit` |
| [Restore supplier](suppliers.md#restore-supplier) | `POST /api/v1/suppliers/{id}/restoration` | `supplier.delete` |

### Users, roles and sign-in

Page: [users-and-sign-in.md](users-and-sign-in.md#users-roles-and-sign-in)

| Operation | Method and path | Permission |
| --- | --- | --- |
| [Request a password reset](users-and-sign-in.md#request-a-password-reset) | `POST /api/v1/auth/password-resets` | — |
| [Set a new password](users-and-sign-in.md#set-a-new-password) | `POST /api/v1/auth/password-resets/redemption` | — |
| [Sign in](users-and-sign-in.md#sign-in) | `POST /api/v1/auth/token` | — |
| [Give a role in bulk](users-and-sign-in.md#give-a-role-in-bulk) | `POST /api/v1/bulk/role-assignments` | `role.assign` |
| [Get current user](users-and-sign-in.md#get-current-user) | `GET /api/v1/me` | — |
| [Change password](users-and-sign-in.md#change-password) | `POST /api/v1/me/password` | — |
| [List permissions](users-and-sign-in.md#list-permissions) | `GET /api/v1/permissions` | `role.view` |
| [List role grant proposals](users-and-sign-in.md#list-role-grant-proposals) | `GET /api/v1/role-grant-proposals` | `role.assign` |
| [Get role grant proposal](users-and-sign-in.md#get-role-grant-proposal) | `GET /api/v1/role-grant-proposals/{id}` | `role.assign` |
| [Cancel role grant proposal](users-and-sign-in.md#cancel-role-grant-proposal) | `POST /api/v1/role-grant-proposals/{id}/cancellation` | `role.assign` |
| [Confirm role grant proposal](users-and-sign-in.md#confirm-role-grant-proposal) | `POST /api/v1/role-grant-proposals/{id}/confirmation` | `role.assign` |
| [Reject role grant proposal](users-and-sign-in.md#reject-role-grant-proposal) | `POST /api/v1/role-grant-proposals/{id}/rejection` | `role.assign` |
| [List roles](users-and-sign-in.md#list-roles) | `GET /api/v1/roles` | `role.view` |
| [Create role](users-and-sign-in.md#create-role) | `POST /api/v1/roles` | `role.create` |
| [Get role](users-and-sign-in.md#get-role) | `GET /api/v1/roles/{id}` | `role.view` |
| [Update role](users-and-sign-in.md#update-role) | `PATCH /api/v1/roles/{id}` | `role.edit` |
| [Delete role](users-and-sign-in.md#delete-role) | `DELETE /api/v1/roles/{id}` | `role.delete` |
| [Restore role](users-and-sign-in.md#restore-role) | `POST /api/v1/roles/{id}/restoration` | `role.delete` |
| [Get settings](users-and-sign-in.md#get-settings) | `GET /api/v1/settings` | `tenant_settings.view` |
| [Update settings](users-and-sign-in.md#update-settings) | `PATCH /api/v1/settings` | `tenant_settings.edit` |
| [List users](users-and-sign-in.md#list-users) | `GET /api/v1/users` | `app_user.view` |
| [Create user](users-and-sign-in.md#create-user) | `POST /api/v1/users` | `app_user.create` |
| [Get user](users-and-sign-in.md#get-user) | `GET /api/v1/users/{id}` | `app_user.view` |
| [Update user](users-and-sign-in.md#update-user) | `PATCH /api/v1/users/{id}` | `app_user.edit` |
| [Delete user](users-and-sign-in.md#delete-user) | `DELETE /api/v1/users/{id}` | `app_user.delete` |
| [List offered roles](users-and-sign-in.md#list-offered-roles) | `GET /api/v1/users/{id}/offered-roles` | `role.assign` |
| [Restore user](users-and-sign-in.md#restore-user) | `POST /api/v1/users/{id}/restoration` | `app_user.delete` |
| [Create role grant proposal](users-and-sign-in.md#create-role-grant-proposal) | `POST /api/v1/users/{id}/role-grant-proposals` | `role.assign` |
| [Give role](users-and-sign-in.md#give-role) | `POST /api/v1/users/{id}/roles` | `role.assign` |
| [Take role away](users-and-sign-in.md#take-role-away) | `DELETE /api/v1/users/{id}/roles/{roleId}` | `role.assign` |

### Warehouses and locations

Page: [warehouses.md](warehouses.md#warehouses-and-locations)

| Operation | Method and path | Permission |
| --- | --- | --- |
| [List warehouses](warehouses.md#list-warehouses) | `GET /api/v1/warehouses` | `warehouse.view` |
| [Create warehouse](warehouses.md#create-warehouse) | `POST /api/v1/warehouses` | `warehouse.create` |
| [Get warehouse](warehouses.md#get-warehouse) | `GET /api/v1/warehouses/{id}` | `warehouse.view` |
| [Update warehouse](warehouses.md#update-warehouse) | `PATCH /api/v1/warehouses/{id}` | `warehouse.edit` |
| [Delete warehouse](warehouses.md#delete-warehouse) | `DELETE /api/v1/warehouses/{id}` | `warehouse.delete` |
| [List storage locations](warehouses.md#list-storage-locations) | `GET /api/v1/warehouses/{id}/locations` | `storage_location.view` |
| [Create storage location](warehouses.md#create-storage-location) | `POST /api/v1/warehouses/{id}/locations` | `storage_location.create` |
| [Update storage location](warehouses.md#update-storage-location) | `PATCH /api/v1/warehouses/{id}/locations/{locationId}` | `storage_location.edit` |
| [Delete storage location](warehouses.md#delete-storage-location) | `DELETE /api/v1/warehouses/{id}/locations/{locationId}` | `storage_location.delete` |
| [Restore storage location](warehouses.md#restore-storage-location) | `POST /api/v1/warehouses/{id}/locations/{locationId}/restoration` | `storage_location.delete` |
| [Restore warehouse](warehouses.md#restore-warehouse) | `POST /api/v1/warehouses/{id}/restoration` | `warehouse.delete` |

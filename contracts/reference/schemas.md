# Schemas

Generated from `contracts/openapi.json` and `contracts/api-surface.json` by `make contracts`. Do not edit by hand.

Every body the API reads or sends. A field is required when the schema says so; a nested field is shown under its parent (`lines[].quantity`).

## ActorReference

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `display_name` | string | yes | no |  |  |
| `id` | string (`uuid`) | yes | no |  |  |

## AuditLogEntryCollection

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `items` | array of object | yes | no |  |  |
| `items[].actor` | object | yes | yes |  |  |
| `items[].actor.display_name` | string | yes | no |  |  |
| `items[].actor.id` | string (`uuid`) | yes | no |  |  |
| `items[].actor_label` | string | yes | no |  |  |
| `items[].event_type` | string | yes | no |  |  |
| `items[].id` | string (`uuid`) | yes | no |  |  |
| `items[].ip_address` | string | yes | yes |  |  |
| `items[].metadata` | object | yes | no |  | Details of the event, written by the server. Their shape depends on `event_type`, and they never hold a money amount. |
| `items[].occurred_at` | string (`date-time`) | yes | no |  |  |
| `items[].subject_id` | string (`uuid`) | yes | yes |  |  |
| `items[].subject_type` | string | yes | yes |  |  |
| `next_cursor` | string | yes | yes |  |  |
| `previous_cursor` | string | yes | yes |  |  |
| `tenant_has_rows` | boolean | yes | no |  |  |

## AuditRecordCollection

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `items` | array of object | yes | no |  |  |
| `items[].actor` | object | yes | yes |  |  |
| `items[].actor.display_name` | string | yes | no |  |  |
| `items[].actor.id` | string (`uuid`) | yes | no |  |  |
| `items[].actor_label` | string | yes | no |  |  |
| `items[].event_type` | string | yes | no |  |  |
| `items[].id` | string (`uuid`) | yes | no |  |  |
| `items[].occurred_at` | string (`date-time`) | yes | no |  |  |
| `items[].subject_id` | string (`uuid`) | yes | no |  |  |
| `items[].subject_type` | string | yes | no |  |  |
| `next_cursor` | string | yes | yes |  |  |
| `previous_cursor` | string | yes | yes |  |  |
| `tenant_has_rows` | boolean | yes | no |  |  |

## BulkArchival.CreateBulkArchivalInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `ids` | array of string (`uuid`) | yes | no | minItems `1`, maxItems `50` |  |
| `resource` | string | yes | no | minLength `1` |  |

## BulkRestoration.CreateBulkRestorationInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `ids` | array of string (`uuid`) | yes | no | minItems `1`, maxItems `50` |  |
| `resource` | string | yes | no | minLength `1` |  |

## BulkRoleAssignment.CreateBulkRoleAssignmentInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `role_id` | string (`uuid`) | yes | no |  |  |
| `user_ids` | array of string (`uuid`) | yes | no | minItems `1`, maxItems `50` |  |

## CreditNote

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `credit_note_number` | string | yes | no |  |  |
| `currency_code` | string | yes | no |  |  |
| `id` | string (`uuid`) | yes | no |  |  |
| `invoice` | object | yes | no |  |  |
| `invoice.id` | string (`uuid`) | yes | no |  |  |
| `invoice.invoice_number` | string | yes | no |  |  |
| `issued_at` | string (`date-time`) | yes | no |  |  |
| `issued_by` | object | yes | no |  |  |
| `issued_by.display_name` | string | yes | no |  |  |
| `issued_by.id` | string (`uuid`) | yes | no |  |  |
| `lines` | array of object | yes | no |  |  |
| `lines[].amount` | string | yes | no |  |  |
| `lines[].fulfilment_line` | object | yes | no |  |  |
| `lines[].fulfilment_line.id` | string (`uuid`) | yes | no |  |  |
| `lines[].fulfilment_line.inventory_item` | object | yes | no |  |  |
| `lines[].fulfilment_line.inventory_item.id` | string (`uuid`) | yes | no |  |  |
| `lines[].fulfilment_line.inventory_item.name` | string | yes | no |  |  |
| `lines[].fulfilment_line.inventory_item.sku` | string | yes | no |  |  |
| `lines[].fulfilment_line.quantity` | string | yes | no |  |  |
| `lines[].fulfilment_line.sales_order_line` | object | yes | no |  |  |
| `lines[].fulfilment_line.sales_order_line.id` | string (`uuid`) | yes | no |  |  |
| `lines[].fulfilment_line.sales_order_line.line_number` | integer | yes | no |  |  |
| `lines[].id` | string (`uuid`) | yes | no |  |  |
| `lines[].inventory_item` | object | yes | no |  |  |
| `lines[].inventory_item.id` | string (`uuid`) | yes | no |  |  |
| `lines[].inventory_item.name` | string | yes | no |  |  |
| `lines[].inventory_item.sku` | string | yes | no |  |  |
| `lines[].quantity` | string | yes | no |  |  |
| `lines[].return_decisions` | array of object | no | no |  |  |
| `lines[].return_decisions[].id` | string (`uuid`) | yes | no |  |  |
| `lines[].return_decisions[].kind` | string | yes | no |  |  |
| `lines[].return_decisions[].quantity` | string | yes | yes |  |  |
| `lines[].return_decisions[].recorded_at` | string (`date-time`) | yes | no |  |  |
| `lines[].return_decisions[].recorded_by` | object | yes | no |  |  |
| `lines[].return_decisions[].recorded_by.display_name` | string | yes | no |  |  |
| `lines[].return_decisions[].recorded_by.id` | string (`uuid`) | yes | no |  |  |
| `lines[].return_decisions[].reverses_decision_id` | string (`uuid`) | yes | yes |  |  |
| `lines[].return_decisions[].sales_return` | object | yes | no |  |  |
| `lines[].return_decisions[].sales_return.id` | string (`uuid`) | yes | no |  |  |
| `lines[].return_decisions[].sales_return.sales_return_number` | string | yes | no |  |  |
| `reason` | string | yes | no |  |  |
| `sales_return` | object | yes | yes |  |  |
| `sales_return.id` | string (`uuid`) | yes | no |  |  |
| `sales_return.sales_return_number` | string | yes | no |  |  |
| `total_amount` | string | yes | no |  |  |

## CreditNote.IssueAdjustmentCreditNoteInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `acknowledged_return_ids` | array of string (`uuid`) | no | yes |  |  |
| `lines` | array of object | yes | no | minItems `1`, maxItems `200` |  |
| `lines[].fulfilment_line_id` | string (`uuid`) | yes | no |  |  |
| `lines[].quantity` | string | yes | no | minLength `1` |  |
| `reason` | string: `damaged_goods_kept`, `short_shipped` | yes | no |  |  |

## CreditNote.IssueCreditNoteInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `lines` | array of object | yes | no | minItems `1`, maxItems `200` |  |
| `lines[].fulfilment_line_id` | string (`uuid`) | yes | no |  |  |
| `lines[].quantity` | string | yes | no | minLength `1` |  |
| `reason` | string: `goods_returned` | yes | no |  |  |
| `sales_return_id` | string (`uuid`) | yes | no |  |  |

## CreditNoteCollection

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `items` | array of [CreditNote](schemas.md#creditnote) | yes | no |  |  |
| `next_cursor` | string | yes | yes |  |  |
| `previous_cursor` | string | yes | yes |  |  |
| `tenant_has_rows` | boolean | yes | no |  |  |

## CurrentUser

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `display_name` | string | yes | no |  |  |
| `email` | string | yes | no |  |  |
| `id` | string (`uuid`) | yes | no |  |  |
| `permission_codes` | array of string | yes | no |  |  |
| `roles` | array of object | yes | no |  |  |
| `roles[].id` | string (`uuid`) | yes | no |  |  |
| `roles[].name` | string | yes | no |  |  |

## Customer

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `archived_at` | string (`date-time`) | yes | yes |  |  |
| `code` | string | yes | no |  |  |
| `country_code` | string | yes | no |  |  |
| `currency_code` | string | yes | no |  |  |
| `id` | string (`uuid`) | yes | no |  |  |
| `name` | string | yes | no |  |  |
| `payment_terms_days` | integer | yes | no |  |  |

## Customer.CreateCustomerInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `code` | string | yes | no | minLength `1` |  |
| `country_code` | string | yes | no | minLength `1` |  |
| `currency_code` | string | yes | no | minLength `1` |  |
| `name` | string | yes | no | minLength `1` |  |
| `payment_terms_days` | integer | no | no |  |  |

## Customer.UpdateCustomerInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `code` | string | no | no | minLength `1` |  |
| `country_code` | string | no | no | minLength `1` |  |
| `currency_code` | string | no | no | minLength `1` |  |
| `name` | string | no | no | minLength `1` |  |
| `payment_terms_days` | integer | no | no |  |  |

## CustomerAddress

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `city` | string | yes | no |  |  |
| `country_code` | string | yes | no |  |  |
| `id` | string (`uuid`) | yes | no |  |  |
| `kind` | string | yes | no |  |  |
| `line` | string | yes | no |  |  |
| `postal_code` | string | yes | no |  |  |

## CustomerAddress.CreateCustomerAddressInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `city` | string | yes | no | minLength `1` |  |
| `country_code` | string | yes | no | minLength `1` |  |
| `kind` | string: `billing`, `shipping` | yes | no |  |  |
| `line` | string | yes | no | minLength `1` |  |
| `postal_code` | string | yes | no | minLength `1` |  |

## CustomerAddress.UpdateCustomerAddressInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `city` | string | no | no | minLength `1` |  |
| `country_code` | string | no | no | minLength `1` |  |
| `kind` | string: `billing`, `shipping` | no | no |  |  |
| `line` | string | no | no | minLength `1` |  |
| `postal_code` | string | no | no | minLength `1` |  |

## CustomerAddressCollection

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `items` | array of [CustomerAddress](schemas.md#customeraddress) | yes | no |  |  |
| `next_cursor` | string | yes | yes |  |  |
| `previous_cursor` | string | yes | yes |  |  |
| `tenant_has_rows` | boolean | yes | no |  |  |

## CustomerCollection

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `items` | array of [Customer](schemas.md#customer) | yes | no |  |  |
| `next_cursor` | string | yes | yes |  |  |
| `previous_cursor` | string | yes | yes |  |  |
| `tenant_has_rows` | boolean | yes | no |  |  |

## CustomerContact

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `email` | string | yes | yes |  |  |
| `id` | string (`uuid`) | yes | no |  |  |
| `is_primary` | boolean | yes | no |  |  |
| `name` | string | yes | no |  |  |
| `phone` | string | yes | yes |  |  |

## CustomerContact.CreateCustomerContactInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `email` | string | no | yes | minLength `1` |  |
| `is_primary` | boolean | no | no |  |  |
| `name` | string | yes | no | minLength `1` |  |
| `phone` | string | no | yes | minLength `1` |  |

## CustomerContact.UpdateCustomerContactInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `email` | string | no | yes | minLength `1` |  |
| `is_primary` | boolean | no | no |  |  |
| `name` | string | no | no | minLength `1` |  |
| `phone` | string | no | yes | minLength `1` |  |

## CustomerContactCollection

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `items` | array of [CustomerContact](schemas.md#customercontact) | yes | no |  |  |
| `next_cursor` | string | yes | yes |  |  |
| `previous_cursor` | string | yes | yes |  |  |
| `tenant_has_rows` | boolean | yes | no |  |  |

## Export.CreateExportInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `filters` | object | no | yes |  |  |
| `resource` | string | yes | no | minLength `1` |  |
| `sort` | string | no | yes | minLength `1` |  |

## Fulfilment

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `fulfilment_number` | string | yes | no |  |  |
| `id` | string (`uuid`) | yes | no |  |  |
| `lines` | array of object | yes | no |  |  |
| `lines[].id` | string (`uuid`) | yes | no |  |  |
| `lines[].inventory_item` | object | yes | no |  |  |
| `lines[].inventory_item.id` | string (`uuid`) | yes | no |  |  |
| `lines[].inventory_item.name` | string | yes | no |  |  |
| `lines[].inventory_item.sku` | string | yes | no |  |  |
| `lines[].quantity` | string | yes | no |  |  |
| `lines[].sales_order_line` | object | yes | no |  |  |
| `lines[].sales_order_line.id` | string (`uuid`) | yes | no |  |  |
| `lines[].sales_order_line.line_number` | integer | yes | no |  |  |
| `lines[].storage_location` | object | yes | no |  |  |
| `lines[].storage_location.code` | string | yes | no |  |  |
| `lines[].storage_location.id` | string (`uuid`) | yes | no |  |  |
| `sales_order` | object | yes | no |  |  |
| `sales_order.id` | string (`uuid`) | yes | no |  |  |
| `sales_order.order_number` | string | yes | no |  |  |
| `shipped_at` | string (`date-time`) | yes | no |  |  |
| `shipped_by` | object | yes | no |  |  |
| `shipped_by.display_name` | string | yes | no |  |  |
| `shipped_by.id` | string (`uuid`) | yes | no |  |  |

## Fulfilment.CreateFulfilmentInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `lines` | array of object | yes | no | minItems `1`, maxItems `200` |  |
| `lines[].quantity` | string | yes | no | minLength `1` |  |
| `lines[].sales_order_line_id` | string (`uuid`) | yes | no |  |  |
| `lines[].storage_location_id` | string (`uuid`) | yes | no |  |  |
| `shipped_at` | string (`date-time`) | no | yes |  | An RFC 3339 date and time, such as 2026-11-18T09:30:00Z or 2026-11-18T17:30:00.250+08:00: an uppercase T; seconds 00 to 59 (no leap second) with at most three decimal places; Z or an offset written ±hh:mm of at most ±15:59, with minutes 00 to 59, not a time-zone name; a year from 0001 to 9999, also once converted to UTC. |

## FulfilmentCollection

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `items` | array of [Fulfilment](schemas.md#fulfilment) | yes | no |  |  |
| `next_cursor` | string | yes | yes |  |  |
| `previous_cursor` | string | yes | yes |  |  |
| `tenant_has_rows` | boolean | yes | no |  |  |

## GoodsReceipt

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `id` | string (`uuid`) | yes | no |  |  |
| `lines` | array of object | yes | no |  |  |
| `lines[].id` | string (`uuid`) | yes | no |  |  |
| `lines[].inventory_item` | object | yes | no |  |  |
| `lines[].inventory_item.id` | string (`uuid`) | yes | no |  |  |
| `lines[].inventory_item.name` | string | yes | no |  |  |
| `lines[].inventory_item.sku` | string | yes | no |  |  |
| `lines[].purchase_order_line` | object | yes | no |  |  |
| `lines[].purchase_order_line.id` | string (`uuid`) | yes | no |  |  |
| `lines[].purchase_order_line.line_number` | integer | yes | no |  |  |
| `lines[].quantity` | string | yes | no |  |  |
| `lines[].storage_location` | object | yes | no |  |  |
| `lines[].storage_location.code` | string | yes | no |  |  |
| `lines[].storage_location.id` | string (`uuid`) | yes | no |  |  |
| `lines[].unit_of_measure_code` | string: `EA`, `BOX`, `KG`, `L`, `M` | yes | no |  |  |
| `purchase_order` | object | yes | no |  |  |
| `purchase_order.id` | string (`uuid`) | yes | no |  |  |
| `purchase_order.order_number` | string | yes | no |  |  |
| `receipt_number` | string | yes | no |  |  |
| `received_at` | string (`date-time`) | yes | no |  |  |
| `received_by` | any | yes | no |  |  |

## GoodsReceipt.CreateGoodsReceiptInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `lines` | array of object | yes | no | minItems `1`, maxItems `200` |  |
| `lines[].purchase_order_line_id` | string (`uuid`) | yes | no |  |  |
| `lines[].quantity` | string | yes | no | minLength `1` |  |
| `lines[].storage_location_id` | string (`uuid`) | yes | no |  |  |
| `received_at` | string (`date-time`) | no | yes |  | An RFC 3339 date and time, such as 2026-11-18T09:30:00Z or 2026-11-18T17:30:00.250+08:00: an uppercase T; seconds 00 to 59 (no leap second) with at most three decimal places; Z or an offset written ±hh:mm of at most ±15:59, with minutes 00 to 59, not a time-zone name; a year from 0001 to 9999, also once converted to UTC. |

## GoodsReceiptCollection

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `items` | array of [GoodsReceipt](schemas.md#goodsreceipt) | yes | no |  |  |
| `next_cursor` | string | yes | yes |  |  |
| `previous_cursor` | string | yes | yes |  |  |
| `tenant_has_rows` | boolean | yes | no |  |  |

## Invoice

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `amount_credited` | string | yes | no |  |  |
| `amount_due` | string | yes | no |  |  |
| `credited_in_full` | boolean | yes | no |  |  |
| `currency_code` | string | yes | no |  |  |
| `customer` | object | yes | no |  |  |
| `customer.id` | string (`uuid`) | yes | no |  |  |
| `customer.name` | string | yes | no |  |  |
| `due_at` | string (`date-time`) | yes | no |  |  |
| `fulfilment` | object | yes | no |  |  |
| `fulfilment.fulfilment_number` | string | yes | no |  |  |
| `fulfilment.id` | string (`uuid`) | yes | no |  |  |
| `id` | string (`uuid`) | yes | no |  |  |
| `invoice_number` | string | yes | no |  |  |
| `issued_at` | string (`date-time`) | yes | no |  |  |
| `paid_at` | string (`date-time`) | yes | yes |  |  |
| `paid_by` | object | yes | yes |  |  |
| `paid_by.display_name` | string | yes | no |  |  |
| `paid_by.id` | string (`uuid`) | yes | no |  |  |
| `status` | string | yes | no |  |  |
| `total_amount` | string | yes | no |  |  |

## Invoice.PayInvoiceInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `acknowledged_return_ids` | array of string (`uuid`) | no | yes |  |  |
| `paid_at` | string (`date-time`) | no | yes |  | An RFC 3339 date and time, such as 2026-11-18T09:30:00Z or 2026-11-18T17:30:00.250+08:00: an uppercase T; seconds 00 to 59 (no leap second) with at most three decimal places; Z or an offset written ±hh:mm of at most ±15:59, with minutes 00 to 59, not a time-zone name; a year from 0001 to 9999, also once converted to UTC. |

## Invoice.ReverseInvoicePaymentInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `reason` | string | yes | no |  |  |

## Invoice.VoidInvoiceInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `reason` | string | yes | no |  |  |

## InvoiceCollection

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `items` | array of [Invoice](schemas.md#invoice) | yes | no |  |  |
| `next_cursor` | string | yes | yes |  |  |
| `previous_cursor` | string | yes | yes |  |  |
| `tenant_has_rows` | boolean | yes | no |  |  |

## InvoiceLineCollection

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `items` | array of object | yes | no |  |  |
| `items[].amount_billed` | string | yes | no |  |  |
| `items[].amount_credited` | string | yes | no |  |  |
| `items[].fulfilment_line` | object | yes | no |  |  |
| `items[].fulfilment_line.id` | string (`uuid`) | yes | no |  |  |
| `items[].fulfilment_line.inventory_item` | object | yes | no |  |  |
| `items[].fulfilment_line.inventory_item.id` | string (`uuid`) | yes | no |  |  |
| `items[].fulfilment_line.inventory_item.name` | string | yes | no |  |  |
| `items[].fulfilment_line.inventory_item.sku` | string | yes | no |  |  |
| `items[].fulfilment_line.quantity` | string | yes | no |  |  |
| `items[].fulfilment_line.sales_order_line` | object | yes | no |  |  |
| `items[].fulfilment_line.sales_order_line.id` | string (`uuid`) | yes | no |  |  |
| `items[].fulfilment_line.sales_order_line.line_number` | integer | yes | no |  |  |
| `items[].id` | string (`uuid`) | yes | no |  |  |
| `items[].inventory_item` | object | yes | no |  |  |
| `items[].inventory_item.id` | string (`uuid`) | yes | no |  |  |
| `items[].inventory_item.name` | string | yes | no |  |  |
| `items[].inventory_item.sku` | string | yes | no |  |  |
| `items[].quantity_billed` | string | yes | no |  |  |
| `items[].quantity_credited` | string | yes | no |  |  |
| `items[].unit_price` | string | yes | no |  |  |
| `next_cursor` | string | yes | yes |  |  |
| `previous_cursor` | string | yes | yes |  |  |
| `tenant_has_rows` | boolean | yes | no |  |  |

## Item

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `archived_at` | string (`date-time`) | yes | yes |  |  |
| `holds_stock` | boolean | yes | no |  |  |
| `id` | string (`uuid`) | yes | no |  |  |
| `name` | string | yes | no |  |  |
| `reorder_point` | string | yes | no |  |  |
| `reorder_quantity` | string | yes | no |  |  |
| `safety_stock` | string | yes | no |  |  |
| `sku` | string | yes | no |  |  |
| `supplier` | any | yes | no |  |  |
| `unit_of_measure_code` | string: `EA`, `BOX`, `KG`, `L`, `M` | yes | no |  |  |

## Item.CreateItemInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `name` | string | yes | no | minLength `1` |  |
| `reorder_point` | string | no | yes | minLength `1` |  |
| `reorder_quantity` | string | no | yes | minLength `1` |  |
| `safety_stock` | string | no | yes | minLength `1` |  |
| `sku` | string | yes | no | minLength `1` |  |
| `supplier_id` | string (`uuid`) | no | yes |  |  |
| `unit_of_measure_code` | string: `EA`, `BOX`, `KG`, `L`, `M` | yes | no |  |  |

## Item.UpdateItemInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `name` | string | no | no | minLength `1` |  |
| `reorder_point` | string | no | no | minLength `1` |  |
| `reorder_quantity` | string | no | no | minLength `1` |  |
| `safety_stock` | string | no | no | minLength `1` |  |
| `sku` | string | no | no | minLength `1` |  |
| `supplier_id` | string (`uuid`) | no | yes |  |  |
| `unit_of_measure_code` | string: `EA`, `BOX`, `KG`, `L`, `M` | no | no |  |  |

## ItemCollection

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `items` | array of [Item](schemas.md#item) | yes | no |  |  |
| `next_cursor` | string | yes | yes |  |  |
| `previous_cursor` | string | yes | yes |  |  |
| `tenant_has_rows` | boolean | yes | no |  |  |

## NamedReference

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `id` | string (`uuid`) | yes | no |  |  |
| `name` | string | yes | no |  |  |

## OfferedRoleCollection

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `items` | array of object | yes | no |  |  |
| `items[].conflicts_with` | array of object | yes | no |  |  |
| `items[].conflicts_with[].role` | object | yes | no |  |  |
| `items[].conflicts_with[].role.id` | string (`uuid`) | yes | no |  |  |
| `items[].conflicts_with[].role.name` | string | yes | no |  |  |
| `items[].conflicts_with[].sod_row` | string | yes | no |  |  |
| `items[].id` | string (`uuid`) | yes | no |  |  |
| `items[].margin_exposure_with` | array of object | yes | no |  |  |
| `items[].margin_exposure_with[].id` | string (`uuid`) | yes | no |  |  |
| `items[].margin_exposure_with[].name` | string | yes | no |  |  |
| `items[].name` | string | yes | no |  |  |
| `items[].refusal` | string | yes | yes |  |  |
| `items[].requires_owner_confirmation` | boolean | yes | no |  |  |
| `next_cursor` | string | yes | yes |  |  |
| `previous_cursor` | string | yes | yes |  |  |
| `tenant_has_rows` | boolean | yes | no |  |  |

## Password

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `changed_at` | string (`date-time`) | yes | no |  |  |

## Password.ChangePasswordInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `current_password` | string | yes | no | minLength `1` |  |
| `new_password` | string | yes | no | minLength `1` |  |

## PasswordReset.CreatePasswordResetInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `email` | string | yes | no | minLength `1` |  |
| `workspace` | string | yes | no | minLength `1` |  |

## PasswordResetRedemption

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `changed_at` | string (`date-time`) | yes | no |  |  |

## PasswordResetRedemption.CreatePasswordResetRedemptionInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `password` | string | yes | no | minLength `1` |  |
| `token` | string | yes | no |  |  |

## PermissionCollection

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `items` | array of object | yes | no |  |  |
| `items[].code` | string | yes | no |  |  |
| `items[].description` | string | yes | no |  |  |
| `items[].module` | string | yes | no |  |  |
| `items[].sod_rows` | array of object | yes | no |  |  |
| `items[].sod_rows[].half` | string: `preparing`, `controlled` | yes | no |  |  |
| `items[].sod_rows[].id` | string | yes | no |  |  |
| `next_cursor` | string | yes | yes |  |  |
| `previous_cursor` | string | yes | yes |  |  |
| `tenant_has_rows` | boolean | yes | no |  |  |

## PickList

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `customer` | object | yes | no |  |  |
| `customer.id` | string (`uuid`) | yes | no |  |  |
| `customer.name` | string | yes | no |  |  |
| `id` | string (`uuid`) | yes | no |  |  |
| `lines` | array of object | yes | no |  |  |
| `lines[].candidate_bins` | array of object | yes | no |  |  |
| `lines[].candidate_bins[].qty_on_hand` | string | yes | no |  |  |
| `lines[].candidate_bins[].storage_location` | object | yes | no |  |  |
| `lines[].candidate_bins[].storage_location.code` | string | yes | no |  |  |
| `lines[].candidate_bins[].storage_location.id` | string (`uuid`) | yes | no |  |  |
| `lines[].inventory_item` | object | yes | no |  |  |
| `lines[].inventory_item.id` | string (`uuid`) | yes | no |  |  |
| `lines[].inventory_item.name` | string | yes | no |  |  |
| `lines[].inventory_item.sku` | string | yes | no |  |  |
| `lines[].quantity` | string | yes | no |  |  |
| `lines[].quantity_fulfilled` | string | yes | no |  |  |
| `lines[].quantity_outstanding` | string | yes | no |  |  |
| `lines[].sales_order_line` | object | yes | no |  |  |
| `lines[].sales_order_line.id` | string (`uuid`) | yes | no |  |  |
| `lines[].sales_order_line.line_number` | integer | yes | no |  |  |
| `order_number` | string | yes | no |  |  |
| `ship_to_city` | string | yes | no |  |  |
| `ship_to_country_code` | string | yes | no |  |  |
| `ship_to_line` | string | yes | no |  |  |
| `ship_to_postal_code` | string | yes | no |  |  |
| `status` | string | yes | no |  |  |
| `warehouse` | object | yes | no |  |  |
| `warehouse.id` | string (`uuid`) | yes | no |  |  |
| `warehouse.name` | string | yes | no |  |  |

## PickQueueEntryCollection

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `items` | array of object | yes | no |  |  |
| `items[].customer` | object | yes | no |  |  |
| `items[].customer.id` | string (`uuid`) | yes | no |  |  |
| `items[].customer.name` | string | yes | no |  |  |
| `items[].id` | string (`uuid`) | yes | no |  |  |
| `items[].order_number` | string | yes | no |  |  |
| `items[].ordered_at` | string (`date-time`) | yes | no |  |  |
| `items[].status` | string: `confirmed`, `partially_fulfilled` | yes | no |  |  |
| `items[].warehouse` | object | yes | no |  |  |
| `items[].warehouse.id` | string (`uuid`) | yes | no |  |  |
| `items[].warehouse.name` | string | yes | no |  |  |
| `next_cursor` | string | yes | yes |  |  |
| `previous_cursor` | string | yes | yes |  |  |
| `tenant_has_rows` | boolean | yes | no |  |  |

## Problem

A refusal (RFC 9457 problem details). `type`, `title` and `status` are always present; each other member is present only on the refusals that set it.

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `allow` | string | no | no |  | On 405: the methods this address serves. |
| `current` | object | no | no |  | On a 409 about stock figures that changed: the figures as they are now. |
| `detail` | string | no | no |  | A sentence about this refusal, when there is one. |
| `errors` | array of [ProblemFieldError](schemas.md#problemfielderror) | no | no |  | On 422: what was refused. |
| `message` | string | no | no |  | On a 409 refused by a rule: the rule in plain words, fit to show a person. |
| `required_permission` | string | no | no |  | On 403: the permission the request needed. |
| `rule` | string | no | no |  | On a 409 refused by a rule: the rule's name, which stays the same if its wording changes. |
| `status` | integer | yes | no |  | The HTTP status, repeated. |
| `title` | string | yes | no |  | A short summary of the kind, the same for every refusal of that kind. |
| `type` | string: `urn:erp:problem:validation-failed`, `urn:erp:problem:business-rule-violated`, `urn:erp:problem:permission-denied`, `urn:erp:problem:not-found`, `urn:erp:problem:stale-record`, `urn:erp:problem:too-many-attempts`, `urn:erp:problem:sign-in-paused`, `urn:erp:problem:authentication-failed`, `urn:erp:problem:idempotency-key-conflict`, `urn:erp:problem:idempotency-key-required`, `urn:erp:problem:ai-capacity-exhausted`, `urn:erp:problem:unsupported-media-type`, `urn:erp:problem:not-acceptable`, `urn:erp:problem:method-not-allowed` | yes | no |  | Which kind of refusal this is. Read this, not `title`. |

## ProblemFieldError

One field defect.

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `code` | string | yes | no |  | A short name for the problem that does not change. |
| `message` | string | yes | no |  | A sentence fit to show a person. |
| `pointer` | string | yes | no |  | Where the problem is: a JSON pointer into the body (`/name`, or an empty string for the whole body), `?` and a name for a query parameter (`?limit`), or `#` and a name for a header (`#Idempotency-Key`). |

## PurchaseOrder

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `archived_at` | string (`date-time`) | yes | yes |  |  |
| `created_by` | any | yes | no |  |  |
| `currency_code` | string | yes | no |  |  |
| `expected_at` | string (`date-time`) | yes | yes |  |  |
| `id` | string (`uuid`) | yes | no |  |  |
| `order_number` | string | yes | no |  |  |
| `ordered_at` | string (`date-time`) | yes | yes |  |  |
| `status` | string | yes | no |  |  |
| `supplier` | [NamedReference](schemas.md#namedreference) | yes | no |  |  |
| `total_amount` | string | yes | no |  |  |
| `warehouse` | [NamedReference](schemas.md#namedreference) | yes | no |  |  |

## PurchaseOrder.ApprovePurchaseOrderInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `expected_at` | string (`date-time`) | no | yes |  | An RFC 3339 date and time, such as 2026-11-18T09:30:00Z or 2026-11-18T17:30:00.250+08:00: an uppercase T; seconds 00 to 59 (no leap second) with at most three decimal places; Z or an offset written ±hh:mm of at most ±15:59, with minutes 00 to 59, not a time-zone name; a year from 0001 to 9999, also once converted to UTC. |

## PurchaseOrder.CancelPurchaseOrderInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `reason` | string | no | yes |  |  |

## PurchaseOrder.CreatePurchaseOrderInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `currency_code` | string | no | yes | minLength `1` |  |
| `expected_at` | string (`date-time`) | no | yes |  | An RFC 3339 date and time, such as 2026-11-18T09:30:00Z or 2026-11-18T17:30:00.250+08:00: an uppercase T; seconds 00 to 59 (no leap second) with at most three decimal places; Z or an offset written ±hh:mm of at most ±15:59, with minutes 00 to 59, not a time-zone name; a year from 0001 to 9999, also once converted to UTC. |
| `lines` | array of object | no | yes | minItems `1`, maxItems `200` |  |
| `lines[].inventory_item_id` | string (`uuid`) | yes | no |  |  |
| `lines[].quantity` | string | yes | no | minLength `1` |  |
| `lines[].unit_cost` | string | yes | no | minLength `1` |  |
| `supplier_id` | string (`uuid`) | yes | no |  |  |
| `warehouse_id` | string (`uuid`) | yes | no |  |  |

## PurchaseOrder.UpdatePurchaseOrderInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `expected_at` | string (`date-time`) | no | yes |  | An RFC 3339 date and time, such as 2026-11-18T09:30:00Z or 2026-11-18T17:30:00.250+08:00: an uppercase T; seconds 00 to 59 (no leap second) with at most three decimal places; Z or an offset written ±hh:mm of at most ±15:59, with minutes 00 to 59, not a time-zone name; a year from 0001 to 9999, also once converted to UTC. |
| `supplier_id` | string (`uuid`) | no | no |  |  |
| `warehouse_id` | string (`uuid`) | no | no |  |  |

## PurchaseOrderCollection

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `items` | array of [PurchaseOrder](schemas.md#purchaseorder) | yes | no |  |  |
| `next_cursor` | string | yes | yes |  |  |
| `previous_cursor` | string | yes | yes |  |  |
| `tenant_has_rows` | boolean | yes | no |  |  |

## PurchaseOrderLine

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `archived_at` | string (`date-time`) | yes | yes |  |  |
| `id` | string (`uuid`) | yes | no |  |  |
| `inventory_item` | object | yes | no |  |  |
| `inventory_item.id` | string (`uuid`) | yes | no |  |  |
| `inventory_item.name` | string | yes | no |  |  |
| `inventory_item.sku` | string | yes | no |  |  |
| `line_number` | integer | yes | no |  |  |
| `quantity` | string | yes | no |  |  |
| `quantity_outstanding` | string | yes | no |  |  |
| `quantity_received` | string | yes | no |  |  |
| `unit_cost` | string | yes | no |  |  |
| `unit_of_measure_code` | string: `EA`, `BOX`, `KG`, `L`, `M` | yes | no |  |  |

## PurchaseOrderLine.CreatePurchaseOrderLineInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `inventory_item_id` | string (`uuid`) | yes | no |  |  |
| `quantity` | string | yes | no | minLength `1` |  |
| `unit_cost` | string | yes | no | minLength `1` |  |

## PurchaseOrderLine.UpdatePurchaseOrderLineInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `quantity` | string | no | no | minLength `1` |  |
| `unit_cost` | string | no | no | minLength `1` |  |

## PurchaseOrderLineCollection

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `items` | array of [PurchaseOrderLine](schemas.md#purchaseorderline) | yes | no |  |  |
| `next_cursor` | string | yes | yes |  |  |
| `previous_cursor` | string | yes | yes |  |  |
| `tenant_has_rows` | boolean | yes | no |  |  |

## ReorderSuggestionCollection

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `items` | array of object | yes | no |  |  |
| `items[].currency_code` | string | yes | yes |  |  |
| `items[].id` | string (`uuid`) | yes | no |  |  |
| `items[].inventory_item` | object | yes | no |  |  |
| `items[].inventory_item.id` | string (`uuid`) | yes | no |  |  |
| `items[].inventory_item.name` | string | yes | no |  |  |
| `items[].inventory_item.sku` | string | yes | no |  |  |
| `items[].lead_time_days` | integer | yes | yes |  |  |
| `items[].preferred_supplier` | object | yes | yes |  |  |
| `items[].preferred_supplier.id` | string (`uuid`) | yes | no |  |  |
| `items[].preferred_supplier.name` | string | yes | no |  |  |
| `items[].qty_on_hand_total` | string | yes | no |  |  |
| `items[].qty_on_order` | string | yes | no |  |  |
| `items[].reorder_point` | string | yes | no |  |  |
| `items[].reorder_quantity` | string | yes | no |  |  |
| `items[].suggested_quantity` | string | yes | no |  |  |
| `items[].unit_cost` | string | yes | yes |  |  |
| `next_cursor` | string | yes | yes |  |  |
| `previous_cursor` | string | yes | yes |  |  |
| `tenant_has_rows` | boolean | yes | no |  |  |

## ReorderSummary

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `items_below_reorder_point` | integer | yes | no |  |  |

## Role

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `archived_at` | string (`date-time`) | yes | yes |  |  |
| `description` | string | yes | no |  |  |
| `holder_count` | integer | yes | no |  |  |
| `id` | string (`uuid`) | yes | no |  |  |
| `is_system` | boolean | yes | no |  |  |
| `margin_exposure` | boolean | yes | no |  |  |
| `name` | string | yes | no |  |  |
| `permission_codes` | array of string | yes | no |  |  |

## Role.AssignRoleInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `role_id` | string (`uuid`) | yes | no |  |  |

## Role.CreateRoleInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `description` | string | no | yes |  |  |
| `name` | string | yes | no | minLength `1` |  |
| `permission_codes` | array of string | yes | no |  |  |

## Role.UpdateRoleInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `description` | string | no | yes |  |  |
| `name` | string | no | no | minLength `1` |  |
| `permission_codes` | array of string | no | no |  |  |

## RoleCollection

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `items` | array of [Role](schemas.md#role) | yes | no |  |  |
| `next_cursor` | string | yes | yes |  |  |
| `previous_cursor` | string | yes | yes |  |  |
| `tenant_has_rows` | boolean | yes | no |  |  |

## RoleGrantProposal

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `decided_at` | string (`date-time`) | yes | yes |  |  |
| `decided_by` | any | yes | no |  |  |
| `expires_at` | string (`date-time`) | yes | no |  |  |
| `id` | string (`uuid`) | yes | no |  |  |
| `is_expired` | boolean | yes | no |  |  |
| `lapse_reason` | string | yes | yes |  |  |
| `permission_codes` | array of string | yes | no |  |  |
| `proposed_at` | string (`date-time`) | yes | no |  |  |
| `proposed_by` | [UserDisplayReference](schemas.md#userdisplayreference) | yes | no |  |  |
| `reason` | string | yes | yes |  |  |
| `role` | [RoleReference](schemas.md#rolereference) | yes | no |  |  |
| `status` | string | yes | no |  |  |
| `user` | [UserDisplayReference](schemas.md#userdisplayreference) | yes | no |  |  |

## RoleGrantProposal.CreateRoleGrantProposalInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `role_id` | string (`uuid`) | yes | no |  |  |

## RoleGrantProposal.RejectRoleGrantProposalInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `reason` | string | no | yes |  |  |

## RoleGrantProposalCollection

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `items` | array of [RoleGrantProposal](schemas.md#rolegrantproposal) | yes | no |  |  |
| `next_cursor` | string | yes | yes |  |  |
| `previous_cursor` | string | yes | yes |  |  |
| `tenant_has_rows` | boolean | yes | no |  |  |

## RoleReference

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `id` | string (`uuid`) | yes | no |  |  |
| `name` | string | yes | no |  |  |

## SalesActorReference

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `display_name` | string | yes | no |  |  |
| `id` | string (`uuid`) | yes | no |  |  |

## SalesItemReference

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `id` | string (`uuid`) | yes | no |  |  |
| `name` | string | yes | no |  |  |
| `sku` | string | yes | no |  |  |

## SalesNamedReference

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `id` | string (`uuid`) | yes | no |  |  |
| `name` | string | yes | no |  |  |

## SalesOrder

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `archived_at` | string (`date-time`) | yes | yes |  |  |
| `created_by` | any | yes | no |  |  |
| `currency_code` | string | yes | no |  |  |
| `customer` | [SalesNamedReference](schemas.md#salesnamedreference) | yes | no |  |  |
| `id` | string (`uuid`) | yes | no |  |  |
| `order_number` | string | yes | no |  |  |
| `ordered_at` | string (`date-time`) | yes | yes |  |  |
| `ship_to_city` | string | yes | no |  |  |
| `ship_to_country_code` | string | yes | no |  |  |
| `ship_to_line` | string | yes | no |  |  |
| `ship_to_postal_code` | string | yes | no |  |  |
| `status` | string | yes | no |  |  |
| `total_amount` | string | yes | no |  |  |
| `warehouse` | [SalesNamedReference](schemas.md#salesnamedreference) | yes | no |  |  |

## SalesOrder.CancelSalesOrderInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `reason` | string | no | yes |  |  |

## SalesOrder.CreateSalesOrderInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `currency_code` | string | no | yes | minLength `1` |  |
| `customer_id` | string (`uuid`) | yes | no |  |  |
| `lines` | array of object | no | yes | minItems `1`, maxItems `200` |  |
| `lines[].inventory_item_id` | string (`uuid`) | yes | no |  |  |
| `lines[].quantity` | string | yes | no | minLength `1` |  |
| `lines[].unit_price` | string | yes | no | minLength `1` |  |
| `ship_to_address_id` | string (`uuid`) | yes | no |  |  |
| `warehouse_id` | string (`uuid`) | yes | no |  |  |

## SalesOrder.UpdateSalesOrderInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `customer_id` | string (`uuid`) | no | no |  |  |
| `ship_to_address_id` | string (`uuid`) | no | no |  |  |
| `warehouse_id` | string (`uuid`) | no | no |  |  |

## SalesOrderCollection

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `items` | array of [SalesOrder](schemas.md#salesorder) | yes | no |  |  |
| `next_cursor` | string | yes | yes |  |  |
| `previous_cursor` | string | yes | yes |  |  |
| `tenant_has_rows` | boolean | yes | no |  |  |

## SalesOrderLine

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `archived_at` | string (`date-time`) | yes | yes |  |  |
| `id` | string (`uuid`) | yes | no |  |  |
| `inventory_item` | [SalesItemReference](schemas.md#salesitemreference) | yes | no |  |  |
| `line_number` | integer | yes | no |  |  |
| `quantity` | string | yes | no |  |  |
| `quantity_fulfilled` | string | yes | no |  |  |
| `quantity_outstanding` | string | yes | no |  |  |
| `unit_price` | string | yes | no |  |  |

## SalesOrderLine.CreateSalesOrderLineInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `inventory_item_id` | string (`uuid`) | yes | no |  |  |
| `quantity` | string | yes | no | minLength `1` |  |
| `unit_price` | string | yes | no | minLength `1` |  |

## SalesOrderLine.UpdateSalesOrderLineInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `quantity` | string | no | no | minLength `1` |  |
| `unit_price` | string | no | no | minLength `1` |  |

## SalesOrderLineCollection

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `items` | array of [SalesOrderLine](schemas.md#salesorderline) | yes | no |  |  |
| `next_cursor` | string | yes | yes |  |  |
| `previous_cursor` | string | yes | yes |  |  |
| `tenant_has_rows` | boolean | yes | no |  |  |

## SalesReturn

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `adjustment_decision_owed` | boolean | no | no |  |  |
| `created_at` | string (`date-time`) | yes | no |  |  |
| `credit_blocked_by_payment` | boolean | no | no |  |  |
| `credit_target` | object | yes | yes |  |  |
| `credit_target.id` | string (`uuid`) | yes | no |  |  |
| `credit_target.invoice_number` | string | yes | no |  |  |
| `deducts` | boolean | yes | no |  |  |
| `fulfilment` | object | yes | no |  |  |
| `fulfilment.fulfilment_number` | string | yes | no |  |  |
| `fulfilment.id` | string (`uuid`) | yes | no |  |  |
| `id` | string (`uuid`) | yes | no |  |  |
| `invoice` | object | yes | yes |  |  |
| `invoice.id` | string (`uuid`) | yes | no |  |  |
| `invoice.invoice_number` | string | yes | no |  |  |
| `lines` | array of object | yes | no |  |  |
| `lines[].adjustment_decisions` | array of object | no | no |  |  |
| `lines[].adjustment_decisions[].credit_note` | object | yes | no |  |  |
| `lines[].adjustment_decisions[].credit_note.credit_note_number` | string | yes | no |  |  |
| `lines[].adjustment_decisions[].credit_note.id` | string (`uuid`) | yes | no |  |  |
| `lines[].adjustment_decisions[].id` | string (`uuid`) | yes | no |  |  |
| `lines[].adjustment_decisions[].kind` | string | yes | no |  |  |
| `lines[].adjustment_decisions[].quantity` | string | yes | yes |  |  |
| `lines[].adjustment_decisions[].recorded_at` | string (`date-time`) | yes | no |  |  |
| `lines[].adjustment_decisions[].recorded_by` | object | yes | no |  |  |
| `lines[].adjustment_decisions[].recorded_by.display_name` | string | yes | no |  |  |
| `lines[].adjustment_decisions[].recorded_by.id` | string (`uuid`) | yes | no |  |  |
| `lines[].adjustment_decisions[].reverses_decision_id` | string (`uuid`) | yes | yes |  |  |
| `lines[].fulfilment_line` | object | yes | no |  |  |
| `lines[].fulfilment_line.id` | string (`uuid`) | yes | no |  |  |
| `lines[].fulfilment_line.inventory_item` | object | yes | no |  |  |
| `lines[].fulfilment_line.inventory_item.id` | string (`uuid`) | yes | no |  |  |
| `lines[].fulfilment_line.inventory_item.name` | string | yes | no |  |  |
| `lines[].fulfilment_line.inventory_item.sku` | string | yes | no |  |  |
| `lines[].fulfilment_line.sales_order_line` | object | yes | no |  |  |
| `lines[].fulfilment_line.sales_order_line.id` | string (`uuid`) | yes | no |  |  |
| `lines[].fulfilment_line.sales_order_line.line_number` | integer | yes | no |  |  |
| `lines[].fulfilment_line.storage_location` | object | yes | no |  |  |
| `lines[].fulfilment_line.storage_location.code` | string | yes | no |  |  |
| `lines[].fulfilment_line.storage_location.id` | string (`uuid`) | yes | no |  |  |
| `lines[].id` | string (`uuid`) | yes | no |  |  |
| `lines[].inventory_item` | object | yes | no |  |  |
| `lines[].inventory_item.id` | string (`uuid`) | yes | no |  |  |
| `lines[].inventory_item.name` | string | yes | no |  |  |
| `lines[].inventory_item.sku` | string | yes | no |  |  |
| `lines[].quantity` | string | yes | no |  |  |
| `lines[].quantity_covered_by_adjustment` | string | no | no |  |  |
| `lines[].quantity_credited` | string | yes | no |  |  |
| `lines[].storage_location` | object | yes | no |  |  |
| `lines[].storage_location.code` | string | yes | no |  |  |
| `lines[].storage_location.id` | string (`uuid`) | yes | no |  |  |
| `reason` | string | yes | no |  |  |
| `recorded_by` | object | yes | no |  |  |
| `recorded_by.display_name` | string | yes | no |  |  |
| `recorded_by.id` | string (`uuid`) | yes | no |  |  |
| `returned_at` | string (`date-time`) | yes | no |  |  |
| `sales_return_number` | string | yes | no |  |  |

## SalesReturn.CreateAdjustmentDecisionInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `acknowledged_return_ids` | array of string (`uuid`) | no | yes |  |  |
| `credit_note_line_id` | string (`uuid`) | no | yes |  |  |
| `kind` | string: `same`, `distinct`, `reversal` | yes | no |  |  |
| `quantity` | string | no | yes | minLength `1` |  |
| `reverses_decision_id` | string (`uuid`) | no | yes |  |  |
| `sales_return_line_id` | string (`uuid`) | no | yes |  |  |

## SalesReturn.CreateSalesReturnInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `lines` | array of object | yes | no | minItems `1`, maxItems `200` |  |
| `lines[].fulfilment_line_id` | string (`uuid`) | yes | no |  |  |
| `lines[].quantity` | string | yes | no | minLength `1` |  |
| `lines[].storage_location_id` | string (`uuid`) | no | yes |  |  |
| `reason` | string: `damaged_in_transit`, `wrong_item_shipped`, `defective`, `no_longer_wanted`, `other`, `recorded_in_error` | yes | no |  |  |
| `returned_at` | string (`date-time`) | no | yes |  | An RFC 3339 date and time, such as 2026-11-18T09:30:00Z or 2026-11-18T17:30:00.250+08:00: an uppercase T; seconds 00 to 59 (no leap second) with at most three decimal places; Z or an offset written ±hh:mm of at most ±15:59, with minutes 00 to 59, not a time-zone name; a year from 0001 to 9999, also once converted to UTC. |

## SalesReturnCollection

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `items` | array of [SalesReturn](schemas.md#salesreturn) | yes | no |  |  |
| `next_cursor` | string | yes | yes |  |  |
| `previous_cursor` | string | yes | yes |  |  |
| `tenant_has_rows` | boolean | yes | no |  |  |

## Settings

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `default_currency_code` | string | yes | no |  |  |
| `display_name` | string | yes | no |  |  |
| `workspace` | string | yes | no |  |  |

## Settings.UpdateSettingsInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `display_name` | string | no | no | minLength `1` |  |

## StockLevelCollection

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `items` | array of object | yes | no |  |  |
| `items[].id` | string (`uuid`) | yes | no |  |  |
| `items[].inventory_item` | object | yes | no |  |  |
| `items[].inventory_item.id` | string (`uuid`) | yes | no |  |  |
| `items[].inventory_item.name` | string | yes | no |  |  |
| `items[].inventory_item.sku` | string | yes | no |  |  |
| `items[].qty_on_hand` | string | yes | no |  |  |
| `items[].storage_location` | object | yes | no |  |  |
| `items[].storage_location.code` | string | yes | no |  |  |
| `items[].storage_location.id` | string (`uuid`) | yes | no |  |  |
| `items[].version` | integer | yes | no |  |  |
| `items[].warehouse` | object | yes | no |  |  |
| `items[].warehouse.id` | string (`uuid`) | yes | no |  |  |
| `items[].warehouse.name` | string | yes | no |  |  |
| `next_cursor` | string | yes | yes |  |  |
| `previous_cursor` | string | yes | yes |  |  |
| `tenant_has_rows` | boolean | yes | no |  |  |

## StockMovement

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `id` | string (`uuid`) | yes | no |  |  |
| `inventory_item` | object | yes | no |  |  |
| `inventory_item.id` | string (`uuid`) | yes | no |  |  |
| `inventory_item.name` | string | yes | no |  |  |
| `inventory_item.sku` | string | yes | no |  |  |
| `occurred_at` | string (`date-time`) | yes | no |  |  |
| `quantity` | string | yes | no |  |  |
| `reason` | string | yes | no |  |  |
| `source_id` | string | yes | yes |  |  |
| `source_type` | string | yes | yes |  |  |
| `storage_location` | object | yes | no |  |  |
| `storage_location.code` | string | yes | no |  |  |
| `storage_location.id` | string (`uuid`) | yes | no |  |  |

## StockMovement.CreateStockAdjustmentInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `counted_quantity` | string | yes | no | minLength `1` |  |
| `inventory_item_id` | string (`uuid`) | yes | no |  |  |
| `note` | string | yes | no | minLength `1` |  |
| `storage_location_id` | string (`uuid`) | yes | no |  |  |
| `version` | integer | yes | yes |  |  |

## StockMovement.CreateStockTransferInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `destination_storage_location_id` | string (`uuid`) | yes | no |  |  |
| `inventory_item_id` | string (`uuid`) | yes | no |  |  |
| `note` | string | no | yes |  |  |
| `quantity` | string | yes | no | minLength `1` |  |
| `source_storage_location_id` | string (`uuid`) | yes | no |  |  |

## StockMovementCollection

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `items` | array of [StockMovement](schemas.md#stockmovement) | yes | no |  |  |
| `next_cursor` | string | yes | yes |  |  |
| `previous_cursor` | string | yes | yes |  |  |
| `tenant_has_rows` | boolean | yes | no |  |  |

## StorageLocation

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `archived_at` | string (`date-time`) | yes | yes |  |  |
| `code` | string | yes | no |  |  |
| `id` | string (`uuid`) | yes | no |  |  |
| `kind` | string: `bin`, `shelf`, `zone`, `staging`, `quarantine` | yes | no |  |  |

## StorageLocation.CreateStorageLocationInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `code` | string | yes | no | minLength `1` |  |
| `kind` | string: `bin`, `shelf`, `zone`, `staging`, `quarantine` | no | no | default `bin` |  |

## StorageLocation.UpdateStorageLocationInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `code` | string | no | no | minLength `1` |  |
| `kind` | string: `bin`, `shelf`, `zone`, `staging`, `quarantine` | no | no |  |  |

## StorageLocationCollection

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `items` | array of [StorageLocation](schemas.md#storagelocation) | yes | no |  |  |
| `next_cursor` | string | yes | yes |  |  |
| `previous_cursor` | string | yes | yes |  |  |
| `tenant_has_rows` | boolean | yes | no |  |  |

## Supplier

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `archived_at` | string (`date-time`) | yes | yes |  |  |
| `code` | string | yes | no |  |  |
| `country_code` | string | yes | no |  |  |
| `currency_code` | string | yes | no |  |  |
| `id` | string (`uuid`) | yes | no |  |  |
| `name` | string | yes | no |  |  |
| `payment_terms_days` | integer | yes | no |  |  |

## Supplier.CreateSupplierInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `code` | string | yes | no | minLength `1` |  |
| `country_code` | string | yes | no | minLength `1` |  |
| `currency_code` | string | yes | no | minLength `1` |  |
| `name` | string | yes | no | minLength `1` |  |
| `payment_terms_days` | integer | no | no |  |  |

## Supplier.UpdateSupplierInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `code` | string | no | no | minLength `1` |  |
| `country_code` | string | no | no | minLength `1` |  |
| `currency_code` | string | no | no | minLength `1` |  |
| `name` | string | no | no | minLength `1` |  |
| `payment_terms_days` | integer | no | no |  |  |

## SupplierCollection

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `items` | array of [Supplier](schemas.md#supplier) | yes | no |  |  |
| `next_cursor` | string | yes | yes |  |  |
| `previous_cursor` | string | yes | yes |  |  |
| `tenant_has_rows` | boolean | yes | no |  |  |

## SupplierItemReference

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `id` | string (`uuid`) | yes | no |  |  |
| `name` | string | yes | no |  |  |
| `sku` | string | yes | no |  |  |

## SupplierProduct

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `currency_code` | string | yes | no |  |  |
| `id` | string (`uuid`) | yes | no |  |  |
| `inventory_item` | [SupplierItemReference](schemas.md#supplieritemreference) | yes | no |  |  |
| `lead_time_days` | integer | yes | no |  |  |
| `minimum_order_quantity` | string | yes | no |  |  |
| `supplier_sku` | string | yes | no |  |  |
| `unit_cost` | string | yes | no |  |  |
| `unit_of_measure_code` | string: `EA`, `BOX`, `KG`, `L`, `M` | yes | no |  |  |

## SupplierProduct.CreateSupplierProductInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `inventory_item_id` | string (`uuid`) | yes | no |  |  |
| `lead_time_days` | integer | yes | no |  |  |
| `minimum_order_quantity` | string | no | no | minLength `1` |  |
| `supplier_sku` | string | yes | no | minLength `1` |  |
| `unit_cost` | string | yes | no | minLength `1` |  |

## SupplierProduct.UpdateSupplierProductInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `lead_time_days` | integer | no | no |  |  |
| `minimum_order_quantity` | string | no | no | minLength `1` |  |
| `supplier_sku` | string | no | no | minLength `1` |  |
| `unit_cost` | string | no | no | minLength `1` |  |

## SupplierProductCollection

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `items` | array of [SupplierProduct](schemas.md#supplierproduct) | yes | no |  |  |
| `next_cursor` | string | yes | yes |  |  |
| `previous_cursor` | string | yes | yes |  |  |
| `tenant_has_rows` | boolean | yes | no |  |  |

## SupplierReference

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `id` | string (`uuid`) | yes | no |  |  |
| `name` | string | yes | no |  |  |

## Token

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `access_token` | string | yes | no |  |  |
| `expires_at` | string (`date-time`) | yes | no |  |  |
| `token_type` | string | yes | no |  |  |

## Token.IssueTokenInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `email` | string | yes | no |  |  |
| `password` | string | yes | no |  |  |
| `workspace` | string | yes | no |  |  |

## User

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `archived_at` | string (`date-time`) | yes | yes |  |  |
| `display_name` | string | yes | no |  |  |
| `email` | string | yes | no |  |  |
| `id` | string (`uuid`) | yes | no |  |  |
| `is_active` | boolean | yes | no |  |  |
| `margin_exposure` | boolean | yes | no |  |  |
| `roles` | array of object | yes | no |  |  |
| `roles[].id` | string (`uuid`) | yes | no |  |  |
| `roles[].name` | string | yes | no |  |  |

## User.CreateUserInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `display_name` | string | yes | no | minLength `1` |  |
| `email` | string | yes | no | minLength `1` |  |

## User.UpdateUserInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `display_name` | string | no | no | minLength `1` |  |
| `is_active` | boolean | no | no |  |  |

## UserCollection

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `items` | array of [User](schemas.md#user) | yes | no |  |  |
| `next_cursor` | string | yes | yes |  |  |
| `previous_cursor` | string | yes | yes |  |  |
| `tenant_has_rows` | boolean | yes | no |  |  |

## UserDisplayReference

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `display_name` | string | yes | no |  |  |
| `id` | string (`uuid`) | yes | no |  |  |

## Warehouse

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `archived_at` | string (`date-time`) | yes | yes |  |  |
| `code` | string | yes | no |  |  |
| `country_code` | string | yes | no |  |  |
| `id` | string (`uuid`) | yes | no |  |  |
| `name` | string | yes | no |  |  |

## Warehouse.CreateWarehouseInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `code` | string | yes | no | minLength `1` |  |
| `country_code` | string | yes | no | minLength `1` |  |
| `name` | string | yes | no | minLength `1` |  |

## Warehouse.UpdateWarehouseInput

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `code` | string | no | no | minLength `1` |  |
| `country_code` | string | no | no | minLength `1` |  |
| `name` | string | no | no | minLength `1` |  |

## WarehouseCollection

| Field | Type | Required | Nullable | Limits | Description |
| --- | --- | --- | --- | --- | --- |
| `items` | array of [Warehouse](schemas.md#warehouse) | yes | no |  |  |
| `next_cursor` | string | yes | yes |  |  |
| `previous_cursor` | string | yes | yes |  |  |
| `tenant_has_rows` | boolean | yes | no |  |  |

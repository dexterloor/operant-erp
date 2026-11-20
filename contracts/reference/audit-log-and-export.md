# Audit log and export

Generated from `contracts/openapi.json` and `contracts/api-surface.json` by `make contracts`. Do not edit by hand.

## List audit log entries

`GET /api/v1/audit-log` · `ListAuditLogEntries`

Lists audit log entries, one page at a time.

**Who may call it:** `audit_log.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `actor_user_id` | query | string (`uuid`) | no | Only one person's events, newest first. Not with `event_type`: sending both is refused 422 `one_filter_at_a_time`. A person this workspace cannot see matches nothing: the answer is an empty page, never a 404. |
| `event_type` | query | string: `auth.login.success`, `auth.login.failure`, `auth.login.tenant_inactive`, `auth.login.credential_mismatch`, `auth.password_reset.issued`, `auth.credential.identity_mismatch`, `auth.password.reset`, `auth.password.changed`, `authz.permission.denied`, `data.export.completed`, `purchase_order.placed`, `purchase_order.cancelled`, `sales_order.cancelled`, `invoice.issued`, `invoice.paid`, `invoice.payment_reversed`, `invoice.voided`, `stock_movement.adjusted`, `role.assigned`, `role.revoked`, `role.assignment_proposed`, `role.assignment_lapsed`, `role.assignment_rejected`, `role.assignment_cancelled`, `role.permissions_changed`, `role.updated`, `role.deleted`, `role.restored`, `goods_receipt.created`, `sales_order.confirmed`, `fulfilment.created`, `sales_order.deleted`, `purchase_order.deleted`, `sales_return.created`, `credit_note.issued`, `credit_note.return_decided`, `customer.created`, `customer.updated`, `supplier.created`, `supplier.updated`, `supplier.restored`, `supplier.deleted`, `supplier_product.created`, `supplier_product.updated`, `supplier_product.deleted`, `inventory_item.deleted`, `inventory_item.restored`, `inventory_item.updated`, `tenant_settings.updated`, `customer.restored`, `customer.deleted`, `customer_address.created`, `customer_address.updated`, `customer_address.deleted`, `customer_contact.created`, `customer_contact.updated`, `customer_contact.deleted`, `warehouse.created`, `warehouse.updated`, `warehouse.restored`, `warehouse.deleted`, `sales_order.restored`, `purchase_order.restored`, `storage_location.created`, `storage_location.updated`, `storage_location.restored`, `storage_location.deleted`, `user.deactivated`, `user.reactivated`, `user.updated`, `user.deleted`, `user.restored`, `user.created` | no | Only one kind of event, newest first. Not with `actor_user_id`: sending both is refused 422 `one_filter_at_a_time`. |
| `limit` | query | integer | no | How many rows to return: 50 if not sent, at most 200. |
| `after` | query | string | no | The `next_cursor` of the previous page, to fetch the next one. Not with `before`. |
| `before` | query | string | no | The `previous_cursor` of a later page, to fetch the one before it. Not with `after`. |

**Responses**

- **200** One page of the audit log entry list. `next_cursor` is null on the last page; otherwise send it as `after` for the next page. [AuditLogEntryCollection](schemas.md#auditlogentrycollection) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [422](README.md#422)

## List audit records

`GET /api/v1/audit-log/records` · `ListAuditRecords`

Lists audit records, one page at a time.

**Who may call it:** `audit_log.view_record`, and the read permission of `subject_type`:

| `subject_type` | Permission |
| --- | --- |
| `app_user` | `app_user.view` |
| `credit_note` | `invoice.view` |
| `customer` | `customer.view` |
| `customer_address` | `customer.view` |
| `customer_contact` | `customer.view` |
| `fulfilment` | `fulfilment.view` |
| `goods_receipt` | `goods_receipt.view` |
| `inventory_item` | `inventory_item.view` |
| `invoice` | `invoice.view` |
| `purchase_order` | `purchase_order.view` |
| `role` | `role.view` |
| `sales_order` | `sales_order.view` |
| `sales_return` | `sales_return.view` |
| `stock_movement` | `stock_movement.view` |
| `storage_location` | `storage_location.view` |
| `supplier` | `supplier.view` |
| `supplier_product` | `supplier_product.view` |
| `tenant_settings` | `tenant_settings.view` |
| `warehouse` | `warehouse.view` |

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `subject_type` | query | string: `app_user`, `credit_note`, `customer`, `customer_address`, `customer_contact`, `fulfilment`, `goods_receipt`, `inventory_item`, `invoice`, `purchase_order`, `role`, `sales_order`, `sales_return`, `stock_movement`, `storage_location`, `supplier`, `supplier_product`, `tenant_settings`, `warehouse` | no | Required. The kind of record whose history to list, one of the listed values. You also need that kind of record's read permission (for example `invoice.view` for `credit_note`); without it the answer is 403 naming that permission. Leaving it out is refused 422 `required`, but only after the check for `audit_log.view_record`: a caller without it is told 403 first. |
| `subject_id` | query | string (`uuid`) | no | Required. The record's id. An id this workspace has no history for (unknown, another workspace's, or a record with no listed event) is an empty page, never a 404. |
| `limit` | query | integer | no | How many rows to return: 50 if not sent, at most 200. |
| `after` | query | string | no | The `next_cursor` of the previous page, to fetch the next one. Not with `before`. |
| `before` | query | string | no | The `previous_cursor` of a later page, to fetch the one before it. Not with `after`. |

**Responses**

- **200** One page of the audit record list. `next_cursor` is null on the last page; otherwise send it as `after` for the next page. [AuditRecordCollection](schemas.md#auditrecordcollection) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [422](README.md#422)

## Export a list

`POST /api/v1/exports` · `CreateExport`

Returns one list, filtered and sorted as asked, as a CSV file.

**Who may call it:** The permission depends on `resource`:

| `resource` | Permission |
| --- | --- |
| `app_user` | `app_user.view` |
| `credit_note` | `invoice.view` |
| `customer` | `customer.view` |
| `fulfilment` | `fulfilment.view` |
| `goods_receipt` | `goods_receipt.view` |
| `inventory_item` | `inventory_item.view` |
| `invoice` | `invoice.view` |
| `permission` | `role.view` |
| `purchase_order` | `purchase_order.view` |
| `role` | `role.view` |
| `sales_order` | `sales_order.view` |
| `sales_return` | `sales_return.view` |
| `stock_level` | `stock_level.view` |
| `stock_movement` | `stock_movement.view` |
| `supplier` | `supplier.view` |
| `warehouse` | `warehouse.view` |

**Request body:** [Export.CreateExportInput](schemas.md#exportcreateexportinput) (`application/json`) — Which list to export, and its filters and order.

**Responses**

- **200** The current filtered view of the named List, as CSV (RFC 4180, UTF-8 with a BOM, CRLF line ends). `text/csv`
- **Refusals** [401](README.md#401) · [403](README.md#403) · [409](README.md#409) · [422](README.md#422)

# Sales

Generated from `contracts/openapi.json` and `contracts/api-surface.json` by `make contracts`. Do not edit by hand.

## List credit notes

`GET /api/v1/credit-notes` · `ListCreditNotes`

Lists credit notes, one page at a time.

**Who may call it:** `invoice.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `invoice_id` | query | string (`uuid`) | no | Only the credit notes that reduce one invoice. An invoice this workspace cannot see matches nothing: the answer is an empty page, never a 404. |
| `customer_id` | query | string (`uuid`) | no | Only one customer's credit notes: those that reduce an invoice of that customer. |
| `sales_return_id` | query | string (`uuid`) | no | Only the credit notes issued for one return. A credit note that credits no return (an adjustment) never matches. |
| `q` | query | string | no | Search: a prefix of the `credit_note_number`. Nothing else is searched. |
| `limit` | query | integer | no | How many rows to return: 50 if not sent, at most 200. |
| `after` | query | string | no | The `next_cursor` of the previous page, to fetch the next one. Not with `before`. |
| `before` | query | string | no | The `previous_cursor` of a later page, to fetch the one before it. Not with `after`. |

**Responses**

- **200** One page of the credit note list. `next_cursor` is null on the last page; otherwise send it as `after` for the next page. [CreditNoteCollection](schemas.md#creditnotecollection) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [422](README.md#422)

## Get credit note

`GET /api/v1/credit-notes/{id}` · `GetCreditNote`

Returns one credit note.

**Who may call it:** `invoice.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The credit note's id. |

**Responses**

- **200** The credit note. [CreditNote](schemas.md#creditnote) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [422](README.md#422)

## List fulfilments

`GET /api/v1/fulfilments` · `ListFulfilments`

Lists fulfilments, one page at a time.

**Who may call it:** `fulfilment.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `sales_order_id` | query | string (`uuid`) | no | Only one order's shipments, newest shipment first. An order this workspace cannot see matches nothing: the answer is an empty page, never a 404. |
| `q` | query | string | no | Search: a prefix of the `fulfilment_number`. Nothing else is searched. |
| `limit` | query | integer | no | How many rows to return: 50 if not sent, at most 200. |
| `after` | query | string | no | The `next_cursor` of the previous page, to fetch the next one. Not with `before`. |
| `before` | query | string | no | The `previous_cursor` of a later page, to fetch the one before it. Not with `after`. |

**Responses**

- **200** One page of the fulfilment list. `next_cursor` is null on the last page; otherwise send it as `after` for the next page. [FulfilmentCollection](schemas.md#fulfilmentcollection) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [422](README.md#422)

## Get fulfilment

`GET /api/v1/fulfilments/{id}` · `GetFulfilment`

Returns one fulfilment.

**Who may call it:** `fulfilment.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The fulfilment's id. |

**Responses**

- **200** The fulfilment. [Fulfilment](schemas.md#fulfilment) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [422](README.md#422)

## Issue invoice

`POST /api/v1/fulfilments/{id}/invoices` · `IssueInvoice`

Issues one invoice.

**Who may call it:** `invoice.issue`

**Idempotency-Key:** required: send a fresh key with each new request, and the same key to retry it.

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The fulfilment's id. |

**Responses**

- **201** The invoice this request created. `Location` gives its address. [Invoice](schemas.md#invoice) (`application/json`) Header `Location`: The address of what this request created.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422) · [428](README.md#428)

## Create sales return

`POST /api/v1/fulfilments/{id}/sales-returns` · `CreateSalesReturn`

Creates one sales return.

**Who may call it:** `sales_return.create`

**Idempotency-Key:** required: send a fresh key with each new request, and the same key to retry it.

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The fulfilment's id. |

**Request body:** [SalesReturn.CreateSalesReturnInput](schemas.md#salesreturncreatesalesreturninput) (`application/json`) — The sales return to create.

**Responses**

- **201** The sales return this request created. `Location` gives its address. [SalesReturn](schemas.md#salesreturn) (`application/json`) Header `Location`: The address of what this request created.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422) · [428](README.md#428)

## List invoices

`GET /api/v1/invoices` · `ListInvoices`

Lists invoices, one page at a time.

**Who may call it:** `invoice.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `status` | query | string: `unpaid`, `paid`, `credited`, `void` | no | Which invoices to list: `unpaid` (issued and not credited in full; one past its due date shows as overdue in its row but is not filtered out), `paid`, `credited` (issued and credited in full, so it owes nothing), `void`. |
| `customer_id` | query | string (`uuid`) | no | Only one customer's invoices. A customer this workspace cannot see matches nothing: the answer is an empty page, never a 404. |
| `fulfilment_id` | query | string (`uuid`) | no | Only the invoices of one shipment, newest first. A shipment this workspace cannot see matches nothing: the answer is an empty page, never a 404. |
| `q` | query | string | no | Search: a prefix of the `invoice_number`. Nothing else is searched. |
| `sort` | query | string: `invoice_number`, `due_at`, `issued_at` | no | The order. `invoice_number` is always allowed. With `customer_id`, `issued_at` is also allowed and is the default; otherwise, with `status=unpaid`, `due_at` is also allowed and is the default. With `fulfilment_id`, only `invoice_number`, newest first. |
| `limit` | query | integer | no | How many rows to return: 50 if not sent, at most 200. |
| `after` | query | string | no | The `next_cursor` of the previous page, to fetch the next one. Not with `before`. |
| `before` | query | string | no | The `previous_cursor` of a later page, to fetch the one before it. Not with `after`. |

**Responses**

- **200** One page of the invoice list. `next_cursor` is null on the last page; otherwise send it as `after` for the next page. [InvoiceCollection](schemas.md#invoicecollection) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [422](README.md#422)

## Get invoice

`GET /api/v1/invoices/{id}` · `GetInvoice`

Returns one invoice.

**Who may call it:** `invoice.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The invoice's id. |

**Responses**

- **200** The invoice. [Invoice](schemas.md#invoice) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [422](README.md#422)

## Issue adjustment credit note

`POST /api/v1/invoices/{id}/credit-note-adjustments` · `IssueAdjustmentCreditNote`

Issues one adjustment credit note.

**Who may call it:** `credit_note.adjust`

**Idempotency-Key:** required: send a fresh key with each new request, and the same key to retry it.

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The invoice's id. |

**Request body:** [CreditNote.IssueAdjustmentCreditNoteInput](schemas.md#creditnoteissueadjustmentcreditnoteinput) (`application/json`) — The adjustment credit note to issue.

**Responses**

- **201** The credit note this request created. `Location` gives its address. [CreditNote](schemas.md#creditnote) (`application/json`) Header `Location`: The address of what this request created.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422) · [428](README.md#428)

## Issue credit note

`POST /api/v1/invoices/{id}/credit-notes` · `IssueCreditNote`

Issues one credit note.

**Who may call it:** `credit_note.issue`

**Idempotency-Key:** required: send a fresh key with each new request, and the same key to retry it.

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The invoice's id. |

**Request body:** [CreditNote.IssueCreditNoteInput](schemas.md#creditnoteissuecreditnoteinput) (`application/json`) — The credit note to issue.

**Responses**

- **201** The credit note this request created. `Location` gives its address. [CreditNote](schemas.md#creditnote) (`application/json`) Header `Location`: The address of what this request created.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422) · [428](README.md#428)

## List invoice lines

`GET /api/v1/invoices/{id}/lines` · `ListInvoiceLines`

Lists invoice lines.

**Who may call it:** `invoice.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The invoice's id. |

**Responses**

- **200** The invoice line list. It is not paged: `next_cursor` and `previous_cursor` are always null. [InvoiceLineCollection](schemas.md#invoicelinecollection) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [422](README.md#422)

## Record invoice payment

`POST /api/v1/invoices/{id}/payment` · `PayInvoice`

Records that one invoice was paid.

**Who may call it:** `invoice.pay`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The invoice's id. |

**Request body:** [Invoice.PayInvoiceInput](schemas.md#invoicepayinvoiceinput) (`application/json`)

**Responses**

- **200** The invoice, as saved. [Invoice](schemas.md#invoice) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Reverse invoice payment

`POST /api/v1/invoices/{id}/payment-reversal` · `ReverseInvoicePayment`

Reverses one invoice payment.

**Who may call it:** `invoice.pay`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The invoice's id. |

**Request body:** [Invoice.ReverseInvoicePaymentInput](schemas.md#invoicereverseinvoicepaymentinput) (`application/json`)

**Responses**

- **200** The invoice, as saved. [Invoice](schemas.md#invoice) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Void invoice

`POST /api/v1/invoices/{id}/void` · `VoidInvoice`

Voids one invoice.

**Who may call it:** `invoice.void`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The invoice's id. |

**Request body:** [Invoice.VoidInvoiceInput](schemas.md#invoicevoidinvoiceinput) (`application/json`)

**Responses**

- **200** The invoice, as saved. [Invoice](schemas.md#invoice) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## List pick queue entries

`GET /api/v1/pick-queue` · `ListPickQueueEntries`

Lists pick queue entries, one page at a time.

**Who may call it:** `fulfilment.create`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `warehouse_id` | query | string (`uuid`) | no | Only one warehouse's open orders. A warehouse this workspace cannot see matches nothing: the answer is an empty page, never a 404. |
| `q` | query | string | no | Search: a prefix of the order's `order_number`. Nothing else is searched. |
| `limit` | query | integer | no | How many rows to return: 50 if not sent, at most 200. |
| `after` | query | string | no | The `next_cursor` of the previous page, to fetch the next one. Not with `before`. |
| `before` | query | string | no | The `previous_cursor` of a later page, to fetch the one before it. Not with `after`. |

**Responses**

- **200** One page of the pick queue entry list. `next_cursor` is null on the last page; otherwise send it as `after` for the next page. [PickQueueEntryCollection](schemas.md#pickqueueentrycollection) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [422](README.md#422)

## List sales orders

`GET /api/v1/sales-orders` · `ListSalesOrders`

Lists sales orders, one page at a time.

**Who may call it:** `sales_order.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `status` | query | string: `open`, `draft`, `fulfilled`, `cancelled` | no | Which orders to list. `open` covers `confirmed` and `partially_fulfilled`. |
| `customer_id` | query | string (`uuid`) | no | Only one customer's orders. |
| `archived` | query | boolean | no | Return the archived (deleted) orders instead of the live ones, most recently archived first. Not with `status` or `customer_id`: sending either is refused 422 `mutually_exclusive`. |
| `q` | query | string | no | Search: a prefix of the `order_number`. Nothing else is searched. |
| `sort` | query | string: `order_number`, `ordered_at`, `archived_at` | no | The order. `order_number` is always allowed. `ordered_at` is allowed only with `status=open`, or with `customer_id` together with `status=open` or `status=fulfilled`; where it is allowed it is the default, elsewhere `order_number` is. With `archived=true`, only `archived_at`, newest first. |
| `limit` | query | integer | no | How many rows to return: 50 if not sent, at most 200. |
| `after` | query | string | no | The `next_cursor` of the previous page, to fetch the next one. Not with `before`. |
| `before` | query | string | no | The `previous_cursor` of a later page, to fetch the one before it. Not with `after`. |

**Responses**

- **200** One page of the sales order list. `next_cursor` is null on the last page; otherwise send it as `after` for the next page. [SalesOrderCollection](schemas.md#salesordercollection) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [422](README.md#422)

## Create sales order

`POST /api/v1/sales-orders` · `CreateSalesOrder`

Creates one sales order.

**Who may call it:** `sales_order.create`

**Idempotency-Key:** required: send a fresh key with each new request, and the same key to retry it.

**Request body:** [SalesOrder.CreateSalesOrderInput](schemas.md#salesordercreatesalesorderinput) (`application/json`) — The sales order to create.

**Responses**

- **201** The sales order this request created. `Location` gives its address. [SalesOrder](schemas.md#salesorder) (`application/json`) Header `Location`: The address of what this request created.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [409](README.md#409) · [422](README.md#422) · [428](README.md#428)

## Get sales order

`GET /api/v1/sales-orders/{id}` · `GetSalesOrder`

Returns one sales order.

**Who may call it:** `sales_order.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The sales order's id. |

**Responses**

- **200** The sales order. [SalesOrder](schemas.md#salesorder) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [422](README.md#422)

## Update sales order

`PATCH /api/v1/sales-orders/{id}` · `UpdateSalesOrder`

Changes one sales order.

**Who may call it:** `sales_order.edit`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The sales order's id. |

**Request body:** [SalesOrder.UpdateSalesOrderInput](schemas.md#salesorderupdatesalesorderinput) (`application/json`) — Only the fields to change. A field left out is not changed.

**Responses**

- **200** The sales order, as saved. [SalesOrder](schemas.md#salesorder) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Delete sales order

`DELETE /api/v1/sales-orders/{id}` · `DeleteSalesOrder`

Archives one sales order. `RestoreSalesOrder` brings it back.

**Who may call it:** `sales_order.delete`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The sales order's id. |

**Responses**

- **204** Done. The answer has no body.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Cancel sales order

`POST /api/v1/sales-orders/{id}/cancellation` · `CancelSalesOrder`

Cancels one sales order.

**Who may call it:** `sales_order.cancel`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The sales order's id. |

**Request body:** [SalesOrder.CancelSalesOrderInput](schemas.md#salesordercancelsalesorderinput) (`application/json`)

**Responses**

- **200** The sales order, as saved. [SalesOrder](schemas.md#salesorder) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Confirm sales order

`POST /api/v1/sales-orders/{id}/confirmation` · `ConfirmSalesOrder`

Confirms one sales order.

**Who may call it:** `sales_order.confirm`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The sales order's id. |

**Responses**

- **200** The sales order, as saved. [SalesOrder](schemas.md#salesorder) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Create fulfilment

`POST /api/v1/sales-orders/{id}/fulfilments` · `CreateFulfilment`

Creates one fulfilment.

**Who may call it:** `fulfilment.create`

**Idempotency-Key:** required: send a fresh key with each new request, and the same key to retry it.

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The sales order's id. |

**Request body:** [Fulfilment.CreateFulfilmentInput](schemas.md#fulfilmentcreatefulfilmentinput) (`application/json`) — The fulfilment to create.

**Responses**

- **201** The fulfilment this request created. `Location` gives its address. [Fulfilment](schemas.md#fulfilment) (`application/json`) Header `Location`: The address of what this request created.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422) · [428](README.md#428)

## List sales order lines

`GET /api/v1/sales-orders/{id}/lines` · `ListSalesOrderLines`

Lists sales order lines.

**Who may call it:** `sales_order.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The sales order's id. |
| `archived` | query | boolean | no | Return the archived lines instead of the live ones. |

**Responses**

- **200** The sales order line list. It is not paged: `next_cursor` and `previous_cursor` are always null. [SalesOrderLineCollection](schemas.md#salesorderlinecollection) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [422](README.md#422)

## Create sales order line

`POST /api/v1/sales-orders/{id}/lines` · `CreateSalesOrderLine`

Creates one sales order line.

**Who may call it:** `sales_order.edit`

**Idempotency-Key:** required: send a fresh key with each new request, and the same key to retry it.

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The sales order's id. |

**Request body:** [SalesOrderLine.CreateSalesOrderLineInput](schemas.md#salesorderlinecreatesalesorderlineinput) (`application/json`) — The sales order line to create.

**Responses**

- **201** The sales order line this request created. `Location` gives its address. [SalesOrderLine](schemas.md#salesorderline) (`application/json`) Header `Location`: The address of what this request created.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422) · [428](README.md#428)

## Update sales order line

`PATCH /api/v1/sales-orders/{id}/lines/{lineId}` · `UpdateSalesOrderLine`

Changes one sales order line.

**Who may call it:** `sales_order.edit`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The sales order's id. |
| `lineId` | path | string (`uuid`) | yes | The line's id. |

**Request body:** [SalesOrderLine.UpdateSalesOrderLineInput](schemas.md#salesorderlineupdatesalesorderlineinput) (`application/json`) — Only the fields to change. A field left out is not changed.

**Responses**

- **200** The sales order line, as saved. [SalesOrderLine](schemas.md#salesorderline) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Delete sales order line

`DELETE /api/v1/sales-orders/{id}/lines/{lineId}` · `DeleteSalesOrderLine`

Archives one sales order line. `RestoreSalesOrderLine` brings it back.

**Who may call it:** `sales_order.edit`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The sales order's id. |
| `lineId` | path | string (`uuid`) | yes | The line's id. |

**Responses**

- **204** Done. The answer has no body.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Restore sales order line

`POST /api/v1/sales-orders/{id}/lines/{lineId}/restoration` · `RestoreSalesOrderLine`

Restores one archived sales order line.

**Who may call it:** `sales_order.edit`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The sales order's id. |
| `lineId` | path | string (`uuid`) | yes | The line's id. |

**Responses**

- **200** The sales order line, as saved. [SalesOrderLine](schemas.md#salesorderline) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Get pick list

`GET /api/v1/sales-orders/{id}/pick-list` · `GetPickList`

Returns the pick list for one sales order.

**Who may call it:** `fulfilment.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The sales order's id. |

**Responses**

- **200** The pick list. [PickList](schemas.md#picklist) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [422](README.md#422)

## Restore sales order

`POST /api/v1/sales-orders/{id}/restoration` · `RestoreSalesOrder`

Restores one archived sales order.

**Who may call it:** `sales_order.delete`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The sales order's id. |

**Responses**

- **200** The sales order, as saved. [SalesOrder](schemas.md#salesorder) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## List sales returns

`GET /api/v1/sales-returns` · `ListSalesReturns`

Lists sales returns, one page at a time.

**Who may call it:** `sales_return.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `fulfilment_id` | query | string (`uuid`) | no | Only one shipment's returns. A shipment this workspace cannot see matches nothing: the answer is an empty page, never a 404. |
| `invoice_id` | query | string (`uuid`) | no | Only the returns recorded against one invoice (the invoice that was live when each was recorded). |
| `creditable` | query | string: `true`, `false` | no | `true`: the returns a credit note is owed for now; `false`: the others. Unlike the filters that need `invoice.view`, anyone who may list returns may use it. |
| `deducts` | query | string: `true`, `false` | no | `true`: the returns that lowered an invoice without a credit note, to reconcile against the goods actually received. |
| `credit_blocked_by_payment` | query | string: `true`, `false` | no | `true`: the returns that would be creditable but whose invoice is paid. Only for a caller holding `invoice.view`; anyone else is refused 422 `not_permitted`. |
| `adjustment_decision_owed` | query | string: `true`, `false` | no | `true`: the returns on an order line that already carries a credit without a return, which wait for a decision (already credited, or, by an owner, different goods) before they can be credited. Only for a caller holding `invoice.view`; anyone else is refused 422 `not_permitted`. |
| `adjustment_decided` | query | string: `same` | no | `same`: the returns carrying a live "already credited" decision, for the owner to review. Only for a caller holding `invoice.view`; anyone else is refused 422 `not_permitted`. |
| `decided_since` | query | string | no | With `adjustment_decided=same`: only the decisions recorded at or after this RFC 3339 instant; without `adjustment_decided` it is refused 422 `needs_adjustment_decided`. A decision's time is when its transaction started, so start each review a little before the last one. Only for a caller holding `invoice.view`; anyone else is refused 422 `not_permitted`. |
| `q` | query | string | no | Search: a prefix of the `sales_return_number`. Nothing else is searched. |
| `limit` | query | integer | no | How many rows to return: 50 if not sent, at most 200. |
| `after` | query | string | no | The `next_cursor` of the previous page, to fetch the next one. Not with `before`. |
| `before` | query | string | no | The `previous_cursor` of a later page, to fetch the one before it. Not with `after`. |

**Responses**

- **200** One page of the sales return list. `next_cursor` is null on the last page; otherwise send it as `after` for the next page. [SalesReturnCollection](schemas.md#salesreturncollection) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [422](README.md#422)

## Get sales return

`GET /api/v1/sales-returns/{id}` · `GetSalesReturn`

Returns one sales return.

**Who may call it:** `sales_return.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The sales return's id. |

**Responses**

- **200** The sales return. [SalesReturn](schemas.md#salesreturn) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [422](README.md#422)

## Record an adjustment decision

`POST /api/v1/sales-returns/{id}/adjustment-decisions` · `CreateAdjustmentDecision`

Records, or reverses, a decision about one line of one sales return.

**Who may call it:** `credit_note.issue`

**Idempotency-Key:** required: send a fresh key with each new request, and the same key to retry it.

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The sales return's id. |

**Request body:** [SalesReturn.CreateAdjustmentDecisionInput](schemas.md#salesreturncreateadjustmentdecisioninput) (`application/json`)

**Responses**

- **200** The sales return as it reads after the decision this request recorded. Each line lists its decisions in `adjustment_decisions`. [SalesReturn](schemas.md#salesreturn) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422) · [428](README.md#428)

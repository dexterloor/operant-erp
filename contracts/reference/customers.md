# Customers

Generated from `contracts/openapi.json` and `contracts/api-surface.json` by `make contracts`. Do not edit by hand.

## List customers

`GET /api/v1/customers` · `ListCustomers`

Lists customers, one page at a time.

**Who may call it:** `customer.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `archived` | query | boolean | no | Return the archived customers instead of the live ones, most recently archived first. |
| `q` | query | string | no | Search: a prefix of the `name`, ignoring case, or a prefix of the `code`, matched exactly as stored. |
| `sort` | query | string: `name`, `code`, `archived_at` | no | The order. Live customers: `name` (the default) or `code`. Archived customers: only `archived_at`, newest first. |
| `limit` | query | integer | no | How many rows to return: 50 if not sent, at most 200. |
| `after` | query | string | no | The `next_cursor` of the previous page, to fetch the next one. Not with `before`. |
| `before` | query | string | no | The `previous_cursor` of a later page, to fetch the one before it. Not with `after`. |

**Responses**

- **200** One page of the customer list. `next_cursor` is null on the last page; otherwise send it as `after` for the next page. [CustomerCollection](schemas.md#customercollection) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [422](README.md#422)

## Create customer

`POST /api/v1/customers` · `CreateCustomer`

Creates one customer.

**Who may call it:** `customer.create`

**Idempotency-Key:** required: send a fresh key with each new request, and the same key to retry it.

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `allow_shared_name` | query | boolean | no | Send `true` to go ahead when another customer, live or archived, already has this name. Two customers can share a name. |

**Request body:** [Customer.CreateCustomerInput](schemas.md#customercreatecustomerinput) (`application/json`) — The customer to create.

**Responses**

- **201** The customer this request created. `Location` gives its address. [Customer](schemas.md#customer) (`application/json`) Header `Location`: The address of what this request created.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [409](README.md#409) · [422](README.md#422) · [428](README.md#428)

## Get customer

`GET /api/v1/customers/{id}` · `GetCustomer`

Returns one customer.

**Who may call it:** `customer.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The customer's id. |

**Responses**

- **200** The customer. [Customer](schemas.md#customer) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [422](README.md#422)

## Update customer

`PATCH /api/v1/customers/{id}` · `UpdateCustomer`

Changes one customer.

**Who may call it:** `customer.edit`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The customer's id. |
| `allow_shared_name` | query | boolean | no | Send `true` to go ahead when another customer, live or archived, already has this name. Two customers can share a name. |

**Request body:** [Customer.UpdateCustomerInput](schemas.md#customerupdatecustomerinput) (`application/json`) — Only the fields to change. A field left out is not changed.

**Responses**

- **200** The customer, as saved. [Customer](schemas.md#customer) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Delete customer

`DELETE /api/v1/customers/{id}` · `DeleteCustomer`

Archives one customer. `RestoreCustomer` brings it back.

**Who may call it:** `customer.delete`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The customer's id. |

**Responses**

- **204** Done. The answer has no body.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## List customer addresses

`GET /api/v1/customers/{id}/addresses` · `ListCustomerAddresses`

Lists customer addresses.

**Who may call it:** `customer.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The customer's id. |

**Responses**

- **200** The customer address list. It is not paged: `next_cursor` and `previous_cursor` are always null. [CustomerAddressCollection](schemas.md#customeraddresscollection) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [422](README.md#422)

## Create customer address

`POST /api/v1/customers/{id}/addresses` · `CreateCustomerAddress`

Creates one customer address.

**Who may call it:** `customer.edit`

**Idempotency-Key:** required: send a fresh key with each new request, and the same key to retry it.

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The customer's id. |

**Request body:** [CustomerAddress.CreateCustomerAddressInput](schemas.md#customeraddresscreatecustomeraddressinput) (`application/json`) — The customer address to create.

**Responses**

- **201** The customer address this request created. `Location` gives its address. [CustomerAddress](schemas.md#customeraddress) (`application/json`) Header `Location`: The address of what this request created.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422) · [428](README.md#428)

## Update customer address

`PATCH /api/v1/customers/{id}/addresses/{addressId}` · `UpdateCustomerAddress`

Changes one customer address.

**Who may call it:** `customer.edit`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The customer's id. |
| `addressId` | path | string (`uuid`) | yes | The address's id. |

**Request body:** [CustomerAddress.UpdateCustomerAddressInput](schemas.md#customeraddressupdatecustomeraddressinput) (`application/json`) — Only the fields to change. A field left out is not changed.

**Responses**

- **200** The customer address, as saved. [CustomerAddress](schemas.md#customeraddress) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Delete customer address

`DELETE /api/v1/customers/{id}/addresses/{addressId}` · `DeleteCustomerAddress`

Removes one customer address for good.

**Who may call it:** `customer.edit`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The customer's id. |
| `addressId` | path | string (`uuid`) | yes | The address's id. |

**Responses**

- **204** Done. The answer has no body.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## List customer contacts

`GET /api/v1/customers/{id}/contacts` · `ListCustomerContacts`

Lists customer contacts.

**Who may call it:** `customer.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The customer's id. |

**Responses**

- **200** The customer contact list. It is not paged: `next_cursor` and `previous_cursor` are always null. [CustomerContactCollection](schemas.md#customercontactcollection) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [422](README.md#422)

## Create customer contact

`POST /api/v1/customers/{id}/contacts` · `CreateCustomerContact`

Creates one customer contact.

**Who may call it:** `customer.edit`

**Idempotency-Key:** required: send a fresh key with each new request, and the same key to retry it.

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The customer's id. |

**Request body:** [CustomerContact.CreateCustomerContactInput](schemas.md#customercontactcreatecustomercontactinput) (`application/json`) — The customer contact to create.

**Responses**

- **201** The customer contact this request created. `Location` gives its address. [CustomerContact](schemas.md#customercontact) (`application/json`) Header `Location`: The address of what this request created.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422) · [428](README.md#428)

## Update customer contact

`PATCH /api/v1/customers/{id}/contacts/{contactId}` · `UpdateCustomerContact`

Changes one customer contact.

**Who may call it:** `customer.edit`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The customer's id. |
| `contactId` | path | string (`uuid`) | yes | The contact's id. |

**Request body:** [CustomerContact.UpdateCustomerContactInput](schemas.md#customercontactupdatecustomercontactinput) (`application/json`) — Only the fields to change. A field left out is not changed.

**Responses**

- **200** The customer contact, as saved. [CustomerContact](schemas.md#customercontact) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Delete customer contact

`DELETE /api/v1/customers/{id}/contacts/{contactId}` · `DeleteCustomerContact`

Removes one customer contact for good.

**Who may call it:** `customer.edit`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The customer's id. |
| `contactId` | path | string (`uuid`) | yes | The contact's id. |

**Responses**

- **204** Done. The answer has no body.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Restore customer

`POST /api/v1/customers/{id}/restoration` · `RestoreCustomer`

Restores one archived customer.

**Who may call it:** `customer.delete`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The customer's id. |
| `allow_shared_name` | query | boolean | no | Send `true` to go ahead when another customer, live or archived, already has this name. Two customers can share a name. |

**Responses**

- **200** The customer, as saved. [Customer](schemas.md#customer) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

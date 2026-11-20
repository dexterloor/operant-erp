# Reorder forecast

Generated from `contracts/openapi.json` and `contracts/api-surface.json` by `make contracts`. Do not edit by hand.

## List reorder suggestions

`GET /api/v1/forecast/reorder` · `ListReorderSuggestions`

Lists reorder suggestions.

**Who may call it:** `forecast.view`, `inventory_item.view`, `purchase_order.view`, `stock_level.view`, `supplier.view`, `supplier_product.view`

**Responses**

- **200** The reorder suggestion list. It is not paged: `next_cursor` and `previous_cursor` are always null. [ReorderSuggestionCollection](schemas.md#reordersuggestioncollection) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [422](README.md#422)

## Get reorder summary

`GET /api/v1/forecast/reorder/summary` · `GetReorderSummary`

Returns the reorder summary.

**Who may call it:** `forecast.view`

**Responses**

- **200** The reorder summary. [ReorderSummary](schemas.md#reordersummary) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [422](README.md#422)

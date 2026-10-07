<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Domain;

/**
 * **PC-12's margin sources, committed so a role definition can be warned at runtime** — roles slice 4 C0 (design §4.6;
 * `SECURITY_MODEL.md` PC-12 clauses 1, 2 and 6; `RESIDUAL_GAPS.md` G-28 items 2 and 3).
 *
 * PC-12 clause 2: *"a source is the smallest set of codes whose joint holding reaches a classified column, derived from the
 * contract, never typed"*. A running request cannot regenerate the OpenAPI document, so the derivation is done by a TEST and
 * this table is what it is held equal to — `MarginSourcesDerivationTest` (clause 6's check) fails, naming the difference,
 * the moment an operation that serves a classified money field is added, removed or re-gated without this table moving.
 * The typed list is therefore one statement checked against an independent derivation, not a second source of truth.
 *
 *  - {@see COLUMNS}: PC-12 clause 1 — every `numeric(19,4)` column the server holds, classified `cost`, `sale` or
 *    `neither`. The test reads the domain from `pg_attribute`; an unclassified column fails, and so does a classification
 *    naming a column the server does not have.
 *  - {@see COST} / {@see SALE}: the sources. Each is a CODE SET (a conjunction; a role holds a source only if it holds every
 *    code in it). Today every built operation is gated on one code, so every set has one member; a five-code
 *    `ListReorderSuggestions` would be one five-member set.
 *
 * **What is honestly NOT a source yet, because nothing serves it:** `supplier_product.view` — PC-12's original cost code —
 * reaches `supplier_product.unit_cost` through no built operation, so it is absent here; the first supplier-product read
 * turns the derivation red until it is added. Ask (PC-12 clause 2's third bullet) has no built tool; the derivation test
 * fails if an AI module appears under `src/Modules/` so that half cannot arrive unaccounted for.
 *
 * **`credit_note.total_amount` and `credit_note_line.amount` are classified `sale`** at C0: a credit note reduces a sale
 * and its amounts are the invoice's sale price. PC-12 clause 1's written list (8 columns) predates both tables; `SEC` is
 * asked to confirm the classification (recorded in `docs/session13/roles-s4-build-progress.md`).
 */
final class MarginSources
{
    public const COST = 'cost';
    public const SALE = 'sale';
    public const NEITHER = 'neither';

    /** @var array<string, self::COST|self::SALE|self::NEITHER> `table.column` → class (PC-12 clause 1) */
    private const COLUMNS = [
        'credit_note.total_amount' => self::SALE,
        'credit_note_line.amount' => self::SALE,
        'customer.credit_limit' => self::NEITHER,
        'inventory_item.unit_cost' => self::COST,
        'invoice.total_amount' => self::SALE,
        'purchase_order.total_amount' => self::COST,
        'purchase_order_line.unit_cost' => self::COST,
        'sales_order.total_amount' => self::SALE,
        'sales_order_line.unit_price' => self::SALE,
        'supplier_product.unit_cost' => self::COST,
    ];

    /** @var list<list<string>> the cost sources — each a code set (PC-12 clause 2) */
    private const COST_SOURCES = [
        ['purchase_order.approve'],
        ['purchase_order.cancel'],
        ['purchase_order.create'],
        ['purchase_order.edit'],
        ['purchase_order.view'],
    ];

    /** @var list<list<string>> the sale sources — each a code set (PC-12 clause 2) */
    private const SALE_SOURCES = [
        ['credit_note.adjust'],
        ['credit_note.issue'],
        ['invoice.issue'],
        ['invoice.pay'],
        ['invoice.view'],
        ['invoice.void'],
        ['sales_order.cancel'],
        ['sales_order.confirm'],
        ['sales_order.create'],
        ['sales_order.edit'],
        ['sales_order.view'],
    ];

    /** Not instantiable: a table, not a service. */
    private function __construct()
    {
    }

    /** @return array<string, self::COST|self::SALE|self::NEITHER> */
    public static function columns(): array
    {
        return self::COLUMNS;
    }

    /** @return list<list<string>> */
    public static function costSources(): array
    {
        return self::COST_SOURCES;
    }

    /** @return list<list<string>> */
    public static function saleSources(): array
    {
        return self::SALE_SOURCES;
    }
}

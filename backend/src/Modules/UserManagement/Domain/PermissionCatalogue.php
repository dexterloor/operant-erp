<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Domain;

/**
 * `SECURITY_MODEL.md` §3's catalogue, committed and runtime-readable.
 *
 * ---------------------------------------------------------------------------
 * Why this class exists
 * ---------------------------------------------------------------------------
 * `V2-R-2` / ADR-008 open item **D2**: E-15e, E-18/PC-13, E-15f and
 * `roles_offered[]` must all derive from one home, and until this commit that
 * home was a **gitignored document** plus `docs/adr/sod_check.py`, which is not
 * committed either. Nothing in running code knew what the 60 codes were.
 *
 * **This file is a copy of §3 and the copy is the point.** `docs/` is gitignored,
 * so a runtime domain cannot be derived from it; the alternative to one committed
 * copy is a copy per consumer, which is the drift mechanism this project has
 * filed more often than any other. The reconciliation against §3 is
 * `DefaultRoleCatalogueDocumentTest`, which runs wherever the document is present.
 *
 * ---------------------------------------------------------------------------
 * PC-1a — the module value is namespace-shaped, verbatim
 * ---------------------------------------------------------------------------
 * The keys below are the `permission.module` column values: `Modules\Inventory`,
 * `Platform\Tenancy` — never bare `Inventory`, never lowercase. Nine of the
 * eleven are byte-identical to a value in `SchemaManifest`; `Modules\Ai` and
 * `Modules\Analytics` own no table and come from `ARCHITECTURE.md` §4's
 * **Namespace** column (SEC-O-12, closed).
 *
 * **In PHP a single-quoted `'Modules\\Inventory'` is the six-plus-one-plus-nine
 * character string `Modules\Inventory`.** The doubled backslash is the escape,
 * not part of the value.
 *
 * ---------------------------------------------------------------------------
 * What is deliberately NOT here
 * ---------------------------------------------------------------------------
 * No count. `size()` is `\count(self::codes())` and every check that wants the
 * number computes it — Rule 15. The one place a literal 60 is permitted is a test
 * file, where it is a pin the code is compared against rather than a figure the
 * code supplies to itself.
 */
final class PermissionCatalogue
{
    /**
     * §3's eleven module rows, expanded. The shorthand `inventory_item.view`
     * `.create` `.edit` `.delete` is expanded here so that no consumer ever has
     * to understand it.
     *
     * @var array<string, list<string>>
     */
    private const CATALOGUE = [
        'Modules\\Inventory' => [
            'inventory_item.view',
            'inventory_item.create',
            'inventory_item.edit',
            'inventory_item.delete',
            'stock_level.view',
            'stock_movement.view',
            'stock_movement.adjust',
        ],
        'Modules\\Warehouses' => [
            'warehouse.view',
            'warehouse.create',
            'warehouse.edit',
            'warehouse.delete',
            'storage_location.view',
            'storage_location.create',
            'storage_location.edit',
            'storage_location.delete',
        ],
        'Modules\\Suppliers' => [
            'supplier.view',
            'supplier.create',
            'supplier.edit',
            'supplier.delete',
            'supplier_product.view',
            'supplier_product.edit',
        ],
        'Modules\\Purchasing' => [
            'purchase_order.view',
            'purchase_order.create',
            'purchase_order.edit',
            'purchase_order.delete',
            'purchase_order.approve',
            'purchase_order.cancel',
            'goods_receipt.view',
            'goods_receipt.create',
        ],
        'Modules\\Customers' => [
            'customer.view',
            'customer.create',
            'customer.edit',
            'customer.delete',
        ],
        'Modules\\Sales' => [
            'sales_order.view',
            'sales_order.create',
            'sales_order.edit',
            'sales_order.delete',
            'sales_order.confirm',
            'sales_order.cancel',
            'fulfilment.view',
            'fulfilment.create',
            'invoice.view',
            'invoice.issue',
            'invoice.pay',
            'invoice.void',
            // Sales step 7 R2 (2026-10-01): owner rulings Q-F and Q-B. SoD-5 (`sales_return.create` / `credit_note.issue`)
            // enters with `credit_note.issue` at C1 (ADR-008 A2.7), never before its second code exists.
            'sales_return.view',
            'sales_return.create',
            // Sales step 7 C1 (2026-10-01): owner rulings Q-F (finance + owner) and ADR-008 A2.1b — it enters with its
            // SoD-3 entry and SoD-5's row in `SeparationOfDuty::ROWS`, in the same commit, never before (A2.7).
            'credit_note.issue',
            // Sales step 7 C1b (2026-10-01): owner ruling Q-A = B′, ADR-008 A2.1c — with its SoD-3 entry, owner-held.
            'credit_note.adjust',
        ],
        'Modules\\UserManagement' => [
            'app_user.view',
            'app_user.create',
            'app_user.edit',
            'app_user.delete',
            'role.view',
            'role.create',
            'role.edit',
            'role.delete',
            'role.assign',
        ],
        'Modules\\AuditLog' => [
            'audit_log.view',
            'audit_log.view_record',
        ],
        'Modules\\Analytics' => [
            'forecast.view',
        ],
        'Modules\\Ai' => [
            'ai_assistant.use',
        ],
        'Platform\\Tenancy' => [
            'tenant_settings.view',
            'tenant_settings.edit',
        ],
    ];

    /** Not instantiable: a catalogue, not a service. */
    private function __construct()
    {
    }

    /**
     * Every permission code, in §3's order.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        $codes = [];

        foreach (self::CATALOGUE as $module) {
            foreach ($module as $code) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * The module values, in §3's order — the `permission.module` column's domain.
     *
     * @return list<string>
     */
    public static function modules(): array
    {
        return array_keys(self::CATALOGUE);
    }

    /**
     * The codes one module owns.
     *
     * @return list<string>
     */
    public static function codesInModule(string $module): array
    {
        return self::CATALOGUE[$module] ?? [];
    }

    /** The module a code belongs to, or null when the code is not in the catalogue. */
    public static function moduleOf(string $code): ?string
    {
        foreach (self::CATALOGUE as $module => $codes) {
            if (\in_array($code, $codes, true)) {
                return $module;
            }
        }

        return null;
    }

    public static function has(string $code): bool
    {
        return null !== self::moduleOf($code);
    }

    /**
     * Every code whose verb is `view`.
     *
     * Derived by suffix rather than listed, which is what keeps
     * `audit_log.view_record` **out** — it is a second permission, not a `.view`
     * (PC-11), and `viewer`'s row and PC-12's exemption both turn on the
     * difference.
     *
     * @return list<string>
     */
    public static function viewCodes(): array
    {
        return array_values(array_filter(
            self::codes(),
            static fn (string $code): bool => str_ends_with($code, '.view'),
        ));
    }
}

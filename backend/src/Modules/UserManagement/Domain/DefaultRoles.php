<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Domain;

/**
 * `SECURITY_MODEL.md` §4 — the eight default roles, committed and
 * runtime-readable. `V2-R-2` / ADR-008 **D2**.
 *
 * ---------------------------------------------------------------------------
 * Three of the eight are DERIVED, five are enumerated, and the split is §4's
 * ---------------------------------------------------------------------------
 * §4 writes three of its rows as derivations and five as enumerations, and this
 * class follows it exactly rather than flattening everything into eight lists:
 *
 *  - **`owner`** — *"holds all 60"*. `PermissionCatalogue::codes()`.
 *  - **`admin`** — *"all 60 except …"*. The catalogue minus
 *    `AdministrativeCharter::excludedCodes()`, which is PC-14's `X(admin)` and is
 *    itself derived from ADR-008 §2 plus E-15b's marker. **Nothing about
 *    `admin` is typed in this file** — that is `V2-R-2`'s *fails if* clause.
 *  - **`viewer`** — *"every `.view` in §3 **except** `audit_log.view`,
 *    `supplier_product.view` and `purchase_order.view` … and two non-`.view`
 *    codes"*. Derived from `PermissionCatalogue::viewCodes()` with the three
 *    subtractions and two additions §4 names.
 *  - **`buyer`, `purchasing_manager`, `warehouse_operator`, `sales_rep`,
 *    `finance`** — *"the other six roles are enumerated in full"* (five here;
 *    `viewer` is the sixth and §4 writes its row as a derivation). Enumerated,
 *    because there is nothing to derive them from: they are choices.
 *
 * §4's own violation clause governs the enumerated rows — *"any row containing
 * the words 'plus', 'minus', 'as', or another role's name"* — so
 * `purchasing_manager` repeats `buyer`'s codes in full rather than saying
 * "`buyer` plus two". The duplication is the rule, not an oversight.
 *
 * ---------------------------------------------------------------------------
 * No count appears in this file
 * ---------------------------------------------------------------------------
 * Rule 15. `admin` is 54 *today*; that figure is a test pin compared against
 * `\count(self::codesFor('admin'))`, never a number this class supplies to
 * itself. The two places this project has been bitten — `sod_check.py`'s
 * `ADMIN_EXCL`/assert pair, and the stale `role sizes` line ADR-008 A1.2 carried
 * for two days — were both a figure validating the edit that chose it.
 *
 * ---------------------------------------------------------------------------
 * What this class is NOT
 * ---------------------------------------------------------------------------
 * It is **not a seeder** and it writes nothing. `role.is_system` (E-15h),
 * `role_permission` rows and the demo tenant are Output 16's other deliverable.
 * It is also not an authorisation path: PC-13 and E-18 are enforced at
 * assignment, E-15e at grant, and none of those operations exists yet
 * (`V2-C-1` gates them). This is the domain those rules will derive from — which
 * is exactly what `V2-R-2` asks for and all it asks for.
 */
final class DefaultRoles
{
    public const OWNER = 'owner';
    public const ADMIN = 'admin';
    public const BUYER = 'buyer';
    public const PURCHASING_MANAGER = 'purchasing_manager';
    public const WAREHOUSE_OPERATOR = 'warehouse_operator';
    public const SALES_REP = 'sales_rep';
    public const FINANCE = 'finance';
    public const VIEWER = 'viewer';

    /**
     * The five roles §4 enumerates in full.
     *
     * @var array<string, list<string>>
     */
    private const ENUMERATED = [
        self::BUYER => [
            'purchase_order.view',
            'purchase_order.create',
            'purchase_order.edit',
            'purchase_order.delete',
            'goods_receipt.view',
            'supplier.view',
            'supplier.create',
            'supplier.edit',
            'supplier.delete',
            'supplier_product.view',
            'supplier_product.edit',
            'inventory_item.view',
            'stock_level.view',
            'warehouse.view',
            'forecast.view',
            'ai_assistant.use',
            'audit_log.view_record',
        ],
        self::PURCHASING_MANAGER => [
            'purchase_order.view',
            'purchase_order.create',
            'purchase_order.edit',
            'purchase_order.delete',
            'purchase_order.approve',
            'purchase_order.cancel',
            'goods_receipt.view',
            'supplier.view',
            'supplier.create',
            'supplier.edit',
            'supplier.delete',
            'supplier_product.view',
            'supplier_product.edit',
            'inventory_item.view',
            'stock_level.view',
            'warehouse.view',
            'forecast.view',
            'ai_assistant.use',
            'audit_log.view_record',
        ],
        self::WAREHOUSE_OPERATOR => [
            'purchase_order.view',
            'goods_receipt.view',
            'goods_receipt.create',
            'fulfilment.view',
            'fulfilment.create',
            'stock_movement.view',
            'stock_movement.adjust',
            'stock_level.view',
            'inventory_item.view',
            'warehouse.view',
            'warehouse.edit',
            'storage_location.view',
            'storage_location.edit',
            'forecast.view',
            'ai_assistant.use',
            'audit_log.view_record',
        ],
        self::SALES_REP => [
            'sales_order.view',
            'sales_order.create',
            'sales_order.edit',
            'sales_order.delete',
            'sales_order.confirm',
            'sales_order.cancel',
            'fulfilment.view',
            'invoice.view',
            'invoice.issue',
            'customer.view',
            'customer.create',
            'customer.edit',
            'customer.delete',
            'inventory_item.view',
            'stock_level.view',
            'warehouse.view',
            'forecast.view',
            'ai_assistant.use',
            'audit_log.view_record',
        ],
        self::FINANCE => [
            'invoice.view',
            'invoice.pay',
            'forecast.view',
            'ai_assistant.use',
            'audit_log.view_record',
        ],
    ];

    /**
     * The three `.view` codes §4 withholds from `viewer`.
     *
     * `audit_log.view` is PC-11's administrative half. `supplier_product.view`
     * and `purchase_order.view` are the owner's cost ruling of 2026-09-15
     * (`SESSION_LOG.md` Move 200): a read that exists to withhold supplier cost
     * from a role that has no reason to see it.
     */
    private const VIEWER_WITHHELD_VIEWS = [
        'audit_log.view',
        'supplier_product.view',
        'purchase_order.view',
    ];

    /** §4: *"and two non-`.view` codes"*. */
    private const VIEWER_NON_VIEW_CODES = [
        'audit_log.view_record',
        'ai_assistant.use',
    ];

    /** Not instantiable: a catalogue, not a service. */
    private function __construct()
    {
    }

    /**
     * The eight role names, in §4's order (`owner` and `admin` first, as the two
     * rows §4 states in prose, then its table's six).
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return [
            self::OWNER,
            self::ADMIN,
            self::BUYER,
            self::PURCHASING_MANAGER,
            self::WAREHOUSE_OPERATOR,
            self::SALES_REP,
            self::FINANCE,
            self::VIEWER,
        ];
    }

    public static function isDefault(string $role): bool
    {
        return \in_array($role, self::names(), true);
    }

    /**
     * One role's permission set. An unknown name yields the empty set — callers
     * that must not accept one check `isDefault()` first.
     *
     * @return list<string>
     */
    public static function codesFor(string $role): array
    {
        return match ($role) {
            // §4: "holds all 60".
            self::OWNER => PermissionCatalogue::codes(),

            // §4 + PC-14: the catalogue minus X(admin). Derived end to end —
            // ADR-008 §2's controlled halves plus E-15b's marker.
            self::ADMIN => array_values(array_diff(
                PermissionCatalogue::codes(),
                AdministrativeCharter::excludedCodes(),
            )),

            self::VIEWER => self::viewerCodes(),

            default => self::ENUMERATED[$role] ?? [],
        };
    }

    /**
     * Every default role's set.
     *
     * @return array<string, list<string>>
     */
    public static function all(): array
    {
        $roles = [];

        foreach (self::names() as $name) {
            $roles[$name] = self::codesFor($name);
        }

        return $roles;
    }

    /**
     * §4's `viewer` row, derived exactly as it is written.
     *
     * The suffix filter is what keeps `audit_log.view_record` out of the
     * "every `.view`" half and puts it back explicitly in the second — which is
     * PC-11's whole point, and the reason `viewer` is 15 + 2 rather than 16 + 1.
     *
     * @return list<string>
     */
    private static function viewerCodes(): array
    {
        $codes = array_values(array_diff(
            PermissionCatalogue::viewCodes(),
            self::VIEWER_WITHHELD_VIEWS,
        ));

        foreach (self::VIEWER_NON_VIEW_CODES as $code) {
            if (!\in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }
}

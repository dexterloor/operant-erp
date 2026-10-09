<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use App\Platform\Http\Idempotency\IdempotencyKeyHash;
use App\Platform\Http\Idempotency\RequestDigest;
use Symfony\Component\Uid\Uuid;

/**
 * Fixtures for the `/purchase-orders` slice.
 *
 * ---------------------------------------------------------------------------
 * Why these are here and not on `TenancyTestCase`
 * ---------------------------------------------------------------------------
 * `seedSupplier()` and `seedItem()` live on the shared tenancy rig because the
 * isolation suite needs them. Nothing outside these five files needs a purchase
 * order, and a fixture on the shared rig is a fixture every test in the project
 * has to keep working.
 *
 * ---------------------------------------------------------------------------
 * ⚠ `seedOrder()` writes `created_by_user_id` DIRECTLY, and that is the whole
 * reason this class exists
 * ---------------------------------------------------------------------------
 * DSoD-1 is a comparison against `purchase_order.created_by_user_id`, so testing
 * it needs orders with a **known** creator — and `CreatePurchaseOrder`, the
 * operation §8.1 says *"sets it server-side from the JWT subject on every
 * user-initiated create"*, is not in this package. The fixture therefore does
 * what that operation will do, and takes the creator as an argument so a test
 * can name all four of E-19's cases:
 *
 *   creator ≠ caller            → approval allowed
 *   creator = caller            → refused, `business-rule-violated`
 *   creator = caller, `owner`   → allowed, exempt **by name**
 *   creator NULL                → allowed (R-6, and it is the rule returning
 *                                 true rather than an exemption)
 *
 * **This is a fixture standing in for an unbuilt operation and it is labelled as
 * one.** The day `CreatePurchaseOrder` ships, §8.1's fifth check —
 * `::everyHttpCreatedOrderRecordsItsCreator` — is what proves the real path sets
 * the column, and these fixtures stop being the only writer of it.
 */
abstract class PurchaseOrderTestCase extends ApiTestCase
{
    /** The last number {@see self::freshNumber()} handed out; the band 300001+ is reserved for it. */
    private static int $lastFreshNumber = 300000;

    /**
     * A document number of the product's own shape (`PREFIX-dddddd`), unique for the whole process, for a
     * fixture that seeds orders in a loop or from a shared helper and cannot name each one by hand.
     *
     * Added with CD-7 (2026-09-28): every `order_number` / `fulfilment_number` / `receipt_number` is pinned to
     * six digits by a CHECK, so the free-text suffixes these seeds used (`SO-ARC-<tag>-<random hex>`,
     * `SO-EW-<md5>`, …) are no longer writable. **A counter, never a random suffix:** a random six-digit
     * number can collide with another seed on `*_tenant_number_live` and turn a green test into a flaky 23505
     * (`RED` F7). **Spaced 100 apart**, because the writers mint `MAX + 1`: a test that seeds one of these and
     * then creates through the API must not mint the number the next call would hand out. The band (300100 and
     * up) is clear of the hand-named seeds (000001–000009, and 101000–216000 spaced 1000 apart for the same
     * reason), of the list fixtures (900001+), and far enough below 999,999 to leave every test headroom.
     */
    protected static function freshNumber(string $prefix): string
    {
        self::$lastFreshNumber += 100;
        if (self::$lastFreshNumber >= 900000) {
            throw new \LogicException('freshNumber() ran into the list fixtures\' band (900001+).');
        }

        return \sprintf('%s-%06d', $prefix, self::$lastFreshNumber);
    }

    /** @return string the new row's id */
    protected function seedWarehouse(string $tenantId, string $name = 'Main'): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant($tenantId, <<<'SQL'
            INSERT INTO warehouse (id, tenant_id, code, name, country_code)
            VALUES (?::uuid, ?::uuid, ?, ?, 'DE')
            SQL, [$id, $tenantId, strtoupper(substr(md5($name.$id), 0, 8)), $name]);

        return $id;
    }

    /**
     * ⚠ **THREE SALES fixtures on the purchase-order rig, and the reason is a pin rather than a taxonomy.**
     * *(Two until `GetSalesOrder` shipped on 2026-09-19 and added `seedSalesOrder` below; the count is
     * written here rather than left at "two" because the paragraph is the one that explains why they are
     * here at all.)*
     *
     * `OperationSuccessRecipes` is a trait declared `@phpstan-require-extends PurchaseOrderTestCase`
     * (`:26`), and every registry-wide check that drives each built operation — `ResponseStatusTest`,
     * `ResponseShapeTest`, `WireScaleTest`, `ErrorContractTest` — consumes it from a subclass of this
     * class. `CreateSalesOrder`'s recipe therefore has to live in that trait (RED F-12: a separate
     * `SalesOrderTestCase` does not satisfy the pin), and a recipe can only call fixtures this class
     * offers. Putting them on `TenancyTestCase` instead would make every test in the project responsible
     * for keeping them working, which is the rule this class's own docblock states.
     *
     * The honest alternatives are to rename this class or to key the trait to a shared rig. Both are
     * refactors of a pinned test surface and neither belongs in the commit that ships the first sales
     * writer.
     *
     * As the owner, not `erp_app`: no `src/` path writes `customer` or `customer_address`, so after
     * ADR-002 Amendment 4 `erp_app` holds SELECT on both and nothing else.
     *
     * @return string the new row's id
     */
    protected function seedCustomer(string $tenantId, string $name = 'Acme Retail', string $countryCode = 'GB'): string
    {
        $id = Uuid::v4()->toRfc4122();
        // `code`, `country_code` (customers slice 1 C0) and `currency_code` are NOT NULL with no usable default.
        //
        // ⚠ The code carries a LETTER PREFIX on purpose (`docs/RESIDUAL_GAPS.md` CD-3, customers slice 1 C0): eight hex
        // characters alone are all-digit with probability (10/16)^8, about 2.3% per customer, and from C1 `code` is on the
        // wire under `ListCustomers`, where `WireScaleTest` reads an all-digit string as a scale-0 decimal and goes red by
        // chance. `C-` makes that impossible rather than unlikely.
        $this->insertAsOwnerForTenant($tenantId, <<<'SQL'
            INSERT INTO customer (id, tenant_id, code, name, country_code, currency_code)
            VALUES (?::uuid, ?::uuid, ?, ?, ?, 'EUR')
            SQL, [$id, $tenantId, 'C-'.strtoupper(substr(md5($name.$id), 0, 8)), $name, $countryCode]);

        return $id;
    }

    /**
     * A `sales_order` written as the owner, with **no creator and no lines** — the state no built
     * operation can produce, which is exactly what makes it a fixture and not a second copy of
     * `CreateSalesOrder` (this class's *"a fixture standing in for an unbuilt operation"*).
     *
     * Added with `GetSalesOrder` because three registry-wide checks reached for it at once and none of
     * them was predicted: `ArchivedRowSubjects` needs a soft `sales_order` pair now that the table has a
     * built direct-hit `Get`, `TenantClaimProbeTest` needs a subject for that `Get` in both tenants, and
     * `SalesOrderReadTest` needs the null-creator case.
     *
     * `idempotency_key` and `request_digest` are `NOT NULL` since `Version20260919000100` (ruling R-C) and
     * 64 lower-case hex by `CHECK`. They are **generated, not literal**: the live-row unique is per
     * tenant, so a literal would collide the moment a caller seeds two orders — which
     * `ArchivedRowSubjects` does on every run.
     *
     * ⚠ **`$status` and `$orderedAt` are independent parameters, and on this table that is not sloppiness
     * — it is the schema.** `sales_order` carries **no `ordered_at` CHECK at all** (measured against
     * `pg_constraint` on 2026-09-28: the six are `id_is_uuid_v4`, `idempotency_key_sha256`,
     * `request_digest_sha256`, `status_values`, `archived_only_while_draft` and CD-7's `number_shape`, none of
     * which reads `ordered_at`), unlike `purchase_order`, whose `purchase_order_ordered_at_set` is what lets
     * `seedOrder` derive the date from the status. So every `(status, ordered_at)` combination this
     * fixture can write is one the database accepts — including `cancelled` with no date, which is
     * exactly what `CreateSalesOrder` → `CancelSalesOrder` produces today, and which is why
     * `SalesOrderViews::legalSorts()` refuses `ordered_at` on that view.
     *
     * @return string the new row's id
     */
    protected function seedSalesOrder(
        string $tenantId,
        string $number,
        ?string $customerId = null,
        ?string $warehouseId = null,
        string $status = SalesOrder::STATUS_DRAFT,
        ?string $orderedAt = null,
    ): string {
        $customer = $customerId ?? $this->seedCustomer($tenantId, 'Customer '.$number);
        $warehouse = $warehouseId ?? $this->seedWarehouse($tenantId, 'WH '.$number);
        $id = Uuid::v4()->toRfc4122();

        $this->insertAsOwnerForTenant($tenantId, <<<'SQL'
            INSERT INTO sales_order
                (id, tenant_id, order_number, customer_id, warehouse_id, currency_code, ship_to_country_code,
                 status, ordered_at, idempotency_key, request_digest)
            VALUES (?::uuid, ?::uuid, ?, ?::uuid, ?::uuid, 'EUR', 'GB',
                    ?, ?::timestamptz,
                    encode(sha256(gen_random_uuid()::text::bytea), 'hex'),
                    encode(sha256(gen_random_uuid()::text::bytea), 'hex'))
            SQL, [$id, $tenantId, $number, $customer, $warehouse, $status, $orderedAt]);

        return $id;
    }

    /**
     * A live `sales_order_line`, written as the owner.
     *
     * Added with `ConfirmSalesOrder`, which is the first operation for which *"a sales order"* is not a
     * sufficient fixture: its line guard refuses an order with no live line, so every caller that must
     * reach the **success** path needs one. `seedSalesOrder` deliberately seeds none — the zero-line draft
     * is itself a case this commit tests — so the two are separate helpers rather than a flag.
     *
     * `line_number` is a parameter because `sales_order_line_unique` is partial on
     * `(tenant_id, sales_order_id, line_number)`: two lines on one order must differ.
     *
     * ⚠ **`$quantityFulfilled` is a parameter and nothing in `src/` can produce a non-zero one**
     * (`RESIDUAL_GAPS.md` G-7 — `CreateFulfilment` is unbuilt). It is here so that
     * `SalesOrderLineReadTest` can drive the **subtraction** half of `quantity_outstanding` at all: with
     * every row at `0`, that half is untested and only the V2-C-14(b) status clause is doing work. The
     * defaults changed from `1, 1` to `10.000000, 2.5000` with `ListSalesOrderLines` so a read can tell
     * `quantity` from `unit_price` and both from a positional mistake.
     *
     * @return string the new row's id
     */
    protected function seedSalesOrderLine(
        string $tenantId,
        string $orderId,
        string $itemId,
        int $lineNumber = 1,
        string $quantity = '10.000000',
        string $unitPrice = '2.5000',
        string $quantityFulfilled = '0.000000',
    ): string {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant($tenantId, <<<'SQL'
            INSERT INTO sales_order_line
                (id, tenant_id, sales_order_id, inventory_item_id, line_number, quantity, quantity_fulfilled, unit_price)
            VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, ?::numeric, ?::numeric, ?::numeric)
            SQL, [$id, $tenantId, $orderId, $itemId, $lineNumber, $quantity, $quantityFulfilled, $unitPrice]);

        return $id;
    }

    /**
     * An address of a customer. `kind` is a parameter rather than a constant because V2-S-9's refusal is
     * *"a shipping address of THIS customer"*, and a test that cannot seed a `billing` one cannot show the
     * kind half of that rule firing.
     *
     * ⚠ **The default postal code deliberately is not all digits, and that is a finding rather than a
     * flourish.** `WireScaleTest`'s walker decides what a decimal is from the **value** — its pattern is
     * `-?\d+(\.\d+)?` — so a numeric postal code such as Berlin's `10115` is recorded in the committed
     * `wire-scale.json` as `CreateSalesOrder $.ship_to_postal_code: [0]`, a `text` column pinned as a
     * decimal at scale 0. Nothing else on this API had an all-digit string field before; `receipt_number`
     * escapes only because `GR-` is a prefix. A false entry in a contract artefact is worse than no entry,
     * and changing the fixture is the one-line answer available to this commit. **Making the walker
     * field-aware is the real fix and it belongs to whoever owns `WireScaleTest`** — a list of
     * "fields that are not decimals" is otherwise the second copy of a set nobody maintains.
     *
     * @return string the new row's id
     */
    protected function seedCustomerAddress(
        string $tenantId,
        string $customerId,
        string $kind = 'shipping',
        string $addressLine = '9 Threadneedle Street',
        string $city = 'London',
        string $postalCode = 'EC2R 8AH',
        string $countryCode = 'GB',
    ): string {
        $id = Uuid::v4()->toRfc4122();
        // `idempotency_key` and `request_digest` are NOT NULL on a TOTAL tenant-wide unique (customers slice 1 C0,
        // Version20261009000200), and this helper runs many times per tenant, so each row mints a DISTINCT random
        // 64-hex value for both. They stand for a request no test replays; a test that replays uses the API.
        $this->insertAsOwnerForTenant($tenantId, <<<'SQL'
            INSERT INTO customer_address
                (id, tenant_id, customer_id, kind, address_line, city, postal_code, country_code, idempotency_key, request_digest)
            VALUES (?::uuid, ?::uuid, ?::uuid, ?, ?, ?, ?, ?, ?, ?)
            SQL, [$id, $tenantId, $customerId, $kind, $addressLine, $city, $postalCode, $countryCode,
                hash('sha256', 'key:'.Uuid::v4()->toRfc4122()), hash('sha256', 'digest:'.Uuid::v4()->toRfc4122())]);

        return $id;
    }

    /**
     * A purchase order in whatever state a test needs.
     *
     * `ordered_at` follows `purchase_order_ordered_at_set` — as of
     * `Version20260911000200`, `CHECK (status IN ('draft', 'cancelled') OR
     * ordered_at IS NOT NULL)` — rather than being passed in, so a fixture cannot
     * construct a row the database would refuse and then have the test fail
     * somewhere else entirely. **A `cancelled` fixture therefore keeps whatever
     * `ordered_at` it is given, including none**, which is the case that
     * constraint was widened to admit: an order cancelled while still a draft was
     * never placed.
     *
     * ⚠ **`idempotency_key` is written on every seeded row, and that is not
     * tidiness.** `Version20260911000200` installs
     * `purchase_order_idempotency_key_required`, a `BEFORE INSERT` trigger that
     * refuses an insert carrying no key — so this fixture and
     * `CreatePurchaseOrder` are now under the same rule, which is the whole
     * content of the trigger. A test wanting the **historical** shape (a row
     * raised before step 5, with a NULL key) cannot get one from here and should
     * not: those rows predate the migration and no insert creates them any more.
     *
     * The default is a fresh UUID rather than a fixed string because the column
     * is on a live-row unique per tenant, and two fixtures sharing a literal
     * would collide in any test that seeds two orders.
     *
     * **Since D-B the fixture writes what `CreatePurchaseOrder` writes, not the
     * raw key.** `$idempotencyKey` is the header value a test would send; the row
     * gets its hex SHA-256, and a `request_digest` equal to what the create path
     * computes for the request that would have produced this row — the same
     * supplier, warehouse and `expected_at`, with `currency_code` **omitted**
     * (null): the create that left the currency to an `EUR` workspace, which
     * every fixture workspace is and which the insert writes. That matches
     * `PurchaseOrderCreateTest::body()`, which omits the currency, so a test that
     * replays a seeded order with its raw key and the same supplier and
     * warehouse gets the replay. ⚠ Sending `currency_code` in that replay is a
     * different request, and is a 409. The database refuses
     * a raw key and refuses a key without a digest (`Version20260911000300`), so
     * a fixture that did anything else would fail at the insert rather than lie.
     *
     * @return string the new row's id
     */
    protected function seedOrder(
        string $tenantId,
        string $orderNumber,
        string $supplierId,
        string $warehouseId,
        ?string $createdByUserId = null,
        string $status = PurchaseOrder::STATUS_DRAFT,
        ?string $orderedAt = null,
        ?string $expectedAt = null,
        bool $archived = false,
        ?string $idempotencyKey = null,
    ): string {
        $id = Uuid::v4()->toRfc4122();

        $this->insertAsOwnerForTenant($tenantId, <<<'SQL'
            INSERT INTO purchase_order
                (id, tenant_id, order_number, supplier_id, warehouse_id, created_by_user_id,
                 status, currency_code, ordered_at, expected_at, deleted_at, idempotency_key,
                 request_digest)
            VALUES
                (?::uuid, ?::uuid, ?, ?::uuid, ?::uuid, ?::uuid,
                 ?, 'EUR', ?::timestamptz, ?::timestamptz, ?::timestamptz, ?,
                 ?)
            SQL, [
                $id,
                $tenantId,
                $orderNumber,
                $supplierId,
                $warehouseId,
                $createdByUserId,
                $status,
                \in_array($status, [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_CANCELLED], true)
                    ? $orderedAt
                    : ($orderedAt ?? '2026-01-01T00:00:00+00:00'),
                $expectedAt,
                $archived ? '2026-01-02T00:00:00+00:00' : null,
                IdempotencyKeyHash::of($idempotencyKey ?? Uuid::v4()->toRfc4122()),
                RequestDigest::of('CreatePurchaseOrder', [
                    'supplier_id' => RequestDigest::uuid($supplierId),
                    'warehouse_id' => RequestDigest::uuid($warehouseId),
                    'currency_code' => null,
                    'expected_at' => RequestDigest::instant($expectedAt),
                ]),
            ]);

        return $id;
    }

    /**
     * A line, with the `line_number` and `lead_time_days` the write path mints
     * and snapshots. Both are `NOT NULL` and neither is an accepted field.
     *
     * @return string the new row's id
     */
    protected function seedLine(
        string $tenantId,
        string $orderId,
        string $itemId,
        int $lineNumber = 1,
        string $quantity = '10.000000',
        string $unitCost = '2.5000',
        string $quantityReceived = '0.000000',
        int $leadTimeDays = 7,
    ): string {
        $id = Uuid::v4()->toRfc4122();

        $this->insertAsOwnerForTenant($tenantId, <<<'SQL'
            INSERT INTO purchase_order_line
                (id, tenant_id, purchase_order_id, inventory_item_id, line_number,
                 quantity_ordered, quantity_received, unit_cost, lead_time_days)
            VALUES
                (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, ?::numeric, ?::numeric, ?::numeric, ?)
            SQL, [$id, $tenantId, $orderId, $itemId, $lineNumber, $quantity, $quantityReceived, $unitCost, $leadTimeDays]);

        return $id;
    }

    /**
     * The supplier's catalogue entry for an item — F-2 step 3's source for the
     * lead time `CreatePurchaseOrderLine` snapshots. Without one the operation
     * refuses the line, which is a case the write test drives on purpose.
     */
    protected function seedSupplierProduct(string $tenantId, string $supplierId, string $itemId, int $leadTimeDays = 7): void
    {
        $this->insertAsOwnerForTenant($tenantId, <<<'SQL'
            INSERT INTO supplier_product
                (id, tenant_id, supplier_id, inventory_item_id, supplier_sku, lead_time_days, unit_cost, currency_code)
            VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, ?, 1.0000, 'EUR')
            SQL, [Uuid::v4()->toRfc4122(), $tenantId, $supplierId, $itemId, 'SP-'.substr(md5($itemId), 0, 8), $leadTimeDays]);
    }

    /**
     * *(Replaced `grantRoleNamed()`, a role with a name and no codes, at C6a: DSoD-1's exemption stopped being a name.)*
     * A role named `$roleName` conferring exactly `$codes`, granted to `$userId` (sales step 6, C6a). The caller's
     * permission set is derived live on each request (`IdentityReader`), so the grant takes effect on the next one.
     *
     * @param list<string> $codes
     */
    protected function grantRoleWithCodes(string $tenantId, string $userId, string $roleName, array $codes): void
    {
        $roleId = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant($tenantId,
            'INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, ?)',
            [$roleId, $tenantId, $roleName]);
        $this->insertAsOwnerForTenant($tenantId,
            'INSERT INTO user_role (id, tenant_id, user_id, role_id) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid)',
            [Uuid::v4()->toRfc4122(), $tenantId, $userId, $roleId]);
        foreach ($codes as $code) {
            $this->insertAsOwnerForTenant($tenantId,
                'INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (?::uuid, ?::uuid, ?::uuid, ?)',
                [Uuid::v4()->toRfc4122(), $tenantId, $roleId, $code]);
        }
    }

    /**
     * A complete draft order with one supplier, one warehouse and one item, and
     * the ids a test needs to address any of them.
     *
     * @return array{order: string, supplier: string, warehouse: string, item: string}
     */
    protected function seedDraft(string $tenantId, string $orderNumber = 'PO-000001', ?string $createdByUserId = null): array
    {
        $supplier = $this->seedSupplier($tenantId, 'Acme '.$orderNumber);
        $warehouse = $this->seedWarehouse($tenantId, 'WH '.$orderNumber);
        $item = $this->seedItem($tenantId, 'SKU-'.$orderNumber);

        return [
            'order' => $this->seedOrder($tenantId, $orderNumber, $supplier, $warehouse, $createdByUserId),
            'supplier' => $supplier,
            'warehouse' => $warehouse,
            'item' => $item,
        ];
    }
}

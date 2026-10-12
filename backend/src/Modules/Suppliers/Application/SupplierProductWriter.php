<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Application;

use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`CreateSupplierProduct` and `UpdateSupplierProduct`** (suppliers slice 1 C3; `docs/session13/suppliers-s1-design.md`
 * FINAL F-4 and §5; owner ruling Q-S4 (a)+(ii), Q-S5, `suppliers-s1-ruling.md`; final RED M-B). `CustomerAddressWriter`'s
 * shape for its reasons. Takes wire-named scalars, returns a {@see SupplierProductView}; never an `ApiResource` class (A-7)
 * and never a `Doctrine\DBAL\Connection` (A-10).
 *
 * The two writes are raw SQL naming exactly the columns `erp_app`'s column grants cover (`SchemaManifest['supplier_product']`,
 * `Version20261012000500`): the INSERT names `id, tenant_id, supplier_id, inventory_item_id, supplier_sku, unit_cost,
 * lead_time_days, currency_code` and `min_order_quantity` only when it was sent (omitted, the column default 1 applies, never
 * a PHP copy); the UPDATE sets only submitted columns plus `updated_at`. **`currency_code` is INSERT-only**: it is copied
 * from the supplier when the entry is added (owner Q-S4 (a)) and never rewritten — the supplier's currency cannot change
 * while the supplier has entries (Q-S4 (ii), {@see SupplierWriter}). `supplier_id` and `inventory_item_id` are INSERT-only
 * too: a different item is a remove and an add.
 *
 * **Create, in order:**
 *  1. shape, before any transaction (`inventory_item_id` a uuid; `supplier_sku` non-blank, stored as sent; `unit_cost` a
 *     non-negative decimal string within `numeric(19,4)`; `lead_time_days` a whole number ≥ 0 within the `int` column;
 *     `minimum_order_quantity`, optional, a POSITIVE decimal string within `numeric(19,6)` — an MOQ of zero means nothing;
 *     the writer is the only guard on cost and MOQ, no CHECK exists on either);
 *  2. **the parent, read `FOR SHARE`** ({@see self::PARENT_LOCK}): absent or another workspace's → 404; archived → 409
 *     `supplier.is_archived` (ST-9: an archived row's children are read-only). The lock is final RED M-B's: it conflicts
 *     with `UpdateSupplier`'s `FOR NO KEY UPDATE`, so a currency change and this create serialise — whichever commits first,
 *     the other sees it (the change waits and is refused because an entry now exists; this create waits and copies the new
 *     currency). The FK's own `KEY SHARE` would not conflict, which is why the read carries the lock rather than relying on
 *     the INSERT. Legal for `erp_app` because it holds a column UPDATE on `supplier` (C2, A4.3 constraint 1);
 *  3. the item: a live item of this workspace, else 422 `unknown_reference` on `/inventory_item_id` (an archived item is
 *     refused for a new entry; an entry made earlier keeps naming it);
 *  4. the INSERT, `currency_code` copied from the locked parent row. **`supplier_product_unique` (supplier, item) is the
 *     §4.2 `Idem` mechanism: refused, never replayed** — 409 `supplier_product.item_is_listed_once_per_supplier`, translated
 *     by constraint name **outside** `TenantContext::run()` (the failed statement aborted the transaction). The
 *     `Idempotency-Key` header is demanded (API-14) and unused, `CreateSupplier`'s precedent. Any other 23505 is rethrown.
 *
 * **Update:** the parent (404) → the pair `(supplier, product)` (404 for another supplier's entry, ST-5) → archived 409 →
 * shape (`null` on any field refused, all four are NOT NULL) → `UPDATE … SET <submitted>, updated_at WHERE id = ? AND
 * supplier_id = ?`. An empty PATCH issues no statement.
 *
 * **No statement touches `purchase_order*`:** a placed order line holds a snapshot of the lead time and its own cost
 * (`PurchaseOrderLine`), so an edit here moves no order; only a line added later reads the new lead time.
 *
 * **No audit row** (owner Q-S5, 2026-10-12): who changed a catalogue cost is a recorded gap, `RESIDUAL_GAPS.md` G-41, keyed
 * by `OwedAmendmentTripwireTest` on the first operation that copies a catalogue cost into a suggestion or document
 * (`ListReorderSuggestions`). Copy for the three new refusals is a **placeholder** (`UX` owes it).
 */
final readonly class SupplierProductWriter
{
    /**
     * The parent read every create takes — public so the two-connection race test holds the lock with THIS statement, never
     * a transcription of it (final RED M-B; mutation: drop `FOR SHARE` → `SupplierProductWriteTest`'s race tests red).
     */
    public const PARENT_LOCK = 'SELECT deleted_at IS NOT NULL AS archived, currency_code FROM supplier WHERE id = ?::uuid FOR SHARE';

    public const ARCHIVED_ON_CREATE = 'This supplier is archived, so nothing can be added to its catalogue.';

    public const ARCHIVED_ON_UPDATE = 'This supplier is archived, so its catalogue can\'t be changed.';

    public const ITEM_LISTED = 'This supplier already lists this item. Change its terms instead.';

    public const ITEM_UNKNOWN = 'No live inventory item in this workspace has this id.';

    public const ITEM_SHAPE = 'This field must be a UUID naming an item in this workspace.';

    public const UNIT_COST_SHAPE = 'This field must be a non-negative decimal string with at most 15 integer digits and 4 fractional digits.';

    public const MOQ_SHAPE = 'This field must be a positive decimal string with at most 13 integer digits and 6 fractional digits.';

    public const LEAD_TIME_RANGE = 'Lead time must be a whole number of days, 0 or more.';

    private const UNIQUE = 'supplier_product_unique';

    /** `numeric(19,4)`, non-negative: `PurchaseOrderWriter::MONEY`'s rule, restated here because Suppliers may not import it. */
    private const MONEY = '/^\d{1,15}(\.\d{1,4})?$/';

    /** `numeric(19,6)`, non-negative: `PurchaseOrderWriter::QUANTITY`'s rule; zero is refused separately. */
    private const QUANTITY = '/^\d{1,13}(\.\d{1,6})?$/';

    /** The `int` column's ceiling — the column's width, not a business rule (the `lead_time_sane` CHECK is `>= 0`). */
    private const LEAD_TIME_MAX = 2147483647;

    /** The five create fields, in §4.2's order, as wire names → the column each is stored in. */
    private const COLUMNS = [
        'inventory_item_id' => 'inventory_item_id',
        'supplier_sku' => 'supplier_sku',
        'unit_cost' => 'unit_cost',
        'lead_time_days' => 'lead_time_days',
        'minimum_order_quantity' => 'min_order_quantity',
    ];

    /** `minimum_order_quantity` is optional on create (the column default 1 applies). */
    private const REQUIRED_ON_CREATE = ['inventory_item_id', 'supplier_sku', 'unit_cost', 'lead_time_days'];

    /** The four fields a PATCH may carry (§4.2): never the item. */
    private const UPDATABLE = ['supplier_sku', 'unit_cost', 'lead_time_days', 'minimum_order_quantity'];

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
    ) {
    }

    /** @param array<string, mixed> $values wire name => value, for the fields the body carried */
    public function create(string $supplierId, array $values): SupplierProductView
    {
        self::refuseMalformed($values, self::REQUIRED_ON_CREATE);

        if (!Uuid::isValid($supplierId)) {
            throw ApiProblem::notFound();
        }
        $supplierId = Uuid::fromString($supplierId)->toRfc4122();
        $tenantId = $this->tenant->tenantId();

        try {
            return $this->tenants->run($tenantId, static function (EntityManagerInterface $em) use ($supplierId, $values, $tenantId): SupplierProductView {
                $connection = $em->getConnection();

                $parent = $connection->fetchAssociative(self::PARENT_LOCK, [$supplierId]);
                if (false === $parent) {
                    throw ApiProblem::notFound();
                }
                // ST-9. Inlined, never a `refuseUnless…` method (`EditWindowGuardTest` clause (b) would read one as an edit
                // window).
                if (true === $parent['archived']) {
                    throw ApiProblem::businessRuleViolated('supplier.is_archived', self::ARCHIVED_ON_CREATE);
                }

                $itemId = Uuid::fromString((string) $values['inventory_item_id'])->toRfc4122();
                if (false === $connection->fetchOne('SELECT 1 FROM inventory_item WHERE id = ?::uuid AND deleted_at IS NULL', [$itemId])) {
                    throw ApiProblem::validationFailed([self::error('inventory_item_id', 'unknown_reference', self::ITEM_UNKNOWN)]);
                }

                $id = Uuid::v4()->toRfc4122();
                $columns = ['id', 'tenant_id', 'supplier_id', 'inventory_item_id', 'supplier_sku', 'unit_cost', 'lead_time_days', 'currency_code'];
                $parameters = [$id, $tenantId, $supplierId, $itemId, $values['supplier_sku'], $values['unit_cost'], $values['lead_time_days'], $parent['currency_code']];
                if (\array_key_exists('minimum_order_quantity', $values)) {
                    $columns[] = 'min_order_quantity';
                    $parameters[] = $values['minimum_order_quantity'];
                }

                $connection->executeStatement(
                    \sprintf('INSERT INTO supplier_product (%s) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid%s)', implode(', ', $columns), str_repeat(', ?', \count($columns) - 4)),
                    $parameters,
                );

                return SupplierProductReader::readInside($em, $supplierId, $id)
                    ?? throw new \LogicException('The catalogue entry just inserted is not readable.');
            });
        } catch (UniqueConstraintViolationException $e) {
            // Outside `run()`: the failed INSERT aborted the transaction. The id is minted here, so the only unique a create
            // can hit is (supplier, item) — refused, never replayed. Matched quoted, as `SupplierWriter::translated()`.
            if (str_contains($e->getMessage(), '"'.self::UNIQUE.'"')) {
                throw ApiProblem::businessRuleViolated('supplier_product.item_is_listed_once_per_supplier', self::ITEM_LISTED);
            }

            throw $e;
        }
    }

    /** @param array<string, mixed> $submitted wire name => value, exactly the fields the body carried */
    public function update(string $supplierId, string $productId, array $submitted): SupplierProductView
    {
        if (!Uuid::isValid($supplierId) || !Uuid::isValid($productId)) {
            throw ApiProblem::notFound();
        }
        $supplierId = Uuid::fromString($supplierId)->toRfc4122();
        $productId = Uuid::fromString($productId)->toRfc4122();

        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em) use ($supplierId, $productId, $submitted): SupplierProductView {
            $connection = $em->getConnection();

            // `fetchAssociative`, not `fetchOne`: a live supplier's `false` would be indistinguishable from "no row".
            $parent = $connection->fetchAssociative('SELECT deleted_at IS NOT NULL AS archived FROM supplier WHERE id = ?::uuid', [$supplierId]);
            if (false === $parent) {
                throw ApiProblem::notFound();
            }
            // The pair: another supplier's entry under this path is the same 404 as a random id (ST-5).
            if (null === SupplierProductReader::readInside($em, $supplierId, $productId)) {
                throw ApiProblem::notFound();
            }
            if (true === $parent['archived']) {
                throw ApiProblem::businessRuleViolated('supplier.is_archived', self::ARCHIVED_ON_UPDATE);
            }

            self::refuseMalformed($submitted, []);

            $set = [];
            $parameters = [];
            foreach (self::UPDATABLE as $field) {
                if (\array_key_exists($field, $submitted)) {
                    $set[] = self::COLUMNS[$field].' = ?';
                    $parameters[] = $submitted[$field];
                }
            }
            if ([] !== $set) {
                $set[] = 'updated_at = now()';
                $parameters[] = $productId;
                $parameters[] = $supplierId;
                $connection->executeStatement(
                    'UPDATE supplier_product SET '.implode(', ', $set).' WHERE id = ?::uuid AND supplier_id = ?::uuid',
                    $parameters,
                );
            }

            return SupplierProductReader::readInside($em, $supplierId, $productId)
                ?? throw new \LogicException('The catalogue entry just updated is not readable.');
        });
    }

    /**
     * Shape, and only shape: decidable without the database.
     *
     * @param array<string, mixed> $values
     * @param list<string>         $required
     */
    private static function refuseMalformed(array $values, array $required): void
    {
        $errors = [];

        foreach (array_keys(self::COLUMNS) as $field) {
            $present = \array_key_exists($field, $values);
            $value = $values[$field] ?? null;

            if (null === $value) {
                if (\in_array($field, $required, true)) {
                    $errors[] = self::error($field, 'required', 'This field is required.');
                } elseif ($present) {
                    $errors[] = self::error($field, 'not_nullable', 'This field may not be cleared.');
                }
                continue;
            }

            switch ($field) {
                case 'inventory_item_id':
                    if (!\is_string($value) || !Uuid::isValid($value)) {
                        $errors[] = self::error($field, 'not_a_uuid', self::ITEM_SHAPE);
                    }
                    break;
                case 'supplier_sku':
                    if (!\is_string($value)) {
                        $errors[] = self::error($field, 'not_a_string', 'This field must be a string.');
                    } elseif ('' === trim($value)) {
                        $errors[] = self::error($field, 'blank', 'This field may not be blank.');
                    }
                    break;
                case 'unit_cost':
                    if (!\is_string($value) || 1 !== preg_match(self::MONEY, $value)) {
                        $errors[] = self::error($field, 'not_a_decimal', self::UNIT_COST_SHAPE);
                    }
                    break;
                case 'lead_time_days':
                    if (!\is_int($value) || $value < 0 || $value > self::LEAD_TIME_MAX) {
                        $errors[] = self::error($field, 'out_of_range', self::LEAD_TIME_RANGE);
                    }
                    break;
                case 'minimum_order_quantity':
                    if (!\is_string($value) || 1 !== preg_match(self::QUANTITY, $value) || 0 === bccomp($value, '0', 6)) {
                        $errors[] = self::error($field, 'not_a_positive_decimal', self::MOQ_SHAPE);
                    }
                    break;
            }
        }

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }
    }

    /** @return array{pointer: string, code: string, message: string} */
    private static function error(string $field, string $code, string $message): array
    {
        return ['pointer' => '/'.$field, 'code' => $code, 'message' => $message];
    }
}

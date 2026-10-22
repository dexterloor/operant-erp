<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Application;

use App\Modules\AuditLog\Infrastructure\SupplierAuditWriter;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`CreateSupplierProduct` and `UpdateSupplierProduct`** (suppliers slice 1 C3; and **`DeleteSupplierProduct`**, C4 — see
 * {@see self::delete()}; `docs/session13/suppliers-s1-design.md`
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
 * **Update:** the parent, read {@see self::PARENT_KEY_SHARE} (C5 C2b; 404) → the pair `(supplier, product)`, read
 * {@see self::ENTRY_LOCK} (G3-C0; 404 for another supplier's entry, ST-5) → archived 409 → shape (`null` on any field
 * refused, all four are NOT NULL) → the diff against the locked row → `UPDATE … SET <changed>, updated_at WHERE id = ? AND
 * supplier_id = ?` → `supplier_product.updated`. **A PATCH that changes nothing issues no UPDATE and writes no row**, and
 * `updated_at` does not move (G3-C0, owner notice N-1 — until then every submitted column was set and `updated_at` moved
 * on a no-op PATCH); an empty PATCH is the same. Lock order supplier → entry, as create (`FOR SHARE`, then the INSERT) and
 * delete (`FOR KEY SHARE`, then the DELETE).
 *
 * **No statement touches `purchase_order*`:** a placed order line holds a snapshot of the lead time and its own cost
 * (`PurchaseOrderLine`), so an edit here moves no order; only a line added later reads the new lead time.
 *
 * **The trail** (G3-C0, 2026-10-22; `RESIDUAL_GAPS.md` G-41 CLOSED): each write records its row through
 * {@see SupplierAuditWriter} in its own transaction — `supplier_product.created` after the INSERT, `.updated` after an UPDATE
 * that changed something (field names, never values: `unit_cost` is money, PC-12), `.deleted` after a one-row DELETE —
 * subject the entry, never the supplier (design D-C0-1). A refusal rolls the transaction back, so it writes none. *(Until
 * G3-C0 this paragraph read "No audit row (owner Q-S5, 2026-10-12): who changed a catalogue cost is a recorded gap,
 * G-41".)* Copy for the three archived refusals is `UX`'s final (`owed-ux-c5.md` FINAL (a2), "until it's restored").
 */
final readonly class SupplierProductWriter
{
    /**
     * The parent read every create takes — public so the two-connection race test holds the lock with THIS statement, never
     * a transcription of it (final RED M-B; mutation: drop `FOR SHARE` → `SupplierProductWriteTest`'s race tests red).
     */
    public const PARENT_LOCK = 'SELECT deleted_at IS NOT NULL AS archived, currency_code FROM supplier WHERE id = ?::uuid FOR SHARE';

    /**
     * The parent read `UpdateSupplierProduct` and `DeleteSupplierProduct` take (C5 C2b; `docs/session13/c5-design.md` §4.1,
     * closing suppliers final RED L-9). `FOR KEY SHARE` conflicts with `DeleteSupplier`'s `FOR UPDATE`
     * ({@see SupplierWriter::DELETE_LOCK}), so a catalogue change cannot land on a supplier that is being archived: it waits,
     * re-reads the committed row and is refused `supplier.is_archived`. It does NOT conflict with `UpdateSupplier`'s
     * `FOR NO KEY UPDATE`, so C4's reasoning for not taking {@see self::PARENT_LOCK} on these two survives unchanged.
     * Public so the race tests name this statement (mutation M15: drop the lock → `SupplierDeleteTest`'s catalogue races red).
     */
    public const PARENT_KEY_SHARE = 'SELECT deleted_at IS NOT NULL AS archived FROM supplier WHERE id = ?::uuid FOR KEY SHARE';

    /**
     * The entry read `UpdateSupplierProduct` takes AFTER {@see self::PARENT_KEY_SHARE} (G3-C0): it is the pair check (no row
     * for another supplier's entry → 404) AND the row lock the diff is taken under, so `supplier_product.updated` names exactly
     * what this request changed — a concurrent PATCH waits here and diffs against the committed row (mutation M-C0-6: drop
     * `FOR NO KEY UPDATE` → `SupplierProductWriteTest::aConcurrentTermsEditNamesOnlyItsOwnChange` red). `FOR NO KEY UPDATE`,
     * the lock the UPDATE would take anyway; legal for `erp_app`, which holds column UPDATE on the entry (A4.3 constraint 1).
     */
    public const ENTRY_LOCK = 'SELECT supplier_sku, unit_cost, lead_time_days, min_order_quantity FROM supplier_product WHERE id = ?::uuid AND supplier_id = ?::uuid FOR NO KEY UPDATE';

    public const ARCHIVED_ON_CREATE = 'This supplier is archived, so nothing can be added to its catalogue until it\'s restored.';

    public const ARCHIVED_ON_UPDATE = 'This supplier is archived, so its catalogue can\'t be changed until it\'s restored.';

    public const ARCHIVED_ON_DELETE = 'This supplier is archived, so nothing can be removed from its catalogue until it\'s restored.';

    /** The one statement `DeleteSupplierProduct` issues; the pair predicate is what keeps another supplier's entry out. */
    public const DELETE = 'DELETE FROM supplier_product WHERE id = ?::uuid AND supplier_id = ?::uuid';

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
        private RequestIdentity $identities,
        private SupplierAuditWriter $audit,
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
        $identity = $this->identities->authenticated();
        $audit = $this->audit;

        try {
            return $this->tenants->run($tenantId, static function (EntityManagerInterface $em) use ($supplierId, $values, $tenantId, $identity, $audit): SupplierProductView {
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
                // G-59 closed (G5-C2, 2026-10-22): `FOR KEY SHARE` waits on an archiving `DeleteItem`/bulk member's `FOR UPDATE`, and
                // the re-checked `deleted_at IS NULL` then refuses the just-archived item (before, the row was read unlocked and the line landed).
                if (false === $connection->fetchOne('SELECT 1 FROM inventory_item WHERE id = ?::uuid AND deleted_at IS NULL FOR KEY SHARE', [$itemId])) {
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

                // After the INSERT, in its transaction: a 23505 above leaves no row.
                $audit->recordCatalogueCreated(
                    tenantId: $identity->tenantId,
                    actorUserId: $identity->userId,
                    actorLabel: $identity->actorLabel,
                    supplierProductId: $id,
                    supplierId: $supplierId,
                    inventoryItemId: $itemId,
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
        $identity = $this->identities->authenticated();
        $audit = $this->audit;

        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em) use ($supplierId, $productId, $submitted, $identity, $audit): SupplierProductView {
            $connection = $em->getConnection();

            // `fetchAssociative`, not `fetchOne`: a live supplier's `false` would be indistinguishable from "no row". Locked
            // `FOR KEY SHARE` against `DeleteSupplier` (C5 C2b, {@see self::PARENT_KEY_SHARE}).
            $parent = $connection->fetchAssociative(self::PARENT_KEY_SHARE, [$supplierId]);
            if (false === $parent) {
                throw ApiProblem::notFound();
            }
            // The pair: another supplier's entry under this path is the same 404 as a random id (ST-5). The same read is the
            // entry's row lock (G3-C0): the diff below is taken against a row no concurrent update can move until this
            // transaction ends.
            $stored = $connection->fetchAssociative(self::ENTRY_LOCK, [$productId, $supplierId]);
            if (false === $stored) {
                throw ApiProblem::notFound();
            }
            if (true === $parent['archived']) {
                throw ApiProblem::businessRuleViolated('supplier.is_archived', self::ARCHIVED_ON_UPDATE);
            }

            self::refuseMalformed($submitted, []);

            $changed = self::changedFields($stored, $submitted);
            if ([] !== $changed) {
                $set = [];
                $parameters = [];
                foreach ($changed as $field) {
                    $set[] = self::COLUMNS[$field].' = ?';
                    $parameters[] = $submitted[$field];
                }
                $set[] = 'updated_at = now()';
                $parameters[] = $productId;
                $parameters[] = $supplierId;
                $connection->executeStatement(
                    'UPDATE supplier_product SET '.implode(', ', $set).' WHERE id = ?::uuid AND supplier_id = ?::uuid',
                    $parameters,
                );

                // After the UPDATE, in its transaction.
                $audit->recordCatalogueUpdated(
                    tenantId: $identity->tenantId,
                    actorUserId: $identity->userId,
                    actorLabel: $identity->actorLabel,
                    supplierProductId: $productId,
                    fields: $changed,
                );
            }

            return SupplierProductReader::readInside($em, $supplierId, $productId)
                ?? throw new \LogicException('The catalogue entry just updated is not readable.');
        });
    }

    /**
     * **`DeleteSupplierProduct`** (suppliers slice 1 C4; design §6, FINAL F-5). `supplier_product` is class `hard`, so this is
     * a real `DELETE` — the third table `erp_app` may delete from (`Version20261012000600`, after `user_role` and
     * `role_permission`). In one tenant transaction: the parent (absent or another workspace's → 404) → the pair
     * `(supplier, product)` (another supplier's entry under this path is the same 404, ST-5) → archived parent 409
     * `supplier.is_archived` (ST-9: an archived supplier's catalogue is read-only) → `DELETE … WHERE id = ? AND
     * supplier_id = ?` ({@see self::DELETE}). A delete that removes nothing (a concurrent removal won) is the same 404.
     *
     * **The parent read is NOT {@see self::PARENT_LOCK}.** That lock exists so an ADD and a currency change serialise
     * (final RED M-B). A removal can only shrink the count `UpdateSupplier` takes under its own row lock, so a currency
     * change racing a removal is at worst refused on a count that was true when it was taken — never a price left in the
     * wrong currency. It is {@see self::PARENT_KEY_SHARE} since C5 C2b (FINAL F-6, final RED L-9 decided with
     * `DeleteSupplier`): a removal cannot land on a supplier being archived.
     *
     * **Consequences, stated (design §6):** a later purchase-order line for the item is refused
     * `purchase_order_line.supplier_lists_the_item` (existing behaviour, now reachable by API); placed lines keep their
     * snapshots; `supplier_product_unique` is total but the row is gone, so re-adding the item is a fresh create — there is
     * no key to replay. Once a supplier has no entries its currency may change again (Q-S4 (ii)). **Race with
     * `CreatePurchaseOrderLine`, recorded, not guarded:** its lead-time read is unlocked, so a delete committing between that
     * read and the line's INSERT yields a line snapshotting terms that no longer exist — harmless, snapshot semantics.
     * **`supplier_product.deleted`** (G3-C0) after a DELETE that removed one row, `{supplier_id, inventory_item_id}`: the row
     * is gone, so these ids keep its chain findable. *(Until G3-C0: "No audit row (G-41 covers removal as well as change)".)*
     */
    public function delete(string $supplierId, string $productId): void
    {
        if (!Uuid::isValid($supplierId) || !Uuid::isValid($productId)) {
            throw ApiProblem::notFound();
        }
        $supplierId = Uuid::fromString($supplierId)->toRfc4122();
        $productId = Uuid::fromString($productId)->toRfc4122();
        $identity = $this->identities->authenticated();
        $audit = $this->audit;

        $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em) use ($supplierId, $productId, $identity, $audit): void {
            $connection = $em->getConnection();

            // Locked `FOR KEY SHARE` against `DeleteSupplier` (C5 C2b, {@see self::PARENT_KEY_SHARE}).
            $parent = $connection->fetchAssociative(self::PARENT_KEY_SHARE, [$supplierId]);
            if (false === $parent) {
                throw ApiProblem::notFound();
            }
            $itemId = $connection->fetchOne('SELECT inventory_item_id FROM supplier_product WHERE id = ?::uuid AND supplier_id = ?::uuid', [$productId, $supplierId]);
            if (false === $itemId) {
                throw ApiProblem::notFound();
            }
            if (true === $parent['archived']) {
                throw ApiProblem::businessRuleViolated('supplier.is_archived', self::ARCHIVED_ON_DELETE);
            }

            if (0 === (int) $connection->executeStatement(self::DELETE, [$productId, $supplierId])) {
                throw ApiProblem::notFound();
            }

            // After a DELETE that removed one row, in its transaction.
            $audit->recordCatalogueDeleted(
                tenantId: $identity->tenantId,
                actorUserId: $identity->userId,
                actorLabel: $identity->actorLabel,
                supplierProductId: $productId,
                supplierId: $supplierId,
                inventoryItemId: (string) $itemId,
            );
        });
    }

    /**
     * The wire names whose submitted value differs from the locked entry, sorted ascending — **numerically at column scale**
     * for the two decimals (`bccomp` at scale 4 for `unit_cost`, `numeric(19,4)`; scale 6 for `minimum_order_quantity`,
     * `numeric(19,6)`: `"10.50"` is the stored `10.5000`), as an integer for `lead_time_days`, as an exact string for
     * `supplier_sku` (stored as sent). Runs after {@see self::refuseMalformed()}, so every submitted value is the right shape.
     *
     * @param array<string, mixed> $stored    the {@see self::ENTRY_LOCK} row (column names)
     * @param array<string, mixed> $submitted
     *
     * @return list<string>
     */
    private static function changedFields(array $stored, array $submitted): array
    {
        $changed = [];
        foreach (self::UPDATABLE as $field) {
            if (!\array_key_exists($field, $submitted)) {
                continue;
            }
            $was = $stored[self::COLUMNS[$field]];
            $now = $submitted[$field];
            $differs = match ($field) {
                'unit_cost' => 0 !== bccomp((string) $was, (string) $now, 4),
                'minimum_order_quantity' => 0 !== bccomp((string) $was, (string) $now, 6),
                'lead_time_days' => (int) $was !== (int) $now,
                default => (string) $was !== (string) $now,
            };
            if ($differs) {
                $changed[] = $field;
            }
        }
        sort($changed);

        return $changed;
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

<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Modules\Suppliers\Infrastructure\Entity\Supplier;
use App\Platform\Tenancy\SchemaManifest;
use App\Platform\Tenancy\SoftDeleteFilter;
use App\Platform\Tenancy\TenantFilter;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * D-12 and D-13, exercised rather than inspected.
 *
 * SchemaManifestTest asserts the constraints exist. A constraint that exists
 * and never fires is the failure mode that matters, so everything here works by
 * attempting the thing the constraint is supposed to refuse.
 */
final class DeletionPolicyTest extends TenancyTestCase
{
    /**
     * Fixture writes on `supplier` and `warehouse` run as the owner: no `src/`
     * path writes either table, so `erp_app` holds SELECT on them and nothing else
     * (ADR-002 Amendment 4). The constraints these tests fire -- the UUIDv4 CHECK,
     * the live-row uniques, the composite keys -- bind every role, and locally the
     * owner is bound by FORCE ROW LEVEL SECURITY too.
     */
    private function insertSupplier(string $id, string $tenantId, string $name, string $code): void
    {
        $this->insertAsOwnerForTenant(
            $tenantId,
            "INSERT INTO supplier (id, tenant_id, name, code, country_code, currency_code) VALUES (?::uuid, ?::uuid, ?, ?, ?, 'EUR')",
            [$id, $tenantId, $name, $code, 'GB'],
        );
    }

    private function softDeleteSupplier(string $tenantId, string $id): void
    {
        $this->executeAsOwnerForTenant($tenantId, 'UPDATE supplier SET deleted_at = now() WHERE id = ?::uuid', [$id]);
    }

    private function seedWarehouse(): void
    {
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO warehouse (id, tenant_id, code, name, country_code)
            VALUES (gen_random_uuid(), ?::uuid, 'WH-C', 'Cascade', 'DE')
            SQL, [self::TENANT_A]);
    }

    /**
     * D-12 / A1.8. The whole point of the constraint is that a type check is not
     * enough: this id is a perfectly valid `uuid`, and it is a UUIDv7, so its
     * leading bits are a millisecond timestamp and the row after it is
     * guessable. `PRIMARY KEY (id)` is a bare unique index whose violations
     * bypass row security, so a guessable id is a confirmable one.
     */
    #[Test]
    public function aTimeOrderedUuidVersionSevenIdentifierIsRefused(): void
    {
        // Version nibble 7, RFC 4122 variant. Accepted by `uuid`, refused here.
        $v7 = '0192f3a1-2b3c-7def-8abc-0123456789ab';
        self::assertTrue(Uuid::isValid($v7), 'The fixture is not a well-formed UUID, so the test would prove nothing.');

        $thrown = null;
        try {
            $this->insertSupplier($v7, self::TENANT_A, 'Time Ordered', 'TO-1');
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown, 'A UUIDv7 primary key was accepted on a tenant-scoped table.');
        self::assertStringContainsString('supplier_id_is_uuid_v4', $thrown->getMessage());
    }

    #[Test]
    public function aRandomUuidVersionFourIdentifierIsAccepted(): void
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertSupplier($id, self::TENANT_A, 'Random', 'R-1');

        self::assertSame(1, $this->countAsOwnerForTenant(
            self::TENANT_A, 'SELECT count(*) FROM supplier WHERE id = ?::uuid', [$id]));
    }

    /**
     * ADR-007 §1's sharp edge: "delete a supplier, re-create it with the same
     * code" is a routine ERP operation, and under a total unique constraint it
     * fails against a row the user cannot see -- with an error message that also
     * happens to bypass row security, because integrity checks always do.
     */
    #[Test]
    public function aSoftDeletedRowReleasesItsBusinessKeyForReuse(): void
    {
        $first = Uuid::v4()->toRfc4122();
        $this->insertSupplier($first, self::TENANT_A, 'Acme', 'ACME');

        $this->softDeleteSupplier(self::TENANT_A, $first);

        $second = Uuid::v4()->toRfc4122();
        $this->insertSupplier($second, self::TENANT_A, 'Acme', 'ACME');

        self::assertSame(2, $this->countAsOwnerForTenant(
            self::TENANT_A, "SELECT count(*) FROM supplier WHERE code = 'ACME'"),
            'Both rows must survive: the deleted one is history, the live one is the current supplier.');
    }

    /** The same key twice while both are live is still refused. */
    #[Test]
    public function twoLiveRowsCannotShareABusinessKey(): void
    {
        $this->insertSupplier(Uuid::v4()->toRfc4122(), self::TENANT_A, 'Acme', 'ACME');

        $thrown = null;
        try {
            $this->insertSupplier(Uuid::v4()->toRfc4122(), self::TENANT_A, 'Acme Duplicate', 'ACME');
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown, 'The live-row unique index did not fire.');
        self::assertStringContainsString('supplier_tenant_code_live', $thrown->getMessage());
    }

    /**
     * Why `UNIQUE (tenant_id, id)` has to stay total, demonstrated rather than
     * argued: a child legitimately references a soft-deleted parent, and the
     * composite foreign key resolves against the total index. Had ADR-007's
     * partial predicate been applied to it, this insert would fail and the
     * entire deletion policy would collapse.
     */
    #[Test]
    public function aChildCanBeCreatedAgainstASoftDeletedParent(): void
    {
        $supplierId = Uuid::v4()->toRfc4122();
        $this->insertSupplier($supplierId, self::TENANT_A, 'Retired', 'RET');

        $this->softDeleteSupplier(self::TENANT_A, $supplierId);

        $itemId = $this->seedItem(self::TENANT_A, 'SKU-ORPHAN', $supplierId);

        self::assertSame(1, $this->countAsOwnerForTenant(
            self::TENANT_A, 'SELECT count(*) FROM inventory_item WHERE id = ?::uuid AND supplier_id = ?::uuid',
            [$itemId, $supplierId]));
    }

    /**
     * `RED`'s MAJOR-2, closed in the database rather than in a convention.
     *
     * Before the cascade trigger, soft-deleting a purchase order left its lines
     * live, and `purchase_order_line_tenant_item_idx` exists precisely to answer
     * "how much of this item is already on order" -- so the obvious query
     * counted supply from a discarded order, suppressed a legitimate reorder,
     * and would have surfaced weeks later as a stockout with no explanation.
     *
     * The reply at the time was "the deletion filter applies at the aggregate
     * root". That is a convention, and ADR-007's own argument is that a control
     * enforced by a convention is a hope. Written here as raw SQL, so the
     * cascade is proved to survive a caller that never touches the ORM.
     */
    #[Test]
    public function softDeletingAPurchaseOrderSoftDeletesItsLinesAndRemovesItFromTheOnOrderQuantity(): void
    {
        $supplierId = Uuid::v4()->toRfc4122();
        $this->insertSupplier($supplierId, self::TENANT_A, 'Cascade', 'CAS');
        $itemId = $this->seedItem(self::TENANT_A, 'SKU-CASCADE', $supplierId);
        $this->seedWarehouse();

        $orderId = Uuid::v4()->toRfc4122();
        $this->tenantContext()->run(self::TENANT_A, function () use ($orderId, $supplierId, $itemId): void {
            $this->app()->executeStatement(<<<'SQL'
                INSERT INTO purchase_order (id, tenant_id, order_number, supplier_id, warehouse_id, currency_code, status, ordered_at, idempotency_key, request_digest)
                VALUES (?::uuid, ?::uuid, 'PO-107000', ?::uuid,
                        (SELECT id FROM warehouse WHERE code = 'WH-C'), 'EUR', 'ordered', now(),
                        encode(sha256(gen_random_uuid()::text::bytea), 'hex'), encode(sha256(gen_random_uuid()::text::bytea), 'hex'))
                SQL, [$orderId, self::TENANT_A, $supplierId]);
            $this->app()->executeStatement(<<<'SQL'
                INSERT INTO purchase_order_line
                    (id, tenant_id, purchase_order_id, inventory_item_id, line_number, quantity_ordered, unit_cost, lead_time_days)
                VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?::uuid, 1, 100, 5.00, 7)
                SQL, [self::TENANT_A, $orderId, $itemId]);
        });

        $onOrder = fn (): string => $this->tenantContext()->run(self::TENANT_A, fn (): string =>
            (string) $this->app()->fetchOne(
                'SELECT coalesce(sum(quantity_ordered), 0) FROM purchase_order_line
                 WHERE inventory_item_id = ?::uuid AND deleted_at IS NULL', [$itemId]));

        self::assertSame('100.000000', $onOrder());

        $this->tenantContext()->run(self::TENANT_A, function () use ($orderId): void {
            $this->app()->executeStatement('UPDATE purchase_order SET deleted_at = now() WHERE id = ?::uuid', [$orderId]);
        });

        self::assertSame('0', $onOrder(),
            'A discarded purchase order still contributes to on-order quantity. That is supply that will never '
            .'arrive, and it suppresses the reorder that should have been raised.');

        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM purchase_order_line WHERE purchase_order_id = ?::uuid AND deleted_at IS NOT NULL',
            [$orderId]),
            'The line was hidden by a filter rather than actually marked deleted.');

        // Symmetry: ADR-007 §4 leaves whether restore is offered to Output 17,
        // but if it is, the aggregate has to come back whole.
        $this->tenantContext()->run(self::TENANT_A, function () use ($orderId): void {
            $this->app()->executeStatement('UPDATE purchase_order SET deleted_at = NULL WHERE id = ?::uuid', [$orderId]);
        });

        self::assertSame('100.000000', $onOrder(), 'Restoring the order did not restore its lines.');
    }

    /**
     * The mirror of the test above, and the reason it exists separately.
     *
     * The `RED` gate dropped each trigger in turn and found that
     * `sales_order_cascade_deletion` could be removed with the **whole suite
     * still green**. The fix had gone to both tables; the assertion had gone to
     * one. That is the same defect as the one the purchasing test was written to
     * catch, wearing the other module's name — and it is a reminder that "I
     * fixed both sides" and "I proved both sides" are different claims.
     *
     * Sales is not a copy of purchasing here: the query is ADR-004's *demand*
     * history rather than its supply, served by
     * `sales_order_line_tenant_item_idx`. Demand from a discarded order is not
     * demand, and a forecast that counts it over-orders instead of
     * under-ordering — the opposite failure, from the same cause.
     *
     * **The order is a DRAFT, and until 2026-09-21 it was `confirmed`.** The
     * owner's CD-4 ruling makes an archived sales order always a draft, and
     * `sales_order_archived_only_while_draft` refuses the confirmed fixture this
     * test used to archive. The demand query carries no status predicate, so a
     * draft's lines count as demand while it is live, and the property is
     * unchanged: a draft somebody discards was never going to be shipped, and
     * its lines must stop counting the moment it is.
     *
     * **Writes as the owner since V4 batch B7 (2026-09-16).** No `src/` path writes
     * `customer`, `sales_order` or `sales_order_line`, so under ADR-002 Amendment 4
     * `erp_app` holds SELECT on them and nothing else. This test therefore proves
     * the cascade function's body and the trigger's wiring, not that it runs as the
     * invoker `erp_app`. That invoker property -- a role with UPDATE on a cascade
     * parent must also hold UPDATE and SELECT on the child -- is held for every
     * application role by the catalogue check over `pg_trigger`,
     * `GrantFootprintTest::aRoleThatCanUpdateACascadeParentCanUpdateAndReadItsChild`
     * (ADR-002 A4.3 constraint 2). The
     * demand reads still run as `erp_app`. The purchasing twin above keeps
     * `erp_app` for every `purchase_order*` statement, so the invoker path is still
     * exercised end to end there.
     */
    #[Test]
    public function softDeletingASalesOrderSoftDeletesItsLinesAndRemovesItFromTheDemandHistory(): void
    {
        $itemId = $this->seedItem(self::TENANT_A, 'SKU-DEMAND');
        $this->seedWarehouse();

        $orderId = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO customer (id, tenant_id, code, name, country_code, currency_code)
            VALUES (gen_random_uuid(), ?::uuid, 'CUS-C', 'Cascade Customer', 'GB', 'EUR')
            SQL, [self::TENANT_A]);
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO sales_order
                (id, tenant_id, order_number, customer_id, warehouse_id, currency_code, status, ordered_at, ship_to_country_code,
                 idempotency_key, request_digest)
            VALUES (?::uuid, ?::uuid, 'SO-140000',
                    (SELECT id FROM customer  WHERE code = 'CUS-C'),
                    (SELECT id FROM warehouse WHERE code = 'WH-C'),
                    'EUR', 'draft', NULL, 'DE',
                    -- NOT NULL since Version20260919000100, and 64 lower-case hex by CHECK.
                    -- Generated rather than literal: the live-row unique is per tenant, so a
                    -- literal here would collide with any second order this fixture ever grows.
                    encode(sha256(gen_random_uuid()::text::bytea), 'hex'),
                    encode(sha256(gen_random_uuid()::text::bytea), 'hex'))
            SQL, [$orderId, self::TENANT_A]);
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO sales_order_line
                (id, tenant_id, sales_order_id, inventory_item_id, line_number, quantity, unit_price)
            VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?::uuid, 1, 40, 12.50)
            SQL, [self::TENANT_A, $orderId, $itemId]);

        $demand = fn (): string => $this->tenantContext()->run(self::TENANT_A, fn (): string =>
            (string) $this->app()->fetchOne(
                'SELECT coalesce(sum(quantity), 0) FROM sales_order_line
                 WHERE inventory_item_id = ?::uuid AND deleted_at IS NULL', [$itemId]));

        self::assertSame('40.000000', $demand());

        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE sales_order SET deleted_at = now() WHERE id = ?::uuid', [$orderId]);

        self::assertSame('0', $demand(),
            'A discarded draft sales order still contributes to demand history. ADR-004 forecasts from this figure, '
            .'so a draft somebody threw away becomes stock that was never really needed.');

        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM sales_order_line WHERE sales_order_id = ?::uuid AND deleted_at IS NOT NULL',
            [$orderId]),
            'The line was hidden by a filter rather than actually marked deleted.');

        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE sales_order SET deleted_at = NULL WHERE id = ?::uuid', [$orderId]);

        self::assertSame('40.000000', $demand(), 'Restoring the order did not restore its lines.');
    }

    /**
     * **CD-4, ruled by the owner 2026-09-21 as option (i): an archived sales order is always a draft** — made a
     * mechanism at the database, not left to the edit window alone.
     *
     * CD-4 asked what `quantity_outstanding` means on an order archived while `confirmed`. Under L-8 (*"a document is
     * deletable only while `status = 'draft'`"*) no shipped path produces that state, and
     * `sales_order_archived_only_while_draft` makes sure no path ever can, raw SQL included. It also FREEZES an
     * archived order's status: a transition cannot run underneath the API on a discarded order either.
     *
     * Raw SQL as the owner on purpose — the subject is the constraint, which binds every role, and the point is that it
     * holds for a writer that never touches the ORM or the edit window.
     */
    #[Test]
    public function aSalesOrderCanBeArchivedOnlyWhileItIsADraft(): void
    {
        $this->seedWarehouse();
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO customer (id, tenant_id, code, name, country_code, currency_code)
            VALUES (gen_random_uuid(), ?::uuid, 'CUS-D', 'Draft Customer', 'GB', 'EUR')
            SQL, [self::TENANT_A]);
        $order = function (string $number, string $status): string {
            $id = Uuid::v4()->toRfc4122();
            $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                INSERT INTO sales_order
                    (id, tenant_id, order_number, customer_id, warehouse_id, currency_code, status, ordered_at, ship_to_country_code,
                     idempotency_key, request_digest)
                VALUES (?::uuid, ?::uuid, ?, (SELECT id FROM customer WHERE code = 'CUS-D'),
                        (SELECT id FROM warehouse WHERE code = 'WH-C'), 'EUR', ?,
                        CASE WHEN ? = 'draft' THEN NULL ELSE now() END, 'DE',
                        encode(sha256(gen_random_uuid()::text::bytea), 'hex'), encode(sha256(gen_random_uuid()::text::bytea), 'hex'))
                SQL, [$id, self::TENANT_A, $number, $status, $status]);

            return $id;
        };
        $refusal = function (string $sql, string $id): ?string {
            try {
                $this->executeAsOwnerForTenant(self::TENANT_A, $sql, [$id]);
            } catch (\Doctrine\DBAL\Exception\DriverException $e) {
                return $e->getSQLState().' '.$e->getMessage();
            }

            return null;
        };

        $draft = $order('SO-148000', 'draft');
        self::assertNull($refusal('UPDATE sales_order SET deleted_at = now() WHERE id = ?::uuid', $draft),
            'Control: archiving a DRAFT sales order was refused, so the refusals below could be refusing everything.');

        // ⚠ `partially_fulfilled` and `fulfilled` are SEEDED with nothing shipped -- states the API cannot produce as
        // seeded (a fulfilled order it produces has shipped every line; RED P9, 2026-09-22). The CHECK under test
        // reads only `status`, so the seed is sufficient for it.
        foreach (['confirmed', 'partially_fulfilled', 'fulfilled', 'cancelled'] as $n => $status) {
            $id = $order(\sprintf('SO-2001%02d', $n), $status);
            $refused = $refusal('UPDATE sales_order SET deleted_at = now() WHERE id = ?::uuid', $id);
            self::assertNotNull($refused,
                "A $status sales order was archived underneath the API. CD-4 (ruled 2026-09-21, option (i)): an archived "
                .'sales order is always a draft, so SalesOrderLineRow never has to decide what a deleted COMMITMENT owes. '
                .'If this is being widened on purpose, CD-4 is open again and is the owner\'s to rule.');
            self::assertStringStartsWith('23514', $refused);
            self::assertStringContainsString('sales_order_archived_only_while_draft', $refused);
        }

        $refused = $refusal("UPDATE sales_order SET status = 'confirmed', ordered_at = now() WHERE id = ?::uuid", $draft);
        self::assertNotNull($refused, 'An ARCHIVED draft was confirmed underneath the API — the discarded order became a commitment.');
        self::assertStringContainsString('sales_order_archived_only_while_draft', $refused);
    }

    /**
     * The two parents `cascade_deletion_to_lines` serves. **One function, two triggers** — so every property below is
     * proved on BOTH, never on one. The two tests above record why: the cascade was once fixed on both tables and
     * proved on one, and `RED` removed the unproved trigger with the whole suite still green.
     *
     * @return iterable<string, array{string}>
     */
    public static function cascadeParents(): iterable
    {
        yield 'purchase_order' => ['purchase_order'];
        yield 'sales_order' => ['sales_order'];
    }

    /**
     * An individually discarded line keeps ITS OWN stamp when its order is discarded after it.
     *
     * Before `Version20260921000200` the archive branch matched `deleted_at IS DISTINCT FROM $1`, which is every line
     * not already carrying the order's stamp — **including a line the user had already discarded on purpose**. It was
     * re-stamped with the order's instant, and from that moment nothing (timestamp, query or human) could tell it
     * from the lines the order took with it. On purchasing that was visible on the wire:
     * `GET …/purchase-orders/{id}/lines?archived=true` reported the order's `archived_at`, not the one the user caused.
     *
     * **Delete direction ONLY, asserted before any restore** — so a mutation of the archive branch alone reddens this
     * test and nothing is borrowed from the restore branch (`RED` §3: the other two tests redden under that mutation
     * too, because a re-stamped line is then also matched by the restore).
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('cascadeParents')]
    public function anIndividuallyArchivedLineKeepsItsOwnStampWhenItsOrderIsArchived(string $parent): void
    {
        $o = $this->seedDraftOrderWithTwoLines($parent);

        $this->writeAsApp("UPDATE {$o['child']} SET deleted_at = '2026-01-01T00:00:00+00' WHERE id = ?::uuid", [$o['line2']]);
        $this->writeAsApp("UPDATE $parent SET deleted_at = '2026-02-01T00:00:00+00' WHERE id = ?::uuid", [$o['order']]);

        $stamps = $this->lineStamps($o);
        self::assertSame('2026-02-01 00:00:00', $stamps[$o['line1']],
            "Control: archiving the $parent did not archive its live line, so the cascade never ran and the next "
            .'assertion would prove nothing.');
        self::assertSame('2026-01-01 00:00:00', $stamps[$o['line2']],
            "Archiving the $parent re-stamped a line the user had ALREADY discarded with the order's own instant. "
            .'From here on nothing can tell that line from the ones the order took with it, so a restore can only '
            .'resurrect it (or collide on it). cascade_deletion_to_lines must archive LIVE lines only.');
    }

    /**
     * The owner's Q3 ruling (2026-09-21): **a restore revives only the lines deleted WITH the order**, never a line
     * deleted individually before it. The shipped restore branch matched every line not already live, so a restore
     * brought the discarded line back **silently** whenever nothing collided — and then `total_amount` no longer
     * matched the lines, because the trigger writes only the line table.
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('cascadeParents')]
    public function restoringAnOrderDoesNotResurrectALineArchivedBeforeIt(string $parent): void
    {
        $o = $this->seedDraftOrderWithTwoLines($parent);

        $this->writeAsApp("UPDATE {$o['child']} SET deleted_at = '2026-01-01T00:00:00+00' WHERE id = ?::uuid", [$o['line2']]);
        $this->writeAsApp("UPDATE $parent SET deleted_at = '2026-02-01T00:00:00+00' WHERE id = ?::uuid", [$o['order']]);
        $this->writeAsApp("UPDATE $parent SET deleted_at = NULL WHERE id = ?::uuid", [$o['order']]);

        $stamps = $this->lineStamps($o);
        self::assertNull($stamps[$o['line1']],
            "Control: restoring the $parent did not bring back the line it archived, so a function that restores "
            .'nothing would pass the next assertion.');
        self::assertSame('2026-01-01 00:00:00', $stamps[$o['line2']],
            "Restoring the $parent resurrected a line the user had deleted BEFORE deleting the order — silently, "
            .'with no error. The owner ruled (Q3, 2026-09-21) that only the lines archived with the order return.');
    }

    /**
     * The collision `ff281dc` found and translated on the LINE restore path, reached here through the ORDER's cascade
     * — V2-R-12b's second trigger, which until this test nothing in `backend/` went red about.
     *
     * A replacement line is created exactly as the shipped writers create one: `line_number = MAX + 1` over LIVE lines
     * (so it takes the discarded line's number) and the same retry key (the key lookup is live-only). Under the shipped
     * function the order's restore tried to bring the discarded line back beside it and raised `23505` on
     * `…_line_unique` — first, before the key's unique could fire. Under Q3's rule the discarded line stays where it is,
     * so there is nothing to collide with.
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('cascadeParents')]
    public function restoringAnOrderWhoseReplacementLineHoldsTheArchivedLinesNumberAndKeySucceeds(string $parent): void
    {
        $o = $this->seedDraftOrderWithTwoLines($parent);

        $this->writeAsApp("UPDATE {$o['child']} SET deleted_at = '2026-01-01T00:00:00+00' WHERE id = ?::uuid", [$o['line2']]);
        $replacement = $this->insertLine($o, 2, withTheArchivedLinesKey: true);
        $this->writeAsApp("UPDATE $parent SET deleted_at = '2026-02-01T00:00:00+00' WHERE id = ?::uuid", [$o['order']]);

        try {
            $this->writeAsApp("UPDATE $parent SET deleted_at = NULL WHERE id = ?::uuid", [$o['order']]);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
            self::fail("Restoring the $parent collided with its own replacement line — the cascade tried to bring back "
                .'a line the user had discarded before the order, beside the line that replaced it: '.$e->getMessage());
        }

        $stamps = $this->lineStamps($o);
        self::assertNull($stamps[$o['line1']], 'Control: the line archived with the order did not come back.');
        self::assertNull($stamps[$replacement], 'Control: the replacement line archived with the order did not come back.');
        self::assertSame('2026-01-01 00:00:00', $stamps[$o['line2']],
            'The line discarded before the order was resurrected by the order\'s restore.');
    }

    /**
     * A draft order with two lines, written as `erp_app` so the cascade runs as the invoker it runs as in production:
     * line 1 carries no retry key (as a nested create's lines do not), line 2 carries one (as a sub-resource create's
     * does). Drafts from the start, on both tables: `sales_order_archived_only_while_draft` refuses an archived
     * non-draft, and purchasing's own write path archives only drafts.
     *
     * `purchase_order_idempotency_key_required` refuses a keyless `purchase_order` insert (`RED` §2.1), so both parents
     * carry a generated key.
     *
     * @return array{parent: string, child: string, fk: string, order: string, line1: string, line2: string, item: string}
     */
    private function seedDraftOrderWithTwoLines(string $parent): array
    {
        $this->seedWarehouse();
        $order = Uuid::v4()->toRfc4122();

        if ('purchase_order' === $parent) {
            $supplierId = Uuid::v4()->toRfc4122();
            $this->insertSupplier($supplierId, self::TENANT_A, 'Cascade', 'CAS');
            $item = $this->seedItem(self::TENANT_A, 'SKU-KEEP', $supplierId);
            $this->writeAsApp(<<<'SQL'
                INSERT INTO purchase_order (id, tenant_id, order_number, supplier_id, warehouse_id, currency_code, status, idempotency_key, request_digest)
                VALUES (?::uuid, ?::uuid, 'PO-116000', ?::uuid, (SELECT id FROM warehouse WHERE code = 'WH-C'), 'EUR', 'draft',
                        encode(sha256(gen_random_uuid()::text::bytea), 'hex'), encode(sha256(gen_random_uuid()::text::bytea), 'hex'))
                SQL, [$order, self::TENANT_A, $supplierId]);
            $o = ['parent' => $parent, 'child' => 'purchase_order_line', 'fk' => 'purchase_order_id'];
        } else {
            $item = $this->seedItem(self::TENANT_A, 'SKU-KEEP');
            $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                INSERT INTO customer (id, tenant_id, code, name, country_code, currency_code)
                VALUES (gen_random_uuid(), ?::uuid, 'CUS-K', 'Keep Customer', 'GB', 'EUR')
                SQL, [self::TENANT_A]);
            $this->writeAsApp(<<<'SQL'
                INSERT INTO sales_order
                    (id, tenant_id, order_number, customer_id, warehouse_id, currency_code, status, ship_to_country_code,
                     idempotency_key, request_digest)
                VALUES (?::uuid, ?::uuid, 'SO-166000', (SELECT id FROM customer WHERE code = 'CUS-K'),
                        (SELECT id FROM warehouse WHERE code = 'WH-C'), 'EUR', 'draft', 'DE',
                        encode(sha256(gen_random_uuid()::text::bytea), 'hex'), encode(sha256(gen_random_uuid()::text::bytea), 'hex'))
                SQL, [$order, self::TENANT_A]);
            $o = ['parent' => $parent, 'child' => 'sales_order_line', 'fk' => 'sales_order_id'];
        }

        $o += ['order' => $order, 'item' => $item];
        $o['line1'] = $this->insertLine($o, 1, withTheArchivedLinesKey: false);
        $o['line2'] = $this->insertLine($o, 2, withTheArchivedLinesKey: true);

        return $o;
    }

    /**
     * One line. `withTheArchivedLinesKey` gives it the fixed retry key line 2 is created with, so a replacement line
     * holds exactly the key the shipped live-only key lookup would let it reuse.
     *
     * @param array{parent: string, child: string, fk: string, order: string, item: string} $o
     */
    private function insertLine(array $o, int $lineNumber, bool $withTheArchivedLinesKey): string
    {
        $id = Uuid::v4()->toRfc4122();
        $key = $withTheArchivedLinesKey ? hash('sha256', 'the-archived-lines-key') : null;
        $digest = $withTheArchivedLinesKey ? hash('sha256', 'the-archived-lines-body') : null;

        if ('purchase_order' === $o['parent']) {
            $this->writeAsApp(<<<'SQL'
                INSERT INTO purchase_order_line
                    (id, tenant_id, purchase_order_id, inventory_item_id, line_number, quantity_ordered, unit_cost, lead_time_days,
                     idempotency_key, request_digest)
                VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, 10, 5.00, 7, ?, ?)
                SQL, [$id, self::TENANT_A, $o['order'], $o['item'], $lineNumber, $key, $digest]);
        } else {
            $this->writeAsApp(<<<'SQL'
                INSERT INTO sales_order_line
                    (id, tenant_id, sales_order_id, inventory_item_id, line_number, quantity, unit_price, idempotency_key, request_digest)
                VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, 10, 12.50, ?, ?)
                SQL, [$id, self::TENANT_A, $o['order'], $o['item'], $lineNumber, $key, $digest]);
        }

        return $id;
    }

    /** @param list<mixed> $params */
    private function writeAsApp(string $sql, array $params): void
    {
        $this->tenantContext()->run(self::TENANT_A, function () use ($sql, $params): void {
            $this->app()->executeStatement($sql, $params);
        });
    }

    /**
     * Every line of the order, archived or not, keyed by id: `null` when live, else its stamp in UTC to the second.
     *
     * @param array{child: string, fk: string, order: string} $o
     *
     * @return array<string, string|null>
     */
    private function lineStamps(array $o): array
    {
        $rows = $this->fetchAsOwnerForTenant(self::TENANT_A, <<<SQL
            SELECT id::text AS id, to_char(deleted_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS stamp
            FROM {$o['child']} WHERE {$o['fk']} = ?::uuid
            SQL, [$o['order']]);

        return array_column($rows, 'stamp', 'id');
    }

    /**
     * `RED`'s MAJOR-3. ADR-006 §2.1 step 2's sentinel exists so an unresolved
     * slug still runs the `app_user` SELECT and finds nothing -- which is what
     * makes the statement sequence identical on every miss path (V-8). "Owns no
     * app_user rows" is the property the whole equalisation rests on, and it was
     * a property of nothing until this constraint.
     */
    #[Test]
    public function theSentinelTenantCannotOwnAUser(): void
    {
        $thrown = null;
        try {
            // As the owner, through the shared helper: FORCE ROW LEVEL SECURITY
            // binds the owner too, so a seeding path needs a context like anyone
            // else -- and this is the route a fixture builder would take.
            $this->insertAsOwnerForTenant(SchemaManifest::SENTINEL_TENANT_ID, <<<'SQL'
                INSERT INTO app_user (id, tenant_id, email, password_hash, display_name)
                VALUES (gen_random_uuid(), ?::uuid, 'nobody@example.com', 'x', 'Nobody')
                SQL, [SchemaManifest::SENTINEL_TENANT_ID]);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown, 'A user was created against the sentinel tenant.');
        self::assertStringContainsString('app_user_not_sentinel_tenant', $thrown->getMessage());
    }

    /**
     * ADR-007 §2, stated as a test so the two filters cannot quietly become the
     * same kind of thing in someone's mental model.
     *
     * Turning the deletion filter off shows a deleted row -- a bug. Turning the
     * tenant filter off shows nothing extra at all, because RLS is underneath
     * it. Same gesture, two entirely different consequences, in one test.
     */
    #[Test]
    public function theDeletionFilterIsErgonomicsAndTheTenantFilterHasABackstop(): void
    {
        $deletedId = Uuid::v4()->toRfc4122();
        $this->insertSupplier($deletedId, self::TENANT_A, 'Gone', 'GONE');
        $this->insertSupplier(Uuid::v4()->toRfc4122(), self::TENANT_A, 'Here', 'HERE');
        $this->insertSupplier(Uuid::v4()->toRfc4122(), self::TENANT_B, 'Other Tenant', 'OTHER');

        $this->softDeleteSupplier(self::TENANT_A, $deletedId);

        $this->em()->clear();

        $withBothFilters = $this->tenantContext()->run(self::TENANT_A, fn (): int => \count(
            $this->em()->createQuery('SELECT s FROM '.Supplier::class.' s')->getResult()));

        self::assertSame(1, $withBothFilters, 'The deleted row was returned with both filters on.');

        // Deletion filter off: the deleted row comes back. A bug, not a breach.
        $this->em()->getFilters()->disable(SoftDeleteFilter::NAME);
        $this->em()->clear();

        $withoutDeletionFilter = $this->tenantContext()->run(self::TENANT_A, fn (): int => \count(
            $this->em()->createQuery('SELECT s FROM '.Supplier::class.' s')->getResult()));

        // Tenant filter off as well: still nothing from tenant B, because the
        // boundary was never the filter.
        $this->em()->getFilters()->disable(TenantFilter::NAME);
        $this->em()->clear();

        $withNeitherFilter = $this->tenantContext()->run(self::TENANT_A, fn (): array => array_map(
            static fn (Supplier $s): string => $s->getName(),
            $this->em()->createQuery('SELECT s FROM '.Supplier::class.' s')->getResult()));

        $this->em()->getFilters()->enable(SoftDeleteFilter::NAME);
        $this->em()->getFilters()->enable(TenantFilter::NAME);

        self::assertSame(2, $withoutDeletionFilter,
            'Disabling the deletion filter must expose the deleted row -- it is ergonomics, and this is the '
            .'bug it protects against, not a control.');

        sort($withNeitherFilter);
        self::assertSame(['Gone', 'Here'], $withNeitherFilter,
            'With both filters off, RLS must still return tenant A only. If tenant B appeared here, the '
            .'tenant filter was the boundary all along, which is the mistake ADR-002 §4 exists to prevent.');
    }
}

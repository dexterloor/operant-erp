<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

use App\Modules\Purchasing\ApiResource\CreatePurchaseOrderLineInput;
use App\Modules\Purchasing\ApiResource\PurchaseOrderLineResource;
use App\Modules\Purchasing\ApiResource\PurchaseOrderResource;
use App\Modules\Purchasing\ApiResource\UpdatePurchaseOrderInput;
use App\Modules\Purchasing\ApiResource\UpdatePurchaseOrderLineInput;
use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The write half of the `/purchase-orders` slice, minus the approval.
 *
 * `ApprovePurchaseOrder` is `PurchaseOrderApproval`'s, in a file of its own,
 * because §8.1 fixes an ordering that has to be readable in one screen and
 * because it is the only path here that touches an entity rather than a table.
 *
 * ===========================================================================
 * L-5 and L-8 — the state machine closes editing, not a permission
 * ===========================================================================
 * **L-5:** *"For documents with a state machine the detail page is the editor
 * only while `status = 'draft'`. After the first transition the page is read-only
 * except for the transition actions themselves."* F-2 step 8 says it from the
 * flow's side: *"From here the document is read-only except for its
 * transitions."*
 *
 * **L-8:** *"A document is deletable only while `status = 'draft'`."* And L-8
 * states why it cannot be a permission test: *"`purchasing_manager` holds
 * `purchase_order.delete` **and** `.approve` **and** `.cancel`, so a permission
 * test alone would let it delete a placed order."* Disposing of a draft and
 * repudiating a placed commitment are different acts — `.delete` and `.cancel` —
 * and it is the **status** that decides which is available.
 *
 * Every write below therefore reads the order's status first and refuses on
 * anything but `draft`, with `business-rule-violated` (E-2's second case: the
 * caller holds the code and is looking at the row) and **never** 403 or 404.
 * `refuseUnlessDraft()` is the one place that rule is written.
 *
 * ===========================================================================
 * `total_amount` is recomputed on every line write, and nobody owned that
 * ===========================================================================
 * `purchase_order.total_amount` is `numeric(19,4) NOT NULL DEFAULT 0`, §4.4.3
 * declares it a field of the read shape, and **no document says who maintains
 * it**. Left alone it would read `0` on an order with lines, which is a number
 * the screen would show and the database would not have earned. It is recomputed
 * from the live lines inside the same transaction as the line write, so the two
 * cannot disagree. **Reported as a decision taken here rather than presented as
 * derived.**
 *
 * ===========================================================================
 * Where a constraint failure is caught
 * ===========================================================================
 * Outside `TenantContext::run()`, never inside it — `ItemWriter`'s rule, for its
 * reason: a failed statement leaves the Postgres transaction aborted (25P02), so
 * catching within the block and continuing makes the following `COMMIT` fail with
 * an error nobody can trace back to the line that caused it.
 */
final readonly class PurchaseOrderWriter
{
    /** numeric(19,6) — quantities. Precision 19, scale 6, so at most 13 digits before the point. */
    private const QUANTITY = '/^\d{1,13}(\.\d{1,6})?$/';

    /** numeric(19,4) — money (S-12). Never a float, in either direction. */
    private const MONEY = '/^\d{1,15}(\.\d{1,4})?$/';

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
    ) {
    }

    // -----------------------------------------------------------------------
    // The order
    // -----------------------------------------------------------------------

    public function update(string $id, UpdatePurchaseOrderInput $input): PurchaseOrderResource
    {
        $submitted = $input->submitted();
        $this->refuseMalformedOrderFields($submitted);

        return $this->translatingConstraintFailures(fn (): PurchaseOrderResource => $this->tenants->run(
            $this->tenant->tenantId(),
            function (EntityManagerInterface $em) use ($id, $submitted): PurchaseOrderResource {
                $current = $this->readOrderInside($em, $id) ?? throw ApiProblem::notFound();

                // ST-9 / §5.2a: an archived row renders read-only, and the
                // refusal is on the business-rule branch rather than
                // `not-found` -- the caller can see the row, so pretending it is
                // gone would be a different lie from the one it is being told.
                $this->refuseIfArchived($current);
                $this->refuseUnlessDraft($current);

                if ([] !== $submitted) {
                    $em->getConnection()->executeStatement(
                        'UPDATE purchase_order SET '.implode(', ', array_map(
                            static fn (string $property): string => self::ORDER_COLUMNS[$property].' = '.self::bind($property, self::ORDER_CASTS),
                            array_keys($submitted),
                        )).', updated_at = now() WHERE id = CAST(:id AS uuid)',
                        [...$submitted, 'id' => $id],
                    );
                }

                return $this->readOrderInside($em, $id) ?? throw ApiProblem::notFound();
            },
        ));
    }

    /**
     * `DeletePurchaseOrder` — API-12: `purchase_order` is `SchemaManifest::SOFT`,
     * so this sets `deleted_at`, and `purchase_order_cascade_deletion` carries it
     * to the lines in the database. API-21 branch 3: **204, and no body**.
     */
    public function archive(string $id): void
    {
        $this->tenants->run($this->tenant->tenantId(), function (EntityManagerInterface $em) use ($id): void {
            $current = $this->readOrderInside($em, $id) ?? throw ApiProblem::notFound();

            $this->refuseIfArchived($current);
            // L-8, and it is the whole of the difference between this operation
            // and `CancelPurchaseOrder`.
            $this->refuseUnlessDraft($current);

            $em->getConnection()->executeStatement(
                'UPDATE purchase_order SET deleted_at = now(), updated_at = now() WHERE id = ?::uuid',
                [$id],
            );
        });
    }

    // -----------------------------------------------------------------------
    // The lines
    // -----------------------------------------------------------------------

    public function createLine(string $orderId, CreatePurchaseOrderLineInput $input): PurchaseOrderLineResource
    {
        $values = [
            'inventoryItemId' => $input->inventoryItemId,
            'quantity' => $input->quantity,
            'unitCost' => $input->unitCost,
        ];

        $this->refuseMalformedLineFields($values, required: ['inventoryItemId', 'quantity', 'unitCost']);

        $id = Uuid::v4()->toRfc4122();

        return $this->translatingConstraintFailures(fn (): PurchaseOrderLineResource => $this->tenants->run(
            $this->tenant->tenantId(),
            function (EntityManagerInterface $em) use ($orderId, $values, $id): PurchaseOrderLineResource {
                $connection = $em->getConnection();

                $order = $this->readOrderInside($em, $orderId) ?? throw ApiProblem::notFound();
                $this->refuseIfArchived($order);
                $this->refuseUnlessDraft($order);

                $itemId = (string) $values['inventoryItemId'];

                // ST-5's oracle: `inventory_item` is tenant-scoped, so this
                // lookup is bounded by RLS and an id belonging to another tenant
                // is indistinguishable from one that never existed. That is why
                // it is a `validation-failed` on the *field* rather than a
                // `not-found` on a row the caller never addressed.
                if (false === $connection->fetchOne('SELECT 1 FROM inventory_item WHERE id = ?::uuid AND deleted_at IS NULL', [$itemId])) {
                    throw ApiProblem::validationFailed([
                        self::error('inventoryItemId', 'unknown_reference', 'No live inventory item in this workspace has this id.'),
                    ]);
                }

                // F-2 step 3 -- lead time is **snapshotted** onto the line from
                // the supplier's catalogue, never referenced, because
                // `supplier_product` is deletion class `hard` *"precisely so
                // current terms can change without rewriting a placed order"*.
                // `purchase_order_line.lead_time_days` is NOT NULL with no
                // default and §4.2 accepts no field for it, so this read is the
                // only source there is.
                $leadTime = $connection->fetchOne(
                    <<<'SQL'
                    SELECT sp.lead_time_days
                    FROM supplier_product sp
                    JOIN purchase_order po ON po.tenant_id = sp.tenant_id AND po.supplier_id = sp.supplier_id
                    WHERE po.id = ?::uuid AND sp.inventory_item_id = ?::uuid
                    SQL,
                    [$orderId, $itemId],
                );

                if (false === $leadTime) {
                    // A rule about *this* order and *this* item rather than a
                    // defect in a field the caller sent -- the supplier comes
                    // from the order, not from the request -- so E-2's second
                    // case and §5.2's `business-rule-violated`.
                    throw ApiProblem::businessRuleViolated(
                        'purchase_order_line.supplier_lists_the_item',
                        'This order\'s supplier has no catalogue entry for that item, so the line has no lead time to record.',
                    );
                }

                $connection->executeStatement(
                    <<<'SQL'
                    INSERT INTO purchase_order_line
                        (id, tenant_id, purchase_order_id, inventory_item_id, line_number,
                         quantity_ordered, unit_cost, lead_time_days)
                    VALUES
                        (?::uuid, ?::uuid, ?::uuid, ?::uuid,
                         -- The next free number on this order. `line_number` is
                         -- NOT NULL, §4.2 accepts no field for it, and
                         -- `purchase_order_line_unique` is partial on
                         -- `deleted_at IS NULL` -- so a discarded number is
                         -- reusable and this MAX is over the live lines only,
                         -- which is the same set the index constrains.
                         (SELECT COALESCE(MAX(line_number), 0) + 1
                            FROM purchase_order_line
                           WHERE purchase_order_id = ?::uuid AND deleted_at IS NULL),
                         ?::numeric, ?::numeric, ?)
                    SQL,
                    [
                        $id,
                        // Bound explicitly rather than read back out of the GUC.
                        // The row still has to satisfy the RLS WITH CHECK
                        // clause, so a mismatch between this value and the
                        // transaction's tenant is refused by Postgres rather
                        // than accepted.
                        $this->tenant->tenantId(),
                        $orderId,
                        $itemId,
                        $orderId,
                        $values['quantity'],
                        $values['unitCost'],
                        (int) $leadTime,
                    ],
                );

                $this->recomputeTotal($em, $orderId);

                return $this->readLineInside($em, $orderId, $id)
                    ?? throw new \LogicException('The line just inserted is not visible to its own transaction.');
            },
        ));
    }

    public function updateLine(string $orderId, string $lineId, UpdatePurchaseOrderLineInput $input): PurchaseOrderLineResource
    {
        $submitted = $input->submitted();
        $this->refuseMalformedLineFields($submitted, required: []);

        return $this->translatingConstraintFailures(fn (): PurchaseOrderLineResource => $this->tenants->run(
            $this->tenant->tenantId(),
            function (EntityManagerInterface $em) use ($orderId, $lineId, $submitted): PurchaseOrderLineResource {
                $order = $this->readOrderInside($em, $orderId) ?? throw ApiProblem::notFound();
                $this->refuseIfArchived($order);
                $this->refuseUnlessDraft($order);

                $line = $this->readLineInside($em, $orderId, $lineId) ?? throw ApiProblem::notFound();

                // A line whose order is a draft cannot itself be archived --
                // the trigger only ever archives lines with their order -- but
                // the check is written rather than argued, because it is one
                // `UPDATE` away from being false and §5.2a requires the refusal
                // either way.
                if (null !== $line->archivedAt) {
                    throw self::lineArchived();
                }

                if ([] !== $submitted) {
                    $em->getConnection()->executeStatement(
                        'UPDATE purchase_order_line SET '.implode(', ', array_map(
                            static fn (string $property): string => self::LINE_COLUMNS[$property].' = '.self::bind($property, self::LINE_CASTS),
                            array_keys($submitted),
                        )).', updated_at = now() WHERE id = CAST(:id AS uuid)',
                        [...$submitted, 'id' => $lineId],
                    );
                }

                $this->recomputeTotal($em, $orderId);

                return $this->readLineInside($em, $orderId, $lineId) ?? throw ApiProblem::notFound();
            },
        ));
    }

    /** API-21 branch 3: **204, and no body**. `purchase_order_line` is `SchemaManifest::SOFT`. */
    public function archiveLine(string $orderId, string $lineId): void
    {
        $this->tenants->run($this->tenant->tenantId(), function (EntityManagerInterface $em) use ($orderId, $lineId): void {
            $order = $this->readOrderInside($em, $orderId) ?? throw ApiProblem::notFound();
            $this->refuseIfArchived($order);
            $this->refuseUnlessDraft($order);

            $line = $this->readLineInside($em, $orderId, $lineId) ?? throw ApiProblem::notFound();

            if (null !== $line->archivedAt) {
                throw self::lineArchived();
            }

            $em->getConnection()->executeStatement(
                'UPDATE purchase_order_line SET deleted_at = now(), updated_at = now() WHERE id = ?::uuid',
                [$lineId],
            );

            $this->recomputeTotal($em, $orderId);
        });
    }

    // -----------------------------------------------------------------------

    /** Property name => column, so no UPDATE restates the field list. */
    private const ORDER_COLUMNS = [
        'supplierId' => 'supplier_id',
        'warehouseId' => 'warehouse_id',
        'expectedAt' => 'expected_at',
    ];

    /**
     * Written as `CAST(:p AS t)` rather than `:p::t` because DBAL's parser has to
     * tell a Postgres cast from a named placeholder, and `:supplierId::uuid` is
     * exactly the string where that goes wrong.
     */
    private const ORDER_CASTS = [
        'supplierId' => 'uuid',
        'warehouseId' => 'uuid',
        'expectedAt' => 'timestamptz',
    ];

    private const LINE_COLUMNS = [
        'inventoryItemId' => 'inventory_item_id',
        'quantity' => 'quantity_ordered',
        'unitCost' => 'unit_cost',
    ];

    private const LINE_CASTS = [
        'inventoryItemId' => 'uuid',
        'quantity' => 'numeric',
        'unitCost' => 'numeric',
    ];

    private const POINTERS = [
        'supplierId' => '/supplier_id',
        'warehouseId' => '/warehouse_id',
        'expectedAt' => '/expected_at',
        'inventoryItemId' => '/inventory_item_id',
        'quantity' => '/quantity',
        'unitCost' => '/unit_cost',
    ];

    /** @param array<string, string> $casts */
    private static function bind(string $property, array $casts): string
    {
        return \sprintf('CAST(:%s AS %s)', $property, $casts[$property]);
    }

    /**
     * §4.4.3 declares `total_amount` and no document says who maintains it. See
     * the class docblock: this is a decision taken here.
     *
     * The sum is over **live** lines, which is the same set `archiveLine()`
     * removes a row from and the same set the cascade trigger empties when the
     * order is archived.
     */
    private function recomputeTotal(EntityManagerInterface $em, string $orderId): void
    {
        $em->getConnection()->executeStatement(
            <<<'SQL'
            UPDATE purchase_order
               SET total_amount = (
                       SELECT COALESCE(SUM(quantity_ordered * unit_cost), 0)
                         FROM purchase_order_line
                        WHERE purchase_order_id = ?::uuid AND deleted_at IS NULL
                   ),
                   updated_at = now()
             WHERE id = ?::uuid
            SQL,
            [$orderId, $orderId],
        );
    }

    private function readOrderInside(EntityManagerInterface $em, string $id): ?PurchaseOrderResource
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $row = $em->getConnection()->fetchAssociative(
            'SELECT '.PurchaseOrderRow::COLUMNS.' '.PurchaseOrderRow::FROM.' WHERE po.id = ?::uuid',
            [$id],
        );

        return false === $row ? null : PurchaseOrderRow::toResource($row);
    }

    /**
     * The line is addressed by **both** path variables, so a `lineId` that names
     * a line of a different order is `not-found` rather than an edit of somebody
     * else's line. §5.3 clause 2 applies to the pair, not to the last segment.
     */
    private function readLineInside(EntityManagerInterface $em, string $orderId, string $lineId): ?PurchaseOrderLineResource
    {
        if (!Uuid::isValid($orderId) || !Uuid::isValid($lineId)) {
            return null;
        }

        $row = $em->getConnection()->fetchAssociative(
            'SELECT '.PurchaseOrderLineRow::COLUMNS.' '.PurchaseOrderLineRow::FROM
            .' WHERE pol.id = ?::uuid AND pol.purchase_order_id = ?::uuid',
            [$lineId, $orderId],
        );

        return false === $row ? null : PurchaseOrderLineRow::toResource($row);
    }

    // -----------------------------------------------------------------------
    // Shape, and only shape. Everything here is decidable without the database,
    // so it runs before a transaction is opened.
    // -----------------------------------------------------------------------

    /** @param array<string, string|null> $values */
    private function refuseMalformedOrderFields(array $values): void
    {
        $errors = [];

        // Both parent columns are NOT NULL, so neither may be cleared by a
        // PATCH. `expected_at` is nullable and `null` legitimately means "clear
        // the expected date".
        foreach (['supplierId', 'warehouseId'] as $property) {
            if (\array_key_exists($property, $values) && null === $values[$property]) {
                $errors[] = self::error($property, 'not_nullable', 'This field may not be cleared.');
            }
        }

        foreach (['supplierId', 'warehouseId'] as $property) {
            $value = $values[$property] ?? null;
            if (null !== $value && !Uuid::isValid($value)) {
                $errors[] = self::error($property, 'not_a_uuid', 'This field must be a UUID naming a row in this workspace.');
            }
        }

        if (null !== ($values['expectedAt'] ?? null) && null === self::parseTimestamp($values['expectedAt'])) {
            $errors[] = self::error('expectedAt', 'not_a_timestamp', 'This field must be an RFC 3339 timestamp.');
        }

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }
    }

    /**
     * @param array<string, string|null> $values
     * @param list<string>               $required
     */
    private function refuseMalformedLineFields(array $values, array $required): void
    {
        $errors = [];

        foreach ($required as $property) {
            if (null === ($values[$property] ?? null)) {
                $errors[] = self::error($property, 'required', 'This field is required.');
            }
        }

        // Every column a line write touches is NOT NULL, so none may be cleared.
        foreach (array_keys($values) as $property) {
            if (null === $values[$property] && !\in_array($property, $required, true)) {
                $errors[] = self::error($property, 'not_nullable', 'This field may not be cleared.');
            }
        }

        if (null !== ($values['inventoryItemId'] ?? null) && !Uuid::isValid($values['inventoryItemId'])) {
            $errors[] = self::error('inventoryItemId', 'not_a_uuid', 'This field must be a UUID naming an item in this workspace.');
        }

        // `purchase_order_line_qty_sane` is `quantity_ordered > 0`, so zero and
        // negative are refused here rather than at the database -- a CHECK
        // violation is a 500 with no pointer, and §5.2 wants the field named.
        $quantity = $values['quantity'] ?? null;
        if (null !== $quantity && (1 !== preg_match(self::QUANTITY, $quantity) || 0 === bccomp($quantity, '0', 6))) {
            $errors[] = self::error('quantity', 'not_a_positive_decimal', 'This field must be a positive decimal string with at most 13 integer digits and 6 fractional digits.');
        }

        $unitCost = $values['unitCost'] ?? null;
        if (null !== $unitCost && 1 !== preg_match(self::MONEY, $unitCost)) {
            $errors[] = self::error('unitCost', 'not_a_decimal', 'This field must be a non-negative decimal string with at most 15 integer digits and 4 fractional digits.');
        }

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }
    }

    /**
     * Accepts what `timestamptz` accepts and nothing looser. `strtotime` would
     * take "next tuesday", which is not a value a client should be able to store
     * in a supplier commitment.
     */
    private static function parseTimestamp(string $value): ?\DateTimeImmutable
    {
        foreach ([\DateTimeInterface::RFC3339_EXTENDED, \DateTimeInterface::RFC3339] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $value);
            if (false !== $parsed) {
                return $parsed;
            }
        }

        return null;
    }

    private function refuseIfArchived(PurchaseOrderResource $order): void
    {
        if (null !== $order->archivedAt) {
            throw ApiProblem::businessRuleViolated(
                'purchase_order.is_archived',
                'This purchase order is archived. Restore it before changing it.',
            );
        }
    }

    /**
     * **L-5 and L-8, in the one place either is written.**
     *
     * The legal status is read off the entity's own constant rather than spelled
     * as a literal, so a rename of `STATUS_DRAFT`'s value moves this rule with it
     * — the same discipline API-15 requires of a transition guard.
     */
    private function refuseUnlessDraft(PurchaseOrderResource $order): void
    {
        if (PurchaseOrder::STATUS_DRAFT !== $order->status) {
            throw ApiProblem::businessRuleViolated(
                'purchase_order.is_editable_only_while_draft',
                'This purchase order has been placed, so it is read-only except for its transitions.',
            );
        }
    }

    private static function lineArchived(): ApiProblem
    {
        return ApiProblem::businessRuleViolated(
            'purchase_order_line.is_archived',
            'This line is archived, and a line is restored with the order it belongs to.',
        );
    }

    /** @return array{pointer: string, code: string, message: string} */
    private static function error(string $property, string $code, string $message): array
    {
        return ['pointer' => self::POINTERS[$property], 'code' => $code, 'message' => $message];
    }

    /**
     * @template T
     *
     * @param callable(): T $write
     *
     * @return T
     */
    private function translatingConstraintFailures(callable $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            // The only uniqueness a write here can hit is
            // `purchase_order_line_unique` -- (tenant_id, purchase_order_id,
            // line_number) among live lines -- and it can only be hit by two
            // concurrent creates picking the same MAX+1. It is a genuine
            // conflict rather than a client mistake, and the caller's answer is
            // to retry.
            throw ApiProblem::businessRuleViolated(
                'purchase_order_line.line_number_is_unique_per_order',
                'Another line was added to this order at the same moment. Try again.',
            );
        } catch (ForeignKeyConstraintViolationException) {
            // `purchase_order_supplier_fk` and `purchase_order_warehouse_fk` are
            // composite on `(tenant_id, id)`, so an id belonging to another
            // tenant fails here rather than at a lookup -- and ST-5 means the
            // caller must not learn which of "wrong tenant" and "no such row" it
            // was. Both fields are named because the statement does not say
            // which key failed.
            throw ApiProblem::validationFailed([
                self::error('supplierId', 'unknown_reference', 'One of the ids sent names no row in this workspace.'),
                self::error('warehouseId', 'unknown_reference', 'One of the ids sent names no row in this workspace.'),
            ]);
        }
    }
}

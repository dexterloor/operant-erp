<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Purchasing\Application\PurchaseOrderWriter;
use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Modules\Suppliers\Application\SupplierWriter;
use App\Modules\Warehouses\Application\WarehouseWriter;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`RestorePurchaseOrder`** (order restores C3, 2026-10-22; `docs/session13/order-restores-design.md` §6 as amended by
 * Revision 1 R1.2/R1.3/R1.4, `order-restores-final-red.md` L-2, and `restore-warehouse-rule-code-spar.md`).
 * `POST /purchase-orders/{id}/restoration`, `purchase_order.delete`, no body, 200 with `GetPurchaseOrder`'s body;
 * `guard:purchase_order.deleted_at`. `SalesOrderRestoreTest`'s twin, with purchase names and two differences:
 *
 *  - **the total is RECOMPUTED** (R1.3) — a purchase order deleted before `3292c4a` (2026-09-21) can revive lines its user
 *    deleted one by one, because the old cascade re-stamped them with the order's instant. Three owner-seeded LEGACY
 *    fixtures (final RED L-2): (a) two revived lines sharing a number → 409; (b) a non-colliding revived line → 200 and the
 *    total is the revived lines' sum, not the stored total; (c) revived lines whose sum leaves `numeric(19,4)` → the R1.3
 *    409, never `recomputeTotal()`'s `LogicException` 500;
 *  - **the order NUMBER is legacy-reachable** (purchasing's delete shipped 2026-09-10, before its Q4 fix) and translated.
 *
 * The parent refusals reuse the codes `ApprovePurchaseOrder` answers (the spar, binding: no new rule code): warehouse
 * first, then supplier, both reads before either refusal. Races in R1.7's shape (the holder is a separate process; where it
 * runs the restore's own statements it runs the writer's PUBLIC constants).
 */
final class PurchaseOrderRestoreTest extends PurchaseOrderTestCase
{
    private const HOLDER_DEADLINE_SECONDS = 10.0;

    /** The legacy instant every legacy fixture stamps its order and its "deleted with the order" lines with. */
    private const LEGACY_STAMP = '2026-09-01 12:00:00+00';

    // -----------------------------------------------------------------------
    // The happy path
    // -----------------------------------------------------------------------

    /**
     * Delete then restore → 200, the body is `GetPurchaseOrder`'s, `archived_at` null; the lines deleted WITH the order are
     * live again, and **a line deleted on its own before the order stays deleted** (owner ruling Q3, true for purchasing
     * since `3292c4a`). The stored total equals the live lines' sum.
     */
    #[Test]
    public function aDeletedDraftComesBackWithTheLinesDeletedWithItAndATotalThatIsTheirSum(): void
    {
        $order = $this->raiseOrder('PO-RT1', [['3', '2.5000'], ['4', '10.0000'], ['1', '7.0000']]);
        $lines = $this->lineIds($order);
        $this->send('DELETE', '/purchase-orders/'.$order.'/lines/'.$lines[2]);
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->send('DELETE', '/purchase-orders/'.$order);
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->restore($order);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertNull($this->client()->getResponse()->headers->get('Location'), 'A restore mints nothing, so it names no Location.');
        $body = $this->json();
        self::assertNull($body['archived_at']);
        self::assertSame(PurchaseOrder::STATUS_DRAFT, $body['status']);
        $this->send('GET', '/purchase-orders/'.$order);
        self::assertSame($this->json(), $body, 'The restore\'s body is not GetPurchaseOrder\'s.');

        self::assertEqualsCanonicalizing([$lines[0], $lines[1]], $this->liveLineIds($order),
            'The lines deleted WITH the order did not come back, or a line deleted on its own did.');

        $sum = $this->totals($order);
        self::assertSame('47.5000', $sum['live']);
        self::assertSame($sum['live'], $sum['stored'], 'After a restore the stored total is not the live lines\' sum.');
        self::assertSame('47.5000', $body['total_amount']);
    }

    /** One `purchase_order.restored` row naming the actor, business, `{}`, subject the order; a refused repeat adds none. */
    #[Test]
    public function aRestoreWritesOneRestoredRowAndARepeatIsRefusedWithoutASecond(): void
    {
        $order = $this->raiseOrder('PO-RT2');
        $this->send('DELETE', '/purchase-orders/'.$order);
        $this->restore($order);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $rows = $this->restoredRows($order);
        self::assertCount(1, $rows);
        self::assertSame($this->actorId, $rows[0]['actor_user_id']);
        self::assertSame('purchase_order', $rows[0]['subject_type']);
        self::assertSame('{}', $rows[0]['metadata']);
        self::assertNull($rows[0]['ip_address']);

        $this->restore($order);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('purchase_order.is_not_archived', $this->json()['rule']);
        self::assertSame(PurchaseOrderWriter::NOT_ARCHIVED, $this->json()['detail']);
        self::assertCount(1, $this->restoredRows($order), 'A refused repeat wrote a second restored row.');
    }

    /** A live order was never deleted: refused, nothing written. */
    #[Test]
    public function aLiveOrderHasNothingToRestore(): void
    {
        $order = $this->raiseOrder('PO-RT3');

        $this->restore($order);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('purchase_order.is_not_archived', $this->json()['rule']);
        self::assertSame([], $this->restoredRows($order));
    }

    // -----------------------------------------------------------------------
    // The parents (R1.4: both read under the order lock; warehouse refused first; the spar: no new rule code)
    // -----------------------------------------------------------------------

    /** C5's invariant: an archived supplier holds no open order, so a draft is not restored onto one. */
    #[Test]
    public function aDraftIsNotRestoredOntoAnArchivedSupplier(): void
    {
        $c = $this->deletedOrder('PO-RT4');
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE supplier SET deleted_at = now() WHERE id = ?::uuid', [$c['supplier']]);

        $this->restore($c['order']);

        $this->assertRefusedAndUnchanged($c['order'], 'purchase_order.supplier_is_live', PurchaseOrderWriter::RESTORE_SUPPLIER_ARCHIVED);
    }

    /** API-O-34's invariant: an archived warehouse holds no open order, so a draft is not restored onto one. */
    #[Test]
    public function aDraftIsNotRestoredOntoAnArchivedWarehouse(): void
    {
        $c = $this->deletedOrder('PO-RT5');
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE warehouse SET deleted_at = now() WHERE id = ?::uuid', [$c['warehouse']]);

        $this->restore($c['order']);

        $this->assertRefusedAndUnchanged($c['order'], 'purchase_order.warehouse_is_live', PurchaseOrderWriter::RESTORE_WAREHOUSE_ARCHIVED);
    }

    /** Both archived → the WAREHOUSE is named (`PurchaseOrder::place()`'s order; the spar's point 3). */
    #[Test]
    public function whenBothParentsAreArchivedTheWarehouseIsNamed(): void
    {
        $c = $this->deletedOrder('PO-RT6');
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE supplier SET deleted_at = now() WHERE id = ?::uuid', [$c['supplier']]);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE warehouse SET deleted_at = now() WHERE id = ?::uuid', [$c['warehouse']]);

        $this->restore($c['order']);

        $this->assertRefusedAndUnchanged($c['order'], 'purchase_order.warehouse_is_live', PurchaseOrderWriter::RESTORE_WAREHOUSE_ARCHIVED);
    }

    // -----------------------------------------------------------------------
    // The collisions (R1.2, purchase column), each reached ALONE
    // -----------------------------------------------------------------------

    /**
     * `purchase_order_tenant_idem_live` — REACHABLE: a retry of the create after the order was deleted makes a NEW order
     * holding the same key (the key lookup reads live rows only). Nested lines carry no key, so only the order key collides.
     */
    #[Test]
    public function anOrderWhoseRetryKeyALiveOrderNowHoldsIsRefusedOnTheOrderKey(): void
    {
        $key = Uuid::v4()->toRfc4122();
        $w = $this->workspace('PO-RT7');
        $this->raise($w, [['1', '1.0000']], $key);
        $first = $this->idFromLocation();
        $this->send('DELETE', '/purchase-orders/'.$first);
        $this->raise($w, [['1', '1.0000']], $key);
        self::assertSame(201, $this->httpStatus(), 'The retry after a delete was not a new order: '.$this->client()->getResponse()->getContent());
        self::assertNotSame($first, $this->idFromLocation());

        $this->restore($first);

        $this->assertRefusedAndUnchanged($first, 'purchase_order.retry_key_is_held_by_a_live_order', PurchaseOrderWriter::RESTORE_ORDER_KEY_HELD);
    }

    /**
     * `purchase_order_line_tenant_idem_live` — REACHABLE ACROSS ORDERS, raised inside the cascade trigger's UPDATE: the
     * trigger's 23505 still names the constraint, or the answer would be a 500. The two orders' own keys differ.
     */
    #[Test]
    public function anOrderWhoseLineKeyALineOnAnotherOrderNowHoldsIsRefusedOnTheLineKey(): void
    {
        $lineKey = Uuid::v4()->toRfc4122();
        $w = $this->workspace('PO-RT8');
        $this->raise($w, [['1', '1.0000']]);
        $a = $this->idFromLocation();
        $item = $this->listedItem($w, 'SKU-PO-RT8-X');
        $this->addLine($a, $item, $lineKey);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->send('DELETE', '/purchase-orders/'.$a);

        $this->raise($w, [['1', '1.0000']]);
        $b = $this->idFromLocation();
        $this->addLine($b, $item, $lineKey);
        self::assertSame(201, $this->httpStatus(), 'The line key was not reusable on another order: '.$this->client()->getResponse()->getContent());

        $this->restore($a);

        $this->assertRefusedAndUnchanged($a, 'purchase_order.line_retry_key_is_held_by_another_order', PurchaseOrderWriter::RESTORE_LINE_KEY_HELD);
    }

    /**
     * `purchase_order_tenant_number_live` — **LEGACY-REACHABLE** (R1.2): purchasing's `DeletePurchaseOrder` shipped
     * 2026-09-10, before the Q4 fix (`8e52309`) stopped a deleted order's number being reissued. Owner-seeded: an archived
     * order and a live one carrying its number.
     */
    #[Test]
    public function aLegacyOrderWhoseNumberALiveOrderWasGivenIsRefusedOnTheNumber(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme PO-RT9');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH PO-RT9');
        $number = self::freshNumber('PO');
        $archived = $this->seedOrder(self::TENANT_A, $number, $supplier, $warehouse, archived: true);
        $this->seedOrder(self::TENANT_A, $number, $supplier, $warehouse);

        $this->restore($archived);

        $this->assertRefusedAndUnchanged($archived, 'purchase_order.order_number_is_taken_by_a_live_order', PurchaseOrderWriter::RESTORE_ORDER_NUMBER_TAKEN);
    }

    /**
     * **Legacy fixture (a)** — `purchase_order_line_unique`, LEGACY-REACHABLE (H-1): a line deleted on its own before
     * `3292c4a`, re-stamped with the order's instant when the order was deleted, then a replacement given its number.
     * Both revive together → the line-number unique, raised in the trigger → 409, never the create path's "try again".
     */
    #[Test]
    public function legacyFixtureA_twoRevivedLinesSharingANumberAreRefusedOnTheLineNumber(): void
    {
        $order = $this->legacyOrder('PO-RT10', [[1, '1.000000', '1.0000'], [1, '2.000000', '1.0000']]);
        self::assertSame(2, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM purchase_order_line WHERE purchase_order_id = ?::uuid AND deleted_at = ?::timestamptz', [$order, self::LEGACY_STAMP]),
            'Fixture (T-17): both lines do not carry the order\'s stamp, so the restore cannot revive both.');

        $this->restore($order);

        $this->assertRefusedAndUnchanged($order, 'purchase_order.line_number_is_taken_on_this_order', PurchaseOrderWriter::RESTORE_LINE_NUMBER_TAKEN);
    }

    /**
     * **Legacy fixture (b)** — R1.3 / R1.8 item 4: a non-colliding line deleted on its own before `3292c4a` (a non-max
     * line, so no replacement took its number) revives with the order. The stored total still excludes it; **the restore
     * recomputes**, so the total afterwards is the revived lines' sum, not the stored figure. The disclosed limit (H-1 c):
     * this line cannot be told apart from the ones deleted with the order.
     */
    #[Test]
    public function legacyFixtureB_aRevivedLegacyLineIsCountedInTheRecomputedTotal(): void
    {
        $order = $this->legacyOrder('PO-RT11', [[1, '2.000000', '5.0000'], [2, '3.000000', '4.0000'], [3, '1.000000', '1.0000']]);
        // The stored total as it stood after the user deleted line 2 on its own: lines 1 and 3 only.
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE purchase_order SET total_amount = 11.0000 WHERE id = ?::uuid', [$order]);

        $this->restore($order);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('23.0000', $this->json()['total_amount'], 'The restore did not recompute: the revived legacy line is not in the total.');
        $sum = $this->totals($order);
        self::assertSame('23.0000', $sum['live']);
        self::assertSame($sum['live'], $sum['stored']);
        self::assertCount(3, $this->liveLineIds($order));
    }

    /**
     * **Legacy fixture (c)** — final RED L-2 / R1.3: two revived lines each fit `numeric(19,4)` but their sum does not. The
     * recompute's overflow is the restore's own 409, with no pointer (a restore has no body), never the `LogicException` 500.
     */
    #[Test]
    public function legacyFixtureC_revivedLinesWhoseSumOverflowsAreRefusedNotA500(): void
    {
        $order = $this->legacyOrder('PO-RT12', [[1, '1000000.000000', '600000000.0000'], [2, '1000000.000000', '600000000.0000']]);

        $this->restore($order);

        $this->assertRefusedAndUnchanged($order, 'purchase_order.total_would_exceed_the_limit_on_restore', PurchaseOrderWriter::RESTORE_TOTAL_TOO_LARGE);
    }

    // -----------------------------------------------------------------------
    // Who may, and which rows
    // -----------------------------------------------------------------------

    /** `purchase_order.delete` is the code; `viewer` and an editor without it are refused before anything is read. */
    #[Test]
    public function onlyAHolderOfTheDeleteCodeMayRestore(): void
    {
        $c = $this->deletedOrder('PO-RT13');

        foreach ([['purchase_order.view'], ['purchase_order.view', 'purchase_order.edit', 'purchase_order.create']] as $codes) {
            $this->actAs(self::TENANT_A, $codes);
            $this->restore($c['order']);
            self::assertSame(403, $this->httpStatus(), implode(',', $codes).': '.$this->client()->getResponse()->getContent());
        }

        self::assertNotNull($this->stored($c['order'])['deleted_at']);
        self::assertSame([], $this->restoredRows($c['order']));
    }

    /** §5.3 clause 2: another tenant's order, an id no row answers and a non-uuid are the same 404. */
    #[Test]
    public function anotherTenantsOrderAndAnIdNoRowAnswersAreNotFound(): void
    {
        $theirs = $this->seedDraft(self::TENANT_B, self::freshNumber('PO'))['order'];
        $this->executeAsOwnerForTenant(self::TENANT_B, 'UPDATE purchase_order SET deleted_at = now() WHERE id = ?::uuid', [$theirs]);

        foreach ([$theirs, Uuid::v4()->toRfc4122(), 'not-a-uuid'] as $id) {
            $this->restore($id);
            self::assertSame(404, $this->httpStatus(), $id.': '.$this->client()->getResponse()->getContent());
        }
        self::assertNotNull($this->fetchAsOwnerForTenant(self::TENANT_B, 'SELECT deleted_at FROM purchase_order WHERE id = ?::uuid', [$theirs])[0]['deleted_at']);
    }

    // -----------------------------------------------------------------------
    // Races (R1.7)
    // -----------------------------------------------------------------------

    /**
     * **R-1d — delete first, the evidence for the restore's supplier lock.** The holder runs `DeleteSupplier`'s own
     * statements (`SupplierWriter::DELETE_LOCK`, `::ARCHIVE`) and holds before COMMIT. The locked restore waits on its
     * `FOR KEY SHARE`, re-reads the committed row and refuses. Mutation "unlocked supplier read" → 200, never waited → red.
     */
    #[Test]
    public function aRestoreRacingADeleteOfItsSupplierWaitsAndIsRefused(): void
    {
        $c = $this->deletedOrder('PO-RT14');

        $holder = $this->raceAgainstAHolder(
            [[SupplierWriter::DELETE_LOCK, [$c['supplier']]], [SupplierWriter::ARCHIVE, [$c['supplier']]]],
            fn () => $this->restore($c['order']),
        );

        $this->assertWaitedThenRefused($holder, $c['order'], 'purchase_order.supplier_is_live', PurchaseOrderWriter::RESTORE_SUPPLIER_ARCHIVED);
    }

    /** **R-2d** — R-1d's twin on the warehouse (`WarehouseWriter::DELETE_LOCK`, `::ARCHIVE`). */
    #[Test]
    public function aRestoreRacingADeleteOfItsWarehouseWaitsAndIsRefused(): void
    {
        $c = $this->deletedOrder('PO-RT15');

        $holder = $this->raceAgainstAHolder(
            [[WarehouseWriter::DELETE_LOCK, [$c['warehouse']]], [WarehouseWriter::ARCHIVE, [$c['warehouse']]]],
            fn () => $this->restore($c['order']),
        );

        $this->assertWaitedThenRefused($holder, $c['order'], 'purchase_order.warehouse_is_live', PurchaseOrderWriter::RESTORE_WAREHOUSE_ARCHIVED);
    }

    /**
     * **R-1r — restore first.** The holder runs the restore's own statements (the writer's public constants) and holds;
     * the API `DeleteSupplier` waits on the `FOR KEY SHARE`, then counts the restored draft and refuses. Because the holder
     * runs `RESTORE_SUPPLIER_READ` itself, a mutation of that constant's lock clause reddens this too.
     */
    #[Test]
    public function aSupplierDeleteRacingARestoreOfItsDraftWaitsAndCountsIt(): void
    {
        $c = $this->deletedOrder('PO-RT16');

        $holder = $this->raceAgainstAHolder(
            [[PurchaseOrderWriter::RESTORE_SUPPLIER_READ, [$c['supplier']]], [PurchaseOrderWriter::RESTORE, [$c['order']]]],
            fn () => $this->send('DELETE', '/suppliers/'.$c['supplier']),
            $c['order'],
        );

        self::assertTrue($holder['waiter_seen'], 'The supplier delete never waited on the restore. '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('supplier.has_open_purchase_orders', $this->json()['rule']);
        self::assertNull($this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT deleted_at FROM supplier WHERE id = ?::uuid', [$c['supplier']])[0]['deleted_at']);
    }

    /** **R-3** — restore against restore: the second waits on the order lock, then finds the order live and is refused. */
    #[Test]
    public function aSecondRestoreWaitsForTheFirstAndIsRefused(): void
    {
        $c = $this->deletedOrder('PO-RT17');

        $holder = $this->raceAgainstAHolder(
            [[PurchaseOrderWriter::RESTORE, [$c['order']]]],
            fn () => $this->restore($c['order']),
            $c['order'],
        );

        self::assertTrue($holder['waiter_seen'], json_encode($holder));
        self::assertTrue($holder['committed'], json_encode($holder));
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('purchase_order.is_not_archived', $this->json()['rule']);
        self::assertSame([], $this->restoredRows($c['order']), 'The refused restore wrote a row (the holder writes none).');
    }

    /**
     * **R-4 — restore against a create retrying the same key**: the holder restores order A (key K) and holds; a create
     * with K reads no live row, waits at `purchase_order_tenant_idem_live`, and after the commit loses with the create
     * path's own in-flight answer — never a 500, never a second live order on K.
     */
    #[Test]
    public function aCreateRetryingTheKeyOfAnOrderBeingRestoredIsToldItIsInFlight(): void
    {
        $key = Uuid::v4()->toRfc4122();
        $w = $this->workspace('PO-RT18');
        $this->raise($w, [['1', '1.0000']], $key);
        $order = $this->idFromLocation();
        $this->send('DELETE', '/purchase-orders/'.$order);

        $holder = $this->raceAgainstAHolder(
            [[PurchaseOrderWriter::RESTORE, [$order]]],
            fn () => $this->raise($w, [['1', '1.0000']], $key),
            $order,
        );

        self::assertTrue($holder['waiter_seen'], 'The create never waited on the restore\'s key. '.json_encode($holder));
        self::assertTrue($holder['committed'], json_encode($holder));
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('purchase_order.idempotency_key_is_in_flight', $this->json()['rule'] ?? null, (string) $this->client()->getResponse()->getContent());
        self::assertSame([$order], array_column($this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT id::text AS id FROM purchase_order WHERE supplier_id = ?::uuid AND deleted_at IS NULL', [$w['supplier']]), 'id'),
            'Two live orders on one retry key, or the restored order is not the live one.');
    }

    /**
     * **R-5 — restore against a line create on ANOTHER order with a revived line's key**: the line create waits at the
     * line key index, then loses with the create path's answer — never a 500.
     */
    #[Test]
    public function aLineCreateRacingARestoreThatRevivesItsKeyIsRefusedNotA500(): void
    {
        $lineKey = Uuid::v4()->toRfc4122();
        $w = $this->workspace('PO-RT19');
        $this->raise($w, [['1', '1.0000']]);
        $a = $this->idFromLocation();
        $item = $this->listedItem($w, 'SKU-PO-RT19-X');
        $this->addLine($a, $item, $lineKey);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->send('DELETE', '/purchase-orders/'.$a);
        $this->raise($w, [['1', '1.0000']]);
        $b = $this->idFromLocation();

        $holder = $this->raceAgainstAHolder(
            [[PurchaseOrderWriter::RESTORE, [$a]]],
            fn () => $this->addLine($b, $item, $lineKey),
            $a,
        );

        self::assertTrue($holder['waiter_seen'], json_encode($holder));
        self::assertTrue($holder['committed'], json_encode($holder));
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM purchase_order_line WHERE inventory_item_id = ?::uuid AND deleted_at IS NULL', [$item]),
            'Two live lines on one retry key, or the revived line is gone.');
    }

    /** **R-6 — restore against a line delete on the same order**: the delete waits on the order lock, then works on a live draft. */
    #[Test]
    public function aLineDeleteWaitsForTheRestoreOfItsOrderAndThenProceeds(): void
    {
        $order = $this->raiseOrder('PO-RT20');
        $line = $this->lineIds($order)[0];
        $this->send('DELETE', '/purchase-orders/'.$order);

        $holder = $this->raceAgainstAHolder(
            [[PurchaseOrderWriter::RESTORE, [$order]]],
            fn () => $this->send('DELETE', '/purchase-orders/'.$order.'/lines/'.$line),
            $order,
        );

        self::assertTrue($holder['waiter_seen'], json_encode($holder));
        self::assertTrue($holder['committed'], json_encode($holder));
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertNull($this->stored($order)['deleted_at']);
    }

    // -----------------------------------------------------------------------

    private function restore(string $order): void
    {
        $this->send('POST', '/purchase-orders/'.$order.'/restoration');
    }

    /** @return array{supplier: string, warehouse: string, item: string} */
    private function workspace(string $tag): array
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme '.$tag);
        $item = $this->seedItem(self::TENANT_A, 'SKU-'.$tag);
        $this->seedSupplierProduct(self::TENANT_A, $supplier, $item);

        return [
            'supplier' => $supplier,
            'warehouse' => $this->seedWarehouse(self::TENANT_A, 'WH '.$tag),
            'item' => $item,
        ];
    }

    /** @param array{supplier: string} $w */
    private function listedItem(array $w, string $sku): string
    {
        $item = $this->seedItem(self::TENANT_A, $sku);
        $this->seedSupplierProduct(self::TENANT_A, $w['supplier'], $item);

        return $item;
    }

    /**
     * @param array{supplier: string, warehouse: string, item: string} $w
     * @param list<array{0: string, 1: string}>                       $lines quantity, unit cost
     */
    private function raise(array $w, array $lines, ?string $key = null): void
    {
        $items = [];
        foreach ($lines as $n => [$quantity, $cost]) {
            $items[] = ['inventory_item_id' => 0 === $n ? $w['item'] : $this->listedItem($w, 'SKU-'.Uuid::v4()->toRfc4122()), 'quantity' => $quantity, 'unit_cost' => $cost];
        }
        $this->send('POST', '/purchase-orders', [
            'supplier_id' => $w['supplier'], 'warehouse_id' => $w['warehouse'],
            'lines' => $items,
        ], [IdempotencyKeyGuard::HEADER => $key ?? Uuid::v4()->toRfc4122()]);
    }

    /** @param list<array{0: string, 1: string}> $lines */
    private function raiseOrder(string $tag, array $lines = [['1', '1.0000']]): string
    {
        $this->raise($this->workspace($tag), $lines);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        return $this->idFromLocation();
    }

    /** @return array{order: string, supplier: string, warehouse: string} */
    private function deletedOrder(string $tag): array
    {
        $w = $this->workspace($tag);
        $this->raise($w, [['1', '1.0000']]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $order = $this->idFromLocation();
        $this->send('DELETE', '/purchase-orders/'.$order);
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        return ['order' => $order, 'supplier' => $w['supplier'], 'warehouse' => $w['warehouse']];
    }

    /**
     * A LEGACY archived draft, seeded as the owner (T-17): the order and every given line stamped with {@see self::LEGACY_STAMP},
     * the state the pre-`3292c4a` cascade left behind. Lines are `[line_number, quantity, unit_cost]`.
     *
     * @param list<array{0: int, 1: string, 2: string}> $lines
     */
    private function legacyOrder(string $tag, array $lines): string
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme '.$tag);
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH '.$tag);
        $order = $this->seedOrder(self::TENANT_A, self::freshNumber('PO'), $supplier, $warehouse);
        foreach ($lines as $n => [$number, $quantity, $cost]) {
            $line = $this->seedLine(self::TENANT_A, $order, $this->seedItem(self::TENANT_A, 'SKU-'.$tag.'-'.$n), $number, $quantity, $cost);
            $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE purchase_order_line SET deleted_at = ?::timestamptz WHERE id = ?::uuid', [self::LEGACY_STAMP, $line]);
        }
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE purchase_order SET deleted_at = ?::timestamptz WHERE id = ?::uuid', [self::LEGACY_STAMP, $order]);

        return $order;
    }

    private function addLine(string $order, string $item, string $key): void
    {
        $this->send('POST', '/purchase-orders/'.$order.'/lines', ['inventory_item_id' => $item, 'quantity' => '2', 'unit_cost' => '3.0000'],
            [IdempotencyKeyGuard::HEADER => $key]);
    }

    private function idFromLocation(): string
    {
        $location = (string) $this->client()->getResponse()->headers->get('Location');
        self::assertNotSame('', $location, (string) $this->client()->getResponse()->getContent());

        return basename($location);
    }

    /** @return list<string> */
    private function lineIds(string $order): array
    {
        return array_map(static fn (array $r): string => (string) $r['id'], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT id FROM purchase_order_line WHERE purchase_order_id = ?::uuid ORDER BY line_number', [$order]));
    }

    /** @return list<string> */
    private function liveLineIds(string $order): array
    {
        return array_map(static fn (array $r): string => (string) $r['id'], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT id FROM purchase_order_line WHERE purchase_order_id = ?::uuid AND deleted_at IS NULL ORDER BY line_number', [$order]));
    }

    /** @return array{stored: string, live: string} */
    private function totals(string $order): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT o.total_amount::text AS stored, coalesce((SELECT sum(l.quantity_ordered * l.unit_cost) FROM purchase_order_line l
                    WHERE l.purchase_order_id = o.id AND l.deleted_at IS NULL), 0)::numeric(19,4)::text AS live
              FROM purchase_order o WHERE o.id = ?::uuid
            SQL, [$order])[0];
    }

    /** @return array<string, mixed> */
    private function stored(string $order): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT deleted_at, status, total_amount::text AS total FROM purchase_order WHERE id = ?::uuid', [$order])[0];
    }

    /** @return list<array<string, mixed>> */
    private function restoredRows(string $order): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT actor_user_id::text AS actor_user_id, subject_type, metadata::text AS metadata, ip_address
              FROM audit_log WHERE event_type = ? AND subject_id = ?::uuid
            SQL, [AuditEvent::PURCHASE_ORDER_RESTORED, $order]);
    }

    private function assertRefusedAndUnchanged(string $order, string $rule, string $detail): void
    {
        $before = $this->stored($order);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($rule, $this->json()['rule']);
        self::assertSame($detail, $this->json()['detail']);
        self::assertNotNull($before['deleted_at'], 'A refused restore changed the order.');
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM purchase_order_line WHERE purchase_order_id = ?::uuid AND deleted_at IS NULL', [$order]),
            'A refused restore revived a line.');
        self::assertSame([], $this->restoredRows($order), 'A refused restore wrote a restored row.');
    }

    /** @param array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string} $holder */
    private function assertWaitedThenRefused(array $holder, string $order, string $rule, string $detail): void
    {
        self::assertTrue($holder['waiter_seen'], 'The restore never waited on the delete: its parent read is not locked. '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        $this->assertRefusedAndUnchanged($order, $rule, $detail);
    }

    /**
     * `SalesOrderRestoreTest::raceAgainstAHolder`'s shape, another copy (not extracted, the earlier copies' reason), with
     * `HOLD_TABLE` `purchase_order` (`PurchaseOrderRowLock`'s `FOR NO KEY UPDATE`).
     *
     * @param list<array{0: string, 1: list<string>}> $before
     *
     * @return array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string}
     */
    private function raceAgainstAHolder(array $before, callable $request, ?string $lockedOrder = null): array
    {
        $readyFile = tempnam(sys_get_temp_dir(), 'erp_purchase_restore_');
        file_put_contents($readyFile, '');

        $proc = proc_open(
            [PHP_BINARY, __DIR__.'/support/hold_order_lock.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [
                'APP_DIRECT_DATABASE_URL' => $_ENV['APP_DIRECT_DATABASE_URL'],
                'HOLD_TENANT' => self::TENANT_A,
                'HOLD_ORDER' => $lockedOrder ?? Uuid::v4()->toRfc4122(),
                'HOLD_TABLE' => 'purchase_order',
                'HOLD_BEFORE' => json_encode($before, \JSON_THROW_ON_ERROR),
                'HOLD_AFTER' => json_encode([], \JSON_THROW_ON_ERROR),
                'HOLD_READY_FILE' => $readyFile,
                'HOLD_DEADLINE_SECONDS' => (string) self::HOLDER_DEADLINE_SECONDS,
                'PATH' => (string) getenv('PATH'),
            ],
        );
        self::assertIsResource($proc);

        try {
            $deadline = microtime(true) + self::HOLDER_DEADLINE_SECONDS;
            while ('' === trim((string) file_get_contents($readyFile))) {
                if (microtime(true) > $deadline) {
                    self::fail('The holder never took the lock: '.stream_get_contents($pipes[2]));
                }
                usleep(1000);
            }
            if ('failed' === trim((string) file_get_contents($readyFile))) {
                self::fail('The holder failed before taking the lock: '.stream_get_contents($pipes[1]).stream_get_contents($pipes[2]));
            }

            $request();

            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
        } finally {
            if (proc_get_status($proc)['running']) {
                proc_terminate($proc);
            }
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($proc);
            @unlink($readyFile);
        }

        $report = json_decode(trim($out), true);
        self::assertIsArray($report, 'The holder printed no report. stdout: '.$out.' stderr: '.$err);

        return $report;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Inventory\Application\StockAdjuster;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **The stock count's release rule — owner Q1 (C′)** (stock count C1, 2026-10-16; `docs/session13/stock-adjustment-ruling.md`;
 * `RESIDUAL_GAPS.md` G-44). Releasing held stock is the owner's alone (Q-W1 (d)), so a count may not do it by another name:
 * - counting a `quarantine` bin DOWN (writing held goods off) is owner-only — `stock_level.count_down_needs_owner`;
 * - counting an item UP in a non-quarantine bin, while that item has stock in ANY live quarantine location of the
 *   workspace, is owner-only — `stock_level.held_item_needs_owner` (C′: anywhere, so a second warehouse is no way round);
 * - counting a quarantine bin up is a hold and free; every other count is open to `stock_movement.adjust` holders.
 *
 * Owner-only is the marker (`OwnerPredicate::holdsMarker`), unconditional for every non-owner (`SEC` S-2's shape). Codes
 * `SEC` (`stock-adjustment-sec.md` §5), sentences `UX` S-7 / S-8. The races are `PurchaseOrderRowLockTest`'s R-2′ / R-2″.
 */
final class StockAdjustmentReleaseTest extends PurchaseOrderTestCase
{
    /** @return iterable<string, array{0: string, 1: string}> */
    public static function nonOwnersCountingQuarantineDown(): iterable
    {
        foreach ([DefaultRoles::WAREHOUSE_OPERATOR, DefaultRoles::ADMIN] as $role) {
            yield $role.', partly' => [$role, '4'];
            yield $role.', to zero' => [$role, '0'];
        }
    }

    /** Mutation M3 (drop the count-down gate) → red. */
    #[Test]
    #[DataProvider('nonOwnersCountingQuarantineDown')]
    public function aNonOwnerCannotCountDownAQuarantineBin(string $role, string $counted): void
    {
        $w = $this->warehouse();
        $q = $this->bin($w, 'Q-01', 'quarantine');
        $item = $this->item();
        $this->level($item, $q, '10', 2);
        $this->actAsRole($role);

        $this->recount($item, $q, $counted, 2);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('stock_level.count_down_needs_owner', $this->json()['rule']);
        self::assertSame('This is a quarantine location, so only an owner can count its stock down. Counting down here writes off held stock.', $this->json()['detail']);
        self::assertSame(StockAdjuster::COUNT_DOWN_NEEDS_OWNER, $this->json()['detail']);
        self::assertSame(['10.000000', 2], $this->onHand($item, $q));
    }

    #[Test]
    public function theOwnerCountsDownAQuarantineBin(): void
    {
        $w = $this->warehouse();
        $q = $this->bin($w, 'Q-01', 'quarantine');
        $item = $this->item();
        $this->level($item, $q, '10', 2);
        $this->actAsRole(DefaultRoles::OWNER);

        $this->recount($item, $q, '0', 2);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['0.000000', 3], $this->onHand($item, $q));
    }

    /** A hold: counting a quarantine bin UP takes nothing out of quarantine, so it is free. */
    #[Test]
    public function aNonOwnerCanCountUpAQuarantineBin(): void
    {
        $w = $this->warehouse();
        $q = $this->bin($w, 'Q-01', 'quarantine');
        $item = $this->item();
        $this->level($item, $q, '10', 2);
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $this->recount($item, $q, '12', 2);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['12.000000', 3], $this->onHand($item, $q));
    }

    /** Mutation M4 (drop the held-item gate) → red. */
    #[Test]
    public function aNonOwnerCannotCountUpAnItemHeldInTheSameWarehouse(): void
    {
        $w = $this->warehouse();
        $q = $this->bin($w, 'Q-01', 'quarantine');
        $b = $this->bin($w, 'A-01');
        $item = $this->item();
        $this->level($item, $q, '3', 1);
        $this->level($item, $b, '5', 1);
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $this->recount($item, $b, '8', 1);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('stock_level.held_item_needs_owner', $this->json()['rule']);
        self::assertSame('Some of this item is in quarantine, in this warehouse or another, so only an owner can count it up. It can be counted up by anyone once none of it is held.', $this->json()['detail']);
        self::assertSame(['5.000000', 1], $this->onHand($item, $b));
    }

    /**
     * **(C′), not (C):** held stock in ANOTHER warehouse still gates the count — otherwise anyone who may count makes a held
     * item sellable by counting it up one building over (final RED H-2 of the first round). Mutation M4b (restrict the held
     * read to the counted bin's warehouse) → red. A never-stocked bin is gated the same way.
     */
    #[Test]
    public function aNonOwnerCannotCountUpWhenTheHeldStockIsInAnotherWarehouse(): void
    {
        $here = $this->warehouse();
        $there = $this->warehouse();
        $q = $this->bin($there, 'Q-01', 'quarantine');
        $b = $this->bin($here, 'A-01');
        $empty = $this->bin($here, 'A-02');
        $item = $this->item();
        $this->level($item, $q, '3', 1);
        $this->level($item, $b, '5', 1);
        $this->actAsRole(DefaultRoles::ADMIN);

        $this->recount($item, $b, '6', 1);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('stock_level.held_item_needs_owner', $this->json()['rule']);

        $this->recount($item, $empty, '1', null);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('stock_level.held_item_needs_owner', $this->json()['rule']);
    }

    /** Nothing held — including held stock counted to zero, and an archived quarantine location — leaves the count open. */
    #[Test]
    public function aNonOwnerCanCountUpWhenNothingIsHeld(): void
    {
        $w = $this->warehouse();
        $q = $this->bin($w, 'Q-01', 'quarantine');
        $gone = $this->bin($w, 'Q-02', 'quarantine');
        $b = $this->bin($w, 'A-01');
        $item = $this->item();
        $this->level($item, $q, '0', 4);
        $this->level($item, $gone, '7', 1);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE storage_location SET deleted_at = now() WHERE id = ?::uuid', [$gone]);
        $this->level($item, $b, '5', 1);
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $this->recount($item, $b, '9', 1);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['9.000000', 2], $this->onHand($item, $b));
    }

    #[Test]
    public function theOwnerCountsUpAHeldItem(): void
    {
        $w = $this->warehouse();
        $q = $this->bin($w, 'Q-01', 'quarantine');
        $b = $this->bin($w, 'A-01');
        $item = $this->item();
        $this->level($item, $q, '3', 1);
        $this->level($item, $b, '5', 1);
        $this->actAsRole(DefaultRoles::OWNER);

        $this->recount($item, $b, '8', 1);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /** Routine shrinkage: counting a pickable bin down is open, held stock elsewhere or not (N-1: the trail is the control). */
    #[Test]
    public function aNonOwnerCountsDownAPickableBin(): void
    {
        $w = $this->warehouse();
        $q = $this->bin($w, 'Q-01', 'quarantine');
        $b = $this->bin($w, 'A-01');
        $item = $this->item();
        $this->level($item, $q, '3', 1);
        $this->level($item, $b, '5', 1);
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $this->recount($item, $b, '2', 1);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['2.000000', 2], $this->onHand($item, $b));
    }

    /**
     * **Final RED H-1, the wrong refusal.** The counter saw quarantine bin Q at 5 (version 3) and counted 7 — a hold. A
     * receipt of 5 into Q then posted (10, version 4). Against the CURRENT figure the count reads as a write-off of 3,
     * which a non-owner may not do — a refusal that makes no sense to someone who counted up, and that could lead the
     * owner into writing off good units. The version is checked FIRST, so the answer is `stale-record`, with `current`
     * (every adjust holder holds `stock_level.view` — `SEC` SA-1 — and `ListStockLevels` serves quarantine bins, G-47).
     * **Mutation M26** (release rule before the version) → red.
     */
    #[Test]
    public function aStaleCountIsRefusedStaleBeforeTheReleaseRule(): void
    {
        $w = $this->warehouse();
        $q = $this->bin($w, 'Q-01', 'quarantine');
        $item = $this->item();
        $this->level($item, $q, '10', 4);
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $this->recount($item, $q, '7', 3);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('urn:erp:problem:stale-record', $this->json()['type']);
        self::assertSame(['qty_on_hand' => '10.000000', 'version' => 4], $this->json()['current']);
    }

    private function warehouse(): string
    {
        return $this->seedWarehouse(self::TENANT_A, 'WH '.Uuid::v4()->toRfc4122());
    }

    private function bin(string $warehouse, string $code, string $kind = 'bin'): string
    {
        return $this->seedStorageLocation(self::TENANT_A, $warehouse, $code, $kind);
    }

    private function item(): string
    {
        return $this->seedItem(self::TENANT_A, 'REL-'.strtoupper(substr(Uuid::v4()->toRfc4122(), 0, 8)));
    }

    private function level(string $item, string $bin, string $onHand, int $version): void
    {
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand, version) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric, ?)',
            [Uuid::v4()->toRfc4122(), self::TENANT_A, $item, $bin, $onHand, $version]);
    }

    private function actAsRole(string $role): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor($role));
    }

    private function recount(string $item, string $bin, string $counted, ?int $version): void
    {
        $this->send('POST', '/stock-movements/adjustments', [
            'inventory_item_id' => $item, 'storage_location_id' => $bin, 'counted_quantity' => $counted, 'version' => $version, 'note' => 'Recounted',
        ], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
    }

    /** @return array{0: string, 1: int} */
    private function onHand(string $item, string $bin): array
    {
        $row = $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT qty_on_hand::text AS q, version FROM stock_level WHERE inventory_item_id = ?::uuid AND storage_location_id = ?::uuid', [$item, $bin]);

        return [(string) $row[0]['q'], (int) $row[0]['version']];
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Inventory\Application\StockTransferrer;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **The transfer's release rule — owner Q-T2** (stock transfer C1, 2026-10-20; `docs/session13/s35-ruling.md`;
 * `RESIDUAL_GAPS.md` G-44; `SEC` `stock-transfer-sec.md` §7). Releasing held stock is the owner's alone (Q-W1 (d)), so a
 * transfer may not do it by another name:
 * - moving stock out of a `quarantine` bin into a bin of any other kind is owner-only — `stock_level.release_needs_owner`,
 *   unconditional for every non-owner (`OwnerPredicate::holdsMarker`);
 * - moving stock INTO quarantine is a hold and free; quarantine → quarantine is open (owner Q-T2); and a held item moved
 *   between two pickable bins is open (net zero per item: nothing becomes sellable).
 *
 * The rule is decided on the two bins' kinds alone, before any level is read, so its refusal discloses no figure (`SEC` §3).
 * `WriteOwnershipTest::STOCK_LEVEL_RELEASE_DECISIONS` names test 11 as this writer's pin (G-44, `SEC` stock-transfer-sec-2.md §2).
 * Sentences are PLACEHOLDERS for `UX`.
 */
final class StockTransferReleaseTest extends PurchaseOrderTestCase
{
    /** @return iterable<string, array{0: string}> */
    public static function nonOwnersWithAdjust(): iterable
    {
        foreach ([DefaultRoles::WAREHOUSE_OPERATOR, DefaultRoles::ADMIN] as $role) {
            yield $role => [$role];
        }
    }

    /** Test 11. Mutation M-R1 (drop the release rule) → red. */
    #[Test]
    #[DataProvider('nonOwnersWithAdjust')]
    public function aNonOwnerCannotMoveStockOutOfQuarantineIntoAPickableBin(string $role): void
    {
        $w = $this->warehouse();
        $q = $this->bin($w, 'Q-01', 'quarantine');
        $pick = $this->bin($w, 'A-01');
        $item = $this->item();
        $this->level($item, $q, '10');
        $this->actAsRole($role);

        $this->transfer($item, $q, $pick, '4');

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('stock_level.release_needs_owner', $this->json()['rule']);
        self::assertSame('This stock is in a quarantine location, so only an owner can move it to a location that isn\'t quarantine. Moving it there releases held stock. Nothing was moved.', $this->json()['detail']);
        self::assertSame(StockTransferrer::RELEASE_NEEDS_OWNER, $this->json()['detail']);
        self::assertSame('10.000000', $this->onHand($item, $q));
        self::assertNull($this->onHand($item, $pick));
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM stock_movement WHERE inventory_item_id = ?::uuid', [$item]));
    }

    /** Test 12: the owner releases by transfer — into a pickable bin of another warehouse, even. */
    #[Test]
    public function theOwnerMovesStockOutOfQuarantine(): void
    {
        $q = $this->bin($this->warehouse(), 'Q-01', 'quarantine');
        $pick = $this->bin($this->warehouse(), 'A-01');
        $item = $this->item();
        $this->level($item, $q, '10');
        $this->actAsRole(DefaultRoles::OWNER);

        $this->transfer($item, $q, $pick, '4');

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['6.000000', '4.000000'], [$this->onHand($item, $q), $this->onHand($item, $pick)]);
    }

    /** Test 13: a hold — moving stock INTO quarantine — is never gated. Mutation M-R1's twin: gate on the destination → red. */
    #[Test]
    public function aNonOwnerMovesStockIntoQuarantine(): void
    {
        $w = $this->warehouse();
        $q = $this->bin($w, 'Q-01', 'quarantine');
        $pick = $this->bin($w, 'A-01');
        $item = $this->item();
        $this->level($item, $pick, '10');
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $this->transfer($item, $pick, $q, '4');

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['6.000000', '4.000000'], [$this->onHand($item, $pick), $this->onHand($item, $q)]);
    }

    /**
     * Test 13: owner Q-T2 — quarantine → quarantine is open to `stock_movement.adjust` holders, across warehouses too
     * (owner Q-T1). The item's total in live quarantine bins is unchanged. Mutation M-R2 (gate Q→Q) → red.
     */
    #[Test]
    public function aNonOwnerMovesStockBetweenTwoQuarantineBins(): void
    {
        $q1 = $this->bin($this->warehouse(), 'Q-01', 'quarantine');
        $q2 = $this->bin($this->warehouse(), 'Q-02', 'quarantine');
        $item = $this->item();
        $this->level($item, $q1, '10');
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $this->transfer($item, $q1, $q2, '10');

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['0.000000', '10.000000'], [$this->onHand($item, $q1), $this->onHand($item, $q2)]);
    }

    /**
     * Test 13: a held item (stock in a quarantine bin) moved between two PICKABLE bins is open — net zero per item, nothing
     * becomes sellable, unlike the count's (C′) count-up. Mutation M-R3 (gate a held item's pickable → pickable) → red.
     */
    #[Test]
    public function aNonOwnerMovesAHeldItemBetweenTwoPickableBins(): void
    {
        $w = $this->warehouse();
        $q = $this->bin($w, 'Q-01', 'quarantine');
        $a = $this->bin($w, 'A-01');
        $b = $this->bin($w, 'A-02', 'shelf');
        $item = $this->item();
        $this->level($item, $q, '5');
        $this->level($item, $a, '10');
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $this->transfer($item, $a, $b, '3');

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['5.000000', '7.000000', '3.000000'], [$this->onHand($item, $q), $this->onHand($item, $a), $this->onHand($item, $b)]);
    }

    /**
     * Test 14 (`SEC` §3): the release refusal is decided on kinds alone, BEFORE any level is read, so it discloses no
     * figure — here the source is also short, and the answer is still the release refusal, with no number in the body.
     */
    #[Test]
    public function aRefusedReleaseDisclosesNoFigure(): void
    {
        $w = $this->warehouse();
        $q = $this->bin($w, 'Q-01', 'quarantine');
        $pick = $this->bin($w, 'A-01');
        $item = $this->item();
        $this->level($item, $q, '7.25');
        $this->level($item, $pick, '3.5');
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $this->transfer($item, $q, $pick, '9');

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('stock_level.release_needs_owner', $this->json()['rule']);
        $body = (string) $this->client()->getResponse()->getContent();
        foreach (['7.25', '7,25', '3.5', '3,5', 'qty_on_hand', 'on_hand'] as $figure) {
            self::assertStringNotContainsString($figure, $body, 'The release refusal disclosed `'.$figure.'`.');
        }
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
        return $this->seedItem(self::TENANT_A, 'XREL-'.strtoupper(substr(Uuid::v4()->toRfc4122(), 0, 8)));
    }

    private function level(string $item, string $bin, string $onHand): void
    {
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric)',
            [Uuid::v4()->toRfc4122(), self::TENANT_A, $item, $bin, $onHand]);
    }

    private function actAsRole(string $role): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor($role));
    }

    private function transfer(string $item, string $from, string $to, string $quantity): void
    {
        $this->send('POST', '/stock-movements/transfers', [
            'inventory_item_id' => $item, 'source_storage_location_id' => $from, 'destination_storage_location_id' => $to, 'quantity' => $quantity,
        ], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
    }

    private function onHand(string $item, string $bin): ?string
    {
        $row = $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT qty_on_hand::text AS q FROM stock_level WHERE inventory_item_id = ?::uuid AND storage_location_id = ?::uuid', [$item, $bin]);

        return [] === $row ? null : (string) $row[0]['q'];
    }
}

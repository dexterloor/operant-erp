<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **The stock count's retry key on `stock_movement` — stock count C0** (`Version20261016000100`; design §3 + Revision 1
 * R1.3 / R1.5). Every row here is written **as the owner**: the subject is the constraint, and constraints bind every
 * role. `GrantFootprintTest` holds the grants (none added) against the manifest.
 *
 * - Both columns are nullable — rows the receipt, shipment and return writers post carry neither — and, when set, 64
 *   lower-case hex (the hash of the header, D-B, never the header).
 * - **Both or neither** (`stock_movement_key_both_or_neither`, design R1.3 M-C0c: "add one" is required — dropping the
 *   CHECK must redden something, and before this test nothing named it).
 * - The unique on `(tenant_id, idempotency_key)` is TOTAL (class `none`, no `deleted_at`) and per workspace; NULL keys
 *   never collide, so any number of keyless movements coexist.
 */
final class StockMovementRetryKeySchemaTest extends TenancyTestCase
{
    private const MOVEMENT = <<<'SQL'
        INSERT INTO stock_movement (id, tenant_id, inventory_item_id, storage_location_id, quantity, reason, idempotency_key, request_digest)
        VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 1, 'adjustment', ?, ?)
        SQL;

    #[Test]
    public function aMovementsRetryKeyIsOptionalHexPairedAndUniquePerWorkspace(): void
    {
        [$itemA, $binA] = $this->seedItemAndBin(self::TENANT_A);
        [$itemB, $binB] = $this->seedItemAndBin(self::TENANT_B);
        $key = hash('sha256', 'one key');
        $digest = hash('sha256', 'one digest');

        self::assertSame('accepted', $this->attemptMovement(self::TENANT_A, $itemA, $binA, null, null), 'The control: a keyless movement, as every shipped writer posts.');
        self::assertSame('accepted', $this->attemptMovement(self::TENANT_A, $itemA, $binA, null, null), 'Two keyless movements collided: NULL keys must never collide.');

        foreach (['key' => [strtoupper($key), $digest], 'digest' => [$key, strtoupper($digest)]] as $which => [$k, $d]) {
            self::assertSame('23514', $this->attemptMovement(self::TENANT_A, $itemA, $binA, $k, $d), $which.' accepted upper-case hex.');
        }
        self::assertSame('23514', $this->attemptMovement(self::TENANT_A, $itemA, $binA, substr($key, 1), $digest), 'The key accepted 63 characters.');
        self::assertSame('23514', $this->attemptMovement(self::TENANT_A, $itemA, $binA, $key, 'abc'), 'The digest accepted "abc".');

        self::assertSame('23514', $this->attemptMovement(self::TENANT_A, $itemA, $binA, $key, null), 'A key without its digest was accepted.');
        self::assertSame('23514', $this->attemptMovement(self::TENANT_A, $itemA, $binA, null, $digest), 'A digest without its key was accepted.');

        self::assertSame('accepted', $this->attemptMovement(self::TENANT_A, $itemA, $binA, $key, $digest), 'The control: a keyed movement.');
        // `attemptAsOwnerForTenant` rolls each attempt back; the held key is laid for real.
        $this->insertAsOwnerForTenant(self::TENANT_A, self::MOVEMENT, [Uuid::v4()->toRfc4122(), self::TENANT_A, $itemA, $binA, $key, $digest]);
        self::assertSame('23505', $this->attemptMovement(self::TENANT_A, $itemA, $binA, $key, hash('sha256', 'another digest')),
            'A second movement took a key already held in this workspace.');
        self::assertSame('accepted', $this->attemptMovement(self::TENANT_B, $itemB, $binB, $key, $digest),
            'The control: another workspace may hold the same key hash.');

        $constraints = $this->owner()->fetchAllKeyValue(<<<'SQL'
            SELECT conname, pg_get_constraintdef(oid) FROM pg_constraint
            WHERE conrelid = 'stock_movement'::regclass
              AND conname IN ('stock_movement_key_both_or_neither', 'stock_movement_idem_unique')
            ORDER BY conname
            SQL);
        self::assertSame([
            'stock_movement_idem_unique' => 'UNIQUE (tenant_id, idempotency_key)',
            'stock_movement_key_both_or_neither' => 'CHECK (((idempotency_key IS NULL) = (request_digest IS NULL)))',
        ], $constraints, 'The unique must be TOTAL (no WHERE: stock_movement has no deleted_at), and the both-or-neither CHECK must exist by name.');
    }

    /** @return array{string, string} item id, bin id */
    private function seedItemAndBin(string $tenantId): array
    {
        $item = $this->seedItem($tenantId, 'SKU-RK-'.substr(Uuid::v4()->toRfc4122(), 0, 8));
        $warehouse = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant($tenantId, "INSERT INTO warehouse (id, tenant_id, code, name, country_code) VALUES (?::uuid, ?::uuid, ?, ?, 'DE')",
            [$warehouse, $tenantId, 'WH-'.substr($warehouse, 0, 6), 'Retry key warehouse']);
        $bin = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant($tenantId, 'INSERT INTO storage_location (id, tenant_id, warehouse_id, code) VALUES (?::uuid, ?::uuid, ?::uuid, ?)',
            [$bin, $tenantId, $warehouse, 'A-01']);

        return [$item, $bin];
    }

    private function attemptMovement(string $tenantId, string $item, string $bin, ?string $key, ?string $digest): string
    {
        return $this->attemptAsOwnerForTenant($tenantId, self::MOVEMENT, [Uuid::v4()->toRfc4122(), $tenantId, $item, $bin, $key, $digest]);
    }
}

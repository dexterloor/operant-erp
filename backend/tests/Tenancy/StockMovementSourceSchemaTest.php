<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`stock_movement`'s two source CHECKs — stock transfer C0** (`Version20261019000800`; `docs/session13/stock-transfer-design.md`
 * Revision 2 §C0, `stock-transfer-data.md` §3.3). Every row here is written **as the owner**: the subject is the constraint, and
 * constraints bind every role.
 *
 * - `stock_movement_source_paired`: `source_type` and `source_id` both set or both NULL.
 * - `stock_movement_transfer_source`: `source_type = 'stock_movement'` exactly when the reason is `transfer_out`/`transfer_in`.
 *
 * The definitions are pinned to the **server-normalised** text, copied from the first read-back (the
 * `CreditedSegmentLapseTest` precedent) — note Postgres rewrites `IS NOT DISTINCT FROM` as `NOT (… IS DISTINCT FROM …)`, which
 * `DATA`'s inferred form did not predict. An unvalidated constraint's definition carries a ` NOT VALID` suffix, so omitting a
 * `VALIDATE` reddens both `convalidated` and the definition. **A definition-only test cannot tell `=` from `<>` once the string
 * is copied from a read-back of a mutated constraint** (DATA §3.3), so the behavioural cases below carry the meaning.
 */
final class StockMovementSourceSchemaTest extends TenancyTestCase
{
    private const SOURCE_PAIRED_DEFINITION = 'CHECK (((source_type IS NULL) = (source_id IS NULL)))';

    private const TRANSFER_SOURCE_DEFINITION = "CHECK (((NOT (source_type IS DISTINCT FROM 'stock_movement'::text)) = (reason = ANY (ARRAY['transfer_out'::text, 'transfer_in'::text]))))";

    private const MOVEMENT = <<<'SQL'
        INSERT INTO stock_movement (id, tenant_id, inventory_item_id, storage_location_id, quantity, reason, source_type, source_id)
        VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 1, ?, ?, ?::uuid)
        SQL;

    /** Both legs in one statement, each naming the other — the shape C1's writer posts. */
    private const LINKED_PAIR = <<<'SQL'
        INSERT INTO stock_movement (id, tenant_id, inventory_item_id, storage_location_id, quantity, reason, source_type, source_id)
        VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, -1, 'transfer_out', 'stock_movement', ?::uuid),
               (?::uuid, ?::uuid, ?::uuid, ?::uuid, 1, 'transfer_in', 'stock_movement', ?::uuid)
        SQL;

    #[Test]
    public function bothSourceChecksExistAreValidatedAndReadAsDecided(): void
    {
        $constraints = $this->owner()->fetchAllAssociative(<<<'SQL'
            SELECT conname, convalidated, pg_get_constraintdef(oid) AS def FROM pg_constraint
            WHERE conrelid = 'stock_movement'::regclass AND contype = 'c'
              AND conname IN ('stock_movement_source_paired', 'stock_movement_transfer_source')
            ORDER BY conname
            SQL);

        self::assertSame([
            ['conname' => 'stock_movement_source_paired', 'convalidated' => true, 'def' => self::SOURCE_PAIRED_DEFINITION],
            ['conname' => 'stock_movement_transfer_source', 'convalidated' => true, 'def' => self::TRANSFER_SOURCE_DEFINITION],
        ], $constraints, 'Both source CHECKs must exist by name, be VALIDATEd (laid NOT VALID, then validated), and read as the server-normalised definitions pinned here.');
    }

    #[Test]
    public function aMovementsSourceIsPairedAndOnlyATransferLegNamesAMovement(): void
    {
        $item = $this->seedItem(self::TENANT_A, 'SKU-SRC-'.substr(Uuid::v4()->toRfc4122(), 0, 8));
        $warehouse = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, "INSERT INTO warehouse (id, tenant_id, code, name, country_code) VALUES (?::uuid, ?::uuid, ?, ?, 'DE')",
            [$warehouse, self::TENANT_A, 'WH-'.substr($warehouse, 0, 6), 'Source check warehouse']);
        $bin = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO storage_location (id, tenant_id, warehouse_id, code) VALUES (?::uuid, ?::uuid, ?::uuid, ?)',
            [$bin, self::TENANT_A, $warehouse, 'S-01']);
        $someId = Uuid::v4()->toRfc4122();

        $attempt = fn (string $reason, ?string $type, ?string $id): string => $this->attemptAsOwnerForTenant(
            self::TENANT_A, self::MOVEMENT, [Uuid::v4()->toRfc4122(), self::TENANT_A, $item, $bin, $reason, $type, $id]);

        // Accepted: what the shipped writers and fixtures post.
        self::assertSame('accepted', $attempt('adjustment', null, null), 'The control: a count posts no source (StockAdjuster).');
        self::assertSame('accepted', $attempt('receipt', null, null), 'The control: fixtures post a receipt with no source.');
        self::assertSame('accepted', $attempt('receipt', 'goods_receipt', $someId), 'The control: a receipt names its goods receipt (StockReceiver).');
        $out = Uuid::v4()->toRfc4122();
        $in = Uuid::v4()->toRfc4122();
        self::assertSame('accepted', $this->attemptAsOwnerForTenant(self::TENANT_A, self::LINKED_PAIR,
            [$out, self::TENANT_A, $item, $bin, $in, $in, self::TENANT_A, $item, $bin, $out]),
            'A linked transfer pair (each leg names the other as a stock_movement) was refused.');

        // Refused by stock_movement_transfer_source.
        self::assertSame('23514', $attempt('transfer_out', null, null),
            'A transfer leg with no source was accepted: with = instead of IS NOT DISTINCT FROM a NULL source_type makes the CHECK NULL, which passes.');
        self::assertSame('23514', $attempt('adjustment', 'stock_movement', $someId),
            'An adjustment named a movement: only a transfer leg may carry source_type stock_movement.');
        // Refused by stock_movement_source_paired.
        self::assertSame('23514', $attempt('receipt', 'goods_receipt', null), 'A source type without its id was accepted.');
        self::assertSame('23514', $attempt('receipt', null, $someId), 'A source id without its type was accepted.');
    }
}

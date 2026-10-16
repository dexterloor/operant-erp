<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Application\Contract\NoCallerTransaction;
use App\Modules\Inventory\Application\Contract\ReturnStock;
use App\Modules\Inventory\Application\Contract\StockReturn;
use App\Modules\Inventory\Application\Contract\StockReturnLine;
use App\Modules\Inventory\Application\Contract\StockReturnRefused;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The one implementation of `ReturnStock` (sales step 7 R2). The contract is on the interface.
 *
 * **`StockReceiver`'s statements, with this writer's own literals** — `reason = 'return'`,
 * `source_type = 'sales_return'` — because E-26 binds a reason to ONE writer class: a shared helper taking the reason
 * as a parameter would make the binding a runtime value no scan can see (design §7: "provided each class keeps its own
 * reason literal"). Duplicated deliberately rather than extracted, for that reason.
 *
 * Every statement goes through the entity manager's own connection (A-10).
 */
final readonly class StockReturner implements ReturnStock
{
    /** The largest value numeric(19,6) holds: 13 digits before the point, 6 after. */
    private const LARGEST_QUANTITY = '9999999999999.999999';

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private EntityManagerInterface $em,
    ) {
    }

    public function returnStock(StockReturn $return): void
    {
        // W-2. `run()` below joins a context the caller opened, and refuses a
        // transaction something else opened — but with NOTHING open it would
        // open its own and commit it. That one case is refused here.
        if (0 === $this->em->getConnection()->getTransactionNestingLevel()) {
            throw NoCallerTransaction::for(ReturnStock::class);
        }

        $tenantId = $this->tenant->tenantId();

        $this->tenants->run($tenantId, function (EntityManagerInterface $em) use ($return, $tenantId): void {
            $connection = $em->getConnection();

            // O-6's key, `(source_type, source_id)`: a repeat inside one transaction is a caller bug.
            $posted = $connection->fetchOne(
                "SELECT 1 FROM stock_movement WHERE source_type = 'sales_return' AND source_id = ?::uuid LIMIT 1",
                [$return->salesReturnId],
            );
            if (false !== $posted) {
                throw StockReturnRefused::salesReturnAlreadyPosted($return->salesReturnId);
            }

            // The shipping warehouse, closed since, takes nothing back (owner ruling Q-M): its bins are not archived
            // with it, so the bin check alone would not see it.
            $archived = $connection->fetchOne(
                'SELECT 1 FROM warehouse WHERE id = ?::uuid AND deleted_at IS NOT NULL',
                [$return->warehouseId],
            );
            if (false !== $archived) {
                throw StockReturnRefused::warehouseIsArchived($return->warehouseId);
            }

            $this->refuseBinsOutsideTheWarehouse($em, $return);
            $deltas = $this->summedLevelDeltas($return);

            foreach ($return->lines as $line) {
                $connection->executeStatement(
                    <<<'SQL'
                    INSERT INTO stock_movement
                        (id, tenant_id, inventory_item_id, storage_location_id, quantity, reason, source_type, source_id, occurred_at)
                    VALUES
                        (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric, 'return', 'sales_return', ?::uuid, ?::timestamptz)
                    SQL,
                    [
                        Uuid::v4()->toRfc4122(),
                        $tenantId,
                        $line->inventoryItemId,
                        $line->storageLocationId,
                        $line->quantity,
                        $return->salesReturnId,
                        $return->occurredAt->format(\DATE_ATOM),
                    ],
                );
            }

            // W-5: `stock_level` LAST, after every movement, in ONE order -- (inventory_item_id,
            // storage_location_id) ascending -- whatever order the caller's lines came in. `StockReceiver`'s order.
            foreach ($deltas as $key => $quantity) {
                [$itemId, $locationId] = explode("\0", (string) $key);

                // The level is RAISED only while the sum still fits numeric(19,6). The
                // comparison is evaluated on the locked, current row, in unconstrained
                // numeric, so it cannot itself overflow and no concurrent return can slip
                // between a check and the write. Without it an existing level pushed past
                // 13 integer digits failed with 22003 — an untranslated 500 (shipped
                // defect, fixed 2026-10-16 with the stock count, which makes such a level
                // one request away). 0 rows = refused; no SQL error was raised, so the
                // caller's transaction is alive and its rollback clean (StockDepleter's
                // conditional-write precedent).
                $changed = $connection->executeStatement(
                    <<<'SQL'
                    INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand)
                    VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric)
                    ON CONFLICT (tenant_id, inventory_item_id, storage_location_id) DO UPDATE
                        SET qty_on_hand = stock_level.qty_on_hand + EXCLUDED.qty_on_hand,
                            version     = stock_level.version + 1,
                            updated_at  = now()
                        WHERE stock_level.qty_on_hand + EXCLUDED.qty_on_hand <= ?::numeric
                    SQL,
                    [Uuid::v4()->toRfc4122(), $tenantId, $itemId, $locationId, $quantity, self::LARGEST_QUANTITY],
                );

                if (1 !== $changed) {
                    throw StockReturnRefused::returnedQuantityWouldOverfillTheLevel(self::firstLineOf($return->lines, $itemId, $locationId), $itemId, $locationId);
                }
            }
        });
    }

    /**
     * The per-(item, bin) sums, in W-5's order, computed BEFORE any write; a sum past numeric(19,6) is refused here
     * rather than as a 22003 after the movements (`StockReceiver::summedLevelDeltas()`'s reasoning).
     *
     * @return array<string, string> "item\0bin" => summed quantity, ascending by key
     */
    private function summedLevelDeltas(StockReturn $return): array
    {
        $deltas = [];
        foreach ($return->lines as $index => $line) {
            $key = $line->inventoryItemId."\0".$line->storageLocationId;
            $deltas[$key] = bcadd($deltas[$key] ?? '0', $line->quantity, 6);

            if (1 === bccomp($deltas[$key], self::LARGEST_QUANTITY, 6)) {
                throw StockReturnRefused::returnedQuantityDoesNotFit($index, $line->inventoryItemId, $line->storageLocationId);
            }
        }
        ksort($deltas, \SORT_STRING);

        return $deltas;
    }

    /**
     * "This bin is a live bin of the SHIPPING warehouse" (owner ruling Q-M). Quarantine bins are live bins and are
     * accepted: a return is not a pick. Read before any write.
     */
    private function refuseBinsOutsideTheWarehouse(EntityManagerInterface $em, StockReturn $return): void
    {
        $asked = array_values(array_unique(array_map(
            static fn (StockReturnLine $line): string => $line->storageLocationId,
            $return->lines,
        )));

        $live = $em->getConnection()->fetchFirstColumn(
            'SELECT id::text FROM storage_location WHERE warehouse_id = ?::uuid AND deleted_at IS NULL AND id IN (?)',
            [$return->warehouseId, $asked],
            [ParameterType::STRING, ArrayParameterType::STRING],
        );

        foreach ($return->lines as $index => $line) {
            // Both sides canonical: `id::text` is Postgres's canonical form and
            // `StockReturnLine` holds its ids in the same one.
            if (!\in_array($line->storageLocationId, $live, true)) {
                throw StockReturnRefused::binIsNotALiveBinOfTheReturningWarehouse($index, $line->storageLocationId, $return->warehouseId);
            }
        }
    }

    /**
     * The index of the first line booking this item into this bin — the line a
     * level refusal names (several lines for one pair are one write).
     *
     * @param array<int, StockReturnLine> $lines
     */
    private static function firstLineOf(array $lines, string $itemId, string $locationId): int
    {
        foreach ($lines as $index => $line) {
            if ($line->inventoryItemId === $itemId && $line->storageLocationId === $locationId) {
                return $index;
            }
        }

        throw new \LogicException('A level was written for an (item, bin) pair no line names.');
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Application\Contract\NoCallerTransaction;
use App\Modules\Inventory\Application\Contract\ReceiveStock;
use App\Modules\Inventory\Application\Contract\StockReceipt;
use App\Modules\Inventory\Application\Contract\StockReceiptLine;
use App\Modules\Inventory\Application\Contract\StockReceiptRefused;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The one implementation of `ReceiveStock`. The contract is on the interface.
 *
 * ---------------------------------------------------------------------------
 * SQL, not the `StockLevel` / `StockMovement` entities
 * ---------------------------------------------------------------------------
 * W-5 is a property of STATEMENT ORDER, and a flush does not promise one: the
 * unit of work orders its writes by its own rules, not by the lock order the
 * rule needs. So the statements are issued here, in the order they must run.
 *
 * The level write is an atomic `qty_on_hand + x` in one upsert rather than
 * read-compute-write, so no lost update is possible between two receipts. It
 * still moves `version`, which is what makes an ORM writer holding an older
 * `StockLevel` fail its optimistic-lock check instead of overwriting this.
 *
 * Every statement goes through the entity manager's own connection — the one
 * `TenantContext` opened the caller's transaction on. A-10 keeps
 * `Doctrine\DBAL\Connection` out of this class's signatures.
 */
final readonly class StockReceiver implements ReceiveStock
{
    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private EntityManagerInterface $em,
    ) {
    }

    public function receive(StockReceipt $receipt): void
    {
        // W-2. `run()` below joins a context the caller opened, and refuses a
        // transaction something else opened — but with NOTHING open it would
        // open its own and commit it. That one case is refused here.
        if (0 === $this->em->getConnection()->getTransactionNestingLevel()) {
            throw NoCallerTransaction::for(ReceiveStock::class);
        }

        $tenantId = $this->tenant->tenantId();

        $this->tenants->run($tenantId, function (EntityManagerInterface $em) use ($receipt, $tenantId): void {
            $connection = $em->getConnection();

            // O-6: `(source_type, source_id)` is the "did this goods receipt
            // already post?" key, answered by `stock_movement_tenant_source_idx`.
            // A repeat would double the stock; inside one synchronous
            // transaction it can only be a caller bug, so it is refused loudly.
            $posted = $connection->fetchOne(
                "SELECT 1 FROM stock_movement WHERE source_type = 'goods_receipt' AND source_id = ?::uuid LIMIT 1",
                [$receipt->goodsReceiptId],
            );
            if (false !== $posted) {
                throw StockReceiptRefused::goodsReceiptAlreadyPosted($receipt->goodsReceiptId);
            }

            $this->refuseBinsOutsideTheWarehouse($em, $receipt);

            foreach ($receipt->lines as $line) {
                $connection->executeStatement(
                    <<<'SQL'
                    INSERT INTO stock_movement
                        (id, tenant_id, inventory_item_id, storage_location_id, quantity, reason, source_type, source_id, occurred_at)
                    VALUES
                        (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric, 'receipt', 'goods_receipt', ?::uuid, ?::timestamptz)
                    SQL,
                    [
                        Uuid::v4()->toRfc4122(),
                        $tenantId,
                        $line->inventoryItemId,
                        $line->storageLocationId,
                        $line->quantity,
                        $receipt->goodsReceiptId,
                        $receipt->occurredAt->format(\DATE_ATOM),
                    ],
                );
            }

            // W-5. `stock_level` is written LAST — after every movement — and
            // in ONE order, (inventory_item_id, storage_location_id) ascending,
            // whatever order the caller's lines came in. Each upsert of an
            // existing row takes its row lock, so two receipts over the same
            // bins queue on the first row rather than each holding the row the
            // other wants next. Two lines for one (item, bin) are one write:
            // the row is locked once and its version moves once per receipt.
            //
            // The key is two lower-cased 36-character UUIDs joined by NUL, so a
            // string sort is the pair's order and is Postgres's uuid order.
            $deltas = [];
            foreach ($receipt->lines as $line) {
                $key = strtolower($line->inventoryItemId)."\0".strtolower($line->storageLocationId);
                $deltas[$key] = bcadd($deltas[$key] ?? '0', $line->quantity, 6);
            }
            ksort($deltas, \SORT_STRING);

            foreach ($deltas as $key => $quantity) {
                [$itemId, $locationId] = explode("\0", (string) $key);

                $connection->executeStatement(
                    <<<'SQL'
                    INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand)
                    VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric)
                    ON CONFLICT (tenant_id, inventory_item_id, storage_location_id) DO UPDATE
                        SET qty_on_hand = stock_level.qty_on_hand + EXCLUDED.qty_on_hand,
                            version     = stock_level.version + 1,
                            updated_at  = now()
                    SQL,
                    [Uuid::v4()->toRfc4122(), $tenantId, $itemId, $locationId, $quantity],
                );
            }
        });
    }

    /**
     * "This bin is a live bin of that warehouse" is Inventory's fact to check,
     * and nothing else checks it: `stock_movement` has no `warehouse_id`, so the
     * schema would accept stock received at one warehouse booked into a bin at
     * another. Read before any write, so a refusal leaves nothing to undo.
     *
     * Takes the entity manager rather than a `Connection`: A-10 keeps
     * `Doctrine\DBAL\Connection` out of module code, and the register exists for
     * deviations that pre-date the ruleset, not new ones.
     */
    private function refuseBinsOutsideTheWarehouse(EntityManagerInterface $em, StockReceipt $receipt): void
    {
        $asked = array_values(array_unique(array_map(
            static fn (StockReceiptLine $line): string => strtolower($line->storageLocationId),
            $receipt->lines,
        )));

        $live = $em->getConnection()->fetchFirstColumn(
            'SELECT id::text FROM storage_location WHERE warehouse_id = ?::uuid AND deleted_at IS NULL AND id IN (?)',
            [$receipt->warehouseId, $asked],
            [ParameterType::STRING, ArrayParameterType::STRING],
        );

        foreach ($receipt->lines as $index => $line) {
            if (!\in_array(strtolower($line->storageLocationId), $live, true)) {
                throw StockReceiptRefused::binIsNotALiveBinOfTheReceivingWarehouse($index, $line->storageLocationId, $receipt->warehouseId);
            }
        }
    }
}

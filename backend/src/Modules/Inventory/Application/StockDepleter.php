<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Application\Contract\DepleteStock;
use App\Modules\Inventory\Application\Contract\NoCallerTransaction;
use App\Modules\Inventory\Application\Contract\StockDepletion;
use App\Modules\Inventory\Application\Contract\StockDepletionLine;
use App\Modules\Inventory\Application\Contract\StockDepletionRefused;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The one implementation of `DepleteStock`. The contract is on the interface.
 *
 * `StockReceiver`'s shape, deliberately: SQL rather than the entities, because
 * W-5 is a property of statement order and a flush does not promise one; every
 * statement on the entity manager's own connection, the one `TenantContext`
 * opened the caller's transaction on (A-10).
 *
 * The one real difference is the level write. A receipt can always add, so its
 * write is an upsert; a depletion can only subtract what is there, so its write
 * is a **conditional** `UPDATE` whose row count is the answer. An upsert here
 * would *insert* a negative level for a bin that holds none, and the CHECK
 * would then raise 23514 — the outcome this class exists to prevent.
 */
final readonly class StockDepleter implements DepleteStock
{
    /**
     * `storage_location.kind`'s one value that is never pickable (owner ruling R2).
     * A literal of the Warehouses module's CHECK value rather than an import of
     * `StorageLocation::KIND_QUARANTINE`: Inventory reads that table (the accepted
     * A-2 residual `StockReceiver` already carries) but must not depend on the
     * other module's entity.
     */
    private const QUARANTINE = 'quarantine';

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private EntityManagerInterface $em,
    ) {
    }

    public function deplete(StockDepletion $depletion): void
    {
        // W-2, exactly as `StockReceiver::receive()`.
        if (0 === $this->em->getConnection()->getTransactionNestingLevel()) {
            throw NoCallerTransaction::for(DepleteStock::class);
        }

        $tenantId = $this->tenant->tenantId();

        $this->tenants->run($tenantId, function (EntityManagerInterface $em) use ($depletion, $tenantId): void {
            $connection = $em->getConnection();

            // O-6: the "did this fulfilment already post?" key. A repeat would take
            // the stock out twice; inside one transaction it is a caller bug.
            $posted = $connection->fetchOne(
                "SELECT 1 FROM stock_movement WHERE source_type = 'fulfilment' AND source_id = ?::uuid LIMIT 1",
                [$depletion->fulfilmentId],
            );
            if (false !== $posted) {
                throw StockDepletionRefused::fulfilmentAlreadyPosted($depletion->fulfilmentId);
            }

            $archived = $connection->fetchOne(
                'SELECT 1 FROM warehouse WHERE id = ?::uuid AND deleted_at IS NOT NULL',
                [$depletion->warehouseId],
            );
            if (false !== $archived) {
                throw StockDepletionRefused::warehouseIsArchived($depletion->warehouseId);
            }

            $this->refuseBinsThatAreNotPickable($em, $depletion);
            [$deltas, $contributors] = self::summedLevelDeltas($depletion);

            foreach ($depletion->lines as $line) {
                $connection->executeStatement(
                    <<<'SQL'
                    INSERT INTO stock_movement
                        (id, tenant_id, inventory_item_id, storage_location_id, quantity, reason, source_type, source_id, occurred_at)
                    VALUES
                        (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric, 'fulfilment', 'fulfilment', ?::uuid, ?::timestamptz)
                    SQL,
                    [
                        Uuid::v4()->toRfc4122(),
                        $tenantId,
                        $line->inventoryItemId,
                        $line->storageLocationId,
                        bcsub('0', $line->quantity, 6),
                        $depletion->fulfilmentId,
                        $depletion->occurredAt->format(\DATE_ATOM),
                    ],
                );
            }

            // W-5: levels LAST, after every movement, and ascending by
            // (inventory_item_id, storage_location_id) — `StockReceiver`'s order.
            //
            // ⚠ The predicate is `qty_on_hand >= ?` and names no other column. It
            // is correct because qty_reserved is structurally 0 (model A, G-7), and
            // it must not name qty_reserved: G-7's tripwire flags any write literal
            // that does, a WHERE included. Whoever writes that column changes this.
            foreach ($deltas as $key => $quantity) {
                [$itemId, $locationId] = explode("\0", (string) $key);

                $changed = $connection->executeStatement(
                    <<<'SQL'
                    UPDATE stock_level
                       SET qty_on_hand = qty_on_hand - ?::numeric, version = version + 1, updated_at = now()
                     WHERE tenant_id = ?::uuid AND inventory_item_id = ?::uuid AND storage_location_id = ?::uuid
                       AND qty_on_hand >= ?::numeric
                    SQL,
                    [$quantity, $tenantId, $itemId, $locationId, $quantity],
                );

                if (1 !== $changed) {
                    // Either no level row exists (nothing was ever received here) or it
                    // holds too little: one refusal. No SQL error was raised, so the
                    // caller's transaction is alive and its rollback clean. The figure is
                    // read without a lock, so it is "when this was checked".
                    $onHand = $connection->fetchOne(
                        'SELECT qty_on_hand::text FROM stock_level WHERE inventory_item_id = ?::uuid AND storage_location_id = ?::uuid',
                        [$itemId, $locationId],
                    );

                    throw StockDepletionRefused::onHandDoesNotCoverTheDepletion(
                        $contributors[$key],
                        $itemId,
                        $locationId,
                        false === $onHand ? '0.000000' : bcadd((string) $onHand, '0', 6),
                        $quantity,
                    );
                }
            }
        });
    }

    /**
     * The per-(item, bin) sums the levels will be lowered by, ascending by key,
     * and for each key the line indexes that contributed — computed BEFORE any
     * write. The key is two canonical UUIDs joined by NUL, so a string sort is
     * Postgres's uuid order (`StockReceiver::summedLevelDeltas()`).
     *
     * No overflow refusal is needed, unlike the receipt: a sum no numeric(19,6)
     * level can hold is a sum no level can cover, so it is refused as
     * insufficient, and the comparison is against an unconstrained `numeric`.
     *
     * @return array{0: array<string, string>, 1: array<string, list<int>>}
     */
    private static function summedLevelDeltas(StockDepletion $depletion): array
    {
        $deltas = [];
        $contributors = [];
        foreach ($depletion->lines as $index => $line) {
            $key = $line->inventoryItemId."\0".$line->storageLocationId;
            $deltas[$key] = bcadd($deltas[$key] ?? '0', $line->quantity, 6);
            $contributors[$key][] = $index;
        }
        ksort($deltas, \SORT_STRING);

        return [$deltas, $contributors];
    }

    /**
     * Owner ruling R2: a line may pick only from a live, non-quarantine location
     * of the fulfilling warehouse. Read before any write, so a refusal leaves
     * nothing to undo. A bin this workspace cannot see (RLS) or of another
     * warehouse is not a location of this one; a bin of this warehouse that is
     * archived or quarantine is one, and is refused as not pickable.
     */
    private function refuseBinsThatAreNotPickable(EntityManagerInterface $em, StockDepletion $depletion): void
    {
        $asked = array_values(array_unique(array_map(
            static fn (StockDepletionLine $line): string => $line->storageLocationId,
            $depletion->lines,
        )));

        $found = [];
        foreach ($em->getConnection()->fetchAllAssociative(
            'SELECT id::text AS id, deleted_at IS NOT NULL AS archived, kind FROM storage_location WHERE warehouse_id = ?::uuid AND id IN (?)',
            [$depletion->warehouseId, $asked],
            [ParameterType::STRING, ArrayParameterType::STRING],
        ) as $row) {
            $found[(string) $row['id']] = $row;
        }

        foreach ($depletion->lines as $index => $line) {
            $bin = $found[$line->storageLocationId] ?? null;
            if (null === $bin) {
                throw StockDepletionRefused::binIsNotALocationOfTheFulfillingWarehouse($index, $line->storageLocationId, $depletion->warehouseId);
            }

            $archived = true === $bin['archived'] || 't' === $bin['archived'] || 1 === $bin['archived'];
            if ($archived || self::QUARANTINE === $bin['kind']) {
                throw StockDepletionRefused::binIsNotPickable($index, $line->storageLocationId, $archived);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\AuditLog\Infrastructure\StockMovementAuditWriter;
use App\Modules\Inventory\Application\Contract\PickableStock;
use App\Modules\Inventory\Application\Contract\TransferStock;
use App\Modules\UserManagement\Application\Contract\OwnerPredicate;
use App\Platform\Http\Idempotency\IdempotencyKeyHash;
use App\Platform\Http\Idempotency\RequestDigest;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\Display\QuantityRendering;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **The stock transfer: the one implementation of {@see TransferStock}, and the one class posting `transfer_out` and
 * `transfer_in`** (stock transfer C1, 2026-10-19). Authority, in precedence: `docs/session13/s35-ruling.md` (owner Q-T1
 * within and between warehouses, one instant move; Q-T2 quarantine → quarantine open; Q-T3 the note optional) >
 * `stock-transfer-sec-2.md` > `stock-transfer-design.md` Revision 2 §C1; codes `SEC` (`stock-transfer-sec.md`), sentences
 * PLACEHOLDERS for `UX`.
 *
 * Raw SQL on the entity manager's connection, in the order the rules need, never a `Doctrine\DBAL\Connection` in a
 * signature (A-10). It never writes `qty_reserved` (G-7): the source falls by a conditional decrement on `qty_on_hand`
 * alone. **The commit that first writes `qty_reserved` must refuse a transfer that would take on-hand below it, in domain
 * terms, or `stock_level_reserved_sane` raises 23514.**
 *
 * **Order** (design §5 as amended by Revision 2 C1.1), all inside its own `TenantContext::run()`:
 *  1. shape, before any transaction ({@see self::refuseMalformed()}), ids canonicalised before every comparison, so a
 *     case-variant id of the source is `same_as_source`;
 *  2. the replay, unlocked: the same key with the same digest is the first request's out leg; another digest is 409 (the
 *     digest names this operation, so a count's key sent on a transfer is 409, never a cross-operation replay);
 *  3. the item `FOR KEY SHARE` — unknown or another workspace's → 422; archived → 409;
 *  4. both bins' `warehouse_id`, unlocked (frozen: `UpdateStorageLocation` refuses it) — unknown → 422 at that bin's
 *     pointer; the DISTINCT warehouses `FOR KEY SHARE`, ascending by id, one statement each → archived 409 naming the side;
 *     then the two bins `FOR KEY SHARE`, ascending by id, **one statement each** (each reads its committed `kind` after any
 *     wait — the T-13 lesson) → archived 409 naming the side. Parent before child. No same-warehouse rule (owner Q-T1);
 *  5. **the release rule** (G-44; owner Q-T2), on kinds alone and before any level is read, so its refusal discloses no
 *     figure: a non-owner (`OwnerPredicate::holdsMarker`) may not move stock out of a `quarantine` bin into a
 *     non-quarantine one (`stock_level.release_needs_owner`). Into quarantine (a hold) and quarantine → quarantine are open.
 *     The two bin locks are G-44's lock clause: each conflicts with `UpdateStorageLocation`'s `FOR UPDATE`, so a hold or a
 *     release in flight serialises with the transfer and the transfer reads the committed kind;
 *  6. under a savepoint, ONE two-tuple `INSERT INTO stock_movement`: the out leg (`transfer_out`, −q, the source, the key
 *     and the digest) and the in leg (`transfer_in`, +q, the destination, no key), each naming the other as its source
 *     (written only as SQL text inside the tuples — never a PHP constant, parameter or entity reference: E-26 / F-2), at the
 *     database clock to the second. One statement makes a lone `transfer_in` impossible. A 23505 can only be the key (both
 *     ids are minted here): `ROLLBACK TO SAVEPOINT`, re-read by key → replay or 409 (the count's D-1 shape: never a 500);
 *  7. the levels, **ascending by `storage_location_id`** whatever the direction (W-5's house order for one item): the source
 *     by {@see self::LEVEL_DECREMENT} (0 rows → 409 `stock_level.on_hand_covers_the_transfer`, stating the source's
 *     on-hand read unlocked "when this was checked", that figure only — `SEC` §3), the destination by
 *     {@see self::LEVEL_BOUNDED_UPSERT} (0 rows → 409 `stock_level.on_hand_fits`, no figure). The upsert creates the
 *     destination's row when the bin never held the item. A 0-row write raises no SQL error, so the transaction is alive
 *     when the refusal is built, and the rollback takes the legs with it. **Source-first would deadlock two opposite
 *     transfers** (`StockTransferRaceTest` T-R1, mutation M-L1);
 *  8. one `stock_movement.adjusted` row per leg (`SEC` §1), in the same transaction.
 *
 * **Why the levels come after the movements:** house statement order, and it makes the key insert the first contended
 * write, so two same-key requests serialise on the unique index before either takes a level lock.
 *
 * **Disclosed residuals.** (L-1) A count and a transfer sent concurrently under ONE `Idempotency-Key` can deadlock (the
 * count holds its level and waits on the key; the transfer holds the key and waits on the level); Postgres aborts one with
 * `40P01`, which since G-65's closing work (2026-10-23) answers 409 `transaction.contention`, nothing saved (it was a 500).
 * Client misuse (one key, two operations): the loser's retry under the same key meets the winner's key with a different
 * request digest and is still refused, so nothing is ever applied twice. (L-4) A transfer whose source
 * is short **and** whose destination would overflow gets the refusal of whichever level comes first in uuid order —
 * shortage if the source id sorts first, overflow otherwise.
 */
final readonly class StockTransferrer implements TransferStock
{
    public const OPERATION = 'CreateStockTransfer';

    /**
     * The source's conditional decrement — `StockDepleter`'s predicate, naming no other column (G-7). Public so
     * `StockTransferRaceTest`'s holder executes THIS statement, not a transcription (final RED M-3).
     * Parameters: quantity, tenant, item, location, quantity.
     */
    public const LEVEL_DECREMENT = <<<'SQL'
        UPDATE stock_level
           SET qty_on_hand = qty_on_hand - ?::numeric, version = version + 1, updated_at = now()
         WHERE tenant_id = ?::uuid AND inventory_item_id = ?::uuid AND storage_location_id = ?::uuid
           AND qty_on_hand >= ?::numeric
        SQL;

    /**
     * The destination's bounded upsert — `StockReceiver`'s statement: the level is raised only while the sum still fits
     * numeric(19,6), evaluated on the locked current row. Public for the same reason as {@see self::LEVEL_DECREMENT}.
     * Parameters: new row id, tenant, item, location, quantity, the largest value.
     */
    public const LEVEL_BOUNDED_UPSERT = <<<'SQL'
        INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand)
        VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric)
        ON CONFLICT (tenant_id, inventory_item_id, storage_location_id) DO UPDATE
            SET qty_on_hand = stock_level.qty_on_hand + EXCLUDED.qty_on_hand,
                version     = stock_level.version + 1,
                updated_at  = now()
            WHERE stock_level.qty_on_hand + EXCLUDED.qty_on_hand <= ?::numeric
        SQL;

    /** The largest value numeric(19,6) holds — the receiver's bound. */
    public const LARGEST_QUANTITY = '9999999999999.999999';

    private const NOTE_MAX = 500;

    // Sentences: PLACEHOLDERS, `UX` TO CONFIRM (`stock-transfer-sec.md` §10).
    public const ITEM_ARCHIVED = 'This item is archived, so its stock can\'t be moved. It can be moved once it\'s restored.';

    public const SOURCE_WAREHOUSE_ARCHIVED = 'The warehouse you\'re moving stock from is archived, so stock can\'t be moved out of it until it\'s restored.';

    public const DESTINATION_WAREHOUSE_ARCHIVED = 'The warehouse you\'re moving stock to is archived, so stock can\'t be moved into it until it\'s restored.';

    public const SOURCE_LOCATION_ARCHIVED = 'The location you\'re moving stock from is archived, so stock can\'t be moved out of it until it\'s restored.';

    public const DESTINATION_LOCATION_ARCHIVED = 'The location you\'re moving stock to is archived, so stock can\'t be moved into it until it\'s restored.';

    public const RELEASE_NEEDS_OWNER = 'This stock is in a quarantine location, so only an owner can move it to a location that isn\'t quarantine. Moving it there releases held stock. Nothing was moved.';

    public const ON_HAND_FITS = 'The location you\'re moving stock to already holds so much of this item that the quantity moved would exceed 13 digits before the decimal point. Nothing was moved.';

    public const KEY_REUSED_HINT = 'Another move is a new request, so it needs a new key too.';

    public const ITEM_UNKNOWN = 'No inventory item in this workspace has this id.';

    public const LOCATION_UNKNOWN = 'No storage location in this workspace has this id.';

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private StockMovementAuditWriter $audit,
    ) {
    }

    /** The shortage sentence (PLACEHOLDER, `UX`): the source bin's on-hand when checked, that figure only (`SEC` §3). */
    public static function onHandDoesNotCover(string $onHand, string $quantity): string
    {
        return \sprintf('The location you\'re moving stock from held %s of this item when this was checked, so %s can\'t be moved out of it. Nothing was moved.',
            QuantityRendering::render($onHand), QuantityRendering::render($quantity));
    }

    public function transfer(array $submitted, string $idempotencyKey): string
    {
        self::refuseMalformed($submitted);

        $itemId = Uuid::fromString((string) $submitted['inventory_item_id'])->toRfc4122();
        $sourceId = Uuid::fromString((string) $submitted['source_storage_location_id'])->toRfc4122();
        $destinationId = Uuid::fromString((string) $submitted['destination_storage_location_id'])->toRfc4122();
        $quantity = bcadd((string) $submitted['quantity'], '0', 6);
        $note = self::canonicalNote($submitted['note'] ?? null);

        // D-B: the column holds the header's hash, never its value. The digest is over the canonical body: absent, null
        // and blank notes are one value (null), so they replay each other; ids are canonical (final RED L-3 / L-4).
        $keyHash = IdempotencyKeyHash::of($idempotencyKey);
        $digest = RequestDigest::of(self::OPERATION, [
            'inventory_item_id' => RequestDigest::uuid((string) $submitted['inventory_item_id']),
            'source_storage_location_id' => RequestDigest::uuid((string) $submitted['source_storage_location_id']),
            'destination_storage_location_id' => RequestDigest::uuid((string) $submitted['destination_storage_location_id']),
            'quantity' => $submitted['quantity'],
            'note' => $note,
        ]);
        $tenantId = $this->tenant->tenantId();
        $identity = $this->identities->authenticated();
        $owner = OwnerPredicate::holdsMarker($identity->permissionCodes);

        return $this->tenants->run($tenantId, function (EntityManagerInterface $em) use ($itemId, $sourceId, $destinationId, $quantity, $note, $keyHash, $digest, $tenantId, $identity, $owner): string {
            $connection = $em->getConnection();

            // 2.
            $replay = self::recordedByKeyInside($em, $keyHash, $digest);
            if (null !== $replay) {
                return $replay;
            }

            // 3. `fetchAssociative`, never `fetchOne`: a live item's `archived` IS `false`.
            $item = $connection->fetchAssociative('SELECT deleted_at IS NOT NULL AS archived FROM inventory_item WHERE id = ?::uuid FOR KEY SHARE', [$itemId]);
            if (false === $item) {
                throw ApiProblem::validationFailed([self::error('inventory_item_id', 'unknown_reference', self::ITEM_UNKNOWN)]);
            }
            if (self::bool($item['archived'])) {
                throw ApiProblem::businessRuleViolated('inventory_item.is_archived', self::ITEM_ARCHIVED);
            }

            // 4. Warehouse ids, unlocked (frozen).
            /** @var array<string, string> $warehouseOf */
            $warehouseOf = $connection->fetchAllKeyValue(
                'SELECT id::text, warehouse_id::text FROM storage_location WHERE id IN (?::uuid, ?::uuid)',
                [$sourceId, $destinationId],
            );
            $unknown = [];
            foreach (['source_storage_location_id' => $sourceId, 'destination_storage_location_id' => $destinationId] as $field => $id) {
                if (!isset($warehouseOf[$id])) {
                    $unknown[] = self::error($field, 'unknown_reference', self::LOCATION_UNKNOWN);
                }
            }
            if ([] !== $unknown) {
                throw ApiProblem::validationFailed($unknown);
            }

            // The distinct warehouses, ascending, one statement each.
            $warehouses = array_values(array_unique([$warehouseOf[$sourceId], $warehouseOf[$destinationId]]));
            sort($warehouses, \SORT_STRING);
            $warehouseArchived = [];
            foreach ($warehouses as $warehouseId) {
                $warehouseArchived[$warehouseId] = self::bool($connection->fetchOne(
                    'SELECT deleted_at IS NOT NULL FROM warehouse WHERE id = ?::uuid FOR KEY SHARE', [$warehouseId]));
            }
            if ($warehouseArchived[$warehouseOf[$sourceId]]) {
                throw ApiProblem::businessRuleViolated('warehouse.is_archived', self::SOURCE_WAREHOUSE_ARCHIVED);
            }
            if ($warehouseArchived[$warehouseOf[$destinationId]]) {
                throw ApiProblem::businessRuleViolated('warehouse.is_archived', self::DESTINATION_WAREHOUSE_ARCHIVED);
            }

            // The two bins, ascending, one statement each (G-44's lock clause).
            $bins = [$sourceId, $destinationId];
            sort($bins, \SORT_STRING);
            $location = [];
            foreach ($bins as $binId) {
                $row = $connection->fetchAssociative(
                    'SELECT kind, deleted_at IS NOT NULL AS archived FROM storage_location WHERE id = ?::uuid FOR KEY SHARE',
                    [$binId],
                );
                if (false === $row) {
                    throw new \LogicException('A storage location read a statement ago is no longer visible.');
                }
                $location[$binId] = $row;
            }
            if (self::bool($location[$sourceId]['archived'])) {
                throw ApiProblem::businessRuleViolated('storage_location.is_archived', self::SOURCE_LOCATION_ARCHIVED);
            }
            if (self::bool($location[$destinationId]['archived'])) {
                throw ApiProblem::businessRuleViolated('storage_location.is_archived', self::DESTINATION_LOCATION_ARCHIVED);
            }

            // 5. The release rule: kinds only, before any level is read.
            if (!$owner
                && PickableStock::NEVER_PICKABLE_KIND === (string) $location[$sourceId]['kind']
                && PickableStock::NEVER_PICKABLE_KIND !== (string) $location[$destinationId]['kind']) {
                throw ApiProblem::businessRuleViolated('stock_level.release_needs_owner', self::RELEASE_NEEDS_OWNER);
            }

            // 6. Both legs in one statement, under a savepoint.
            $outLegId = Uuid::v4()->toRfc4122();
            $inLegId = Uuid::v4()->toRfc4122();
            $connection->executeStatement('SAVEPOINT stock_transfer_key');
            try {
                $connection->executeStatement(
                    <<<'SQL'
                    INSERT INTO stock_movement
                        (id, tenant_id, inventory_item_id, storage_location_id, quantity, reason, source_type, source_id,
                         occurred_at, idempotency_key, request_digest)
                    VALUES
                        (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric, 'transfer_out', 'stock_movement', ?::uuid,
                         date_trunc('second', now()), ?, ?),
                        (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric, 'transfer_in', 'stock_movement', ?::uuid,
                         date_trunc('second', now()), NULL, NULL)
                    SQL,
                    [
                        $outLegId, $tenantId, $itemId, $sourceId, bcsub('0', $quantity, 6), $inLegId, $keyHash, $digest,
                        $inLegId, $tenantId, $itemId, $destinationId, $quantity, $outLegId,
                    ],
                );
            } catch (UniqueConstraintViolationException $e) {
                // Both ids are minted here, so the only unique this INSERT can hit is `stock_movement_idem_unique`.
                $connection->executeStatement('ROLLBACK TO SAVEPOINT stock_transfer_key');

                return self::recordedByKeyInside($em, $keyHash, $digest) ?? throw $e;
            }
            $connection->executeStatement('RELEASE SAVEPOINT stock_transfer_key');

            // 7. The levels, ascending by location id, whatever the direction.
            foreach ($bins as $binId) {
                if ($binId === $sourceId) {
                    $changed = $connection->executeStatement(self::LEVEL_DECREMENT, [$quantity, $tenantId, $itemId, $sourceId, $quantity]);
                    if (1 !== $changed) {
                        // No row, or too little. Read without a lock: "when this was checked".
                        $onHand = $connection->fetchOne(
                            'SELECT qty_on_hand::text FROM stock_level WHERE inventory_item_id = ?::uuid AND storage_location_id = ?::uuid',
                            [$itemId, $sourceId],
                        );
                        throw ApiProblem::businessRuleViolated('stock_level.on_hand_covers_the_transfer',
                            self::onHandDoesNotCover(false === $onHand ? '0.000000' : bcadd((string) $onHand, '0', 6), $quantity));
                    }
                } else {
                    $changed = $connection->executeStatement(self::LEVEL_BOUNDED_UPSERT,
                        [Uuid::v4()->toRfc4122(), $tenantId, $itemId, $destinationId, $quantity, self::LARGEST_QUANTITY]);
                    if (1 !== $changed) {
                        throw ApiProblem::businessRuleViolated('stock_level.on_hand_fits', self::ON_HAND_FITS);
                    }
                }
            }

            // 8.
            $this->audit->recordTransferred(
                tenantId: $identity->tenantId,
                actorUserId: $identity->userId,
                actorLabel: $identity->actorLabel,
                outLegId: $outLegId,
                inLegId: $inLegId,
                note: $note,
            );

            return $outLegId;
        });
    }

    /** The out leg this key recorded, if its digest matches; 409 if the key recorded another request; null if none. */
    private static function recordedByKeyInside(EntityManagerInterface $em, string $keyHash, string $digest): ?string
    {
        $row = $em->getConnection()->fetchAssociative(
            'SELECT id::text AS id, request_digest FROM stock_movement WHERE idempotency_key = ?',
            [$keyHash],
        );
        if (false === $row) {
            return null;
        }
        if ($row['request_digest'] !== $digest) {
            throw ApiProblem::idempotencyKeyConflict(self::KEY_REUSED_HINT);
        }

        return (string) $row['id'];
    }

    /** Owner Q-T3: absent, null and blank-after-trim are "no note" (null); otherwise the trimmed text. */
    private static function canonicalNote(mixed $note): ?string
    {
        if (!\is_string($note) || '' === trim($note)) {
            return null;
        }

        return trim($note);
    }

    private static function bool(mixed $value): bool
    {
        return true === $value || 't' === $value || 1 === $value || '1' === $value;
    }

    /**
     * Shape, and only shape: decidable without the database. Every defect at once, each at its own pointer.
     *
     * @param array<string, mixed> $submitted
     */
    private static function refuseMalformed(array $submitted): void
    {
        $errors = [];

        $valid = [];
        foreach (['inventory_item_id', 'source_storage_location_id', 'destination_storage_location_id'] as $field) {
            $value = $submitted[$field] ?? null;
            if (null === $value) {
                $errors[] = self::error($field, 'required', 'This field is required.');
            } elseif (!\is_string($value) || !Uuid::isValid($value)) {
                $errors[] = self::error($field, 'not_a_uuid', 'This field must be a UUID naming a row in this workspace.');
            } else {
                $valid[$field] = Uuid::fromString($value)->toRfc4122();
            }
        }
        if (isset($valid['source_storage_location_id'], $valid['destination_storage_location_id'])
            && $valid['source_storage_location_id'] === $valid['destination_storage_location_id']) {
            $errors[] = self::error('destination_storage_location_id', 'same_as_source', 'Choose a location other than the one the stock is moving from.');
        }

        $quantity = $submitted['quantity'] ?? null;
        if (null === $quantity) {
            $errors[] = self::error('quantity', 'required', 'Enter the quantity to move.');
        } elseif (!\is_string($quantity) || 1 !== preg_match('/\A-?\d+(\.\d+)?\z/', $quantity)) {
            $errors[] = self::error('quantity', 'not_a_decimal', 'Enter the quantity to move as a number, for example 12 or 12.5.');
        } elseif (1 !== bccomp($quantity, '0', 30)) {
            $errors[] = self::error('quantity', 'not_positive', 'The quantity to move must be more than zero.');
        } elseif (1 !== preg_match('/\A\d{1,13}(\.\d{1,6})?\z/', $quantity) || 1 === bccomp($quantity, self::LARGEST_QUANTITY, 6)) {
            $errors[] = self::error('quantity', 'out_of_range', 'A quantity can have at most 13 digits before the decimal point and 6 after.');
        }

        $note = $submitted['note'] ?? null;
        if (null !== $note) {
            if (!\is_string($note)) {
                $errors[] = self::error('note', 'not_a_string', 'This field must be a string.');
            } elseif (mb_strlen(trim($note)) > self::NOTE_MAX) {
                $errors[] = self::error('note', 'too_long', 'The note must be at most 500 characters.');
            }
        }

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }
    }

    /** @return array{pointer: string, code: string, message: string} */
    private static function error(string $field, string $code, string $message): array
    {
        return ['pointer' => '/'.$field, 'code' => $code, 'message' => $message];
    }
}

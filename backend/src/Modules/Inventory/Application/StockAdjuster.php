<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\AuditLog\Infrastructure\StockMovementAuditWriter;
use App\Modules\Inventory\Application\Contract\AdjustStock;
use App\Modules\Inventory\Application\Contract\PickableStock;
use App\Modules\UserManagement\Application\Contract\OwnerPredicate;
use App\Platform\Http\Idempotency\IdempotencyKeyHash;
use App\Platform\Http\Idempotency\RequestDigest;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **The stock count: the one implementation of {@see AdjustStock}, and the one class posting `reason = 'adjustment'`**
 * (stock count C1, 2026-10-16). Authority, in precedence: `docs/session13/stock-adjustment-ruling.md` (owner Q1 (C′), Q2,
 * Q3 (i), Q4) > `stock-adjustment-final-red.md` (H-1's order, R-2″, L-3) > `stock-adjustment-design.md` Revision 1;
 * codes `SEC` (`stock-adjustment-sec.md`), sentences `UX` (`stock-adjustment-ux.md` §2).
 *
 * Raw SQL on the entity manager's connection, in the order the rules need (a flush promises no order), never a
 * `Doctrine\DBAL\Connection` in a signature (A-10). It never writes `qty_reserved` (G-7): the level is SET to the counted
 * figure, which is `>= 0`, so `stock_level_reserved_sane` cannot fire while nothing writes `qty_reserved`. **The commit
 * that first writes `qty_reserved` must refuse a count below it in domain terms, or the CHECK raises 23514.**
 *
 * **Order** (final RED H-1 — every comparison with on-hand happens only after the counter has proved they saw it):
 *  1. shape, before any transaction ({@see self::refuseMalformed()}); the `version` KEY must be present (`null` is a value:
 *     "this bin showed no row for this item"; an absent key would be an unconditional overwrite);
 *  2. the replay, unlocked: the same key with the same digest is the first request's movement; another digest is 409;
 *  3. the item `FOR KEY SHARE` — unknown or another workspace's → 422 `unknown_reference`; archived → 409
 *     `inventory_item.is_archived` (owner Q4). `KEY SHARE`, the siblings' lock, not `FOR SHARE` (final RED L-2: an archive
 *     racing a count serialises as "count, then archive", a legal order; `FOR SHARE` would buy nothing a test could see);
 *  4. the bin's `warehouse_id`, unlocked (frozen: `UpdateStorageLocation` refuses it) — unknown → 422; the warehouse
 *     `FOR KEY SHARE` → archived 409; then, **in its own statement**, the bin `FOR KEY SHARE` reading `kind` and
 *     `deleted_at` → archived 409. The bin lock is `THE_RELEASE_RULE`'s lock clause: it conflicts with
 *     `UpdateStorageLocation`'s `FOR UPDATE`, so a concurrent hold or release serialises with the count and the count reads
 *     the committed kind (R-2′, R-2″; a qty-only `stock_level` write locks no location);
 *  5. the level, **its own statement**, `FOR UPDATE` — the last lock taken (W-5); absent → on-hand 0, no row;
 *  6. **the replay again**, its own statement (READ COMMITTED: a fresh snapshot), so a same-key request that committed
 *     while this one waited at step 5 is a replay, never a `stale-record` (final RED R-5 / M23);
 *  7. **the version** — a row whose version differs, `null` with a row, or a number with none → 409 `stale-record` with
 *     `current = {qty_on_hand, version}` (`SEC` §5: exactly those two);
 *  8. **the match** — counted = on-hand → 409 `stock_level.count_matches_on_hand` (owner Q3 (i)), nothing written, no key
 *     stored; also keeps `stock_movement_quantity_nonzero` from ever raising 23514;
 *  9. **the release rule** (owner Q1 (C′); G-44): a non-owner may not count a `quarantine` bin DOWN
 *     (`stock_level.count_down_needs_owner`), nor count UP, in a non-quarantine bin, an item that has stock in any live
 *     quarantine location of the workspace (`stock_level.held_item_needs_owner`). Counting a quarantine bin up is a hold
 *     and free. **The held read takes no lock because only an owner can empty quarantine** (final RED L-3): a hold that
 *     commits after it is ordered after the count, a correct order; no non-owner path takes held stock to zero today. An
 *     operation that lets a non-owner move stock out of quarantine must lock or re-check it. It is a separate statement,
 *     after the level lock, never folded into a locking one (the T-13 lesson: a statement that waited reads other tables
 *     from its start snapshot);
 * 10. the writes, the siblings' statement order: the movement (literal `'adjustment'`, no `source_type`/`source_id` —
 *     there is no originating document, `SEC` §2 — `occurred_at` from the database clock to the second, the key and the
 *     digest), then the level (`UPDATE … version = version + 1` on the locked row, or `INSERT … ON CONFLICT DO NOTHING`
 *     for a bin with no row; 0 rows there means a row appeared since the counter looked → re-read it, 409
 *     `stale-record`, and the rollback takes the movement with it);
 * 11. `stock_movement.adjusted` `{counted_quantity, note}` in the same transaction (E-25 (a)).
 *
 * **A 23505 from the movement insert** can only be `stock_movement_idem_unique` (the id is minted here). It is caught
 * INSIDE the transaction, under a savepoint taken just before the INSERT: `ROLLBACK TO SAVEPOINT` clears the aborted
 * statement and the key is re-read — the same digest replays, any other is 409. This covers the never-stocked bin, where
 * no level lock serialises two same-key requests. **Deviation from the design (R1.1 "caught OUTSIDE `run()`, then a fresh
 * `run()`", `CustomerAddressWriter`'s branch), measured:** `run()`'s `wrapInTransaction` CLOSES the entity manager when
 * the closure throws, so the fresh `run()` failed "The EntityManager is closed" → HTTP 500 (`StockAdjustmentRaceTest`'s
 * never-stocked replay, first run). The savepoint never lets the exception reach `wrapInTransaction`.
 */
final readonly class StockAdjuster implements AdjustStock
{
    public const OPERATION = 'CreateStockAdjustment';

    /** The largest value numeric(19,6) holds — the receiver's bound. */
    private const LARGEST_QUANTITY = '9999999999999.999999';

    private const NOTE_MAX = 500;

    // `UX` `docs/session13/stock-adjustment-ux.md` §2.1 (S-1…S-8) and §2.3 (V-1…V-11).
    public const ITEM_ARCHIVED = 'This item is archived, so its stock can\'t be counted. It can be counted once it\'s restored.';

    public const WAREHOUSE_ARCHIVED = 'This warehouse is archived, so stock can\'t be counted in it.';

    public const LOCATION_ARCHIVED = 'This location is archived, so stock can\'t be counted in it.';

    public const KEY_REUSED_HINT = 'A recount is a new request, so it needs a new key too.';

    public const MATCHES = 'This count matches the stock on record, so there\'s nothing to change. Nothing was recorded.';

    public const COUNT_DOWN_NEEDS_OWNER = 'This is a quarantine location, so only an owner can count its stock down. Counting down here writes off held stock.';

    public const HELD_ITEM_NEEDS_OWNER = 'Some of this item is in quarantine, in this warehouse or another, so only an owner can count it up. It can be counted up by anyone once none of it is held.';

    public const ITEM_UNKNOWN = 'No inventory item in this workspace has this id.';

    public const LOCATION_UNKNOWN = 'No storage location in this workspace has this id.';

    /**
     * The (C′) held read: does the item have stock in any live quarantine location of the workspace? Public so the plan
     * test explains THIS statement. Reaches `stock_level` by `stock_level_unique`'s `(tenant_id, inventory_item_id)` prefix.
     */
    public const HELD_ANYWHERE = <<<'SQL'
        SELECT EXISTS (
            SELECT 1 FROM stock_level s
              JOIN storage_location l ON l.tenant_id = s.tenant_id AND l.id = s.storage_location_id
             WHERE s.inventory_item_id = ?::uuid AND s.qty_on_hand > 0
               AND l.kind = ? AND l.deleted_at IS NULL
        )
        SQL;

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private StockMovementAuditWriter $audit,
    ) {
    }

    public function adjust(array $submitted, string $idempotencyKey): string
    {
        self::refuseMalformed($submitted);

        $itemId = Uuid::fromString((string) $submitted['inventory_item_id'])->toRfc4122();
        $locationId = Uuid::fromString((string) $submitted['storage_location_id'])->toRfc4122();
        $counted = bcadd((string) $submitted['counted_quantity'], '0', 6);
        /** @var int|null $version */
        $version = $submitted['version'];
        $note = (string) $submitted['note'];

        // D-B: the column holds the header's hash, never its value; the digest is over the body as sent.
        $keyHash = IdempotencyKeyHash::of($idempotencyKey);
        $digest = RequestDigest::of(self::OPERATION, [
            'inventory_item_id' => RequestDigest::uuid((string) $submitted['inventory_item_id']),
            'storage_location_id' => RequestDigest::uuid((string) $submitted['storage_location_id']),
            'counted_quantity' => $submitted['counted_quantity'],
            'version' => $version,
            'note' => $note,
        ]);
        $tenantId = $this->tenant->tenantId();
        $identity = $this->identities->authenticated();
        $owner = OwnerPredicate::holdsMarker($identity->permissionCodes);

        return $this->tenants->run($tenantId, function (EntityManagerInterface $em) use ($itemId, $locationId, $counted, $version, $note, $keyHash, $digest, $tenantId, $identity, $owner): string {
            $connection = $em->getConnection();

            // 2.
            $replay = self::recordedByKeyInside($em, $keyHash, $digest);
            if (null !== $replay) {
                return $replay;
            }

            // 3.
            // `fetchAssociative`, never `fetchOne`: a live item's `archived` IS `false`, which `fetchOne` cannot tell
            // from "no row".
            $item = $connection->fetchAssociative('SELECT deleted_at IS NOT NULL AS archived FROM inventory_item WHERE id = ?::uuid FOR KEY SHARE', [$itemId]);
            if (false === $item) {
                throw ApiProblem::validationFailed([self::error('inventory_item_id', 'unknown_reference', self::ITEM_UNKNOWN)]);
            }
            if (self::bool($item['archived'])) {
                throw ApiProblem::businessRuleViolated('inventory_item.is_archived', self::ITEM_ARCHIVED);
            }

            // 4.
            $warehouseId = $connection->fetchOne('SELECT warehouse_id::text FROM storage_location WHERE id = ?::uuid', [$locationId]);
            if (false === $warehouseId) {
                throw ApiProblem::validationFailed([self::error('storage_location_id', 'unknown_reference', self::LOCATION_UNKNOWN)]);
            }
            $warehouseArchived = $connection->fetchOne('SELECT deleted_at IS NOT NULL FROM warehouse WHERE id = ?::uuid FOR KEY SHARE', [$warehouseId]);
            if (self::bool($warehouseArchived)) {
                throw ApiProblem::businessRuleViolated('warehouse.is_archived', self::WAREHOUSE_ARCHIVED);
            }
            $location = $connection->fetchAssociative(
                'SELECT kind, deleted_at IS NOT NULL AS archived FROM storage_location WHERE id = ?::uuid FOR KEY SHARE',
                [$locationId],
            );
            if (false === $location) {
                throw new \LogicException('A storage location read a statement ago is no longer visible.');
            }
            if (self::bool($location['archived'])) {
                throw ApiProblem::businessRuleViolated('storage_location.is_archived', self::LOCATION_ARCHIVED);
            }

            // 5.
            $level = $connection->fetchAssociative(
                'SELECT id::text AS id, qty_on_hand::text AS qty_on_hand, version FROM stock_level'
                .' WHERE inventory_item_id = ?::uuid AND storage_location_id = ?::uuid FOR UPDATE',
                [$itemId, $locationId],
            );

            // 6.
            $replay = self::recordedByKeyInside($em, $keyHash, $digest);
            if (null !== $replay) {
                return $replay;
            }

            // 7.
            if (false === $level) {
                if (null !== $version) {
                    throw ApiProblem::staleRecord(['qty_on_hand' => '0.000000', 'version' => null]);
                }
                $onHand = '0.000000';
            } else {
                if ($version !== (int) $level['version']) {
                    throw ApiProblem::staleRecord(self::current($level));
                }
                $onHand = bcadd((string) $level['qty_on_hand'], '0', 6);
            }

            // 8.
            $delta = bcsub($counted, $onHand, 6);
            if (0 === bccomp($delta, '0', 6)) {
                throw ApiProblem::businessRuleViolated('stock_level.count_matches_on_hand', self::MATCHES);
            }

            // 9.
            if (!$owner) {
                $quarantine = PickableStock::NEVER_PICKABLE_KIND === (string) $location['kind'];
                if ($quarantine && -1 === bccomp($delta, '0', 6)) {
                    throw ApiProblem::businessRuleViolated('stock_level.count_down_needs_owner', self::COUNT_DOWN_NEEDS_OWNER);
                }
                if (!$quarantine && 1 === bccomp($delta, '0', 6)
                    && self::bool($connection->fetchOne(self::HELD_ANYWHERE, [$itemId, PickableStock::NEVER_PICKABLE_KIND]))) {
                    throw ApiProblem::businessRuleViolated('stock_level.held_item_needs_owner', self::HELD_ITEM_NEEDS_OWNER);
                }
            }

            // 10. Under a savepoint, so a 23505 on the key (a same-key request committed while this one waited at the
            // unique — the never-stocked bin has no level lock to queue it) rolls back only this statement, and the
            // key is re-read in THIS transaction (READ COMMITTED: the statement sees the winner).
            $movementId = Uuid::v4()->toRfc4122();
            $connection->executeStatement('SAVEPOINT stock_count_key');
            try {
                $connection->executeStatement(
                    <<<'SQL'
                    INSERT INTO stock_movement
                        (id, tenant_id, inventory_item_id, storage_location_id, quantity, reason, source_type, source_id,
                         occurred_at, idempotency_key, request_digest)
                    VALUES
                        (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric, 'adjustment', NULL, NULL,
                         date_trunc('second', now()), ?, ?)
                    SQL,
                    [$movementId, $tenantId, $itemId, $locationId, $delta, $keyHash, $digest],
                );
            } catch (UniqueConstraintViolationException $e) {
                // The id is minted here, so the only unique this INSERT can hit is `stock_movement_idem_unique`.
                $connection->executeStatement('ROLLBACK TO SAVEPOINT stock_count_key');

                return self::recordedByKeyInside($em, $keyHash, $digest) ?? throw $e;
            }
            $connection->executeStatement('RELEASE SAVEPOINT stock_count_key');

            if (false !== $level) {
                $changed = $connection->executeStatement(
                    'UPDATE stock_level SET qty_on_hand = ?::numeric, version = version + 1, updated_at = now()'
                    .' WHERE id = ?::uuid AND version = ?',
                    [$counted, $level['id'], (int) $level['version']],
                );
                if (1 !== $changed) {
                    throw new \LogicException('The level locked at step 5 moved under its own lock.');
                }
            } else {
                $inserted = $connection->executeStatement(
                    <<<'SQL'
                    INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand)
                    VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric)
                    ON CONFLICT (tenant_id, inventory_item_id, storage_location_id) DO NOTHING
                    SQL,
                    [Uuid::v4()->toRfc4122(), $tenantId, $itemId, $locationId, $counted],
                );
                if (1 !== $inserted) {
                    // A row appeared since the counter looked (L-14): re-read it, in a new statement, for `current`.
                    $appeared = $connection->fetchAssociative(
                        'SELECT qty_on_hand::text AS qty_on_hand, version FROM stock_level WHERE inventory_item_id = ?::uuid AND storage_location_id = ?::uuid',
                        [$itemId, $locationId],
                    );
                    throw ApiProblem::staleRecord(false === $appeared ? ['qty_on_hand' => '0.000000', 'version' => null] : self::current($appeared));
                }
            }

            // 11.
            $this->audit->recordAdjusted(
                tenantId: $identity->tenantId,
                actorUserId: $identity->userId,
                actorLabel: $identity->actorLabel,
                movementId: $movementId,
                countedQuantity: $counted,
                note: $note,
            );

            return $movementId;
        });
    }

    /** The movement this key recorded, if its digest matches; 409 if the key recorded another request; null if none. */
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

    /**
     * @param array<string, mixed> $level
     *
     * @return array{qty_on_hand: string, version: int}
     */
    private static function current(array $level): array
    {
        return ['qty_on_hand' => bcadd((string) $level['qty_on_hand'], '0', 6), 'version' => (int) $level['version']];
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

        foreach (['inventory_item_id', 'storage_location_id'] as $field) {
            $value = $submitted[$field] ?? null;
            if (null === $value) {
                $errors[] = self::error($field, 'required', 'This field is required.');
            } elseif (!\is_string($value) || !Uuid::isValid($value)) {
                $errors[] = self::error($field, 'not_a_uuid', 'This field must be a UUID naming a row in this workspace.');
            }
        }

        $counted = $submitted['counted_quantity'] ?? null;
        if (null === $counted) {
            $errors[] = self::error('counted_quantity', 'required', 'Enter the quantity you counted.');
        } elseif (!\is_string($counted) || 1 !== preg_match('/\A-?\d+(\.\d+)?\z/', $counted)) {
            $errors[] = self::error('counted_quantity', 'not_a_decimal', 'Enter the quantity counted as a number, for example 12 or 12.5.');
        } elseif (str_starts_with($counted, '-') && 0 !== bccomp($counted, '0', 6)) {
            $errors[] = self::error('counted_quantity', 'negative', 'A count can\'t be less than zero.');
        } elseif (1 !== preg_match('/\A-?\d{1,13}(\.\d{1,6})?\z/', $counted) || 1 === bccomp(ltrim($counted, '-'), self::LARGEST_QUANTITY, 6)) {
            $errors[] = self::error('counted_quantity', 'out_of_range', 'A count can have at most 13 digits before the decimal point and 6 after.');
        }

        if (!\array_key_exists('version', $submitted)) {
            $errors[] = self::error('version', 'required', 'Send the version shown with the stock figure you counted against, or null if this location showed none of this item.');
        } elseif (null !== $submitted['version'] && (!\is_int($submitted['version']) || $submitted['version'] < 1)) {
            $errors[] = self::error('version', 'not_an_integer', 'The version must be a whole number of at least 1, or null.');
        }

        $note = $submitted['note'] ?? null;
        if (null === $note) {
            $errors[] = self::error('note', 'required', 'Say why the stock is being corrected.');
        } elseif (!\is_string($note)) {
            $errors[] = self::error('note', 'not_a_string', 'This field must be a string.');
        } elseif ('' === trim($note)) {
            $errors[] = self::error('note', 'blank', 'Say why the stock is being corrected.');
        } elseif (mb_strlen($note) > self::NOTE_MAX) {
            $errors[] = self::error('note', 'too_long', 'The note must be at most 500 characters.');
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

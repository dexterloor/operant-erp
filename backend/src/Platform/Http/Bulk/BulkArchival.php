<?php

declare(strict_types=1);

namespace App\Platform\Http\Bulk;

use App\Platform\Http\Idempotency\IdempotencyEnvelopeStore;
use App\Platform\Http\Idempotency\IdempotencyKeyHash;
use App\Platform\Http\Idempotency\Replay;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Http\Problem\ProblemType;
use App\Platform\Http\Problem\TransactionContention;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * **`CreateBulkArchival`'s coordinator** (G5-C2, 2026-10-22; authority `docs/session13/g5-g6-final-red.md` >
 * `g5-g6-bulk-export-design.md` Revision 1 FINAL R1.2 G5-C2 > §5.2–§5.4; replay protocol C-1).
 *
 * **ONE `TenantContext::run()`, in this order — the order is the design:**
 *  1. {@see IdempotencyEnvelopeStore::lookup()}: a live same-request envelope is returned as stored, with no rule re-run
 *     (a member restored since must not turn a committed 200 into anything else); an expired key 422; another request 409.
 *  2. `SAVEPOINT bulk_work`, then the members in {@see BulkArchiveMember::lockOrder()}, each under `SAVEPOINT bulk_member`:
 *     the member's single writer method (parity, D-12). Applied → `RELEASE`. Refused with an allow-listed problem (404
 *     `not-found`, 409 `business-rule-violated`; design L-3) → `ROLLBACK TO` **then `RELEASE`** (final RED L-2: without the
 *     release each refused member leaves one more nested level for the next `SAVEPOINT` to shadow). Anything else —
 *     including a `40P01` raised inside a member, which a `ROLLBACK TO` could technically recover — is RETHROWN (final RED
 *     L-3): a bug or a deadlock is never counted as "not applied".
 *  3. After every member, the unit of work must be clean (final RED L-6, runtime guard): the end of `run()` flushes, and a
 *     rolled-back member's scheduled ORM change would otherwise be persisted by it.
 *  4. {@see IdempotencyEnvelopeStore::record()} LAST. 0 rows → a same-key request committed while this one waited at the
 *     unique: `ROLLBACK TO SAVEPOINT bulk_work` (this request's member work is undone, its locks released) and
 *     {@see IdempotencyEnvelopeStore::replayTheWinner()} on the same connection — never a second `run()` from a `catch`.
 *
 * **Contention** (design R1 L-2, final RED L-3/M-3): a `40P01` deadlock or a `55P03` lock timeout aborts the whole request
 * and is translated OUTSIDE `run()` (the `NoRunAfterAFailedRunTest` shape: the catch only translates) into 409
 * `bulk.contention` — nothing done, nothing stored. `55P03` is unreachable today (no `lock_timeout` is set anywhere); its
 * test sets one locally.
 *
 * **What the answer says** (E-14): `{"requested": n, "applied": m}`, and nothing else — a foreign id, an absent id, an
 * already-archived row and a row refused for open work are one undifferentiated `requested − applied`. No bulk-level event
 * and no `bulk` marker (D-15): each applied member's own `<table>.deleted` row is written by its single writer, and a
 * refused member's rolls back with its savepoint.
 *
 * **Cap: {@see self::MAXIMUM_MEMBERS} = 50** (final RED M-2): each applied member is a subtransaction that keeps its subxid
 * until top-level commit, and a backend's subxid cache holds `PGPROC_MAX_CACHED_SUBXIDS` = 64; past it, every snapshot in
 * the cluster that sees this transaction running consults `pg_subtrans`. 50 members + `bulk_work` stays under 64
 * (`BulkArchivalTest::theCapKeepsEverySubtransactionInTheSubxidCache`).
 */
final readonly class BulkArchival
{
    public const OPERATION = 'CreateBulkArchival';

    /** Final RED M-2: cap + 1 (`bulk_work`) must stay below PostgreSQL's 64 cached subxids. */
    public const MAXIMUM_MEMBERS = 50;

    /** `PGPROC_MAX_CACHED_SUBXIDS` (PostgreSQL `proc.h`), the figure the cap is held under. */
    public const POSTGRES_CACHED_SUBXIDS = 64;

    public const CONTENT_TYPE = 'application/json; charset=utf-8';

    public const CONTENTION_RULE = 'bulk.contention';
    public const CONTENTION_MESSAGE = 'Some of these records were being changed at the same moment. Nothing was done; try again.';

    public const WORK_SAVEPOINT = 'bulk_work';
    public const MEMBER_SAVEPOINT = 'bulk_member';

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private IdempotencyEnvelopeStore $store,
        private BulkArchiveMembers $members,
    ) {
    }

    /**
     * @param list<string> $ids lower-case canonical uuids, distinct, 1..{@see self::MAXIMUM_MEMBERS} (the processor's 422s)
     */
    public function run(string $resource, array $ids, string $idempotencyKey, string $actorUserId): Replay
    {
        if ([] === $ids || \count($ids) > self::MAXIMUM_MEMBERS || \count($ids) !== \count(array_unique($ids))) {
            throw new \LogicException('A bulk archival runs over 1 to 50 distinct ids; the processor refuses anything else with a 422.');
        }

        $member = $this->members->get($resource);
        $sorted = $ids;
        sort($sorted, \SORT_STRING);

        $keyHash = IdempotencyKeyHash::of($idempotencyKey);
        $digest = IdempotencyEnvelopeStore::requestDigest(self::OPERATION, $actorUserId, ['resource' => $resource, 'ids' => $sorted]);
        $tenantId = $this->tenant->tenantId();

        try {
            return $this->tenants->run($tenantId, function (EntityManagerInterface $em) use ($member, $sorted, $keyHash, $digest, $tenantId): Replay {
                $connection = $em->getConnection();

                $replay = $this->store->lookup($connection, self::OPERATION, $keyHash, $digest);
                if (null !== $replay) {
                    return $replay;
                }

                $connection->createSavepoint(self::WORK_SAVEPOINT);

                $order = $member->lockOrder($sorted);
                $given = $order;
                sort($given, \SORT_STRING);
                if ($given !== $sorted) {
                    throw new \LogicException(\sprintf('The bulk member for "%s" reordered ids it was not given.', $member->resource()));
                }

                $applied = 0;
                foreach ($order as $id) {
                    $connection->createSavepoint(self::MEMBER_SAVEPOINT);
                    try {
                        $member->archive($id);
                        $connection->releaseSavepoint(self::MEMBER_SAVEPOINT);
                        ++$applied;
                    } catch (ApiProblem $refused) {
                        if (!self::isNotApplied($refused)) {
                            throw $refused;
                        }
                        $connection->rollbackSavepoint(self::MEMBER_SAVEPOINT);
                        $connection->releaseSavepoint(self::MEMBER_SAVEPOINT);
                    }
                    self::refuseAPendingOrmChange($em, $member);
                }

                $body = json_encode(['requested' => \count($sorted), 'applied' => $applied], \JSON_THROW_ON_ERROR);
                $headers = ['content-type' => self::CONTENT_TYPE];

                if ($this->store->record($connection, $tenantId, self::OPERATION, $keyHash, $digest, IdempotencyEnvelopeStore::STORED_STATUS, $body, $headers)) {
                    return new Replay(IdempotencyEnvelopeStore::STORED_STATUS, $body, $headers);
                }

                $connection->rollbackSavepoint(self::WORK_SAVEPOINT);

                return $this->store->replayTheWinner($connection, self::OPERATION, $keyHash, $digest);
            });
        } catch (DriverException $failure) {
            // Outside run(): the transaction is already rolled back and the manager closed; this only translates.
            if (!self::isContention($failure)) {
                throw $failure;
            }

            throw ApiProblem::contention(self::CONTENTION_RULE, self::CONTENTION_MESSAGE, $failure);
        }
    }

    /**
     * `40P01` (DBAL: `DeadlockException`) or `55P03` (lock not available). **Measured 2026-10-22:** DBAL 4's PostgreSQL
     * converter maps `40P01` (and `40001`) to `DeadlockException` but does NOT map `55P03` to `LockWaitTimeoutException` —
     * it arrives as a plain `DriverException` — so the lock-timeout arm is keyed on the SQLSTATE, not the class.
     */
    public static function isContention(DriverException $failure): bool
    {
        // One predicate (G-65's closing work, 2026-10-23): the listener's `transaction.contention` arm asks the same one.
        return TransactionContention::is($failure);
    }

    /** The two refusals a single delete answers for a record it will not archive (design L-3); any other is a 500. */
    public static function isNotApplied(ApiProblem $problem): bool
    {
        return \in_array($problem->getType(), [ProblemType::NotFound->uri(), ProblemType::BusinessRuleViolated->uri()], true);
    }

    /**
     * Final RED L-6's runtime guard. The members are DBAL-only by contract (and by `BulkMemberWritersAreDbalOnlyTest`);
     * this is what goes red if one ever schedules an ORM change, which the end-of-`run()` flush would otherwise persist
     * even for a member whose savepoint was rolled back.
     */
    private static function refuseAPendingOrmChange(EntityManagerInterface $em, BulkArchiveMember $member): void
    {
        $unitOfWork = $em->getUnitOfWork();
        $unitOfWork->computeChangeSets();

        if ([] !== $unitOfWork->getScheduledEntityInsertions()
            || [] !== $unitOfWork->getScheduledEntityUpdates()
            || [] !== $unitOfWork->getScheduledEntityDeletions()
        ) {
            throw new \LogicException(\sprintf(
                'The bulk member for "%s" left an ORM change scheduled; bulk members issue DBAL statements only, because the end '
                .'of the tenancy transaction flushes whatever a rolled-back member scheduled.',
                $member->resource(),
            ));
        }
    }
}

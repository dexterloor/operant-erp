<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Modules\AuditLog\Infrastructure\RoleAuditWriter;
use App\Modules\UserManagement\Domain\MarginExposure;
use App\Platform\Http\Bulk\BulkArchival;
use App\Platform\Http\Idempotency\IdempotencyEnvelopeStore;
use App\Platform\Http\Idempotency\IdempotencyKeyHash;
use App\Platform\Http\Idempotency\Replay;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\Identity;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * **`CreateBulkRoleAssignment` — give one role to up to 50 people, each exactly as `AssignRole` would** (G5-C1, 2026-10-23;
 * authority `docs/session13/g5-g6-final-red.md` > `g5-g6-bulk-export-design.md` Revision 1 FINAL R1.2 G5-C1 > §4.1–§4.3;
 * builder's decisions `docs/session13/g5-c1-decisions.md` as attacked; replay protocol C-1, shipped first by
 * `CreateBulkArchival`, {@see BulkArchival}).
 *
 * **ONE `TenantContext::run()`, in this order — the order is the design:**
 *  1. {@see RoleAdministrationLock}, the first statement after `set_config` (`PurchaseOrderRowLockTest`'s positional case).
 *  2. The actor re-checked under the lock ({@see RoleAdministrationActor}, `role.assign`): a {@see LockTimeDenial} is
 *     returned and its 403 thrown after the commit (outside `run()`); an inactive actor is 409 `user_role.actor_is_inactive`.
 *     A caller who has lost `role.assign` is refused, never replayed.
 *  3. {@see IdempotencyEnvelopeStore::lookup()}: a live same-request envelope is returned as stored, with no rule re-run (a
 *     role archived since must not turn a committed 200 into a 409); another request under the key 409, an expired key 422.
 *  4. **The role, wholesale — before any member id is read (E-14):** absent → 422 `/role_id unknown_reference`; then
 *     {@see GrantRules::roleVerdict()} (archived, E-15b marker, E-15c beyond the grantor) with `AssignRole`'s sentences;
 *     then E-15e routing ({@see GrantConfirmationPolicy}) → 409 `user_role.needs_owner_confirmation` with the BULK sentence.
 *     **Bulk never proposes and never writes `role_grant_proposal`** (`SECURITY_MODEL.md` §5.2.3).
 *  5. `SAVEPOINT bulk_work`, then the members in ascending id order, each under `SAVEPOINT bulk_member`: the subject read,
 *     {@see GrantRules::firstVerdict()} (AssignRole's phases 1–4, so the wholesale rules pass again), AssignRole's slot B (a
 *     `pending` proposal for the pair, live or expired), then {@see GrantRules::insert()} and `role.assigned` — exactly what
 *     `AssignRole` writes. A member any rule refuses is "not applied": nothing is thrown, so nothing about it escapes. A
 *     `23505` on `user_role_unique` (only an out-of-band writer, which takes no lock, can reach it) → `ROLLBACK TO` then
 *     `RELEASE` → not applied. Anything else is rethrown: a bug or a deadlock is never counted as "not applied".
 *  6. After every member, the unit of work must be clean (final RED L-6's runtime guard, the role path — the ONE copy,
 *     {@see BulkArchival::refuseAPendingOrmChange()}): the end of `run()` flushes, and a rolled-back member's scheduled
 *     ORM change would otherwise be persisted. A refusal escaping a member is a LogicException (500), never "not applied".
 *  7. {@see IdempotencyEnvelopeStore::record()} LAST; 0 rows → `ROLLBACK TO bulk_work` and
 *     {@see IdempotencyEnvelopeStore::replayTheWinner()} on the same connection. Under the role lock two same-key requests
 *     are serialised, so the loser normally replays at step 3; this branch is the protocol's backstop.
 *
 * **No clock read** (deviation from design §4.2 step 4): `AssignRole` reads `clock_timestamp()` only to choose slot B's
 * sentence; a bulk member refused at slot B has no sentence, so the read would decide nothing.
 *
 * **Contention:** `40P01` / `55P03` abort the whole request and are translated OUTSIDE `run()` into 409 `bulk.contention`
 * ({@see BulkArchival::isContention()}, one copy of the rule). **What the answer says** (E-14, V2-C-6 closed by refusal):
 * `{"requested": n, "applied": m, "margin_exposure": bool}` — the role's PC-12 clause-3 warning, the same for every member
 * ({@see MarginExposure}, what `RoleView` serves; G-28's response-contract trigger), and never which members applied.
 * **Cap:** {@see BulkArchival::MAXIMUM_MEMBERS} (final RED M-2: 50 savepoint members + `bulk_work` under the 64 cached
 * subxids). API-20 cannot fire: a bulk only adds grants, so it never lowers the active-owner count.
 */
final readonly class BulkRoleAssignment
{
    public const OPERATION = 'CreateBulkRoleAssignment';

    /** The one permission (static; §4.2). */
    public const PERMISSION = 'role.assign';

    /**
     * `UX` placeholder (design §4.2 step 5): routing is a property of (actor, role), so the whole request is refused and the
     * sentence names the per-person route that exists.
     */
    public const NEEDS_OWNER_CONFIRMATION = 'Giving people this role needs an owner\'s confirmation, so it can\'t be done for many people at once. You can propose it for each person instead.';

    /** The unique index a concurrent out-of-band grant collides on (`Version20260906000100`; `RoleAssignment`'s). */
    private const UNIQUE_INDEX = 'user_role_unique';

    /** AssignRole's slot B, without the sentence: any `pending` proposal for the pair, live or expired (D-7 / Q-4 (f)). */
    private const PENDING_SAME_PAIR = <<<'SQL'
        SELECT 1 FROM role_grant_proposal WHERE user_id = ?::uuid AND role_id = ?::uuid AND status = 'pending'
        SQL;

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private IdempotencyEnvelopeStore $store,
        private RoleAuditWriter $audit,
        private RoleAdministrationActor $actor,
    ) {
    }

    /**
     * @param string                         $roleId        canonical lower-case uuid (the processor's 422s)
     * @param list<string>                   $userIds       canonical lower-case uuids, distinct, 1..50 (the processor's 422s)
     * @param array{0: ?string, 1: ?string} $denialSubject what `PermissionGate::denialSubjectOf()` resolves for this request
     */
    public function run(string $roleId, array $userIds, string $idempotencyKey, ?string $ipAddress, array $denialSubject): Replay
    {
        if ([] === $userIds || \count($userIds) > BulkArchival::MAXIMUM_MEMBERS || \count($userIds) !== \count(array_unique($userIds))) {
            throw new \LogicException('A bulk role assignment runs over 1 to 50 distinct user ids; the processor refuses anything else with a 422.');
        }

        $identity = $this->identities->authenticated();
        $sorted = $userIds;
        sort($sorted, \SORT_STRING);

        $keyHash = IdempotencyKeyHash::of($idempotencyKey);
        $digest = IdempotencyEnvelopeStore::requestDigest(self::OPERATION, $identity->userId, ['role_id' => $roleId, 'user_ids' => $sorted]);
        $tenantId = $this->tenant->tenantId();

        try {
            // ONE closure taking the run's own manager, as `BulkArchival` does: every store call below passes
            // `$em->getConnection()` from it (`EnvelopeStoreCallerConnectionTest`, `g5-c1-decisions-red.md` M-1).
            $outcome = $this->tenants->run($tenantId, function (EntityManagerInterface $em) use ($identity, $tenantId, $roleId, $sorted, $keyHash, $digest, $ipAddress, $denialSubject): Replay|LockTimeDenial {
                // 1.
                RoleAdministrationLock::holdInside($em, $tenantId);

                // 2. Before any read of the role or a member (E-1).
                $actorCodes = $this->actor->reCheckUnderTheLock($em, $identity, $ipAddress, $denialSubject, self::PERMISSION);
                if ($actorCodes instanceof LockTimeDenial) {
                    return $actorCodes;
                }

                $connection = $em->getConnection();

                // 3.
                $replay = $this->store->lookup($connection, self::OPERATION, $keyHash, $digest);
                if (null !== $replay) {
                    return $replay;
                }

                // 4. The role, wholesale. No member id has been read.
                $role = self::judgeTheRole($em, $roleId, $actorCodes);

                // 5.–6.
                $connection->createSavepoint(BulkArchival::WORK_SAVEPOINT);
                $applied = $this->applyMembers($em, $identity, $sorted, $roleId, $role, $actorCodes, $ipAddress);

                // 7.
                $body = json_encode([
                    'requested' => \count($sorted),
                    'applied' => $applied,
                    'margin_exposure' => MarginExposure::of($role['codes']),
                ], \JSON_THROW_ON_ERROR);
                $headers = ['content-type' => BulkArchival::CONTENT_TYPE];

                if ($this->store->record($connection, $tenantId, self::OPERATION, $keyHash, $digest, IdempotencyEnvelopeStore::STORED_STATUS, $body, $headers)) {
                    return new Replay(IdempotencyEnvelopeStore::STORED_STATUS, $body, $headers);
                }

                $connection->rollbackSavepoint(BulkArchival::WORK_SAVEPOINT);

                return $this->store->replayTheWinner($connection, self::OPERATION, $keyHash, $digest);
            });
        } catch (DriverException $failure) {
            // Outside run(): the transaction is already rolled back and the manager closed; this only translates.
            if (!BulkArchival::isContention($failure)) {
                throw $failure;
            }

            // `ApiProblem::contention()` (G-65, 2026-10-23): the bulk family's answer, with the driver failure chained so the
            // logged exception keeps PostgreSQL's deadlock detail.
            throw ApiProblem::contention(BulkArchival::CONTENTION_RULE, BulkArchival::CONTENTION_MESSAGE, $failure);
        }

        if ($outcome instanceof LockTimeDenial) {
            // Outside `run()`, after the commit: the gate's own factory, so the body is byte-identical to its 403.
            throw ApiProblem::permissionDenied($outcome->permissionCode);
        }

        return $outcome;
    }

    /**
     * Step 4: the rules that depend on (actor, role) alone, once, before any member id is read (E-14).
     *
     * @param list<string> $actorCodes lock-time
     *
     * @return array{name: string, live: bool, is_system: bool, codes: list<string>}
     */
    private static function judgeTheRole(EntityManagerInterface $em, string $roleId, array $actorCodes): array
    {
        $role = LiveGrantReader::role($em, $roleId) ?? throw ApiProblem::validationFailed([
            ['pointer' => '/role_id', 'code' => 'unknown_reference', 'message' => 'No role in this workspace has this id.'],
        ]);
        $wholesale = GrantRules::roleVerdict($role, $actorCodes);
        if (null !== $wholesale) {
            throw GrantRules::problem($wholesale, GrantRules::ROUTE_ASSIGN);
        }
        if (GrantConfirmationPolicy::needsConfirmation($actorCodes, $role['codes'])) {
            throw ApiProblem::businessRuleViolated('user_role.needs_owner_confirmation', self::NEEDS_OWNER_CONFIRMATION);
        }

        return $role;
    }

    /**
     * Steps 5–6: the members in ascending id order, each under its own savepoint; returns how many were applied.
     *
     * @param list<string>                                                             $sorted
     * @param array{name: string, live: bool, is_system: bool, codes: list<string>} $role
     * @param list<string>                                                             $actorCodes lock-time
     */
    private function applyMembers(EntityManagerInterface $em, Identity $identity, array $sorted, string $roleId, array $role, array $actorCodes, ?string $ipAddress): int
    {
        $connection = $em->getConnection();
        $applied = 0;
        foreach ($sorted as $userId) {
            $connection->createSavepoint(BulkArchival::MEMBER_SAVEPOINT);
            try {
                if ($this->applyOne($em, $identity, $userId, $roleId, $role, $actorCodes, $ipAddress)) {
                    $connection->releaseSavepoint(BulkArchival::MEMBER_SAVEPOINT);
                    ++$applied;
                } else {
                    $connection->rollbackSavepoint(BulkArchival::MEMBER_SAVEPOINT);
                    $connection->releaseSavepoint(BulkArchival::MEMBER_SAVEPOINT);
                }
            } catch (UniqueConstraintViolationException $raced) {
                if (!str_contains($raced->getMessage(), self::UNIQUE_INDEX)) {
                    throw $raced;
                }
                $connection->rollbackSavepoint(BulkArchival::MEMBER_SAVEPOINT);
                $connection->releaseSavepoint(BulkArchival::MEMBER_SAVEPOINT);
            } catch (ApiProblem $refusal) {
                // `g5-c1-decisions-red.md` L-3: members return verdicts; a refusal escaping one would answer the whole
                // request with that member's own sentence — an E-14 oracle. It is a bug, never "not applied".
                throw new \LogicException('A bulk role-assignment member threw a refusal; members return verdicts (E-14).', 0, $refusal);
            }

            BulkArchival::refuseAPendingOrmChange($em, 'A bulk role-assignment member');
        }

        return $applied;
    }

    /**
     * One member: `AssignRole`'s phases 1–4 and slot B as values, then its insert and audit row. False = not applied, with
     * nothing written (the caller rolls the savepoint back regardless).
     *
     * @param array{name: string, live: bool, is_system: bool, codes: list<string>} $role
     * @param list<string>                                                             $actorCodes lock-time
     */
    private function applyOne(EntityManagerInterface $em, Identity $identity, string $userId, string $roleId, array $role, array $actorCodes, ?string $ipAddress): bool
    {
        // (a) Absent, or another workspace's (RLS hides it): the undifferentiated group.
        $subject = LiveGrantReader::user($em, $userId);
        if (null === $subject) {
            return false;
        }

        // (b) Phases 1–4: V2-R-6, E-15a, E-15b, E-15c, C12, PC-13 over the subject's LIVE roles.
        if (null !== GrantRules::firstVerdict(
            $subject,
            $role,
            $userId,
            $identity->userId,
            $actorCodes,
            null !== LiveGrantReader::grant($em, $userId, $roleId),
            LiveGrantReader::codesHeldBy($em, $userId),
        )) {
            return false;
        }

        // (c) Slot B (R-21): a pending proposal for the pair, live or expired.
        if (false !== $em->getConnection()->fetchOne(self::PENDING_SAME_PAIR, [$userId, $roleId])) {
            return false;
        }

        // (d) Exactly AssignRole's writes: the one INSERT and one `role.assigned` per (actor, subject, role).
        GrantRules::insert($em, $identity->tenantId, $userId, $roleId);
        $this->audit->recordAssigned(
            tenantId: $identity->tenantId,
            actorUserId: $identity->userId,
            actorLabel: $identity->actorLabel,
            subjectUserId: $userId,
            roleId: $roleId,
            ipAddress: $ipAddress,
        );

        return true;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Modules\AuditLog\Infrastructure\RoleAuditWriter;
use App\Modules\UserManagement\Application\Contract\OwnerPredicate;
use App\Modules\UserManagement\Domain\LastOwnerFloor;
use App\Modules\UserManagement\Infrastructure\Entity\AppUser;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\Identity;
use App\SharedKernel\ValueObject\RoleEventMetadata;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * **E-15g's mechanics — ONE copy, shared by every writer of a person's liveness** (G4; `docs/session13/g4-users-design.md`
 * §5.2, Revision 1 FINAL R1.3 D-4 / D-7; `g4-users-final-red.md` M-1, M-2(a), L-1). `UpdateUser` (C1) is its first caller;
 * `DeleteUser` (C2) and `RestoreUser` (C3) reuse it. Every method runs inside the caller's `TenantContext::run()`, on the
 * entity manager whose connection already holds `RoleAdministrationLock` and whose actor was re-checked under it
 * (`RoleAdministrationActor`). Nothing here takes the lock or reads the actor's codes: the caller passes the lock-time set.
 *
 *  - {@see self::loadUnderTheLock()} — the person's `AppUser`, `find()` then an UNCONDITIONAL
 *    `refresh(…, PESSIMISTIC_WRITE)` (final RED L-1, M-2(a)): one path, no "if managed" branch to get wrong. The test rig
 *    and `LoginRecorder` routinely leave a STALE managed instance in the identity map (one kernel per test, `disableReboot()`;
 *    the map is cleared only on a tenant change), and a decision taken from it would write an audited no-op. The `FOR UPDATE`
 *    also stops a concurrent writer that does not take the role lock (`ChangePassword`, `LoginRecorder`, later Mail-C2's reset)
 *    from committing a stamp between this read and the flush, which `max(existing, $at)` would then lower (M-2).
 *    **Live users only.** The app entity manager's `soft_delete` filter hides a deleted row from `find()` AND from
 *    `refresh()` — the latter silently, leaving a stale entity (final RED M-1). C1 and C2 answer a deleted subject from the
 *    caller's DBAL read (409 `app_user.is_archived`) BEFORE any load, so they never reach this with one. **C3 `RestoreUser`
 *    must disable the filter around its OWN load and refresh only** (with a `finally`), assert `isDeleted()`, and must not
 *    hoist that toggle into this shared path.
 *  - {@see self::refuseSelf()} — E-15a, unconditional, owners included (E-15g's ruling).
 *  - {@see self::refuseNonOwnerStrip()} — E-15b over the STORED codes of every row a strip will remove, archived role or
 *    not, on EVERY strip path (R1.3 D-4, first RED M-4): E-15g's consequence clause makes revoking an `owner` role a revocation
 *    under E-15b. Through `OwnerPredicate::holdsMarker()`, the one answer.
 *  - {@see self::refuseLastOwner()} — API-20 (V2-S-6): switching a person off may not leave no active marker holder.
 *  - {@see self::strip()} — `DELETE FROM user_role WHERE user_id = ?` (the `RevokeRole` grant), returning the role ids.
 *  - {@see self::lapse()} — every pending proposal FOR the person (`subject_removed`) then BY the person (`proposer_removed`),
 *    two bulk statements carrying `AND status = 'pending'` (the decided-is-final trigger refuses any other), dated
 *    `GREATEST(clock_timestamp(), proposed_at)` with **no** 60-second refusal (D-8: a planted future-dated proposal must not
 *    block a deactivation; `role_grant_proposal_decided_not_before_proposed` still holds). `RoleGrantProposal::lapse()` is the
 *    other implementation of `pending → lapsed` and keeps its tolerance (final RED L-5): one row, one decider, on touch.
 *  - {@see self::recordStripAndLapses()} — one `role.revoked` per stripped grant with `revoke_reason: subject_removed`, then one
 *    `role.assignment_lapsed` per lapsed proposal, written AFTER the caller's `user.*` cause row (design §5.3's row order).
 *
 * **Two clocks, two facts, nothing compares them** (design §5.2): the PHP clock (`$at`) stamps `app_user` (the stamp is
 * compared with a token's PHP-minted `iat`); the database clock dates the proposal decisions and the audit rows (§5.2.6,
 * E-15e's *"a decision timestamp taken from a PHP clock"* concerns `role_grant_proposal`, which stays on the DB clock).
 *
 * `strip()` and `lapse()` write role-administration tables, so the role lock-first scan derives them as writers of their
 * own; `PurchaseOrderRowLockTest::userLivenessWritersAreCalledOnlyFromLockFirstCheckedPaths` holds their callers to the
 * operations that have a positional case.
 */
final readonly class UserLiveness
{
    /** First RED / design §5.4 (codes `BE-LEAD`'s). PLACEHOLDER copy — `UX` to confirm. */
    public const ACTOR_IS_SUBJECT = 'Nobody can switch off their own account. Ask someone else who manages people to do it.';

    /** E-15b (E-15g's consequence clause). PLACEHOLDER — `UX` to confirm. */
    public const MARKER_NEEDS_OWNER = 'Only an owner can do this, because this person holds a role that makes them an owner.';

    /** API-20. PLACEHOLDER — `UX` to confirm (`RoleRevocation`'s sentence, which `UX` wrote for the same floor). */
    public const LAST_OWNER = 'This would leave the workspace with no owner. Make someone else an owner first.';

    private const STRIP = 'DELETE FROM user_role WHERE user_id = ?::uuid RETURNING role_id::text AS role_id';

    /** FOR the person first, then BY — the order `role.assignment_lapsed` rows are written in. */
    private const LAPSE_FOR = <<<'SQL'
        UPDATE role_grant_proposal
           SET status = 'lapsed', lapse_reason = 'subject_removed',
               decided_at = GREATEST(clock_timestamp(), proposed_at), updated_at = clock_timestamp()
         WHERE user_id = ?::uuid AND status = 'pending'
        RETURNING id::text AS id, role_id::text AS role_id, user_id::text AS user_id, lapse_reason
        SQL;

    private const LAPSE_BY = <<<'SQL'
        UPDATE role_grant_proposal
           SET status = 'lapsed', lapse_reason = 'proposer_removed',
               decided_at = GREATEST(clock_timestamp(), proposed_at), updated_at = clock_timestamp()
         WHERE proposed_by_user_id = ?::uuid AND status = 'pending'
        RETURNING id::text AS id, role_id::text AS role_id, user_id::text AS user_id, lapse_reason
        SQL;

    public function __construct(private RoleAuditWriter $roleAudit)
    {
    }

    /**
     * The person's entity as the lock sees it, row-locked. The caller has already established, by a DBAL read under the lock,
     * that the row exists and is not deleted.
     */
    public static function loadUnderTheLock(EntityManagerInterface $em, string $userId): AppUser
    {
        $user = $em->find(AppUser::class, $userId)
            ?? throw new \LogicException('The person read live under the role lock is not loadable: a deleted row reached UserLiveness (final RED M-1).');
        // Unconditional (final RED L-1): `find()` returns a managed instance — possibly a stale one from earlier in this
        // kernel — without SQL, so `contains()` would always be true and an "if managed" branch could never decide anything.
        $em->refresh($user, LockMode::PESSIMISTIC_WRITE);

        return $user;
    }

    /** E-15a: nobody switches off, deletes or strips themselves — owners included. */
    public static function refuseSelf(Identity $identity, string $userId): void
    {
        if (strtolower($identity->userId) === strtolower($userId)) {
            throw ApiProblem::businessRuleViolated('app_user.actor_is_subject', self::ACTOR_IS_SUBJECT);
        }
    }

    /**
     * E-15b over every row the strip will remove (stored codes, archived role or not).
     *
     * @param list<array{id: string, role_id: string, role_codes: list<string>}> $grants   {@see LiveGrantReader::allGrantsOf()}
     * @param list<string>                                                        $actorCodes the actor's LOCK-time codes
     */
    public static function refuseNonOwnerStrip(array $grants, array $actorCodes): void
    {
        $stored = array_merge([], ...array_map(static fn (array $grant): array => $grant['role_codes'], $grants));
        if (OwnerPredicate::holdsMarker($stored) && !OwnerPredicate::holdsMarker($actorCodes)) {
            throw ApiProblem::businessRuleViolated('app_user.marker_needs_owner', self::MARKER_NEEDS_OWNER);
        }
    }

    /** API-20: the person stops counting as an active marker holder. */
    public static function refuseLastOwner(EntityManagerInterface $em, string $userId): void
    {
        if (LastOwnerFloor::refuses(
            LiveGrantReader::activeMarkerHolderCount($em),
            LiveGrantReader::activeMarkerHolderCountExcludingUser($em, $userId),
        )) {
            throw ApiProblem::businessRuleViolated('app_user.last_owner', self::LAST_OWNER);
        }
    }

    /**
     * E-15g's strip: every `user_role` row of the person, in one statement.
     *
     * @return list<string> the role ids removed, in role-id order
     */
    public function strip(EntityManagerInterface $em, string $userId): array
    {
        $roleIds = array_map(strval(...), $em->getConnection()->fetchFirstColumn(self::STRIP, [$userId]));
        sort($roleIds);

        return $roleIds;
    }

    /**
     * E-15g's lapse: every pending proposal for, then by, the person.
     *
     * @return list<array{id: string, role_id: string, user_id: string, lapse_reason: string}>
     */
    public function lapse(EntityManagerInterface $em, string $userId): array
    {
        $connection = $em->getConnection();
        $lapsed = [];
        foreach ([self::LAPSE_FOR, self::LAPSE_BY] as $statement) {
            $rows = $connection->fetchAllAssociative($statement, [$userId]);
            usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));
            foreach ($rows as $row) {
                $lapsed[] = [
                    'id' => (string) $row['id'],
                    'role_id' => (string) $row['role_id'],
                    'user_id' => (string) $row['user_id'],
                    'lapse_reason' => (string) $row['lapse_reason'],
                ];
            }
        }

        return $lapsed;
    }

    /**
     * The strip's and the lapse's rows, after the caller's `user.*` row: one `role.revoked` per removed grant (subject the
     * person, `revoke_reason: subject_removed`), then one `role.assignment_lapsed` per proposal (subject the proposal's
     * `user_id`). The actor is the requester; every row carries the request's IP.
     *
     * @param list<string>                                                                       $strippedRoleIds
     * @param list<array{id: string, role_id: string, user_id: string, lapse_reason: string}> $lapsed
     */
    public function recordStripAndLapses(Identity $identity, string $userId, array $strippedRoleIds, array $lapsed, ?string $ipAddress): void
    {
        foreach ($strippedRoleIds as $roleId) {
            $this->roleAudit->recordRevoked(
                tenantId: $identity->tenantId,
                actorUserId: $identity->userId,
                actorLabel: $identity->actorLabel,
                subjectUserId: $userId,
                roleId: $roleId,
                ipAddress: $ipAddress,
                revokeReason: RoleEventMetadata::REVOKE_REASON_SUBJECT_REMOVED,
            );
        }
        foreach ($lapsed as $proposal) {
            $this->roleAudit->recordLapsed(
                tenantId: $identity->tenantId,
                actorUserId: $identity->userId,
                actorLabel: $identity->actorLabel,
                subjectUserId: $proposal['user_id'],
                roleId: $proposal['role_id'],
                proposalId: $proposal['id'],
                lapseReason: $proposal['lapse_reason'],
                ipAddress: $ipAddress,
            );
        }
    }
}

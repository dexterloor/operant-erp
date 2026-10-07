<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Modules\AuditLog\Infrastructure\RoleAuditWriter;
use App\Modules\UserManagement\Application\Contract\OwnerPredicate;
use App\Modules\UserManagement\Domain\LastOwnerFloor;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\Identity;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`RevokeRole` — take one role away from one user, never from the last owner** (roles slice 2, C2;
 * `docs/session13/roles-s2-design.md` §5.1 as amended by `roles-s2-spar.md` §2 and `roles-s2-sec.md` §Acknowledgement).
 *
 * One transaction, in this order — the order is the design, and two of its steps are integrity properties:
 *  1. **`RoleAdministrationLock`**, the first statement after `set_config` (§5.2.5; positional test in
 *     `PurchaseOrderRowLockTest`). Everything below reads what the previous holder committed.
 *  2. **The actor's permission, re-derived under the lock (S-6)** from `LiveGrantReader`, BEFORE any read of the subject
 *     (E-1: otherwise a demoted actor would get 404 for an absent user and 403 for a real one — an existence oracle).
 *     Missing → `SEC`'s S-2: the `authz.permission.denied` row is written HERE, through the gate's own writer and the
 *     gate's own subject resolver (`PermissionGate::denialSubjectOf()`, passed in by the processor), a
 *     {@see LockTimeDenial} is RETURNED, the transaction commits having written nothing else, and the 403 is thrown
 *     outside `run()`. Never thrown across `run()` (P13: the EM closes and the row is lost).
 *  3. **An inactive actor** → 409 `user_role.actor_is_inactive`, no row — AFTER step 2, so an actor both inactive and
 *     without the code gets the 403 and its row (`SEC` §2.4). When API-O-22 is ruled this refusal takes its status.
 *  4. **The subject:** `{id}` not a uuid, absent, another workspace's or soft-deleted → 404; `{roleId}` not a uuid, or no
 *     grant `(user, role)` → 404. An archived role's grant is revocable.
 *  5. **E-15a:** nobody revokes their own role → 409 `user_role.actor_is_subject` (this is also how the last owner is kept).
 *  6. **E-15b:** a role whose STORED codes confer the marker — archived or not (D-15: `RestoreRole` could revive it) — is
 *     revoked only by an actor holding the marker under the lock → 409 `user_role.marker_needs_owner`.
 *  7. **API-20:** `LastOwnerFloor::refuses(before, after)` over active marker holders → 409 `user_role.last_owner`.
 *  8. `DELETE FROM user_role` — the project's first `DELETE` grant (`Version20260929000300`), exactly this statement.
 *  9. `role.revoked`, subject the user, `{role_id}`, the request's IP, `clock_timestamp()`.
 *
 * Steps 2–3 are {@see RoleAdministrationActor} since C3 — one copy of the S-2 mechanism, shared with `AssignRole`.
 *
 * Every decision reads `LiveGrantReader` on the locked connection; every caller decision about the marker goes through
 * `OwnerPredicate::holdsMarker()` over that lock-time set. No PHP clock stamps or decides anything.
 */
final readonly class RoleRevocation
{
    /** The permission this operation requires — the gate's, re-derived under the lock. */
    public const REQUIRED_PERMISSION = 'role.assign';

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private RoleAuditWriter $audit,
        private RoleAdministrationActor $actor,
    ) {
    }

    /**
     * @param array{0: ?string, 1: ?string} $denialSubject what `PermissionGate::denialSubjectOf()` resolves for this request
     */
    public function revoke(string $userId, string $roleId, ?string $ipAddress, array $denialSubject): void
    {
        $identity = $this->identities->authenticated();

        $outcome = $this->tenants->run(
            $this->tenant->tenantId(),
            fn (EntityManagerInterface $em): ?LockTimeDenial => $this->revokeInside($em, $identity, $userId, $roleId, $ipAddress, $denialSubject),
        );

        if ($outcome instanceof LockTimeDenial) {
            // Outside `run()`, after the commit: the gate's own factory, so the body is byte-identical to its 403.
            throw ApiProblem::permissionDenied($outcome->permissionCode);
        }
    }

    /** @param array{0: ?string, 1: ?string} $denialSubject */
    private function revokeInside(EntityManagerInterface $em, Identity $identity, string $userId, string $roleId, ?string $ipAddress, array $denialSubject): ?LockTimeDenial
    {
        $connection = $em->getConnection();

        // 1.
        RoleAdministrationLock::holdInside($em, $this->tenant->tenantId());

        // 2–3. Before any read of the subject (E-1): the one shared re-check (`RoleAdministrationActor`, extracted in C3).
        $actorCodes = $this->actor->reCheckUnderTheLock($em, $identity, $ipAddress, $denialSubject, self::REQUIRED_PERMISSION);
        if ($actorCodes instanceof LockTimeDenial) {
            return $actorCodes;
        }

        // 4.
        if (!Uuid::isValid($userId) || !Uuid::isValid($roleId)) {
            throw ApiProblem::notFound();
        }
        $userId = Uuid::fromString($userId)->toRfc4122();
        $roleId = Uuid::fromString($roleId)->toRfc4122();

        $subject = LiveGrantReader::user($em, $userId);
        if (null === $subject || $subject['deleted']) {
            throw ApiProblem::notFound();
        }
        $grant = LiveGrantReader::grant($em, $userId, $roleId);
        if (null === $grant) {
            throw ApiProblem::notFound();
        }

        // 5.
        if ($userId === Uuid::fromString($identity->userId)->toRfc4122()) {
            throw ApiProblem::businessRuleViolated('user_role.actor_is_subject', 'Nobody can take a role away from themselves. Ask someone else who manages roles to do it.');
        }

        // 6.
        if (OwnerPredicate::holdsMarker($grant['role_codes']) && !OwnerPredicate::holdsMarker($actorCodes)) {
            throw ApiProblem::businessRuleViolated('user_role.marker_needs_owner', 'Only an owner can take this role away, because it makes the person an owner.');
        }

        // 7.
        if (LastOwnerFloor::refuses(
            LiveGrantReader::activeMarkerHolderCount($em),
            LiveGrantReader::activeMarkerHolderCountExcluding($em, $grant['id']),
        )) {
            throw ApiProblem::businessRuleViolated('user_role.last_owner', 'This would leave the workspace with no owner. Make someone else an owner first.');
        }

        // 8.
        $connection->executeStatement('DELETE FROM user_role WHERE id = ?::uuid', [$grant['id']]);

        // 9.
        $this->audit->recordRevoked(
            tenantId: $identity->tenantId,
            actorUserId: $identity->userId,
            actorLabel: $identity->actorLabel,
            subjectUserId: $userId,
            roleId: $roleId,
            ipAddress: $ipAddress,
        );

        return null;
    }
}

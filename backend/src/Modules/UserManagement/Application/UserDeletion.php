<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Modules\AuditLog\Infrastructure\UserAuditWriter;
use App\Modules\UserManagement\Infrastructure\Entity\AppUser;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\Identity;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`DeleteUser` — soft-delete a person, taking their roles and open requests with them** (G4-C2;
 * `docs/session13/g4-users-design.md` §6, Revision 1 FINAL; E-15g). `DELETE /users/{id}`, `app_user.delete`, **204**.
 * `Idem = no`: a repeat finds a deleted person and is 409 `app_user.is_archived` (`DeleteRole`'s precedent).
 *
 * `UserUpdate`'s transaction, with these differences (design §6):
 *  1. `RoleAdministrationLock` first; 2. the actor re-checked under the lock with **`app_user.delete`**;
 *  3. `{id}` not a uuid / absent / another workspace's → 404; already deleted → 409 `app_user.is_archived` — a DBAL read, so a
 *     deleted row never reaches the entity load (final RED M-1: the soft-delete filter would hide it);
 *  4. E-15a: nobody deletes themselves, owners included → 409 `app_user.actor_is_subject`;
 *  5. E-15b over the stored codes of every grant the strip removes → 409 `app_user.marker_needs_owner` for a non-owner;
 *  6. API-20: the floor counts the person out whether or not they are active — and is a no-op for an inactive person, who
 *     was never counted (`LiveGrantReader::activeMarkerHolderCountExcludingUser`);
 *  7. the strip (every `user_role` row, `revoke_reason: subject_removed`) and the lapse (both statements, always);
 *  8. `AppUser::delete($at)` + flush: `deleted_at`, `sessions_valid_after` (monotone), `updated_at`; **`is_active` unchanged**
 *     (D-6);
 *  9. `user.deleted` (`{}`, IP) first, then the `role.revoked` and `role.assignment_lapsed` rows.
 * Every refusal comes before any write. Lock order as `UserUpdate`'s (`ARCHITECTURE.md` W-5).
 */
final readonly class UserDeletion
{
    /** The permission this operation requires — the gate's, re-derived under the lock. */
    public const REQUIRED_PERMISSION = 'app_user.delete';

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private RoleAdministrationActor $actor,
        private UserLiveness $liveness,
        private UserAuditWriter $audit,
        private ClockInterface $clock,
    ) {
    }

    /** @param array{0: ?string, 1: ?string} $denialSubject what `PermissionGate::denialSubjectOf()` resolves for this request */
    public function delete(string $userId, ?string $ipAddress, array $denialSubject): void
    {
        $identity = $this->identities->authenticated();
        $at = $this->clock->now();

        $outcome = $this->tenants->run(
            $this->tenant->tenantId(),
            fn (EntityManagerInterface $em): ?LockTimeDenial => $this->deleteInside($em, $identity, $userId, $ipAddress, $denialSubject, $at),
        );

        if ($outcome instanceof LockTimeDenial) {
            // Outside `run()`, after the commit: the gate's own factory, so the body is byte-identical to its 403.
            throw ApiProblem::permissionDenied($outcome->permissionCode);
        }
    }

    /** @param array{0: ?string, 1: ?string} $denialSubject */
    private function deleteInside(EntityManagerInterface $em, Identity $identity, string $userId, ?string $ipAddress, array $denialSubject, \DateTimeImmutable $at): ?LockTimeDenial
    {
        // 1.
        RoleAdministrationLock::holdInside($em, $this->tenant->tenantId());

        // 2. Before any read of the person (E-1).
        $actorCodes = $this->actor->reCheckUnderTheLock($em, $identity, $ipAddress, $denialSubject, self::REQUIRED_PERMISSION);
        if ($actorCodes instanceof LockTimeDenial) {
            return $actorCodes;
        }

        // 3.
        if (!Uuid::isValid($userId)) {
            throw ApiProblem::notFound();
        }
        $userId = Uuid::fromString($userId)->toRfc4122();
        $subject = LiveGrantReader::user($em, $userId) ?? throw ApiProblem::notFound();
        if ($subject['deleted']) {
            throw ApiProblem::businessRuleViolated('app_user.is_archived', UserUpdate::IS_ARCHIVED);
        }

        // 4.
        UserLiveness::refuseSelf($identity, $userId);

        // 5.
        $grants = LiveGrantReader::allGrantsOf($em, $userId);
        UserLiveness::refuseNonOwnerStrip($grants, $actorCodes);

        // 6.
        UserLiveness::refuseLastOwner($em, $userId);

        // The role lock-first scan derives a liveness writer from a method that NAMES `AppUser` (`X::class` or `new X`) and
        // calls a liveness mutator; naming it here is what makes this method a derived writer (G4-C1 deviation D-3).
        $user = UserLiveness::loadUnderTheLock($em, $userId);
        \assert(is_a($user, AppUser::class));

        // 7.
        $stripped = $this->liveness->strip($em, $userId);
        $lapsed = $this->liveness->lapse($em, $userId);

        // 8.
        $user->delete($at);
        $em->flush();

        // 9.
        $this->audit->recordDeleted($identity->tenantId, $identity->userId, $identity->actorLabel, $userId, $ipAddress);
        $this->liveness->recordStripAndLapses($identity, $userId, $stripped, $lapsed, $ipAddress);

        return null;
    }
}

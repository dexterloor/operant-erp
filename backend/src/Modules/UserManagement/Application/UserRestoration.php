<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Modules\AuditLog\Infrastructure\UserAuditWriter;
use App\Modules\UserManagement\Infrastructure\Entity\AppUser;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\Identity;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\SoftDeleteFilter;
use App\Platform\Tenancy\TenantContext;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`RestoreUser` — bring a deleted person back, with no roles and no old sessions** (G4-C3; `docs/session13/g4-users-design.md`
 * §7, Revision 1 FINAL R1.2 G4-C3; `g4-users-final-red.md` M-1, L-2; E-15g: *"reactivation restores nothing"*).
 * `POST /users/{id}/restoration`, `app_user.delete` (undoing a delete is the authority that makes one), **200** + `GetUser`'s
 * body. `guard:app_user.deleted_at`: a live person is 409 `app_user.is_not_archived`; no `Idempotency-Key` (`RestoreItem`'s
 * reason).
 *
 * One transaction:
 *  1. `RoleAdministrationLock` first; 2. the actor re-checked under the lock with `app_user.delete`;
 *  3. `{id}` not a uuid / absent / another workspace's → 404; live → 409 `app_user.is_not_archived` (a DBAL read);
 *  4. **email collision:** a LIVE person in the workspace already holds this email → 409 `app_user.email_taken` (the live-only
 *     unique `app_user_tenant_email_live` would refuse the restore). The `23505` on that index is translated the same way as a
 *     backstop, outside `run()` (V2-R-12b family). Today only SQL or seeding can create the clash (`CreateUser` is unbuilt);
 *  5. the **legacy strip** (D-4): any `user_role` row a deleted person still holds (only a database edit leaves one) is removed
 *     and recorded with `revoke_reason: subject_removed` — and **E-15b binds it** (R1.2 C3 M-4): a non-owner is refused
 *     409 `app_user.marker_needs_owner` if any of those rows' stored codes confer the marker;
 *  6. the **lapse, both statements** (R1.2 C3 L-7): any pending proposal for or by the person (again only a database edit
 *     leaves one — `DeleteUser` lapsed them all);
 *  7. `AppUser::restore($at)` + flush: `deleted_at = NULL`, the session stamp raised to `$at` (monotone) and `updated_at`.
 *     `is_active` is whatever it was (D-6): the person comes back as they were, with no roles;
 *  8. `user.restored` (`{}`, SECURITY, IP) first, then any strip / lapse rows; 9. the body.
 * **No E-15a** (a deleted person cannot authenticate, so actor = subject cannot happen) and **no floor** (a restore adds no
 * owner).
 *
 * **The entity load (final RED M-1):** the app entity manager's `soft_delete` filter hides a deleted `app_user` from `find()`
 * and — silently — from `refresh()`, which would leave a stale entity. So THIS class, and only this class, disables the filter
 * around its own load-and-refresh and re-enables it in a `finally`, then asserts the entity really is deleted; if a concurrent
 * writer that does not take the role lock had restored it meanwhile, the answer is the step-3 refusal. The toggle is not hoisted
 * into `UserLiveness` (its other callers must never load a deleted row).
 */
final readonly class UserRestoration
{
    /** The permission this operation requires — the gate's, re-derived under the lock. */
    public const REQUIRED_PERMISSION = 'app_user.delete';

    /** PLACEHOLDER — `UX` to confirm. */
    public const NOT_ARCHIVED = 'This person\'s account hasn\'t been deleted, so there is nothing to restore.';

    /** PLACEHOLDER — `UX` to confirm. Names no person and no email (a `.delete` seat may lack `app_user.view`). */
    public const EMAIL_TAKEN = 'Someone else in this workspace now uses this person\'s email address, so the account can\'t be restored.';

    private const EMAIL_UNIQUE = 'app_user_tenant_email_live';

    private const EMAIL_IN_USE = <<<'SQL'
        SELECT 1
          FROM app_user other
          JOIN app_user me ON me.id = ?::uuid
         WHERE other.deleted_at IS NULL AND other.id <> me.id AND other.email = me.email
         LIMIT 1
        SQL;

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private RoleAdministrationActor $actor,
        private UserLiveness $liveness,
        private UserAuditWriter $audit,
        private UserReader $users,
        private ClockInterface $clock,
    ) {
    }

    /** @param array{0: ?string, 1: ?string} $denialSubject what `PermissionGate::denialSubjectOf()` resolves for this request */
    public function restore(string $userId, ?string $ipAddress, array $denialSubject): UserView
    {
        $identity = $this->identities->authenticated();
        $at = $this->clock->now();

        try {
            $outcome = $this->tenants->run(
                $this->tenant->tenantId(),
                fn (EntityManagerInterface $em): UserView|LockTimeDenial => $this->restoreInside($em, $identity, $userId, $ipAddress, $denialSubject, $at),
            );
        } catch (UniqueConstraintViolationException $e) {
            // Outside `run()`: the failed statement aborted the transaction and closed the entity manager.
            if (str_contains($e->getMessage(), self::EMAIL_UNIQUE)) {
                throw self::emailTaken();
            }

            throw $e;
        }

        if ($outcome instanceof LockTimeDenial) {
            // Outside `run()`, after the commit: the gate's own factory, so the body is byte-identical to its 403.
            throw ApiProblem::permissionDenied($outcome->permissionCode);
        }

        return $outcome;
    }

    /** @param array{0: ?string, 1: ?string} $denialSubject */
    private function restoreInside(EntityManagerInterface $em, Identity $identity, string $userId, ?string $ipAddress, array $denialSubject, \DateTimeImmutable $at): UserView|LockTimeDenial
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
        if (!$subject['deleted']) {
            throw self::notArchived();
        }

        // 4.
        if (false !== $em->getConnection()->fetchOne(self::EMAIL_IN_USE, [$userId])) {
            throw self::emailTaken();
        }

        // 5. (E-15b first, before any write.)
        $grants = LiveGrantReader::allGrantsOf($em, $userId);
        if ([] !== $grants) {
            UserLiveness::refuseNonOwnerStrip($grants, $actorCodes);
        }

        // The entity, under the lock, with the soft-delete filter off for this load only (final RED M-1). `AppUser::class` is
        // named here so the role lock-first scan derives this method as a liveness writer (G4-C1 deviation D-3).
        $user = $this->loadDeleted($em, $userId);
        \assert(is_a($user, AppUser::class));

        $stripped = [] !== $grants ? $this->liveness->strip($em, $userId) : [];
        // 6.
        $lapsed = $this->liveness->lapse($em, $userId);

        // 7.
        $user->restore($at);
        $em->flush();

        // 8.
        $this->audit->recordRestored($identity->tenantId, $identity->userId, $identity->actorLabel, $userId, $ipAddress);
        $this->liveness->recordStripAndLapses($identity, $userId, $stripped, $lapsed, $ipAddress);

        // 9.
        return $this->users->get($userId) ?? throw new \LogicException('The person restored under the lock is not visible to its own transaction.');
    }

    private function loadDeleted(EntityManagerInterface $em, string $userId): AppUser
    {
        $filters = $em->getFilters();
        $wasEnabled = $filters->isEnabled(SoftDeleteFilter::NAME);
        if ($wasEnabled) {
            $filters->disable(SoftDeleteFilter::NAME);
        }
        try {
            $user = UserLiveness::loadUnderTheLock($em, $userId);
        } finally {
            if ($wasEnabled) {
                $filters->enable(SoftDeleteFilter::NAME);
            }
        }
        if (!$user->isDeleted()) {
            // The DBAL read under the lock saw it deleted; a writer that does not take the role lock restored it since.
            throw self::notArchived();
        }

        return $user;
    }

    private static function notArchived(): ApiProblem
    {
        return ApiProblem::businessRuleViolated('app_user.is_not_archived', self::NOT_ARCHIVED);
    }

    private static function emailTaken(): ApiProblem
    {
        return ApiProblem::businessRuleViolated('app_user.email_taken', self::EMAIL_TAKEN);
    }
}

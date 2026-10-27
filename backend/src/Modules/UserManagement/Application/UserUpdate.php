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
 * **`UpdateUser` — rename a person, switch them off, or switch them back on** (G4-C1; `docs/session13/g4-users-design.md`
 * §5, Revision 1 FINAL R1.2 G4-C1 / R1.3; `g4-users-final-red.md` M-2(a), L-1, L-2, L-7; owner ruling RO-U1 (a); E-15g).
 *
 * `PATCH /users/{id}`, `app_user.edit`, any subset of `{display_name, is_active}`. **A request that changes nothing is 200 with
 * the person and no write and no audit row** (`UpdateRole`'s precedent; that is why §4.2's `Idem = no` is sound). **A refused
 * request changes nothing** (final RED L-7): every refusal comes before any write, so `{display_name, is_active: false}`
 * refused by E-15a, E-15b or API-20 leaves the name unchanged too.
 *
 * One transaction, in this order (design §5.2; every statement as `erp_app` through `TenantContext::run()`):
 *  1. `RoleAdministrationLock` — the FIRST statement, **even for a rename-only patch** (the method writes `is_active`, so the
 *     lock-first scan sees it; E-15g binds the writer, not the request's shape).
 *  2. The actor re-checked under the lock with **`app_user.edit`** (`RoleAdministrationActor`): 403 + denial row
 *     `('app_user', {id})` / 409 `user_role.actor_is_inactive` (reachable only when `is_active` is cleared outside `UpdateUser`
 *     — SQL — while the request waits; first RED L-1). The returned set is the LOCK-time set every later decision reads.
 *  3. `{id}` not a uuid, absent, or another workspace's → 404; deleted → 409 `app_user.is_archived` (D-5, `UpdateRole`'s
 *     archived precedent) — a DBAL read (`LiveGrantReader::user()`), before any entity load (final RED M-1).
 *  4. Member validation (422): `display_name` (`RoleDefinitionFields`' name rule: trimmed, not blank, ≤ 100, no invisible or
 *     control character; not unique, D-12); `is_active` a boolean.
 *  5. The person's `AppUser`, loaded and row-locked under the lock (`UserLiveness::loadUnderTheLock()`, unconditional
 *     `refresh(…, PESSIMISTIC_WRITE)`); the delta is read off THAT entity, never off anything hydrated before the lock (M-3).
 *     `rename` = present and different after trim; `deactivate` = `is_active: false` on an active person; `reactivate` =
 *     `is_active: true` on an inactive one; a repeat `is_active: false` on an inactive person is no transition.
 *  6. Every grant the person holds (`LiveGrantReader::allGrantsOf()`). **A strip runs whenever the request leaves the person
 *     non-live or brings them back** (R1.3 D-4): on a deactivation, and on a repeat-inactive or a reactivation that finds
 *     leftover grants (only a database edit leaves them).
 *  7. E-15a (`UserLiveness::refuseSelf()`) when the request deactivates or strips — a rename of oneself is allowed.
 *  8. E-15b over the stripped rows' STORED codes, on every strip path (`UserLiveness::refuseNonOwnerStrip()`).
 *  9. API-20 on a deactivation (`UserLiveness::refuseLastOwner()`).
 * 10. Strip (`DELETE FROM user_role`), then — on a deactivation only — lapse every pending proposal for and by the person.
 * 11. The mutators (`deactivate($at)` / `reactivate($at)` / `rename($name, $at)`) and one flush: `is_active`, `display_name`,
 *     `sessions_valid_after` (monotone, `max(existing, $at)`), `updated_at` — the `Version20261023000100` column grant plus
 *     H0's `updated_at`. `$at` is ONE value per request from the injected `Psr\Clock\ClockInterface` (§4.3's stamp rule).
 * 12. Audit rows, same transaction: the `user.*` cause row(s) first — `user.deactivated` / `user.reactivated`, then
 *     `user.updated {fields: ["display_name"]}` on a rename — then one `role.revoked` (`revoke_reason: subject_removed`) per
 *     stripped grant and one `role.assignment_lapsed` per lapsed proposal. **A repeat-inactive strip writes the
 *     `role.revoked` rows only**, with no `user.*` row: there was no transition, and the actor is the trail (D-16).
 * 13. The body: `GetUser`'s `User` (`UserReader::get()`, re-entrant, in this transaction) — the same for every caller (PC-17).
 *
 * Lock order: the role advisory lock → the `app_user` row (step 5) → `user_role` rows (step 10) → `role_grant_proposal`
 * rows → `audit_log` INSERTs. `ChangePassword`, `LoginRecorder` (and Mail-C2) take the `app_user` row lock and nothing this
 * class takes after it, so no cycle (`ARCHITECTURE.md` W-5).
 *
 * **The refusal sentences are PLACEHOLDERS** (`UX`'s, V2-C-7), pinned as literals in `UserUpdateTest`.
 */
final readonly class UserUpdate
{
    /** The permission this operation requires — the gate's, re-derived under the lock. */
    public const REQUIRED_PERMISSION = 'app_user.edit';

    /** D-5. PLACEHOLDER — `UX` to confirm. Names no unbuilt operation (the session-33 (a3) shipped-copy lesson). */
    public const IS_ARCHIVED = 'This person\'s account has been deleted, so it can\'t be changed.';

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

    /**
     * @param array<string, mixed>          $submitted     the PATCH members present, keyed `display_name` / `is_active`
     * @param array{0: ?string, 1: ?string} $denialSubject what `PermissionGate::denialSubjectOf()` resolves for this request
     */
    public function update(string $userId, array $submitted, ?string $ipAddress, array $denialSubject): UserView
    {
        $identity = $this->identities->authenticated();
        $at = $this->clock->now();

        $outcome = $this->tenants->run(
            $this->tenant->tenantId(),
            fn (EntityManagerInterface $em): UserView|LockTimeDenial => $this->updateInside($em, $identity, $userId, $submitted, $ipAddress, $denialSubject, $at),
        );

        if ($outcome instanceof LockTimeDenial) {
            // Outside `run()`, after the commit: the gate's own factory, so the body is byte-identical to its 403.
            throw ApiProblem::permissionDenied($outcome->permissionCode);
        }

        return $outcome;
    }

    /**
     * @param array<string, mixed>          $submitted
     * @param array{0: ?string, 1: ?string} $denialSubject
     */
    private function updateInside(EntityManagerInterface $em, Identity $identity, string $userId, array $submitted, ?string $ipAddress, array $denialSubject, \DateTimeImmutable $at): UserView|LockTimeDenial
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
            throw ApiProblem::businessRuleViolated('app_user.is_archived', self::IS_ARCHIVED);
        }

        // 4.
        $fields = self::validated($submitted);

        // 5.
        // The role lock-first scan derives a liveness writer from a method that NAMES `AppUser` and calls one of its
        // liveness mutators (`PurchaseOrderRowLockTest::ROLE_ADMIN_SCAN['mutator_only']`); a mutator called on an entity
        // handed back by another class is invisible to it. Naming the class here is what makes this method a derived
        // writer with its own positional case ('UpdateUser') — do not drop it as redundant (G4-C1 deviation D-3).
        $user = UserLiveness::loadUnderTheLock($em, $userId);
        \assert(is_a($user, AppUser::class)); // `AppUser::class`, not `instanceof`: the scan sees `X::class` and `new X` only.
        $rename = isset($fields['display_name']) && $fields['display_name'] !== $user->getDisplayName();
        $deactivate = false === ($fields['is_active'] ?? null) && $user->isActive();
        $reactivate = true === ($fields['is_active'] ?? null) && !$user->isActive();
        $repeatInactive = false === ($fields['is_active'] ?? null) && !$user->isActive();

        // 6.
        $grants = LiveGrantReader::allGrantsOf($em, $userId);
        $strips = $deactivate || (($repeatInactive || $reactivate) && [] !== $grants);
        if (!$rename && !$deactivate && !$reactivate && !$strips) {
            return $this->body($userId);
        }

        // 7.
        if ($deactivate || $strips) {
            UserLiveness::refuseSelf($identity, $userId);
        }

        // 8.
        if ($strips) {
            UserLiveness::refuseNonOwnerStrip($grants, $actorCodes);
        }

        // 9.
        if ($deactivate) {
            UserLiveness::refuseLastOwner($em, $userId);
        }

        // 10.
        $stripped = $strips ? $this->liveness->strip($em, $userId) : [];
        $lapsed = $deactivate ? $this->liveness->lapse($em, $userId) : [];

        // 11.
        if ($deactivate) {
            $user->deactivate($at);
        }
        if ($reactivate) {
            $user->reactivate($at);
        }
        if ($rename) {
            $user->rename($fields['display_name'], $at);
        }
        $em->flush();

        // 12.
        if ($deactivate) {
            $this->audit->recordDeactivated($identity->tenantId, $identity->userId, $identity->actorLabel, $userId, $ipAddress);
        }
        if ($reactivate) {
            $this->audit->recordReactivated($identity->tenantId, $identity->userId, $identity->actorLabel, $userId, $ipAddress);
        }
        if ($rename) {
            $this->audit->recordRenamed($identity->tenantId, $identity->userId, $identity->actorLabel, $userId, $ipAddress);
        }
        $this->liveness->recordStripAndLapses($identity, $userId, $stripped, $lapsed, $ipAddress);

        // 13.
        return $this->body($userId);
    }

    private function body(string $userId): UserView
    {
        return $this->users->get($userId) ?? throw new \LogicException('The person read live under the lock is not visible to its own transaction.');
    }

    /**
     * @param array<string, mixed> $submitted
     *
     * @return array{display_name?: string, is_active?: bool}
     */
    private static function validated(array $submitted): array
    {
        $errors = [];
        $valid = [];

        if (\array_key_exists('display_name', $submitted)) {
            // One rule, two callers (U-C1b: `CreateUser`) — {@see UserDisplayName}.
            [$name, $error] = UserDisplayName::check($submitted['display_name']);
            if (null !== $error) {
                $errors[] = $error;
            } else {
                $valid['display_name'] = $name;
            }
        }

        if (\array_key_exists('is_active', $submitted)) {
            $isActive = $submitted['is_active'];
            if (null === $isActive) {
                $errors[] = RoleDefinitionFields::error('/is_active', 'required', 'This field is required.');
            } elseif (!\is_bool($isActive)) {
                $errors[] = RoleDefinitionFields::error('/is_active', 'not_a_boolean', 'This field must be true or false.');
            } else {
                $valid['is_active'] = $isActive;
            }
        }

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }

        return $valid;
    }
}

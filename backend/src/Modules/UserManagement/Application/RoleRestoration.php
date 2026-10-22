<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Modules\AuditLog\Infrastructure\RoleAuditWriter;
use App\Modules\UserManagement\Application\Contract\OwnerPredicate;
use App\Modules\UserManagement\Domain\RoleCompanions;
use App\Modules\UserManagement\Domain\RoleDefinitionRules;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\Identity;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`RestoreRole` — bring back a composed role that `DeleteRole` archived, only if bringing it back changes nobody's
 * permissions** (G2, 2026-10-22; `docs/session13/g2-restore-role-design.md`, attacked in `g2-restore-role-red.md`; authority
 * `remaining-ops-final-red.md` M-3 and L-2 > `remaining-ops-plan.md` Revision 1 FINAL G2; V2-R-6 *"a grant of a soft-deleted
 * role must not confer on restore"*). Undoing an archive is the authority that makes one (`role.delete`, §4.2).
 *
 * One transaction, in this order — every refusal before any write (`RoleDeletion`'s order, then the definition rules
 * `RoleUpdate` runs on its result, because a restore makes a definition live exactly as a create does):
 *  1. `RoleAdministrationLock` — the FIRST statement (`SECURITY_MODEL.md` §5.2.5 names `RestoreRole` in its domain).
 *  2. The actor re-checked under the lock with **`role.delete`** (`RoleAdministrationActor`): 403 + denial row / 409
 *     `user_role.actor_is_inactive`. The returned set is the LOCK-time set every later decision reads.
 *  3. **The `role.assign` backstop** (`RoleDeletion` step 3's reason, `SEC` D-7.2): steps 9–10 disclose a holder count and a
 *     pending-proposal count, so a seat holding `role.delete` without `role.assign` (only SQL composes one, G-10) gets one
 *     fixed body for every id, before the role is read. The rule belongs to the `role.delete` seat, so its code is
 *     `DeleteRole`'s; the sentence is this operation's.
 *  4. `{id}` not a uuid, absent, or another workspace's → 404 (read archived or not).
 *  5. **E-15h:** `is_system` → 409 `role.is_system`. A seeded role soft-deleted by SQL is not restored through the API.
 *  6. Live → 409 `role.is_not_archived` — what a repeat meets (§4.2 `guard:role.deleted_at`; no `Idempotency-Key`).
 *  7. **Marker (roles-s4 Q-A symmetry):** the stored codes confer `tenant_settings.edit` and the actor does not hold it →
 *     409 `role.marker_needs_owner`. Before the counts (RED L-2, `UpdateRole`'s order): a restorer who could never restore
 *     the role is told why, not how many hold it.
 *  8. **E-15f's ceiling (`SEC`, M-3, CreateRole symmetry):** the stored codes ⊄ the lock-time set → 409
 *     `role.exceeds_actor_permissions`. `role.delete` is not a way round `role.create`'s ceiling.
 *  9. **Holders (L-2):** ANY `user_role` row for the role (`LiveGrantReader::holderRowCount`, fail closed — deactivated and
 *     removed accounts included) → 409 `role.has_holders`. Only SQL can plant one (G-10; `RoleRevokeTest`'s shape);
 *     restoring would make that grant confer again — V2-R-6's case. Refused, never stripped, never merely recorded. A grant
 *     held by a REMOVED account cannot be revoked through the API until G4's strip ships (G-10, disclosed).
 * 10. **Pending proposals:** ANY `pending` proposal for the role, stale included → 409 `role.has_pending_proposals`.
 *     `ConfirmRoleGrantProposal` refuses an archived role, so a planted proposal is dormant; a restore would wake it.
 * 11. **PC-4 (M-3):** the stored codes span an SoD row — the rows in force NOW, not when it was archived → 409
 *     `role.separation_of_duty`, no owner exemption.
 * 12. **Companions** on the stored codes (rows added since the archive bind too) → 409 `role.missing_companion_view`.
 * 13. **Name:** a live role with the same collision key → 409 `role.name_taken` with the restore sentence; the `23505` on
 *     `role_tenant_name_live` (an out-of-band writer) is caught OUTSIDE `run()` and answered the same.
 * 14. The write, raw SQL inside the existing column grant: one `UPDATE role SET deleted_at = NULL, updated_at`, carrying
 *     `AND deleted_at IS NOT NULL AND NOT is_system` itself. `role_permission` is untouched (`RoleDeletion` kept it).
 * 15. One audit row, `role.restored` `{role_id}`, subject the role (SECURITY, IP-bearing — `role.deleted`'s filing).
 * 16. The body: `RoleReader::get()` in this transaction (`holder_count` 0; `margin_exposure` warns, PC-12 clause 3).
 *
 * Not run: E-15f's held-role clause and PC-13's holder re-run — step 9 leaves no holder.
 *
 * **The refusal sentences are PLACEHOLDERS for `UX`** (`g2-restore-role-design.md` §4), each pinned as a LITERAL in
 * `RoleRestoreTest`; none names a permission code or a person (R-1).
 */
final readonly class RoleRestoration
{
    public const NEEDS_ROLE_ASSIGN = 'Restoring a role also needs permission to give roles to people and take them away, and you don\'t have it. Ask an owner.';

    public const IS_SYSTEM = 'This is one of the built-in roles, and built-in roles can\'t be restored or changed.';

    public const NOT_ARCHIVED = 'This role is not archived, so there is nothing to restore.';

    public const HAS_HOLDERS = 'This role is still given to %s, counting anyone whose account is deactivated or removed. It can be restored once nobody holds it.';

    public const HAS_PENDING_PROPOSALS = '%s still open for this role, counting any that are more than 14 days old. It can be restored once they\'re closed: an owner can reject them, and whoever proposed one can withdraw it.';

    public const MARKER_NEEDS_OWNER = 'Only an owner can restore this role, because it makes the people given it owners.';

    public const EXCEEDS = 'This role includes permissions you don\'t have yourself, and a role can only be restored by someone who holds all of its permissions. Ask an owner to restore it.';

    public const SEPARATION_OF_DUTY = 'This role covers both sides of a job that has to be split: anyone given it could %s (%s). It can\'t be restored, but a new role can be created with the permissions for one side.';

    public const MISSING_COMPANION = 'Some permissions in this role only work alongside others it doesn\'t have: %s. It can\'t be restored, but a new role can be created with the missing ones added.';

    public const NAME_TAKEN = 'Another role now uses this role\'s name, so it can\'t be restored. It can be restored once that role is renamed.';

    private const BACKSTOP_CODE = 'role.assign';

    /** Step 14 — inside the column UPDATE grant (`Version20261007000300` + `Version20261007000500`). */
    private const RESTORE_ROLE = <<<'SQL'
        UPDATE role
           SET deleted_at = NULL, updated_at = clock_timestamp()
         WHERE id = ?::uuid AND deleted_at IS NOT NULL AND NOT is_system
        SQL;

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private RoleAuditWriter $audit,
        private RoleAdministrationActor $actor,
        private RoleReader $roles,
    ) {
    }

    /**
     * @param array{0: ?string, 1: ?string} $denialSubject what `PermissionGate::denialSubjectOf()` resolves for this request
     */
    public function restore(string $roleId, ?string $ipAddress, array $denialSubject): RoleView
    {
        $identity = $this->identities->authenticated();

        try {
            $outcome = $this->tenants->run(
                $this->tenant->tenantId(),
                fn (EntityManagerInterface $em): RoleView|LockTimeDenial => $this->restoreInside($em, $identity, $roleId, $ipAddress, $denialSubject),
            );
        } catch (DriverException $e) {
            // Caught OUTSIDE `run()`: the failed statement aborted the transaction and closed the entity manager.
            throw self::translated($e) ?? $e;
        }

        if ($outcome instanceof LockTimeDenial) {
            // Outside `run()`, after the commit: the gate's own factory, so the body is byte-identical to its 403.
            throw ApiProblem::permissionDenied($outcome->permissionCode);
        }

        return $outcome;
    }

    /** @param array{0: ?string, 1: ?string} $denialSubject */
    private function restoreInside(EntityManagerInterface $em, Identity $identity, string $roleId, ?string $ipAddress, array $denialSubject): RoleView|LockTimeDenial
    {
        // 1.
        RoleAdministrationLock::holdInside($em, $this->tenant->tenantId());

        // 2. Before any read of the role (E-1).
        $actorCodes = $this->actor->reCheckUnderTheLock($em, $identity, $ipAddress, $denialSubject, 'role.delete');
        if ($actorCodes instanceof LockTimeDenial) {
            return $actorCodes;
        }

        // 3. On the LOCK-time set, before the role is read.
        if (!\in_array(self::BACKSTOP_CODE, $actorCodes, true)) {
            throw ApiProblem::businessRuleViolated('role.delete_needs_role_assign', self::NEEDS_ROLE_ASSIGN);
        }

        // 4.
        if (!Uuid::isValid($roleId)) {
            throw ApiProblem::notFound();
        }
        $roleId = Uuid::fromString($roleId)->toRfc4122();
        $role = LiveGrantReader::role($em, $roleId) ?? throw ApiProblem::notFound();

        // 5.
        if ($role['is_system']) {
            throw ApiProblem::businessRuleViolated('role.is_system', self::IS_SYSTEM);
        }

        // 6.
        if ($role['live']) {
            throw ApiProblem::businessRuleViolated('role.is_not_archived', self::NOT_ARCHIVED);
        }

        $codes = $role['codes'];

        // 7. Before the counts (RED L-2): a restorer who could never restore this role is told so, not how many hold it.
        if (OwnerPredicate::holdsMarker($codes) && !OwnerPredicate::holdsMarker($actorCodes)) {
            throw ApiProblem::businessRuleViolated('role.marker_needs_owner', self::MARKER_NEEDS_OWNER);
        }

        // 8.
        if ([] !== RoleDefinitionRules::beyondCeiling($codes, $actorCodes)) {
            throw ApiProblem::businessRuleViolated('role.exceeds_actor_permissions', self::EXCEEDS);
        }

        // 9.
        $holders = LiveGrantReader::holderRowCount($em, $roleId);
        if ($holders > 0) {
            throw ApiProblem::businessRuleViolated('role.has_holders', sprintf(self::HAS_HOLDERS, RefusalCounts::people($holders)));
        }

        // 10.
        $pending = LiveGrantReader::pendingProposalCount($em, $roleId);
        if ($pending > 0) {
            throw ApiProblem::businessRuleViolated('role.has_pending_proposals', sprintf(self::HAS_PENDING_PROPOSALS, RefusalCounts::openProposals($pending)));
        }

        // 11.
        $spanned = RoleDefinitionRules::separationOfDutyRowsSpanned($codes);
        if ([] !== $spanned) {
            throw ApiProblem::businessRuleViolated('role.separation_of_duty', self::separationOfDutyMessage($spanned));
        }

        // 12.
        $missing = RoleCompanions::missingFrom($codes);
        if ([] !== $missing) {
            throw ApiProblem::businessRuleViolated('role.missing_companion_view', self::missingCompanionMessage($missing));
        }

        // 13.
        if (RoleDefinitionFields::nameIsTaken($em, $role['name'], $roleId)) {
            throw self::nameTaken();
        }

        // 14.
        if (1 !== $em->getConnection()->executeStatement(self::RESTORE_ROLE, [$roleId])) {
            throw new \LogicException('The role read archived and unused under the lock was not restored.');
        }

        // 15.
        $this->audit->recordRestored(
            tenantId: $identity->tenantId,
            actorUserId: $identity->userId,
            actorLabel: $identity->actorLabel,
            roleId: $roleId,
            ipAddress: $ipAddress,
        );

        // 16.
        return $this->roles->get($roleId) ?? throw new \LogicException('The role just restored is not visible to its own transaction.');
    }

    private static function nameTaken(): ApiProblem
    {
        return ApiProblem::businessRuleViolated('role.name_taken', self::NAME_TAKEN);
    }

    /** Step 13's backstop: a `23505` on the live-name unique, answered as the rule. Null = not ours; the caller rethrows. */
    private static function translated(DriverException $e): ?ApiProblem
    {
        return $e instanceof UniqueConstraintViolationException && str_contains($e->getMessage(), 'role_tenant_name_live') ? self::nameTaken() : null;
    }

    /** @param list<string> $rows */
    private static function separationOfDutyMessage(array $rows): string
    {
        $clauses = array_map(static fn (string $row): string => RoleAssignment::SOD_CLAUSES[$row] ?? throw new \LogicException(
            'SoD row '.$row.' has no sentence in RoleAssignment::SOD_CLAUSES.'), $rows);
        $last = array_pop($clauses);
        $joined = [] === $clauses ? (string) $last : implode(', ', $clauses).' and '.$last;

        return sprintf(self::SEPARATION_OF_DUTY, $joined, implode(', ', $rows));
    }

    /** @param list<array{holding: string, requires: string}> $missing */
    private static function missingCompanionMessage(array $missing): string
    {
        return sprintf(self::MISSING_COMPANION, implode('; ', array_map(
            static fn (array $row): string => RoleCreation::COMPANION_CLAUSES[$row['holding']][$row['requires']] ?? throw new \LogicException(
                'Companion row '.$row['holding'].' -> '.$row['requires'].' has no clause in RoleCreation::COMPANION_CLAUSES.'),
            $missing,
        )));
    }
}

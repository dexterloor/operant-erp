<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Modules\AuditLog\Infrastructure\RoleAuditWriter;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\Identity;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`DeleteRole` — soft-delete a composed role that nobody holds and nobody has asked to be given** (roles slice 4, C3;
 * `docs/session13/roles-s4-design.md` §3.3, §5.1–§5.4, §7, §8.1; owner ruling `session22-owner-ruling.md` D-6 option (1),
 * D-8; `SEC` `roles-s4-sec-data.md` D-7.2 as corrected by `roles-s4-sec-data-red.md` item 1, and D-7.4).
 *
 * One transaction, in this order — every refusal before any write:
 *  1. `RoleAdministrationLock` — the FIRST statement (§5.2.5): a concurrent `AssignRole`, proposal or edit of this role
 *     waits on it, so "no holder and nothing pending" is still true at the write.
 *  2. The actor re-checked under the lock with **`role.delete`** (`RoleAdministrationActor`): 403 + denial row / 409
 *     `user_role.actor_is_inactive`. The returned set is the LOCK-time set every later decision reads.
 *  3. **The `role.assign` backstop (`SEC` D-7.2; RED F1-A, F1-C):** the lock-time set lacks `role.assign` → 409
 *     `role.delete_needs_role_assign`, **before the role is read**, one fixed body for every id (existing, archived,
 *     system, another workspace's, not a uuid) — so it is no oracle and reveals no pending state. No denial row, no
 *     `required_permission`: it is a business rule, not a second permission gate. No API-composed seat can meet it (owner
 *     D-6 (1): a composed role holding `role.delete` must hold `role.assign`; `owner` and `admin` hold both) — only a role
 *     written by SQL as `erp_app` (G-10) can. It keeps the pending bit from a holder of such a seat through the API; it
 *     does NOT narrow G-10 (SQL as `erp_app` can read `role_grant_proposal` directly).
 *  4. `{id}` not a uuid, absent, or another workspace's → 404 (read archived or not, so step 6 can answer).
 *  5. **E-15h:** `is_system` → 409 `role.is_system`.
 *  6. Already deleted → 409 `role.is_archived` — which is also what a repeat meets: §4.2's `Idem = no` is sound (the
 *     `DeleteSalesOrder` precedent).
 *  7. **E-15h holders (D-7.4):** ANY `user_role` row for the role (`LiveGrantReader::holderRowCount`, whatever the user's
 *     state — fail closed) → 409 `role.has_holders`, naming the count. **Nothing is ever stripped** (E-15h refused
 *     "deletion that revokes every holder": an admin could strip an owner by deleting the role).
 *  8. **E-15h pending proposals (D-7.2):** ANY `status = 'pending'` proposal for the role, **stale ones included** (an
 *     expired one not yet lapsed still counts — fail closed) → 409 `role.has_pending_proposals`, naming the count. Served
 *     only to a `role.assign` holder (step 3), so PC-16 condition 1 holds by mechanism. Every pending proposal is
 *     clearable: an owner may reject it (expired or not, §5.2.7), its proposer may withdraw it.
 *  9. The write, raw SQL, exactly the C3 grant: one `UPDATE role SET deleted_at, updated_at` (both `clock_timestamp()`,
 *     never a PHP clock), carrying `AND deleted_at IS NULL AND NOT is_system` itself. `role_permission` rows STAY (the
 *     definition is kept for audit and for `RestoreRole`, G2 2026-10-22, which brings it back unchanged). No cascade statement exists.
 * 10. One audit row, `role.deleted` `{role_id}`, subject the role (SECURITY, IP-bearing).
 *
 * **G-31, worsened and disclosed (design §5.3; no pull-forward):** an expired proposal nobody opens now blocks deletion
 * until someone clears it; the refusal sentence names the way out.
 *
 * **The refusal sentences are `UX`'s** (`docs/session13/roles-s4-ux-copy.md` #16–#20, signed over `SEC`'s D-7.2 intent; applied
 * 2026-10-09), each pinned as a LITERAL in `RoleDeleteTest`. Counts are {@see RefusalCounts}; a remedy that depends on the
 * reader is passive (R-3).
 */
final readonly class RoleDeletion
{
    /** `UX` `roles-s4-ux-copy.md` #16 (`SEC` D-7.2 item 4; RED F1-A). Identical for every id: no "it", no "this role". */
    public const NEEDS_ROLE_ASSIGN = 'Deleting a role also needs permission to give roles to people and take them away, and you don\'t have it. Ask an owner.';

    /** `UX` `roles-s4-ux-copy.md` #17 (E-15h). */
    public const IS_SYSTEM = 'This is one of the built-in roles, and built-in roles can\'t be deleted.';

    /** `UX` `roles-s4-ux-copy.md` #18 (a repeat lands here; still true since G2 shipped `RestoreRole` — the archived roles are where it is restored from). */
    public const IS_ARCHIVED = 'This role has already been deleted. It\'s listed with the archived roles.';

    /** `UX` `roles-s4-ux-copy.md` #19 (`SEC` D-7.2 item 2: the row count, which may exceed `GetRole`'s `holder_count`). */
    public const HAS_HOLDERS = 'This role is still given to %s, counting anyone whose account is deactivated. It can be deleted once nobody holds it.';

    /** `UX` `roles-s4-ux-copy.md` #20 (`SEC` D-7.2 item 3: stale ones counted; names no proposal and no person). */
    public const HAS_PENDING_PROPOSALS = '%s still open for this role, counting any that are more than 14 days old. It can be deleted once they\'re closed: an owner can reject them, and whoever proposed one can withdraw it. You can find them among the open role proposals.';

    /** The code step 3 requires of the lock-time set. */
    private const BACKSTOP_CODE = 'role.assign';

    /** Step 9 — the column list IS the C3 column UPDATE grant (`Version20261007000500`) plus C2's `updated_at`. */
    private const DELETE_ROLE = <<<'SQL'
        UPDATE role
           SET deleted_at = clock_timestamp(), updated_at = clock_timestamp()
         WHERE id = ?::uuid AND deleted_at IS NULL AND NOT is_system
        SQL;

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
    public function delete(string $roleId, ?string $ipAddress, array $denialSubject): void
    {
        $identity = $this->identities->authenticated();

        $outcome = $this->tenants->run(
            $this->tenant->tenantId(),
            fn (EntityManagerInterface $em): ?LockTimeDenial => $this->deleteInside($em, $identity, $roleId, $ipAddress, $denialSubject),
        );

        if ($outcome instanceof LockTimeDenial) {
            // Outside `run()`, after the commit: the gate's own factory, so the body is byte-identical to its 403.
            throw ApiProblem::permissionDenied($outcome->permissionCode);
        }
    }

    /** @param array{0: ?string, 1: ?string} $denialSubject */
    private function deleteInside(EntityManagerInterface $em, Identity $identity, string $roleId, ?string $ipAddress, array $denialSubject): ?LockTimeDenial
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
        if (!$role['live']) {
            throw ApiProblem::businessRuleViolated('role.is_archived', self::IS_ARCHIVED);
        }

        // 7.
        $holders = LiveGrantReader::holderRowCount($em, $roleId);
        if ($holders > 0) {
            throw ApiProblem::businessRuleViolated('role.has_holders', sprintf(self::HAS_HOLDERS, RefusalCounts::people($holders)));
        }

        // 8.
        $pending = LiveGrantReader::pendingProposalCount($em, $roleId);
        if ($pending > 0) {
            throw ApiProblem::businessRuleViolated('role.has_pending_proposals', sprintf(self::HAS_PENDING_PROPOSALS, RefusalCounts::openProposals($pending)));
        }

        // 9.
        if (1 !== $em->getConnection()->executeStatement(self::DELETE_ROLE, [$roleId])) {
            throw new \LogicException('The role read live and unused under the lock was not deleted.');
        }

        // 10.
        $this->audit->recordDeleted(
            tenantId: $identity->tenantId,
            actorUserId: $identity->userId,
            actorLabel: $identity->actorLabel,
            roleId: $roleId,
            ipAddress: $ipAddress,
        );

        return null;
    }
}

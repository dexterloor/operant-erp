<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Modules\AuditLog\Infrastructure\RoleAuditWriter;
use App\Modules\UserManagement\Application\Contract\OwnerPredicate;
use App\Modules\UserManagement\Domain\LastOwnerFloor;
use App\Modules\UserManagement\Domain\RoleCompanions;
use App\Modules\UserManagement\Domain\RoleDefinitionRules;
use App\Modules\UserManagement\Domain\SeparationOfDuty;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\Identity;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`UpdateRole` — change a composed role's name, description or permission codes** (roles slice 4, C2;
 * `docs/session13/roles-s4-design.md` §3.2, §4.1, §4.3, §4.5, §4.7, §7, §8.1; owner ruling `session22-owner-ruling.md`;
 * **Q-A** `roles-s4-c2-decisions.md` FINAL + `roles-s4-c2-decisions-red.md` §6 (a); `SEC` `roles-s4-sec-data.md` D-7.1,
 * D-7.3, D-7.4, D-7.5 as replaced by c2-decisions-red §6 (b)).
 *
 * A PATCH carries any subset of `name`, `description`, `permission_codes`; a present `permission_codes` **replaces** the
 * set (an edit is a SET, not a delta — which is why §4.2's `Idem = no` is sound: a repeat computes an empty delta and writes
 * nothing; the lost-update hazard of two stale full sets is residual R-4, made visible by the audit row's `added`/`removed`).
 *
 * One transaction, in this order — every refusal before any write:
 *  1. `RoleAdministrationLock` — the FIRST statement (§5.2.5).
 *  2. The actor re-checked under the lock with **`role.edit`** (`RoleAdministrationActor`): 403 + denial row / 409
 *     `user_role.actor_is_inactive` (the role-definition sentence, `UX` copy #1). The returned set is the LOCK-time set every
 *     later decision reads.
 *  3. `{id}` not a uuid, absent, or another workspace's → 404 (read ARCHIVED OR NOT, so step 5 can answer).
 *  4. **E-15h:** `is_system` → 409 `role.is_system` — even for a request that changes nothing (*"an edit to an `is_system`
 *     role through any path"*).
 *  5. Archived → 409 `role.is_archived` (no `RestoreRole`, D-8: the sentence says to create a new role).
 *  6. Field validation of the members PRESENT ({@see RoleDefinitionFields}, shared with `CreateRole`; 422), the name rule
 *     excluding this role (409 `role.name_taken`), codes from the `permission` table (422).
 *  7. `added`, `removed`, `fields` (`name` / `description` whose stored value differs). **All empty → 200 with the role,
 *     no write, no audit row.**
 *  8. **Q-A (LEAD under the owner's delegation, after RED; replaces design step 9a):** the role's STORED codes (archived
 *     or not — step 5 already refused an archived role, but the predicate is written for `RestoreRole`'s sake, as
 *     `RoleRevocation` D-15) or its RESULTING codes contain `tenant_settings.edit`, and the actor does not hold the marker
 *     in its lock-time set (`OwnerPredicate::holdsMarker()`) → 409 `role.marker_needs_owner`. A role that makes its holders
 *     owners is changed only by an owner, held or not — the same rule `RevokeRole` and E-15b keep. Before the ceiling, so
 *     a non-owner adding the marker is told THIS rule (the resulting-set disjunct is defence in depth: the ceiling would
 *     refuse it too).
 *  9. If `added ≠ ∅`: (a) **E-15f's ceiling** on the lock-time set (held or unheld, D-3) → `role.exceeds_actor_permissions`;
 *     (b) **E-15f's 2026-09-22 clause:** the role has ANY holder (`LiveGrantReader::holderRowCount`, D-7.4) and the edit
 *     would route a grant to an owner (`GrantConfirmationPolicy::needsConfirmation(actor, added)` — not a marker holder and
 *     `added ∩ X(admin) ≠ ∅`) → `role.owner_needed_for_held_role`, naming the holder count and no code (E-15f as amended
 *     2026-10-08, `UX` copy #10: the codes are the caller's own additions); (c) **PC-13 re-run
 *     over every holder** at edge grain, new conflicts only (`SEC` D-7.1) → `role.holder_would_conflict`, naming the count
 *     of affected holders and the rows' work, never a holder.
 * 10. If the marker is REMOVED: **API-20** — `LastOwnerFloor::refuses(active marker holders, the same without this role)`
 *     → `role.last_owner` (design step 9b; Q-A already made the actor an owner).
 * 11. **PC-4 on the RESULT, unconditionally** (`SEC` D-7.1 item 4: an edit that only renames a role whose stored codes span
 *     a row — reachable only by SQL — is still refused) → `role.separation_of_duty`, no owner exemption.
 * 12. **Companions on the RESULT** (D-5a, D-5b, D-6 (1)) → `role.missing_companion_view`.
 * 13. Writes, raw SQL, exactly the C2 grants: one `UPDATE role SET name, description, updated_at = clock_timestamp()`
 *     (`updated_at` bumped on every write, codes-only included; `AND NOT is_system AND deleted_at IS NULL` carried by the
 *     statement), `DELETE FROM role_permission` for `removed`, one multi-row `INSERT INTO role_permission` for `added`.
 *     Never `UPDATE role_permission`, never `ON CONFLICT DO UPDATE`.
 * 14. One audit row (E-15f: one per edit, never per holder): `role.permissions_changed` `{role_id, added, removed[, fields]}`
 *     when the code set changed, else `role.updated` `{role_id, fields}` (`SEC` D-7.3).
 * 15. The body: `RoleReader::get()` after the writes, in this transaction.
 *
 * **Pending proposals on the role are NOT written to** (E-15f): each carries its code snapshot, and
 * `ConfirmRoleGrantProposal` refuses a live-set mismatch as `role_changed`.
 *
 * **The refusal sentences are `UX`'s** (`docs/session13/roles-s4-ux-copy.md` #6–#12, #14, #15; applied 2026-10-09). No sentence
 * names a holder or a permission code (R-1); a count of people is {@see RefusalCounts::people()}. Each is pinned as a LITERAL
 * in `RoleUpdateTest`.
 */
final readonly class RoleUpdate
{
    /** `UX` `roles-s4-ux-copy.md` #6 (E-15h). Passive remedy (R-3): a `role.edit` seat need not hold `role.create`. */
    public const IS_SYSTEM = 'This is one of the built-in roles, and built-in roles can\'t be changed. A new role can be created instead.';

    /** `UX` `roles-s4-ux-copy.md` #7. "can't be restored" is true while `RestoreRole` is out (D-8); it changes if one ships. */
    public const IS_ARCHIVED = 'This role is archived, so it can\'t be changed. Archived roles can\'t be restored, so a new role can be created in its place.';

    /** `UX` `roles-s4-ux-copy.md` #8 (Q-A): "makes, or would make" is true on both the stored and the resulting disjunct. */
    public const MARKER_NEEDS_OWNER = 'Only an owner can change this role, because it makes, or would make, the people given it owners.';

    /** `UX` `roles-s4-ux-copy.md` #9 — the Update ceiling binds ADDED codes only (D-3), so it is not `CreateRole`'s #3. */
    public const EXCEEDS = 'Some of the permissions you\'re adding are ones you don\'t have yourself, and you can only add permissions you hold. Leave those out, or ask an owner to add them.';

    /**
     * `UX` `roles-s4-ux-copy.md` #10 (E-15f as amended 2026-10-08, `SEC` co-signed): the holder count as people, never the
     * codes — they are the caller's own additions, and only the `X(admin)` ones trigger the rule.
     */
    public const OWNER_NEEDED_FOR_HELD_ROLE = 'This role is already given to %s, and some of the permissions you\'re adding can only reach people with an owner\'s confirmation. Ask an owner to add them, or they can go in a new role instead.';

    /** `UX` `roles-s4-ux-copy.md` #11 (`SEC` D-7.1: the count and the work, never a holder). */
    public const HOLDER_WOULD_CONFLICT = 'With the other roles they have, %s given this role would then be able to %s (%s). Leave these permissions out, or the conflicting roles have to be taken away from them first.';

    /** `UX` `roles-s4-ux-copy.md` #12 (API-20). */
    public const LAST_OWNER = 'This change would leave the workspace with no owner. Make someone else an owner first.';

    /** Step 13 — the column list IS the C2 column UPDATE grant (`Version20261007000300`). */
    private const UPDATE_ROLE = <<<'SQL'
        UPDATE role
           SET name = ?, description = ?, updated_at = clock_timestamp()
         WHERE id = ?::uuid AND NOT is_system AND deleted_at IS NULL
        SQL;

    /** Step 13 — the C2 table DELETE grant; the system-role guard is in the statement itself (RED F-11). */
    private const DELETE_CODES = <<<'SQL'
        DELETE FROM role_permission
         WHERE role_id = ?::uuid
           AND permission_code = ANY (?::text[])
           AND role_id IN (SELECT id FROM role WHERE NOT is_system)
        SQL;

    /** Step 13 — one multi-row statement; the column list IS C1's column INSERT grant. */
    private const INSERT_CODES = <<<'SQL'
        INSERT INTO role_permission (id, tenant_id, role_id, permission_code)
        SELECT x.id, ?::uuid, ?::uuid, x.code
          FROM jsonb_to_recordset(?::jsonb) AS x(id uuid, code text)
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
     * @param array<string, mixed>        $submitted     the PATCH members present, keyed `name` / `description` / `permission_codes`
     * @param array{0: ?string, 1: ?string} $denialSubject what `PermissionGate::denialSubjectOf()` resolves for this request
     */
    public function update(string $roleId, array $submitted, ?string $ipAddress, array $denialSubject): RoleView
    {
        $identity = $this->identities->authenticated();

        try {
            $outcome = $this->tenants->run(
                $this->tenant->tenantId(),
                fn (EntityManagerInterface $em): RoleView|LockTimeDenial => $this->updateInside($em, $identity, $roleId, $submitted, $ipAddress, $denialSubject),
            );
        } catch (DriverException $e) {
            // Caught OUTSIDE `run()`: the failed statement aborted the transaction and closed the entity manager.
            throw RoleDefinitionFields::translated($e, $submitted['name'] ?? null) ?? $e;
        }

        if ($outcome instanceof LockTimeDenial) {
            // Outside `run()`, after the commit: the gate's own factory, so the body is byte-identical to its 403.
            throw ApiProblem::permissionDenied($outcome->permissionCode);
        }

        return $outcome;
    }

    /**
     * @param array<string, mixed>        $submitted
     * @param array{0: ?string, 1: ?string} $denialSubject
     */
    private function updateInside(EntityManagerInterface $em, Identity $identity, string $roleId, array $submitted, ?string $ipAddress, array $denialSubject): RoleView|LockTimeDenial
    {
        // 1.
        RoleAdministrationLock::holdInside($em, $this->tenant->tenantId());

        // 2. Before any read of the role (E-1).
        $actorCodes = $this->actor->reCheckUnderTheLock($em, $identity, $ipAddress, $denialSubject, 'role.edit');
        if ($actorCodes instanceof LockTimeDenial) {
            return $actorCodes;
        }

        // 3.
        if (!Uuid::isValid($roleId)) {
            throw ApiProblem::notFound();
        }
        $roleId = Uuid::fromString($roleId)->toRfc4122();
        $role = LiveGrantReader::role($em, $roleId) ?? throw ApiProblem::notFound();

        // 4.
        if ($role['is_system']) {
            throw ApiProblem::businessRuleViolated('role.is_system', self::IS_SYSTEM);
        }

        // 5.
        if (!$role['live']) {
            throw ApiProblem::businessRuleViolated('role.is_archived', self::IS_ARCHIVED);
        }

        // 6.
        $fields = RoleDefinitionFields::validated(array_intersect_key($submitted, ['name' => true, 'description' => true, 'permission_codes' => true]));
        $connection = $em->getConnection();
        $name = $fields['name'] ?? $role['name'];
        $description = $fields['description'] ?? $role['description'];
        if (isset($fields['name']) && RoleDefinitionFields::nameIsTaken($em, $name, $roleId)) {
            throw RoleDefinitionFields::nameTaken($name);
        }
        if (isset($fields['permission_codes'])) {
            $unknown = RoleDefinitionFields::unknownCodeErrors($em, $fields['permission_codes']);
            if ([] !== $unknown) {
                throw ApiProblem::validationFailed($unknown);
            }
        }

        // 7.
        $old = $role['codes'];
        $new = $fields['permission_codes'] ?? $old;
        sort($new);
        $added = array_values(array_diff($new, $old));
        $removed = array_values(array_diff($old, $new));
        $changedFields = array_keys(array_filter(['name' => $name !== $role['name'], 'description' => $description !== $role['description']]));
        if ([] === $added && [] === $removed && [] === $changedFields) {
            return $this->roles->get($roleId) ?? throw new \LogicException('The role read under the lock is not visible to its own transaction.');
        }

        // 8. Q-A — the stored or the resulting set makes its holders owners.
        if ((OwnerPredicate::holdsMarker($old) || OwnerPredicate::holdsMarker($new)) && !OwnerPredicate::holdsMarker($actorCodes)) {
            throw ApiProblem::businessRuleViolated('role.marker_needs_owner', self::MARKER_NEEDS_OWNER);
        }

        // 9.
        if ([] !== $added) {
            $beyond = RoleDefinitionRules::beyondCeiling($added, $actorCodes);
            if ([] !== $beyond) {
                throw ApiProblem::businessRuleViolated('role.exceeds_actor_permissions', self::EXCEEDS);
            }

            $holderCount = LiveGrantReader::holderRowCount($em, $roleId);
            if ($holderCount > 0 && GrantConfirmationPolicy::needsConfirmation($actorCodes, $added)) {
                throw ApiProblem::businessRuleViolated('role.owner_needed_for_held_role', sprintf(self::OWNER_NEEDED_FOR_HELD_ROLE, RefusalCounts::people($holderCount)));
            }

            $affected = 0;
            $rows = [];
            foreach (LiveGrantReader::holdersOtherCodes($em, $roleId) as $heldOther) {
                $edges = RoleDefinitionRules::newConflictEdgesForHolder($heldOther, $old, $new);
                if ([] !== $edges) {
                    ++$affected;
                    foreach ($edges as $edge) {
                        $rows = [...$rows, ...SeparationOfDuty::rowsSpannedBy($edge)];
                    }
                }
            }
            if ($affected > 0) {
                throw ApiProblem::businessRuleViolated('role.holder_would_conflict', self::holderConflictMessage($affected, $rows));
            }
        }

        // 10. API-20 — only an owner reaches here with the marker removed (step 8). "The removed codes confer the marker" is
        // asked through the one predicate, never by reading the marker's code here (`OwnerPredicateSingleSourceTest`).
        if (OwnerPredicate::holdsMarker($removed) && LastOwnerFloor::refuses(
            LiveGrantReader::activeMarkerHolderCount($em),
            LiveGrantReader::activeMarkerHolderCountWithoutRole($em, $roleId),
        )) {
            throw ApiProblem::businessRuleViolated('role.last_owner', self::LAST_OWNER);
        }

        // 11. PC-4 on the result, unconditionally, no exemption.
        $spanned = RoleDefinitionRules::separationOfDutyRowsSpanned($new);
        if ([] !== $spanned) {
            throw ApiProblem::businessRuleViolated('role.separation_of_duty', RoleCreation::separationOfDutyMessage($spanned));
        }

        // 12. Companions on the result.
        $missing = RoleCompanions::missingFrom($new);
        if ([] !== $missing) {
            throw ApiProblem::businessRuleViolated('role.missing_companion_view', RoleCreation::missingCompanionMessage($missing));
        }

        // 13.
        if (1 !== $connection->executeStatement(self::UPDATE_ROLE, [$name, $description, $roleId])) {
            throw new \LogicException('The role read live and composed under the lock was not updated.');
        }
        if ([] !== $removed) {
            $connection->executeStatement(self::DELETE_CODES, [$roleId, '{'.implode(',', $removed).'}']);
        }
        if ([] !== $added) {
            $connection->executeStatement(self::INSERT_CODES, [
                $identity->tenantId,
                $roleId,
                json_encode(array_map(static fn (string $code): array => ['id' => Uuid::v4()->toRfc4122(), 'code' => $code], $added), \JSON_THROW_ON_ERROR),
            ]);
        }

        // 14.
        if ([] !== $added || [] !== $removed) {
            $this->audit->recordPermissionsChanged(
                tenantId: $identity->tenantId,
                actorUserId: $identity->userId,
                actorLabel: $identity->actorLabel,
                roleId: $roleId,
                added: $added,
                removed: $removed,
                ipAddress: $ipAddress,
                fields: $changedFields,
            );
        } else {
            $this->audit->recordUpdated(
                tenantId: $identity->tenantId,
                actorUserId: $identity->userId,
                actorLabel: $identity->actorLabel,
                roleId: $roleId,
                fields: $changedFields,
                ipAddress: $ipAddress,
            );
        }

        // 15.
        return $this->roles->get($roleId) ?? throw new \LogicException('The role just edited is not visible to its own transaction.');
    }

    /** @param list<string> $rows */
    private static function holderConflictMessage(int $affected, array $rows): string
    {
        $rows = array_values(array_intersect(SeparationOfDuty::rowIds(), $rows));
        $clauses = array_map(static fn (string $row): string => RoleAssignment::SOD_CLAUSES[$row] ?? throw new \LogicException(
            'SoD row '.$row.' has no sentence in RoleAssignment::SOD_CLAUSES.'), $rows);
        $last = array_pop($clauses);
        $joined = [] === $clauses ? (string) $last : implode(', ', $clauses).' and '.$last;

        return sprintf(self::HOLDER_WOULD_CONFLICT, RefusalCounts::people($affected), $joined, implode(', ', $rows));
    }
}

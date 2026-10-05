<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Modules\AuditLog\Infrastructure\RoleAuditWriter;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\Identity;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * **`AssignRole` — give one user one role, under every rule E-15 writes** (roles slice 2, C3;
 * `docs/session13/roles-s2-design.md` §5.2 as amended by `roles-s2-spar.md` §2 and `roles-s2-sec.md`).
 *
 * One transaction, in this order — the order is the design, and several steps are integrity properties:
 *  1. **`RoleAdministrationLock`**, the first statement after `set_config` (§5.2.5; positional test in
 *     `PurchaseOrderRowLockTest`). Every read below sees what the previous holder committed.
 *  2. **The actor, re-checked under the lock** — {@see RoleAdministrationActor}, the one copy shared with `RevokeRole`:
 *     missing `role.assign` → the denial row inside this transaction, a {@see LockTimeDenial} returned, commit, the gate's
 *     own 403 thrown outside `run()` (S-2); inactive → 409 `user_role.actor_is_inactive`. Before ANY subject read (E-1).
 *  3. **The subject and the role.** `{id}` not a uuid, absent or another workspace's → 404. `role_id` absent → 422
 *     `required`; not a uuid → 422 `not_a_uuid`; no role with that id in this workspace → 422 `unknown_reference` (the
 *     `CreateSalesOrder` body-reference precedent; ST-5: another workspace's id is indistinguishable from none). **V2-R-6**
 *     (ruled 2026-09-22): subject soft-deleted → 409 `user_role.subject_is_deleted`; subject `is_active = false` → 409
 *     `user_role.subject_is_inactive`; role archived → 409 `user_role.role_is_archived`.
 *  4. **E-15a:** nobody grants themselves a role → 409 `user_role.actor_is_subject`.
 *  5. **E-15b / Q5:** a role conferring the owner marker is granted only by an actor holding it → 409
 *     `user_role.marker_needs_owner` — it takes NEITHER route (the proposal route refuses it too), so no route is named.
 *  6. **E-15e routing:** {@see GrantConfirmationPolicy} → 409 `user_role.needs_owner_confirmation`. Until slice 3 the refusal
 *     names no route; slice 3 adds the proposal route to the body without changing the code.
 *  7. **E-15c:** a composed role (`is_system = false`) whose codes are not a subset of the actor's → 409
 *     `user_role.exceeds_actor_permissions`.
 *  8. **C12:** already held → 409 `user_role.already_held` (a race loser against an out-of-band writer gets the same code
 *     from the translated `23505` on `user_role_unique`).
 *  9. **PC-13 / E-18:** {@see \App\Modules\UserManagement\Domain\GrantConflict::newRows()} over the subject's LIVE roles → 409 `user_role.separation_of_duty`,
 *     naming each SoD row id, never the codes.
 * 10. `INSERT INTO user_role (id, tenant_id, user_id, role_id)` — exactly H1's four columns, the only ones `erp_app` may
 *     insert (`Version20260930000100`); `inserted_by` is the database's `session_user`.
 * 11. `role.assigned`, subject the user, `{role_id}`, the request's IP, `clock_timestamp()`.
 * 12. **The body (`SEC` S-1 (b)):** the granted role in `GetRole`'s shape, read through `RoleReader::get()` AFTER the insert
 *     and the audit write, in the same locked transaction (same tenant ⇒ `run()` joins), so `holder_count` includes this
 *     grant. `CreatedRowLocation` reads its `id` to fill `{roleId}` in `Location`.
 *
 * **The refusal sentences are `UX`'s** (`docs/session13/roles-s2-ux-copy.md` §3.11), verbatim and pinned as literals in
 * `RoleAssignTest`. `actor_is_inactive` is `RoleAdministrationActor`'s, shared with `RevokeRole` and unchanged in C3.
 *
 * Every decision reads `LiveGrantReader` on the locked connection; every caller decision about the marker goes through
 * `OwnerPredicate::holdsMarker()` over that lock-time set. No PHP clock stamps or decides anything.
 *
 * **Roles slice 3, C1:** steps 3–5 and 7–10 are `GrantRules`' phases 1–5 — the one copy of the grant rules, shared with
 * `CreateRoleGrantProposal` now and with confirmation at C2 — called in the shipped order, with routing (step 6) in the
 * temporary slot S between phases 1 and 2. Behaviour unchanged; C2 moves routing last (design §4.7, §5 change 3).
 */
final readonly class RoleAssignment
{
    /**
     * **`UX`'s clause per SoD row** (`docs/session13/roles-s2-ux-copy.md` §3.10) — copy keyed by row id, naming the work and
     * never a code, so it is not a second copy of `SeparationOfDuty::ROWS`. It can fall behind them, so
     * `RoleAssignTest::everySeparationOfDutyRowHasItsSentence` holds its keys equal to `SeparationOfDuty::rowIds()`: SoD-5
     * (with `CreateSalesReturn` / A2.1b) cannot ship without its sentence, and A2.1b rewords SoD-3's (*"… record their
     * payment, void them or credit them"*).
     */
    public const SOD_CLAUSES = [
        'SoD-1' => 'raise purchase orders and also approve or cancel them',
        'SoD-2' => 'raise or approve purchase orders and also record the goods as received',
        // A2.1b (sales step 7 C1, 2026-10-01): `UX`'s ruled rewording (`roles-s2-ux-copy.md` §3.10).
        'SoD-3' => 'issue invoices and also record their payment, void them or credit them',
        'SoD-4' => 'confirm sales orders or issue invoices and also ship the goods',
        // SoD-5 (sales step 7 C1). ⚠ `BE-LEAD`'s wording in `UX`'s pattern — `UX` wrote no SoD-5 clause; owed to `UX`.
        'SoD-5' => 'record goods as returned and also credit the invoice for them',
    ];

    /** The unique index a concurrent out-of-band grant collides on (`Version20260906000100`). */
    private const UNIQUE_INDEX = 'user_role_unique';

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
    public function assign(string $userId, ?string $roleId, ?string $ipAddress, array $denialSubject): RoleView
    {
        $identity = $this->identities->authenticated();

        try {
            $outcome = $this->tenants->run(
                $this->tenant->tenantId(),
                fn (EntityManagerInterface $em): RoleView|LockTimeDenial => $this->assignInside($em, $identity, $userId, $roleId, $ipAddress, $denialSubject),
            );
        } catch (UniqueConstraintViolationException $e) {
            // Caught OUTSIDE `run()`: the failed statement aborted the transaction and closed the entity manager. Only an
            // out-of-band writer (the owner process, which never takes the lock) can insert the pair between step 8 and 10.
            if (str_contains($e->getMessage(), self::UNIQUE_INDEX)) {
                throw GrantRules::alreadyHeld();
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
    private function assignInside(EntityManagerInterface $em, Identity $identity, string $userId, ?string $roleId, ?string $ipAddress, array $denialSubject): RoleView|LockTimeDenial
    {
        // 1.
        RoleAdministrationLock::holdInside($em, $this->tenant->tenantId());

        // 2. Before any read of the subject (E-1).
        $actorCodes = $this->actor->reCheckUnderTheLock($em, $identity, $ipAddress, $denialSubject);
        if ($actorCodes instanceof LockTimeDenial) {
            return $actorCodes;
        }

        // 3.–9. The grant rules, as `GrantRules`' phases (roles slice 3, C1; design §4.7). **C1 keeps the shipped order:**
        // routing sits in the temporary slot S, between phases 1 and 2, exactly where step 6 always stood — so no outcome
        // moves and `RoleAssignTest` is byte-identical. C2 moves routing to slot R (last), with the slice-3 sentence.
        ['user_id' => $userId, 'subject' => $subject, 'role_id' => $roleId, 'role' => $role] = GrantRules::subjectAndRole($em, $userId, $roleId);

        // 3.–5. Phase 1: V2-R-6, E-15a, E-15b.
        GrantRules::refuseSubjectRoleAndMarker(GrantRules::ROUTE_ASSIGN, $subject, $role, $userId, $identity->userId, $actorCodes);

        // 6. Slot S (C1 only): E-15e routing.
        if (GrantConfirmationPolicy::needsConfirmation($actorCodes, $role['codes'])) {
            throw ApiProblem::businessRuleViolated('user_role.needs_owner_confirmation', 'Only an owner can give someone this role, because it includes permissions that only an owner can hand out. Ask an owner to give it.');
        }

        // 7. Phase 2: E-15c.
        GrantRules::refuseBeyondGrantor(GrantRules::ROUTE_ASSIGN, $role, $actorCodes);

        // 8. Phase 3: C12.
        GrantRules::refuseAlreadyHeld($em, $userId, $roleId);

        // 9. Phase 4: PC-13.
        GrantRules::refuseConflict($em, $userId, $role);

        // 10. Phase 5: the one INSERT.
        GrantRules::insert($em, $identity->tenantId, $userId, $roleId);

        // 11.
        $this->audit->recordAssigned(
            tenantId: $identity->tenantId,
            actorUserId: $identity->userId,
            actorLabel: $identity->actorLabel,
            subjectUserId: $userId,
            roleId: $roleId,
            ipAddress: $ipAddress,
        );

        // 12. After the insert and the audit write, under the lock (S-1 (b)).
        return $this->roles->get($roleId) ?? throw new \LogicException('The role just granted is not visible to its own transaction.');
    }

    /**
     * `UX`'s sentence (`roles-s2-ux-copy.md` §3.10): the role by name, one clause per SoD row about the WORK (never a code),
     * the row ids in brackets at the end.
     *
     * @param list<string> $rows
     */
    public static function separationOfDutyMessage(string $roleName, array $rows): string
    {
        $clauses = array_map(static fn (string $row): string => self::SOD_CLAUSES[$row] ?? throw new \LogicException(
            'SoD row '.$row.' has no sentence in RoleAssignment::SOD_CLAUSES.'), $rows);
        $last = array_pop($clauses);
        $joined = [] === $clauses ? (string) $last : implode(', ', $clauses).' and '.$last;

        return \sprintf('%s can\'t be given to this person: with the roles they already have, one person could %s (%s). '
            .'Choose another role or another person.', $roleName, $joined, implode(', ', $rows));
    }
}

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
 *  6. **E-15c:** a composed role (`is_system = false`) whose codes are not a subset of the actor's → 409
 *     `user_role.exceeds_actor_permissions`.
 *  7. **C12:** already held → 409 `user_role.already_held` (a race loser against an out-of-band writer gets the same code
 *     from the translated `23505` on `user_role_unique`).
 *  7a. **Slot B (roles slice 3 C3; design §5 change 2, D-7 / Q-4 (f)):** the (user, role) pair already has a `pending`
 *     proposal — live or expired, whoever the actor is → 409 `user_role.proposal_pending`, always. Its sentence is one of
 *     three (`UX` #36a–c, F-5): chosen by `expires_at > $at` and the pure routing policy — it decides nothing. The
 *     refusal does not carry the proposal's id; the screen finds it through `ListRoleGrantProposals?user=&role=&
 *     status=pending` (Q-4 (d)).
 *  8. **PC-13 / E-18:** {@see \App\Modules\UserManagement\Domain\GrantConflict::newRows()} over the subject's LIVE roles → 409 `user_role.separation_of_duty`,
 *     naming each SoD row id, never the codes.
 *  9. **E-15e routing, LAST** (roles slice 3 C2; design §4.7 slot R, §5 change 3): {@see GrantConfirmationPolicy} → 409
 *     `user_role.needs_owner_confirmation`, whose sentence **names the proposal route in the sentence; the body stays
 *     `rule` + `message`** (`UX` `roles-s3-ux-copy.md` #28, ruled 2026-10-05 — it replaced the pre-written slice-2 §3.5
 *     text, which promised a decision nothing delivers). Because routing is last, "you can propose it" is said only when
 *     every rule the proposal route applies has already passed here; a request that fails routing AND E-15c, C12 or PC-13
 *     gets that rule's refusal instead (each was `needs_owner_confirmation` until C2 — shipped behaviour changed, owner
 *     told). *(Slice 2 ran routing at step 6, before E-15c, and named no route, because none existed.)*
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
 * `OwnerPredicate::holdsMarker()` over that lock-time set. No PHP clock stamps or decides anything. **Since roles slice 3 C3
 * there is ONE database clock read** (step 2a, `SELECT clock_timestamp()` right after the actor re-check, design §4.1
 * step 3's position): it is compared in SQL with a pending proposal's `expires_at` ONLY to choose slot B's sentence; the
 * refusal itself stays free of any time comparison (Q-4 (f), owner notice given 2026-10-05).
 *
 * **Roles slice 3:** steps 3–8 and 10 are `GrantRules`' phases 1–5 — the one copy of the grant rules, shared with
 * `CreateRoleGrantProposal` (C1) and `ConfirmRoleGrantProposal` (C2). C1 kept routing in the temporary slot S between
 * phases 1 and 2 (behaviour unchanged); **C2 moved it to slot R, last before the insert** (design §4.7, §5 change 3).
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

    /**
     * The last clause of PC-13's sentence on the routes whose reader can act on it (`AssignRole`, Create): the shipped
     * slice-2 text (`roles-s2-ux-copy.md` §3.10). Confirm's reader cannot, so its tail is its own (`GrantRules::SOD_TAILS`).
     */
    public const SOD_SHIPPED_TAIL = 'Choose another role or another person.';

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
     * `UX` `roles-s3-ux-copy.md` #28 (ruled 2026-10-05, F-3), from roles slice 3 C2: the proposal route exists, so the
     * refusal names it — and promises no decision, since nothing tells an owner a proposal is waiting (G-31).
     */
    public const NEEDS_OWNER_CONFIRMATION = 'Giving someone this role needs an owner\'s confirmation. You can propose it instead; it takes effect only if an owner confirms it within 14 days.';

    /**
     * `UX` #36a–#36c (ruled 2026-10-05, F-5; 36a revised by RED M-2), from roles slice 3 C3: `user_role.proposal_pending`,
     * one rule, three sentences — a live proposal; an expired one where this actor's grant needs confirmation (a new
     * proposal would replace it); an expired one where it does not (it must be rejected or withdrawn first).
     */
    public const PROPOSAL_PENDING_LIVE = 'This role has already been proposed for this person, and that proposal is still open, so the role can\'t be given this way. You can find it among the open role proposals.';

    public const PROPOSAL_PENDING_EXPIRED_PROPOSABLE = 'This role was proposed for this person more than 14 days ago. That proposal can\'t be confirmed any more, but it is still open. A new proposal would replace it.';

    public const PROPOSAL_PENDING_EXPIRED_GIVE_DIRECTLY = 'This role was proposed for this person more than 14 days ago. That proposal can\'t be confirmed any more, but it is still open, so it has to be rejected or withdrawn before the role can be given directly. You can find it among the open role proposals.';

    /**
     * Slot B — names `role_grant_proposal` only (T2: no statement naming `user_role` names proposals). `live` is bound to
     * the post-lock `$at` — Create step 8's shape — never `now()`, which is the transaction's start, before the lock wait.
     */
    private const PENDING_SAME_PAIR = <<<'SQL'
        SELECT expires_at > ?::timestamptz AS live
          FROM role_grant_proposal
         WHERE user_id = ?::uuid AND role_id = ?::uuid AND status = 'pending'
        SQL;

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

        // 2a. The post-lock database instant (roles slice 3 C3; `UX` F-5): used ONLY to choose slot B's sentence.
        $atText = (string) $em->getConnection()->fetchOne('SELECT clock_timestamp()::text');

        // 3.–9. The grant rules, as `GrantRules`' phases (roles slice 3; design §4.7), routing LAST in slot R (C2).
        ['user_id' => $userId, 'subject' => $subject, 'role_id' => $roleId, 'role' => $role] = GrantRules::subjectAndRole($em, $userId, $roleId);

        // 3.–5. Phase 1: V2-R-6, E-15a, E-15b.
        GrantRules::refuseSubjectRoleAndMarker(GrantRules::ROUTE_ASSIGN, $subject, $role, $userId, $identity->userId, $actorCodes);

        // 6. Phase 2: E-15c.
        GrantRules::refuseBeyondGrantor(GrantRules::ROUTE_ASSIGN, $role, $actorCodes);

        // 7. Phase 3: C12.
        GrantRules::refuseAlreadyHeld($em, $userId, $roleId);

        // 7a. Slot B (C3): the pair already has a pending proposal — live or expired, whoever the actor is (D-7 / Q-4 (f)).
        // The refusal is unconditional; `live` (against the post-lock `$at`, never `now()`) and the pure routing policy
        // choose only the sentence.
        $pending = $em->getConnection()->fetchAssociative(self::PENDING_SAME_PAIR, [$atText, $userId, $roleId]);
        if (false !== $pending) {
            throw ApiProblem::businessRuleViolated('user_role.proposal_pending', match (true) {
                true === $pending['live'] => self::PROPOSAL_PENDING_LIVE,
                GrantConfirmationPolicy::needsConfirmation($actorCodes, $role['codes']) => self::PROPOSAL_PENDING_EXPIRED_PROPOSABLE,
                default => self::PROPOSAL_PENDING_EXPIRED_GIVE_DIRECTLY,
            });
        }

        // 8. Phase 4: PC-13.
        GrantRules::refuseConflict(GrantRules::ROUTE_ASSIGN, $em, $userId, $role);

        // 9. Slot R: E-15e routing, last (C2). The sentence names the proposal route (`UX` #28).
        if (GrantConfirmationPolicy::needsConfirmation($actorCodes, $role['codes'])) {
            throw ApiProblem::businessRuleViolated('user_role.needs_owner_confirmation', self::NEEDS_OWNER_CONFIRMATION);
        }

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
     * the row ids in brackets, then the route's tail. `$tail` defaults to the shipped one, so every slice-2 caller and pin
     * is byte-identical; Confirm passes its own (`UX` flag F-2, ruled 2026-10-05; `GrantRules::SOD_TAILS`).
     *
     * @param list<string> $rows
     */
    public static function separationOfDutyMessage(string $roleName, array $rows, string $tail = self::SOD_SHIPPED_TAIL): string
    {
        $clauses = array_map(static fn (string $row): string => self::SOD_CLAUSES[$row] ?? throw new \LogicException(
            'SoD row '.$row.' has no sentence in RoleAssignment::SOD_CLAUSES.'), $rows);
        $last = array_pop($clauses);
        $joined = [] === $clauses ? (string) $last : implode(', ', $clauses).' and '.$last;

        return \sprintf('%s can\'t be given to this person: with the roles they already have, one person could %s (%s). %s',
            $roleName, $joined, implode(', ', $rows), $tail);
    }
}

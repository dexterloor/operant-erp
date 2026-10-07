<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Modules\AuditLog\Infrastructure\RoleAuditWriter;
use App\Modules\UserManagement\Infrastructure\Entity\RoleGrantProposal;
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
 * **`CreateRoleGrantProposal` — propose a grant that needs an owner's confirmation** (E-15e; roles slice 3, C1;
 * `docs/session13/roles-s3-design.md` §4.1–§4.2, §4.7; `SECURITY_MODEL.md` §5.2.3–§5.2.8).
 *
 * One transaction, in this order — the order is the design and is pinned by the two-failure boundary cases in
 * `GrantRoutingTest`:
 *  - **Prefix (every proposal write, §4.1):** `RoleAdministrationLock` first; then the actor re-checked under the lock
 *    (`RoleAdministrationActor`: `role.assign` re-derived — a missing code writes the denial row and returns a
 *    {@see LockTimeDenial}, thrown as the gate's own 403 outside `run()`; an inactive actor → `actor_is_inactive`), BEFORE
 *    any subject or proposal read (E-1); then ONE `clock_timestamp()` read after the lock, `$at`, the instant every
 *    decision in this request compares against (S-11; never `now()`, never a PHP clock).
 *  1–2. The subject (`{id}` → 404) and the role (`role_id` → 422), `GrantRules::subjectAndRole()`.
 *  3–5. `GrantRules` phase 1 — V2-R-6, E-15a (Create's own sentence), E-15b / Q5 (`marker_needs_owner`, naming no route).
 *  6. Phase 2 — E-15c on the PROPOSER's set (Create's own sentence).
 *  7. Phase 3 — C12 `already_held`.
 *  8. Slot B — the same (user, role) already pending, **read only**: live (`expires_at > $at`) → 409
 *     `role_grant_proposal.already_pending`; expired → remembered for step 11.
 *  9. Phase 4 — PC-13 "new conflict only" over the subject's held set (other pending proposals are not considered:
 *     confirmation is the control).
 *  10. Slot R — **inverse routing, last** (RED-3 M-1): a grant that needs no confirmation → 409
 *     `role_grant_proposal.grant_directly`. Every rule `AssignRole` applies has already been applied here, so "give it
 *     directly" is said only when `AssignRole` would admit it. An owner never passes this step: **an owner never proposes.**
 *     Since C3 its sentence has a variant (`UX` #13a): when step 8 remembered an expired same-pair proposal, `AssignRole`
 *     would refuse that pair (`proposal_pending`), so the sentence says the old proposal must be closed first.
 *  11. If step 8 remembered an expired proposal: `find` + `refresh`; **if it is no longer pending** (only an out-of-band
 *     owner process can decide it between steps 8 and 11 — every API writer holds the lock) there is nothing to lapse;
 *     otherwise `lapse('expired', $at)`, `flush()`, `role.assignment_lapsed`. Then the proposal `INSERT` — raw DBAL,
 *     AFTER the lapse flush (F-9: the unit of work would run the INSERT before the UPDATE and collide on the pending
 *     unique), naming exactly the eight column-granted columns, with `proposed_at` and `expires_at` from one
 *     `clock_timestamp()` in its own SQL and the window in HOURS (RED L-4).
 *  12. `role.assignment_proposed`, `{role_id, proposal_id[, supersedes_proposal_id]}`, the request's IP.
 *  13. The body: the proposal read back under the lock. 201; `Location` is `GetRoleGrantProposal`'s URI (API-21 branch 4).
 *
 * A `23505` on `role_grant_proposal_pending_unique` (only an out-of-band writer can race past step 8) is caught OUTSIDE
 * `run()` and answered `already_pending` — the same code as the pre-check, and what a replay of this request's own
 * `Idempotency-Key` meets (§4.2's `unique:` mechanism: the header is demanded and not stored).
 *
 * **The refusal sentences are `UX`'s** (`docs/session13/roles-s3-ux-copy.md` §3), verbatim and pinned in
 * `RoleGrantProposalTest`.
 */
final readonly class RoleGrantProposalCreation
{
    /** The proposal window, in HOURS — never `'14 days'`, which adds calendar days in the session `TimeZone` (RED L-4). */
    public const WINDOW = '336 hours';

    /** `UX` #13. */
    public const GRANT_DIRECTLY = 'This role doesn\'t need anyone\'s confirmation when you give it, so there\'s nothing to propose. Give it to this person directly instead.';

    /** `UX` #13a (C3): step 8 remembered an expired same-pair proposal, which `AssignRole`'s slot B would refuse on. */
    public const GRANT_DIRECTLY_AFTER_CLEARING = 'This role doesn\'t need anyone\'s confirmation when you give it, so there\'s nothing to propose. An earlier proposal for this person and role is more than 14 days old but still open; it has to be rejected or withdrawn before the role can be given directly. You can find it among the open role proposals.';

    private const PENDING_UNIQUE = 'role_grant_proposal_pending_unique';

    private const NOT_SELF = 'role_grant_proposal_not_self';

    /** Step 8 — names `role_grant_proposal` only (T2: no statement naming `user_role` may also name proposals). */
    private const PENDING_SAME_PAIR = <<<'SQL'
        SELECT id::text AS id, expires_at > ?::timestamptz AS live
          FROM role_grant_proposal
         WHERE user_id = ?::uuid AND role_id = ?::uuid AND status = 'pending'
        SQL;

    /** Step 11 — the column list IS the column INSERT grant (`Version20261004000100`); `status` defaults to `pending`. */
    private const INSERT = <<<'SQL'
        INSERT INTO role_grant_proposal (id, tenant_id, user_id, role_id, permission_codes, proposed_by_user_id, proposed_at, expires_at)
        SELECT ?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::jsonb, ?::uuid, c.t, c.t + interval '%s'
          FROM (SELECT clock_timestamp() AS t) c
        SQL;

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private RoleAuditWriter $audit,
        private RoleAdministrationActor $actor,
        private RoleGrantProposalReader $proposals,
    ) {
    }

    /**
     * @param array{0: ?string, 1: ?string} $denialSubject what `PermissionGate::denialSubjectOf()` resolves for this request
     */
    public function propose(string $userId, ?string $roleId, ?string $ipAddress, array $denialSubject): RoleGrantProposalView
    {
        $identity = $this->identities->authenticated();

        try {
            $outcome = $this->tenants->run(
                $this->tenant->tenantId(),
                fn (EntityManagerInterface $em): RoleGrantProposalView|LockTimeDenial => $this->proposeInside($em, $identity, $userId, $roleId, $ipAddress, $denialSubject),
            );
        } catch (UniqueConstraintViolationException $e) {
            // Caught OUTSIDE `run()`: the failed statement aborted the transaction and closed the entity manager.
            if (str_contains($e->getMessage(), self::PENDING_UNIQUE)) {
                throw self::alreadyPending();
            }

            throw $e;
        } catch (DriverException $e) {
            // E-15a is a CHECK as well as phase 1's refusal (§5.2.4): unreachable while the handler check comes first, and
            // translated so that a future writer's violation is never a 500 (the `22003` lesson; design §4.3, T5).
            if ('23514' === $e->getSQLState() && str_contains($e->getMessage(), self::NOT_SELF)) {
                throw ApiProblem::businessRuleViolated('user_role.actor_is_subject', 'Nobody can propose a role for themselves. Ask someone else who manages roles to do it.');
            }

            throw $e;
        }

        if ($outcome instanceof LockTimeDenial) {
            throw ApiProblem::permissionDenied($outcome->permissionCode);
        }

        return $outcome;
    }

    /** @param array{0: ?string, 1: ?string} $denialSubject */
    private function proposeInside(EntityManagerInterface $em, Identity $identity, string $userId, ?string $roleId, ?string $ipAddress, array $denialSubject): RoleGrantProposalView|LockTimeDenial
    {
        RoleAdministrationLock::holdInside($em, $this->tenant->tenantId());

        $actorCodes = $this->actor->reCheckUnderTheLock($em, $identity, $ipAddress, $denialSubject, 'role.assign');
        if ($actorCodes instanceof LockTimeDenial) {
            return $actorCodes;
        }

        $connection = $em->getConnection();
        // §4.1 step 3: the post-lock database instant, with its microseconds intact (`DATA` §2).
        $atText = (string) $connection->fetchOne('SELECT clock_timestamp()::text');
        $at = new \DateTimeImmutable($atText);

        // 1–2.
        ['user_id' => $userId, 'subject' => $subject, 'role_id' => $roleId, 'role' => $role] = GrantRules::subjectAndRole($em, $userId, $roleId);

        // 3–5. Phase 1.
        GrantRules::refuseSubjectRoleAndMarker(GrantRules::ROUTE_PROPOSE, $subject, $role, $userId, $identity->userId, $actorCodes);

        // 6. Phase 2, on the proposer's set.
        GrantRules::refuseBeyondGrantor(GrantRules::ROUTE_PROPOSE, $role, $actorCodes);

        // 7. Phase 3.
        GrantRules::refuseAlreadyHeld($em, $userId, $roleId);

        // 8. Slot B: read only.
        $pending = $connection->fetchAssociative(self::PENDING_SAME_PAIR, [$atText, $userId, $roleId]);
        if (false !== $pending && true === $pending['live']) {
            throw self::alreadyPending();
        }
        $expired = false === $pending ? null : (string) $pending['id'];

        // 9. Phase 4.
        GrantRules::refuseConflict(GrantRules::ROUTE_PROPOSE, $em, $userId, $role);

        // 10. Slot R: inverse routing, last.
        // `UX` #13 / #13a (F-4, ruled 2026-10-05; C3): from C3 `AssignRole` refuses a pair with an open proposal, so "give it
        // directly" is false while step 8 remembered an expired one — that variant says it must be closed first.
        if (!GrantConfirmationPolicy::needsConfirmation($actorCodes, $role['codes'])) {
            throw ApiProblem::businessRuleViolated('role_grant_proposal.grant_directly', null === $expired ? self::GRANT_DIRECTLY : self::GRANT_DIRECTLY_AFTER_CLEARING);
        }

        // 11. The lapse of an expired same-pair proposal, flushed BEFORE the successor's INSERT (F-9).
        $supersedes = null;
        if (null !== $expired) {
            $old = $em->find(RoleGrantProposal::class, Uuid::fromString($expired));
            if (null !== $old) {
                $em->refresh($old);
            }
            if (null !== $old && RoleGrantProposal::STATUS_PENDING === $old->getStatus()) {
                $old->lapse('expired', $at);
                $em->flush();
                $this->audit->recordLapsed(
                    tenantId: $identity->tenantId,
                    actorUserId: $identity->userId,
                    actorLabel: $identity->actorLabel,
                    subjectUserId: $userId,
                    roleId: $roleId,
                    proposalId: $expired,
                    lapseReason: 'expired',
                    ipAddress: $ipAddress,
                );
                $supersedes = $expired;
            }
        }

        $proposalId = Uuid::v4()->toRfc4122();
        // The window is ONE PHP constant interpolated as a literal, never input (design §4.2 step 11).
        $connection->executeStatement(sprintf(self::INSERT, self::WINDOW), [
            $proposalId,
            $identity->tenantId,
            $userId,
            $roleId,
            json_encode($role['codes'], \JSON_THROW_ON_ERROR),
            $identity->userId,
        ]);

        // 12.
        $this->audit->recordProposed(
            tenantId: $identity->tenantId,
            actorUserId: $identity->userId,
            actorLabel: $identity->actorLabel,
            subjectUserId: $userId,
            roleId: $roleId,
            proposalId: $proposalId,
            supersedesProposalId: $supersedes,
            ipAddress: $ipAddress,
        );

        // 13. Under the lock, in this transaction (same tenant ⇒ `run()` joins).
        return $this->proposals->get($proposalId) ?? throw new \LogicException('The proposal just inserted is not visible to its own transaction.');
    }

    private static function alreadyPending(): ApiProblem
    {
        return ApiProblem::businessRuleViolated('role_grant_proposal.already_pending', 'This role has already been proposed for this person, and that proposal is still open. You can find it among the open role proposals.');
    }
}

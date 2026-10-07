<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Modules\AuditLog\Infrastructure\RoleAuditWriter;
use App\Modules\UserManagement\Application\Contract\OwnerPredicate;
use App\Modules\UserManagement\Infrastructure\Entity\AppUser;
use App\Modules\UserManagement\Infrastructure\Entity\RoleGrantProposal;
use App\Platform\Domain\IllegalTransition;
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
 * **`ConfirmRoleGrantProposal` — an owner confirms a proposed grant, and confirmation IS the grant, "as if the confirmer
 * were granting now"** (E-15e; roles slice 3, C2; `docs/session13/roles-s3-design.md` §4.1, §4.3, §4.7;
 * `SECURITY_MODEL.md` §5.2.3–§5.2.8; owner ruling Q-6 (c′), 2026-10-01).
 *
 * One transaction, in this order — one total order, pinned by the two-failure boundary cases (`GrantRoutingTest`):
 *  - **Prefix (§4.1):** `RoleAdministrationLock` first; the actor re-checked under the lock (`RoleAdministrationActor`:
 *    `role.assign` re-derived — missing → the denial row inside this transaction, a {@see LockTimeDenial}, the gate's own
 *    403 thrown outside `run()`; inactive → `actor_is_inactive`). **Then the owner check, Q-6 (c′):**
 *    `OwnerPredicate::holdsMarker()` over the LOCK-TIME codes that call returned (never the request's set, S-6), else 409
 *    `role_grant_proposal.decider_not_owner` — **before the proposal is read, so a non-owner gets the same refusal for any
 *    id**, existing or not (`UX` rule 5). Then ONE post-lock `clock_timestamp()`, `$at`.
 *  1. `{id}` not a uuid, absent, or another workspace's → 404.
 *  2. `find()` + **`refresh()`** — the identity map may hold a stale copy (S-12/S-13; `InvoicePayment`'s precedent).
 *  3. **Status guard:** `$proposal->confirm($at, $confirmer)` — anything but `pending` → `IllegalTransition`, rewritten in
 *     the flow's vocabulary ({@see RoleGrantProposalStatusRefusal}; `UX` F-1). Nothing is flushed before step 11.
 *  4. **C15 expiry, in SQL** against `$at` → `role_grant_proposal.expired`. Writes nothing (R-13: an untouched stale
 *     proposal stays `pending`; Reject and Withdraw close it from C3).
 *  5. **C4:** confirmer = proposer → `role_grant_proposal.confirmer_is_proposer` (also a CHECK).
 *  6. **C8 / C9, the proposer:** deleted or inactive → `proposer_is_inactive`; no longer holds `role.assign` through a live
 *     role (`LiveGrantReader::codesHeldBy`) → `proposer_cannot_assign`.
 *  7. **`GrantRules` phase 1** on the Confirm route: V2-R-6 (archived role → Confirm's own sentence), E-15a (confirmer =
 *     subject → Confirm's own sentence; also a CHECK), E-15b (trivially met by the owner check; re-run anyway).
 *  8. **C14 snapshot:** the role's live codes equal `permission_codes` **as sets** — both decoded and sorted in PHP, never
 *     one PHP-sorted and one PG-sorted (RED L-6) — else `role_grant_proposal.role_changed`. Covers removals too.
 *  9. **`GrantRules` phases 2–4** on the CONFIRMER's set: E-15c, C12, PC-13 "new conflict only" over the subject's set as
 *     it stands now (Confirm's SoD tail). **Routing is NOT re-tested** (design §2.2, ratified): the confirmer is an owner.
 *     Then **phase 5**, the one `INSERT INTO user_role` every grant goes through.
 * 10. `role.assigned`, actor = the confirmer (the granter of record), `metadata {role_id, proposal_id,
 *     proposed_by_user_id}` (Q1).
 * 11. `flush()`: `status`, `decided_by_user_id`, `decided_at`, `updated_at` — inside the column UPDATE grant
 *     (`Version20261005000100`); a fifth column would be a `42501` (fix the entity, never the grant).
 * 12. **The body:** the proposal, now `confirmed`, read under the lock. **200** (API-21 branch 5).
 *
 * **Translations outside `run()`:** `23505` on `user_role_unique` (only an out-of-band writer can race past step 9) →
 * `already_held`; `23514` on `role_grant_proposal_confirmer_not_proposer` / `…_confirmer_not_subject` → the handler codes
 * above. Unreachable while the handler checks come first; they exist so a future writer's violation is never a 500.
 *
 * Every refusal sentence is `UX`'s (`docs/session13/roles-s3-ux-copy.md` §4.1, the **C3** texts since roles slice 3 C3,
 * which moved them from the C2 texts once Reject and Withdraw existed), verbatim and pinned in
 * `RoleGrantProposalConfirmTest`.
 */
final readonly class RoleGrantProposalConfirmation
{
    /**
     * `UX` #14/#29, the C3 text, SHARED by Confirm and Reject (C2 read "…can confirm role proposals…"). Says "role
     * proposals", never "this proposal": it is given for any id, before the proposal is read (`UX` rule 5).
     */
    public const DECIDER_NOT_OWNER = 'Only an owner can confirm or reject role proposals. Ask an owner.';

    /**
     * `UX` #16–#19 and #24, the C3 texts (roles slice 3 C3 moved them from the C2 texts: Reject and Withdraw exist now,
     * so each sentence says how the open proposal is closed — `UX` rule 4).
     */
    public const EXPIRED = 'This proposal is more than 14 days old, so it can\'t be confirmed any more. It can still be rejected or withdrawn to close it, and then the role can be given directly if it\'s still wanted.';

    public const CONFIRMER_IS_PROPOSER = 'You proposed this, so you can\'t confirm it too. Another owner has to confirm it. You can still withdraw it.';

    public const PROPOSER_IS_INACTIVE = 'The person who proposed this is no longer active in this workspace, so the proposal can\'t be confirmed. Reject it to close it; then the role can be given directly if it\'s still wanted.';

    public const PROPOSER_CANNOT_ASSIGN = 'The person who proposed this can no longer manage roles, so the proposal can\'t be confirmed. Reject it to close it; then the role can be given directly if it\'s still wanted.';

    public const ROLE_CHANGED = 'This role\'s permissions have changed since it was proposed, so the proposal can\'t be confirmed: it would give something other than what was proposed. Reject it to close it; then the role can be given directly if it\'s still wanted.';

    /** `UX` #22, the C3 text — Confirm's `actor_is_subject`, also what the translated `23514` answers. */
    private const CONFIRMER_IS_SUBJECT = 'You can\'t confirm a role proposed for yourself. Another owner has to confirm it. You can reject it if you don\'t want it.';

    /** The code a proposer needs to have made the proposal, re-checked at confirmation (C9). */
    private const PROPOSER_CODE = 'role.assign';

    private const USER_ROLE_UNIQUE = 'user_role_unique';

    private const CONFIRMER_NOT_PROPOSER = 'role_grant_proposal_confirmer_not_proposer';

    private const CONFIRMER_NOT_SUBJECT = 'role_grant_proposal_confirmer_not_subject';

    /** Step 4 and the facts steps 5–8 read — names `role_grant_proposal` only (T2). */
    private const PROPOSAL_FACTS = <<<'SQL'
        SELECT expires_at > ?::timestamptz AS live, user_id::text AS user_id, role_id::text AS role_id,
               proposed_by_user_id::text AS proposed_by, permission_codes::text AS codes
          FROM role_grant_proposal
         WHERE id = ?::uuid
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
    public function confirm(string $proposalId, ?string $ipAddress, array $denialSubject): RoleGrantProposalView
    {
        $identity = $this->identities->authenticated();

        try {
            $outcome = $this->tenants->run(
                $this->tenant->tenantId(),
                fn (EntityManagerInterface $em): RoleGrantProposalView|LockTimeDenial => $this->confirmInside($em, $identity, $proposalId, $ipAddress, $denialSubject),
            );
        } catch (UniqueConstraintViolationException $e) {
            // Caught OUTSIDE `run()`: the failed statement aborted the transaction and closed the entity manager.
            if (str_contains($e->getMessage(), self::USER_ROLE_UNIQUE)) {
                throw GrantRules::alreadyHeld();
            }

            throw $e;
        } catch (DriverException $e) {
            if ('23514' === $e->getSQLState() && str_contains($e->getMessage(), self::CONFIRMER_NOT_PROPOSER)) {
                throw ApiProblem::businessRuleViolated('role_grant_proposal.confirmer_is_proposer', self::CONFIRMER_IS_PROPOSER);
            }
            if ('23514' === $e->getSQLState() && str_contains($e->getMessage(), self::CONFIRMER_NOT_SUBJECT)) {
                throw ApiProblem::businessRuleViolated('user_role.actor_is_subject', self::CONFIRMER_IS_SUBJECT);
            }

            throw $e;
        }

        if ($outcome instanceof LockTimeDenial) {
            throw ApiProblem::permissionDenied($outcome->permissionCode);
        }

        return $outcome;
    }

    /** @param array{0: ?string, 1: ?string} $denialSubject */
    private function confirmInside(EntityManagerInterface $em, Identity $identity, string $proposalId, ?string $ipAddress, array $denialSubject): RoleGrantProposalView|LockTimeDenial
    {
        RoleAdministrationLock::holdInside($em, $this->tenant->tenantId());

        $actorCodes = $this->actor->reCheckUnderTheLock($em, $identity, $ipAddress, $denialSubject, 'role.assign');
        if ($actorCodes instanceof LockTimeDenial) {
            return $actorCodes;
        }

        // Q-6 (c′): owners only, on the lock-time codes, BEFORE the proposal is read — the same refusal for any id.
        if (!OwnerPredicate::holdsMarker($actorCodes)) {
            throw ApiProblem::businessRuleViolated('role_grant_proposal.decider_not_owner', self::DECIDER_NOT_OWNER);
        }

        $connection = $em->getConnection();
        // §4.1 step 3: the post-lock database instant, with its microseconds intact (`DATA` §2).
        $atText = (string) $connection->fetchOne('SELECT clock_timestamp()::text');
        $at = new \DateTimeImmutable($atText);

        // 1.
        if (!Uuid::isValid($proposalId)) {
            throw ApiProblem::notFound();
        }
        $proposalId = Uuid::fromString($proposalId)->toRfc4122();

        // 2. Under the lock, from the database, never the identity map's copy.
        $proposal = $em->find(RoleGrantProposal::class, Uuid::fromString($proposalId)) ?? throw ApiProblem::notFound();
        $em->refresh($proposal);

        // 3. The status guard — the ONE entity call this handler catches around (`UX` F-1).
        $confirmerId = Uuid::fromString($identity->userId)->toRfc4122();
        try {
            $proposal->confirm($at, $em->getReference(AppUser::class, Uuid::fromString($confirmerId)));
        } catch (IllegalTransition $refusal) {
            throw RoleGrantProposalStatusRefusal::rewrite($refusal);
        }

        $facts = $connection->fetchAssociative(self::PROPOSAL_FACTS, [$atText, $proposalId]);
        \assert(\is_array($facts));
        $userId = (string) $facts['user_id'];
        $roleId = (string) $facts['role_id'];
        $proposerId = (string) $facts['proposed_by'];

        // 4. C15.
        if (true !== $facts['live']) {
            throw ApiProblem::businessRuleViolated('role_grant_proposal.expired', self::EXPIRED);
        }

        // 5. C4.
        if ($proposerId === $confirmerId) {
            throw ApiProblem::businessRuleViolated('role_grant_proposal.confirmer_is_proposer', self::CONFIRMER_IS_PROPOSER);
        }

        // 6. C8 / C9.
        $proposer = LiveGrantReader::user($em, $proposerId);
        if (null === $proposer || $proposer['deleted'] || !$proposer['is_active']) {
            throw ApiProblem::businessRuleViolated('role_grant_proposal.proposer_is_inactive', self::PROPOSER_IS_INACTIVE);
        }
        if (!\in_array(self::PROPOSER_CODE, LiveGrantReader::codesHeldBy($em, $proposerId), true)) {
            throw ApiProblem::businessRuleViolated('role_grant_proposal.proposer_cannot_assign', self::PROPOSER_CANNOT_ASSIGN);
        }

        // 7. Phase 1, by id through DBAL — never the entity's proxies (SoftDeleteFilter; RED L-2).
        $subject = LiveGrantReader::user($em, $userId) ?? throw new \LogicException('A proposal names a user the workspace does not have.');
        $role = LiveGrantReader::role($em, $roleId) ?? throw new \LogicException('A proposal names a role the workspace does not have.');
        GrantRules::refuseSubjectRoleAndMarker(GrantRules::ROUTE_CONFIRM, $subject, $role, $userId, $identity->userId, $actorCodes);

        // 8. C14, as sets, both sides sorted in PHP.
        $snapshot = json_decode((string) $facts['codes'], true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($snapshot));
        $snapshot = array_map(strval(...), $snapshot);
        $live = $role['codes'];
        sort($snapshot);
        sort($live);
        if ($snapshot !== $live) {
            throw ApiProblem::businessRuleViolated('role_grant_proposal.role_changed', self::ROLE_CHANGED);
        }

        // 9. Phases 2–4 on the confirmer's set; routing is not re-tested. Then phase 5.
        GrantRules::refuseBeyondGrantor(GrantRules::ROUTE_CONFIRM, $role, $actorCodes);
        GrantRules::refuseAlreadyHeld($em, $userId, $roleId);
        GrantRules::refuseConflict(GrantRules::ROUTE_CONFIRM, $em, $userId, $role);
        GrantRules::insert($em, $identity->tenantId, $userId, $roleId);

        // 10.
        $this->audit->recordAssignedByConfirmation(
            tenantId: $identity->tenantId,
            actorUserId: $identity->userId,
            actorLabel: $identity->actorLabel,
            subjectUserId: $userId,
            roleId: $roleId,
            proposalId: $proposalId,
            proposedByUserId: $proposerId,
            ipAddress: $ipAddress,
        );

        // 11.
        $em->flush();

        // 12. Under the lock, in this transaction (same tenant ⇒ `run()` joins).
        return $this->proposals->get($proposalId) ?? throw new \LogicException('The proposal just confirmed is not visible to its own transaction.');
    }
}

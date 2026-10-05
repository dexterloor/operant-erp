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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`RejectRoleGrantProposal` — an owner turns a proposed grant down** (E-15e; roles slice 3, C3;
 * `docs/session13/roles-s3-design.md` §4.1, §4.4; `SECURITY_MODEL.md` §5.2.3–§5.2.8; owner rulings Q-5 (a), Q-6 (c′)).
 *
 * One transaction, in this order:
 *  - **Prefix (§4.1):** `RoleAdministrationLock` first; the actor re-checked under the lock (`RoleAdministrationActor`:
 *    `role.assign` re-derived — missing → the denial row, a {@see LockTimeDenial}, the gate's 403 outside `run()`;
 *    inactive → `actor_is_inactive`); **the owner check on the LOCK-TIME codes, before the proposal is read** — 409
 *    `role_grant_proposal.decider_not_owner`, the sentence Confirm shares (`UX` #29); one post-lock `clock_timestamp()`.
 *  1. `{id}` → 404. 2. `find()` + `refresh()`.
 *  3. **Status guard:** `reject()` — anything but `pending` is refused in the flow's words (`UX` F-1). **An expired
 *     pending proposal MAY be rejected**, as cleanup (§5.2.7): there is no expiry check here.
 *  4. Rejecter = proposer → `role_grant_proposal.rejecter_is_proposer` (R-15; also a CHECK).
 *  5. **Q-5 (a): the subject MAY reject a proposal naming them** — necessarily an owner. No check; the CHECK is
 *     `…_confirmer_not_subject`, confirmation only. The subject and role are never read through the entity's proxies
 *     (`SoftDeleteFilter`; RED L-2), so a proposal for a removed subject is rejectable.
 *  6. `reason`: absent, null or blank → null; not a string → 422 `not_a_string` on `/reason`; over 500 characters → 422
 *     `too_long` (`UX` #32, #33).
 *  7. `flush()` (`status`, `decided_by_user_id`, `decided_at`, `updated_at`, `reason`), then `role.assignment_rejected`
 *     `{role_id, proposal_id, proposed_by_user_id}` — never the reason text (PC-16 condition 3).
 *  8. The body: the proposal, now `rejected`, read under the lock. **200** (branch 5).
 *
 * A `23514` on `role_grant_proposal_rejecter_not_proposer` (unreachable while step 4 comes first) is translated outside
 * `run()`, so a future writer's violation is never a 500.
 */
final readonly class RoleGrantProposalRejection
{
    /** `UX` #31. */
    public const REJECTER_IS_PROPOSER = 'You proposed this, so you can\'t reject it. You can withdraw it instead.';

    /** `UX` #32. The house shape (`ChangePassword`'s "must be at least %d characters"). */
    public const REASON_TOO_LONG = 'The reason must be at most 500 characters.';

    /** `UX` #33, the shipped sentence (`PurchaseOrderWriter`). */
    public const NOT_A_STRING = 'This field must be sent as a string.';

    /** `role_grant_proposal_reason_only_on_reject`'s upper bound. */
    public const REASON_MAX = 500;

    private const REJECTER_NOT_PROPOSER = 'role_grant_proposal_rejecter_not_proposer';

    /** The facts step 4 reads — names `role_grant_proposal` only (T2). */
    private const PROPOSAL_FACTS = <<<'SQL'
        SELECT user_id::text AS user_id, role_id::text AS role_id, proposed_by_user_id::text AS proposed_by
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
    public function reject(string $proposalId, mixed $reason, ?string $ipAddress, array $denialSubject): RoleGrantProposalView
    {
        $identity = $this->identities->authenticated();

        try {
            $outcome = $this->tenants->run(
                $this->tenant->tenantId(),
                fn (EntityManagerInterface $em): RoleGrantProposalView|LockTimeDenial => $this->rejectInside($em, $identity, $proposalId, $reason, $ipAddress, $denialSubject),
            );
        } catch (DriverException $e) {
            if ('23514' === $e->getSQLState() && str_contains($e->getMessage(), self::REJECTER_NOT_PROPOSER)) {
                throw ApiProblem::businessRuleViolated('role_grant_proposal.rejecter_is_proposer', self::REJECTER_IS_PROPOSER);
            }

            throw $e;
        }

        if ($outcome instanceof LockTimeDenial) {
            throw ApiProblem::permissionDenied($outcome->permissionCode);
        }

        return $outcome;
    }

    /** @param array{0: ?string, 1: ?string} $denialSubject */
    private function rejectInside(EntityManagerInterface $em, Identity $identity, string $proposalId, mixed $reason, ?string $ipAddress, array $denialSubject): RoleGrantProposalView|LockTimeDenial
    {
        RoleAdministrationLock::holdInside($em, $this->tenant->tenantId());

        $actorCodes = $this->actor->reCheckUnderTheLock($em, $identity, $ipAddress, $denialSubject);
        if ($actorCodes instanceof LockTimeDenial) {
            return $actorCodes;
        }

        // Q-6 (c′): owners only, on the lock-time codes, BEFORE the proposal is read — the same refusal for any id.
        if (!OwnerPredicate::holdsMarker($actorCodes)) {
            throw ApiProblem::businessRuleViolated('role_grant_proposal.decider_not_owner', RoleGrantProposalConfirmation::DECIDER_NOT_OWNER);
        }

        $connection = $em->getConnection();
        $at = new \DateTimeImmutable((string) $connection->fetchOne('SELECT clock_timestamp()::text'));

        // 1.
        if (!Uuid::isValid($proposalId)) {
            throw ApiProblem::notFound();
        }
        $proposalId = Uuid::fromString($proposalId)->toRfc4122();

        // 2.
        $proposal = $em->find(RoleGrantProposal::class, Uuid::fromString($proposalId)) ?? throw ApiProblem::notFound();
        $em->refresh($proposal);

        // 6, read now so the transition is handed a clean value; its refusal is raised at its own place below.
        [$text, $reasonError] = self::reason($reason);

        // 3. The status guard — the ONE entity call this handler catches around (`UX` F-1).
        $rejecterId = Uuid::fromString($identity->userId)->toRfc4122();
        try {
            $proposal->reject($at, $em->getReference(AppUser::class, Uuid::fromString($rejecterId)), $text);
        } catch (IllegalTransition $refusal) {
            throw RoleGrantProposalStatusRefusal::rewrite($refusal);
        }

        $facts = $connection->fetchAssociative(self::PROPOSAL_FACTS, [$proposalId]);
        \assert(\is_array($facts));

        // 4. R-15.
        if ((string) $facts['proposed_by'] === $rejecterId) {
            throw ApiProblem::businessRuleViolated('role_grant_proposal.rejecter_is_proposer', self::REJECTER_IS_PROPOSER);
        }

        // 5. Q-5 (a): a subject may reject a proposal naming them — nothing to check.

        // 6.
        if (null !== $reasonError) {
            throw $reasonError;
        }

        // 7.
        $em->flush();
        $this->audit->recordRejected(
            tenantId: $identity->tenantId,
            actorUserId: $identity->userId,
            actorLabel: $identity->actorLabel,
            subjectUserId: (string) $facts['user_id'],
            roleId: (string) $facts['role_id'],
            proposalId: $proposalId,
            proposedByUserId: (string) $facts['proposed_by'],
            ipAddress: $ipAddress,
        );

        // 8.
        return $this->proposals->get($proposalId) ?? throw new \LogicException('The proposal just rejected is not visible to its own transaction.');
    }

    /**
     * Step 6 as a pure decision: the stored value, and the refusal to raise (or null).
     *
     * @return array{0: ?string, 1: ?ApiProblem}
     */
    public static function reason(mixed $reason): array
    {
        if (null === $reason) {
            return [null, null];
        }
        if (!\is_string($reason)) {
            return [null, ApiProblem::validationFailed([['pointer' => '/reason', 'code' => 'not_a_string', 'message' => self::NOT_A_STRING]])];
        }
        $reason = trim($reason);
        if ('' === $reason) {
            return [null, null];
        }
        if (mb_strlen($reason) > self::REASON_MAX) {
            return [null, ApiProblem::validationFailed([['pointer' => '/reason', 'code' => 'too_long', 'message' => self::REASON_TOO_LONG]])];
        }

        return [$reason, null];
    }
}

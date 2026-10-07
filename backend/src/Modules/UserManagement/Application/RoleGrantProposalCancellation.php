<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Modules\AuditLog\Infrastructure\RoleAuditWriter;
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
 * **`CancelRoleGrantProposal` — the proposer withdraws their own proposal** (E-15e; roles slice 3, C3;
 * `docs/session13/roles-s3-design.md` §4.1, §4.5; the copy says "withdraw", never "cancel").
 *
 * One transaction, in this order:
 *  - **Prefix (§4.1):** `RoleAdministrationLock` first; the actor re-checked under the lock (`role.assign` — missing →
 *    the denial row and the gate's 403; inactive → `actor_is_inactive`). **No owner check:** withdrawing needs only
 *    `role.assign`. One post-lock `clock_timestamp()`.
 *  1. `{id}` → 404. 2. `find()` + `refresh()`.
 *  3. **Status guard:** `cancel()` — anything but `pending` is refused in the flow's words (`UX` F-1). An expired pending
 *     proposal MAY be withdrawn (§5.2.7).
 *  4. Caller ≠ proposer → 409 `role_grant_proposal.not_the_proposer` — a 409, not a 404: the caller can list the row
 *     (design §2.3). Also CHECK `…_canceller_is_proposer`.
 *  5. `flush()` (`status`, `decided_by_user_id`, `decided_at`, `updated_at`), then `role.assignment_cancelled`
 *     `{role_id, proposal_id}`.
 *  6. The body: the proposal, now `cancelled`, read under the lock. **200** (branch 5).
 */
final readonly class RoleGrantProposalCancellation
{
    /** `UX` #35. */
    public const NOT_THE_PROPOSER = 'Only the person who proposed this can withdraw it. An owner can reject it instead.';

    private const CANCELLER_IS_PROPOSER = 'role_grant_proposal_canceller_is_proposer';

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
    public function cancel(string $proposalId, ?string $ipAddress, array $denialSubject): RoleGrantProposalView
    {
        $identity = $this->identities->authenticated();

        try {
            $outcome = $this->tenants->run(
                $this->tenant->tenantId(),
                fn (EntityManagerInterface $em): RoleGrantProposalView|LockTimeDenial => $this->cancelInside($em, $identity, $proposalId, $ipAddress, $denialSubject),
            );
        } catch (DriverException $e) {
            if ('23514' === $e->getSQLState() && str_contains($e->getMessage(), self::CANCELLER_IS_PROPOSER)) {
                throw ApiProblem::businessRuleViolated('role_grant_proposal.not_the_proposer', self::NOT_THE_PROPOSER);
            }

            throw $e;
        }

        if ($outcome instanceof LockTimeDenial) {
            throw ApiProblem::permissionDenied($outcome->permissionCode);
        }

        return $outcome;
    }

    /** @param array{0: ?string, 1: ?string} $denialSubject */
    private function cancelInside(EntityManagerInterface $em, Identity $identity, string $proposalId, ?string $ipAddress, array $denialSubject): RoleGrantProposalView|LockTimeDenial
    {
        RoleAdministrationLock::holdInside($em, $this->tenant->tenantId());

        $actorCodes = $this->actor->reCheckUnderTheLock($em, $identity, $ipAddress, $denialSubject, 'role.assign');
        if ($actorCodes instanceof LockTimeDenial) {
            return $actorCodes;
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

        // 3. The status guard — the ONE entity call this handler catches around (`UX` F-1).
        $callerId = Uuid::fromString($identity->userId)->toRfc4122();
        try {
            $proposal->cancel($at, $em->getReference(AppUser::class, Uuid::fromString($callerId)));
        } catch (IllegalTransition $refusal) {
            throw RoleGrantProposalStatusRefusal::rewrite($refusal);
        }

        $facts = $connection->fetchAssociative(self::PROPOSAL_FACTS, [$proposalId]);
        \assert(\is_array($facts));

        // 4. C16.
        if ((string) $facts['proposed_by'] !== $callerId) {
            throw ApiProblem::businessRuleViolated('role_grant_proposal.not_the_proposer', self::NOT_THE_PROPOSER);
        }

        // 5.
        $em->flush();
        $this->audit->recordCancelled(
            tenantId: $identity->tenantId,
            actorUserId: $identity->userId,
            actorLabel: $identity->actorLabel,
            subjectUserId: (string) $facts['user_id'],
            roleId: (string) $facts['role_id'],
            proposalId: $proposalId,
            ipAddress: $ipAddress,
        );

        // 6.
        return $this->proposals->get($proposalId) ?? throw new \LogicException('The proposal just withdrawn is not visible to its own transaction.');
    }
}

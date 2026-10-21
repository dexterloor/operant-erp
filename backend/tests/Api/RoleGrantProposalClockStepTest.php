<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\UserManagement\Infrastructure\Entity\RoleGrantProposal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * **A decision is never dated before the proposal it decides, even when the database clock steps back** (proposal clock
 * fix, 2026-10-21; `docs/session13/proposal-clock-fix-design.md` §3.1 as amended by `proposal-clock-fix-red.md` M-1).
 *
 * `proposed_at` and `decided_at` both come from the database's `clock_timestamp()`, in two transactions. That is a wall
 * clock: when it steps back between them (the Lima host agent pulls a fast guest back ~0.1 s every few seconds; NTP can
 * step a server) the decision reads earlier than the proposal and `role_grant_proposal_decided_not_before_proposed`
 * answered 23514 as a 500 — on Confirm, Reject and Withdraw alike. Shipped defect, fixed by clamping the stored decision
 * instant to the proposal's (`RoleGrantProposal::decisionInstant()`), within 60 seconds.
 *
 * **No clock is moved.** The proposal is seeded in the database's FUTURE as the owner, which is exactly what the decision
 * sees after a backward step, on any machine and independent of host skew. One provider row per operation: a refused or
 * failed request closes the shared entity manager.
 */
final class RoleGrantProposalClockStepTest extends ApiTestCase
{
    use RoleGrantFixtures;

    /**
     * **T-A:** a proposal 5 seconds in the database's future is decided — 200, the decided status, and `decided_at`
     * stored EXACTLY equal to `proposed_at` (the microsecond write type lands the clamp on it, not a fraction below).
     */
    #[Test]
    #[DataProvider('decisions')]
    public function aDecisionAfterABackwardClockStepIsDatedAtTheProposal(string $operation, string $status): void
    {
        $id = $this->decide($operation, -5);

        self::assertSame(200, $this->httpStatus(), $operation.': '.(string) $this->client()->getResponse()->getContent());
        self::assertSame($status, $this->json()['status']);
        self::assertSame([['equal' => true, 'not_before' => true]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT decided_at = proposed_at AS equal, decided_at >= proposed_at AS not_before FROM role_grant_proposal WHERE id = ?::uuid',
            [$id]), $operation.': the decision was not dated at the proposal it decides.');
    }

    /**
     * **T-B, the normal path still uses the clock:** a proposal a minute old is decided at the database's own instant —
     * strictly after the proposal, and recent. A clamp that always wrote `proposed_at` would be red here.
     */
    #[Test]
    #[DataProvider('decisions')]
    public function anOrdinaryDecisionIsDatedByTheClock(string $operation, string $status): void
    {
        $id = $this->decide($operation, 60);

        self::assertSame(200, $this->httpStatus(), $operation.': '.(string) $this->client()->getResponse()->getContent());
        self::assertSame($status, $this->json()['status']);
        self::assertSame([['after' => true, 'recent' => true]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            "SELECT decided_at > proposed_at AS after, decided_at >= now() - interval '1 minute' AS recent FROM role_grant_proposal WHERE id = ?::uuid",
            [$id]), $operation.': an ordinary decision was not dated by the database clock.');
    }

    /**
     * **M-1, the bound:** a proposal more than 60 seconds in the future is no clock step — it was written outside the
     * application (G-10's residual: SQL as `erp_app` can choose `proposed_at`). It is not silently decided in the future:
     * 500-class, as before the fix, the proposal still pending and no `role.*` row written.
     */
    #[Test]
    #[DataProvider('decisions')]
    public function aProposalFarInTheFutureIsNotDecided(string $operation, string $decidedStatus): void
    {
        self::assertNotSame(RoleGrantProposal::STATUS_PENDING, $decidedStatus);
        $id = $this->decide($operation, -120);

        self::assertSame(500, $this->httpStatus(), $operation.': a proposal two minutes in the future was decided.');
        self::assertSame([['status' => RoleGrantProposal::STATUS_PENDING, 'decided' => null]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT status, decided_at::text AS decided FROM role_grant_proposal WHERE id = ?::uuid', [$id]));
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM audit_log WHERE event_type LIKE 'role.%'"),
            $operation.': a refused decision wrote a trail row.');
    }

    /** @return iterable<string, array{string, string}> */
    public static function decisions(): iterable
    {
        yield 'confirm' => ['confirm', RoleGrantProposal::STATUS_CONFIRMED];
        yield 'reject' => ['reject', RoleGrantProposal::STATUS_REJECTED];
        yield 'withdraw' => ['withdraw', RoleGrantProposal::STATUS_CANCELLED];
    }

    /**
     * Seeds a pending proposal `$ageSeconds` old (negative: in the database's future) and decides it as that operation's
     * legal actor — an owner confirms or rejects, the proposer withdraws.
     */
    private function decide(string $operation, int $ageSeconds): string
    {
        $proposer = $this->principal(DefaultRoles::ADMIN);
        $id = $this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $this->systemRole(DefaultRoles::FINANCE), $proposer, 0, $ageSeconds);

        if ('withdraw' === $operation) {
            $this->actAsUser(self::TENANT_A, $proposer);
            $this->send('POST', '/role-grant-proposals/'.$id.'/cancellation');

            return $id;
        }

        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::OWNER));
        'confirm' === $operation
            ? $this->send('POST', '/role-grant-proposals/'.$id.'/confirmation')
            : $this->send('POST', '/role-grant-proposals/'.$id.'/rejection', []);

        return $id;
    }
}

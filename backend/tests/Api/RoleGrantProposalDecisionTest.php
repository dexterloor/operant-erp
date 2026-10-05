<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Application\RoleGrantProposalStatusRefusal;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\UserManagement\Domain\OwnerMarker;
use App\Modules\UserManagement\Infrastructure\Entity\RoleGrantProposal;
use App\SharedKernel\ValueObject\AuditEvent;
use App\SharedKernel\ValueObject\RoleEventMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **E-15e's other two decisions, C3: `RejectRoleGrantProposal` and `CancelRoleGrantProposal`** (roles slice 3;
 * `docs/session13/roles-s3-design.md` §4.1, §4.4, §4.5, §15; owner rulings Q-5 (a), Q-6 (c′); `UX`
 * `roles-s3-ux-copy.md` §4.3, §5.1, §5.2 — the copy says "withdraw", never "cancel").
 *
 * One rule per test: each 200 and its body (the proposal in its new state, PC-16's references `{id, display_name}`), the
 * one security row each (T11; ids only, never the reason text), the owner check before the proposal is read (T7, its
 * boundaries; the marker holder without `role.assign` → 403, **G-11's closing mechanism for Reject**), R-15 (the proposer
 * cannot reject — T6), Q-5 (a) (the subject may), the reason's bounds, cleanup of an EXPIRED pending proposal, a removed
 * subject (RED L-2), C16 (only the proposer withdraws), and the status guard in the flow's words (F-1). Every positive
 * rejection seeds the owner marker explicitly. **Every refusal's sentence is pinned as a literal.** The concurrency half
 * is `PurchaseOrderRowLockTest`'s.
 */
final class RoleGrantProposalDecisionTest extends ApiTestCase
{
    use RoleGrantFixtures;

    /** `UX` §5.1/§5.2, verbatim. */
    public const COPY = [
        'role_grant_proposal.decider_not_owner' => 'Only an owner can confirm or reject role proposals. Ask an owner.',
        'role_grant_proposal.rejecter_is_proposer' => 'You proposed this, so you can\'t reject it. You can withdraw it instead.',
        'role_grant_proposal.not_the_proposer' => 'Only the person who proposed this can withdraw it. An owner can reject it instead.',
    ];

    private const SHAPE = ['id', 'user', 'role', 'permission_codes', 'status', 'is_expired', 'proposed_by', 'proposed_at', 'expires_at', 'decided_by', 'decided_at', 'reason', 'lapse_reason'];

    /**
     * **Reject: 200, the proposal now `rejected`, the reason stored trimmed; one `role.assignment_rejected`** — the
     * rejecter as actor, the subject user, `{proposal_id, proposed_by_user_id, role_id}` and NOTHING else (the reason text
     * never reaches the trail: PC-16 condition 3), the IP. Nothing is granted. GET serves the body.
     */
    #[Test]
    public function aRejectionIs200AndWritesOneRowWithoutTheReason(): void
    {
        $role = $this->systemRole(DefaultRoles::FINANCE);
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $proposer = $this->principal(DefaultRoles::ADMIN);
        $id = $this->seedPendingProposal($subject, $role, $proposer);
        $owner = $this->principal(DefaultRoles::OWNER);
        $this->actAsUser(self::TENANT_A, $owner);

        $this->reject($id, ['reason' => '  Not needed for this job.  ']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertSame(self::SHAPE, array_keys($body));
        self::assertSame(['rejected', 'Not needed for this job.', null], [$body['status'], $body['reason'], $body['lapse_reason']]);
        self::assertSame(['id' => $owner, 'display_name' => 'Fixture Actor'], $body['decided_by']);
        self::assertNull($this->client()->getResponse()->headers->get('Location'));
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM user_role WHERE user_id = ?::uuid', [$subject]));

        self::assertSame([[
            'event_type' => AuditEvent::ROLE_ASSIGNMENT_REJECTED,
            'actor' => $owner,
            'subject' => $subject,
            'ip' => '127.0.0.1',
            'keys' => '["proposal_id", "proposed_by_user_id", "role_id"]',
            'proposal_id' => $id,
            'proposed_by' => $proposer,
        ]], $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT event_type, actor_user_id::text AS actor, subject_id::text AS subject, host(ip_address) AS ip,
                   (SELECT jsonb_agg(k ORDER BY k)::text FROM jsonb_object_keys(metadata) k) AS keys,
                   metadata->>? AS proposal_id, metadata->>? AS proposed_by
              FROM audit_log WHERE event_type LIKE 'role.%'
            SQL, [RoleEventMetadata::PROPOSAL_ID, RoleEventMetadata::PROPOSED_BY_USER_ID]));
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM audit_log WHERE metadata::text LIKE '%Not needed%'"),
            'The reason text reached the trail.');

        $this->send('GET', '/role-grant-proposals/'.$id);
        self::assertSame($body, $this->json());
    }

    /** No reason, a null one and a blank one are all stored as null (§4.4 step 6). */
    #[Test]
    public function anAbsentNullOrBlankReasonIsNoReason(): void
    {
        $proposer = $this->principal(DefaultRoles::ADMIN);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::OWNER));
        foreach (['absent' => [], 'null' => ['reason' => null], 'blank' => ['reason' => '   ']] as $case => $body) {
            $this->reject($this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $this->systemRole(DefaultRoles::FINANCE), $proposer), $body);
            self::assertSame(200, $this->httpStatus(), $case.': '.$this->client()->getResponse()->getContent());
            self::assertNull($this->json()['reason'], $case);
        }
    }

    /** The reason's bounds: a non-string → 422 `not_a_string`; 501 characters → 422 `too_long`; 500 → 200. Nothing written. */
    #[Test]
    public function aReasonMustBeAStringOfAtMostFiveHundredCharacters(): void
    {
        $proposer = $this->principal(DefaultRoles::ADMIN);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::OWNER));

        foreach ([
            'a number' => [42, 'not_a_string', 'This field must be sent as a string.'],
            'a list' => [['x'], 'not_a_string', 'This field must be sent as a string.'],
            '501 characters' => [str_repeat('é', 501), 'too_long', 'The reason must be at most 500 characters.'],
        ] as $case => [$reason, $code, $message]) {
            $id = $this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $this->systemRole(DefaultRoles::FINANCE), $proposer);
            $this->reject($id, ['reason' => $reason]);
            self::assertSame(422, $this->httpStatus(), $case.': '.$this->client()->getResponse()->getContent());
            self::assertSame([['pointer' => '/reason', 'code' => $code, 'message' => $message]], $this->json()['errors'] ?? null, $case);
            self::assertSame([['status' => 'pending']], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT status FROM role_grant_proposal WHERE id = ?::uuid', [$id]), $case);
        }

        $this->reject($this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $this->systemRole(DefaultRoles::FINANCE), $proposer), ['reason' => str_repeat('é', 500)]);
        self::assertSame(200, $this->httpStatus(), 'Control: a 500-character reason (counted in characters, not bytes) was refused.');
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM audit_log WHERE event_type = 'role.assignment_rejected'"),
            'A refused rejection wrote a row, or the accepted one wrote none.');
    }

    /**
     * **T7 for Reject (Q-6 (c′)):** a `role.assign` holder without the marker → 409 `decider_not_owner`, for any id and
     * before the proposal is read (an unknown uuid, their own proposal, an expired one — never 404 or
     * `rejecter_is_proposer`), with no denial row; a marker holder WITHOUT `role.assign` → the gate's 403 and its denial row
     * — **G-11's closing mechanism, completed for Reject**: every decider can read a proposal before deciding it.
     */
    #[Test]
    public function onlyAnOwnerWhoManagesRolesRejectsAndANonOwnerIsRefusedTheSameWayForAnyId(): void
    {
        $role = $this->systemRole(DefaultRoles::FINANCE);
        $proposer = $this->principal(DefaultRoles::ADMIN);
        $this->actAsUser(self::TENANT_A, $proposer);
        foreach ([
            'an unknown uuid' => Uuid::v4()->toRfc4122(),
            'a malformed id' => 'not-a-uuid',
            'their own proposal' => $this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $role, $proposer),
            'an expired proposal' => $this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $role, $this->principal(DefaultRoles::ADMIN), ageHours: 360),
        ] as $case => $id) {
            $this->reject($id);
            $this->assertRefused('role_grant_proposal.decider_not_owner', $case);
        }
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM audit_log'));

        $markerOnly = $this->seedPrincipal(self::TENANT_A, [OwnerMarker::CODE]);
        $this->actAsUser(self::TENANT_A, $markerOnly);
        $this->reject($this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $role, $proposer));
        self::assertSame(403, $this->httpStatus());
        self::assertSame(RoleRevokeTest::ROLE_ASSIGN_DENIED, (string) $this->client()->getResponse()->getContent());
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM audit_log WHERE event_type = ? AND actor_user_id = ?::uuid', [AuditEvent::AUTHZ_PERMISSION_DENIED, $markerOnly]));
    }

    /** **T6 / R-15:** the proposer cannot reject their own proposal, even as an owner (also a CHECK); they may withdraw it. */
    #[Test]
    public function aProposerCannotRejectTheirOwnProposal(): void
    {
        $proposer = $this->principal(DefaultRoles::OWNER);
        $id = $this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $this->systemRole(DefaultRoles::FINANCE), $proposer);
        $this->actAsUser(self::TENANT_A, $proposer);

        $this->reject($id);
        $this->assertRefused('role_grant_proposal.rejecter_is_proposer');

        $this->withdraw($id);
        self::assertSame(200, $this->httpStatus(), 'The proposer could not withdraw the proposal the refusal pointed to.');
    }

    /**
     * **Q-5 (a): an owner may reject a proposal naming them** — the only way a sole owner named as a subject can clear it
     * (nobody can confirm it, E-15a). Confirming it stays forbidden (`RoleGrantProposalConfirmTest`).
     */
    #[Test]
    public function anOwnerMayRejectARoleProposedForThemselves(): void
    {
        $owner = $this->principal(DefaultRoles::OWNER);
        $id = $this->seedPendingProposal($owner, $this->systemRole(DefaultRoles::FINANCE), $this->principal(DefaultRoles::ADMIN));
        $this->actAsUser(self::TENANT_A, $owner);

        $this->reject($id);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('rejected', $this->json()['status']);
    }

    /**
     * **Cleanup (§5.2.7):** an EXPIRED pending proposal may be rejected and withdrawn — that is how one is closed — and a
     * proposal whose subject has since been removed may be rejected (RED L-2: read by id, never through a proxy) → 200.
     */
    #[Test]
    public function anExpiredProposalOrOneForARemovedSubjectCanStillBeClosed(): void
    {
        $role = $this->systemRole(DefaultRoles::FINANCE);
        $proposer = $this->principal(DefaultRoles::ADMIN);
        $owner = $this->principal(DefaultRoles::OWNER);

        $this->actAsUser(self::TENANT_A, $owner);
        $this->reject($this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $role, $proposer, ageHours: 360));
        self::assertSame([200, 'rejected', true], [$this->httpStatus(), $this->json()['status'] ?? null, null !== ($this->json()['decided_at'] ?? null)]);

        $gone = $this->seedPrincipal(self::TENANT_A, []);
        $id = $this->seedPendingProposal($gone, $role, $proposer);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET deleted_at = now() WHERE id = ?::uuid', [$gone]);
        $this->reject($id);
        self::assertSame(200, $this->httpStatus(), 'A proposal for a removed subject could not be rejected: '.$this->client()->getResponse()->getContent());

        $this->actAsUser(self::TENANT_A, $proposer);
        $this->withdraw($this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $role, $proposer, ageHours: 360));
        self::assertSame([200, 'cancelled'], [$this->httpStatus(), $this->json()['status'] ?? null]);
    }

    /**
     * **Withdraw: the proposer, with `role.assign` only — 200, the proposal now `cancelled`; one `role.assignment_cancelled`
     * `{proposal_id, role_id}`, the proposer as actor.** Anyone else → 409 `not_the_proposer` (a 409, not a 404: they can
     * list it), an owner included. A `viewer` → 403.
     */
    #[Test]
    public function onlyTheProposerWithdrawsAProposal(): void
    {
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $role = $this->systemRole(DefaultRoles::FINANCE);
        $proposer = $this->principal(DefaultRoles::ADMIN);
        $id = $this->seedPendingProposal($subject, $role, $proposer);

        foreach (['another admin' => $this->principal(DefaultRoles::ADMIN), 'an owner' => $this->principal(DefaultRoles::OWNER)] as $case => $other) {
            $this->actAsUser(self::TENANT_A, $other);
            $this->withdraw($id);
            $this->assertRefused('role_grant_proposal.not_the_proposer', $case);
        }
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::VIEWER));
        $this->withdraw($id);
        self::assertSame(403, $this->httpStatus());

        $this->actAsUser(self::TENANT_A, $proposer);
        $this->withdraw($id);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(self::SHAPE, array_keys($this->json()));
        self::assertSame(['cancelled', ['id' => $proposer, 'display_name' => 'Fixture Actor'], null], [$this->json()['status'], $this->json()['decided_by'], $this->json()['reason']]);
        self::assertSame([[
            'event_type' => AuditEvent::ROLE_ASSIGNMENT_CANCELLED,
            'actor' => $proposer,
            'subject' => $subject,
            'keys' => '["proposal_id", "role_id"]',
            'role_id' => $role,
        ]], $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT event_type, actor_user_id::text AS actor, subject_id::text AS subject,
                   (SELECT jsonb_agg(k ORDER BY k)::text FROM jsonb_object_keys(metadata) k) AS keys, metadata->>? AS role_id
              FROM audit_log WHERE event_type LIKE 'role.%'
            SQL, [RoleEventMetadata::ROLE_ID]));
    }

    /**
     * **`UX` F-1 on Reject and Withdraw:** a proposal that is no longer pending is refused in the flow's own words — same
     * rule, `UX`'s sentence by its status, never the table name; nothing written. Mutation: delete either handler's catch
     * → its literal red.
     */
    #[Test]
    #[DataProvider('decidedStatusesOnBothRoutes')]
    public function aDecidedProposalCannotBeRejectedOrWithdrawnAndIsToldInTheFlowsWords(string $route, string $status): void
    {
        $proposer = $this->principal(DefaultRoles::ADMIN);
        $decider = match ($status) {
            RoleGrantProposal::STATUS_CANCELLED => $proposer,
            RoleGrantProposal::STATUS_LAPSED => null,
            default => $this->principal(DefaultRoles::OWNER),
        };
        $id = $this->seedDecidedProposal($status, $this->seedPrincipal(self::TENANT_A, []), $this->systemRole(DefaultRoles::FINANCE), $proposer, $decider);
        $this->actAsUser(self::TENANT_A, 'reject' === $route ? $this->principal(DefaultRoles::OWNER) : $proposer);

        'reject' === $route ? $this->reject($id) : $this->withdraw($id);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('role_grant_proposal.status_transition_is_legal', $this->json()['rule'] ?? null);
        self::assertSame(RoleGrantProposalStatusRefusal::SENTENCES[$status], $this->json()['detail'] ?? null);
        self::assertStringNotContainsString('role_grant_proposal', (string) ($this->json()['detail'] ?? ''));
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM audit_log WHERE event_type LIKE 'role.%'"));
    }

    /** @return iterable<string, array{string, string}> */
    public static function decidedStatusesOnBothRoutes(): iterable
    {
        foreach (['reject', 'withdraw'] as $route) {
            foreach ([RoleGrantProposal::STATUS_CONFIRMED, RoleGrantProposal::STATUS_REJECTED, RoleGrantProposal::STATUS_CANCELLED, RoleGrantProposal::STATUS_LAPSED] as $status) {
                yield $route.' '.$status => [$route, $status];
            }
        }
    }

    /** Withdraw accepts no body (`BodylessWriteRefusal`); Reject refuses an undeclared field. Nothing is written. */
    #[Test]
    public function theDecisionsRefuseUndeclaredFields(): void
    {
        $proposer = $this->principal(DefaultRoles::ADMIN);
        $id = $this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $this->systemRole(DefaultRoles::FINANCE), $proposer);

        $this->actAsUser(self::TENANT_A, $proposer);
        $this->send('POST', '/role-grant-proposals/'.$id.'/cancellation', ['reason' => 'x']);
        self::assertSame(422, $this->httpStatus());
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::OWNER));
        $this->reject($id, ['status' => 'confirmed']);
        self::assertSame(422, $this->httpStatus());
        self::assertSame([['status' => 'pending']], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT status FROM role_grant_proposal WHERE id = ?::uuid', [$id]));
    }

    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $body */
    private function reject(string $id, array $body = []): void
    {
        $this->send('POST', '/role-grant-proposals/'.$id.'/rejection', $body);
    }

    private function withdraw(string $id): void
    {
        $this->send('POST', '/role-grant-proposals/'.$id.'/cancellation');
    }

    private function assertRefused(string $rule, string $case = ''): void
    {
        self::assertSame(409, $this->httpStatus(), $case.': '.$this->client()->getResponse()->getContent());
        self::assertSame($rule, $this->json()['rule'] ?? null, $case);
        self::assertSame(self::COPY[$rule], $this->json()['detail'] ?? null, $case.': the refusal sentence is not UX\'s.');
    }
}

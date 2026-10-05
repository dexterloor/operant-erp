<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Domain\DefaultRoles;
use App\SharedKernel\ValueObject\AuditEvent;
use App\SharedKernel\ValueObject\RoleEventMetadata;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **E-15e's proposal route, C1: `CreateRoleGrantProposal`, `GetRoleGrantProposal`, `ListRoleGrantProposals`** (roles
 * slice 3; `docs/session13/roles-s3-design.md` §3, §4.1–§4.2, §4.6, §6, §9, §12; DDL `roles-s3-data.md`).
 *
 * One rule per test: the 201 and its shape (PC-16's three references, `{id, display_name}` exactly), the one
 * `role.assignment_proposed` row (key set by name, the IP, `clock_timestamp()` after the lock), the 428, T1's first half
 * (a pending proposal confers nothing), the step-8 pending pair (live → `already_pending`; a replay of the same request is
 * the same refusal), T13 (an expired same pair is lapsed and succeeded in one transaction, lapsed row written first; the
 * F-9 control planted in raw SQL), V2-R-6, E-15a, E-15b, E-15c, C12, PC-13, inverse routing, the 404/422 shapes, the reads
 * (`role.assign` only — `viewer` 403 on all three; a removed subject's proposal still listed and gettable), and the
 * decided-is-final trigger (`DATA` §3.5).
 *
 * **Every refusal's sentence is pinned as a literal** — `UX`'s copy (`docs/session13/roles-s3-ux-copy.md` §3), so a
 * wording change is a visible edit. The order between the rules is `GrantRoutingTest`'s; the concurrency half (positional
 * lock-first case, the demotion twin, Create ∥ Create) lives in `PurchaseOrderRowLockTest`.
 */
final class RoleGrantProposalTest extends ApiTestCase
{
    use RoleGrantFixtures;

    /**
     * The operations shipped under `/role-grant-proposals`, joined into
     * `AuthOperationContractTest::theShippedSurfaceIsExactlyTheOperationsThisWaveHasPinned`'s union
     * (`CreateRoleGrantProposal` is under `/users`, in `RoleRevokeTest::PINNED_USER_ROLE_OPERATIONS`).
     *
     * @var array<string, array{method: string, path: string, idem: bool}>
     */
    public const PINNED_ROLE_GRANT_PROPOSAL_OPERATIONS = [
        'GetRoleGrantProposal' => ['method' => 'GET', 'path' => '/role-grant-proposals/{id}', 'idem' => false],
        'ListRoleGrantProposals' => ['method' => 'GET', 'path' => '/role-grant-proposals', 'idem' => false],
        // Roles slice 3 C2: a `guard:` cell, so no header is demanded.
        'ConfirmRoleGrantProposal' => ['method' => 'POST', 'path' => '/role-grant-proposals/{id}/confirmation', 'idem' => false],
    ];

    /** `UX` §3 (#4–#13), verbatim. */
    public const COPY = [
        'user_role.subject_is_deleted' => 'This person has been removed from the workspace, so they can\'t be given a role.',
        'user_role.subject_is_inactive' => 'This person\'s account is not active, so they can\'t be given a role.',
        'user_role.role_is_archived' => 'This role is archived, so it can\'t be given to anyone. Choose another role.',
        'user_role.actor_is_subject' => 'Nobody can propose a role for themselves. Ask someone else who manages roles to do it.',
        'user_role.marker_needs_owner' => 'Only an owner can give someone this role, because it makes them an owner.',
        'user_role.exceeds_actor_permissions' => 'This role includes permissions you don\'t have yourself, so you can\'t propose it. Ask an owner, or someone who holds all of its permissions.',
        'user_role.already_held' => 'This person already has this role.',
        'role_grant_proposal.already_pending' => 'This role has already been proposed for this person, and that proposal is still open. You can find it among the open role proposals.',
        'role_grant_proposal.grant_directly' => 'This role doesn\'t need anyone\'s confirmation when you give it, so there\'s nothing to propose. Give it to this person directly instead.',
    ];

    /** §3.3: the closed member set, in serialisation order. */
    private const SHAPE = ['id', 'user', 'role', 'permission_codes', 'status', 'is_expired', 'proposed_by', 'proposed_at', 'expires_at', 'decided_by', 'decided_at', 'reason', 'lapse_reason'];

    /**
     * 201; `Location` = `GetRoleGrantProposal`'s URI (API-21 branch 4); the body is the closed §3.3 shape, read back under
     * the lock; PC-16's three references are `{id, display_name}` and nothing else (mutation: add `email` → red); the
     * snapshot is the role's codes, sorted; `expires_at` - `proposed_at` is exactly 336 hours; and GET returns the body
     * byte for byte.
     */
    #[Test]
    public function aProposalIs201AndItsBodyIsTheClosedShape(): void
    {
        $role = $this->systemRole(DefaultRoles::FINANCE);
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $admin = $this->principal(DefaultRoles::ADMIN);
        $this->actAsUser(self::TENANT_A, $admin);

        $this->propose($subject, $role);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertSame(self::SHAPE, array_keys($body), 'The proposal body is not §3.3\'s closed shape.');
        self::assertSame('/api/v1/role-grant-proposals/'.$body['id'], $this->client()->getResponse()->headers->get('Location'));
        self::assertSame(['id' => $subject, 'display_name' => 'Fixture Actor'], $body['user'], 'PC-16 field 1 is not {id, display_name}.');
        self::assertSame(['id' => $admin, 'display_name' => 'Fixture Actor'], $body['proposed_by'], 'PC-16 field 2 is not {id, display_name}.');
        self::assertNull($body['decided_by']);
        self::assertSame($role, $body['role']['id']);
        self::assertSame(['id', 'name'], array_keys($body['role']));
        $codes = DefaultRoles::codesFor(DefaultRoles::FINANCE);
        sort($codes);
        self::assertSame($codes, $body['permission_codes']);
        self::assertSame('pending', $body['status']);
        self::assertFalse($body['is_expired']);
        self::assertNull($body['decided_at']);
        self::assertNull($body['reason']);
        self::assertNull($body['lapse_reason']);
        self::assertSame([['exact' => true, 'pending' => true]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            "SELECT expires_at - proposed_at = interval '336 hours' AS exact, status = 'pending' AS pending FROM role_grant_proposal WHERE id = ?::uuid",
            [$body['id']]));

        $this->send('GET', '/role-grant-proposals/'.$body['id']);
        self::assertSame(200, $this->httpStatus());
        self::assertSame($body, $this->json(), 'GetRoleGrantProposal does not serve the 201 body.');
    }

    /**
     * One `role.assignment_proposed` (security): the proposer as actor, the subject USER, `{role_id, proposal_id}` by the
     * `RoleEventMetadata` constants and nothing else, the request's IP, `occurred_at` after the request began. A refused
     * proposal writes no `role.*` row.
     */
    #[Test]
    public function aProposalWritesExactlyOneProposedRow(): void
    {
        $role = $this->systemRole(DefaultRoles::FINANCE);
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $admin = $this->principal(DefaultRoles::ADMIN);
        $this->actAsUser(self::TENANT_A, $admin);

        $this->propose($subject, Uuid::v4()->toRfc4122());
        self::assertSame(422, $this->httpStatus());
        self::assertSame(0, $this->roleRows(), 'A refused proposal wrote a role.* row.');

        $before = (string) $this->owner()->fetchOne('SELECT now()::text');
        $this->propose($subject, $role);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $proposal = $this->json()['id'];

        self::assertSame([[
            'event_type' => AuditEvent::ROLE_ASSIGNMENT_PROPOSED,
            'actor' => $admin,
            'subject_type' => 'app_user',
            'subject' => $subject,
            'ip' => '127.0.0.1',
            'keys' => '["proposal_id", "role_id"]',
            'role_id' => $role,
            'proposal_id' => $proposal,
            'after_start' => true,
        ]], $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT event_type, actor_user_id::text AS actor, subject_type, subject_id::text AS subject, host(ip_address) AS ip,
                   (SELECT jsonb_agg(k ORDER BY k)::text FROM jsonb_object_keys(metadata) k) AS keys,
                   metadata->>? AS role_id, metadata->>? AS proposal_id, occurred_at >= ?::timestamptz AS after_start
              FROM audit_log WHERE event_type LIKE 'role.%'
            SQL, [RoleEventMetadata::ROLE_ID, RoleEventMetadata::PROPOSAL_ID, $before]));
    }

    /** API-14: a `unique:` cell demands the header — 428, and nothing is written. */
    #[Test]
    public function aProposalWithoutAnIdempotencyKeyIs428AndWritesNothing(): void
    {
        $role = $this->systemRole(DefaultRoles::FINANCE);
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));

        $this->send('POST', '/users/'.$subject.'/role-grant-proposals', ['role_id' => $role]);

        self::assertSame(428, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([], $this->proposalRows());
        self::assertSame(0, $this->roleRows());
    }

    /**
     * **T1, first half (design §9, §15): a pending proposal confers nothing.** `warehouse_operator` is proposed for S; S is
     * still refused `CreateGoodsReceipt` (403 at the gate) and `/me` does not list `goods_receipt.create`. The positive
     * half — confirmation confers it — needs Confirm and lands with C2. Mutation M1 (`UNION … FROM role_grant_proposal` in
     * `IdentityReader`) → red here and in T2.
     */
    #[Test]
    public function aPendingProposalConfersNoPermission(): void
    {
        $role = $this->systemRole(DefaultRoles::WAREHOUSE_OPERATOR);
        $subject = $this->seedPrincipal(self::TENANT_A, ['inventory_item.view']);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $this->propose($subject, $role);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->actAsUser(self::TENANT_A, $subject);
        $this->send('GET', '/me');
        self::assertSame(200, $this->httpStatus());
        self::assertContains('inventory_item.view', $this->json()['permission_codes'], 'Control: /me does not list what S holds.');
        self::assertNotContains('goods_receipt.create', $this->json()['permission_codes'], 'A pending proposal conferred its codes on /me.');

        $this->send('POST', '/purchase-orders/'.Uuid::v4()->toRfc4122().'/goods-receipts', ['lines' => []], $this->key());
        self::assertSame(403, $this->httpStatus(), 'A pending proposal let its subject past the gate.');
    }

    /**
     * Step 8: a LIVE same-pair proposal → 409 `already_pending`, the pre-check, with nothing written. And a replay of the
     * caller's own request (a new key, the same body: §4.2's `unique:` key is the pair) meets the same refusal.
     */
    #[Test]
    public function aLivePendingProposalForThePairRefusesAnother(): void
    {
        $role = $this->systemRole(DefaultRoles::FINANCE);
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));

        $this->propose($subject, $role);
        self::assertSame(201, $this->httpStatus());
        $this->propose($subject, $role);
        $this->assertRefused('role_grant_proposal.already_pending', 'replay');
        self::assertCount(1, $this->proposalRows());
        self::assertSame(1, $this->roleRows());

        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $this->propose($subject, $role);
        $this->assertRefused('role_grant_proposal.already_pending', 'a second admin');
        self::assertCount(1, $this->proposalRows());
    }

    /**
     * **T13 (design §15; F-9): an EXPIRED same-pair proposal is lapsed and succeeded in one transaction.** The old row is
     * `lapsed` / `expired` with `decided_at` set at microsecond precision and no decider; the new one is pending; two
     * `role.*` rows, `role.assignment_lapsed` FIRST (R-18), then `role.assignment_proposed` carrying
     * `supersedes_proposal_id`. Run from a destroyed volume this is M-26's falsifier: a fifth column in the lapse's
     * changeset is a `42501` on `role_grant_proposal`. Mutation M10 (drop the lapse) → `23505`, red.
     */
    #[Test]
    public function anExpiredPendingProposalIsLapsedAndSucceeded(): void
    {
        $role = $this->systemRole(DefaultRoles::FINANCE);
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $admin = $this->principal(DefaultRoles::ADMIN);
        $old = $this->seedPendingProposal($subject, $role, $this->principal(DefaultRoles::ADMIN), ageHours: 360);
        $this->actAsUser(self::TENANT_A, $admin);

        $this->send('GET', '/role-grant-proposals/'.$old);
        self::assertTrue($this->json()['is_expired'] ?? null, 'Control: the seeded proposal does not read as expired.');
        self::assertSame('pending', $this->json()['status']);

        $this->propose($subject, $role);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $new = $this->json()['id'];

        self::assertSame([
            ['id' => $old, 'user_id' => $subject, 'role_id' => $role, 'status' => 'lapsed', 'lapse_reason' => 'expired'],
            ['id' => $new, 'user_id' => $subject, 'role_id' => $role, 'status' => 'pending', 'lapse_reason' => null],
        ], $this->proposalRows());
        self::assertSame([['decider' => null, 'micro' => true, 'after' => true]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            "SELECT decided_by_user_id AS decider, decided_at::text LIKE '%.%' AS micro, decided_at >= proposed_at AS after FROM role_grant_proposal WHERE id = ?::uuid",
            [$old]), 'The lapse did not write decided_at at microsecond precision, or wrote a decider.');

        self::assertSame([
            ['event_type' => AuditEvent::ROLE_ASSIGNMENT_LAPSED, 'actor' => $admin, 'proposal_id' => $old, 'lapse_reason' => 'expired', 'supersedes' => null],
            ['event_type' => AuditEvent::ROLE_ASSIGNMENT_PROPOSED, 'actor' => $admin, 'proposal_id' => $new, 'lapse_reason' => null, 'supersedes' => $old],
        ], $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT event_type, actor_user_id::text AS actor, metadata->>? AS proposal_id, metadata->>? AS lapse_reason, metadata->>? AS supersedes
              FROM audit_log WHERE event_type LIKE 'role.%' ORDER BY occurred_at, id
            SQL, [RoleEventMetadata::PROPOSAL_ID, RoleEventMetadata::LAPSE_REASON, RoleEventMetadata::SUPERSEDES_PROPOSAL_ID]));

        $this->send('GET', '/role-grant-proposals/'.$old);
        self::assertSame(['lapsed', 'expired', false, null], [$this->json()['status'], $this->json()['lapse_reason'], $this->json()['is_expired'], $this->json()['decided_by']]);
    }

    /**
     * **T13's F-9 control, planted in raw SQL** (RED L-3): the successor INSERTed BEFORE the lapse UPDATE collides on
     * `role_grant_proposal_pending_unique` (`23505`) — which is why Create flushes the lapse first. Run as the owner, in
     * a rolled-back transaction: an ORM persist cannot produce this (`status` is outside the column INSERT grant).
     */
    #[Test]
    public function theSuccessorBeforeTheLapseCollidesOnThePendingUnique(): void
    {
        $role = $this->systemRole(DefaultRoles::FINANCE);
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $proposer = $this->principal(DefaultRoles::ADMIN);
        $this->seedPendingProposal($subject, $role, $proposer, ageHours: 360);

        $state = $this->attemptAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO role_grant_proposal (id, tenant_id, user_id, role_id, permission_codes, proposed_by_user_id, proposed_at, expires_at)
            SELECT gen_random_uuid(), ?::uuid, ?::uuid, ?::uuid, '["invoice.pay"]'::jsonb, ?::uuid, c.t, c.t + interval '336 hours'
              FROM (SELECT clock_timestamp() AS t) c
            SQL, [self::TENANT_A, $subject, $role, $proposer]);

        self::assertSame('23505', $state, 'The successor inserted before the lapse did not collide: F-9\'s order is not what keeps Create correct.');
    }

    /**
     * **`DATA` §3.5 — a decided proposal is final.** As `erp_app` in tenant A's context, `UPDATE … SET updated_at = now()`
     * on a `lapsed` row (owner-INSERTed decided, every CHECK satisfied, the column inside C1's grant) is refused `23514` by
     * `role_grant_proposal_decided_is_final` — nothing else can refuse it. Positive control: the same statement on a
     * PENDING row succeeds. Mutation: drop the trigger → red. And the function is a raiser, not a writer.
     */
    #[Test]
    public function aDecidedProposalCannotBeUpdatedByTheApplication(): void
    {
        $role = $this->systemRole(DefaultRoles::FINANCE);
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $proposer = $this->principal(DefaultRoles::ADMIN);
        $pending = $this->seedPendingProposal($subject, $role, $proposer);
        $lapsed = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO role_grant_proposal (id, tenant_id, user_id, role_id, permission_codes, status, proposed_by_user_id,
                                             proposed_at, expires_at, decided_at, lapse_reason)
            SELECT ?::uuid, ?::uuid, ?::uuid, ?::uuid, '["invoice.pay"]'::jsonb, 'lapsed', ?::uuid, c.t, c.t + interval '336 hours', c.t + interval '337 hours', 'expired'
              FROM (SELECT clock_timestamp() - interval '400 hours' AS t) c
            SQL, [$lapsed, self::TENANT_A, $subject, $role, $proposer]);

        $attempt = function (string $id): ?string {
            $connection = $this->secondClient();
            $connection->beginTransaction();
            try {
                $this->tenantContext()->establishOn($connection, self::TENANT_A);
                $connection->executeStatement('UPDATE role_grant_proposal SET updated_at = now() WHERE id = ?::uuid', [$id]);

                return null;
            } catch (DriverException $e) {
                return ($e->getSQLState() ?? '?').(str_contains($e->getMessage(), 'role_grant_proposal_decided_is_final') ? ' trigger' : ' other');
            } finally {
                $connection->rollBack();
                $connection->close();
            }
        };

        self::assertNull($attempt($pending), 'Positive control: erp_app could not update a pending proposal\'s updated_at.');
        self::assertSame('23514 trigger', $attempt($lapsed), 'A decided proposal was updated by erp_app: the decided-is-final trigger did not refuse it.');

        self::assertSame([['body_writes' => false, 'definer' => false]], $this->owner()->fetchAllAssociative(
            "SELECT prosrc ~* '\\m(insert|update|delete)\\M' AS body_writes, prosecdef AS definer FROM pg_proc WHERE proname = 'role_grant_proposal_decided_is_final'"),
            'The trigger function issues a statement or runs as its definer.');
    }

    /** V2-R-6 on the proposal route (§5.2.4: the same rule binds all three routes), each with a live control. */
    #[Test]
    public function aRemovedSubjectOrArchivedRoleIsRefused(): void
    {
        $role = $this->systemRole(DefaultRoles::FINANCE);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));

        $deleted = $this->seedPrincipal(self::TENANT_A, []);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET deleted_at = now() WHERE id = ?::uuid', [$deleted]);
        $this->propose($deleted, $role);
        $this->assertRefused('user_role.subject_is_deleted');

        $inactive = $this->seedPrincipal(self::TENANT_A, []);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET is_active = false WHERE id = ?::uuid', [$inactive]);
        $this->propose($inactive, $role);
        $this->assertRefused('user_role.subject_is_inactive');

        $archived = $this->systemRole(DefaultRoles::FINANCE);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE role SET deleted_at = now() WHERE id = ?::uuid', [$archived]);
        $live = $this->seedPrincipal(self::TENANT_A, []);
        $this->propose($live, $archived);
        $this->assertRefused('user_role.role_is_archived');

        $this->propose($live, $role);
        self::assertSame(201, $this->httpStatus(), 'Control: a live subject and a live role were refused.');
        self::assertCount(1, $this->proposalRows());
    }

    /**
     * **T5 (Create half): E-15a — nobody proposes a role for themselves** (Create's own sentence), refused, never a 500.
     * The seat (`role.assign`, `role.view`) proposing `warehouse_operator` for itself passes every OTHER rule, so with
     * phase 1's check dropped (mutation M4a) the request reaches CHECK `role_grant_proposal_not_self` — and the `23514`
     * translation answers the same 409; dropping the translation too → 500, red. The CHECK itself is probed below.
     */
    #[Test]
    public function nobodyProposesARoleForThemselves(): void
    {
        $role = $this->systemRole(DefaultRoles::WAREHOUSE_OPERATOR);
        $admin = $this->seedPrincipal(self::TENANT_A, ['role.assign', 'role.view']);
        $this->actAsUser(self::TENANT_A, $admin);

        $this->propose($admin, $role);
        $this->assertRefused('user_role.actor_is_subject');
        self::assertSame([], $this->proposalRows());
        self::assertSame('23514', $this->attemptAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO role_grant_proposal (id, tenant_id, user_id, role_id, permission_codes, proposed_by_user_id, proposed_at, expires_at)
            SELECT gen_random_uuid(), ?::uuid, ?::uuid, ?::uuid, '["invoice.pay"]'::jsonb, ?::uuid, c.t, c.t + interval '336 hours'
              FROM (SELECT clock_timestamp() AS t) c
            SQL, [self::TENANT_A, $admin, $role, $admin]), 'The database admits a self-proposal.');
    }

    /** Q5 / E-15b: a non-owner may not even propose a role conferring the marker, and the refusal names no route. */
    #[Test]
    public function nobodyButAnOwnerMayProposeTheOwnerRole(): void
    {
        $ownerRole = $this->systemRole(DefaultRoles::OWNER);
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));

        $this->propose($subject, $ownerRole);
        $this->assertRefused('user_role.marker_needs_owner');
        self::assertSame([], $this->proposalRows());
    }

    /** E-15c on the PROPOSER's set (custom roles only), with Create's own sentence; a system role is not judged. */
    #[Test]
    public function aComposedRoleOutsideTheProposersSetCannotBeProposed(): void
    {
        $this->actAsUser(self::TENANT_A, $this->seedPrincipal(self::TENANT_A, ['role.assign', 'role.view']));
        $outside = $this->composedRole(['invoice.pay', 'inventory_item.view']);

        $this->propose($this->seedPrincipal(self::TENANT_A, []), $outside);
        $this->assertRefused('user_role.exceeds_actor_permissions');

        $this->propose($this->seedPrincipal(self::TENANT_A, []), $this->systemRole(DefaultRoles::FINANCE));
        self::assertSame(201, $this->httpStatus(), 'Control: E-15c judged a SYSTEM role.');
    }

    /** C12: the subject already holds the role → `already_held` (shared sentence), nothing written. */
    #[Test]
    public function aRoleAlreadyHeldCannotBeProposed(): void
    {
        $role = $this->systemRole(DefaultRoles::FINANCE);
        $subject = $this->holderOf([$role]);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));

        $this->propose($subject, $role);
        $this->assertRefused('user_role.already_held');
        self::assertSame([], $this->proposalRows());
    }

    /** PC-13 "new conflict only" over the subject's held set: `finance` to a `sales_rep` holder splits SoD-3. */
    #[Test]
    public function aProposalThatWouldSplitADutyIsRefusedNamingTheRow(): void
    {
        $finance = $this->systemRole(DefaultRoles::FINANCE);
        $subject = $this->holderOf([$this->systemRole(DefaultRoles::SALES_REP)]);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));

        $this->propose($subject, $finance);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('user_role.separation_of_duty', $this->json()['rule'] ?? null);
        self::assertStringContainsString('(SoD-3)', (string) ($this->json()['detail'] ?? ''));
        self::assertSame([], $this->proposalRows());
    }

    /**
     * Inverse routing, last: a grant that needs no confirmation is not proposed — `admin` proposing `buyer` (no `X(admin)`
     * code), and an OWNER proposing anything (an owner never proposes). Mutation M2c (drop the step) → both 201, red.
     */
    #[Test]
    public function aGrantNeedingNoConfirmationIsGivenDirectlyNotProposed(): void
    {
        $subject = $this->seedPrincipal(self::TENANT_A, []);

        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $this->propose($subject, $this->systemRole(DefaultRoles::BUYER));
        $this->assertRefused('role_grant_proposal.grant_directly', 'admin + buyer');

        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::OWNER));
        $this->propose($subject, $this->systemRole(DefaultRoles::FINANCE));
        $this->assertRefused('role_grant_proposal.grant_directly', 'owner + finance');
        $this->propose($subject, $this->systemRole(DefaultRoles::OWNER));
        $this->assertRefused('role_grant_proposal.grant_directly', 'owner + owner');

        self::assertSame([], $this->proposalRows());
    }

    /** The subject is 404 in every not-found shape; `role_id` is a field error (`AssignRole`'s shipped strings, #1–#3). */
    #[Test]
    public function theSubjectIs404AndTheRoleIdIsAFieldError(): void
    {
        $role = $this->systemRole(DefaultRoles::FINANCE);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));

        foreach (['not a uuid' => 'not-a-uuid', 'absent' => Uuid::v4()->toRfc4122(), 'another workspace' => $this->seedPrincipal(self::TENANT_B, [])] as $case => $id) {
            $this->propose($id, $role);
            self::assertSame(404, $this->httpStatus(), $case);
        }

        $subject = $this->seedPrincipal(self::TENANT_A, []);
        foreach ([
            'required' => [[], 'This field is required.'],
            'not_a_uuid' => [['role_id' => 'nope'], 'This field must be a UUID naming a role in this workspace.'],
            'unknown_reference' => [['role_id' => Uuid::v4()->toRfc4122()], 'No role in this workspace has this id.'],
        ] as $code => [$body, $message]) {
            $this->send('POST', '/users/'.$subject.'/role-grant-proposals', $body, $this->key());
            self::assertSame(422, $this->httpStatus(), $code);
            self::assertSame([['pointer' => '/role_id', 'code' => $code, 'message' => $message]], $this->json()['errors'] ?? null, $code);
        }
        self::assertSame([], $this->proposalRows());
    }

    /**
     * **IA §6.8 / PC-16 condition 1: nothing pending reaches a `role.view` holder.** A `viewer` is 403 on all three
     * operations (with the gate's denial row each); a `role.assign` holder reads them.
     */
    #[Test]
    public function theProposalOperationsAreRefusedToARoleViewHolder(): void
    {
        $role = $this->systemRole(DefaultRoles::FINANCE);
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $this->propose($subject, $role);
        $id = $this->json()['id'];

        // A fresh viewer per request: E-3a caps denial rows per actor and code, and each refusal must show its own row.
        foreach ([['GET', '/role-grant-proposals'], ['GET', '/role-grant-proposals/'.$id], ['POST', '/users/'.$subject.'/role-grant-proposals']] as [$method, $path]) {
            $viewer = $this->principal(DefaultRoles::VIEWER);
            $this->actAsUser(self::TENANT_A, $viewer);
            $this->send($method, $path, 'POST' === $method ? ['role_id' => $role] : null, 'POST' === $method ? $this->key() : []);
            self::assertSame(403, $this->httpStatus(), $method.' '.$path);
            self::assertSame(RoleRevokeTest::ROLE_ASSIGN_DENIED, (string) $this->client()->getResponse()->getContent(), $method.' '.$path);
            self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A,
                'SELECT count(*) FROM audit_log WHERE event_type = ? AND actor_user_id = ?::uuid AND permission_code = ?',
                [AuditEvent::AUTHZ_PERMISSION_DENIED, $viewer, 'role.assign']), $method.' '.$path.': no denial row.');
        }
    }

    /**
     * RED L-2: no deletion predicate on the proposal reads — a proposal whose subject is then removed is still listed and
     * gettable (or nobody could find it to reject or withdraw it). Mutation: `AND s.deleted_at IS NULL` on the join → red.
     */
    #[Test]
    public function aProposalForARemovedSubjectIsStillListedAndGettable(): void
    {
        $role = $this->systemRole(DefaultRoles::FINANCE);
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $this->propose($subject, $role);
        $id = $this->json()['id'];
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET deleted_at = now() WHERE id = ?::uuid', [$subject]);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE role SET deleted_at = now() WHERE id = ?::uuid', [$role]);

        $this->send('GET', '/role-grant-proposals/'.$id);
        self::assertSame(200, $this->httpStatus(), 'A proposal for a removed subject or an archived role cannot be read.');
        $this->send('GET', '/role-grant-proposals?user='.$subject);
        self::assertSame([$id], array_column($this->json()['items'], 'id'));
    }

    /** Get: another workspace's id, an absent one and a malformed one are each 404. */
    #[Test]
    public function aProposalOfAnotherWorkspaceIs404(): void
    {
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        foreach (['not-a-uuid', Uuid::v4()->toRfc4122()] as $id) {
            $this->send('GET', '/role-grant-proposals/'.$id);
            self::assertSame(404, $this->httpStatus(), $id);
        }
    }

    /**
     * `ListRoleGrantProposals`: newest first by `(proposed_at, id)`; `status`, `user`, `role` filter and combine (AND);
     * an unknown uuid is an empty page with `tenant_has_rows`; the cursor walks forward and back over database-rendered
     * keys; bad parameters are 422.
     */
    #[Test]
    public function theListFiltersAndPagesNewestFirst(): void
    {
        $finance = $this->systemRole(DefaultRoles::FINANCE);
        $warehouse = $this->systemRole(DefaultRoles::WAREHOUSE_OPERATOR);
        $proposer = $this->principal(DefaultRoles::ADMIN);
        $alice = $this->seedPrincipal(self::TENANT_A, []);
        $bob = $this->seedPrincipal(self::TENANT_A, []);
        $oldest = $this->seedPendingProposal($alice, $finance, $proposer, ageHours: 360);
        $older = $this->seedPendingProposal($bob, $finance, $proposer, ageHours: 48);
        $this->actAsUser(self::TENANT_A, $proposer);
        $this->propose($alice, $warehouse);
        $newest = $this->json()['id'];

        $this->send('GET', '/role-grant-proposals');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$newest, $older, $oldest], array_column($this->json()['items'], 'id'));
        self::assertSame([false, false, true], array_column($this->json()['items'], 'is_expired'));

        foreach ([
            'user='.$alice => [$newest, $oldest],
            'role='.$finance => [$older, $oldest],
            'user='.$alice.'&role='.$finance => [$oldest],
            'status=pending' => [$newest, $older, $oldest],
            'status=lapsed' => [],
        ] as $query => $expected) {
            $this->send('GET', '/role-grant-proposals?'.$query);
            self::assertSame(200, $this->httpStatus(), $query);
            self::assertSame($expected, array_column($this->json()['items'], 'id'), $query);
        }

        $this->send('GET', '/role-grant-proposals?user='.Uuid::v4()->toRfc4122());
        self::assertSame([[], true], [$this->json()['items'], $this->json()['tenant_has_rows']]);

        $this->send('GET', '/role-grant-proposals?limit=1');
        $walk = array_column($this->json()['items'], 'id');
        self::assertNull($this->json()['previous_cursor']);
        while (null !== ($next = $this->json()['next_cursor'])) {
            $this->send('GET', '/role-grant-proposals?limit=1&after='.$next);
            $walk = [...$walk, ...array_column($this->json()['items'], 'id')];
        }
        self::assertSame([$newest, $older, $oldest], $walk, 'The forward cursor walk skipped or repeated a proposal.');
        $this->send('GET', '/role-grant-proposals?limit=1&before='.(string) $this->json()['previous_cursor']);
        self::assertSame([$older], array_column($this->json()['items'], 'id'), 'The backward cursor did not step back one row.');

        foreach (['limit=0', 'user=nope', 'role=nope', 'after=garbage', 'status=open'] as $query) {
            $this->send('GET', '/role-grant-proposals?'.$query);
            self::assertSame(422, $this->httpStatus(), $query);
        }
    }

    /** An empty workspace: an empty page, `tenant_has_rows` false. */
    #[Test]
    public function anEmptyListSaysTheWorkspaceHasNone(): void
    {
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $this->send('GET', '/role-grant-proposals');
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['items' => [], 'next_cursor' => null, 'previous_cursor' => null, 'tenant_has_rows' => false], $this->json());
    }

    // -----------------------------------------------------------------------

    private function roleRows(): int
    {
        return $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM audit_log WHERE event_type LIKE 'role.%'");
    }

    private function assertRefused(string $rule, string $case = ''): void
    {
        self::assertSame(409, $this->httpStatus(), $case.': '.$this->client()->getResponse()->getContent());
        self::assertSame($rule, $this->json()['rule'] ?? null, $case);
        self::assertSame(self::COPY[$rule], $this->json()['detail'] ?? null, $case.': the refusal sentence is not UX\'s.');
    }
}

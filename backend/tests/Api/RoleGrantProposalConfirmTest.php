<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Application\RoleAssignment;
use App\Modules\UserManagement\Application\RoleGrantProposalConfirmation;
use App\Modules\UserManagement\Application\RoleGrantProposalStatusRefusal;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\UserManagement\Domain\OwnerMarker;
use App\Modules\UserManagement\Infrastructure\Entity\RoleGrantProposal;
use App\SharedKernel\ValueObject\AuditEvent;
use App\SharedKernel\ValueObject\RoleEventMetadata;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **E-15e's confirmation, C2: `ConfirmRoleGrantProposal`** (roles slice 3; `docs/session13/roles-s3-design.md` §4.1, §4.3,
 * §4.7, §8, §15; owner ruling Q-6 (c′); `UX` `roles-s3-ux-copy.md` §4.1 and §4.3; C3 texts since roles slice 3 C3).
 *
 * One rule per test: the 200 and its body (the proposal, now `confirmed`, PC-16's three references `{id, display_name}`;
 * the grant addressable from the body), the one `role.assigned` row with Q1's metadata (T11), T1's positive half
 * (confirmation confers what the pending proposal did not), the owner check under the lock before the proposal is read
 * (T7 and its boundary cases), the status guard in the flow's own words (`UX` F-1), C15 (T12), C4 (T6), C8/C9, phase 1 on
 * the confirm route (V2-R-6, E-15a — T5), C14 (T9), phases 2–4 on the confirmer's set (E-15c, C12, PC-13 with Confirm's
 * tail — T8), and the stale identity map (S-12). **Every positive confirmation seeds the owner marker explicitly**
 * (`principal(OWNER)` holds the whole catalogue; RED-5 M-2), because the house's default principal is not an owner.
 *
 * **Every refusal's sentence is pinned as a literal** — `UX`'s text; the C3 commit moved the C2 texts to their C3 texts
 * (Reject and Withdraw exist only from C3, `UX` rule 4), a visible edit here. The order between the rules is
 * `GrantRoutingTest`'s; the concurrency half (positional lock-first case, demotion twins A and B, Confirm ∥ Confirm,
 * Confirm ∥ `AssignRole`) lives in `PurchaseOrderRowLockTest`.
 */
final class RoleGrantProposalConfirmTest extends ApiTestCase
{
    use RoleGrantFixtures;

    /**
     * `UX` §4.1, the **C3** column, verbatim (roles slice 3 C3 moved these pins from the C2 texts: Reject and Withdraw
     * exist now, so a sentence may say how an open proposal is closed — `UX` rule 4). #25 is the same at C2 and C3.
     */
    public const COPY = [
        'role_grant_proposal.decider_not_owner' => 'Only an owner can confirm or reject role proposals. Ask an owner.',
        'role_grant_proposal.expired' => 'This proposal is more than 14 days old, so it can\'t be confirmed any more. It can still be rejected or withdrawn to close it, and then the role can be given directly if it\'s still wanted.',
        'role_grant_proposal.confirmer_is_proposer' => 'You proposed this, so you can\'t confirm it too. Another owner has to confirm it. You can still withdraw it.',
        'role_grant_proposal.proposer_is_inactive' => 'The person who proposed this is no longer active in this workspace, so the proposal can\'t be confirmed. Reject it to close it; then the role can be given directly if it\'s still wanted.',
        'role_grant_proposal.proposer_cannot_assign' => 'The person who proposed this can no longer manage roles, so the proposal can\'t be confirmed. Reject it to close it; then the role can be given directly if it\'s still wanted.',
        'user_role.subject_is_deleted' => 'This person has been removed from the workspace, so they can\'t be given a role.',
        'user_role.subject_is_inactive' => 'This person\'s account is not active, so they can\'t be given a role.',
        'user_role.role_is_archived' => 'This role is archived, so it can\'t be given to anyone, and this proposal can\'t be confirmed. Reject it to close it.',
        'user_role.actor_is_subject' => 'You can\'t confirm a role proposed for yourself. Another owner has to confirm it. You can reject it if you don\'t want it.',
        'role_grant_proposal.role_changed' => 'This role\'s permissions have changed since it was proposed, so the proposal can\'t be confirmed: it would give something other than what was proposed. Reject it to close it; then the role can be given directly if it\'s still wanted.',
        'user_role.exceeds_actor_permissions' => 'This role includes permissions you don\'t have yourself, so you can\'t confirm it. An owner who holds all of its permissions can.',
        'user_role.already_held' => 'This person already has this role.',
    ];

    /** §3.3: the closed member set, in serialisation order — Confirm returns the same shape (branch 5). */
    private const SHAPE = ['id', 'user', 'role', 'permission_codes', 'status', 'is_expired', 'proposed_by', 'proposed_at', 'expires_at', 'decided_by', 'decided_at', 'reason', 'lapse_reason'];

    /**
     * **200, branch 5:** the body is the proposal in its new state (`confirmed`, `decided_by` = the confirmer as `{id,
     * display_name}` only, `decided_at` set, no `Location`); the grant exists and is addressable from the body
     * (`/users/{user.id}/roles/{role.id}`, design D-4 — `RevokeRole` answers 204 on it); GET serves the body byte for byte;
     * the row's decision columns are exactly what the flush writes (M-4: `decided_at` at microsecond precision, after
     * `proposed_at`).
     */
    #[Test]
    public function aConfirmationIs200AndTheGrantIsMade(): void
    {
        $role = $this->systemRole(DefaultRoles::FINANCE);
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $proposer = $this->principal(DefaultRoles::ADMIN);
        $id = $this->seedPendingProposal($subject, $role, $proposer);
        $owner = $this->principal(DefaultRoles::OWNER);
        $this->actAsUser(self::TENANT_A, $owner);

        $this->confirm($id);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertNull($this->client()->getResponse()->headers->get('Location'), 'A transition creates no row this resource serves: no Location.');
        $body = $this->json();
        self::assertSame(self::SHAPE, array_keys($body), 'The confirmation body is not §3.3\'s closed shape.');
        self::assertSame(['confirmed', $id], [$body['status'], $body['id']]);
        self::assertSame(['id' => $owner, 'display_name' => 'Fixture Actor'], $body['decided_by'], 'PC-16 field 3 is not {id, display_name}.');
        self::assertSame(['id' => $subject, 'display_name' => 'Fixture Actor'], $body['user']);
        self::assertSame(['id' => $proposer, 'display_name' => 'Fixture Actor'], $body['proposed_by']);
        self::assertNotNull($body['decided_at']);
        self::assertNull($body['lapse_reason']);
        self::assertFalse($body['is_expired']);

        self::assertSame([['n' => 1]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) AS n FROM user_role WHERE user_id = ?::uuid AND role_id = ?::uuid', [$subject, $role]), 'The confirmation made no grant.');
        self::assertSame([['decider' => $owner, 'micro' => true, 'after' => true]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            "SELECT decided_by_user_id::text AS decider, decided_at::text LIKE '%.%' AS micro, decided_at >= proposed_at AS after FROM role_grant_proposal WHERE id = ?::uuid",
            [$id]));

        $this->send('GET', '/role-grant-proposals/'.$id);
        self::assertSame($body, $this->json(), 'GetRoleGrantProposal does not serve the confirmation body.');

        $this->send('DELETE', '/users/'.$body['user']['id'].'/roles/'.$body['role']['id']);
        self::assertSame(204, $this->httpStatus(), 'The grant is not addressable from the body\'s user and role (design D-4).');
    }

    /**
     * **T11 (confirm): exactly one `role.assigned`** — the CONFIRMER as actor (the granter of record, Q1), the subject
     * USER, `metadata` = `{role_id, proposal_id, proposed_by_user_id}` by the `RoleEventMetadata` constants and nothing
     * else, the request's IP, `occurred_at` after the request began. A refused confirmation writes no `role.*` row.
     */
    #[Test]
    public function aConfirmationWritesExactlyOneRoleAssignedRowNamingTheProposal(): void
    {
        $role = $this->systemRole(DefaultRoles::FINANCE);
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $proposer = $this->principal(DefaultRoles::ADMIN);
        $id = $this->seedPendingProposal($subject, $role, $proposer);
        $owner = $this->principal(DefaultRoles::OWNER);

        $this->actAsUser(self::TENANT_A, $proposer); // a non-owner: refused, writes nothing
        $this->confirm($id);
        $this->assertRefused('role_grant_proposal.decider_not_owner');
        self::assertSame(0, $this->roleRows(), 'A refused confirmation wrote a role.* row.');

        $before = (string) $this->owner()->fetchOne('SELECT now()::text');
        $this->actAsUser(self::TENANT_A, $owner);
        $this->confirm($id);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        self::assertSame([[
            'event_type' => AuditEvent::ROLE_ASSIGNED,
            'actor' => $owner,
            'subject_type' => 'app_user',
            'subject' => $subject,
            'ip' => '127.0.0.1',
            'keys' => '["proposal_id", "proposed_by_user_id", "role_id"]',
            'role_id' => $role,
            'proposal_id' => $id,
            'proposed_by' => $proposer,
            'after_start' => true,
        ]], $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT event_type, actor_user_id::text AS actor, subject_type, subject_id::text AS subject, host(ip_address) AS ip,
                   (SELECT jsonb_agg(k ORDER BY k)::text FROM jsonb_object_keys(metadata) k) AS keys,
                   metadata->>? AS role_id, metadata->>? AS proposal_id, metadata->>? AS proposed_by, occurred_at >= ?::timestamptz AS after_start
              FROM audit_log WHERE event_type LIKE 'role.%'
            SQL, [RoleEventMetadata::ROLE_ID, RoleEventMetadata::PROPOSAL_ID, RoleEventMetadata::PROPOSED_BY_USER_ID, $before]));
    }

    /**
     * **T1, positive half (design §9, §15; C1 shipped the negative half):** `warehouse_operator` proposed for S confers
     * nothing — S is 403 on `CreateGoodsReceipt` and `/me` lacks `goods_receipt.create` — and its CONFIRMATION confers it:
     * `/me` lists it and the gate admits S (not 403). Mutation M1 (`UNION … FROM role_grant_proposal` in `IdentityReader`)
     * reddens the first half; dropping the confirmation's `INSERT` reddens the second.
     */
    #[Test]
    public function aPendingProposalConfersNothingAndItsConfirmationDoes(): void
    {
        $role = $this->systemRole(DefaultRoles::WAREHOUSE_OPERATOR);
        $subject = $this->seedPrincipal(self::TENANT_A, ['inventory_item.view']);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $this->propose($subject, $role);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $id = $this->json()['id'];

        $receipt = '/purchase-orders/'.Uuid::v4()->toRfc4122().'/goods-receipts';
        $this->actAsUser(self::TENANT_A, $subject);
        $this->send('GET', '/me');
        self::assertNotContains('goods_receipt.create', $this->json()['permission_codes'], 'A pending proposal conferred its codes on /me.');
        $this->send('POST', $receipt, ['lines' => []], $this->key());
        self::assertSame(403, $this->httpStatus(), 'A pending proposal let its subject past the gate.');

        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::OWNER));
        $this->confirm($id);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->actAsUser(self::TENANT_A, $subject);
        $this->send('GET', '/me');
        self::assertContains('goods_receipt.create', $this->json()['permission_codes'], 'The confirmation did not confer the role\'s codes.');
        $this->send('POST', $receipt, ['lines' => []], $this->key());
        self::assertNotSame(403, $this->httpStatus(), 'The confirmed grant did not let its subject past the gate.');
    }

    /**
     * **T7 (Q-6 (c′)): only an owner who also manages roles confirms.** A `role.assign` holder without the marker → 409
     * `decider_not_owner` (no denial row: it is not a permission refusal); a role merely NAMED `owner` without the marker →
     * the same 409 (M2b: never a name); a composed seat holding the marker AND `role.assign` confirms; a marker holder
     * WITHOUT `role.assign` → the gate's 403 with its denial row — the lost approve-only seat, stated, and **G-11's closing
     * mechanism for Confirm** (RED-5 L-5: every decider can read a proposal before deciding it).
     */
    #[Test]
    public function onlyAnOwnerWhoManagesRolesConfirms(): void
    {
        $role = $this->systemRole(DefaultRoles::FINANCE);
        $proposer = $this->principal(DefaultRoles::ADMIN);

        $this->actAsUser(self::TENANT_A, $this->seedPrincipal(self::TENANT_A, ['role.assign', 'role.view']));
        $this->confirm($this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $role, $proposer));
        $this->assertRefused('role_grant_proposal.decider_not_owner', 'a role.assign holder');
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM audit_log WHERE event_type = ?', [AuditEvent::AUTHZ_PERMISSION_DENIED]),
            'decider_not_owner wrote a denial row; it is a 409, not a permission refusal.');

        $namedOwner = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, "INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, 'owner')", [$namedOwner, self::TENANT_A]);
        foreach (['role.assign', 'role.view'] as $code) {
            $this->insertAsOwnerForTenant(self::TENANT_A,
                'INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?)',
                [self::TENANT_A, $namedOwner, $code]);
        }
        $this->actAsUser(self::TENANT_A, $this->holderOf([$namedOwner]));
        $this->confirm($this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $role, $proposer));
        $this->assertRefused('role_grant_proposal.decider_not_owner', 'a role named owner');

        $this->actAsUser(self::TENANT_A, $this->seedPrincipal(self::TENANT_A, [...DefaultRoles::codesFor(DefaultRoles::FINANCE), 'role.assign', OwnerMarker::CODE]));
        $this->confirm($this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $role, $proposer));
        self::assertSame(200, $this->httpStatus(), 'A composed seat holding the marker and role.assign could not confirm: '.$this->client()->getResponse()->getContent());

        $markerOnly = $this->seedPrincipal(self::TENANT_A, [OwnerMarker::CODE]);
        $this->actAsUser(self::TENANT_A, $markerOnly);
        $this->confirm($this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $role, $proposer));
        self::assertSame(403, $this->httpStatus());
        self::assertSame(RoleRevokeTest::ROLE_ASSIGN_DENIED, (string) $this->client()->getResponse()->getContent());
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM audit_log WHERE event_type = ? AND actor_user_id = ?::uuid', [AuditEvent::AUTHZ_PERMISSION_DENIED, $markerOnly]),
            'A marker holder without role.assign was refused without the gate\'s denial row.');
    }

    /**
     * **The owner check runs before the proposal is read — the same refusal for any id** (§4.7, RED-5 M-4; `UX` rule 5).
     * A `role.assign` holder without the marker gets `decider_not_owner` for an unknown uuid and a malformed id (not 404),
     * for a proposal they made themselves (not `confirmer_is_proposer`), and for an expired one (not `expired`). Mutation
     * M2f (owner check moved after `refresh()`) → the unknown-id case 404, red.
     */
    #[Test]
    public function aNonOwnerIsRefusedTheSameWayForAnyId(): void
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
            $this->confirm($id);
            $this->assertRefused('role_grant_proposal.decider_not_owner', $case);
        }
        self::assertSame(0, $this->roleRows());
    }

    /** An owner: a malformed, absent or another workspace's id is 404 (after the owner check). */
    #[Test]
    public function anOwnerGets404ForAProposalThatIsNotThere(): void
    {
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::OWNER));
        foreach (['not-a-uuid', Uuid::v4()->toRfc4122()] as $id) {
            $this->confirm($id);
            self::assertSame(404, $this->httpStatus(), $id);
        }
    }

    /**
     * **`UX` F-1 (ruled 2026-10-05): a proposal that is no longer pending is refused in the flow's own words** — same rule
     * code (`role_grant_proposal.status_transition_is_legal`), `UX`'s sentence by the status it is in, and `detail` never
     * carries the table name (the generic sentence would print `"cancelled"`, which this resource's copy forbids). Nothing
     * is written. Mutation: delete the catch around `confirm()` → the literal red.
     */
    #[Test]
    #[DataProvider('decidedStatuses')]
    public function aDecidedProposalCannotBeConfirmedAndIsToldInTheFlowsWords(string $status): void
    {
        $role = $this->systemRole(DefaultRoles::FINANCE);
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $proposer = $this->principal(DefaultRoles::ADMIN);
        $owner = $this->principal(DefaultRoles::OWNER);
        $decider = match ($status) {
            RoleGrantProposal::STATUS_CANCELLED => $proposer,
            RoleGrantProposal::STATUS_LAPSED => null,
            default => $this->principal(DefaultRoles::OWNER),
        };
        $id = $this->seedDecidedProposal($status, $subject, $role, $proposer, $decider);
        $this->actAsUser(self::TENANT_A, $owner);

        $this->confirm($id);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('role_grant_proposal.status_transition_is_legal', $this->json()['rule'] ?? null);
        self::assertSame(RoleGrantProposalStatusRefusal::SENTENCES[$status], $this->json()['detail'] ?? null);
        self::assertStringNotContainsString('role_grant_proposal', (string) ($this->json()['detail'] ?? ''));
        self::assertSame(0, $this->roleRows());
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM user_role WHERE user_id = ?::uuid', [$subject]));
    }

    /** @return iterable<string, array{string}> */
    public static function decidedStatuses(): iterable
    {
        foreach ([RoleGrantProposal::STATUS_CONFIRMED, RoleGrantProposal::STATUS_REJECTED, RoleGrantProposal::STATUS_CANCELLED, RoleGrantProposal::STATUS_LAPSED] as $status) {
            yield $status => [$status];
        }
    }

    /**
     * F-1's literal sentences, as ruled, and the map's keys are exactly the entity's non-`pending` statuses — a status
     * with no sentence rethrows the original (the generic translator answers), so a new status cannot ship silently.
     */
    #[Test]
    public function theStatusSentencesCoverExactlyTheDecidedStatuses(): void
    {
        self::assertSame([
            'confirmed' => 'This proposal has already been confirmed.',
            'rejected' => 'This proposal has already been rejected.',
            'cancelled' => 'This proposal has already been withdrawn by the person who proposed it.',
            'lapsed' => 'This proposal has already lapsed.',
        ], RoleGrantProposalStatusRefusal::SENTENCES);

        $statuses = array_values(array_filter(
            (new \ReflectionClass(RoleGrantProposal::class))->getConstants(),
            static fn (mixed $value, string $name): bool => str_starts_with($name, 'STATUS_') && RoleGrantProposal::STATUS_PENDING !== $value,
            \ARRAY_FILTER_USE_BOTH,
        ));
        $keys = array_keys(RoleGrantProposalStatusRefusal::SENTENCES);
        sort($statuses);
        sort($keys);
        self::assertSame($statuses, $keys, 'A non-pending RoleGrantProposal status has no UX sentence, or a sentence names no status.');
    }

    /** **T12:** a proposal one DAY past its window cannot be confirmed (nothing written; it stays `pending`); a fresh one can. */
    #[Test]
    public function anExpiredProposalCannotBeConfirmed(): void
    {
        $role = $this->systemRole(DefaultRoles::FINANCE);
        $proposer = $this->principal(DefaultRoles::ADMIN);
        $expired = $this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $role, $proposer, ageHours: 360);
        $fresh = $this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $role, $proposer, ageHours: 312);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::OWNER));

        $this->confirm($expired);
        $this->assertRefused('role_grant_proposal.expired');
        self::assertSame([['status' => 'pending']], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT status FROM role_grant_proposal WHERE id = ?::uuid', [$expired]),
            'C15 wrote something: an untouched stale proposal stays pending (R-13).');

        $this->confirm($fresh);
        self::assertSame(200, $this->httpStatus(), 'Control: a proposal a day inside its window was refused.');
    }

    /** **T6 / C4:** a proposer who later became an owner still cannot confirm their own proposal (also a CHECK). */
    #[Test]
    public function aProposerCannotConfirmTheirOwnProposalEvenAsAnOwner(): void
    {
        $proposer = $this->principal(DefaultRoles::OWNER); // proposed while admin (seeded), an owner now
        $id = $this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $this->systemRole(DefaultRoles::FINANCE), $proposer);
        $this->actAsUser(self::TENANT_A, $proposer);

        $this->confirm($id);
        $this->assertRefused('role_grant_proposal.confirmer_is_proposer');
        self::assertSame(0, $this->roleRows());
    }

    /**
     * **T5 (confirm half) / E-15a:** an owner cannot confirm a role proposed for themselves — Confirm's own sentence (#22).
     * Also CHECK `role_grant_proposal_confirmer_not_subject`; mutation M4b (drop the handler check) → the translated
     * `23514` answers the same code, and dropping the translation too → 500.
     */
    #[Test]
    public function nobodyConfirmsARoleProposedForThemselves(): void
    {
        $owner = $this->principal(DefaultRoles::OWNER);
        $id = $this->seedPendingProposal($owner, $this->systemRole(DefaultRoles::FINANCE), $this->principal(DefaultRoles::ADMIN));
        $this->actAsUser(self::TENANT_A, $owner);

        $this->confirm($id);
        $this->assertRefused('user_role.actor_is_subject');
        self::assertSame(0, $this->roleRows());
    }

    /**
     * **C8 / C9 — the proposer, re-checked at confirmation:** deleted or inactive → `proposer_is_inactive`; no longer
     * holding `role.assign` through a live role → `proposer_cannot_assign`. Live control: an active proposer who still
     * manages roles → 200.
     */
    #[Test]
    public function aProposerWhoIsGoneOrNoLongerManagesRolesVoidsTheProposal(): void
    {
        $role = $this->systemRole(DefaultRoles::FINANCE);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::OWNER));

        foreach ([
            'deleted' => ['UPDATE app_user SET deleted_at = now() WHERE id = ?::uuid', 'role_grant_proposal.proposer_is_inactive'],
            'inactive' => ['UPDATE app_user SET is_active = false WHERE id = ?::uuid', 'role_grant_proposal.proposer_is_inactive'],
            'demoted' => ['DELETE FROM user_role WHERE user_id = ?::uuid', 'role_grant_proposal.proposer_cannot_assign'],
        ] as $case => [$sql, $rule]) {
            $proposer = $this->principal(DefaultRoles::ADMIN);
            $id = $this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $role, $proposer);
            $this->executeAsOwnerForTenant(self::TENANT_A, $sql, [$proposer]);
            $this->confirm($id);
            $this->assertRefused($rule, $case);
        }

        $this->confirm($this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $role, $this->principal(DefaultRoles::ADMIN)));
        self::assertSame(200, $this->httpStatus(), 'Control: a live proposer\'s proposal could not be confirmed.');
    }

    /**
     * **Phase 1 on the confirm route (V2-R-6):** a removed subject, an inactive subject, an archived role — each refused,
     * the archived role in Confirm's own words (#21: "Choose another role" is a wall for a confirmer). Each with nothing
     * written.
     */
    #[Test]
    public function aRemovedSubjectOrArchivedRoleCannotBeConfirmed(): void
    {
        $proposer = $this->principal(DefaultRoles::ADMIN);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::OWNER));

        $gone = $this->seedPrincipal(self::TENANT_A, []);
        $id = $this->seedPendingProposal($gone, $this->systemRole(DefaultRoles::FINANCE), $proposer);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET deleted_at = now() WHERE id = ?::uuid', [$gone]);
        $this->confirm($id);
        $this->assertRefused('user_role.subject_is_deleted');

        $idle = $this->seedPrincipal(self::TENANT_A, []);
        $id = $this->seedPendingProposal($idle, $this->systemRole(DefaultRoles::FINANCE), $proposer);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET is_active = false WHERE id = ?::uuid', [$idle]);
        $this->confirm($id);
        $this->assertRefused('user_role.subject_is_inactive');

        $archived = $this->systemRole(DefaultRoles::FINANCE);
        $id = $this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $archived, $proposer);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE role SET deleted_at = now() WHERE id = ?::uuid', [$archived]);
        $this->confirm($id);
        $this->assertRefused('user_role.role_is_archived');

        self::assertSame(0, $this->roleRows());
    }

    /**
     * **T9 / C14:** the role's codes changed since the proposal — a code ADDED, and (separately) a code REMOVED — each →
     * `role_changed` (compared as sets, both sides sorted in PHP). Control: the snapshot in a different stored order is the
     * same set → 200 (a list comparison would refuse it).
     */
    #[Test]
    public function aRoleWhoseCodesChangedSinceTheProposalCannotBeConfirmed(): void
    {
        $proposer = $this->principal(DefaultRoles::ADMIN);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::OWNER));

        $added = $this->systemRole(DefaultRoles::FINANCE);
        $id = $this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $added, $proposer);
        $this->insertAsOwnerForTenant(self::TENANT_A,
            "INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, 'inventory_item.view')",
            [self::TENANT_A, $added]);
        $this->confirm($id);
        $this->assertRefused('role_grant_proposal.role_changed', 'a code added');

        $removed = $this->systemRole(DefaultRoles::FINANCE);
        $id = $this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $removed, $proposer);
        $this->executeAsOwnerForTenant(self::TENANT_A,
            "DELETE FROM role_permission WHERE role_id = ?::uuid AND permission_code = 'invoice.pay'", [$removed]);
        $this->confirm($id);
        $this->assertRefused('role_grant_proposal.role_changed', 'a code removed');

        $reordered = $this->systemRole(DefaultRoles::FINANCE);
        $id = $this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $reordered, $proposer);
        $this->executeAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            UPDATE role_grant_proposal SET permission_codes = (SELECT jsonb_agg(c ORDER BY c DESC) FROM jsonb_array_elements_text(permission_codes) c)
             WHERE id = ?::uuid
            SQL, [$id]);
        $this->confirm($id);
        self::assertSame(200, $this->httpStatus(), 'C14 compared the snapshot as an ordered list, not as a set: '.$this->client()->getResponse()->getContent());
    }

    /**
     * **E-15c on the CONFIRMER's set (#25):** an owner seat built from a composed role, lacking codes a composed role
     * carries, cannot confirm it — Confirm's own sentence ("Ask an owner" would tell an owner to ask an owner). Control: a
     * seat holding every code of the role confirms.
     */
    #[Test]
    public function aConfirmerMustHoldEveryCodeOfACustomRole(): void
    {
        $composed = $this->composedRole(['invoice.pay', 'role.view']);
        $proposer = $this->seedPrincipal(self::TENANT_A, ['role.assign', 'role.view', 'invoice.pay']);

        $this->actAsUser(self::TENANT_A, $this->seedPrincipal(self::TENANT_A, ['role.assign', OwnerMarker::CODE]));
        $this->confirm($this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $composed, $proposer));
        $this->assertRefused('user_role.exceeds_actor_permissions');

        $this->actAsUser(self::TENANT_A, $this->seedPrincipal(self::TENANT_A, ['role.assign', 'role.view', 'invoice.pay', OwnerMarker::CODE]));
        $this->confirm($this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $composed, $proposer));
        self::assertSame(200, $this->httpStatus(), 'Control: a seat holding every code could not confirm.');
    }

    /** **C12:** a subject who already holds the role (granted directly since the proposal) → `already_held`. */
    #[Test]
    public function aRoleTheSubjectAlreadyHoldsCannotBeConfirmed(): void
    {
        $role = $this->systemRole(DefaultRoles::FINANCE);
        $subject = $this->holderOf([$role]);
        $id = $this->seedPendingProposal($subject, $role, $this->principal(DefaultRoles::ADMIN));
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::OWNER));

        $this->confirm($id);
        $this->assertRefused('user_role.already_held');
    }

    /**
     * **T8 — PC-13 re-run at confirmation, over the subject's set as it stands NOW.** Two pending proposals for one subject
     * that together span SoD-2 (`buyer` raises purchase orders, `warehouse_operator` records goods received): the first
     * confirms; the second is refused `separation_of_duty`, naming SoD-2, with **Confirm's tail** (#27, `UX` F-2: "Choose
     * another role or another person" is a wall for a confirmer). The R-17 control: a subject holding `viewer` (on no SoD
     * row) is confirmed `buyer` — PC-13 decides on rows, not on holding something. Mutation M6 (skip phase 4) → red.
     */
    #[Test]
    public function aConfirmationThatWouldSplitADutyIsRefusedInTheConfirmersWords(): void
    {
        $buyer = $this->systemRole(DefaultRoles::BUYER);
        $warehouse = $this->systemRole(DefaultRoles::WAREHOUSE_OPERATOR);
        $proposer = $this->principal(DefaultRoles::ADMIN);
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $first = $this->seedPendingProposal($subject, $buyer, $proposer);
        $second = $this->seedPendingProposal($subject, $warehouse, $proposer);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::OWNER));

        $this->confirm($first);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->confirm($second);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('user_role.separation_of_duty', $this->json()['rule'] ?? null);
        $name = (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT name FROM role WHERE id = ?::uuid', [$warehouse])[0]['name'];
        self::assertSame(RoleAssignment::separationOfDutyMessage($name, ['SoD-2'], 'This proposal can\'t be confirmed. Reject it to close it.'), $this->json()['detail'] ?? null);
        self::assertStringEndsWith('(SoD-2). This proposal can\'t be confirmed. Reject it to close it.', (string) $this->json()['detail'], 'Confirm\'s SoD sentence does not end with its own (C3) tail.');

        $viewerHolder = $this->holderOf([$this->systemRole(DefaultRoles::VIEWER)]);
        $this->confirm($this->seedPendingProposal($viewerHolder, $this->systemRole(DefaultRoles::BUYER), $proposer));
        self::assertSame(200, $this->httpStatus(), 'R-17 control: a viewer holder could not be confirmed buyer.');
    }

    /**
     * **S-12 / S-13: the confirmation decides on the proposal as it is under the lock, even when the entity was already
     * loaded.** The test loads the pending proposal into the shared unit of work, an owner process then rejects it, and
     * the confirmation must answer the status refusal — not confirm a stale copy. Mutation: drop `refresh()` → the flush
     * meets the decided-is-final trigger (a 500) or confirms, red.
     */
    #[Test]
    public function aConfirmationReReadsAProposalThatWasAlreadyLoaded(): void
    {
        $id = $this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $this->systemRole(DefaultRoles::FINANCE), $this->principal(DefaultRoles::ADMIN));
        $rejecter = $this->principal(DefaultRoles::OWNER);
        $loaded = $this->tenantContext()->run(self::TENANT_A, static fn (EntityManagerInterface $em): ?RoleGrantProposal => $em->find(RoleGrantProposal::class, Uuid::fromString($id)));
        self::assertInstanceOf(RoleGrantProposal::class, $loaded, 'The premise: the proposal is managed before the request.');
        self::assertSame(RoleGrantProposal::STATUS_PENDING, $loaded->getStatus());

        $this->executeAsOwnerForTenant(self::TENANT_A,
            "UPDATE role_grant_proposal SET status = 'rejected', decided_by_user_id = ?::uuid, decided_at = clock_timestamp(), updated_at = now() WHERE id = ?::uuid",
            [$rejecter, $id]);

        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::OWNER));
        $this->confirm($id);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(RoleGrantProposalStatusRefusal::SENTENCES['rejected'], $this->json()['detail'] ?? null);
        self::assertSame(0, $this->roleRows());
    }

    /**
     * **IA §6.8 / PC-16 condition 1:** a `viewer` (`role.view` only) is 403 on Confirm with the gate's denial row — nothing
     * pending, not even whether the id exists.
     */
    #[Test]
    public function aConfirmationIsRefusedToARoleViewHolder(): void
    {
        $id = $this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $this->systemRole(DefaultRoles::FINANCE), $this->principal(DefaultRoles::ADMIN));
        $viewer = $this->principal(DefaultRoles::VIEWER);
        $this->actAsUser(self::TENANT_A, $viewer);

        $this->confirm($id);
        self::assertSame(403, $this->httpStatus());
        self::assertSame(RoleRevokeTest::ROLE_ASSIGN_DENIED, (string) $this->client()->getResponse()->getContent());
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM audit_log WHERE event_type = ? AND actor_user_id = ?::uuid', [AuditEvent::AUTHZ_PERMISSION_DENIED, $viewer]));
    }

    /** Branch 5's no-body rule: Confirm accepts no field, and a body is refused field by field (`BodylessWriteRefusal`). */
    #[Test]
    public function aConfirmationAcceptsNoBody(): void
    {
        $id = $this->seedPendingProposal($this->seedPrincipal(self::TENANT_A, []), $this->systemRole(DefaultRoles::FINANCE), $this->principal(DefaultRoles::ADMIN));
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::OWNER));

        $this->send('POST', '/role-grant-proposals/'.$id.'/confirmation', ['role_id' => Uuid::v4()->toRfc4122()]);
        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([['status' => 'pending']], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT status FROM role_grant_proposal WHERE id = ?::uuid', [$id]));
    }

    /** The handler's own sentences are `UX`'s C3 texts, held here so the class and this test cannot drift apart. */
    #[Test]
    public function theHandlersSentencesAreUxsC3Texts(): void
    {
        self::assertSame(self::COPY['role_grant_proposal.decider_not_owner'], RoleGrantProposalConfirmation::DECIDER_NOT_OWNER);
        self::assertSame(self::COPY['role_grant_proposal.expired'], RoleGrantProposalConfirmation::EXPIRED);
        self::assertSame(self::COPY['role_grant_proposal.confirmer_is_proposer'], RoleGrantProposalConfirmation::CONFIRMER_IS_PROPOSER);
        self::assertSame(self::COPY['role_grant_proposal.proposer_is_inactive'], RoleGrantProposalConfirmation::PROPOSER_IS_INACTIVE);
        self::assertSame(self::COPY['role_grant_proposal.proposer_cannot_assign'], RoleGrantProposalConfirmation::PROPOSER_CANNOT_ASSIGN);
        self::assertSame(self::COPY['role_grant_proposal.role_changed'], RoleGrantProposalConfirmation::ROLE_CHANGED);
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

<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Application\GrantConfirmationPolicy;
use App\Modules\UserManagement\Application\RoleAssignment;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\UserManagement\Domain\OwnerMarker;
use App\Modules\UserManagement\Domain\SeparationOfDuty;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\SharedKernel\ValueObject\AuditEvent;
use App\SharedKernel\ValueObject\RoleEventMetadata;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`AssignRole` — `POST /api/v1/users/{id}/roles`, body `{role_id}`** (roles slice 2, C3; `docs/session13/roles-s2-design.md`
 * §5.2 as amended by `roles-s2-spar.md` §2 and `roles-s2-sec.md`). One rule per test: the 201 and its body (`SEC` S-1 (b)),
 * the one `role.assigned` row, the 428, E-15a, E-15b, E-15e's routing (domain-derived, T3), E-15c, C12, V2-R-6, PC-13 "new
 * conflict only", the 404/422 shapes, and H1 (`inserted_by`). **Every refusal's sentence is pinned as a literal** — `UX`'s
 * copy (`roles-s2-ux-copy.md` §3.11), so a wording change is a visible edit.
 *
 * **The concurrency half lives in `PurchaseOrderRowLockTest`** (positional lock-first case, the grant demotion twin, the
 * PC-13 race), beside the other lock evidence.
 *
 * Roles are seeded as the owner connection: DEFAULT roles as system roles (`is_system = true`) carrying their catalogue
 * codes, composed roles as `is_system = false`. The rig's principals hold one composed role each (`seedPrincipal`).
 */
final class RoleAssignTest extends ApiTestCase
{
    /** `UX` §3.11, verbatim. */
    private const COPY = [
        'user_role.actor_is_subject' => 'Nobody can give themselves a role. Ask someone else who manages roles to do it.',
        'user_role.marker_needs_owner' => 'Only an owner can give someone this role, because it makes them an owner.',
        // Roles slice 3 C2 (`UX` `roles-s3-ux-copy.md` #28, ruled 2026-10-05, F-3): the route exists, so the sentence names
        // it. Was the slice-2 "Only an owner can give someone this role, because it includes permissions that only an owner
        // can hand out. Ask an owner to give it." — shipped wording changed, owner told.
        'user_role.needs_owner_confirmation' => 'Giving someone this role needs an owner\'s confirmation. You can propose it instead; it takes effect only if an owner confirms it within 14 days.',
        'user_role.exceeds_actor_permissions' => 'This role includes permissions you don\'t have yourself, so you can\'t give it to anyone. Ask an owner, or someone who holds all of its permissions.',
        'user_role.subject_is_deleted' => 'This person has been removed from the workspace, so they can\'t be given a role.',
        'user_role.subject_is_inactive' => 'This person\'s account is not active, so they can\'t be given a role.',
        'user_role.role_is_archived' => 'This role is archived, so it can\'t be given to anyone. Choose another role.',
        'user_role.already_held' => 'This person already has this role.',
    ];

    /**
     * **S-1 (b):** 201; `Location` names the grant (`/users/{id}/roles/{roleId}`, the URI `RevokeRole` addresses); the body
     * is the granted role in `GetRole`'s shape, read AFTER the insert under the lock — `holder_count` = before + 1 and `id` =
     * the posted `role_id`. Mutation (body read before the insert) → the count assertion red.
     */
    #[Test]
    public function aGrantIs201AndLocationNamesTheGrant(): void
    {
        $role = $this->composedRole(['role.view']);
        $this->seedHolderOf($role);
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));

        $this->send('GET', '/roles/'.$role);
        self::assertSame(200, $this->httpStatus());
        $before = $this->json();
        self::assertSame(1, $before['holder_count']);

        $this->grant($subject, $role);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('/api/v1/users/'.$subject.'/roles/'.$role, $this->client()->getResponse()->headers->get('Location'));
        $body = $this->json();
        self::assertSame($role, $body['id']);
        self::assertSame(2, $body['holder_count'], 'The body was read before the grant: holder_count did not count it.');
        self::assertSame(array_keys($before), array_keys($body), 'The 201 body is not GetRole\'s shape.');
        self::assertSame([...$before, 'holder_count' => 2], $body);

        $this->send('DELETE', '/api/v1/users/'.$subject.'/roles/'.$role);
        self::assertSame(204, $this->httpStatus(), 'Location does not name a grant RevokeRole can address.');
    }

    /**
     * One `role.assigned`, cell by cell: the actor, the subject USER, the metadata key BY NAME (the constant the
     * reconciliation reads), the request's IP, `occurred_at` from the database clock after the lock. A refused grant writes
     * none.
     */
    #[Test]
    public function aGrantWritesExactlyOneRoleAssignedRow(): void
    {
        $role = $this->composedRole(['role.view']);
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $admin = $this->principal(DefaultRoles::ADMIN);
        $this->actAsUser(self::TENANT_A, $admin);

        $this->grant($subject, Uuid::v4()->toRfc4122());
        self::assertSame(422, $this->httpStatus());
        self::assertSame(0, $this->roleRows(), 'A refused grant wrote a role.* row.');

        $before = (string) $this->owner()->fetchOne('SELECT now()::text');
        $this->grant($subject, $role);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        self::assertSame([[
            'event_type' => AuditEvent::ROLE_ASSIGNED,
            'actor' => $admin,
            'actor_label' => 'Fixture Actor',
            'subject_type' => 'app_user',
            'subject' => $subject,
            'ip' => '127.0.0.1',
            'permission_code' => null,
            'role_id' => $role,
            'only_key' => true,
            'after_start' => true,
        ]], $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT event_type, actor_user_id::text AS actor, actor_label, subject_type, subject_id::text AS subject,
                   host(ip_address) AS ip, permission_code, metadata->>? AS role_id, (metadata - ?::text) = '{}'::jsonb AS only_key,
                   occurred_at >= ?::timestamptz AS after_start
              FROM audit_log WHERE event_type LIKE 'role.%'
            SQL, [RoleEventMetadata::ROLE_ID, RoleEventMetadata::ROLE_ID, $before]));
    }

    /** API-14: a `unique:` cell demands the header — 428, and nothing is written (mutation A7: drop the flag → red). */
    #[Test]
    public function aGrantWithoutAnIdempotencyKeyIs428AndWritesNothing(): void
    {
        $role = $this->composedRole(['role.view']);
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));

        $this->send('POST', '/users/'.$subject.'/roles', ['role_id' => $role]);

        self::assertSame(428, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(0, $this->grantsOf($subject));
        self::assertSame(0, $this->roleRows());
    }

    /** C12: a repeat is 409 `already_held`, and the grant and its row stay single. */
    #[Test]
    public function aRepeatedGrantIs409AlreadyHeld(): void
    {
        $role = $this->composedRole(['role.view']);
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));

        $this->grant($subject, $role);
        self::assertSame(201, $this->httpStatus());
        $this->grant($subject, $role);

        $this->assertRefused('user_role.already_held');
        self::assertSame(1, $this->grantsOf($subject));
        self::assertSame(1, $this->roleRows());
    }

    /** E-15a, unconditionally — an owner granting themselves anything is refused too. */
    #[Test]
    public function nobodyGivesThemselvesARole(): void
    {
        $role = $this->composedRole(['role.view']);
        foreach ([DefaultRoles::ADMIN, DefaultRoles::OWNER] as $seat) {
            $self = $this->principal($seat);
            $this->actAsUser(self::TENANT_A, $self);
            $this->grant($self, $role);
            $this->assertRefused('user_role.actor_is_subject', $seat);
            self::assertSame(1, $this->grantsOf($self), $seat);
        }
        self::assertSame(0, $this->roleRows());
    }

    /** E-15b / Q5: a role conferring the marker is granted only by an owner, and the refusal names no route. */
    #[Test]
    public function onlyAnOwnerGrantsARoleConferringTheMarker(): void
    {
        $ownerRole = $this->systemRole(DefaultRoles::OWNER);
        $subject = $this->seedPrincipal(self::TENANT_A, []);

        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $this->grant($subject, $ownerRole);
        $this->assertRefused('user_role.marker_needs_owner');
        self::assertArrayNotHasKey('proposal', $this->json(), 'The marker refusal names a route; it must never (IA §6.8 item 5).');
        self::assertSame(0, $this->grantsOf($subject));

        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::OWNER));
        $this->grant($subject, $ownerRole);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /**
     * **T3 — `assignRoleAdmitsExactlyTheGrantsNeedingNoConfirmation`** (design §5.2's `GrantRoutingTest`, held here). The
     * domain is derived: seats {`owner`, `admin`, a composed `role.assign` holder WITH an `X(admin)` code, one WITHOUT} ×
     * roles {every default role, as a system role, + one composed role}. Each grant goes to a fresh user; the outcome must be
     * `marker_needs_owner` for the marker role by a non-owner, else `needs_owner_confirmation` iff the policy says so, else
     * `exceeds_actor_permissions` iff a composed role is outside the seat's set, else 201. Literal controls: `admin`+`buyer`
     * → 201; `admin`+`finance` → confirmation; `owner`+`finance` → 201; `admin`+`owner` → marker.
     * Mutations M2 (key on `role.assign`), M2b (key on the role name `owner`), M2c (delete the routing call) → red.
     * **The oracle encodes the slice-2 order (routing before E-15c)** and is correct only over its own domain — fresh users
     * and no composed role carrying an `X(admin)` code, where routing and E-15c never both fail. The routing-last order of
     * roles slice 3 C2 is `GrantRoutingTest`'s, over the widened domain.
     */
    #[Test]
    public function assignRoleAdmitsExactlyTheGrantsNeedingNoConfirmation(): void
    {
        $roles = [];
        foreach (DefaultRoles::names() as $name) {
            $roles[$name] = ['id' => $this->systemRole($name), 'codes' => DefaultRoles::codesFor($name), 'system' => true];
        }
        $roles['composed'] = ['id' => $this->composedRole(['role.view', 'inventory_item.view']), 'codes' => ['inventory_item.view', 'role.view'], 'system' => false];
        $seats = [
            DefaultRoles::OWNER => DefaultRoles::codesFor(DefaultRoles::OWNER),
            DefaultRoles::ADMIN => DefaultRoles::codesFor(DefaultRoles::ADMIN),
            'composed with X(admin)' => ['role.assign', 'role.view', 'invoice.pay'],
            'composed without' => ['role.assign', 'role.view'],
        ];

        $outcomes = [];
        $expected = [];
        foreach ($seats as $seat => $seatCodes) {
            $this->actAsUser(self::TENANT_A, $this->seedPrincipal(self::TENANT_A, $seatCodes));
            foreach ($roles as $name => $role) {
                $this->grant($this->seedPrincipal(self::TENANT_A, []), $role['id']);
                $outcomes[$seat.' grants '.$name] = 201 === $this->httpStatus() ? 201 : ($this->json()['rule'] ?? $this->httpStatus());

                $expected[$seat.' grants '.$name] = match (true) {
                    \in_array(OwnerMarker::CODE, $role['codes'], true) && !\in_array(OwnerMarker::CODE, $seatCodes, true) => 'user_role.marker_needs_owner',
                    GrantConfirmationPolicy::needsConfirmation($seatCodes, $role['codes']) => 'user_role.needs_owner_confirmation',
                    !$role['system'] && [] !== array_diff($role['codes'], $seatCodes) => 'user_role.exceeds_actor_permissions',
                    default => 201,
                };
            }
        }

        self::assertSame(201, $outcomes['admin grants buyer']);
        self::assertSame('user_role.needs_owner_confirmation', $outcomes['admin grants finance']);
        self::assertSame(201, $outcomes['owner grants finance']);
        self::assertSame('user_role.marker_needs_owner', $outcomes['admin grants owner']);
        self::assertSame('user_role.needs_owner_confirmation', $outcomes['composed with X(admin) grants finance'],
            'M2b: a seat holding an X(admin) code is not thereby an owner.');
        self::assertContains(201, $outcomes, 'Anti-vacuity: nothing was admitted.');
        self::assertContains('user_role.needs_owner_confirmation', $outcomes, 'Anti-vacuity: nothing was routed.');
        self::assertSame($expected, $outcomes);
    }

    /**
     * **Roles slice 3 C2 — the routing refusal names the proposal route, and is TRUE** (`UX` #28; design §5 change 1, F-8).
     * `admin` granting `finance` to a fresh user is refused `needs_owner_confirmation` with #28's literal sentence — the
     * first wire assertion of this sentence (the slice-2 entry in `COPY` was never asserted by any test) — and the proposal
     * route it names then accepts the same grant (201). Mutation: the slice-2 sentence back → red on the literal.
     */
    #[Test]
    public function aGrantNeedingConfirmationNamesTheProposalRouteAndThatRouteAcceptsIt(): void
    {
        $finance = $this->systemRole(DefaultRoles::FINANCE);
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));

        $this->grant($subject, $finance);
        $this->assertRefused('user_role.needs_owner_confirmation');
        self::assertSame(RoleAssignment::NEEDS_OWNER_CONFIRMATION, $this->json()['detail'] ?? null);
        $members = array_keys($this->json());
        sort($members);
        self::assertSame(['detail', 'message', 'rule', 'status', 'title', 'type'], $members, 'The body grew a member: §5.2 fixes rule + message (D-2b).');
        self::assertSame(0, $this->grantsOf($subject));

        $this->send('POST', '/users/'.$subject.'/role-grant-proposals', ['role_id' => $finance], $this->key());
        self::assertSame(201, $this->httpStatus(), 'The route the sentence names refused the same grant: '.$this->client()->getResponse()->getContent());
    }

    /**
     * **E-15c:** a composed role whose codes are not a subset of the actor's is refused; inside the set it is granted. The
     * actor is a composed seat (for `admin` every code outside its set is `X(admin)`; since roles slice 3 C2 routing runs
     * LAST, so E-15c now answers first there too — `GrantRoutingTest`). Mutation A1 (drop E-15c) → the outside grant 201,
     * red.
     */
    #[Test]
    public function aComposedRoleOutsideTheActorsSetCannotBeGranted(): void
    {
        $this->actAsUser(self::TENANT_A, $this->seedPrincipal(self::TENANT_A, ['role.assign', 'role.view']));
        $outside = $this->composedRole(['inventory_item.view']);
        $inside = $this->composedRole(['role.view']);
        $system = $this->systemRole(DefaultRoles::VIEWER);

        $this->grant($subject = $this->seedPrincipal(self::TENANT_A, []), $outside);
        $this->assertRefused('user_role.exceeds_actor_permissions');
        self::assertSame(0, $this->grantsOf($subject));

        $this->grant($subject, $inside);
        self::assertSame(201, $this->httpStatus(), 'Control: a composed role inside the set was refused.');
        $this->grant($subject, $system);
        self::assertSame(201, $this->httpStatus(), 'Control: E-15c judged a SYSTEM role (viewer holds codes this seat lacks).');
    }

    /** V2-R-6: a soft-deleted subject is refused 409 (on a grant the caller must learn why), with a live control. */
    #[Test]
    public function aSoftDeletedSubjectIsRefused(): void
    {
        $role = $this->composedRole(['role.view']);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $live = $this->seedPrincipal(self::TENANT_A, []);
        $deleted = $this->seedPrincipal(self::TENANT_A, []);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET deleted_at = now() WHERE id = ?::uuid', [$deleted]);

        $this->grant($deleted, $role);
        $this->assertRefused('user_role.subject_is_deleted');
        $this->grant($live, $role);
        self::assertSame(201, $this->httpStatus(), 'Control: the live twin was refused.');
    }

    /** V2-R-6: an inactive subject is refused 409 — a grant would go live on reactivation (E-15g from the other side). */
    #[Test]
    public function anInactiveSubjectIsRefused(): void
    {
        $role = $this->composedRole(['role.view']);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $inactive = $this->seedPrincipal(self::TENANT_A, []);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET is_active = false WHERE id = ?::uuid', [$inactive]);

        $this->grant($inactive, $role);
        $this->assertRefused('user_role.subject_is_inactive');
        self::assertSame(0, $this->grantsOf($inactive));
    }

    /**
     * V2-R-6 / ST-9: an archived role is refused **409 `role_is_archived`, never 422 `unknown_reference`** (`UX` §3.1 item 2:
     * the reference lookup must see archived rows). Live control in the same test.
     */
    #[Test]
    public function anArchivedRoleCannotBeAssigned(): void
    {
        $archived = $this->composedRole(['role.view']);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE role SET deleted_at = now() WHERE id = ?::uuid', [$archived]);
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));

        $this->grant($subject, $archived);
        $this->assertRefused('user_role.role_is_archived');
        $this->grant($subject, $this->composedRole(['role.view']));
        self::assertSame(201, $this->httpStatus(), 'Control: the live role was refused.');
    }

    /**
     * The not-found and field shapes. `{id}`: not a uuid, absent, another workspace's → the same 404. `role_id`: missing →
     * `required`; not a uuid → `not_a_uuid`; absent or another workspace's → `unknown_reference` (the `CreateSalesOrder`
     * body-reference precedent; ST-5). Pointer `/role_id`.
     */
    #[Test]
    public function theSubjectIs404AndTheRoleIdIsAFieldError(): void
    {
        $role = $this->composedRole(['role.view']);
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $otherUser = $this->seedPrincipal(self::TENANT_B, []);
        $otherRole = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_B, 'INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, ?)', [$otherRole, self::TENANT_B, 'Other tenant']);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));

        $bodies = [];
        foreach (['not-a-uuid', Uuid::v4()->toRfc4122(), $otherUser] as $user) {
            $this->grant($user, $role);
            self::assertSame(404, $this->httpStatus(), $user);
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertCount(1, array_unique($bodies), 'The three not-found shapes are distinguishable.');

        foreach ([
            'required' => null,
            'not_a_uuid' => 'role-1',
            'unknown_reference' => Uuid::v4()->toRfc4122(),
            'unknown_reference (another workspace\'s)' => $otherRole,
        ] as $case => $roleId) {
            $this->send('POST', '/users/'.$subject.'/roles', null === $roleId ? [] : ['role_id' => $roleId], $this->key());
            self::assertSame(422, $this->httpStatus(), $case.': '.$this->client()->getResponse()->getContent());
            self::assertSame([['pointer' => '/role_id', 'code' => explode(' ', $case)[0]]],
                array_map(static fn (array $e): array => ['pointer' => $e['pointer'], 'code' => $e['code']], $this->json()['errors']), $case);
        }
        self::assertSame('No role in this workspace has this id.', $this->json()['errors'][0]['message']);
        self::assertSame(0, $this->grantsOf($subject));
    }

    /**
     * **PC-13 / E-18, "new conflict only".** `sales_rep` held + `finance` granted by an owner → `separation_of_duty` naming
     * SoD-3, in `UX`'s sentence. Controls: `viewer` + `finance` → 201; `purchasing_manager` to a `buyer` → 201 (SoD-1 is
     * spanned by `purchasing_manager` alone — A2b); `viewer` to a seeded `sales_rep` + `finance` holder → 201 (a split
     * already spanned blocks no unrelated grant — A2).
     */
    #[Test]
    public function aGrantThatWouldSplitADutyIsRefusedNamingTheRow(): void
    {
        $finance = $this->systemRole(DefaultRoles::FINANCE);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::OWNER));

        $rep = $this->holderOf([$this->systemRole(DefaultRoles::SALES_REP)]);
        $this->grant($rep, $finance);
        $this->assertRefused('user_role.separation_of_duty', 'sales_rep + finance',
            'finance '.substr($finance, 0, 8).' can\'t be given to this person: with the roles they already have, one person could '
            .'issue invoices and also record their payment, void them or credit them (SoD-3). Choose another role or another person.');
        self::assertSame(1, $this->grantsOf($rep));

        $this->grant($this->holderOf([$this->systemRole(DefaultRoles::VIEWER)]), $finance);
        self::assertSame(201, $this->httpStatus(), 'viewer + finance');

        $this->grant($this->holderOf([$this->systemRole(DefaultRoles::BUYER)]), $this->systemRole(DefaultRoles::PURCHASING_MANAGER));
        self::assertSame(201, $this->httpStatus(), 'A2b: purchasing_manager to a buyer: '.$this->client()->getResponse()->getContent());

        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $conflicted = $this->holderOf([$this->systemRole(DefaultRoles::SALES_REP), $finance]);
        $this->grant($conflicted, $this->systemRole(DefaultRoles::VIEWER));
        self::assertSame(201, $this->httpStatus(), 'A2: viewer to a seeded sales_rep + finance holder: '.$this->client()->getResponse()->getContent());
    }

    /** Two rows at once: both clauses, joined, and both ids. */
    #[Test]
    public function aGrantSplittingTwoDutiesNamesBothRows(): void
    {
        self::assertSame('X can\'t be given to this person: with the roles they already have, one person could issue invoices and '
            .'also record their payment, void them or credit them and confirm sales orders or issue invoices and also ship the goods (SoD-3, SoD-4). '
            .'Choose another role or another person.', RoleAssignment::separationOfDutyMessage('X', ['SoD-3', 'SoD-4']));
    }

    /** `UX` §3.10's clause table is keyed by SoD row: every row has its sentence, and no sentence names a row that is gone. */
    #[Test]
    public function everySeparationOfDutyRowHasItsSentence(): void
    {
        self::assertSame(SeparationOfDuty::rowIds(), array_keys(RoleAssignment::SOD_CLAUSES),
            'A SoD row has no refusal sentence (or a sentence names no row). Add UX\'s clause for it in RoleAssignment::SOD_CLAUSES.');
        foreach (RoleAssignment::SOD_CLAUSES as $row => $clause) {
            self::assertDoesNotMatchRegularExpression('/[a-z_]+\.[a-z_]+/', $clause, $row.'\'s sentence names a permission code (ST-3).');
        }
    }

    /**
     * **H1:** a grant made through the API is inserted by the application's login; one seeded by the owner process is not.
     * Driven over HTTP — `RED` measured that reaching `erp_app` by `SET ROLE` from the owner records the owner's login.
     */
    #[Test]
    public function anApiGrantIsInsertedByTheAppLoginAndAnOwnerSeededGrantIsNot(): void
    {
        $role = $this->composedRole(['role.view']);
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $seeded = $this->seedPrincipal(self::TENANT_A, ['role.view']);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));

        $this->grant($subject, $role);
        self::assertSame(201, $this->httpStatus());

        self::assertSame('erp_app', $this->insertedBy($subject));
        self::assertSame((string) $this->owner()->fetchOne('SELECT session_user'), $this->insertedBy($seeded));
        self::assertNotSame('erp_app', $this->insertedBy($seeded), 'Control: the owner process reads as the application.');
    }

    /**
     * **G-91 / BK-11 (case C):** someone who already combines two duties on SoD-4 (`invoice.issue` through a composed role,
     * shipping through `warehouse_operator`, both seeded by owner SQL) cannot be given `sales_rep`, which adds a NEW pair on
     * that row (confirm × ship) — the shipped row rule alone admitted it, since the row was already spanned. **Case A in the
     * same test:** `sales_rep` to an `admin` + `finance` holder adds no new pair and is still 201 (the recorded narrowing,
     * G-91's open part; `app:roles:reconcile` arm H lists that person).
     */
    #[Test]
    public function someoneWhoAlreadyCombinesTwoDutiesCannotBeGivenANewCombination(): void
    {
        $salesRep = $this->systemRole(DefaultRoles::SALES_REP);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));

        $held = $this->holderOf([$this->composedRole(['invoice.issue']), $this->systemRole(DefaultRoles::WAREHOUSE_OPERATOR)]);
        $this->grant($held, $salesRep);
        $this->assertRefused('user_role.separation_of_duty', 'case C',
            'sales_rep '.substr($salesRep, 0, 8).' can\'t be given to this person: with the roles they already have, one person could '
            .'confirm sales orders or issue invoices and also ship the goods (SoD-4). Choose another role or another person.');
        self::assertSame(2, $this->grantsOf($held));

        $redundant = $this->holderOf([$this->systemRole(DefaultRoles::ADMIN), $this->systemRole(DefaultRoles::FINANCE)]);
        $this->grant($redundant, $salesRep);
        self::assertSame(201, $this->httpStatus(), 'Case A (adds nothing new on SoD-3): '.$this->client()->getResponse()->getContent());
    }

    // -----------------------------------------------------------------------

    private function grant(string $userId, string $roleId): void
    {
        $this->send('POST', '/users/'.$userId.'/roles', ['role_id' => $roleId], $this->key());
    }

    /** @return array<string, string> */
    private function key(): array
    {
        return [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()];
    }

    /** A fresh user of `TENANT_A` holding one composed role with `$role`'s default codes. */
    private function principal(string $role): string
    {
        return $this->seedPrincipal(self::TENANT_A, DefaultRoles::codesFor($role));
    }

    /** @param list<string> $codes */
    private function composedRole(array $codes): string
    {
        $role = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, ?)',
            [$role, self::TENANT_A, 'Composed '.substr($role, 0, 8)]);
        foreach ($codes as $code) {
            $this->insertAsOwnerForTenant(self::TENANT_A,
                'INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?)',
                [self::TENANT_A, $role, $code]);
        }

        return $role;
    }

    /** A system role holding `$name`'s default codes; named `<name> <id prefix>` so the SoD sentence can be pinned. */
    private function systemRole(string $name): string
    {
        $role = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO role (id, tenant_id, name, is_system) VALUES (?::uuid, ?::uuid, ?, true)',
            [$role, self::TENANT_A, $name.' '.substr($role, 0, 8)]);
        foreach (DefaultRoles::codesFor($name) as $code) {
            $this->insertAsOwnerForTenant(self::TENANT_A,
                'INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?)',
                [self::TENANT_A, $role, $code]);
        }

        return $role;
    }

    /**
     * A fresh user holding exactly `$roles` (seeded as the owner).
     *
     * @param list<string> $roles
     */
    private function holderOf(array $roles): string
    {
        $user = $this->seedPrincipal(self::TENANT_A, []);
        foreach ($roles as $role) {
            $this->insertAsOwnerForTenant(self::TENANT_A,
                'INSERT INTO user_role (id, tenant_id, user_id, role_id) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?::uuid)',
                [self::TENANT_A, $user, $role]);
        }

        return $user;
    }

    private function seedHolderOf(string $role): void
    {
        $this->holderOf([$role]);
    }

    private function grantsOf(string $userId): int
    {
        return $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM user_role WHERE user_id = ?::uuid', [$userId]);
    }

    private function roleRows(): int
    {
        return $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM audit_log WHERE event_type LIKE 'role.%'");
    }

    private function insertedBy(string $userId): string
    {
        return (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT inserted_by FROM user_role WHERE user_id = ?::uuid', [$userId])[0]['inserted_by'];
    }

    private function assertRefused(string $rule, string $case = '', ?string $detail = null): void
    {
        self::assertSame(409, $this->httpStatus(), $case.': '.$this->client()->getResponse()->getContent());
        self::assertSame($rule, $this->json()['rule'] ?? null, $case);
        self::assertSame($detail ?? self::COPY[$rule], $this->json()['detail'] ?? null, $case.': the refusal sentence is not UX\'s.');
    }
}

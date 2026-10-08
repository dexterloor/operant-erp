<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\UserManagement\Domain\LastOwnerFloor;
use App\SharedKernel\ValueObject\AuditEvent;
use App\SharedKernel\ValueObject\RoleEventMetadata;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`RevokeRole` — `DELETE /api/v1/users/{id}/roles/{roleId}`** (roles slice 2, C2; `docs/session13/roles-s2-design.md`
 * §5.1 as amended by `roles-s2-spar.md` §2 and `roles-s2-sec.md` §Acknowledgement).
 *
 * The behaviour, one rule per test: E-15a (nobody revokes their own role — which is also how the last owner is kept),
 * E-15b (only an owner revokes a role conferring the marker, archived or not), API-20 (`LastOwnerFloor`, scoped so a
 * workspace already without an active owner is not frozen), the 404s, the gate's 403, the inactive actor's 409 and its
 * order behind the permission re-check, and the one `role.revoked` row.
 *
 * **The concurrency half lives in `PurchaseOrderRowLockTest`** beside the other aggregates' lock evidence, because the
 * two-connection harness and the writer scan are private to that class: the positional lock-first test, the refusal
 * twins, `twoOwnersRevokingEachOtherLeaveExactlyOne`, and the demoted-actor tests that carry `SEC`'s S-2 ruling.
 *
 * Seeded as the owner connection (every principal gets its own role row holding the codes asked for), so each user's
 * "grant" below is exactly one `user_role` row.
 */
final class RoleRevokeTest extends ApiTestCase
{
    /**
     * The operations shipped under `/users`, joined into
     * `AuthOperationContractTest::theShippedSurfaceIsExactlyTheOperationsThisWaveHasPinned`'s union.
     *
     * @var array<string, array{method: string, path: string, idem: bool}>
     */
    public const PINNED_USER_ROLE_OPERATIONS = [
        'RevokeRole' => ['method' => 'DELETE', 'path' => '/users/{id}/roles/{roleId}', 'idem' => false],
        // Roles slice 2, C3. `idem`: §4.2's `unique:user_role_unique` — the header is demanded (428 without it).
        'AssignRole' => ['method' => 'POST', 'path' => '/users/{id}/roles', 'idem' => true],
        // Roles slice 3, C1. `idem`: §4.2's `unique:role_grant_proposal_pending_unique` — the header is demanded.
        'CreateRoleGrantProposal' => ['method' => 'POST', 'path' => '/users/{id}/role-grant-proposals', 'idem' => true],
        // Users slice 1, C3 (owner ruling Q-U2): the F-7 picker's data, a read under `role.assign`.
        'ListOfferedRoles' => ['method' => 'GET', 'path' => '/users/{id}/offered-roles', 'idem' => false],
    ];

    /**
     * `UX`'s refusal copy (`roles-s2-ux-copy.md` §2 / §3.11), verbatim, so a wording change is a visible edit.
     * `actor_is_inactive` is ONE string shared with `AssignRole` (`RoleAdministrationActor`). `last_owner`'s sentence
     * (*"This would leave the workspace with no owner. Make someone else an owner first."*) is a backstop no HTTP path
     * reaches (E-15a and E-15b fire first), so it has no row here.
     */
    private const COPY = [
        'user_role.actor_is_inactive' => 'Your account is not active, so you can\'t change anyone\'s roles.',
        'user_role.actor_is_subject' => 'Nobody can take a role away from themselves. Ask someone else who manages roles to do it.',
        'user_role.marker_needs_owner' => 'Only an owner can take this role away, because it makes the person an owner.',
    ];

    /** The gate's 403 for `role.assign`, byte for byte — the lock-time refusal must be indistinguishable from it. */
    public const ROLE_ASSIGN_DENIED = '{"required_permission":"role.assign","type":"urn:erp:problem:permission-denied","title":"Permission denied","status":403}';

    #[Test]
    public function theUserPathsDeclareExactlyThePinnedOperations(): void
    {
        $declared = [];
        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            // Users slice 1 C1: narrowed from every `/users` path to the `/users/{id}/…` family — `GetUser` (`/users/{id}`)
            // and `ListUsers` (`/users`) are `UserReadTest::PINNED_USER_OPERATIONS`'.
            if (1 === preg_match('#^/users/\{id\}/#', (string) $operation->getUriTemplate())) {
                $declared[$name] = ['method' => $operation->getMethod(), 'path' => $operation->getUriTemplate()];
            }
        }
        ksort($declared);
        $pinned = array_map(static fn (array $pin): array => ['method' => $pin['method'], 'path' => $pin['path']], self::PINNED_USER_ROLE_OPERATIONS);
        ksort($pinned);

        self::assertSame($pinned, $declared);
    }

    /** Positive control first: the subject can read roles; after the revoke it cannot. */
    #[Test]
    public function aRevokedRoleStopsConferringItsCodes(): void
    {
        $subject = $this->principal(DefaultRoles::VIEWER);
        $role = $this->roleOf($subject);

        $this->actAsUser(self::TENANT_A, $subject);
        $this->send('GET', '/roles');
        self::assertSame(200, $this->httpStatus(), 'Control: the viewer could not read roles before the revoke.');

        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $this->send('DELETE', '/users/'.$subject.'/roles/'.$role);
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('', (string) $this->client()->getResponse()->getContent(), 'A 204 carries no body (API-21 branch 3).');

        $this->actAsUser(self::TENANT_A, $subject);
        $this->send('GET', '/roles');
        self::assertSame(403, $this->httpStatus(), 'The revoked role still confers its codes.');
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM user_role WHERE user_id = ?::uuid', [$subject]));
    }

    /**
     * One `role.revoked`, cell by cell: the actor, the subject USER, the metadata key BY NAME (the constant the
     * reconciliation reads), the request's IP, `occurred_at` from the database clock after the lock. A refused revoke of
     * the same request shape writes no `role.*` row.
     */
    #[Test]
    public function aRevokeWritesExactlyOneRoleRevokedRow(): void
    {
        $subject = $this->principal(DefaultRoles::VIEWER);
        $role = $this->roleOf($subject);
        $admin = $this->principal(DefaultRoles::ADMIN);
        $this->actAsUser(self::TENANT_A, $admin);

        // The refused half first: the same request against a grant that does not exist.
        $this->send('DELETE', '/users/'.$subject.'/roles/'.Uuid::v4()->toRfc4122());
        self::assertSame(404, $this->httpStatus());
        self::assertSame(0, $this->roleRows(), 'A refused revoke wrote a role.* row.');

        $before = $this->dbNow();
        $this->send('DELETE', '/users/'.$subject.'/roles/'.$role);
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $rows = $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT event_type, actor_user_id::text AS actor, actor_label, subject_type, subject_id::text AS subject,
                   host(ip_address) AS ip, permission_code, metadata->>? AS role_id, (metadata - ?::text) = '{}'::jsonb AS only_key,
                   occurred_at >= ?::timestamptz AS after_start
              FROM audit_log WHERE event_type LIKE 'role.%'
            SQL, [RoleEventMetadata::ROLE_ID, RoleEventMetadata::ROLE_ID, $before]);
        self::assertSame([[
            'event_type' => AuditEvent::ROLE_REVOKED,
            'actor' => $admin,
            'actor_label' => 'Fixture Actor',
            'subject_type' => 'app_user',
            'subject' => $subject,
            'ip' => '127.0.0.1',
            'permission_code' => null,
            'role_id' => $role,
            'only_key' => true,
            'after_start' => true,
        ]], $rows);
        self::assertSame('role_id', RoleEventMetadata::ROLE_ID, 'The metadata key is pinned by name: the reconciliation reads it.');
    }

    /** E-15a, unconditionally — `owner` included, which is also API-20's promise for a sole owner. */
    #[Test]
    public function nobodyRevokesTheirOwnRoleAndTheLastOwnerIsKept(): void
    {
        foreach ([DefaultRoles::ADMIN, DefaultRoles::OWNER] as $seat) {
            $self = $this->principal($seat);
            $this->actAsUser(self::TENANT_A, $self);
            $this->send('DELETE', '/users/'.$self.'/roles/'.$this->roleOf($self));
            $this->assertRefused('user_role.actor_is_subject', $seat);
            self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM user_role WHERE user_id = ?::uuid', [$self]));
        }
        self::assertSame(0, $this->roleRows());
    }

    /** E-15b both ways: `admin` holds `role.assign` and not the marker; an owner holds both. */
    #[Test]
    public function onlyAnOwnerRevokesARoleConferringTheMarker(): void
    {
        $target = $this->principal(DefaultRoles::OWNER);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $this->send('DELETE', '/users/'.$target.'/roles/'.$this->roleOf($target));
        $this->assertRefused('user_role.marker_needs_owner', 'admin revoking an owner');

        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::OWNER));
        $this->send('DELETE', '/users/'.$target.'/roles/'.$this->roleOf($target));
        self::assertSame(204, $this->httpStatus(), 'A second owner could not revoke an owner: '.$this->client()->getResponse()->getContent());
    }

    /**
     * D-15 / R2-12: an ARCHIVED role that confers the marker is still owner-only to revoke — its stored codes are read,
     * because `RestoreRole` would make the grant live again.
     */
    #[Test]
    public function anArchivedMarkerRolesGrantIsRevokedOnlyByAnOwner(): void
    {
        $target = $this->principal(DefaultRoles::OWNER);
        $role = $this->roleOf($target);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE role SET deleted_at = now() WHERE id = ?::uuid', [$role]);
        // A live owner elsewhere, so the floor cannot be what refuses.
        $owner = $this->principal(DefaultRoles::OWNER);

        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $this->send('DELETE', '/users/'.$target.'/roles/'.$role);
        $this->assertRefused('user_role.marker_needs_owner', 'admin revoking an archived owner role');

        $this->actAsUser(self::TENANT_A, $owner);
        $this->send('DELETE', '/users/'.$target.'/roles/'.$role);
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /** API-20's other half: the floor is not a ban on revoking `owner`. */
    #[Test]
    public function aSecondOwnerCanBeRevoked(): void
    {
        $first = $this->principal(DefaultRoles::OWNER);
        $second = $this->principal(DefaultRoles::OWNER);
        $this->actAsUser(self::TENANT_A, $first);
        $this->send('DELETE', '/users/'.$second.'/roles/'.$this->roleOf($second));
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(1, $this->activeMarkerHolders());
    }

    /**
     * R2-1: SEC-O-19's state (the only owner is inactive — reachable today by owner-process writes) must not freeze
     * incident response: `admin` removes `viewer` from a third user → 204, and the workspace stays ownerless (recovery is
     * out of band). Mutation R9 (`after === 0` alone) turns this red with `last_owner`.
     */
    #[Test]
    public function aRevokeInATenantWithNoActiveOwnerIsNotBlocked(): void
    {
        $owner = $this->principal(DefaultRoles::OWNER);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET is_active = false WHERE id = ?::uuid', [$owner]);
        $subject = $this->principal(DefaultRoles::VIEWER);

        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $this->send('DELETE', '/users/'.$subject.'/roles/'.$this->roleOf($subject));
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(0, $this->activeMarkerHolders(), 'The fixture was meant to have no active owner.');
    }

    /** The pure floor, over `{0, ≥1}` × `{0, ≥1}` and the revoke-derived rows. */
    #[Test]
    public function theFloorRefusesExactlyTheActThatTakesTheLastActiveOwnerAway(): void
    {
        self::assertTrue(LastOwnerFloor::refuses(1, 0), 'the last active owner removed');
        self::assertTrue(LastOwnerFloor::refuses(3, 0), 'every active owner removed at once (bulk / E-15g)');
        self::assertFalse(LastOwnerFloor::refuses(2, 1), 'a second owner removed');
        self::assertFalse(LastOwnerFloor::refuses(1, 1), 'a non-marker role removed, or from a non-owner');
        self::assertFalse(LastOwnerFloor::refuses(0, 0), 'a workspace already without an active owner (R2-1)');
        self::assertFalse(LastOwnerFloor::refuses(0, 1), 'impossible for a revoke; never a refusal');
    }

    /** Not-found in every shape, byte-identical: absent grant, absent user, another workspace's, a soft-deleted user. */
    #[Test]
    public function anAbsentGrantAnotherTenantsUserOrADeletedUserAreTheSameNotFound(): void
    {
        $subject = $this->principal(DefaultRoles::VIEWER);
        $role = $this->roleOf($subject);
        $foreign = $this->seedPrincipal(self::TENANT_B, DefaultRoles::codesFor(DefaultRoles::VIEWER));
        $foreignRole = (string) $this->fetchAsOwnerForTenant(self::TENANT_B, 'SELECT role_id::text AS r FROM user_role WHERE user_id = ?::uuid', [$foreign])[0]['r'];
        $deleted = $this->principal(DefaultRoles::VIEWER);
        $deletedRole = $this->roleOf($deleted);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET deleted_at = now() WHERE id = ?::uuid', [$deleted]);

        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $bodies = [];
        foreach ([
            'absent grant' => '/users/'.$subject.'/roles/'.Uuid::v4()->toRfc4122(),
            'absent user' => '/users/'.Uuid::v4()->toRfc4122().'/roles/'.$role,
            'another workspace\'s grant' => '/users/'.$foreign.'/roles/'.$foreignRole,
            'a soft-deleted user' => '/users/'.$deleted.'/roles/'.$deletedRole,
            'not a uuid' => '/users/'.$subject.'/roles/not-a-uuid',
        ] as $case => $path) {
            $this->send('DELETE', $path);
            self::assertSame(404, $this->httpStatus(), $case);
            $bodies[$case] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertCount(1, array_unique($bodies), 'The not-found bodies differ: '.json_encode($bodies));
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_B, 'SELECT count(*) FROM user_role WHERE user_id = ?::uuid', [$foreign]));
        self::assertSame(0, $this->roleRows());
    }

    #[Test]
    public function anArchivedRolesGrantCanBeRevoked(): void
    {
        $subject = $this->principal(DefaultRoles::VIEWER);
        $role = $this->roleOf($subject);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE role SET deleted_at = now() WHERE id = ?::uuid', [$role]);

        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $this->send('DELETE', '/users/'.$subject.'/roles/'.$role);
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /** The gate's 403 and its denial row, which names the USER the path addresses (F-1 declaration). */
    #[Test]
    public function aCallerWithoutRoleAssignIsRefusedAtTheGate(): void
    {
        $subject = $this->principal(DefaultRoles::VIEWER);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::VIEWER));
        $this->send('DELETE', '/users/'.$subject.'/roles/'.$this->roleOf($subject));
        self::assertSame(403, $this->httpStatus());
        self::assertSame(self::ROLE_ASSIGN_DENIED, (string) $this->client()->getResponse()->getContent());
        self::assertSame([['subject_type' => 'app_user', 'subject_id' => $subject, 'permission_code' => 'role.assign']],
            $this->fetchAsOwnerForTenant(self::TENANT_A,
                'SELECT subject_type, subject_id::text AS subject_id, permission_code FROM audit_log WHERE event_type = ?',
                [AuditEvent::AUTHZ_PERMISSION_DENIED]));
    }

    /**
     * `SEC` §2.4: an inactive actor holding `role.assign` → 409 `actor_is_inactive`, no row. An inactive actor WITHOUT it →
     * the lock-time 403 and its row, which pins the order (the permission re-check comes first) — that half needs the code
     * lost between the gate and the lock, so it is a race: `PurchaseOrderRowLockTest::anInactiveActorDemotedWhileWaitingGetsThe403First`.
     * `IdentityReader` ignores `is_active` (API-O-22), so an inactive actor reaches the processor.
     */
    #[Test]
    public function anInactiveActorIsRefusedAfterThePermissionReCheck(): void
    {
        $subject = $this->principal(DefaultRoles::VIEWER);
        $role = $this->roleOf($subject);

        $admin = $this->principal(DefaultRoles::ADMIN);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET is_active = false WHERE id = ?::uuid', [$admin]);
        $this->actAsUser(self::TENANT_A, $admin);
        $this->send('DELETE', '/users/'.$subject.'/roles/'.$role);
        $this->assertRefused('user_role.actor_is_inactive', 'an inactive admin');
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM audit_log'));
    }

    /**
     * **`LiveGrantReader` is a second derivation of the set E-1 enforces, so it is cross-pinned** (design §4.2): a user
     * holding three roles, one ARCHIVED — the union of `rolesHeldBy()` equals `IdentityReader::read()`'s codes and `/me`'s
     * `permission_codes`, and the archived role's code is in none of them. Control: the archived role's code is real.
     */
    #[Test]
    public function theLockTimeReaderAgreesWithTheRequestTimeReaders(): void
    {
        $user = $this->seedPrincipal(self::TENANT_A, ['role.view', 'inventory_item.view']);
        foreach ([['purchase_order.view', null], ['sales_order.view', 'now()']] as [$code, $archived]) {
            $role = Uuid::v4()->toRfc4122();
            $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO role (id, tenant_id, name, deleted_at) VALUES (?::uuid, ?::uuid, ?, '.($archived ?? 'NULL').')', [$role, self::TENANT_A, 'Extra '.$code]);
            $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?)', [self::TENANT_A, $role, $code]);
            $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO user_role (id, tenant_id, user_id, role_id) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?::uuid)', [self::TENANT_A, $user, $role]);
        }

        $tenants = self::getContainer()->get(\App\Platform\Tenancy\TenantContext::class);
        \assert($tenants instanceof \App\Platform\Tenancy\TenantContext);
        $roles = $tenants->run(self::TENANT_A, static fn (\Doctrine\ORM\EntityManagerInterface $em): array => \App\Modules\UserManagement\Application\LiveGrantReader::rolesHeldBy($em, $user));
        $union = array_values(array_unique(array_merge([], ...array_column($roles, 'codes'))));
        sort($union);
        self::assertCount(2, $roles, 'The archived role was read as held.');

        $identity = self::getContainer()->get(\App\Platform\Security\IdentityReader::class);
        \assert($identity instanceof \App\Platform\Security\IdentityReader);
        self::assertSame($union, $identity->read(self::TENANT_A, $user)?->permissionCodes);

        $this->actAsUser(self::TENANT_A, $user);
        $this->send('GET', '/me');
        $me = $this->json()['permission_codes'];
        sort($me);
        self::assertSame($union, $me);
        self::assertSame(['inventory_item.view', 'purchase_order.view', 'role.view'], $union);
        self::assertNotContains('sales_order.view', $union, 'Control: the archived role\'s code leaked into the live set.');
    }

    // -----------------------------------------------------------------------

    /** A fresh user of `TENANT_A` holding one role row with `$role`'s default codes. */
    private function principal(string $role): string
    {
        return $this->seedPrincipal(self::TENANT_A, DefaultRoles::codesFor($role));
    }

    private function roleOf(string $userId): string
    {
        $rows = $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT role_id::text AS r FROM user_role WHERE user_id = ?::uuid', [$userId]);
        self::assertCount(1, $rows, 'Fixture: the principal holds more than one role.');

        return (string) $rows[0]['r'];
    }

    private function roleRows(): int
    {
        return $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM audit_log WHERE event_type LIKE 'role.%'");
    }

    private function activeMarkerHolders(): int
    {
        return $this->countAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT count(DISTINCT u.id) FROM app_user u
              JOIN user_role ur ON ur.user_id = u.id
              JOIN role r ON r.id = ur.role_id AND r.deleted_at IS NULL
              JOIN role_permission rp ON rp.role_id = r.id
             WHERE u.deleted_at IS NULL AND u.is_active AND rp.permission_code = 'tenant_settings.edit'
            SQL);
    }

    private function dbNow(): string
    {
        return (string) $this->owner()->fetchOne('SELECT now()::text');
    }

    private function assertRefused(string $rule, string $case): void
    {
        self::assertSame(409, $this->httpStatus(), $case.': '.$this->client()->getResponse()->getContent());
        self::assertSame($rule, $this->json()['rule'] ?? null, $case);
        self::assertSame(self::COPY[$rule], $this->json()['detail'] ?? null, $case.': the refusal sentence is not UX\'s.');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Domain\DefaultRoles;
use App\SharedKernel\ValueObject\AuditEvent;
use App\SharedKernel\ValueObject\RoleEventMetadata;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`DeleteUser` — `DELETE /api/v1/users/{id}`, `app_user.delete`** (G4-C2; `docs/session13/g4-users-design.md` §6, Revision 1
 * FINAL; `g4-users-final-red.md` L-2; E-15g). One rule per test, each able to go red:
 *  - T-2-1 a delete strips every grant (archived role too), lapses every pending proposal for and by the person, stamps, and
 *    leaves `is_active` alone (D-6); 204, no body; `GetUser` then returns the person with `archived_at` set (measured: the
 *    design's "GetUser gives 404" was an inference — `GetUser` reads archived or not, `RoleReader::get()`'s precedent);
 *  - T-2-3 E-15a (nobody deletes themselves, owners included), E-15b (an admin cannot delete an owner), API-20's floor is a
 *    no-op for an inactive person (who was never counted);
 *  - T-2-4 a repeat is 409 `app_user.is_archived` (`Idem = no`);
 *  - T-2-5 the strip is what lets `DeleteRole` stop counting a deleted person (the proposal tripwire's own sentence);
 *  - the 404s and the gate's 403.
 * T-2-2 (the token and the password stop) is `App\Tests\Auth\UserSessionStampTest`; T-2-6 (lock first) is
 * `PurchaseOrderRowLockTest`'s positional case 'DeleteUser'.
 */
final class UserDeleteTest extends ApiTestCase
{
    private const COPY = [
        'app_user.is_archived' => 'This person\'s account has been deleted, so it can\'t be changed.',
        'app_user.actor_is_subject' => 'Nobody can switch off their own account. Ask someone else who manages people to do it.',
        'app_user.marker_needs_owner' => 'Only an owner can do this, because this person holds a role that makes them an owner.',
    ];

    /** **T-2-1.** Mutations M-2-1 (no strip → T-2-5 and here) and M-2-3 (a hard delete → 42501, 500) → red. */
    #[Test]
    public function aDeleteStripsLapsesAndStampsAndLeavesIsActiveAlone(): void
    {
        $subject = $this->principal(DefaultRoles::VIEWER);
        $viewerRole = $this->onlyRoleOf($subject);
        $archived = $this->composedRole(['inventory_item.view']);
        $this->grant($subject, $archived);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE role SET deleted_at = now() WHERE id = ?::uuid', [$archived]);
        $proposer = $this->principal(DefaultRoles::ADMIN);
        $for = $this->proposal($subject, $this->composedRole(['warehouse.view']), $proposer);
        $by = $this->proposal($this->principal(DefaultRoles::VIEWER), $this->composedRole(['customer.view']), $subject);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));

        $this->send('DELETE', '/users/'.$subject);

        self::assertSame([204, ''], [$this->httpStatus(), (string) $this->client()->getResponse()->getContent()]);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM user_role WHERE user_id = ?::uuid', [$subject]));
        $row = $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT is_active, deleted_at IS NOT NULL AS deleted, sessions_valid_after IS NOT NULL AS stamped, date_trunc(\'second\', deleted_at) = sessions_valid_after AS same_instant FROM app_user WHERE id = ?::uuid',
            [$subject])[0];
        self::assertSame(['is_active' => true, 'deleted' => true, 'stamped' => true, 'same_instant' => true], $row, 'A delete must set deleted_at and the stamp from one $at, and leave is_active alone (D-6).');

        $expected = [$viewerRole, $archived];
        sort($expected);
        self::assertSame(array_map(static fn (string $role): array => [RoleEventMetadata::ROLE_ID => $role, RoleEventMetadata::REVOKE_REASON => RoleEventMetadata::REVOKE_REASON_SUBJECT_REMOVED], $expected),
            $this->metadataOf(AuditEvent::ROLE_REVOKED, $subject));
        self::assertSame([[]], $this->metadataOf(AuditEvent::USER_DELETED, $subject));
        self::assertSame([AuditEvent::USER_DELETED, AuditEvent::ROLE_REVOKED, AuditEvent::ROLE_REVOKED, AuditEvent::ROLE_ASSIGNMENT_LAPSED],
            array_column($this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT event_type FROM audit_log WHERE subject_id = ?::uuid ORDER BY occurred_at, id', [$subject]), 'event_type'),
            'The cause row (user.deleted) is not first, or a strip/lapse row is missing.');
        self::assertSame([['lapsed', 'subject_removed'], ['lapsed', 'proposer_removed']], [
            array_values($this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT status, lapse_reason FROM role_grant_proposal WHERE id = ?::uuid', [$for])[0]),
            array_values($this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT status, lapse_reason FROM role_grant_proposal WHERE id = ?::uuid', [$by])[0]),
        ]);

        $this->send('GET', '/users/'.$subject);
        self::assertSame(200, $this->httpStatus(), 'GetUser reads a deleted person (archived or not).');
        self::assertNotNull($this->json()['archived_at'] ?? null);
        self::assertSame([], $this->json()['roles'] ?? null);
    }

    /** **T-2-3** E-15a, owners included (M: no E-15a → red); E-15b (M: no E-15b → red); an owner may delete another owner. */
    #[Test]
    public function nobodyDeletesThemselvesAndOnlyAnOwnerDeletesAnOwner(): void
    {
        $owner = $this->principal(DefaultRoles::OWNER);
        $other = $this->principal(DefaultRoles::OWNER);
        $this->actAsUser(self::TENANT_A, $owner);
        $this->send('DELETE', '/users/'.$owner);
        $this->assertRefused('app_user.actor_is_subject', 'an owner deleting themselves');

        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $this->send('DELETE', '/users/'.$other);
        $this->assertRefused('app_user.marker_needs_owner', 'an admin deleting an owner');
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM app_user WHERE deleted_at IS NOT NULL'));
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM audit_log WHERE event_type LIKE 'user.%' OR event_type LIKE 'role.%'"));

        $this->actAsUser(self::TENANT_A, $owner);
        $this->send('DELETE', '/users/'.$other);
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /**
     * API-20 on a delete: an INACTIVE owner was never an active marker holder, so deleting them leaves the count unchanged and
     * the floor says nothing — even when the only active owner is the actor. (The floor's refusing half is unreachable over
     * HTTP in a correct build, as for `UpdateUser`: G4-C1 deviation D-1; `UserUpdateTest::theLastOwnerIsNeverSwitchedOff`
     * drives it directly.)
     */
    #[Test]
    public function theFloorIsANoOpForAnInactivePerson(): void
    {
        $actor = $this->principal(DefaultRoles::OWNER);
        $inactive = $this->principal(DefaultRoles::OWNER);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET is_active = false WHERE id = ?::uuid', [$inactive]);
        $this->actAsUser(self::TENANT_A, $actor);

        $this->send('DELETE', '/users/'.$inactive);
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM audit_log WHERE event_type = ?', [AuditEvent::ROLE_REVOKED]));
    }

    /** **T-2-4** a repeat meets a deleted person: 409 `app_user.is_archived`, nothing written. */
    #[Test]
    public function aRepeatedDeleteIsRefusedAsArchived(): void
    {
        $subject = $this->principal(DefaultRoles::VIEWER);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $this->send('DELETE', '/users/'.$subject);
        self::assertSame(204, $this->httpStatus());

        $this->send('DELETE', '/users/'.$subject);
        $this->assertRefused('app_user.is_archived', 'a repeat');
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM audit_log WHERE event_type = ?', [AuditEvent::USER_DELETED]));
    }

    /**
     * **T-2-5** `LiveGrantReader::holderRowCount` counts any `user_role` row, deleted people included (fail closed, `SEC` D-7.4);
     * the strip is what lets `DeleteRole` of the person's former role succeed. Control: before the delete it is refused.
     */
    #[Test]
    public function aDeletedPersonsFormerRoleCanBeDeleted(): void
    {
        $subject = $this->principal(DefaultRoles::VIEWER);
        $role = $this->composedRole(['inventory_item.view']);
        $this->grant($subject, $role);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));

        $this->send('DELETE', '/roles/'.$role);
        self::assertSame([409, 'role.has_holders'], [$this->httpStatus(), $this->json()['rule'] ?? null], 'Control: the held role was deletable.');

        $this->send('DELETE', '/users/'.$subject);
        self::assertSame(204, $this->httpStatus());
        $this->send('DELETE', '/roles/'.$role);
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /** The 404s (non-uuid, absent, another workspace's — byte-equal) and the gate's 403. */
    #[Test]
    public function theRefusalsBeforeAnyDecision(): void
    {
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $bodies = [];
        foreach (['not-a-uuid', Uuid::v4()->toRfc4122(), $this->seedPrincipal(self::TENANT_B, [])] as $id) {
            $this->send('DELETE', '/users/'.$id);
            self::assertSame(404, $this->httpStatus(), $id);
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertCount(1, array_unique($bodies));

        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::VIEWER));
        $this->send('DELETE', '/users/'.$this->principal(DefaultRoles::VIEWER));
        self::assertSame([403, 'app_user.delete'], [$this->httpStatus(), $this->json()['required_permission'] ?? null]);
    }

    // ---------------------------------------------------------------------

    private function principal(string $role): string
    {
        return $this->seedPrincipal(self::TENANT_A, DefaultRoles::codesFor($role));
    }

    private function onlyRoleOf(string $userId): string
    {
        return (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT role_id::text AS r FROM user_role WHERE user_id = ?::uuid', [$userId])[0]['r'];
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

    private function grant(string $userId, string $roleId): void
    {
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'INSERT INTO user_role (id, tenant_id, user_id, role_id) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?::uuid)',
            [self::TENANT_A, $userId, $roleId]);
    }

    private function proposal(string $userId, string $roleId, string $proposerId): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO role_grant_proposal (id, tenant_id, user_id, role_id, permission_codes, proposed_by_user_id, proposed_at, expires_at)
            SELECT ?::uuid, ?::uuid, ?::uuid, ?::uuid,
                   (SELECT jsonb_agg(rp.permission_code ORDER BY rp.permission_code) FROM role_permission rp WHERE rp.role_id = ?::uuid),
                   ?::uuid, c.t, c.t + interval '336 hours'
              FROM (SELECT clock_timestamp() AS t) c
            SQL, [$id, self::TENANT_A, $userId, $roleId, $roleId, $proposerId]);

        return $id;
    }

    /** @return list<array<string, mixed>> */
    private function metadataOf(string $event, string $subject): array
    {
        return array_map(static fn (array $row): array => json_decode((string) $row['m'], true, 512, \JSON_THROW_ON_ERROR),
            $this->fetchAsOwnerForTenant(self::TENANT_A, "SELECT metadata::text AS m FROM audit_log WHERE event_type = ? AND subject_id = ?::uuid ORDER BY metadata->>'role_id'", [$event, $subject]));
    }

    private function assertRefused(string $rule, string $case): void
    {
        self::assertSame([409, $rule, self::COPY[$rule]], [$this->httpStatus(), $this->json()['rule'] ?? null, $this->json()['detail'] ?? null],
            $case.': '.$this->client()->getResponse()->getContent());
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Application\UserRestoration;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\UserManagement\Infrastructure\Entity\AppUser;
use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\ValueObject\AuditEvent;
use App\SharedKernel\ValueObject\RoleEventMetadata;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`RestoreUser` — `POST /api/v1/users/{id}/restoration`, `app_user.delete`** (G4-C3; `docs/session13/g4-users-design.md` §7,
 * Revision 1 FINAL R1.2 G4-C3; `g4-users-final-red.md` M-1, L-2). One rule per test, each able to go red:
 *  - T-3-1 a restore brings the person back as they were (active if they were, D-6), with no roles, writing `user.restored`;
 *  - T-3-3 a live person now holding the email → 409 `app_user.email_taken`, nothing changes (pre-check; the `23505` backstop
 *    is shown by mutation M-3-2a);
 *  - T-3-4 / L-7 a leftover grant (a database edit) is stripped and recorded, and leftover pending proposals for and by the
 *    person lapse;
 *  - T-3-7 E-15b binds that strip: an admin cannot restore someone still holding an owner role; an owner can;
 *  - T-3-8 (final RED M-1) the person already managed in the entity manager is loaded with the soft-delete filter off and
 *    restored — driven through the service on one EM (G4-C1 D-4: the identity map does not survive between test requests);
 *  - the refusals (404s, a live person's 409 `app_user.is_not_archived`, the gate's 403).
 * The token and password half (T-3-2, T-3-5) is `App\Tests\Auth\UserSessionStampTest`; T-3-6 (lock first) is the positional
 * case 'RestoreUser'; the replay guard is `IdempotencyTest::replayRestoreUser`.
 */
final class UserRestoreTest extends ApiTestCase
{
    private const COPY = [
        'app_user.is_not_archived' => 'This person\'s account hasn\'t been deleted, so there is nothing to restore.',
        'app_user.email_taken' => 'Someone else in this workspace now uses this person\'s email address, so the account can\'t be restored.',
        'app_user.marker_needs_owner' => 'Only an owner can do this, because this person holds a role that makes them an owner.',
    ];

    /** **T-3-1.** Deleted through the API, restored: active, no roles, `user.restored` (`{}`); an inactive person stays inactive. */
    #[Test]
    public function aRestoreBringsThePersonBackAsTheyWereWithNoRoles(): void
    {
        $subject = $this->principal(DefaultRoles::VIEWER);
        $inactive = $this->principal(DefaultRoles::VIEWER);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET is_active = false WHERE id = ?::uuid', [$inactive]);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        foreach ([$subject, $inactive] as $id) {
            $this->send('DELETE', '/users/'.$id);
            self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        }

        $this->send('POST', '/users/'.$subject.'/restoration');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertTrue(\array_key_exists('archived_at', $this->json()), 'The body carries no archived_at.');
        self::assertSame([true, null, []], [$this->json()['is_active'] ?? null, $this->json()['archived_at'], $this->json()['roles'] ?? null]);
        self::assertSame(['{}'], array_column($this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT metadata::text AS m FROM audit_log WHERE event_type = ? AND subject_id = ?::uuid', [AuditEvent::USER_RESTORED, $subject]), 'm'));

        $this->send('POST', '/users/'.$inactive.'/restoration');
        self::assertSame([200, false], [$this->httpStatus(), $this->json()['is_active'] ?? null], 'A restore changed is_active (D-6).');
    }

    /**
     * **T-3-3** another LIVE person now uses the email (only SQL or seeding can do that today) → 409 `app_user.email_taken`, the
     * person stays deleted, nothing is written. Mutations M-3-2a (no pre-check → the translated `23505` backstop still answers
     * 409) and M-3-2b (neither → 500) prove both halves.
     */
    #[Test]
    public function aRestoreIsRefusedWhenSomeoneElseNowUsesTheEmail(): void
    {
        $subject = $this->principal(DefaultRoles::VIEWER);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET deleted_at = now() WHERE id = ?::uuid', [$subject]);
        $email = (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT email FROM app_user WHERE id = ?::uuid', [$subject])[0]['email'];
        $this->insertAsOwnerForTenant(self::TENANT_A,
            "INSERT INTO app_user (id, tenant_id, email, password_hash, display_name) VALUES (gen_random_uuid(), ?::uuid, ?, 'not-a-hash', 'Newcomer')",
            [self::TENANT_A, $email]);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));

        $this->send('POST', '/users/'.$subject.'/restoration');
        $this->assertRefused('app_user.email_taken', 'an email in use');
        self::assertTrue($this->isDeleted($subject));
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM audit_log WHERE event_type LIKE 'user.%' OR event_type LIKE 'role.%'"));
    }

    /**
     * **T-3-4 + L-7** a person deleted by SQL still holding a (non-owner) grant and two pending proposals (for them, by them):
     * the restore strips the grant (`role.revoked` with `revoke_reason`) and lapses both. Mutation M-3-3 (no legacy strip) → red.
     */
    #[Test]
    public function aRestoreStripsLeftoversAndLapsesLeftoverProposals(): void
    {
        $subject = $this->principal(DefaultRoles::VIEWER);
        $role = $this->onlyRoleOf($subject);
        $proposer = $this->principal(DefaultRoles::ADMIN);
        $for = $this->proposal($subject, $this->composedRole(['warehouse.view']), $proposer);
        $by = $this->proposal($this->principal(DefaultRoles::VIEWER), $this->composedRole(['customer.view']), $subject);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET deleted_at = now() WHERE id = ?::uuid', [$subject]);
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));

        $this->send('POST', '/users/'.$subject.'/restoration');
        self::assertSame([200, []], [$this->httpStatus(), $this->json()['roles'] ?? null], (string) $this->client()->getResponse()->getContent());
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM user_role WHERE user_id = ?::uuid', [$subject]));
        self::assertSame([[RoleEventMetadata::ROLE_ID => $role, RoleEventMetadata::REVOKE_REASON => RoleEventMetadata::REVOKE_REASON_SUBJECT_REMOVED]],
            $this->metadataOf(AuditEvent::ROLE_REVOKED));
        self::assertSame([['lapsed', 'subject_removed'], ['lapsed', 'proposer_removed']], [
            array_values($this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT status, lapse_reason FROM role_grant_proposal WHERE id = ?::uuid', [$for])[0]),
            array_values($this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT status, lapse_reason FROM role_grant_proposal WHERE id = ?::uuid', [$by])[0]),
        ]);
        self::assertSame(AuditEvent::USER_RESTORED, $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT event_type FROM audit_log WHERE subject_id = ?::uuid ORDER BY occurred_at, id LIMIT 1', [$subject])[0]['event_type'],
            'The cause row (user.restored) is not written first.');
    }

    /**
     * **T-3-7** (R1.2 C3 M-4, mirrors T-1-16): a deleted person still holding an owner role (a database edit). An admin's restore
     * would strip it — a revocation under E-15b — so it is refused and nothing changes; an owner's succeeds. Mutation M-3-5 (no
     * E-15b at restore) → red.
     */
    #[Test]
    public function onlyAnOwnerRestoresAPersonStillHoldingAnOwnerRole(): void
    {
        $leftover = $this->principal(DefaultRoles::OWNER);
        $this->principal(DefaultRoles::OWNER);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET deleted_at = now() WHERE id = ?::uuid', [$leftover]);

        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $this->send('POST', '/users/'.$leftover.'/restoration');
        $this->assertRefused('app_user.marker_needs_owner', 'admin restoring a leftover owner');
        self::assertTrue($this->isDeleted($leftover));
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM user_role WHERE user_id = ?::uuid', [$leftover]));

        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::OWNER));
        $this->send('POST', '/users/'.$leftover.'/restoration');
        self::assertSame([200, []], [$this->httpStatus(), $this->json()['roles'] ?? null], (string) $this->client()->getResponse()->getContent());
        self::assertFalse($this->isDeleted($leftover));
    }

    /**
     * **T-3-8** (final RED M-1): the person is already a MANAGED entity on the EM the restore runs on (hydrated, then soft-deleted
     * by owner SQL — so the instance is stale and the row is now hidden by `soft_delete`). The restore must turn the filter off
     * for its own load, refresh under the lock, and restore. Mutation M-3-4 (filter left on) → `refresh()` reads nothing, the
     * stale instance reports "not deleted", and the restore is refused — red.
     */
    #[Test]
    public function aPersonAlreadyLoadedIsRestored(): void
    {
        $subject = $this->principal(DefaultRoles::VIEWER);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'DELETE FROM user_role WHERE user_id = ?::uuid', [$subject]);
        $this->actAsUser(self::TENANT_A, $admin = $this->principal(DefaultRoles::ADMIN), DefaultRoles::codesFor(DefaultRoles::ADMIN));
        $tenants = self::getContainer()->get(TenantContext::class);
        \assert($tenants instanceof TenantContext);
        self::assertNotNull($tenants->run(self::TENANT_A, static fn (EntityManagerInterface $em): ?object => $em->find(AppUser::class, $subject)),
            'Control: the person was not hydrated, so this proves nothing.');
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET deleted_at = now() WHERE id = ?::uuid', [$subject]);

        $restoration = self::getContainer()->get(UserRestoration::class);
        \assert($restoration instanceof UserRestoration);
        $view = $restoration->restore($subject, '203.0.113.5', ['app_user', $subject]);

        self::assertNull($view->archivedAt);
        self::assertFalse($this->isDeleted($subject), 'The restore decided on a stale, filtered entity.');
        self::assertTrue($tenants->run(self::TENANT_A, static fn (EntityManagerInterface $em): bool => $em->getFilters()->isEnabled('soft_delete')),
            'The soft-delete filter was left off after the restore.');
        self::assertSame((string) $admin, (string) $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT actor_user_id::text AS a FROM audit_log WHERE event_type = ?', [AuditEvent::USER_RESTORED])[0]['a']);
    }

    /** The 404s (byte-equal), a live person's 409, the gate's 403. */
    #[Test]
    public function theRefusalsBeforeAnyDecision(): void
    {
        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::ADMIN));
        $bodies = [];
        foreach (['not-a-uuid', Uuid::v4()->toRfc4122(), $this->seedPrincipal(self::TENANT_B, [])] as $id) {
            $this->send('POST', '/users/'.$id.'/restoration');
            self::assertSame(404, $this->httpStatus(), $id);
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertCount(1, array_unique($bodies));

        $this->send('POST', '/users/'.$this->principal(DefaultRoles::VIEWER).'/restoration');
        $this->assertRefused('app_user.is_not_archived', 'a live person');

        $this->actAsUser(self::TENANT_A, $this->principal(DefaultRoles::VIEWER));
        $this->send('POST', '/users/'.$this->principal(DefaultRoles::VIEWER).'/restoration');
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

    private function isDeleted(string $userId): bool
    {
        return (bool) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT deleted_at IS NOT NULL AS d FROM app_user WHERE id = ?::uuid', [$userId])[0]['d'];
    }

    /** @return list<array<string, mixed>> */
    private function metadataOf(string $event): array
    {
        return array_map(static fn (array $row): array => json_decode((string) $row['m'], true, 512, \JSON_THROW_ON_ERROR),
            $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT metadata::text AS m FROM audit_log WHERE event_type = ?', [$event]));
    }

    private function assertRefused(string $rule, string $case): void
    {
        self::assertSame([409, $rule, self::COPY[$rule]], [$this->httpStatus(), $this->json()['rule'] ?? null, $this->json()['detail'] ?? null],
            $case.': '.$this->client()->getResponse()->getContent());
    }
}

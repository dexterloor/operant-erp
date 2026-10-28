<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\UserManagement\Domain\SealsIdentities;
use App\Platform\Tenancy\Entity\Tenant;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/**
 * **What SQL as `erp_app` can and cannot do with the U-C1b `INSERT` grant on `app_user`** (U-C1b; `cred-mail-user-design.md`
 * T-U5, `cred-mail-user-final-red.md` M-2; `RESIDUAL_GAPS.md` G-3, G-10, G-12). Every planted statement runs on a SEPARATE
 * `erp_app` client, in the victim workspace's context set by `set_config` (G-3's route) — the application principal, never
 * the owner.
 *
 *  - **T-U5** a stranger INSERTED by SQL, given `owner` by SQL: sign-in fails closed (no `password_mac` — not insertable) and
 *    the mail worker sends nothing, because nobody without the key can compute `identity_mac`.
 *  - **T-U5b** a FORMER member revived by SQL (`deleted_at` cleared, `is_active` set — G4's grants) and given `owner` by SQL
 *    CAN become owner through a reset link today: the seal binds `(tenant, id, email)`, not liveness. **This asserts the
 *    escalation SUCCEEDS** (owner notice N-3). *Red when erp_app loses user_role INSERT (G-10 closed); move it then, never
 *    hand-edit.* (Mutation MU-16, the revoke, run.)
 */
final class CrossTenantInvitationTakeoverTest extends AuthTestCase
{
    use ClockSensitiveTrait;

    private const SLUG = self::SLUG_PREFIX.'victim';

    private const ATTACKER = 'attacker@example.test';

    private const KNOWN = 'a password the attacker chose';

    /** **T-U5.** Mutation MU-3 (drop the worker's identity check) is the reset handler's T-M5 here: a mail would be sent. */
    #[Test]
    public function aUserInsertedBySqlNeverReceivesALink(): void
    {
        $tenant = $this->seedTenant(self::SLUG, Tenant::STATUS_ACTIVE);
        $owner = $this->ownerRole($tenant);
        $planted = Uuid::v4()->toRfc4122();

        $app = $this->secondClient();
        try {
            self::assertSame(1, $this->executeOnClientForTenant($app, $tenant,
                'INSERT INTO app_user (id, tenant_id, email, password_hash, display_name, identity_mac) VALUES (?::uuid, ?::uuid, ?, ?, ?, NULL)',
                [$planted, $tenant, self::ATTACKER, $this->hasher()->hash(self::KNOWN), 'Planted']),
                'Control: the U-C1b grant did not let erp_app insert a person, so this test proves nothing.');
            self::assertSame(1, $this->executeOnClientForTenant($app, $tenant,
                'INSERT INTO user_role (id, tenant_id, user_id, role_id) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid)',
                [Uuid::v4()->toRfc4122(), $tenant, $planted, $owner]));
        } finally {
            $app->close();
        }

        $this->client()->disableReboot();
        $this->client()->setServerParameter('REMOTE_ADDR', '203.0.113.7');
        $this->send('POST', '/auth/password-resets', ['workspace' => self::SLUG, 'email' => self::ATTACKER]);
        self::assertSame(202, $this->httpStatus());
        $this->drain();

        self::assertEmailCount(0);
        self::assertSame(0, $this->countAsOwnerForTenant($tenant, 'SELECT count(*) FROM password_reset_token'));
        self::assertSame(1, $this->countAsOwnerForTenant($tenant, 'SELECT count(*) FROM audit_log WHERE event_type = ?', [AuditEvent::AUTH_CREDENTIAL_IDENTITY_MISMATCH]));

        $this->signIn(self::SLUG, self::ATTACKER, self::KNOWN);
        self::assertSame(401, $this->httpStatus(), 'A person inserted by SQL with a known password signed in.');
        self::assertSame(1, $this->countAsOwnerForTenant($tenant, 'SELECT count(*) FROM audit_log WHERE event_type = ?', [AuditEvent::AUTH_LOGIN_CREDENTIAL_MISMATCH]));
    }

    /** **T-U5b** — the escalation, pinned as SUCCEEDING today (`cred-mail-user-final-red.md` M-2). */
    #[Test]
    public function aRevivedFormerMemberCanBecomeOwnerThroughAResetLink(): void
    {
        $tenant = $this->seedTenant(self::SLUG, Tenant::STATUS_ACTIVE);
        $owner = $this->ownerRole($tenant);
        $former = $this->seedUser($tenant, 'former@example.test', 'their old password');
        $this->executeAsOwnerForTenant($tenant, 'UPDATE app_user SET identity_mac = ? WHERE id = ?::uuid', [$this->seal()->sealIdentity($tenant, $former, 'former@example.test'), $former]);
        $this->executeAsOwnerForTenant($tenant, 'UPDATE app_user SET deleted_at = now() - interval \'1 day\', is_active = false WHERE id = ?::uuid', [$former]);

        $app = $this->secondClient();
        try {
            // 1. Revived by SQL as erp_app (G4's UPDATE (is_active, deleted_at)).
            self::assertSame(1, $this->executeOnClientForTenant($app, $tenant, 'UPDATE app_user SET deleted_at = NULL, is_active = true WHERE id = ?::uuid', [$former]));
            // 2. Given owner by SQL as erp_app — ASSERTED to succeed: when G-10 closes this is the 42501 that turns the test red.
            self::assertSame(1, $this->executeOnClientForTenant($app, $tenant,
                'INSERT INTO user_role (id, tenant_id, user_id, role_id) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid)',
                [Uuid::v4()->toRfc4122(), $tenant, $former, $owner]));
        } finally {
            $app->close();
        }

        // 3. Reset → drain → redeem → sign in. A mock clock behind real time, moved one second after the redeem: a sign-in in
        // the redeem's own second is refused once by the session floor (the accepted cost, `PasswordResetRedeemTest` T-R6).
        $clock = self::mockTime(new \DateTimeImmutable('@'.(time() - 10)));
        \assert($clock instanceof MockClock);
        $this->client()->disableReboot();
        $this->client()->setServerParameter('REMOTE_ADDR', '203.0.113.7');
        $this->send('POST', '/auth/password-resets', ['workspace' => self::SLUG, 'email' => 'former@example.test']);
        self::assertSame(202, $this->httpStatus());
        $this->drain();
        self::assertEmailCount(1);
        self::assertSame(1, preg_match('~/reset-password#token=([A-Za-z0-9_-]{86})\b~', (string) self::getMailerMessage(0)?->getTextBody(), $m));
        $this->send('POST', '/auth/password-resets/redemption', ['token' => $m[1], 'password' => 'a new password of the attacker']);
        self::assertSame(200, $this->httpStatus(), $this->responseContent());
        $clock->sleep(1);
        $this->signIn(self::SLUG, 'former@example.test', 'a new password of the attacker');
        self::assertSame(200, $this->httpStatus(), $this->responseContent());
        $token = (string) $this->json()['access_token'];

        // 4. The token's principal holds the owner marker.
        $this->send('GET', '/me', null, ['Authorization' => 'Bearer '.$token]);
        self::assertSame(200, $this->httpStatus(), $this->responseContent());
        self::assertContains('tenant_settings.edit', $this->json()['permission_codes'] ?? [], 'G-10 appears closed: the revived former member did not become owner. Move this pin deliberately.');
    }

    // ------------------------------------------------------------------------------------------------------------------

    /** The workspace's owner role (fixture, as the owner): the role the planted SQL grants. */
    private function ownerRole(string $tenant): string
    {
        $role = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant($tenant, 'INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, ?)', [$role, $tenant, 'Owner']);
        foreach (DefaultRoles::codesFor(DefaultRoles::OWNER) as $code) {
            $this->insertAsOwnerForTenant($tenant, 'INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (?::uuid, ?::uuid, ?::uuid, ?)',
                [Uuid::v4()->toRfc4122(), $tenant, $role, $code]);
        }

        return $role;
    }

    private function drain(): void
    {
        $async = self::getContainer()->get('messenger.transport.async');
        \assert($async instanceof InMemoryTransport);
        $bus = self::getContainer()->get('messenger.default_bus');
        \assert($bus instanceof MessageBusInterface);
        foreach ($async->get() as $envelope) {
            $bus->dispatch($envelope->with(new ReceivedStamp('async')));
            $async->ack($envelope);
        }
    }

    private function seal(): SealsIdentities
    {
        $seal = self::getContainer()->get('test.auth.identity_seal');
        \assert($seal instanceof SealsIdentities);

        return $seal;
    }
}

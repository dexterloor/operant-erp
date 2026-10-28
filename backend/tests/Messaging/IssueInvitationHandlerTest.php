<?php

declare(strict_types=1);

namespace App\Tests\Messaging;

use App\Modules\AuditLog\Infrastructure\PasswordResetAuditWriter;
use App\Modules\UserManagement\Application\Contract\Message\IssueInvitation;
use App\Modules\UserManagement\Domain\MessageId;
use App\Modules\UserManagement\Domain\SealsIdentities;
use App\Modules\UserManagement\Domain\UserId;
use App\Modules\UserManagement\Infrastructure\CredentialSeal;
use App\Modules\UserManagement\Infrastructure\Messaging\IssueInvitationHandler;
use App\Platform\Messaging\AllowlistSerializer;
use App\Platform\Messaging\MessageCatalogue;
use App\Platform\Tenancy\Entity\Tenant;
use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\ValueObject\AuditEvent;
use App\Tests\Auth\AuthTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;

/**
 * **The mail worker's handling of an invitation** (U-C1b; `docs/session13/u-c1-build-plan.md` Revision 2 FINAL R2.2): T-U8,
 * T-U8b and T-U5c's invitation half. Handled on the real `erp_worker` connection by a handler built with a `MockClock`;
 * the tenant is passed explicitly, as `TenantStatusGateMiddleware` passes the stamp's (final RED M-3). Mutations: MU-3 (drop
 * the identity check) → `aSealThatDoesNotMatchSendsNothing` red; MU-4 (drop `uncredentialed`) →
 * `noOneWithAPasswordAndNoOneSwitchedOffGetsOne` red; MU-15 (lifetime 1800) → `oneMailOnceWithASeventyTwoHourLink` red;
 * MU-17 (drop the per-account cap) → `aSecondInvitationForTheAccountSendsNothing` red.
 */
final class IssueInvitationHandlerTest extends AuthTestCase
{
    private const SLUG = self::SLUG_PREFIX.'invite';

    private const EMAIL = 'newcomer@example.test';

    /** **T-U8:** one mail, once, to the stored address; `expires_at − created_at` = 259200 exactly; a redelivery sends nothing. */
    #[Test]
    public function oneMailOnceWithASeventyTwoHourLink(): void
    {
        [$tenant, $user] = $this->invitee();
        $message = new IssueInvitation(new UserId($user), MessageId::new());
        $handler = $this->handler(new MockClock());

        $handler($message, $tenant);
        $handler($message, $tenant);

        self::assertEmailCount(1);
        $rows = $this->tokens($tenant);
        self::assertCount(1, $rows);
        self::assertSame(['invitation', 259200], [$rows[0]['purpose'], (int) $rows[0]['life']]);
        $issued = $this->trail($tenant, AuditEvent::AUTH_PASSWORD_RESET_ISSUED);
        self::assertCount(1, $issued);
        self::assertNull($issued[0]['ip_address']);
        $metadata = json_decode((string) $issued[0]['metadata'], true);
        self::assertSame(['invitation', 'invitation', $message->messageId->value], [$metadata['purpose'], $metadata['requested_by'], $metadata['message_id']]);
    }

    /**
     * **T-U8b (D-U7′):** a second invitation for the same account — a fresh `messageId` — sends nothing. *Red when a purge
     * deletes invitation rows (`RESIDUAL_GAPS.md` G-80 item 1) or a resend operation is built (owner Q-2 (b)); either must
     * replace this cap deliberately, never hand-edit it green.*
     */
    #[Test]
    public function aSecondInvitationForTheAccountSendsNothing(): void
    {
        [$tenant, $user] = $this->invitee();
        $handler = $this->handler(new MockClock());
        $handler(new IssueInvitation(new UserId($user), MessageId::new()), $tenant);
        $handler(new IssueInvitation(new UserId($user), MessageId::new()), $tenant);

        self::assertEmailCount(1);
        self::assertCount(1, $this->tokens($tenant));
    }

    /** **T-U8 (D-U7′):** three reset links today do not block an invitation, and the invitation spends no reset slot. */
    #[Test]
    public function resetsAndInvitationsAreCountedApart(): void
    {
        [$tenant, $user] = $this->invitee();
        for ($i = 0; $i < 3; ++$i) {
            $this->executeAsOwnerForTenant($tenant, <<<'SQL'
                INSERT INTO password_reset_token (id, tenant_id, user_id, purpose, selector, verifier_mac, message_id, created_at, expires_at)
                VALUES (?::uuid, ?::uuid, ?::uuid, 'reset', ?, ?, ?::uuid, now(), now() + interval '30 minutes')
                SQL, [Uuid::v4()->toRfc4122(), $tenant, $user, bin2hex(random_bytes(16)), 'v1.00000000.'.str_repeat('0', 64), Uuid::v4()->toRfc4122()]);
        }
        $this->handler(new MockClock())(new IssueInvitation(new UserId($user), MessageId::new()), $tenant);

        self::assertEmailCount(1);
        self::assertCount(1, array_filter($this->tokens($tenant), static fn (array $r): bool => 'invitation' === $r['purpose']));
    }

    /**
     * **T-U8:** nothing is sent, and nothing is minted, to: a person who already has a credential (MU-4), a person switched
     * off, a removed person, an id with no person, or a person in a workspace that stopped permitting sign-in after the gate.
     */
    #[Test]
    public function noOneWithAPasswordAndNoOneSwitchedOffGetsOne(): void
    {
        [$tenant] = $this->invitee();
        $credentialed = $this->seedUser($tenant, 'has-password@example.test', 'password-number-one');
        $this->sealIdentity($tenant, $credentialed, 'has-password@example.test');
        $inactive = $this->uncredentialed($tenant, 'inactive@example.test');
        $this->executeAsOwnerForTenant($tenant, 'UPDATE app_user SET is_active = false WHERE id = ?::uuid', [$inactive]);
        $removed = $this->uncredentialed($tenant, 'removed@example.test');
        $this->executeAsOwnerForTenant($tenant, 'UPDATE app_user SET deleted_at = now() WHERE id = ?::uuid', [$removed]);
        $handler = $this->handler(new MockClock());

        foreach ([$credentialed, $inactive, $removed, Uuid::v4()->toRfc4122()] as $user) {
            $handler(new IssueInvitation(new UserId($user), MessageId::new()), $tenant);
        }
        $suspended = $this->seedTenant(self::SLUG.'-suspended', Tenant::STATUS_SUSPENDED);
        $elsewhere = $this->uncredentialed($suspended, 'elsewhere@example.test');
        $handler(new IssueInvitation(new UserId($elsewhere), MessageId::new()), $suspended);

        self::assertEmailCount(0);
        self::assertSame([], $this->tokens($tenant));
        self::assertSame([], $this->tokens($suspended));
    }

    /** **T-U8 (MU-3):** a seal that no longer matches the stored address (rewritten by SQL) sends nothing and writes one row. */
    #[Test]
    public function aSealThatDoesNotMatchSendsNothing(): void
    {
        [$tenant, $user] = $this->invitee();
        $this->executeAsOwnerForTenant($tenant, "UPDATE app_user SET email = 'mallory@example.test' WHERE id = ?::uuid", [$user]);
        $this->handler(new MockClock())(new IssueInvitation(new UserId($user), MessageId::new()), $tenant);

        self::assertEmailCount(0);
        self::assertSame([], $this->tokens($tenant));
        $rows = $this->trail($tenant, AuditEvent::AUTH_CREDENTIAL_IDENTITY_MISMATCH);
        self::assertCount(1, $rows);
        $metadata = json_decode((string) $rows[0]['metadata'], true);
        self::assertSame('invitation', $metadata['requested_by'] ?? null, 'An invitation\'s seal note says someone else asked (final RED L-1).');
        self::assertArrayNotHasKey('unknown_key_id', $metadata);
    }

    /**
     * **T-U5c, invitation half:** a seal under ANOTHER key id is parked — one `{"unknown_key_id": true}` row naming
     * `requested_by: invitation`, COMMITTED; an operator log naming both key ids and never the address; no mail, no token.
     */
    #[Test]
    public function aSealUnderAnotherKeyIdIsParkedWithATrailRow(): void
    {
        [$tenant, $user] = $this->invitee();
        $foreign = new CredentialSeal(base64_encode(str_repeat('f', 32)));
        $this->executeAsOwnerForTenant($tenant, 'UPDATE app_user SET identity_mac = ? WHERE id = ?::uuid', [$foreign->sealIdentity($tenant, $user, self::EMAIL), $user]);
        $logger = new class extends AbstractLogger {
            /** @var list<array{message: string, context: array<mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };

        try {
            $this->handler(new MockClock(), $logger)(new IssueInvitation(new UserId($user), MessageId::new()), $tenant);
            self::fail('A foreign key id was acknowledged silently.');
        } catch (UnrecoverableMessageHandlingException) {
        }

        $logged = json_encode($logger->records, \JSON_THROW_ON_ERROR);
        self::assertStringContainsString($foreign->keyId(), $logged);
        self::assertStringContainsString($this->seal()->keyId(), $logged);
        self::assertStringNotContainsString('newcomer', $logged, 'The operator log carries the address.');
        self::assertEmailCount(0);
        self::assertSame([], $this->tokens($tenant));
        $rows = $this->trail($tenant, AuditEvent::AUTH_CREDENTIAL_IDENTITY_MISMATCH);
        self::assertCount(1, $rows);
        $metadata = json_decode((string) $rows[0]['metadata'], true);
        self::assertSame([true, 'invitation'], [$metadata['unknown_key_id'] ?? null, $metadata['requested_by'] ?? null]);
    }

    /** The message round-trips through `AllowlistSerializer`, ids only: no address in the queued body. */
    #[Test]
    public function theMessageCarriesIdsOnlyAndRoundTrips(): void
    {
        $serializer = new AllowlistSerializer(MessageCatalogue::ALL, new NullLogger());
        $message = new IssueInvitation(new UserId(Uuid::v4()->toRfc4122()), MessageId::new());
        $encoded = $serializer->encode(new Envelope($message));
        self::assertEquals($message, $serializer->decode($encoded)->getMessage());
        self::assertStringNotContainsString('@', (string) $encoded['body']);
        self::assertSame(['user', 'messageId'], array_map(static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(IssueInvitation::class, '__construct'))->getParameters()));
    }

    // ------------------------------------------------------------------------------------------------------------------

    /** @return array{0: string, 1: string} a workspace and an active, sealed person with no credential */
    private function invitee(): array
    {
        $tenant = $this->seedTenant(self::SLUG, Tenant::STATUS_ACTIVE);

        return [$tenant, $this->uncredentialed($tenant, self::EMAIL)];
    }

    private function uncredentialed(string $tenant, string $email): string
    {
        $user = $this->seedUser($tenant, $email, 'unused-password');
        $this->executeAsOwnerForTenant($tenant, 'UPDATE app_user SET password_mac = NULL WHERE id = ?::uuid', [$user]);
        $this->sealIdentity($tenant, $user, $email);

        return $user;
    }

    private function sealIdentity(string $tenant, string $user, string $email): void
    {
        $this->executeAsOwnerForTenant($tenant, 'UPDATE app_user SET identity_mac = ? WHERE id = ?::uuid', [$this->seal()->sealIdentity($tenant, $user, $email), $user]);
    }

    private function seal(): SealsIdentities
    {
        $seal = self::getContainer()->get('test.auth.identity_seal');
        \assert($seal instanceof SealsIdentities);

        return $seal;
    }

    private function handler(MockClock $clock, ?AbstractLogger $logger = null): IssueInvitationHandler
    {
        $container = self::getContainer();
        $worker = $container->get('test.doctrine.dbal.worker_connection');
        \assert($worker instanceof Connection);
        $tenants = $container->get(TenantContext::class);
        \assert($tenants instanceof TenantContext);
        $mailer = $container->get('mailer.transports');
        \assert($mailer instanceof TransportInterface);

        return new IssueInvitationHandler($worker, $tenants, $this->seal(), new PasswordResetAuditWriter($worker),
            $mailer, $clock, $logger ?? new NullLogger(), 'no-reply@erp.local', 'http://127.0.0.1:5173');
    }

    /** @return list<array<string, mixed>> */
    private function tokens(string $tenant): array
    {
        return $this->fetchAsOwnerForTenant($tenant, 'SELECT user_id, purpose, extract(epoch FROM expires_at - created_at)::int AS life FROM password_reset_token ORDER BY created_at');
    }

    /** @return list<array<string, mixed>> */
    private function trail(string $tenant, string $event): array
    {
        return $this->fetchAsOwnerForTenant($tenant, 'SELECT host(ip_address) AS ip_address, metadata FROM audit_log WHERE event_type = ?', [$event]);
    }
}

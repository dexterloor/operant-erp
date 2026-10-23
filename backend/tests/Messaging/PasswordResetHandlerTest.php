<?php

declare(strict_types=1);

namespace App\Tests\Messaging;

use App\Modules\AuditLog\Infrastructure\PasswordResetAuditWriter;
use App\Modules\UserManagement\Application\Contract\Message\PasswordResetRequested;
use App\Modules\UserManagement\Application\RoleAdministrationLock;
use App\Modules\UserManagement\Domain\ClientIp;
use App\Modules\UserManagement\Domain\EmailAddress;
use App\Modules\UserManagement\Domain\MessageId;
use App\Modules\UserManagement\Domain\SealsIdentities;
use App\Modules\UserManagement\Domain\WorkspaceSlug;
use App\Modules\UserManagement\Infrastructure\CredentialSeal;
use App\Modules\UserManagement\Infrastructure\Messaging\PasswordResetRequestedHandler;
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
use Symfony\Component\Mailer\Envelope as MailEnvelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Uid\Uuid;

/**
 * **The mail worker mails exactly the people it should, once, to the stored address** (Mail-C1, `mail-c1-build-plan.md`
 * Revision 1 FINAL R1.6, R1.10; design §4.2). Messages go through the real bus and the real `AllowlistSerializer`
 * (`in-memory://…?serialize=true`) and are handled on the real `erp_worker` connection; the mailer is `null://null`,
 * observed through `MailerAssertionsTrait`.
 *
 * Mutations (`docs/session13/mail-c1-progress.md`): MU-8 drop the identity check → T-M5/T-M5b red; MU-9 UnknownKey as
 * Mismatch → T-G75-4 red; MU-10 cap over the tenant → T-M6 red; MU-11 drop `password_reset_token_message_key` → T-M8′ red;
 * MU-12 send after COMMIT → T-M9 red; MU-13 drop the read-back → T-M12 red; MU-14 an unkeyed verifier → T-M4 red; MU-30 a
 * VO built differently → T-M14 red; MU-33 drop the advisory lock → T-M6b; MU-38 mail the raw request string → T-M4 red.
 */
final class PasswordResetHandlerTest extends AuthTestCase
{
    private const SLUG = self::SLUG_PREFIX.'mail-live';

    private const EMAIL = 'alice@example.test';

    /**
     * **T-M4 (L-6):** a request typed in mixed case with surrounding spaces mails the STORED address once; the link's token
     * decodes to (tenant, selector, verifier), and the stored `verifier_mac` is the keyed MAC of exactly those, with
     * purpose `reset` and the stored expiry; one `auth.password_reset.issued` with the request IP and no token.
     */
    #[Test]
    public function aLiveUserGetsOneMailWithAWorkingLink(): void
    {
        [$tenant, $user] = $this->liveUser();

        $this->request('  '.strtoupper(self::SLUG).' ', '  Alice@Example.TEST ');
        $this->drain();

        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame([self::EMAIL], array_map(static fn ($a): string => $a->getAddress(), $mail->getTo()), 'The mail did not go to the stored address.');
        self::assertSame(1, preg_match('~/reset-password#token=([A-Za-z0-9_-]{86})\b~', (string) $mail->getTextBody(), $m), 'No link in the mail.');
        $raw = base64_decode(strtr($m[1], '-_', '+/'), true);
        self::assertIsString($raw);
        self::assertSame(64, \strlen($raw));
        self::assertSame($tenant, Uuid::fromBinary(substr($raw, 0, 16))->toRfc4122());
        $selector = bin2hex(substr($raw, 16, 16));
        $verifier = bin2hex(substr($raw, 32, 32));

        $rows = $this->tokens($tenant);
        self::assertCount(1, $rows);
        self::assertSame($user, $rows[0]['user_id']);
        self::assertSame('reset', $rows[0]['purpose']);
        self::assertSame($selector, $rows[0]['selector']);
        self::assertNull($rows[0]['used_at']);
        $expires = (new \DateTimeImmutable((string) $rows[0]['expires_at']))->getTimestamp();
        self::assertSame(PasswordResetRequestedHandler::LINK_LIFETIME_SECONDS, $expires - (new \DateTimeImmutable((string) $rows[0]['created_at']))->getTimestamp());
        self::assertSame($this->seal()->macVerifier($tenant, $user, 'reset', $selector, $expires, $verifier), $rows[0]['verifier_mac'],
            'The stored verifier is not the keyed MAC of the mailed token: a row read by SQL would be a working link, or the link would not verify.');

        $trail = $this->trail($tenant);
        self::assertCount(1, $trail);
        self::assertSame(AuditEvent::AUTH_PASSWORD_RESET_ISSUED, $trail[0]['event_type']);
        self::assertSame($user, $trail[0]['actor_user_id']);
        self::assertSame(self::EMAIL, $trail[0]['actor_label']);
        self::assertSame('203.0.113.7', $trail[0]['ip_address']);
        self::assertNull($trail[0]['subject_type']);
        $metadata = json_decode((string) $trail[0]['metadata'], true);
        $keys = array_keys((array) $metadata);
        sort($keys);
        self::assertSame(['message_id', 'purpose', 'requested_by'], $keys);
        self::assertSame('anonymous', $metadata['requested_by'] ?? null);
        self::assertStringNotContainsString($verifier, (string) $trail[0]['metadata']);
        self::assertStringNotContainsString($selector, (string) $trail[0]['metadata']);
    }

    /**
     * **T-M5:** nobody else gets mail — an unknown workspace, an unknown address, a deleted user, a deactivated user, every
     * non-active workspace status (derived from `Tenant::STATUS_*`), a user with no identity seal, and one with a garbage
     * seal under the current key id. No mail and no token for any of them.
     */
    #[Test]
    public function nobodyElseGetsMail(): void
    {
        [$tenant] = $this->liveUser();
        $deleted = $this->seedUserWithId($tenant, Uuid::v4()->toRfc4122(), 'gone@example.test', 'password-number-two', true);
        $this->sealIdentity($tenant, $deleted, 'gone@example.test');
        $idle = $this->seedUser($tenant, 'idle@example.test', 'password-number-three', false);
        $this->sealIdentity($tenant, $idle, 'idle@example.test');
        $this->seedUser($tenant, 'unsealed@example.test', 'password-number-four');
        $garbled = $this->seedUser($tenant, 'garbled@example.test', 'password-number-five');
        $this->executeAsOwnerForTenant($tenant, 'UPDATE app_user SET identity_mac = ? WHERE id = ?::uuid', ['v1.'.$this->seal()->keyId().'.'.str_repeat('0', 64), $garbled]);

        $cases = [
            [self::SLUG_PREFIX.'mail-none', self::EMAIL],
            [self::SLUG, 'nobody@example.test'],
            [self::SLUG, 'gone@example.test'],
            [self::SLUG, 'idle@example.test'],
            [self::SLUG, 'unsealed@example.test'],
            [self::SLUG, 'garbled@example.test'],
        ];
        foreach ((new \ReflectionClass(Tenant::class))->getConstants() as $name => $status) {
            if (!str_starts_with($name, 'STATUS_') || Tenant::statusPermitsLogin((string) $status) || Tenant::STATUS_SENTINEL === $status) {
                continue;
            }
            $slug = self::SLUG_PREFIX.'mail-'.$status;
            $other = $this->seedTenant($slug, (string) $status);
            $id = $this->seedUser($other, self::EMAIL, 'password-number-six');
            $this->sealIdentity($other, $id, self::EMAIL);
            $cases[] = [$slug, self::EMAIL];
        }
        self::assertGreaterThan(8, \count($cases), 'The status walk found no non-active status.');

        foreach ($cases as [$workspace, $email]) {
            $this->request($workspace, $email);
        }
        $this->drain();

        self::assertEmailCount(0);
        self::assertSame(0, (int) $this->owner()->fetchOne('SELECT count(*) FROM password_reset_token'), 'A refused request minted a token.');
    }

    /**
     * **T-M5b:** a sealed row whose `email` was rewritten by SQL (as the owner — `erp_app` cannot write it since H0) gets no
     * mail at the new address, and the trail gains one `auth.credential.identity_mismatch`.
     */
    #[Test]
    public function aSealedRowWhoseEmailWasChangedBySqlGetsNoMail(): void
    {
        [$tenant, $user] = $this->liveUser();
        $this->executeAsOwnerForTenant($tenant, "UPDATE app_user SET email = 'attacker@example.test' WHERE id = ?::uuid", [$user]);

        $this->request(self::SLUG, 'attacker@example.test');
        $this->drain();

        self::assertEmailCount(0);
        self::assertSame([], $this->tokens($tenant));
        $trail = $this->trail($tenant);
        self::assertSame([AuditEvent::AUTH_CREDENTIAL_IDENTITY_MISMATCH], array_column($trail, 'event_type'));
        self::assertSame('203.0.113.7', $trail[0]['ip_address']);
    }

    /**
     * **T-M6:** the fourth link in 24 hours is not sent; at +24 h + 1 s it is; and two users in one workspace are capped
     * independently. Handled by a handler built with a `MockClock`.
     */
    #[Test]
    public function theFourthLinkInADayIsNotSent(): void
    {
        [$tenant, $alice] = $this->liveUser();
        $bob = $this->seedUser($tenant, 'bob@example.test', 'password-number-two');
        $this->sealIdentity($tenant, $bob, 'bob@example.test');
        $clock = new MockClock('2026-10-23 10:00:00');
        $handler = $this->handler($clock);

        for ($i = 0; $i < 4; ++$i) {
            $handler($this->message(self::SLUG, self::EMAIL));
            $clock->sleep(60);
        }
        self::assertSame(3, $this->tokenCount($tenant, $alice), 'The cap did not hold at three.');
        $handler($this->message(self::SLUG, 'bob@example.test'));
        self::assertSame(1, $this->tokenCount($tenant, $bob), 'One user\'s cap refused another user in the same workspace.');

        $clock->modify('2026-10-24 10:00:01');
        $handler($this->message(self::SLUG, self::EMAIL));
        self::assertSame(4, $this->tokenCount($tenant, $alice), 'The cap never reset after 24 hours.');
        self::assertEmailCount(5);
    }

    /**
     * **T-M6b (L-5):** the per-user advisory lock is taken BEFORE the cap count. A second worker holds the user's lock;
     * this worker, on its own connection with a short `lock_timeout`, must wait for it — and so fails rather than counting
     * and minting. Both connections are DIRECT (unpooled) logins as `erp_worker`: through PgBouncer the worker identity has
     * one server connection (`max_user_connections = 1`), which serialises two workers before the lock is ever reached, so
     * a pooled test could not interleave (that is also why the race is a production concern, not a local one). Also pins the
     * lock's class id apart from the role-administration lock's. Mutation MU-33 (drop the lock) → this worker mints → red.
     */
    #[Test]
    public function aSecondWorkerWaitsForTheUsersLockBeforeCounting(): void
    {
        self::assertNotSame(RoleAdministrationLock::CLASS_ID, PasswordResetRequestedHandler::CLASS_ID);
        [$tenant, $alice] = $this->liveUser();
        $clock = new MockClock('2026-10-23 10:00:00');
        $this->handler($clock)($this->message(self::SLUG, self::EMAIL));

        $holder = $this->freshUnpooledWorkerClient();
        $waiter = $this->freshUnpooledWorkerClient();
        self::assertSame('erp_worker', $holder->fetchOne('SELECT current_user'));
        $holder->beginTransaction();
        $holder->executeStatement('SELECT pg_advisory_xact_lock(?, hashtext(?::uuid::text))', [PasswordResetRequestedHandler::CLASS_ID, $alice]);
        $waiter->executeStatement("SET lock_timeout = '300ms'");
        try {
            $this->handler($clock, $waiter)($this->message(self::SLUG, self::EMAIL));
            self::fail('The second worker counted and minted while another held the user\'s lock: the cap can race.');
        } catch (\Doctrine\DBAL\Exception\DriverException $e) {
            self::assertSame('55P03', $e->getSQLState(), 'The second worker failed for a reason other than waiting for the lock.');
        } finally {
            $holder->rollBack();
            $holder->close();
            $waiter->close();
        }
        self::assertSame(1, $this->tokenCount($tenant, $alice));
    }

    /**
     * **T-M5c (M-3 depth):** a message whose address the VO should have refused — invalid UTF-8, built here by writing the
     * VO's property from inside its own scope, the seam a forged queue entry would need — raises SQLSTATE 22021 at the
     * lookup; the handler treats it as a silent refusal (acknowledged, not retried), sends nothing and logs no address.
     * *Measured at build:* a NUL does NOT raise here — `pdo_pgsql` truncates the bound value at the NUL (`length('alice\0@x')`
     * = 5) — so RED M-3's 22021 premise holds for invalid UTF-8, not for a NUL; the VO refuses both, so neither reaches here.
     */
    #[Test]
    public function aLookupDataErrorIsASilentRefusal(): void
    {
        [$tenant] = $this->liveUser();
        $email = (new \ReflectionClass(EmailAddress::class))->newInstanceWithoutConstructor();
        \Closure::bind(static function (EmailAddress $e): void {
            $e->value = "alice\xC3@example.test";
        }, null, EmailAddress::class)($email);
        $message = new PasswordResetRequested(WorkspaceSlug::fromInput(self::SLUG), $email, null, new \DateTimeImmutable('@'.time()), MessageId::new());
        $logger = $this->recordingLogger();

        $this->handler(new MockClock('2026-10-23 10:00:00'), null, null, $logger)($message);

        self::assertEmailCount(0);
        self::assertSame([], $this->tokens($tenant));
        self::assertCount(1, $logger->records);
        self::assertStringNotContainsString('alice', json_encode($logger->records, \JSON_THROW_ON_ERROR));
    }

    /** **T-M8′:** the same envelope handled twice (a lost acknowledgement) → one token, one mail, one audit row. */
    #[Test]
    public function aRetryAfterALostAcknowledgementMintsNothing(): void
    {
        [$tenant] = $this->liveUser();
        $message = $this->message(self::SLUG, self::EMAIL);
        $handler = $this->handler(new MockClock('2026-10-23 10:00:00'));

        $handler($message);
        $handler($message);

        self::assertCount(1, $this->tokens($tenant));
        self::assertCount(1, $this->trail($tenant));
        self::assertEmailCount(1);
    }

    /**
     * **T-M9:** a transport that fails once leaves no token and no audit row after the first attempt (the send is inside
     * the transaction), and exactly one of each after the retry.
     */
    #[Test]
    public function aMailFailureLeavesNoTokenAndIsRetried(): void
    {
        [$tenant] = $this->liveUser();
        $flaky = new class(self::getContainer()->get('mailer.transports')) implements TransportInterface {
            public int $failures = 1;

            public function __construct(private readonly TransportInterface $inner)
            {
            }

            public function send(RawMessage $message, ?MailEnvelope $envelope = null): ?SentMessage
            {
                if ($this->failures-- > 0) {
                    throw new TransportException('Connection could not be established with host "mailpit".');
                }

                return $this->inner->send($message, $envelope);
            }

            public function __toString(): string
            {
                return 'flaky://';
            }
        };
        $handler = $this->handler(new MockClock('2026-10-23 10:00:00'), null, $flaky);
        $message = $this->message(self::SLUG, self::EMAIL);

        try {
            $handler($message);
            self::fail('A failed send was swallowed: the bus would acknowledge a mail that never left.');
        } catch (TransportException) {
        }
        self::assertSame([], $this->tokens($tenant), 'A token survived a failed send: the send is after COMMIT.');
        self::assertSame([], $this->trail($tenant), 'An audit row survived a failed send.');

        $handler($message);
        self::assertCount(1, $this->tokens($tenant));
        self::assertCount(1, $this->trail($tenant));
        self::assertEmailCount(1);
    }

    /** **T-M12:** a tampered row writes one identity-mismatch row per user per UTC day — two requests one day, one more the next. */
    #[Test]
    public function aTamperedRowWritesOneMismatchRowPerDay(): void
    {
        [$tenant, $user] = $this->liveUser();
        $this->executeAsOwnerForTenant($tenant, "UPDATE app_user SET email = 'attacker@example.test' WHERE id = ?::uuid", [$user]);
        $clock = new MockClock('2026-10-23 10:00:00');
        $handler = $this->handler($clock);

        $handler($this->message(self::SLUG, 'attacker@example.test'));
        $clock->modify('2026-10-23 23:59:59');
        $handler($this->message(self::SLUG, 'attacker@example.test'));
        self::assertCount(1, $this->trail($tenant), 'The read-back did not hold the day to one row.');

        $clock->modify('2026-10-24 00:00:01');
        $handler($this->message(self::SLUG, 'attacker@example.test'));
        self::assertCount(2, $this->trail($tenant), 'A new UTC day wrote no row.');
        self::assertEmailCount(0);
    }

    /**
     * **T-G75-4:** an identity seal under ANOTHER key id (key A sealed it, the worker holds key B) is parked in `failed`,
     * not retried and not silently acknowledged; the operator log names both key ids and never the address; no mail, no
     * token. Driven through a real `Worker` with the container's retry and failure listeners.
     */
    #[Test]
    public function aForeignKidIsParkedNotDropped(): void
    {
        [$tenant, $user] = $this->liveUser();
        $foreign = new CredentialSeal(base64_encode(str_repeat('f', 32)));
        self::assertNotSame($foreign->keyId(), $this->seal()->keyId());
        $this->executeAsOwnerForTenant($tenant, 'UPDATE app_user SET identity_mac = ? WHERE id = ?::uuid', [$foreign->sealIdentity($tenant, $user, self::EMAIL), $user]);

        $logger = $this->recordingLogger();
        $handler = $this->handler(new MockClock('2026-10-23 10:00:00'), null, null, $logger);
        try {
            $handler($this->message(self::SLUG, self::EMAIL));
            self::fail('A foreign key id was acknowledged silently.');
        } catch (UnrecoverableMessageHandlingException) {
        }
        $logged = json_encode($logger->records, \JSON_THROW_ON_ERROR);
        self::assertStringContainsString($foreign->keyId(), $logged, 'The operator log does not name the stored key id.');
        self::assertStringContainsString($this->seal()->keyId(), $logged, 'The operator log does not name the configured key id.');
        self::assertStringNotContainsString('alice', $logged, 'The operator log carries the address.');

        // Through the real bus and worker: straight to `failed`, never retried.
        $this->request(self::SLUG, self::EMAIL);
        $async = $this->transport('async');
        $failed = $this->transport('failed');
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $dispatcher->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event): void {
            if ($event->isWorkerIdle()) {
                $event->getWorker()->stop();
            }
        });
        $timeout = ini_get('default_socket_timeout');
        $ignoreArgs = ini_get('zend.exception_ignore_args');
        try {
            (new Worker(['async' => $async], $this->bus(), $dispatcher))->run(['sleep' => 1000]);
        } finally {
            ini_set('default_socket_timeout', (string) $timeout);
            ini_set('zend.exception_ignore_args', (string) $ignoreArgs);
        }
        self::assertCount(1, $failed->getSent(), 'The envelope was not parked in failed.');
        self::assertCount(1, $async->getSent(), 'The envelope was retried on async.');
        self::assertEmailCount(0);
        self::assertSame([], $this->tokens($tenant));
    }

    /**
     * **T-M14 (M-4):** the message round-trips through `AllowlistSerializer` — every value object rebuilt, `requestedAt`
     * equal to the second. **T-M14b:** an envelope whose payload violates a VO is refused at decode, with no handler call;
     * on the Redis receiver that refusal stops the worker (`RESIDUAL_GAPS.md` G-1.3), which this in-memory test cannot show.
     */
    #[Test]
    public function theMessageRoundTripsThroughTheAllowlistSerializer(): void
    {
        $serializer = new AllowlistSerializer(MessageCatalogue::ALL, new NullLogger());
        $message = $this->message(self::SLUG, self::EMAIL);
        $back = $serializer->decode($serializer->encode(new Envelope($message)))->getMessage();

        self::assertEquals($message, $back);
        self::assertInstanceOf(PasswordResetRequested::class, $back);
        self::assertSame($message->requestedAt->getTimestamp(), $back->requestedAt->getTimestamp());
        self::assertSame('000000', $back->requestedAt->format('u'));

        $encoded = $serializer->encode(new Envelope($message));
        $encoded['body'] = str_replace('"'.self::EMAIL.'"', '"no at sign"', $encoded['body']);
        try {
            $serializer->decode($encoded);
            self::fail('An envelope whose email violates EmailAddress was decoded.');
        } catch (MessageDecodingFailedException|\InvalidArgumentException|\Symfony\Component\Serializer\Exception\ExceptionInterface) {
        }
        self::assertSame(PasswordResetRequested::class, MessageCatalogue::ALL[0]);
    }

    /** MU-29's structural half (R1.11): the handler takes a mail TRANSPORT, never `MailerInterface`, which could dispatch. */
    #[Test]
    public function theHandlerSendsThroughATransport(): void
    {
        $types = array_map(
            static fn (\ReflectionParameter $p): string => (string) $p->getType(),
            (new \ReflectionMethod(PasswordResetRequestedHandler::class, '__construct'))->getParameters(),
        );
        self::assertContains(TransportInterface::class, $types);
        self::assertNotContains(\Symfony\Component\Mailer\MailerInterface::class, $types);
    }

    // ------------------------------------------------------------------------------------------------------------------

    /** @return array{0: string, 1: string} tenant and user ids of a live, sealed user */
    private function liveUser(): array
    {
        $tenant = $this->seedTenant(self::SLUG, Tenant::STATUS_ACTIVE);
        $user = $this->seedUser($tenant, self::EMAIL, 'password-number-one');
        $this->sealIdentity($tenant, $user, self::EMAIL);

        return [$tenant, $user];
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

    private function request(string $workspace, string $email): void
    {
        $this->client()->disableReboot();
        $this->client()->setServerParameter('REMOTE_ADDR', '203.0.113.7');
        $this->send('POST', '/auth/password-resets', ['workspace' => $workspace, 'email' => $email]);
        self::assertSame(202, $this->httpStatus());
    }

    /** Get every queued envelope (decoded by `AllowlistSerializer`), handle it as the worker would, acknowledge it. */
    private function drain(): void
    {
        $async = $this->transport('async');
        foreach ($async->get() as $envelope) {
            $this->bus()->dispatch($envelope->with(new ReceivedStamp('async')));
            $async->ack($envelope);
        }
    }

    private function message(string $workspace, string $email): PasswordResetRequested
    {
        return new PasswordResetRequested(
            WorkspaceSlug::fromInput($workspace),
            EmailAddress::fromInput($email),
            new ClientIp('203.0.113.7'),
            new \DateTimeImmutable('@'.time()),
            MessageId::new(),
        );
    }

    private function handler(MockClock $clock, ?Connection $worker = null, ?TransportInterface $mailer = null, ?AbstractLogger $logger = null): PasswordResetRequestedHandler
    {
        $container = self::getContainer();
        $worker ??= $container->get('test.doctrine.dbal.worker_connection');
        \assert($worker instanceof Connection);
        $tenants = $container->get(TenantContext::class);
        \assert($tenants instanceof TenantContext);
        $mailer ??= $container->get('mailer.transports');
        \assert($mailer instanceof TransportInterface);

        return new PasswordResetRequestedHandler($worker, $tenants, $this->seal(), new PasswordResetAuditWriter($worker),
            $mailer, $clock, $logger ?? new NullLogger(), 'no-reply@erp.local', 'http://127.0.0.1:5173');
    }

    private function transport(string $name): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.'.$name);
        \assert($transport instanceof InMemoryTransport);

        return $transport;
    }

    private function bus(): MessageBusInterface
    {
        $bus = self::getContainer()->get('messenger.default_bus');
        \assert($bus instanceof MessageBusInterface);

        return $bus;
    }

    private function recordingLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{message: string, context: array<mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };
    }

    /** @return list<array<string, mixed>> */
    private function tokens(string $tenant): array
    {
        return $this->fetchAsOwnerForTenant($tenant, 'SELECT user_id, purpose, selector, verifier_mac, created_at, expires_at, used_at FROM password_reset_token ORDER BY created_at');
    }

    private function tokenCount(string $tenant, string $user): int
    {
        return \count(array_filter($this->tokens($tenant), static fn (array $r): bool => $r['user_id'] === $user));
    }

    /** @return list<array<string, mixed>> */
    private function trail(string $tenant): array
    {
        return $this->fetchAsOwnerForTenant($tenant, 'SELECT event_type, actor_user_id, actor_label, subject_type, ip_address, metadata FROM audit_log ORDER BY occurred_at');
    }
}

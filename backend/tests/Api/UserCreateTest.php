<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\AuditLog\Infrastructure\UserAuditWriter;
use App\Modules\UserManagement\Application\Contract\Message\IssueInvitation;
use App\Modules\UserManagement\Application\RoleAdministrationActor;
use App\Modules\UserManagement\Application\RoleAdministrationLock;
use App\Modules\UserManagement\Application\UserCreation;
use App\Modules\UserManagement\Application\UserReader;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\UserManagement\Domain\SealCheck;
use App\Modules\UserManagement\Domain\SealsIdentities;
use App\Modules\UserManagement\Domain\VerifiesPasswords;
use App\Modules\UserManagement\Infrastructure\MimeAddressCheck;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\Entity\Tenant;
use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\ValueObject\AuditEvent;
use App\Tests\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Uid\Uuid;

/**
 * **`CreateUser` — `POST /api/v1/users`, `app_user.create`** (U-C1b; `docs/session13/u-c1-build-plan.md` Revision 2 FINAL R2.2
 * as fixed by `u-c1-final-red.md`). T-U2, T-U3, T-U4, T-U4b and T-U10 live here; T-U1 is `App\Tests\Auth\UserProvisioningTest`,
 * T-U5/T-U5b `App\Tests\Auth\CrossTenantInvitationTakeoverTest`, T-U8/T-U8b/T-U5c's invitation half
 * `App\Tests\Messaging\IssueInvitationHandlerTest`, T-U13 the race in `PurchaseOrderRowLockTest`. Mutation results:
 * `docs/session13/u-c1-progress.md`.
 */
final class UserCreateTest extends AuthTestCase
{
    private const SLUG = self::SLUG_PREFIX.'create';

    private const PASSWORD = 'a first passphrase of my own';

    private const COPY = [
        'app_user.email_taken' => UserCreation::EMAIL_TAKEN,
    ];

    /**
     * **T-U2:** an admin adds `"  Ada@Example.TEST "`; the stored address is `ada@example.test`; one mail goes to it; the seal
     * over the STORED address verifies; the link sets the first password; the person signs in. The trail: one `user.created`,
     * one `auth.password_reset.issued` `{purpose: invitation, requested_by: invitation}`, one `auth.password.reset`
     * `{purpose: invitation}`. Mutation MU-2 (seal over the raw input) → the worker sees a mismatch, sends nothing → red.
     */
    #[Test]
    public function anInvitationArrivesAndSetsTheFirstPassword(): void
    {
        $tenant = $this->workspaceWithAnAdmin();

        $this->create('  Ada@Example.TEST ', 'Ada Lovelace');
        self::assertSame(201, $this->httpStatus(), $this->responseContent());
        $id = (string) $this->json()['id'];
        self::assertSame('ada@example.test', $this->json()['email']);
        $stored = $this->fetchAsOwnerForTenant($tenant, 'SELECT email, identity_mac, password_mac FROM app_user WHERE id = ?::uuid', [$id])[0];
        self::assertSame('ada@example.test', $stored['email']);
        self::assertNull($stored['password_mac']);
        self::assertSame(SealCheck::Verified, $this->seal()->checkIdentity($tenant, $id, 'ada@example.test', (string) $stored['identity_mac']));

        $this->drain();
        self::assertEmailCount(1);
        $mail = self::getMailerMessage(0);
        self::assertNotNull($mail);
        self::assertSame(['ada@example.test'], array_map(static fn (Address $a): string => $a->getAddress(), $mail->getTo()));
        self::assertSame(1, preg_match('~/reset-password#token=([A-Za-z0-9_-]{86})\b~', (string) $mail->getTextBody(), $m), 'The mail carries no link.');

        $this->client()->setServerParameter('REMOTE_ADDR', '203.0.113.7');
        $this->actAsNobody();
        $this->send('POST', '/auth/password-resets/redemption', ['token' => $m[1], 'password' => self::PASSWORD]);
        self::assertSame(200, $this->httpStatus(), $this->responseContent());
        $this->signIn(self::SLUG, 'ada@example.test', self::PASSWORD);
        self::assertSame(200, $this->httpStatus(), 'The invited person cannot sign in with the password they chose.');

        $issued = $this->fetchAsOwnerForTenant($tenant, 'SELECT metadata FROM audit_log WHERE event_type = ?', [AuditEvent::AUTH_PASSWORD_RESET_ISSUED]);
        self::assertCount(1, $issued);
        $metadata = json_decode((string) $issued[0]['metadata'], true);
        self::assertSame(['invitation', 'invitation'], [$metadata['purpose'] ?? null, $metadata['requested_by'] ?? null]);
        $reset = $this->fetchAsOwnerForTenant($tenant, 'SELECT metadata FROM audit_log WHERE event_type = ?', [AuditEvent::AUTH_PASSWORD_RESET]);
        self::assertSame([['purpose' => 'invitation']], array_map(static fn (array $r): mixed => json_decode((string) $r['metadata'], true), $reset));
        $token = $this->fetchAsOwnerForTenant($tenant, 'SELECT extract(epoch FROM expires_at - created_at)::int AS life FROM password_reset_token WHERE user_id = ?::uuid', [$id]);
        self::assertSame([259200], array_map(static fn (array $r): int => (int) $r['life'], $token));
    }

    /**
     * **T-U3:** the mixed-case form first, then the lower-case one → 409 `app_user.email_taken`, nothing queued; a deleted
     * person's address can be given to someone new; the deleted account then cannot be restored. Mutation MU-13 (drop the
     * 23505 translation) → 500 → red.
     */
    #[Test]
    public function aTakenEmailIs409AndADeletedOnesIsNot(): void
    {
        $tenant = $this->workspaceWithAnAdmin();
        $this->create('Bob@Example.test', 'Bob');
        self::assertSame(201, $this->httpStatus(), $this->responseContent());
        $bob = (string) $this->json()['id'];
        // The in-memory transport is reset before each request (`services_resetter`), so `getSent()` is that request's.
        self::assertCount(1, $this->transport()->getSent(), 'Control: the first create queued nothing.');

        $this->create('bob@example.test', 'Another Bob');
        self::assertSame([409, 'app_user.email_taken', self::COPY['app_user.email_taken']], [$this->httpStatus(), $this->json()['rule'] ?? null, $this->json()['detail'] ?? null], $this->responseContent());
        self::assertSame([], $this->transport()->getSent(), 'A refused create queued an invitation.');

        $this->send('DELETE', '/users/'.$bob);
        self::assertSame(204, $this->httpStatus(), $this->responseContent());
        $this->create('bob@example.test', 'New Bob');
        self::assertSame(201, $this->httpStatus(), 'A deleted person\'s address could not be given to someone new.');

        $this->send('POST', '/users/'.$bob.'/restoration');
        self::assertSame([409, 'app_user.email_taken'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        self::assertSame(2, $this->countAsOwnerForTenant($tenant, "SELECT count(*) FROM app_user WHERE email = 'bob@example.test'"));
    }

    /**
     * **T-U4 (§4.3a fails-if):** a password, a password hash, roles, `is_active`, an id or a token is refused as an undeclared
     * field, and nothing is created.
     */
    #[Test]
    public function nothingElseIsAccepted(): void
    {
        $tenant = $this->workspaceWithAnAdmin();
        $before = $this->countAsOwnerForTenant($tenant, 'SELECT count(*) FROM app_user');
        foreach (['password' => 'x', 'password_hash' => 'x', 'role_ids' => [], 'is_active' => true, 'id' => Uuid::v4()->toRfc4122(), 'token' => 'x'] as $field => $value) {
            $this->send('POST', '/users', ['email' => 'extra@example.test', 'display_name' => 'Extra', $field => $value], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
            self::assertContains($this->httpStatus(), [400, 422], "$field was not refused: ".$this->responseContent());
            self::assertStringContainsString($field, $this->responseContent(), "The refusal does not name $field.");
        }
        self::assertSame($before, $this->countAsOwnerForTenant($tenant, 'SELECT count(*) FROM app_user'));
        self::assertSame([], $this->transport()->getSent());
    }

    /**
     * **T-U4 (L-3, final RED M-2):** an address the mail worker could not build is refused 422 `/email` before any write — no
     * role lock, no insert, nothing queued. The rows: U+200B, U+202E, U+00AD, an inner space, U+00A0 (the `\p{Z}` arm — Mime
     * accepts it; it reddens a Mime-only checker) and `a(comment)@example.test` (egulias' RFC validation accepts it, Mime
     * refuses — it reddens an `RFCValidation` substitute). A bad display name is 422 `/display_name` the same way. Mutation
     * MU-19 (drop the create-time check) → 201 → red.
     */
    #[Test]
    public function anUndeliverableEmailOrBadNameIs422BeforeAnyStatement(): void
    {
        $tenant = $this->workspaceWithAnAdmin();
        foreach (self::refusedAddresses() as $case => $address) {
            $this->recorder()->start();
            $this->create($address, 'Someone');
            $this->recorder()->stop();
            self::assertSame(422, $this->httpStatus(), "$case: ".$this->responseContent());
            self::assertSame(['/email'], array_column($this->json()['errors'] ?? [], 'pointer'), "$case: ".$this->responseContent());
            self::assertSame([], $this->recorder()->matching('/'.preg_quote(RoleAdministrationLock::SQL, '/').'|INSERT\s+INTO/i'), "$case reached the lock or a write.");
        }
        foreach (['', '   ', str_repeat('x', 101), "Ad\u{200B}a"] as $name) {
            $this->create('fine@example.test', $name);
            self::assertSame([422, ['/display_name']], [$this->httpStatus(), array_column($this->json()['errors'] ?? [], 'pointer')], $this->responseContent());
        }
        self::assertSame(0, $this->countAsOwnerForTenant($tenant, "SELECT count(*) FROM app_user WHERE display_name IN ('Someone') OR email = 'fine@example.test'"));
        self::assertSame([], $this->transport()->getSent());
    }

    /**
     * **The checker IS the worker's call (final RED M-2):** over T-U4's rows plus an accented local part (accepted),
     * `MimeAddressCheck`'s verdict equals `new Address()`'s, except for the `\p{Z}` rows, which only the separator arm refuses.
     */
    #[Test]
    public function theAddressCheckAgreesWithTheWorkersOwnCall(): void
    {
        $check = new MimeAddressCheck();
        $rows = self::refusedAddresses() + ['accented' => 'ünï@example.test', 'plain' => 'ada@example.test'];
        foreach ($rows as $case => $address) {
            try {
                new Address($address);
                $mime = true;
            } catch (\Throwable) {
                $mime = false;
            }
            $expected = $mime && 1 !== preg_match('/\p{Z}/u', $address);
            self::assertSame($expected, $check->accepts($address), "$case disagrees with the worker's own call.");
        }
        self::assertTrue($check->accepts('ünï@example.test'));
        self::assertFalse($check->accepts("ad\u{00A0}a@example.test"), 'The \p{Z} arm is not there.');
        self::assertFalse($check->accepts('a(comment)@example.test'), 'A comment in the local part passed: is this RFCValidation rather than Mime?');
    }

    /**
     * **T-U4b:** 201 with `Location: /users/{id}` and exactly `GetUser`'s body; one `user.created` (subject the new person, the
     * creator's IP, field names only); 428 without the key; a `viewer` 403.
     */
    #[Test]
    public function theBodyIsGetUsersBodyAndTheRowIsRecorded(): void
    {
        $tenant = $this->workspaceWithAnAdmin();
        $this->client()->setServerParameter('REMOTE_ADDR', '198.51.100.9');
        $this->create('carol@example.test', 'Carol');
        self::assertSame(201, $this->httpStatus(), $this->responseContent());
        $body = $this->json();
        $location = (string) $this->client()->getResponse()->headers->get('Location');
        self::assertStringEndsWith('/users/'.$body['id'], $location);
        self::assertSame([[], false, true, null], [$body['roles'], $body['margin_exposure'], $body['is_active'], $body['archived_at']]);
        $this->send('GET', '/users/'.$body['id']);
        self::assertSame($body, $this->json(), 'The 201 body is not GetUser\'s body.');

        $rows = $this->fetchAsOwnerForTenant($tenant, 'SELECT actor_user_id, subject_type, subject_id, host(ip_address) AS ip, metadata FROM audit_log WHERE event_type = ?', [AuditEvent::USER_CREATED]);
        self::assertCount(1, $rows);
        self::assertSame([(string) $this->actorId, 'app_user', $body['id'], '198.51.100.9'], [$rows[0]['actor_user_id'], $rows[0]['subject_type'], $rows[0]['subject_id'], $rows[0]['ip']]);
        self::assertSame(['fields' => ['display_name', 'email']], json_decode((string) $rows[0]['metadata'], true));

        $this->send('POST', '/users', ['email' => 'dan@example.test', 'display_name' => 'Dan']);
        self::assertSame(428, $this->httpStatus(), $this->responseContent());

        $viewer = $this->seedPrincipal($tenant, DefaultRoles::codesFor(DefaultRoles::VIEWER));
        $this->actAsUser($tenant, $viewer);
        $this->create('erin@example.test', 'Erin');
        self::assertSame(403, $this->httpStatus(), $this->responseContent());
    }

    /**
     * **T-U10 (D-U3, L-6, L-7):** a create whose audit write fails (a planted trigger) is a 500 with no person and nothing
     * queued; a queue that refuses the dispatch leaves the 201 intact, the person and `user.created` written, no
     * `issued` row, one log line without the address — and no data statement after the failed dispatch. Mutation MU-7
     * (dispatch inside the create transaction, before the audit row) → an envelope for a rolled-back person → red.
     */
    #[Test]
    public function aFailedCreateQueuesNothingAndAQueueFailureKeepsThe201(): void
    {
        $tenant = $this->workspaceWithAnAdmin();
        // The queue refuses (a stand-in for Redis refusing an XADD): driven through the service, the bus swapped
        // (`PasswordResetRequestTest::aQueueFailureStillAnswers202AndIsLogged`'s shape). The admin's identity is the
        // non-HTTP half `actAsUser()` set.
        $logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        $refusing = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new \RuntimeException('OOM command not allowed when used memory > maxmemory: grace@example.test');
            }
        };
        $this->actAsUser($tenant, (string) $this->actorId, DefaultRoles::codesFor(DefaultRoles::ADMIN));
        $this->recorder()->start();
        $view = $this->creation($refusing, $logger)->create(['email' => 'grace@example.test', 'display_name' => 'Grace'], '198.51.100.9', ['app_user', null]);
        $this->recorder()->stop();

        self::assertSame('grace@example.test', $view->email, 'A queue failure undid the create.');
        self::assertSame(1, $this->countAsOwnerForTenant($tenant, 'SELECT count(*) FROM audit_log WHERE event_type = ? AND subject_id = ?::uuid', [AuditEvent::USER_CREATED, $view->id]));
        self::assertSame(0, $this->countAsOwnerForTenant($tenant, 'SELECT count(*) FROM audit_log WHERE event_type = ?', [AuditEvent::AUTH_PASSWORD_RESET_ISSUED]));
        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertStringNotContainsString('grace', json_encode($logger->records, \JSON_THROW_ON_ERROR), 'The log line carries the address.');
        // L-6: no data statement after the create's own audit row — the dispatch's short tenancy transaction issues only
        // its `set_config` (tenancy setup, not data) and rolls back.
        $statements = $this->recorder()->statements();
        $lastData = null;
        foreach ($statements as $i => $sql) {
            if (1 === preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE)\b/i', $sql) && !str_contains($sql, 'set_config(')) {
                $lastData = $i;
            }
        }
        self::assertNotNull($lastData);
        self::assertStringContainsString('INSERT INTO audit_log', $statements[$lastData] ?? '', "The last data statement is not the create's own audit row:\n".implode("\n", \array_slice($statements, (int) $lastData)));

        // Last, because a failed write closes the shared entity manager: the create whose audit write fails (a planted
        // trigger) is a 500 with no person and nothing queued. The refused dispatch above closed the entity manager
        // (`TenantContext::run()` rolls back on a throw); reset it so the request's 500 is the planted failure's.
        $doctrine = self::getContainer()->get('doctrine');
        \assert($doctrine instanceof \Doctrine\Persistence\ManagerRegistry);
        $doctrine->resetManager();
        $owner = $this->owner();
        $owner->executeStatement(<<<'SQL'
            CREATE OR REPLACE FUNCTION u_c1_refuse_user_created() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN IF NEW.event_type = 'user.created' THEN RAISE EXCEPTION 'planted audit failure'; END IF; RETURN NEW; END $$
            SQL);
        $owner->executeStatement('CREATE TRIGGER u_c1_refuse_user_created BEFORE INSERT ON audit_log FOR EACH ROW EXECUTE FUNCTION u_c1_refuse_user_created()');
        try {
            $this->create('frank@example.test', 'Frank');
            self::assertSame(500, $this->httpStatus(), $this->responseContent());
            self::assertSame([], $this->transport()->getSent(), 'A failed create queued an invitation.');
        } finally {
            $owner->executeStatement('DROP TRIGGER IF EXISTS u_c1_refuse_user_created ON audit_log');
            $owner->executeStatement('DROP FUNCTION IF EXISTS u_c1_refuse_user_created()');
        }
        self::assertSame(0, $this->countAsOwnerForTenant($tenant, "SELECT count(*) FROM app_user WHERE email = 'frank@example.test'"));
    }

    private function creation(MessageBusInterface $bus, LoggerInterface $logger): UserCreation
    {
        $c = self::getContainer();
        $get = static fn (string $id): object => $c->get($id);

        return new UserCreation(
            $get(TenantContext::class), $get(CurrentTenant::class), $get(RequestIdentity::class), $get(RoleAdministrationActor::class),
            $get(UserAuditWriter::class), $get(UserReader::class), $get(VerifiesPasswords::class), $get(SealsIdentities::class),
            new MimeAddressCheck(), $bus, $logger,
        );
    }

    // ------------------------------------------------------------------------------------------------------------------

    /** @return array<string, string> */
    private static function refusedAddresses(): array
    {
        return [
            'U+200B' => "ad\u{200B}a@example.test",
            'U+202E' => "ad\u{202E}a@example.test",
            'U+00AD' => "ad\u{00AD}a@example.test",
            'inner space' => 'ad a@example.test',
            'U+00A0' => "ad\u{00A0}a@example.test",
            'a comment' => 'a(comment)@example.test',
        ];
    }

    private function workspaceWithAnAdmin(): string
    {
        $tenant = $this->seedTenant(self::SLUG, Tenant::STATUS_ACTIVE);
        $this->actAsUser($tenant, $this->seedPrincipal($tenant, DefaultRoles::codesFor(DefaultRoles::ADMIN)));
        // One kernel for the whole test, so the in-memory queue survives between requests.
        $this->client()->disableReboot();

        return $tenant;
    }

    private function create(string $email, string $displayName): void
    {
        $this->send('POST', '/users', ['email' => $email, 'display_name' => $displayName], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);

        return $transport;
    }

    /** Every queued envelope, handled as the worker would, through the real bus (stamp check, tenant-status gate, handler). */
    private function drain(): void
    {
        $bus = self::getContainer()->get('messenger.default_bus');
        \assert($bus instanceof MessageBusInterface);
        foreach ($this->transport()->get() as $envelope) {
            \assert($envelope instanceof Envelope);
            self::assertInstanceOf(IssueInvitation::class, $envelope->getMessage());
            $bus->dispatch($envelope->with(new ReceivedStamp('async')));
            $this->transport()->ack($envelope);
        }
    }

    private function seal(): SealsIdentities
    {
        $seal = self::getContainer()->get('test.auth.identity_seal');
        \assert($seal instanceof SealsIdentities);

        return $seal;
    }
}

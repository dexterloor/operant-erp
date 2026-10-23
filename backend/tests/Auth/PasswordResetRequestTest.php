<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Modules\UserManagement\Application\Contract\Message\PasswordResetRequested;
use App\Modules\UserManagement\Application\PasswordResetRequest;
use App\Modules\UserManagement\Application\PasswordResetRequestCommand;
use App\Modules\UserManagement\Application\PasswordResetRequestOutcome;
use App\Modules\UserManagement\Infrastructure\PreAuthBudgets;
use App\Platform\Tenancy\Entity\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * **`CreatePasswordReset` answers the same for everyone and queues one message** (Mail-C1, `mail-c1-build-plan.md`
 * Revision 1 FINAL R1.6, R1.10; design §4.2). The request path reads no user: what happens next is the worker's
 * (`PasswordResetHandlerTest`).
 *
 * Mutations (see `docs/session13/mail-c1-progress.md`): MU-1 resolve the slug on the request path → T-M2 red (`erp_auth`);
 * MU-2 skip the dispatch for an unknown address → T-M1 red; MU-3 dispatch before the budgets → T-M3 red; MU-4 a separate
 * budget → T-M3b red; MU-5 rethrow the bus error → T-M13 red; MU-6 format check after the budgets → T-M1b red; MU-7 a
 * stricter predicate → T-M1c red; MU-34 format before normalising → T-M1c red; MU-35 drop the control refusal → T-M1b red.
 */
final class PasswordResetRequestTest extends AuthTestCase
{
    private const CONNECTIONS = ['app', 'ai', 'auth', 'authlog', 'ratelimit', 'worker'];

    /**
     * **T-M1:** a known pair, an unknown address, an unknown workspace, a deactivated user, a deleted user and a suspended
     * workspace all get the identical response — status, headers, body — and exactly one envelope each.
     */
    #[Test]
    public function everyWellFormedInputGetsTheSameAnswerAndOneDispatch(): void
    {
        $live = $this->seedTenant(self::SLUG_PREFIX.'reset-live', Tenant::STATUS_ACTIVE);
        $suspended = $this->seedTenant(self::SLUG_PREFIX.'reset-off', Tenant::STATUS_SUSPENDED);
        $this->seedUser($live, 'alice@example.test', 'password-number-one');
        $this->seedUser($live, 'idle@example.test', 'password-number-two', false);
        $this->seedUserWithId($live, '7a8b9c0d-1e2f-4a3b-8c4d-5e6f7a8b9c0d', 'gone@example.test', 'password-number-three', true);
        $this->seedUser($suspended, 'bob@example.test', 'password-number-four');

        $answers = [];
        foreach ([
            'known' => [self::SLUG_PREFIX.'reset-live', 'alice@example.test'],
            'unknown email' => [self::SLUG_PREFIX.'reset-live', 'nobody@example.test'],
            'unknown workspace' => [self::SLUG_PREFIX.'reset-none', 'alice@example.test'],
            'deactivated' => [self::SLUG_PREFIX.'reset-live', 'idle@example.test'],
            'deleted' => [self::SLUG_PREFIX.'reset-live', 'gone@example.test'],
            'suspended workspace' => [self::SLUG_PREFIX.'reset-off', 'bob@example.test'],
        ] as $case => [$workspace, $email]) {
            $this->async()->reset();
            $this->ask($workspace, $email);
            self::assertCount(1, $this->async()->getSent(), "$case: not exactly one envelope.");
            $answers[$case] = $this->answer();
        }

        $first = reset($answers);
        self::assertSame(202, $first['status']);
        self::assertSame('', $first['body'], 'A 202 carried a body.');
        foreach ($answers as $case => $answer) {
            self::assertSame($first, $answer, "$case answered differently from a known pair.");
        }
    }

    /**
     * **T-M1b:** malformed input — a slug the registry cannot hold, an address with no `@`, one over 254 characters, a NUL,
     * a C0 and a C1 control, invalid UTF-8 — is a 422 with a field pointer, and touches nothing: no statement on any of the
     * six connections, no envelope.
     */
    #[Test]
    public function aMalformedRequestIs422AndTouchesNothing(): void
    {
        foreach ([
            ['/workspace', 'x', 'alice@example.test'],
            ['/workspace', 'has space', 'alice@example.test'],
            // An inner NUL (a trailing one is trimmed by sign-in's own normalisation, `PreAuthKeys`, and is harmless).
            ['/workspace', "ac\0me", 'alice@example.test'],
            ['/email', 'acme', 'no-at-sign.example.test'],
            ['/email', 'acme', 'two@@example.test'],
            ['/email', 'acme', '@example.test'],
            ['/email', 'acme', 'alice@'],
            ['/email', 'acme', str_repeat('a', 243).'@example.test'],
            ['/email', 'acme', "alice\0@example.test"],
            ['/email', 'acme', "alice\x07@example.test"],
            ['/email', 'acme', "alice\u{0085}@example.test"],
        ] as [$pointer, $workspace, $email]) {
            $this->async()->reset();
            $this->recordAll();
            $this->ask($workspace, $email);
            $statements = $this->stopAll();

            self::assertSame(422, $this->httpStatus(), "$pointer ".json_encode($email).': not refused.');
            self::assertContains($pointer, array_column((array) ($this->json()['errors'] ?? []), 'pointer'), "$pointer: the error does not point at the field.");
            self::assertSame([], $this->async()->getSent(), "$pointer: an envelope was queued.");
            self::assertSame(array_fill_keys(self::CONNECTIONS, 0), $statements, "$pointer: a statement ran before the format check.");
        }

        // Invalid UTF-8 cannot travel in a JSON body; it is refused at the edge by the decoder, through the service here.
        $result = $this->service($this->bus())->request(new PasswordResetRequestCommand('acme', "alice\xC3@example.test", '203.0.113.7'));
        self::assertSame(PasswordResetRequestOutcome::Invalid, $result->outcome);
    }

    /**
     * **T-M1c:** the predicate is no stricter than what `app_user` can hold and sign-in accepts (L-7), and normalisation
     * runs before it (M-2): `" Acme "` and `"ALICE@EXAMPLE.COM "` are accepted.
     */
    #[Test]
    public function theFormatPredicateIsNoStricterThanStoredAddresses(): void
    {
        foreach ([
            ['acme', 'a@b'],
            ['acme', 'x+y@sub.example'],
            ['acme', str_repeat('a', 241).'@example.test'],
            ['acme', 'élise@exämple.test'],
            [' Acme ', 'alice@example.test'],
            ['acme', 'ALICE@EXAMPLE.COM '],
            ['a-1', 'alice@example.test'],
        ] as [$workspace, $email]) {
            $this->ask($workspace, $email);
            self::assertSame(202, $this->httpStatus(), json_encode([$workspace, $email]).' was refused: an address the store can hold is locked out of recovery.');
        }

        $sent = $this->async()->getSent();
        $last = end($sent);
        self::assertInstanceOf(Envelope::class, $last);
        $message = $last->getMessage();
        self::assertInstanceOf(PasswordResetRequested::class, $message);
        self::assertSame('a-1', $message->workspace->value);
    }

    /**
     * **T-M2:** the request path reads no user and writes nothing but the two budgets — `erp_app`, `erp_ai`, `erp_auth`,
     * `erp_authlog` and `erp_worker` issue nothing; `erp_ratelimit` issues something.
     */
    #[Test]
    public function theRequestPathReadsNoUserAndWritesNothingButBudgets(): void
    {
        $tenant = $this->seedTenant(self::SLUG_PREFIX.'reset-quiet', Tenant::STATUS_ACTIVE);
        $this->seedUser($tenant, 'alice@example.test', 'password-number-one');

        $this->recordAll();
        $this->ask(self::SLUG_PREFIX.'reset-quiet', 'alice@example.test');
        $statements = $this->stopAll();

        self::assertSame(202, $this->httpStatus());
        self::assertGreaterThan(0, $statements['ratelimit'], 'The budgets issued nothing, so the assertions below prove nothing.');
        unset($statements['ratelimit']);
        self::assertSame(array_fill_keys(['app', 'ai', 'auth', 'authlog', 'worker'], 0), $statements,
            'The reset request path reached a principal other than the rate limiter.');
    }

    /** **T-M3:** a spent IP budget, and then a spent slug budget alone, answer 429 and queue nothing. */
    #[Test]
    public function aThrottledCallerIsRefusedBeforeDispatch(): void
    {
        $this->seedTenant(self::SLUG_PREFIX.'reset-busy', Tenant::STATUS_ACTIVE);
        $budgets = self::getContainer()->get('test.auth.budgets');
        \assert($budgets instanceof PreAuthBudgets);
        $now = new \DateTimeImmutable();

        for ($i = 0; $i < PreAuthBudgets::IP_CEILING; ++$i) {
            $budgets->consumeIpBudget('198.51.100.9/32', $now);
        }
        $this->async()->reset();
        $this->ask(self::SLUG_PREFIX.'reset-busy', 'alice@example.test', '198.51.100.9');
        self::assertSame(429, $this->httpStatus(), 'A spent IP budget was not refused.');
        self::assertSame([], $this->async()->getSent(), 'A throttled request queued an envelope.');

        for ($i = 0; $i < PreAuthBudgets::SLUG_CEILING; ++$i) {
            $budgets->consumeSlugBudget(self::SLUG_PREFIX.'reset-busy', '198.51.100.10/32', $now);
        }
        $this->async()->reset();
        $this->ask(self::SLUG_PREFIX.'reset-busy', 'alice@example.test', '198.51.100.10');
        self::assertSame(429, $this->httpStatus(), 'A spent slug budget was not refused.');
        self::assertSame([], $this->async()->getSent(), 'A throttled request queued an envelope.');
    }

    /** **T-M3b (D-M3):** resets and sign-ins spend ONE budget — sign-ins up to the edge, then a reset is refused. */
    #[Test]
    public function resetRequestsAndSignInsShareOneBudget(): void
    {
        $this->seedTenant(self::SLUG_PREFIX.'reset-shared', Tenant::STATUS_ACTIVE);
        $budgets = self::getContainer()->get('test.auth.budgets');
        \assert($budgets instanceof PreAuthBudgets);
        $now = new \DateTimeImmutable();
        for ($i = 0; $i < PreAuthBudgets::SLUG_CEILING - 1; ++$i) {
            $budgets->consumeSlugBudget(self::SLUG_PREFIX.'reset-shared', '198.51.100.11/32', $now);
        }
        $this->signIn(self::SLUG_PREFIX.'reset-shared', 'alice@example.test', 'wrong-password-here', '198.51.100.11');
        self::assertNotSame(429, $this->httpStatus(), 'Control: the sign-in at the edge was already refused.');

        $this->ask(self::SLUG_PREFIX.'reset-shared', 'alice@example.test', '198.51.100.11');
        self::assertSame(429, $this->httpStatus(), 'A reset request did not spend the sign-in budget: a caller can alternate the two.');
    }

    /**
     * **T-M13:** a bus that refuses the message still answers 202 (accepted) and logs one error line that names the
     * message id and never the address or the workspace. A stand-in for Redis refusing an `XADD` under `noeviction`
     * (review check: the real refusal is not simulated here).
     */
    #[Test]
    public function aQueueFailureStillAnswers202AndIsLogged(): void
    {
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
                throw new \RuntimeException('OOM command not allowed when used memory > maxmemory: acme alice@example.test');
            }
        };

        $result = $this->service($refusing, $logger)->request(new PasswordResetRequestCommand('acme', 'alice@example.test', '203.0.113.7'));

        self::assertSame(PasswordResetRequestOutcome::Accepted, $result->outcome);
        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertArrayHasKey('message_id', $logger->records[0]['context']);
        $logged = json_encode($logger->records, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('alice', $logged, 'The log line carries the address.');
        self::assertStringNotContainsString('acme', $logged, 'The log line carries the workspace.');
    }

    private function ask(string $workspace, string $email, string $ip = '203.0.113.7'): void
    {
        $this->client()->setServerParameter('REMOTE_ADDR', $ip);
        $this->send('POST', '/auth/password-resets', ['workspace' => $workspace, 'email' => $email]);
    }

    /** @return array{status: int, body: string, headers: array<string, mixed>} */
    private function answer(): array
    {
        $response = $this->client()->getResponse();
        $headers = $response->headers->all();
        unset($headers['date'], $headers['x-debug-token'], $headers['x-debug-token-link']);
        ksort($headers);

        return ['status' => $response->getStatusCode(), 'body' => (string) $response->getContent(), 'headers' => $headers];
    }

    private function async(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);

        return $transport;
    }

    private function recordAll(): void
    {
        foreach (self::CONNECTIONS as $connection) {
            $this->recorderFor($connection)->start();
        }
    }

    /** @return array<string, int> */
    private function stopAll(): array
    {
        $counts = [];
        foreach (self::CONNECTIONS as $connection) {
            $this->recorderFor($connection)->stop();
            $counts[$connection] = \count($this->recorderFor($connection)->statements());
        }

        return $counts;
    }

    private function bus(): MessageBusInterface
    {
        $bus = self::getContainer()->get('messenger.default_bus');
        \assert($bus instanceof MessageBusInterface);

        return $bus;
    }

    private function service(MessageBusInterface $bus, ?AbstractLogger $logger = null): PasswordResetRequest
    {
        $budgets = self::getContainer()->get('test.auth.budgets');
        \assert($budgets instanceof PreAuthBudgets);

        return new PasswordResetRequest($budgets, $bus, new NativeClock(), $logger ?? new \Psr\Log\NullLogger());
    }
}

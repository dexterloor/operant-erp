<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Modules\UserManagement\Application\Contract\Message\IssueInvitation;
use ErpDemoWalkthrough\Mailbox;
use ErpDemoWalkthrough\Output;
use ErpDemoWalkthrough\Plan;
use ErpDemoWalkthrough\Stop;
use ErpDemoWalkthrough\Transport;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

require_once \dirname(__DIR__, 2).'/bin/demo-seed.php';
require_once \dirname(__DIR__, 2).'/bin/demo-walkthrough.php';

/**
 * **The rig for the demo plan's in-kernel tests** (2a C3/C4), used by an `AuthTestCase`: phase 1 as a real seeder process on
 * a `demo-test-` workspace (`DemoSeedProcessTest`'s precedent), then the plan through a test transport (this test's kernel
 * browser) and a test mailbox that drains the in-memory queue through the real bus and reads the mail the worker sent
 * (`UserCreateTest`'s precedent). Every bearer and invitation token seen is kept in `$secrets`, so a test can prove none
 * reached the output. The real Mailpit client and the real stream transport are covered elsewhere
 * (`DemoStreamTransportTest`, and a recorded run against the running catcher).
 */
trait DemoPlanRig
{
    /** @var list<string> every password, bearer and invitation token seen */
    private array $secrets = [];

    private static function demoSlug(): string
    {
        return \ErpDemoSeed\Locality::TEST_SLUG_PREFIX.substr(bin2hex(random_bytes(6)), 0, 10);
    }

    /** @return array<string, mixed> the committed spec */
    private static function spec(): array
    {
        $spec = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/contracts/openapi.json'), true);
        self::assertIsArray($spec);

        return $spec;
    }

    /**
     * Runs one step of the plan and captures its output and exit code.
     *
     * @param callable(Plan): void $run
     *
     * @return array{0: Plan, 1: int, 2: string}
     */
    private function runPlan(string $slug, string $password, callable $run): array
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        $plan = new Plan($this->transport(), 'http://127.0.0.1:8000', new Output($stream), $slug, $password, self::spec());
        try {
            $run($plan);
            $exit = Plan::EXIT_OK;
        } catch (Stop $stop) {
            (new Output($stream))->line($stop->safeLine);
            $exit = $stop->exitCode;
        }
        rewind($stream);

        return [$plan, $exit, (string) stream_get_contents($stream)];
    }

    private function transport(): Transport
    {
        return new class($this) implements Transport {
            public function __construct(private readonly object $test)
            {
            }

            public function send(string $method, string $url, array $headers, ?string $body): array
            {
                return $this->test->exchange($method, $url, $headers, $body);
            }
        };
    }

    /**
     * The transport's adapter onto this test's kernel browser.
     *
     * @param list<string> $headers
     *
     * @return array{status: int, body: string}
     */
    public function exchange(string $method, string $url, array $headers, ?string $body): array
    {
        $server = [];
        foreach ($headers as $header) {
            [$name, $value] = array_map('trim', explode(':', $header, 2));
            $key = strtoupper(str_replace('-', '_', $name));
            $server['CONTENT_TYPE' === $key ? $key : 'HTTP_'.$key] = $value;
        }
        $this->client()->request($method, $url, [], [], $server, $body);
        $response = $this->client()->getResponse();
        $content = (string) $response->getContent();
        $this->afterRequest();
        $decoded = json_decode($content, true);
        if (\is_array($decoded) && \is_string($decoded['access_token'] ?? null)) {
            $this->secrets[] = $decoded['access_token'];
        }

        return ['status' => $response->getStatusCode(), 'body' => $content];
    }

    /** Called after every request; the walk test accumulates the owner-connection window here. */
    protected function afterRequest(): void
    {
    }

    private function mailbox(): Mailbox
    {
        return new class($this) implements Mailbox {
            public function __construct(private readonly object $test)
            {
            }

            public function invitationToken(string $email): string
            {
                return $this->test->invitationFor($email);
            }
        };
    }

    /** Drains the queue through the real bus, then reads the one invitation sent to `$email`. */
    public function invitationFor(string $email): string
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);
        $bus = self::getContainer()->get('messenger.default_bus');
        \assert($bus instanceof MessageBusInterface);
        foreach ($transport->get() as $envelope) {
            \assert($envelope instanceof Envelope);
            self::assertInstanceOf(IssueInvitation::class, $envelope->getMessage());
            $bus->dispatch($envelope->with(new ReceivedStamp('async')));
            $transport->ack($envelope);
        }
        $mine = array_values(array_filter(self::getMailerMessages(), static fn (mixed $m): bool => $m instanceof Email
            && [$email] === array_map(static fn (Address $a): string => $a->getAddress(), $m->getTo())));
        self::assertCount(1, $mine, 'Not exactly one invitation to '.$email);
        $token = Plan::tokenIn((string) $mine[0]->getTextBody());
        self::assertNotNull($token);
        $this->secrets[] = $token;

        return $token;
    }

    /** `bin/demo-seed.php` as a process, the way `make demo-seed` runs it; the password goes in on stdin. */
    private function seed(string $slug, string $password): void
    {
        $backend = \dirname(__DIR__, 2);
        $process = proc_open([\PHP_BINARY, $backend.'/bin/demo-seed.php', '--workspace='.$slug, '--password-stdin'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $backend, ['PATH' => (string) getenv('PATH'), 'APP_ENV' => 'test']);
        self::assertIsResource($process);
        fwrite($pipes[0], $password);
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr);
        $this->secrets[] = $password;
    }

    /** @return array{0: int, 1: string} */
    private function reconcile(string $tenantId): array
    {
        $tester = new CommandTester((new Application(self::$kernel ?? throw new \LogicException('No kernel.')))->find('app:roles:reconcile'));

        return [$tester->execute(['--tenant' => $tenantId]), $tester->getDisplay()];
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Messaging;

use App\Platform\Messaging\TenantIdStamp;
use App\Platform\Messaging\TenantStampMiddleware;
use App\Platform\Messaging\TenantStatusGateMiddleware;
use App\Platform\Tenancy\Entity\Tenant;
use App\Platform\Tenancy\SchemaManifest;
use App\Tests\Auth\AuthTestCase;
use App\Tests\Messaging\Fixture\CataloguedProbe;
use App\Tests\Messaging\Fixture\ProbeId;
use App\Tests\Messaging\Fixture\TenantProbe;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\HandlerArgumentsStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Uid\Uuid;

/**
 * **O-8: a received workspace message is handled only while its workspace permits sign-in** (U-C1a;
 * `docs/session13/u-c1-build-plan.md` Revision 2 FINAL R2.1; `u-c1-final-red.md` C-1). The catalogue tripwire
 * `MessengerConfigurationTest::aTenantMessageInTheCatalogueRequiresTheTenantStatusGate` reads {@see self::tenantStatuses()}:
 * it must cover every `Tenant::STATUS_*`, so it is DERIVED from the constants, never listed.
 *
 * T-U7 drives {@see TenantStatusGateMiddleware} directly, on the real `erp_worker` connection, with a stack whose next
 * middleware records what passed (the planted `TenantProbe`: the real catalogue holds no workspace message until U-C1b).
 * T-U7c boots the web-shaped kernel with an EMPTY worker DSN in a child process and dispatches through the real bus.
 * Mutations: MU-10 (gate on `!== STATUS_CLOSED`) → `suspended`/`pending`/`sentinel` handled → red here; MU-21 (bind the
 * worker connection eagerly) → T-U7c red (measured: `Doctrine\DBAL\ConnectionException` from the dispatch — the
 * platform probe; RED predicted `DriverRequired`; same effect, the dispatch throws). Records: `docs/session13/u-c1-progress.md`.
 */
final class WorkerTenantStatusTest extends AuthTestCase
{
    /** @var list<Envelope> */
    private array $passed = [];

    /** @return iterable<string, array{0: string}> every `Tenant::STATUS_*`, derived */
    public static function tenantStatuses(): iterable
    {
        foreach ((new \ReflectionClass(Tenant::class))->getReflectionConstants() as $constant) {
            if (str_starts_with($constant->getName(), 'STATUS_')) {
                $status = (string) $constant->getValue();
                yield $status => [$status];
            }
        }
    }

    /** **T-U7:** each status: handled exactly when `Tenant::statusPermitsLogin()` says so; otherwise Unrecoverable, handler not reached. */
    #[Test]
    #[DataProvider('tenantStatuses')]
    public function aMessageForANonActiveTenantIsDeadLettered(string $status): void
    {
        $tenant = Tenant::STATUS_SENTINEL === $status
            ? SchemaManifest::SENTINEL_TENANT_ID
            : $this->seedTenant(self::SLUG_PREFIX.'gate-'.$status, $status);
        self::assertSame($status, $this->fetchAsOwnerForTenant($tenant, 'SELECT status FROM tenant WHERE id = ?::uuid', [$tenant])[0]['status'] ?? null, 'Control: the workspace does not carry the status under test.');

        $this->passed = [];
        try {
            $this->bus()->dispatch(self::received($tenant));
            $handled = true;
        } catch (UnrecoverableMessageHandlingException) {
            $handled = false;
        }

        self::assertSame(Tenant::statusPermitsLogin($status), $handled, "A workspace in status \"$status\" was ".($handled ? 'handled' : 'refused').'.');
        self::assertCount($handled ? 1 : 0, $this->passed, 'The handler side saw the wrong number of envelopes.');
        if ($handled) {
            // U-C1b (final RED M-3): the handler is handed the workspace the gate checked — the stamp's, never the payload's.
            $arguments = $this->passed[0]->last(HandlerArgumentsStamp::class);
            self::assertInstanceOf(HandlerArgumentsStamp::class, $arguments, 'The gate did not hand the handler its workspace.');
            self::assertSame([$tenant], $arguments->getAdditionalArguments());
        }
    }

    /** **T-U7:** a stamp naming no workspace at all is parked; so is a received message without exactly one stamp. */
    #[Test]
    public function aStampNamingNoWorkspaceIsParked(): void
    {
        foreach ([[new TenantIdStamp(Uuid::v4()->toRfc4122())], []] as $stamps) {
            try {
                $this->bus()->dispatch(new Envelope(new TenantProbe(new ProbeId('t')), [new ReceivedStamp('async'), ...$stamps]));
                self::fail('A workspace message with no workspace behind its stamp was handled.');
            } catch (UnrecoverableMessageHandlingException) {
            }
        }
        self::assertSame([], $this->passed);
    }

    /** A dispatch and a pre-authentication message pass straight through, and the worker connection is not touched. */
    #[Test]
    public function aDispatchAndAPreAuthenticationMessagePassWithoutAStatement(): void
    {
        $refusing = new class extends Connection {
            public function __construct()
            {
            }

            public function fetchOne(string $query, array $params = [], array $types = []): mixed
            {
                throw new \LogicException('The gate read the worker connection on a message it must not gate.');
            }
        };
        $bus = $this->bus($refusing);
        $bus->dispatch(new CataloguedProbe(new ProbeId('p')));
        $bus->dispatch(new Envelope(new CataloguedProbe(new ProbeId('p')), [new ReceivedStamp('async')]));
        $tenants = self::getContainer()->get(\App\Platform\Tenancy\TenantContext::class);
        \assert($tenants instanceof \App\Platform\Tenancy\TenantContext);
        $tenants->run(Uuid::v4()->toRfc4122(), fn (): Envelope => $bus->dispatch(new TenantProbe(new ProbeId('t'))));
        self::assertCount(3, $this->passed);
    }

    /**
     * **T-U7c (`u-c1-final-red.md` C-1):** a kernel whose worker DSN is EMPTY — the web process's shape — dispatches through
     * the real `messenger.bus.default` to the in-memory transport and nothing throws. The bus builds every middleware on a
     * dispatch, the gate included; an eagerly injected worker connection throws there (measured: `ConnectionException`), the dispatcher's
     * `catch` swallows it and the reset (U-C1b: the invitation) is never queued while the caller still gets its 202 (201).
     * Planted probes cannot be used: `MessageClassAllowlistMiddleware` refuses them before the gate is ever built, so the
     * child dispatches the real catalogue members. Positive control in the child: building the worker connection itself
     * throws, so the environment really is the web one.
     */
    #[Test]
    public function theWebKernelDispatchesWithNoWorkerDsn(): void
    {
        $run = self::child(<<<'PHP'
            // Booted the way every kernel test boots one: `KernelTestCase` resolves the class from `KERNEL_CLASS`
            // (phpunit.dist.xml), so no test file names the kernel (EntryPointScanTest rule 2 is not widened).
            $booter = new class('child') extends Symfony\Bundle\FrameworkBundle\Test\KernelTestCase {
                public static function container(): Symfony\Component\DependencyInjection\ContainerInterface
                {
                    static::bootKernel(['environment' => 'test', 'debug' => true]);

                    return static::getContainer();
                }
            };
            $c = $booter::container();
            $out = ['worker_builds' => null, 'sent' => [], 'error' => null];
            try {
                $c->get('doctrine.dbal.worker_connection');
                $out['worker_builds'] = true;
            } catch (\Throwable $e) {
                $out['worker_builds'] = $e::class;
            }
            try {
                $c->get('messenger.default_bus')->dispatch(new App\Modules\UserManagement\Application\Contract\Message\PasswordResetRequested(
                    App\Modules\UserManagement\Domain\WorkspaceSlug::fromInput('probe-workspace'),
                    App\Modules\UserManagement\Domain\EmailAddress::fromInput('probe@example.test'),
                    null,
                    new \DateTimeImmutable('@'.time()),
                    App\Modules\UserManagement\Domain\MessageId::new(),
                ));
            } catch (\Throwable $e) {
                $out['error'] = $e::class.': '.$e->getMessage();
            }
            // U-C1b: the first workspace message, dispatched as `CreateUser` dispatches it — inside a tenancy transaction,
            // so the stamp middleware stamps it (no database statement beyond the transaction's own set_config).
            try {
                $c->get(App\Platform\Tenancy\TenantContext::class)->run(App\Platform\Tenancy\SchemaManifest::SENTINEL_TENANT_ID, function () use ($c): void {
                    $c->get('messenger.default_bus')->dispatch(new App\Modules\UserManagement\Application\Contract\Message\IssueInvitation(
                        new App\Modules\UserManagement\Domain\UserId(Symfony\Component\Uid\Uuid::v4()->toRfc4122()),
                        App\Modules\UserManagement\Domain\MessageId::new(),
                    ));
                });
            } catch (\Throwable $e) {
                $out['error'] = ($out['error'] ?? '').$e::class.': '.$e->getMessage();
            }
            foreach ($c->get('messenger.transport.async')->getSent() as $envelope) {
                $out['sent'][] = $envelope->getMessage()::class;
            }
            echo json_encode($out);
            PHP);

        self::assertNotSame(true, $run['worker_builds'] ?? true, 'Control: the child could build the worker connection, so its DSN is not empty and this test proves nothing.');
        self::assertNull($run['error'], 'A dispatch in a kernel with no worker DSN threw: '.(string) $run['error']);
        self::assertSame([
            \App\Modules\UserManagement\Application\Contract\Message\PasswordResetRequested::class,
            \App\Modules\UserManagement\Application\Contract\Message\IssueInvitation::class,
        ], $run['sent'], 'The reset request and the invitation were not both queued.');
    }

    // ------------------------------------------------------------------------------------------------------------------

    private function bus(?Connection $worker = null): MessageBus
    {
        $worker ??= self::getContainer()->get('test.doctrine.dbal.worker_connection');
        \assert($worker instanceof Connection);
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($em instanceof \Doctrine\ORM\EntityManagerInterface);
        $passed = &$this->passed;

        return new MessageBus([
            new TenantStampMiddleware($em),
            new TenantStatusGateMiddleware($worker),
            new class($passed) implements MiddlewareInterface {
                /** @param list<Envelope> $passed */
                public function __construct(private array &$passed)
                {
                }

                public function handle(Envelope $envelope, StackInterface $stack): Envelope
                {
                    $this->passed[] = $envelope;

                    return $envelope;
                }
            },
        ]);
    }

    private static function received(string $tenant): Envelope
    {
        return new Envelope(new TenantProbe(new ProbeId('t')), [new ReceivedStamp('async'), new TenantIdStamp($tenant)]);
    }

    /** @return array<string, mixed> the child's JSON answer */
    private static function child(string $code): array
    {
        $backend = \dirname(__DIR__, 2);
        $env = getenv();
        unset($env['WORKER_DATABASE_URL'], $env['SYMFONY_DOTENV_VARS']);
        $env['APP_ENV'] = 'test';
        $env['KERNEL_CLASS'] = (string) $_SERVER['KERNEL_CLASS'];
        // `proc_open()` drops an empty-valued variable (measured), so the child sets it itself, before Dotenv runs; Dotenv
        // never overrides a variable that is already set.
        $script = '<?php putenv("WORKER_DATABASE_URL="); $_SERVER["WORKER_DATABASE_URL"] = $_ENV["WORKER_DATABASE_URL"] = ""; require '.var_export($backend.'/tests/bootstrap.php', true).";\n".$code;
        $file = tempnam(sys_get_temp_dir(), 'web-dispatch');
        file_put_contents($file, $script);
        try {
            $process = proc_open([\PHP_BINARY, $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $backend, $env);
            self::assertIsResource($process);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            $exit = proc_close($process);
        } finally {
            @unlink($file);
        }
        self::assertSame(0, $exit, "The child failed:\n".substr($stdout.$stderr, -2000));
        $decoded = json_decode(substr($stdout, (int) strrpos($stdout, '{"worker_builds"')), true);
        self::assertIsArray($decoded, "The child printed no answer:\n".substr($stdout.$stderr, -2000));

        return $decoded;
    }
}

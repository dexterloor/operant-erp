<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Modules\UserManagement\Infrastructure\AuthTrailWriter;
use App\Platform\Tenancy\DatabaseRoles;
use App\Tests\Support\PhpSourceScan;
use App\Tests\Support\RegistryConnectionPlant;
use App\Tests\Support\UnboundConnectionProbe;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\Persistence\ConnectionRegistry;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * **`ARCHITECTURE.md` A-10 as reworded by the owner ruling on API-O-27
 * (2026-09-14): a class outside `Platform\Tenancy` that takes a
 * `Doctrine\DBAL\Connection` in its constructor has an explicit per-principal
 * binding in `config/services.yaml`.**
 *
 * The binding is ADR-006's control, not a wiring style: which database role a
 * statement runs as is decided by which connection object a class holds, and
 * deptrac cannot see a binding — it only registers the reference (`deptrac.yaml`,
 * `# A-10`). So the rule is checked here, three ways:
 *
 *  1. **declared** — the service's own entry in `config/services.yaml` binds
 *     that constructor argument, by name, to `@doctrine.dbal.<name>_connection`,
 *     `<name>` being a configured connection other than `owner` (the schema
 *     owner "never serves requests", `config/packages/doctrine.yaml`);
 *  2. **wired** — the object the container injected is that connection;
 *  3. **connected as** — `SELECT current_user` on it is the principal the
 *     connection name stands for.
 *
 * **What an unbound `Connection` becomes is measured, not stated** —
 * {@see self::anUnboundAutowiredConnectionIsTheSchemaOwner}. The comments in
 * `config/services.yaml` said it would be `erp_app`; it is not.
 *
 * **Named-argument aliases are not bindings.** DoctrineBundle registers
 * `Connection $appConnection`, `$authlogConnection`, … as autowiring aliases,
 * so a constructor parameter spelled that way reaches the right connection with
 * no entry in `services.yaml`. It is still red here: the rule is that the choice
 * is an edit someone makes in one reviewed file, not a consequence of a
 * variable name.
 *
 * **A fourth check (RED review, 2026-09-14):** no class outside
 * `Platform\Tenancy` takes a connection through a route no binding can pin —
 * `ConnectionRegistry` / `ManagerRegistry` (whose `getConnection()` is the
 * schema owner, measured in the test) or `Doctrine\DBAL\Driver\Connection` —
 * {@see self::noClassOutsideTenancyTakesAConnectionThroughARegistryOrTheDriver},
 * with a planted `Tests\Support\RegistryConnectionPlant` as its control.
 *
 * **Still not seen:** a connection fetched by service id from a container or
 * service locator; one built with `DriverManager::getConnection()`; setter or
 * `#[Required]` injection (only constructors are read); a `Connection` reached
 * through another object's getter other than the entity manager's.
 *
 * **Out of scope, named:** `Inventory\Application\ItemWriter` type-hints
 * `Connection` only on private methods fed by `$em->getConnection()` — no
 * constructor injection, nothing to bind. It is S-7(b)'s residual (raw SQL on
 * the entity manager's connection, which A-10 does not reach), and its writes
 * are covered by S-7(c)'s `WriteOwnershipTest`. `Platform\Tenancy` is exempt by
 * A-10's own wording.
 */
final class ConnectionBindingTest extends KernelTestCase
{
    /**
     * The constructor-injected `Connection`s in `src/` outside
     * `Platform\Tenancy` on 2026-09-14 at `18a4750`: nine, the nine
     * constructor-injected edges of deptrac's A-10 register (its tenth,
     * `ItemWriter`, is a method type hint). The exact count, so a derivation
     * that stops finding one is red.
     */
    private const CONSTRUCTOR_INJECTED_FLOOR = 9;

    /** Connection name => the principal it must connect as. `owner` is deliberately absent. */
    private const PRINCIPALS = [
        'app' => DatabaseRoles::APP,
        'ai' => DatabaseRoles::AI,
        'auth' => DatabaseRoles::AUTH,
        'authlog' => DatabaseRoles::AUTH_LOG,
        'ratelimit' => DatabaseRoles::RATE_LIMIT,
    ];

    #[Test]
    public function everyConstructorInjectedConnectionIsExplicitlyBoundToAPrincipal(): void
    {
        $container = self::getContainer();
        $injected = self::constructorInjectedConnections();

        self::assertGreaterThanOrEqual(self::CONSTRUCTOR_INJECTED_FLOOR, \count($injected),
            'The derivation found '.\count($injected).' constructor-injected Connection parameters in src/, fewer than the '
            .self::CONSTRUCTOR_INJECTED_FLOOR.' there were when this floor was set, so it is not seeing the tree.');
        self::assertContains(AuthTrailWriter::class.'::$authLog', $injected,
            'The derivation does not find AuthTrailWriter\'s $authLog, a known constructor-injected Connection.');

        self::assertSame(array_keys(self::PRINCIPALS + ['owner' => null]), array_keys(array_intersect_key(
            self::PRINCIPALS + ['owner' => null],
            $container->getParameter('doctrine.connections'),
        )), 'A connection this test maps to a principal is no longer configured in doctrine.yaml.');

        $services = Yaml::parseFile(\dirname(__DIR__, 2).'/config/services.yaml')['services'];
        $failures = [];

        foreach ($injected as $parameter) {
            [$class, $argument] = explode('::', $parameter);
            $bound = $services[$class]['arguments'][$argument] ?? null;

            if (!\is_string($bound) || 1 !== preg_match('/^@doctrine\.dbal\.([a-z_]+)_connection$/', $bound, $m)) {
                $failures[] = \sprintf('  %s has no explicit binding for %s in config/services.yaml (found: %s). An '
                    .'unbound Connection is autowired to the default connection, which is the SCHEMA OWNER — the '
                    .'role that owns the schema and runs migrations, and never serves a request.', $class, $argument, var_export($bound, true));
                continue;
            }

            $name = $m[1];
            if (!isset(self::PRINCIPALS[$name])) {
                $failures[] = \sprintf('  %s binds %s to "%s", which is not an application principal (%s). The schema '
                    .'owner never serves requests.', $class, $argument, $name, implode(', ', array_keys(self::PRINCIPALS)));
                continue;
            }

            $service = $container->get($class);
            $held = (new \ReflectionProperty($class, substr($argument, 1)))->getValue($service);
            if ($held !== $container->get('doctrine.dbal.'.$name.'_connection')) {
                $failures[] = \sprintf('  %s is bound to %s in config/services.yaml, but the container injected a '
                    .'different object.', $class, $bound);
                continue;
            }

            $role = $held->fetchOne('SELECT current_user');
            if (self::PRINCIPALS[$name] !== $role) {
                $failures[] = \sprintf('  %s is bound to the "%s" connection, which is connected as %s rather than %s.',
                    $class, $name, var_export($role, true), self::PRINCIPALS[$name]);
            }
        }

        self::assertSame([], $failures,
            "ARCHITECTURE.md A-10 / ADR-006: a constructor-injected Doctrine\\DBAL\\Connection must be bound explicitly, "
            ."per principal, in config/services.yaml.\n".implode("\n", $failures));
    }

    /**
     * **Measured 2026-09-14: an unbound autowired `Connection` is the schema
     * owner.** `config/packages/doctrine.yaml` sets `default_connection: owner`
     * so that migrations need no flag, and autowiring's `Doctrine\DBAL\Connection`
     * alias follows the default connection, not the entity manager (which is
     * pinned to `app`). The comments in `config/services.yaml` said the opposite
     * — that an autowired `Connection` "would resolve to" `erp_app` — and were
     * corrected when this was measured.
     *
     * Pinned in both halves, so a change to either is visible: the probe holds
     * the very `owner` connection object, and that connection is not connected
     * as any application principal.
     */
    #[Test]
    public function anUnboundAutowiredConnectionIsTheSchemaOwner(): void
    {
        $container = self::getContainer();
        $probe = $container->get(UnboundConnectionProbe::class);
        \assert($probe instanceof UnboundConnectionProbe);

        self::assertSame('owner', $container->getParameter('doctrine.default_connection'));
        self::assertSame($container->get('doctrine.dbal.owner_connection'), $probe->app,
            'An autowired Connection with no binding no longer resolves to the owner connection. Re-measure, and '
            .'correct this test and the config/services.yaml comments together.');

        $role = $probe->app->fetchOne('SELECT current_user');
        self::assertNotContains($role, DatabaseRoles::APPLICATION_ROLES,
            'An unbound Connection is connected as an application principal ('.$role.'), not as the schema owner.');
        self::assertSame($container->get('doctrine.dbal.app_connection')->fetchOne('SELECT current_user'), DatabaseRoles::APP,
            'The app connection is not connected as erp_app, so the comparison above has no control.');
    }

    /**
     * **The types a constructor can reach a connection through that no binding
     * in `config/services.yaml` can pin to a principal** (RED review,
     * 2026-09-14). A Sales class taking `ConnectionRegistry` and calling
     * `getConnection()` passed every check above, because they look only for
     * `Doctrine\DBAL\Connection`, and the registry hands back the default
     * connection: the schema owner, measured below.
     *
     *  - `ConnectionRegistry` — and `ManagerRegistry`, which extends it and is
     *    what a Doctrine `ServiceEntityRepository` takes; none is used in `src/`
     *    today (grep, 2026-09-14), and the first one is red here by design.
     *    `ManagerRegistry` is listed for the reader only: it is a subtype, so
     *    removing it leaves the check unchanged (mutation, run: green);
     *  - `Doctrine\DBAL\Driver\Connection` — the driver-level connection under
     *    DBAL's wrapper, which carries no principal binding at all.
     *
     * `EntityManagerInterface` is **not** here: the entity manager is pinned to
     * the `app` connection in `config/packages/doctrine.yaml`.
     *
     * @var list<class-string>
     */
    private const UNBINDABLE_CONNECTION_ROUTES = [
        ConnectionRegistry::class,
        ManagerRegistry::class,
        DriverConnection::class,
    ];

    #[Test]
    public function noClassOutsideTenancyTakesAConnectionThroughARegistryOrTheDriver(): void
    {
        $container = self::getContainer();

        // The consequence, measured: the registry's default connection is the
        // schema owner, exactly as an unbound autowired Connection is.
        $registry = $container->get('doctrine');
        self::assertInstanceOf(ConnectionRegistry::class, $registry);
        self::assertSame($container->get('doctrine.dbal.owner_connection'), $registry->getConnection(),
            'ConnectionRegistry::getConnection() no longer returns the owner connection. Re-measure, and correct this '
            .'test\'s failure message together with it.');

        // The positive control, in this test: the derivation must see every
        // unbindable route on a planted class, and not the entity manager.
        self::assertSame([
            RegistryConnectionPlant::class.'::$connections',
            RegistryConnectionPlant::class.'::$managers',
            RegistryConnectionPlant::class.'::$driver',
        ], self::constructorParametersTyped([RegistryConnectionPlant::class], self::UNBINDABLE_CONNECTION_ROUTES),
            'The derivation does not see exactly the planted registry and driver-connection parameters, so the empty '
            .'result below proves nothing.');

        self::assertSame([], self::constructorParametersTyped(self::classesInSrcOutsideTenancy(), self::UNBINDABLE_CONNECTION_ROUTES),
            "ARCHITECTURE.md A-10 / ADR-006: a class outside Platform\\Tenancy takes a ConnectionRegistry, ManagerRegistry "
            ."or Doctrine\\DBAL\\Driver\\Connection in its constructor. None of these can be bound to a principal in "
            ."config/services.yaml, and ConnectionRegistry::getConnection() returns the default connection, which is the "
            ."SCHEMA OWNER — the role that owns the schema and runs migrations, and never serves a request. Take a "
            ."Doctrine\\DBAL\\Connection bound explicitly to its principal, or EntityManagerInterface (pinned to app).");
    }

    /**
     * `Class::$parameter` for every constructor parameter in `src/` typed
     * `Doctrine\DBAL\Connection`, outside `Platform\Tenancy`.
     *
     * @return list<string>
     */
    private static function constructorInjectedConnections(): array
    {
        return self::constructorParametersTyped(self::classesInSrcOutsideTenancy(), [Connection::class]);
    }

    /** @return list<class-string> every class in `src/` outside `Platform\Tenancy` */
    private static function classesInSrcOutsideTenancy(): array
    {
        $src = \dirname(__DIR__, 2).'/src/';
        $classes = [];

        foreach (PhpSourceScan::phpFilesUnder($src) as $path) {
            // PSR-4: `App\` is `src/`, so the file names its class.
            $class = 'App\\'.str_replace('/', '\\', substr($path, \strlen($src), -\strlen('.php')));
            if (!str_starts_with($class, 'App\\Platform\\Tenancy\\') && class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * `Class::$parameter` for every constructor parameter of `$classes` whose
     * type (or a member of its union) is one of `$types` or a subtype.
     *
     * @param list<class-string> $classes
     * @param list<class-string> $types
     *
     * @return list<string>
     */
    private static function constructorParametersTyped(array $classes, array $types): array
    {
        $found = [];

        foreach ($classes as $class) {
            foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
                $type = $parameter->getType();
                $names = $type instanceof \ReflectionNamedType ? [$type] : ($type instanceof \ReflectionUnionType ? $type->getTypes() : []);
                foreach ($names as $named) {
                    if ($named instanceof \ReflectionNamedType && [] !== array_filter($types, static fn (string $t): bool => is_a($named->getName(), $t, true))) {
                        $found[] = $class.'::$'.$parameter->getName();
                        break;
                    }
                }
            }
        }

        return $found;
    }
}

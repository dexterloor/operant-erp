<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Modules\UserManagement\Infrastructure\AuthTrailWriter;
use App\Platform\Tenancy\DatabaseRoles;
use App\Tests\Support\IntersectionConnectionPlant;
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
 * **Still not seen** — the blind routes, named (`docs/session10/v3-principals.md` §4.2, V3-2):
 *  - **B1** a connection fetched by service id from a container or service locator
 *    (`doctrine.dbal.owner_connection` and `database_connection` are public);
 *  - **B2** one built with `DriverManager::getConnection()` from a credential read in-process;
 *  - **B3** setter or `#[Required]` injection (only constructors are read);
 *  - **B4** a `Connection` reached through another object's getter other than the entity manager's;
 *  - **B5** intersection and DNF types — **now handled** by the walk (`IntersectionConnectionPlant` is its control);
 *  - **B6** controller action-argument injection;
 *  - **B7** the owner credential in a serving runtime's environment — see below;
 *  - **B8** a class under `App\Platform\Tenancy\`, exempt by A-10's wording;
 *  - **B9** an untyped / `object` / `mixed` parameter with `#[Autowire(service: 'database_connection')]`, a `\Closure`
 *    with `#[AutowireServiceClosure]`, or `#[AutowireCallable]`;
 *  - **B10** config-level consumers of the default connection with no class in `src/` (a DBAL cache pool, lock, session or
 *    messenger store, a second entity manager on `owner`) — none configured;
 *  - **B11** the entity manager repinned to `owner` — **now asserted directly** by
 *    {@see self::theEntityManagerIsPinnedToTheAppConnection};
 *  - **B12** vendor code calling `ManagerRegistry::getConnection()` with no name (DBAL console commands) — CLI today;
 *  - **B13** a network login with the owner's password (`pg_hba` on the direct port; the PgBouncer userlist entry was removed
 *    by V3-12 on 2026-09-16) — not a code route;
 *  - **B14** `KernelInterface $kernel` → `getContainer()->get('database_connection')`, a B1 variant;
 *  - **B15** the credential as a string (`#[Autowire(env: 'DATABASE_URL')]`, `%env(DATABASE_URL)%`) into `new \PDO` or
 *    `pg_connect()`, a B2 variant with no DBAL;
 *  - **B16** a class the walk never examines because its name does not match its file, or a second class in one file —
 *    **now closed** by {@see self::everyFileInSrcDeclaresExactlyOneClassNamedAfterIt}.
 *
 * **Serving processes are now guarded by credential absence** (V3-1(i), 2026-09-16): `public/index.php` refuses to build a
 * kernel while any environment source holds a non-application database credential in a recognised form
 * (`App\Platform\Runtime\ServingCredentialGuard`), the prod container is compiled without the owner DSN in a test
 * (`ProdContainerCredentialTest`), and `EntryPointScanTest` makes a second entry point or a direct connection loud. That is
 * the structural control for B1–B4, B6–B10, B12, B14 and B15: in a serving process each would fail closed for want of a
 * credential. This test stays the in-process check, and the owner-service recorder (`OwnerRequestWindow`) watches the
 * HTTP suite.
 *
 * **Out of scope, named:** `Inventory\Application\ItemWriter` type-hints
 * `Connection` only on private methods fed by `$em->getConnection()` — no
 * constructor injection, nothing to bind. It is S-7(b)'s residual (raw SQL on
 * the entity manager's connection, which A-10 does not reach), and its writes
 * are covered by S-7(c)'s `WriteOwnershipTest`. So is
 * `Platform\Http\Idempotency\IdempotencyEnvelopeStore` (G5-C0, 2026-10-22): its
 * PUBLIC methods take a caller-supplied `Connection` (the run's
 * `$em->getConnection()`), no constructor injection, nothing to bind — and,
 * unlike `ItemWriter`, nothing here checks which principal a caller passes; the
 * store refuses a bare connection and another tenant's record, and a caller check
 * was owed with its first caller and is built since G5-C2 (`EnvelopeStoreCallerConnectionTest`). `Platform\Tenancy` is exempt by
 * A-10's own wording.
 */
final class ConnectionBindingTest extends KernelTestCase
{
    /**
     * The constructor-injected `Connection`s in `src/` outside
     * `Platform\Tenancy` on 2026-09-14 at `18a4750`: nine, the nine
     * constructor-injected edges of deptrac's A-10 register (its tenth,
     * `ItemWriter`, is a method type hint, and so is its eleventh,
     * `IdempotencyEnvelopeStore`, since 2026-10-22). The exact count, so a derivation
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
        // Mail-C1 (owner Q-2): the mail worker's login. The handler, the token store and the reset audit writer bind it.
        'worker' => DatabaseRoles::WORKER,
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
     * **B11: the entity manager is pinned to `app`, asserted directly** (V3-2). Every raw-SQL route on
     * `$em->getConnection()` (S-7(b)) and every ORM flush runs as `erp_app` only because `config/packages/doctrine.yaml`
     * pins the default entity manager to the `app` connection; before this test that was caught only indirectly.
     * Mutation, run 2026-09-16: repin it to `owner` (Rule 17) — red.
     */
    #[Test]
    public function theEntityManagerIsPinnedToTheAppConnection(): void
    {
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.default_entity_manager');

        self::assertSame($container->get('doctrine.dbal.app_connection'), $em->getConnection(),
            'The default entity manager is not on the app connection. Every ORM flush and every $em->getConnection() '
            .'statement would run as another principal.');
        self::assertSame(DatabaseRoles::APP, $em->getConnection()->fetchOne('SELECT current_user'));
    }

    /**
     * **B16: the constructor walk examines a class only if its path-derived name exists**, so a class whose name does not
     * match its file, or a second class in one file, would be skipped and could still be wired by an explicit
     * `services.yaml` entry. Closed by asserting what PSR-4 promises: every PHP file in `src/` declares exactly one
     * class-like (class, interface, trait or enum; anonymous classes excluded), named after the file, in the namespace
     * its path gives. Plant, run 2026-09-16: a second class in one file — red.
     */
    #[Test]
    public function everyFileInSrcDeclaresExactlyOneClassNamedAfterIt(): void
    {
        $src = \dirname(__DIR__, 2).'/src/';
        $failures = [];
        $files = PhpSourceScan::phpFilesUnder($src);
        self::assertGreaterThan(100, \count($files), 'the walk found too few files in src/ to be reading the tree');

        foreach ($files as $path) {
            $expected = 'App\\'.str_replace('/', '\\', substr($path, \strlen($src), -\strlen('.php')));
            $declared = self::classLikesDeclaredIn((string) file_get_contents($path));
            if ([$expected] !== $declared) {
                $failures[] = '  '.substr($path, \strlen($src)).' declares ['.implode(', ', $declared).'], expected ['.$expected.']';
            }
        }

        self::assertSame([], $failures, "B16: a file in src/ that does not declare exactly one class named after it is invisible to the constructor walk above.\n".implode("\n", $failures));
    }

    #[Test]
    public function theConstructorWalkSeesIntersectionAndDnfTypes(): void
    {
        self::assertSame([
            IntersectionConnectionPlant::class.'::$intersection',
            IntersectionConnectionPlant::class.'::$dnf',
        ], self::constructorParametersTyped([IntersectionConnectionPlant::class], [Connection::class]),
            'B5: the constructor walk does not see a Connection inside an intersection or DNF type.');
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
                foreach (self::namedTypesOf($parameter->getType()) as $named) {
                    if ([] !== array_filter($types, static fn (string $t): bool => is_a($named->getName(), $t, true))) {
                        $found[] = $class.'::$'.$parameter->getName();
                        break;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Every named type inside a parameter type: named, union, intersection, and DNF (a union of intersections).
     *
     * @return list<\ReflectionNamedType>
     */
    private static function namedTypesOf(?\ReflectionType $type): array
    {
        if ($type instanceof \ReflectionNamedType) {
            return [$type];
        }
        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            $named = [];
            foreach ($type->getTypes() as $member) {
                array_push($named, ...self::namedTypesOf($member));
            }

            return $named;
        }

        return [];
    }

    /** @return list<string> fully-qualified names of the classes, interfaces, traits and enums a file declares */
    private static function classLikesDeclaredIn(string $code): array
    {
        $tokens = array_values(array_filter(\PhpToken::tokenize($code), static fn (\PhpToken $t): bool => !$t->is([\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT])));
        $namespace = '';
        $declared = [];
        foreach ($tokens as $i => $token) {
            if ($token->is(\T_NAMESPACE) && ($tokens[$i + 1] ?? null)?->is([\T_NAME_QUALIFIED, \T_STRING])) {
                $namespace = $tokens[$i + 1]->text;
            }
            if ($token->is([\T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM]) && ($tokens[$i + 1] ?? null)?->is(\T_STRING)
                && !($tokens[$i - 1] ?? null)?->is([\T_DOUBLE_COLON, \T_NEW])) {
                $declared[] = ('' === $namespace ? '' : $namespace.'\\').$tokens[$i + 1]->text;
            }
        }

        return $declared;
    }
}

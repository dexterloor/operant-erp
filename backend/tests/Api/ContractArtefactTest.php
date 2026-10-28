<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\HttpOperation;
use App\Modules\AuditLog\Application\ExportPermission;
use App\Modules\AuditLog\Application\ExportSources;
use App\Platform\Http\Bulk\BulkArchival;
use App\Platform\Http\Bulk\BulkArchivalPermission;
use App\Platform\Http\Bulk\BulkArchiveMembers;
use App\Platform\Http\Bulk\BulkRestorationPermission;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Security\PermissionRequirement;
use App\Tests\Support\ApiSurface;
use App\Tests\Support\CanonicalJson;
use App\Tests\Support\GeneratedSpec;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * **The committed API contract the web app builds from** (W-4; BK-5).
 *
 * Two files at the repository root, both generated, both compared here byte for byte:
 *  - `contracts/openapi.json`: the spec `api:openapi:export` produces under the test kernel, in {@see CanonicalJson}'s form;
 *  - `contracts/api-surface.json`: what the spec does not say — which resources can be exported or archived in bulk, the
 *    bulk cap, how each operation is gated and whether it needs an `Idempotency-Key`, and that every path variable is a
 *    record id ({@see ApiSurface}).
 *
 * The web app's proxy allowlist and generated types read these files without a running server, so a change to the API's
 * shape that does not regenerate them is red here. **To regenerate:** `make contracts` at the repository root (or, in
 * `backend/`, `CONTRACT_ARTEFACT_UPDATE=1 php bin/phpunit --filter ContractArtefactTest`), read the diff, commit it with
 * the change. Update mode rewrites the files and still FAILS, so no run can quietly re-baseline itself.
 *
 * The spec is read from a cold cache: `tests/bootstrap.php` gives every PHPUnit process its own empty cache directory, so
 * stale API metadata cannot produce a stale file that then matches itself ({@see self::theSpecWasGeneratedFromAColdCache}).
 *
 * A missing file is red, never skipped: an absent artefact is not evidence (API-10a).
 */
final class ContractArtefactTest extends KernelTestCase
{
    private const UPDATE = 'CONTRACT_ARTEFACT_UPDATE';

    private const REGENERATE = 'Run `make contracts` (or `CONTRACT_ARTEFACT_UPDATE=1 php bin/phpunit --filter ContractArtefactTest` in backend/), read the diff, and commit it with the change.';

    private const SECRET_NAME = '/SECRET|PASSWORD|KEY|TOKEN|DSN|DATABASE_URL|MAC/i';

    /** Generated once per process: both artefact tests and the scans read the same bytes. */
    private static ?string $spec = null;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
    }

    #[Test]
    public function theCommittedSpecIsTheGeneratedSpec(): void
    {
        $generated = self::generatedSpec();
        $document = json_decode($generated, true, 512, \JSON_THROW_ON_ERROR);
        $ids = array_keys(ApiSurface::specOperations($document));
        foreach (['CreateExport', 'CreateBulkArchival', 'GetCurrentUser'] as $known) {
            self::assertContains($known, $ids, 'Anti-vacuity: '.$known.' is not in the generated spec, so the comparison is over the wrong document.');
        }
        self::assertArrayHasKey('/api/v1/auth/token', $document['paths'], 'Anti-vacuity: the sign-in path is not in the generated spec.');
        self::assertNotEmpty(array_filter(array_keys($document['paths']), static fn (string $path): bool => !str_starts_with($path, '/api/v1/auth/')),
            'Anti-vacuity: the generated spec has no path outside /auth/.');

        self::compareOrUpdate('openapi.json', $generated, static fn (string $committed): string => self::describeSpecDifference($document, $committed));
    }

    #[Test]
    public function theCommittedApiSurfaceIsTheRegistries(): void
    {
        $built = self::buildSurface(json_decode(self::generatedSpec(), true, 512, \JSON_THROW_ON_ERROR));
        self::assertSame([], $built['problems'], "The registries and the spec disagree:\n".implode("\n", $built['problems']));

        $surface = $built['surface'];
        self::assertArrayHasKey('inventory_item', $surface['export'], 'Anti-vacuity: inventory_item is not exportable, so the export half is blind.');
        self::assertArrayHasKey('customer', $surface['bulk']['resources'], 'Anti-vacuity: customer is not bulk-archivable, so the bulk half is blind.');
        self::assertTrue($surface['operations']['CreateBulkArchival']['idempotency_key_required'] ?? null, 'Anti-vacuity: CreateBulkArchival does not read as requiring an Idempotency-Key.');
        self::assertSame(['inventory_item.view'], $surface['operations']['ListItems']['permission']['codes'] ?? null, 'Anti-vacuity: ListItems is not read as gated on inventory_item.view.');
        self::assertContains('id', $surface['pathParameters']['names'], 'Anti-vacuity: no {id} path variable was found.');
        self::assertContains('lineId', $surface['pathParameters']['names'], 'Anti-vacuity: no {lineId} path variable was found.');

        $json = CanonicalJson::encode(json_encode($surface, \JSON_THROW_ON_ERROR));
        self::compareOrUpdate('api-surface.json', $json, static fn (): string => 'contracts/api-surface.json differs from what the registries derive.');
    }

    /** Each check in the pure builder can bite (planted inputs; RED H-2, M-2, M9, M15). */
    #[Test]
    public function aPlantedSurfaceShowsEachCheckBites(): void
    {
        $spec = ['paths' => [
            '/things' => ['get' => ['operationId' => 'ListThings']],
            '/things/{id}' => ['delete' => ['operationId' => 'DeleteThing', 'parameters' => [['name' => 'id', 'in' => 'path']]]],
            '/things/{code}/restoration' => ['post' => ['operationId' => 'RestoreThing', 'parameters' => [['name' => 'code', 'in' => 'path']]]],
            '/bulk-archivals' => ['post' => ['operationId' => 'CreateBulkArchival', 'requestBody' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/B']]]]]],
            '/bulk-restorations' => ['post' => ['operationId' => 'CreateBulkRestoration', 'requestBody' => ['content' => ['application/json' => ['schema' => ['properties' => ['ids' => ['maxItems' => 49]]]]]]]],
            '/unregistered' => ['get' => ['operationId' => 'ListGhosts']],
            '/things/{id}/archive' => ['post' => ['operationId' => 'ArchiveThing', 'parameters' => [['name' => 'thingId', 'in' => 'path']]]],
        ], 'components' => ['schemas' => ['B' => ['properties' => ['ids' => ['maxItems' => 50]]]]]];
        $facts = static fn (string ...$codes): array => ['kind' => 'all_of', 'codes' => $codes, 'idempotency' => false];
        $registry = [
            'ListThings' => $facts('thing.view'),
            'DeleteThing' => $facts('thing.delete'),
            'RestoreThing' => $facts('thing.delete'),
            'CreateBulkArchival' => ['kind' => 'derived', 'codes' => [], 'idempotency' => true],
            'CreateBulkRestoration' => ['kind' => 'derived', 'codes' => [], 'idempotency' => true],
            'ArchiveThing' => $facts('thing.delete'),
        ];

        $problems = ApiSurface::build(
            $spec,
            $registry,
            ['thing' => 'ListThings', 'ghost' => 'ListPhantoms'],
            ['thing' => 'thing.edit', 'ghost' => 'ghost.view'],
            ['thing' => ['delete' => 'DeleteThing', 'restore' => 'RestoreThing', 'list' => 'ListThings']],
            ['thing' => 'thing.delete'],
            ['thing' => 'thing.delete'],
            50,
        )['problems'];

        $expected = [
            'ListGhosts is in the spec but no operation',
            'export.ghost names ListPhantoms, which is not an operation in the spec',
            'export.thing: ListThings declares thing.view but the export gate resolves thing.edit',
            'CreateBulkRestoration declares ids.maxItems 49; the enforced cap is 50',
            'RestoreThing has a path variable {code}',
            'ArchiveThing: the path template names {id} but the declared path parameters are {thingId}',
        ];
        foreach ($expected as $fragment) {
            self::assertNotEmpty(array_filter($problems, static fn (string $p): bool => str_contains($p, $fragment)), 'The builder did not report: '.$fragment."\nIt reported:\n".implode("\n", $problems));
        }
        self::assertCount(\count($expected), $problems, "The builder reported something unplanted:\n".implode("\n", $problems));
    }

    #[Test]
    public function theCanonicalFormIsStable(): void
    {
        $input = '{"z":{"b":1,"a":[3,1,2]},"e":{},"l":[],"n":{"1":"x","0":"y"},"s":"a\/b é","f":0.1,"g":1.0,"i":2}';
        $expected = <<<'JSON'
            {
                "e": {},
                "f": 0.1,
                "g": 1.0,
                "i": 2,
                "l": [],
                "n": {
                    "0": "y",
                    "1": "x"
                },
                "s": "a/b é",
                "z": {
                    "a": [
                        3,
                        1,
                        2
                    ],
                    "b": 1
                }
            }

            JSON;

        $once = CanonicalJson::encode($input);
        self::assertSame($expected, $once, 'The canonical form changed: {} must stay an object, "0"/"1" keys an object, lists keep their order, keys sort, 1.0 keeps its fraction.');
        self::assertSame($once, CanonicalJson::encode($once), 'The canonical form is not a fixed point.');
    }

    #[Test]
    public function theArtefactsNameNoHostAndNoSecret(): void
    {
        self::assertSame(['FAKE_SECRET'], self::secretsIn(['FAKE_SECRET' => 'abcdefgh123', 'PATH' => 'abcdefgh123', 'SHORT_KEY' => 'abc'], 'x abcdefgh123 abc y'),
            'Planted control: the scanner does not report a secret-named value present in the text.');

        $environment = $_SERVER + $_ENV + getenv();
        $scanned = array_filter($environment, static fn (mixed $value, int|string $name): bool => str_contains((string) $name, 'DATABASE_URL') && \is_string($value) && \strlen($value) >= 8, \ARRAY_FILTER_USE_BOTH);
        self::assertNotEmpty($scanned, 'Anti-vacuity: no DATABASE_URL-named value is visible, so the scan reads the wrong environment.');

        foreach (self::scanned() as $file => $bytes) {
            self::assertSame([], self::secretsIn($environment, $bytes), $file.' contains the value of these environment variables (names only): ');
        }

        $spec = json_decode(self::artefacts()['openapi.json'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertNotEmpty($spec['servers'] ?? [], 'Anti-vacuity: the spec declares no servers, so the host check holds over nothing.');
        foreach ($spec['servers'] as $server) {
            $url = (string) ($server['url'] ?? '');
            self::assertDoesNotMatchRegularExpression('#^([a-z][a-z0-9+.-]*:|//)#i', $url, 'contracts/openapi.json names a host in servers[]: it must be relative.');
        }
    }

    /** Standing Rule 17, mechanised: the spec above was not read from a cache left by an earlier run. */
    #[Test]
    public function theSpecWasGeneratedFromAColdCache(): void
    {
        self::assertTrue(\defined('ERP_TEST_CACHE_DIR'), 'tests/bootstrap.php no longer gives this process its own cache directory.');
        $dir = (string) \constant('ERP_TEST_CACHE_DIR');
        self::assertStringStartsWith($dir.'/', self::$kernel?->getCacheDir() ?? '', 'The kernel reads a cache outside this process\'s fresh directory: a stale API shape can feed every spec test.');
        self::assertSame(1, preg_match('/^erp-phpunit-cache-(\d+)-[0-9a-f]{8}$/', basename($dir), $match), 'The cache directory is not one the bootstrap created.');
        self::assertSame((string) getmypid(), $match[1], 'The cache directory was not created by this PHPUnit process.');
    }

    /**
     * The artefacts are public: they must not name the tools or roles used to build this project. The set is the public
     * export's content rule (`check.sh`, `CONTENT_BAD`) plus four names; a change to one is a reason to re-read the other.
     * Product words (the query and reorder features) are allowed. Needles are split so this file does not contain them.
     */
    #[Test]
    public function theArtefactsCarryNoInternalToolNames(): void
    {
        $planted = json_encode(['a' => ['b' => 'see the '.'OR'.'CH'.' notes', 'c' => 'reorder suggestions']], \JSON_THROW_ON_ERROR);
        self::assertSame(['$.a.b'], array_map(static fn (string $hit): string => explode(' ', $hit)[0], self::internalNamesIn($planted)),
            'Planted control: the matcher does not report a planted name.');

        foreach (self::scanned() as $file => $bytes) {
            self::assertSame([], self::internalNamesIn($bytes), $file.' names an internal tool or role.');
        }
    }

    /**
     * @param array<int|string, mixed> $environment
     *
     * @return list<string> the NAMES whose secret-shaped value occurs in the text (never the values)
     */
    public static function secretsIn(array $environment, string $text): array
    {
        $names = [];
        foreach ($environment as $name => $value) {
            if (\is_string($value) && \strlen($value) >= 8 && 1 === preg_match(self::SECRET_NAME, (string) $name) && str_contains($text, $value)) {
                $names[] = (string) $name;
            }
        }
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /** @return list<string> one entry per hit: "<json path> <needle>" */
    public static function internalNamesIn(string $json): array
    {
        $needles = [
            '/cla'.'ude/i', '/anthr'.'opic/i', '/\bOR'.'CH\b/i', '/RERUN '.'agent/i', '/agen'.'ts read this file/i',
            '/BE-'.'LEAD/i', '/\.agen'.'t-work/i', '/CLA'.'UDE\.md/i', '/\.cla'.'ude\//i',
        ];
        $hits = [];
        $walk = static function (mixed $node, string $path) use (&$walk, &$hits, $needles): void {
            if (\is_array($node)) {
                foreach ($node as $key => $child) {
                    foreach ($needles as $needle) {
                        if (\is_string($key) && 1 === preg_match($needle, $key)) {
                            $hits[] = $path.'.'.$key.' '.$needle;
                        }
                    }
                    $walk($child, $path.'.'.$key);
                }

                return;
            }
            foreach ($needles as $needle) {
                if (\is_string($node) && 1 === preg_match($needle, $node)) {
                    $hits[] = $path.' '.$needle;
                }
            }
        };
        $walk(json_decode($json, true, 512, \JSON_THROW_ON_ERROR), '$');

        return $hits;
    }

    private static function generatedSpec(): string
    {
        return self::$spec ??= CanonicalJson::encode(GeneratedSpec::rawDocument(self::$kernel ?? throw new \LogicException('The kernel is booted in setUp().')));
    }

    /**
     * @param array<string, mixed> $spec
     *
     * @return array{surface: array<string, mixed>, problems: list<string>}
     */
    private static function buildSurface(array $spec): array
    {
        $operations = TransactionBoundaryTest::contractOperations();
        $registry = [];
        foreach ($operations as $name => $operation) {
            $requirement = PermissionRequirement::of($operation);
            $registry[$name] = [
                'kind' => $requirement->kind(),
                'codes' => PermissionRequirement::ALL_OF === $requirement->kind() ? $requirement->codes() : [],
                'idempotency' => true === ($operation->getExtraProperties()[IdempotencyKeyGuard::OPERATION_FLAG] ?? null),
            ];
        }

        $exportLists = [];
        foreach (self::service(ExportSources::class)->all() as $resource => $source) {
            $exportLists[$resource] = $source->listOperationName();
        }

        $members = [];
        foreach (self::service(BulkArchiveMembers::class)->all() as $resource => $member) {
            $list = BulkAdmissibleSetTest::listNameOf($member->deleteOperationName());
            self::assertArrayHasKey($list, $operations, 'bulk.'.$resource.': its archived list '.$list.' is not in the registry.');
            self::assertTrue(self::declaresArchived($operations[$list]), 'bulk.'.$resource.': '.$list.' declares no `archived` parameter.');
            $members[$resource] = ['delete' => $member->deleteOperationName(), 'restore' => $member->restoreOperationName(), 'list' => $list];
        }

        return ApiSurface::build(
            $spec,
            $registry,
            $exportLists,
            self::service(ExportPermission::class)->codesByResource(),
            $members,
            self::service(BulkArchivalPermission::class)->codesByResource(),
            self::service(BulkRestorationPermission::class)->codesByResource(),
            BulkArchival::MAXIMUM_MEMBERS,
        );
    }

    private static function declaresArchived(HttpOperation $list): bool
    {
        foreach ($list->getParameters() ?? [] as $key => $parameter) {
            if ('archived' === (string) ($parameter->getKey() ?? $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private static function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        \assert($service instanceof $class);

        return $service;
    }

    /**
     * What the two scans read: the committed files AND what would be committed now, so a planted value in the source is
     * red before anyone regenerates, not only after.
     *
     * @return array<string, string> label => bytes
     */
    private static function scanned(): array
    {
        $scanned = [];
        foreach (self::artefacts() as $file => $bytes) {
            $scanned['contracts/'.$file] = $bytes;
        }
        $scanned['the generated spec'] = self::generatedSpec();
        $scanned['the generated api surface'] = CanonicalJson::encode(json_encode(self::buildSurface(json_decode(self::generatedSpec(), true, 512, \JSON_THROW_ON_ERROR))['surface'], \JSON_THROW_ON_ERROR));

        return $scanned;
    }

    /** @return array<string, string> file name => committed bytes (a missing file is red) */
    private static function artefacts(): array
    {
        $files = [];
        foreach (['openapi.json', 'api-surface.json'] as $file) {
            $path = self::contractsDirectory().'/'.$file;
            self::assertFileExists($path, 'contracts/'.$file.' is missing. '.self::REGENERATE);
            $files[$file] = (string) file_get_contents($path);
        }

        return $files;
    }

    private static function contractsDirectory(): string
    {
        return \dirname(__DIR__, 3).'/contracts';
    }

    /** @param \Closure(string): string $describe */
    private static function compareOrUpdate(string $file, string $generated, \Closure $describe): void
    {
        $path = self::contractsDirectory().'/'.$file;
        $update = getenv(self::UPDATE);

        if (false !== $update && '' !== $update) {
            if (!is_dir(\dirname($path))) {
                mkdir(\dirname($path), 0o755);
            }
            file_put_contents($path, $generated);
            self::fail('contracts/'.$file.' was rewritten ('.self::UPDATE.' is set). Read the diff, then re-run without '.self::UPDATE.'.');
        }

        self::assertFileExists($path, 'contracts/'.$file.' is missing. '.self::REGENERATE);
        $committed = (string) file_get_contents($path);
        if ($committed !== $generated) {
            self::fail($describe($committed)."\nThe API's shape changed and contracts/".$file." was not regenerated. The web app's proxy allowlist and generated types read this file. ".self::REGENERATE);
        }
        self::assertSame(hash('sha256', $generated), hash('sha256', $committed));
    }

    /** @param array<string, mixed> $generated */
    private static function describeSpecDifference(array $generated, string $committed): string
    {
        $old = json_decode($committed, true);
        if (!\is_array($old) || !\is_array($old['paths'] ?? null)) {
            return 'contracts/openapi.json is not an OpenAPI document.';
        }
        $newIds = array_keys(ApiSurface::specOperations($generated));
        $oldIds = array_keys(ApiSurface::specOperations($old));
        $lines = [];
        if ([] !== $added = array_values(array_diff($newIds, $oldIds))) {
            $lines[] = 'Operations added: '.implode(', ', $added);
        }
        if ([] !== $removed = array_values(array_diff($oldIds, $newIds))) {
            $lines[] = 'Operations removed: '.implode(', ', $removed);
        }
        $paths = array_unique([...array_keys($generated['paths']), ...array_keys($old['paths'])]);
        sort($paths, \SORT_STRING);
        foreach ($paths as $path) {
            if (($generated['paths'][$path] ?? null) !== ($old['paths'][$path] ?? null)) {
                $lines[] = 'First differing path: '.$path;
                break;
            }
        }
        if ([] === $lines) {
            $schemas = array_unique([...array_keys($generated['components']['schemas'] ?? []), ...array_keys($old['components']['schemas'] ?? [])]);
            sort($schemas, \SORT_STRING);
            foreach ($schemas as $schema) {
                if (($generated['components']['schemas'][$schema] ?? null) !== ($old['components']['schemas'][$schema] ?? null)) {
                    $lines[] = 'First differing schema: components.schemas.'.$schema;
                    break;
                }
            }
        }
        if ([] === $lines) {
            $lines[] = 'The paths and schemas agree; the difference is elsewhere (info, servers, tags) or in byte form.';
        }

        return implode("\n", $lines);
    }
}

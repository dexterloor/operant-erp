<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\AuditLog\Application\RecordHistorySubjects;
use App\Tests\Support\ApiReference;
use App\Tests\Support\GeneratedSpec;
use App\Tests\Support\PublishedSpecText;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * **The committed API reference is the one the spec generates** (2b, S3): `contracts/reference/`, written by
 * {@see ApiReference} from the spec and surface the server builds now (the same values `ContractArtefactTest` compares),
 * never from the committed files, so it cannot agree with a stale `contracts/openapi.json`.
 *
 * **To regenerate:** `make contracts` at the repository root. Under `CONTRACT_ARTEFACT_UPDATE` this writes every
 * generated file, deletes any other file in the folder (dot-files aside), and fails once, listing what it changed: no
 * run can quietly re-baseline itself.
 *
 * Each operation's module is read from its resource class's namespace (`App\Modules\<Module>\…`, `App\Platform\Http\Bulk\…`).
 * Whether GitHub's own heading anchors match {@see ApiReference::slug()} is checked by opening the folder on github.com
 * once; the link test here proves only that every link names a heading the generator wrote by that rule.
 */
final class ApiReferenceTest extends KernelTestCase
{
    private const UPDATE = 'CONTRACT_ARTEFACT_UPDATE';

    /** @var array<string, string>|null */
    private static ?array $files = null;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
    }

    /** *fails if:* a committed reference file differs from what the spec generates now. */
    #[Test]
    public function theCommittedReferenceIsTheGeneratedOne(): void
    {
        $generated = self::generated();
        self::assertArrayHasKey(ApiReference::README, $generated, 'Anti-vacuity: the generator wrote no README.');
        $directory = self::directory();
        $update = getenv(self::UPDATE);

        if (false !== $update && '' !== $update) {
            if (!is_dir($directory)) {
                mkdir($directory, 0o755, true);
            }
            $changed = [];
            foreach ($generated as $file => $bytes) {
                if (!is_file($directory.'/'.$file) || (string) file_get_contents($directory.'/'.$file) !== $bytes) {
                    file_put_contents($directory.'/'.$file, $bytes);
                    $changed[] = 'wrote '.$file;
                }
            }
            foreach (self::committedNames() as $file) {
                if (!isset($generated[$file])) {
                    unlink($directory.'/'.$file);
                    $changed[] = 'deleted '.$file;
                }
            }
            self::fail('contracts/reference/ was regenerated ('.self::UPDATE.' is set): '.([] === $changed ? 'nothing changed' : implode(', ', $changed)).'. Read the diff, then re-run without '.self::UPDATE.'.');
        }

        foreach ($generated as $file => $bytes) {
            self::assertFileExists($directory.'/'.$file, 'contracts/reference/'.$file.' is missing. Run `make contracts`.');
            self::assertTrue((string) file_get_contents($directory.'/'.$file) === $bytes, 'contracts/reference/'.$file.' is not what the spec generates: the API changed and the reference was not regenerated. Run `make contracts`, read the diff, commit it with the change.');
        }
    }

    /** *fails if:* the folder holds a file the generator does not write, or lacks one it does (dot-files aside). */
    #[Test]
    public function theReferenceFolderHoldsExactlyTheGeneratedFiles(): void
    {
        $expected = array_keys(self::generated());
        sort($expected);
        self::assertSame($expected, self::committedNames(), 'contracts/reference/ does not hold exactly the generated files. Run `make contracts`.');
    }

    /** *fails if:* the generator stops rendering a kind of part, or renders the same input differently twice. */
    #[Test]
    public function aPlantedSpecRendersEveryPart(): void
    {
        [$spec, $surface, $modules, $readCodes] = self::planted();
        $files = ApiReference::render($spec, $surface, $modules, $readCodes);
        self::assertSame($files, ApiReference::render($spec, $surface, $modules, $readCodes), 'Two renders of one input differ.');
        self::assertSame(['README.md', 'audit-log-and-export.md', 'customers.md', 'schemas.md'], array_keys($files));

        $customers = $files['customers.md'];
        foreach ([
            '| `q` | query | string | no | Search the things. |' => 'a query parameter',
            '**Request body:** [Thing](schemas.md#thing) (`application/json`) — The thing to create.' => 'a request body',
            '- **201** The thing this request created. [Thing](schemas.md#thing) (`application/json`) Header `Location`: The address.' => 'a 201 with Location',
            '[404](README.md#404)' => 'a shared refusal as a link',
            '409 (Its own words.)' => 'an own refusal text inline',
            '**Who may call it:** `thing.view`' => 'an all_of permission',
            '**Who may call it:** No sign-in.' => 'a security: [] operation',
            '**Who may call it:** No permission code: any signed-in person.' => 'a none permission with a token',
            '**Who may call it:** The permission depends on `resource`:' => 'a derived permission',
            '| `thing` | `thing.delete` |' => 'the derived table',
            '**Idempotency-Key:**' => 'the key requirement',
        ] as $needle => $part) {
            self::assertStringContainsString($needle, $customers, 'The planted page does not render '.$part.'.');
        }
        self::assertLessThan(strpos($customers, '## Get thing'), strpos($customers, '## List things'), 'Operations are not sorted by path then method: GET /things comes before GET /things/{id}.');
        self::assertStringContainsString('| `lines[].quantity` | string (`decimal`) | yes | no |', $files['schemas.md'], 'A nested field is not flattened.');
        self::assertStringContainsString('## Thing.UpdateThingInput', $files['schemas.md'], 'The .jsonMergePatch suffix is not dropped.');
        self::assertStringContainsString('| `invoice` | `invoice.view` |', $files['audit-log-and-export.md'], 'The subject-type read permissions are not rendered.');
        self::assertStringContainsString('| `customer` | `customer.view` |', $files['audit-log-and-export.md'], 'The subject-type read permissions are not rendered.');
        self::assertStringContainsString('### 404', $files['README.md'], 'A shared refusal sentence is not printed once in the README.');

        $modules['ListThings'] = 'Ghosts';
        try {
            ApiReference::render($spec, $surface, $modules, $readCodes);
            self::fail('An operation in an unknown module was rendered.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('Ghosts', $e->getMessage());
        }
    }

    /** *fails if:* an operation is missing or written twice, a link names no heading, or a file writes one heading twice. */
    #[Test]
    public function everyOperationAppearsOnceAndEveryLinkResolves(): void
    {
        $files = self::generated();
        $spec = GeneratedSpec::operations(self::spec());
        $written = [];
        foreach ($files as $file => $markdown) {
            if (ApiReference::README === $file || ApiReference::SCHEMAS === $file) {
                continue;
            }
            preg_match_all('/^`[A-Z]+ \S+` · `([A-Za-z]+)`$/m', $markdown, $ids);
            array_push($written, ...$ids[1]);
        }
        $expected = array_map(static fn (array $entry): string => (string) $entry['operation']['operationId'], $spec);
        sort($expected);
        sort($written);
        self::assertSame($expected, $written, 'The reference does not write each operation exactly once.');
        self::assertSame([], ApiReference::linkProblems($files), "A link or heading is wrong:\n".implode("\n", ApiReference::linkProblems($files)));

        $planted = ['a.md' => "# A\n\n## Twice\n\n## Twice\n\n[x](b.md#nowhere) [y](c.md#a)\n", 'b.md' => "# B\n"];
        self::assertSame([
            'a.md: heading "Twice" is written twice',
            'a.md: links to b.md#nowhere, which is no heading there',
            'a.md: links to c.md, which is not written',
        ], ApiReference::linkProblems($planted), 'Planted control: the link check does not report a broken link and a duplicate heading.');
    }

    /** *fails if:* the reference names a tool or role, or the generator's own prose cites the private record. */
    #[Test]
    public function theReferenceNamesNoInternalToolAndCitesNoPrivateRecord(): void
    {
        foreach (self::generated() as $file => $markdown) {
            self::assertSame([], PublishedSpecText::toolNamesInText($markdown), 'contracts/reference/'.$file.' names an internal tool or role.');
        }
        self::assertNotSame([], PublishedSpecText::toolNamesInText('see the '.'OR'.'CH'.' notes'), 'Planted control: a tool name in a template string is not reported.');

        // The prose constants (`TEXT_`, `LABEL_`) and the module titles; file names are not prose.
        $constants = array_filter((new \ReflectionClass(ApiReference::class))->getConstants(), static fn (mixed $value, string $name): bool => \is_string($value) && 1 === preg_match('/^(?:TEXT|LABEL)_/', $name), \ARRAY_FILTER_USE_BOTH);
        $prose = [...array_values($constants), ...array_column(ApiReference::MODULES, 1)];
        self::assertGreaterThan(10, \count($prose), 'Anti-vacuity: the generator\'s own prose is not in its constants.');
        foreach ($prose as $text) {
            self::assertSame([], PublishedSpecText::privateRecordRules($text), 'ApiReference writes prose citing the private record: '.$text);
        }
        self::assertStringContainsString(ApiReference::TEXT_GENERATED, self::generated()[ApiReference::README], 'The README does not carry the generated line, so the generator writes prose outside its constants.');
    }

    /** *fails if:* the root README's operation count is not the spec's. */
    #[Test]
    public function theReadmeOperationCountIsTheSpecs(): void
    {
        $readme = (string) file_get_contents(\dirname(__DIR__, 3).'/README.md');
        $count = \count(GeneratedSpec::operations(self::spec()));
        self::assertSame([], self::readmeCountProblems($readme, $count));
        self::assertSame(['README.md says 131 operations; the spec has '.$count], self::readmeCountProblems('The API serves 131 operations, and', $count), 'Planted control: a stale count is not reported.');
    }

    /** @return list<string> */
    private static function readmeCountProblems(string $readme, int $count): array
    {
        if (1 !== preg_match('/serves (\d+) operations/', $readme, $match)) {
            return ['README.md no longer says how many operations the API serves'];
        }

        return (int) $match[1] === $count ? [] : ['README.md says '.$match[1].' operations; the spec has '.$count];
    }

    /** @return array<string, string> */
    private static function generated(): array
    {
        return self::$files ??= ApiReference::render(self::spec(), ContractArtefactTest::buildSurface(self::spec())['surface'], self::modules(), RecordHistorySubjects::READ_CODES);
    }

    /** @return array<string, mixed> */
    private static function spec(): array
    {
        return json_decode(ContractArtefactTest::generatedSpec(), true, 512, \JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string> operationId => module */
    private static function modules(): array
    {
        $modules = [];
        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            $class = (string) $operation->getClass();
            $modules[(string) $name] = match (true) {
                1 === preg_match('/^App\\\\Modules\\\\([A-Za-z]+)\\\\/', $class, $match) => $match[1],
                str_starts_with($class, 'App\\Platform\\Http\\Bulk\\') => 'Bulk',
                default => $class,
            };
        }

        return $modules;
    }

    /** @return list<string> */
    private static function committedNames(): array
    {
        $names = is_dir(self::directory()) ? array_values(array_filter(scandir(self::directory()) ?: [], static fn (string $name): bool => !str_starts_with($name, '.'))) : [];
        sort($names);

        return $names;
    }

    private static function directory(): string
    {
        return \dirname(__DIR__, 3).'/contracts/reference';
    }

    /**
     * A planted input, unordered on purpose (the generator must sort): one module page, one audit page, two schemas.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, string>, 3: array<string, string>}
     */
    private static function planted(): array
    {
        $json = static fn (string $ref): array => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/'.$ref]]];
        $refusal = static fn (string $text): array => ['description' => $text, 'content' => ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Problem']]]];
        $spec = [
            'info' => ['title' => 'Planted', 'description' => "First.\n\nSecond."],
            'components' => [
                'securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'The token.']],
                'schemas' => [
                    'Thing.UpdateThingInput.jsonMergePatch' => ['type' => 'object', 'properties' => ['name' => ['type' => ['string', 'null']]]],
                    'Thing' => ['type' => 'object', 'required' => ['lines'], 'properties' => ['lines' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['quantity'], 'properties' => ['quantity' => ['type' => 'string', 'format' => 'decimal']]]]]],
                    'Problem' => ['type' => 'object', 'description' => 'A refusal.', 'properties' => ['type' => ['type' => 'string', 'description' => 'Which kind.']]],
                ],
            ],
            'paths' => [
                '/api/v1/things/{id}' => ['get' => ['operationId' => 'GetThing', 'summary' => 'Get thing', 'description' => 'Returns one thing.', 'responses' => ['200' => ['description' => 'The thing.', 'content' => $json('Thing')], '404' => $refusal('Not here.'), '409' => $refusal('Shared conflict.')]]],
                '/api/v1/things' => [
                    'post' => ['operationId' => 'CreateThing', 'summary' => 'Create thing', 'description' => 'Creates one thing.', 'requestBody' => ['description' => 'The thing to create.', 'content' => $json('Thing')], 'responses' => ['201' => ['description' => 'The thing this request created.', 'content' => $json('Thing'), 'headers' => ['Location' => ['description' => 'The address.']]], '409' => $refusal('Its own words.'), '404' => $refusal('Not here.')]],
                    'get' => ['operationId' => 'ListThings', 'summary' => 'List things', 'description' => 'Lists things.', 'parameters' => [['name' => 'q', 'in' => 'query', 'description' => 'Search the things.', 'schema' => ['type' => 'string']]], 'responses' => ['200' => ['description' => 'The things.'], '404' => $refusal('Not here.'), '409' => $refusal('Shared conflict.')]],
                ],
                '/api/v1/auth/token' => ['post' => ['operationId' => 'IssueToken', 'summary' => 'Sign in', 'description' => 'Signs in.', 'security' => [], 'responses' => ['200' => ['description' => 'Signed in.']]]],
                '/api/v1/me' => ['get' => ['operationId' => 'GetMe', 'summary' => 'Get me', 'description' => 'Returns me.', 'responses' => ['200' => ['description' => 'Me.']]]],
                '/api/v1/bulk' => ['post' => ['operationId' => 'CreateBulkThing', 'summary' => 'Bulk thing', 'description' => 'Archives things.', 'responses' => ['200' => ['description' => 'Counts.']]]],
                '/api/v1/audit-records' => ['get' => ['operationId' => 'ListAuditRecords', 'summary' => 'List audit records', 'description' => 'Lists audit records.', 'responses' => ['200' => ['description' => 'History.']]]],
            ],
        ];
        $op = static fn (string $kind, array $codes = [], bool $key = false): array => ['idempotency_key_required' => $key, 'permission' => ['kind' => $kind] + ([] === $codes ? [] : ['codes' => $codes])];
        $surface = [
            'operations' => [
                'GetThing' => $op('all_of', ['thing.view']), 'CreateThing' => $op('all_of', ['thing.create'], true), 'ListThings' => $op('all_of', ['thing.view']),
                'IssueToken' => $op('none'), 'GetMe' => $op('none'), 'CreateBulkThing' => $op('derived', [], true), 'ListAuditRecords' => $op('all_of', ['audit_log.view_record']),
            ],
            'bulk' => ['resources' => ['thing' => ['permission' => 'thing.delete']]],
            'export' => [],
        ];
        $modules = ['GetThing' => 'Customers', 'CreateThing' => 'Customers', 'ListThings' => 'Customers', 'IssueToken' => 'Customers', 'GetMe' => 'Customers', 'CreateBulkThing' => 'Customers', 'ListAuditRecords' => 'AuditLog'];

        return [$spec, $surface, $modules, ['invoice' => 'invoice.view', 'customer' => 'customer.view']];
    }
}

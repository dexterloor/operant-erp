<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Domain\PermissionCatalogue;
use App\Platform\Tenancy\SchemaManifest;
use App\Tests\Support\CanonicalJson;
use App\Tests\Support\GeneratedSpec;
use App\Tests\Support\PublishedSpecText;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * **The published spec reads as a product document** (2b): no private-record id, design word, implementation name, tool
 * name, empty text or framework filler, and it says how to sign in. The rules and their blind spots are
 * {@see PublishedSpecText}'s.
 *
 * Both documents are read: the spec the server builds now, and the committed `contracts/openapi.json`, so a planted
 * source text is red before anyone regenerates, and a hand edit of the committed file is red too. The context the rules
 * allow against is derived from the server: operation ids, tags and components from the spec itself, permission codes
 * from {@see PermissionCatalogue}, tables from {@see SchemaManifest::allTables()}.
 *
 * The truth of a text is not checked here. Whether the right operations answer without a token is held to behaviour by
 * `PermissionEnforcementTest::everyOperationOutsideAuthRefusesARequestCarryingNoToken`.
 */
final class PublishedSpecTextTest extends KernelTestCase
{
    /** The locations rule V applies to in this commit; the operation texts and request bodies follow with their own. */
    private const VENDOR_LOCATIONS = ['tag', 'security scheme', 'query parameter', 'path parameter', 'server'];

    /** The kinds of text the walk must reach on the real spec. */
    private const KINDS_REACHED = [
        'operation summary', 'operation description', 'request body', 'query parameter', 'path parameter', '2xx', '4xx',
        'response header', 'schema', 'info', 'security scheme', 'server',
    ];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $documents = null;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
    }

    /** *fails if:* a published text carries a private-record id, a design document word, an implementation name or a tool or role name. */
    #[Test]
    public function noPublishedTextCitesThePrivateRecord(): void
    {
        foreach (self::documents() as $label => $spec) {
            $faults = PublishedSpecText::problems($spec, self::context($spec), ['I', 'D', 'N', 'T']);
            self::assertSame([], $faults, $label.' carries text a stranger cannot use (rule letters: PublishedSpecText):'."\n".implode("\n", $faults));
        }
    }

    /** *fails if:* a published text is empty or only whitespace. */
    #[Test]
    public function noPublishedTextIsEmpty(): void
    {
        foreach (self::documents() as $label => $spec) {
            self::assertSame([], PublishedSpecText::problems($spec, self::context($spec), ['E']), $label.' publishes an empty text.');
        }
    }

    /** *fails if:* the spec does not declare one bearer scheme required everywhere, with `[]` as the only override. */
    #[Test]
    public function theSpecDeclaresBearerSignIn(): void
    {
        foreach (self::documents() as $label => $spec) {
            self::assertSame([], PublishedSpecText::problems($spec, self::context($spec), ['A']), $label.' does not say how to sign in.');
        }
    }

    /**
     * *fails if:* a tag, the security scheme, a query or path parameter, or the server keeps a text the framework writes
     * when it is given none.
     *
     * The defaults are generated from the installed vendor, so the pin on its version comes first: a vendor update fails
     * here, with the lines to re-read, before any reflection on it can fail with a less useful message.
     */
    #[Test]
    public function noTextIsAFrameworkDefaultYet(): void
    {
        self::assertSame(PublishedSpecText::VENDOR_VERSION, self::installedVersion('api-platform/openapi'),
            'api-platform/openapi moved: re-read `OpenApiFactory` :240-260, :299, :368, :426-453, :491-492, :609-631, :921-926, then move this pin.');

        foreach (self::documents() as $label => $spec) {
            $context = self::context($spec) + ['vendorDefaults' => PublishedSpecText::vendorDefaults($spec), 'vendorLocations' => self::VENDOR_LOCATIONS];
            self::assertContains("Resource 'Customer' operations.", $context['vendorDefaults'], 'Anti-vacuity: the generated defaults miss the tag template.');
            self::assertContains('Retrieves a Customer resource.', $context['vendorDefaults'], 'Anti-vacuity: the generated defaults miss the operation template.');
            $faults = PublishedSpecText::problems($spec, $context, ['V']);
            self::assertSame([], $faults, $label.' keeps the framework\'s own text:'."\n".implode("\n", $faults));
        }
    }

    /**
     * *fails if:* a rule misses its planted offender, or reports a text it must leave alone. The count is the planted
     * (path, rule) pairs exactly: `docs/API.md` breaks rule D twice over (`docs/`, `.md`) and is still one line.
     */
    #[Test]
    public function eachRuleBitesOnAPlantedSpec(): void
    {
        $parameter = static fn (string $name, string $description): array => ['name' => $name, 'in' => 'query', 'description' => $description];
        $spec = [
            'info' => ['title' => 'Planted', 'description' => 'See docs/API.md.'],
            'servers' => [['url' => '/', 'description' => '']],
            'tags' => [['name' => 'Thing', 'description' => "Resource 'Thing' operations."], ['name' => 'IssueTokenTag']],
            'security' => [],
            'components' => ['securitySchemes' => [], 'schemas' => ['Thing' => ['description' => 'The data it holds, see invoice.view.']]],
            'paths' => [
                '/api/v1/things' => ['get' => [
                    'operationId' => 'ListThings',
                    'tags' => ['Thing'],
                    'summary' => 'Lists things per API-19c.',
                    'description' => 'The items are not searched (D-b).',
                    'parameters' => [
                        $parameter('limit', 'Thing limit'),
                        $parameter('archived', 'Return the archived things (C5).'),
                        $parameter('kind', 'One kind, from the soft table.'),
                        $parameter('owner', 'As on /admin/users.'),
                        $parameter('view', 'The Archived view.'),
                        $parameter('q', 'Served by customer_tenant_name_idx.'),
                        $parameter('name', 'Matches customer.name.'),
                        $parameter('subject', 'One of RecordHistorySubjects.'),
                        $parameter('status', 'Never the raw STATUS_X values.'),
                        $parameter('who', 'Asked of RED.'),
                        $parameter('fine', 'Sign in with IssueToken; the number looks like PO-000042, the format UTF-8, the pointer `/name`.'),
                    ],
                    'responses' => ['200' => ['description' => 'The things.']],
                    'security' => [['other' => []]],
                ]],
                '/api/v1/auth/token' => ['post' => ['operationId' => 'IssueToken', 'tags' => ['IssueTokenTag'], 'summary' => 'Sign in', 'description' => 'Signs in.', 'security' => [], 'responses' => []]],
            ],
        ];
        $context = [
            'permissionCodes' => ['invoice.view'],
            'tables' => ['customer', 'invoice'],
            'vendorDefaults' => PublishedSpecText::vendorDefaults($spec),
            'vendorLocations' => self::VENDOR_LOCATIONS,
        ];

        $q = '$.paths./api/v1/things.get.parameters.';
        $expected = [
            '$.info.description [D]',
            '$.servers.0.description [E]',
            '$.tags.0.description [V]',
            '$.paths./api/v1/things.get.summary [I]',
            '$.paths./api/v1/things.get.description [I]',
            $q.'0.description [V]',
            $q.'1.description [I]',
            $q.'2.description [D]',
            $q.'3.description [D]',
            $q.'4.description [D]',
            $q.'5.description [N]',
            $q.'6.description [N]',
            $q.'7.description [N]',
            $q.'8.description [N]',
            $q.'9.description [T]',
            '$.components.securitySchemes [A]',
            '$.security [A]',
            '$.paths./api/v1/things.get.security [A]',
        ];

        $reported = PublishedSpecText::problems($spec, $context, PublishedSpecText::RULES);
        foreach ($expected as $fault) {
            self::assertNotEmpty(array_filter($reported, static fn (string $line): bool => str_starts_with($line, $fault)), 'The checker did not report: '.$fault."\nIt reported:\n".implode("\n", $reported));
        }
        self::assertCount(\count($expected), $reported, "The checker reported something unplanted (the non-offenders: invoice.view, IssueToken, PO-000042, UTF-8, `/name`, \"the data it holds\"):\n".implode("\n", $reported));
    }

    /**
     * *fails if:* the walk stops reaching a kind of text, or a context the rules allow against is empty, or the
     * document-number prefixes rule I allows are not the ones the writers mint.
     */
    #[Test]
    public function theWalkReachesEveryKindOfText(): void
    {
        $spec = self::documents()['the generated spec'];
        $walked = PublishedSpecText::walk($spec);
        $counts = array_count_values(array_column($walked, 'kind'));
        foreach (self::KINDS_REACHED as $kind) {
            self::assertGreaterThan(0, $counts[$kind] ?? 0, 'The walk reached no '.$kind.' text, so no rule holds over it.');
        }

        $operations = GeneratedSpec::operations($spec);
        self::assertSame(\count($operations), $counts['operation summary'] ?? 0, 'The walk did not reach exactly one summary per operation.');
        $limits = 0;
        foreach ($operations as $entry) {
            foreach ($entry['operation']['parameters'] ?? [] as $parameter) {
                $limits += 'limit' === ($parameter['name'] ?? null) ? 1 : 0;
            }
        }
        self::assertGreaterThan(0, $limits, 'Anti-vacuity: no operation declares `limit`.');
        self::assertGreaterThanOrEqual($limits, $counts['query parameter'] ?? 0, 'The walk reached fewer query texts than there are `limit` parameters.');

        $context = self::context($spec);
        self::assertContains('invoice.view', $context['permissionCodes'], 'Anti-vacuity: no permission code, so rule N allows nothing.');
        self::assertContains('customer', $context['tables'], 'Anti-vacuity: no table, so rule N\'s table.column half holds over nothing.');
        self::assertSame([], $spec['paths']['/api/v1/auth/token']['post']['security'] ?? null, 'Anti-vacuity: IssueToken is not exempt, so rule A\'s override half holds over nothing.');

        $minted = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2).'/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                preg_match_all('/\'([A-Z]+)-\'\.str_pad\(/', (string) file_get_contents($file->getPathname()), $matches);
                array_push($minted, ...$matches[1]);
            }
        }
        $minted = array_values(array_unique($minted));
        sort($minted);
        $allowed = PublishedSpecText::DOCUMENT_NUMBER_PREFIXES;
        sort($allowed);
        self::assertSame($minted, $allowed, 'The document-number prefixes rule I allows are not, both ways, the ones src/ mints.');
    }

    /** @return array<string, array<string, mixed>> label => document */
    private static function documents(): array
    {
        if (null === self::$documents) {
            $committed = \dirname(__DIR__, 3).'/contracts/openapi.json';
            self::assertFileExists($committed);
            self::$documents = [
                'the generated spec' => json_decode(CanonicalJson::encode(GeneratedSpec::rawDocument(self::$kernel ?? self::bootKernel())), true, 512, \JSON_THROW_ON_ERROR),
                'contracts/openapi.json' => json_decode((string) file_get_contents($committed), true, 512, \JSON_THROW_ON_ERROR),
            ];
        }

        return self::$documents;
    }

    /**
     * @param array<string, mixed> $spec
     *
     * @return array{permissionCodes: list<string>, tables: list<string>}
     */
    private static function context(array $spec): array
    {
        return ['permissionCodes' => PermissionCatalogue::codes(), 'tables' => SchemaManifest::allTables()];
    }

    private static function installedVersion(string $package): string
    {
        $installed = json_decode((string) file_get_contents(\dirname(__DIR__, 2).'/vendor/composer/installed.json'), true, 512, \JSON_THROW_ON_ERROR);
        foreach ($installed['packages'] ?? [] as $entry) {
            if ($package === ($entry['name'] ?? null)) {
                return (string) ($entry['version'] ?? '');
            }
        }

        return '';
    }
}

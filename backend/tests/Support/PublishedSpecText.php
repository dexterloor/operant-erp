<?php

declare(strict_types=1);

namespace App\Tests\Support;

use ApiPlatform\OpenApi\Factory\OpenApiFactory;

/**
 * **What a stranger reads in the published spec** (2b): every text under a `summary`, `description` or `title` key,
 * anywhere in the document, held to rules that catch the private design record, implementation names, tool names,
 * empty texts, the framework's own filler, and a missing sign-in declaration.
 *
 * Pure: {@see self::problems()} takes a spec and a context and returns one line per (JSON path, rule), so a planted spec
 * proves each rule bites (`PublishedSpecTextTest::eachRuleBitesOnAPlantedSpec`). The walk is recursive, not a list of
 * locations: a text added at a new place (a header, a schema, a tag) is covered without editing this class.
 *
 * The rules:
 * - **I** (ids): a private-record id shape (`API-19c`, `TB-5`, `E-14`, `V2-C-11`, `G-I`, `D-b`, `(C5)` …). Allowed:
 *   `UTF-8`, and a document number (`PO-000042`) whose prefix is one of {@see self::DOCUMENT_NUMBER_PREFIXES}.
 * - **D** (documents): a file or section reference, a design word ("manifest", "limb", "ruling"), a web-app screen or
 *   route of the web app rather than the API (`/admin/users`, "Archived view", "on Unpaid"), a footnote mark.
 * - **N** (implementation names): `::`, an index name (`_idx`), `STATUS_`, a CamelCase word that is not an operation
 *   id, tag or component name, or `<table>.<word>` where the table is the schema manifest's and the whole is not a
 *   permission code (permission codes are public: a 403 names one).
 * - **T** (tool and role names): the public export's needles ({@see self::TOOL_NEEDLES}, case-insensitive) and the
 *   project's role labels ({@see self::ROLE_LABELS}, case-sensitive: "data" is a word, "DATA" a label).
 * - **E** (empty): a text that is empty or only whitespace.
 * - **V** (vendor): a text the framework writes when nothing better is given — compared with the defaults generated from
 *   the installed `api-platform/openapi` ({@see self::vendorDefaults()}) and with their shapes ({@see self::VENDOR_SHAPES}),
 *   at the locations the caller names.
 * - **A** (sign-in, static): one `http`/`bearer` scheme; the top-level `security` names a declared scheme; an
 *   operation's own `security` is absent or exactly `[]`; at least one operation has `[]`. Whether the right operations
 *   carry `[]` is held to the server's behaviour, not here (`PermissionEnforcementTest`).
 *
 * **Blind spots, stated.** Truth is not checked: a clean, wrong sentence passes. Novel id shapes pass: lower case
 * (`tb-5`), a letter and digit with no hyphen (`W5`, `C3` outside parentheses), a private word not on rule D's list
 * ("the design"). "PO" as a role label is not caught (it is also "purchase order"). A backticked internal path
 * (`` `/admin/x` ``) passes. Lower-case "stored" passes (it is ordinary English). Members that are not texts — operation
 * ids, component and tag names, `enum` and `example` values, keys — are not scanned for ids (the tool names in keys are
 * `ContractArtefactTest`'s). An internal class whose short name equals a component, tag or operation id passes rule N.
 * A column named alone (`credit_note_number`) passes, on purpose: most columns are also wire names. Markdown rendering
 * is not checked.
 */
final class PublishedSpecText
{
    /** The text-bearing keys walked. */
    public const TEXT_KEYS = ['summary', 'description', 'title'];

    /**
     * The document-number prefixes the writers mint (`'GR-'.str_pad(…)` and its six siblings). A test-side copy on
     * purpose, held equal both ways to a scan of `src/` (`PublishedSpecTextTest::theWalkReachesEveryKindOfText`).
     *
     * @var list<string>
     */
    public const DOCUMENT_NUMBER_PREFIXES = ['CN', 'FUL', 'GR', 'INV', 'PO', 'RET', 'SO'];

    /**
     * The tool names the public export refuses in any added line, split so this file does not contain them; also read
     * by `ContractArtefactTest::internalNamesIn()` (one source).
     *
     * @var list<string>
     */
    public const TOOL_NEEDLES = [
        '/cla'.'ude/i', '/anthr'.'opic/i', '/\bOR'.'CH\b/i', '/RERUN '.'agent/i', '/agen'.'ts read this file/i',
        '/BE-'.'LEAD/i', '/\.agen'.'t-work/i', '/CLA'.'UDE\.md/i', '/\.cla'.'ude\//i',
    ];

    /** The project's role labels, case-sensitive ("PO" is left out: it is also "purchase order"). */
    public const ROLE_LABELS = '/\b(?:RED|SEC|ARCH|UX|DATA|FE-LEAD|BE-LEAD)\b/';

    /**
     * The framework's default shapes, kept beside the generated defaults so a default with a name the generator did not
     * try is still caught.
     *
     * @var list<string>
     */
    public const VENDOR_SHAPES = [
        '/^Retrieves (?:the collection of \S+ resources|a \S+ resource)\.$/',
        '/^(?:Creates a|Updates the|Replaces the|Removes the) \S+ resource\.$/',
        '/^The (?:new|updated) \S+ resource$/',
        '/^Resource \'\S+\' operations\.$/',
        '/^\S+ (?:resource created|resource updated|resource deleted|collection|resource)$/',
        '/^Value for the http \S+ parameter\.$/',
        '/^Value for the \S+ \S+ parameter\.$/',
        '/^\S+ identifier$/',
    ];

    /** The installed `api-platform/openapi` the copied defaults were read from. */
    public const VENDOR_VERSION = 'v4.3.18';

    /** The kinds of location {@see self::walk()} tells apart; the walk test requires each but `other` and `tag`. */
    public const KINDS = [
        'operation summary', 'operation description', 'request body', 'query parameter', 'path parameter',
        '2xx', '4xx', 'response header', 'schema', 'info', 'security scheme', 'server', 'tag', 'header parameter', 'other',
    ];

    /** The rules, by letter. */
    public const RULES = ['I', 'D', 'N', 'T', 'E', 'V', 'A'];

    private const METHODS = ['get', 'post', 'patch', 'put', 'delete'];

    /**
     * One line per (JSON path, rule): `"<path> [<rule>] <excerpt>"`.
     *
     * @param array<string, mixed> $spec
     * @param array{permissionCodes: list<string>, tables: list<string>, vendorDefaults?: list<string>, vendorLocations?: list<string>} $context
     * @param list<string>         $rules the letters to apply
     *
     * @return list<string>
     */
    public static function problems(array $spec, array $context, array $rules): array
    {
        $names = self::allowedNames($spec);
        $permissionCodes = array_flip($context['permissionCodes']);
        $tables = array_flip($context['tables']);
        $vendor = array_flip($context['vendorDefaults'] ?? []);
        $vendorLocations = $context['vendorLocations'] ?? [];

        $faults = [];
        foreach (self::walk($spec) as $entry) {
            $text = $entry['text'];
            $hit = [];
            if (\in_array('I', $rules, true) && self::idsIn($text)) {
                $hit[] = 'I';
            }
            if (\in_array('D', $rules, true) && self::documentsIn($text)) {
                $hit[] = 'D';
            }
            if (\in_array('N', $rules, true) && self::implementationNamesIn($text, $names, $permissionCodes, $tables)) {
                $hit[] = 'N';
            }
            if (\in_array('T', $rules, true) && self::toolNamesIn($text)) {
                $hit[] = 'T';
            }
            if (\in_array('E', $rules, true) && '' === trim($text)) {
                $hit[] = 'E';
            }
            if (\in_array('V', $rules, true) && \in_array($entry['kind'], $vendorLocations, true) && self::isVendor($text, $vendor)) {
                $hit[] = 'V';
            }
            foreach ($hit as $rule) {
                $faults[] = $entry['path'].' ['.$rule.'] '.mb_substr($text, 0, 90);
            }
        }

        if (\in_array('A', $rules, true)) {
            array_push($faults, ...self::signInFaults($spec));
        }

        return $faults;
    }

    /**
     * The rules a text breaks that need no context: I, D and T. Used by `ErrorResponsesTest`'s success-text check, so
     * the two tests share one matcher.
     *
     * @return list<string>
     */
    public static function privateRecordRules(string $text): array
    {
        return array_values(array_filter([
            self::idsIn($text) ? 'I' : null,
            self::documentsIn($text) ? 'D' : null,
            self::toolNamesIn($text) ? 'T' : null,
        ]));
    }

    /**
     * Every text in the document, with its JSON path and the kind of location it sits at.
     *
     * @param array<string, mixed> $spec
     *
     * @return list<array{path: string, kind: string, text: string}>
     */
    public static function walk(array $spec): array
    {
        $found = [];
        $visit = static function (mixed $node, array $segments) use (&$visit, &$found, $spec): void {
            if (!\is_array($node)) {
                return;
            }
            foreach ($node as $key => $child) {
                $path = [...$segments, (string) $key];
                if (\is_string($child) && \is_string($key) && \in_array($key, self::TEXT_KEYS, true)) {
                    $found[] = ['path' => '$.'.implode('.', $path), 'kind' => self::kind($path, $spec), 'text' => $child];

                    continue;
                }
                $visit($child, $path);
            }
        };
        $visit($spec, []);

        return $found;
    }

    /**
     * The framework's own default texts for this spec's resources, generated from the installed vendor: the operation
     * summaries from its private `getPathDescription()` (called by reflection; it reads nothing of the instance), and
     * the inline defaults copied with their lines in `OpenApiFactory` v4.3.18: request body `'The %s %s resource'`
     * (`:492`), tag `"Resource '%s' operations."` (`:260`), query parameter `"%s %s"` (`:368`), path parameter
     * `"%s identifier"` (`:299`), success `'%s collection'`/`'%s resource'` (`:426`) and `'%s resource
     * created|updated|deleted'` (`:430`, `:442`, `:453`), security schemes `'Value for the http %s parameter.'` (`:926`)
     * and `'Value for the %s %s parameter.'` (`:921`). The short-name domain is the spec's tags (each operation's tag is
     * its resource's short name). The caller asserts {@see self::VENDOR_VERSION} first.
     *
     * @param array<string, mixed> $spec
     *
     * @return list<string>
     */
    public static function vendorDefaults(array $spec): array
    {
        $factory = (new \ReflectionClass(OpenApiFactory::class))->newInstanceWithoutConstructor();
        $pathDescription = new \ReflectionMethod(OpenApiFactory::class, 'getPathDescription');

        $tags = [];
        foreach ($spec['tags'] ?? [] as $tag) {
            $tags[] = (string) ($tag['name'] ?? '');
        }
        $parameterNames = [];
        $schemes = ['bearer', 'basic'];
        foreach (GeneratedSpec::operations($spec) as $entry) {
            foreach ($entry['operation']['tags'] ?? [] as $tag) {
                $tags[] = (string) $tag;
            }
            foreach ($entry['operation']['parameters'] ?? [] as $parameter) {
                $parameterNames[] = (string) ($parameter['name'] ?? '');
            }
        }
        foreach ($spec['components']['securitySchemes'] ?? [] as $scheme) {
            $schemes[] = (string) ($scheme['scheme'] ?? '');
        }
        $tags = array_values(array_unique(array_filter($tags)));

        $defaults = [];
        foreach ($tags as $tag) {
            foreach (['GET', 'POST', 'PATCH', 'PUT', 'DELETE'] as $method) {
                $defaults[] = (string) $pathDescription->invoke($factory, $tag, $method, false);
                $defaults[] = (string) $pathDescription->invoke($factory, $tag, $method, true);
            }
            foreach (['new', 'updated'] as $state) {
                $defaults[] = \sprintf('The %s %s resource', $state, $tag);
            }
            $defaults[] = "Resource '$tag' operations.";
            $defaults[] = "$tag identifier";
            foreach (['collection', 'resource', 'resource created', 'resource updated', 'resource deleted'] as $suffix) {
                $defaults[] = \sprintf('%s %s', $tag, $suffix);
            }
            foreach (array_unique($parameterNames) as $name) {
                $defaults[] = "$tag $name";
            }
        }
        foreach (array_unique(array_filter($schemes)) as $scheme) {
            $defaults[] = \sprintf('Value for the http %s parameter.', $scheme);
        }

        return array_values(array_unique($defaults));
    }

    private static function idsIn(string $text): bool
    {
        $prefixes = implode('|', self::DOCUMENT_NUMBER_PREFIXES);
        $text = (string) preg_replace(['/\bUTF-8\b/', '/\b(?:'.$prefixes.')-\d{6}\b/'], ' ', $text);

        return 1 === preg_match('/\b[A-Z][A-Z0-9]{0,5}(?:-[A-Z0-9]{1,4})*-\d+[a-z]?\b/', $text)
            || 1 === preg_match('/\b[A-Z]{1,3}-[A-Z]\b/', $text)
            || 1 === preg_match('/\b[A-Z]-[a-z]\b/', $text)
            || 1 === preg_match('/\(C\d+\)/', $text);
    }

    private static function documentsIn(string $text): bool
    {
        foreach ([
            '#docs/#', '/\.md\b/', '/§/u', '/\bIA\b/', '/\blimb\b/i', '/\bmanifest\b/i', '/\bruling\b/i', '/\bRevision\b/',
            '/\bderivation step\b/i', '/[¹²³⁴⁵⁶⁷⁸⁹]/u', '/\bsoft table\b/i', '/\[id\]/',
            '#(?<![\w/`])/(?!api/v1)[a-z][a-z-]*(?:/|\b)#', '/\b(?:Archived|Unpaid|Open) view\b/',
            '/\bon (?:Unpaid|Open|Draft|Paid|Credited)\b/', '/\bSTORED\b/',
        ] as $pattern) {
            if (1 === preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, int> $names           allowed CamelCase words
     * @param array<string, int> $permissionCodes
     * @param array<string, int> $tables
     */
    private static function implementationNamesIn(string $text, array $names, array $permissionCodes, array $tables): bool
    {
        if (str_contains($text, '::') || 1 === preg_match('/\w+_idx\b/', $text) || str_contains($text, 'STATUS_')) {
            return true;
        }
        preg_match_all('/\b[A-Z][a-z0-9]+(?:[A-Z][a-z0-9]*)+\b/', $text, $camel);
        foreach ($camel[0] as $word) {
            if (!isset($names[$word])) {
                return true;
            }
        }
        preg_match_all('/\b([a-z][a-z0-9_]*)\.([a-z][a-z0-9_]*)\b/', $text, $dotted, \PREG_SET_ORDER);
        foreach ($dotted as $match) {
            if (isset($tables[$match[1]]) && !isset($permissionCodes[$match[0]])) {
                return true;
            }
        }

        return false;
    }

    private static function toolNamesIn(string $text): bool
    {
        foreach (self::TOOL_NEEDLES as $needle) {
            if (1 === preg_match($needle, $text)) {
                return true;
            }
        }

        return 1 === preg_match(self::ROLE_LABELS, $text);
    }

    /** @param array<string, int> $vendor */
    private static function isVendor(string $text, array $vendor): bool
    {
        if (isset($vendor[$text])) {
            return true;
        }
        foreach (self::VENDOR_SHAPES as $shape) {
            if (1 === preg_match($shape, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $spec
     *
     * @return list<string>
     */
    private static function signInFaults(array $spec): array
    {
        $faults = [];
        $schemes = $spec['components']['securitySchemes'] ?? [];
        $bearer = array_filter((array) $schemes, static fn (mixed $s): bool => \is_array($s) && 'http' === ($s['type'] ?? null) && 'bearer' === strtolower((string) ($s['scheme'] ?? '')));
        if (1 !== \count((array) $schemes) || 1 !== \count($bearer)) {
            $faults[] = '$.components.securitySchemes [A] must be exactly one http/bearer scheme';
        }
        $named = false;
        foreach ($spec['security'] ?? [] as $requirement) {
            foreach (array_keys((array) $requirement) as $name) {
                $named = $named || isset($bearer[$name]);
            }
        }
        if (!$named) {
            $faults[] = '$.security [A] names no declared bearer scheme';
        }
        $exempt = 0;
        foreach (GeneratedSpec::operations($spec) as $entry) {
            if (!\array_key_exists('security', $entry['operation'])) {
                continue;
            }
            if ([] === $entry['operation']['security']) {
                ++$exempt;

                continue;
            }
            $faults[] = '$.paths.'.$entry['path'].'.'.$entry['method'].'.security [A] is neither absent nor []';
        }
        if (0 === $exempt) {
            $faults[] = '$.paths [A] no operation answers without a token (no security: [])';
        }

        return $faults;
    }

    /**
     * Operation ids, tags and component names, and each dotted part of a component name: the CamelCase words rule N
     * allows.
     *
     * @param array<string, mixed> $spec
     *
     * @return array<string, int>
     */
    private static function allowedNames(array $spec): array
    {
        $names = [];
        foreach (GeneratedSpec::operations($spec) as $entry) {
            $names[] = (string) ($entry['operation']['operationId'] ?? '');
            foreach ($entry['operation']['tags'] ?? [] as $tag) {
                $names[] = (string) $tag;
            }
        }
        foreach ($spec['tags'] ?? [] as $tag) {
            $names[] = (string) ($tag['name'] ?? '');
        }
        foreach (array_keys($spec['components']['schemas'] ?? []) as $component) {
            $names[] = (string) $component;
            array_push($names, ...explode('.', (string) $component));
        }

        return array_flip(array_values(array_filter($names)));
    }

    /**
     * @param list<string>         $path the segments below the root
     * @param array<string, mixed> $spec
     */
    private static function kind(array $path, array $spec): string
    {
        $last = end($path);

        return match (true) {
            'info' === $path[0] => 'info',
            'servers' === $path[0] => 'server',
            'tags' === $path[0] => 'tag',
            'components' === $path[0] && 'securitySchemes' === ($path[1] ?? null) => 'security scheme',
            'components' === $path[0] && 'schemas' === ($path[1] ?? null) => 'schema',
            'paths' === $path[0] => self::pathKind($path, $spec, (string) $last),
            default => 'other',
        };
    }

    /**
     * @param list<string>         $path
     * @param array<string, mixed> $spec
     */
    private static function pathKind(array $path, array $spec, string $last): string
    {
        if (!\in_array($path[2] ?? null, self::METHODS, true)) {
            return 'other';
        }
        $count = \count($path);
        if (4 === $count) {
            return match ($last) {
                'summary' => 'operation summary',
                'description' => 'operation description',
                default => 'other',
            };
        }
        if ('requestBody' === ($path[3] ?? null)) {
            return 5 === $count ? 'request body' : 'schema';
        }
        if ('parameters' === ($path[3] ?? null) && 6 === $count) {
            $in = (string) ($spec['paths'][$path[1]][$path[2]]['parameters'][(int) $path[4]]['in'] ?? '');

            return $in.' parameter';
        }
        if ('responses' === ($path[3] ?? null)) {
            if (6 === $count && 'description' === $last) {
                return str_starts_with((string) $path[4], '2') ? '2xx' : ((int) $path[4] >= 400 ? '4xx' : 'other');
            }
            if ('headers' === ($path[5] ?? null)) {
                return 'response header';
            }

            return 'schema';
        }

        return 'other';
    }
}

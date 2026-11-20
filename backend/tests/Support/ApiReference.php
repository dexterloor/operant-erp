<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * **The API reference, as GitHub-flavoured Markdown generated from the spec** (2b, S3): `contracts/reference/README.md`
 * (how to sign in, the refusals once, paging, an index), one page per module, and `schemas.md`.
 *
 * Pure: {@see self::render()} takes the spec, the API surface (`contracts/api-surface.json`'s content), each operation's
 * module and the subject-type read permissions, and returns file name => bytes. The same input always gives the same
 * bytes: operations are sorted by (module title, path, method), schemas by name. `ApiReferenceTest` holds the committed
 * files to this output byte for byte, so the reference cannot go stale beside the spec.
 *
 * Every sentence or label this class writes itself is a class constant (the `TEXT_` and `LABEL_` constants, the module
 * titles), so `ApiReferenceTest` can hold the generator's own prose to the published-text rules. Everything else is the
 * spec's own text, already held by `PublishedSpecTextTest`.
 *
 * An operation whose module is not in {@see self::MODULES} fails the render, naming it: a new module gets a page and a
 * title on purpose, never by default.
 */
final class ApiReference
{
    /**
     * Module => [file, title] (titles wording owner: `UX`).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const MODULES = [
        'Customers' => ['customers.md', 'Customers'],
        'Warehouses' => ['warehouses.md', 'Warehouses and locations'],
        'Suppliers' => ['suppliers.md', 'Suppliers'],
        'UserManagement' => ['users-and-sign-in.md', 'Users, roles and sign-in'],
        'Sales' => ['sales.md', 'Sales'],
        'Purchasing' => ['purchasing.md', 'Purchasing'],
        'Inventory' => ['items-and-stock.md', 'Items and stock'],
        'Analytics' => ['reorder-forecast.md', 'Reorder forecast'],
        'AuditLog' => ['audit-log-and-export.md', 'Audit log and export'],
        'Bulk' => ['bulk.md', 'Bulk archive and restore'],
    ];

    public const README = 'README.md';

    public const SCHEMAS = 'schemas.md';

    public const TEXT_GENERATED = 'Generated from `contracts/openapi.json` and `contracts/api-surface.json` by `make contracts`. Do not edit by hand.';

    public const TEXT_TITLE = 'API reference';

    public const TEXT_ABOUT = 'About this API';

    public const TEXT_SIGN_IN = 'Signing in';

    public const TEXT_NO_TOKEN = 'These operations take no token:';

    public const TEXT_REFUSALS = 'Refusals';

    public const TEXT_REFUSALS_INTRO = 'Every refusal is sent as `application/problem+json` in the shape below. Each operation lists the statuses it can answer; where an operation says nothing more, the sentence here is what that status means.';

    public const TEXT_PAGING = 'Paging and retries';

    public const TEXT_PATCH = 'A `PATCH` body holds only the fields to change: a field left out is not changed. The spec names each such body\'s schema with a `.jsonMergePatch` suffix; this reference leaves the suffix out.';

    public const TEXT_OPERATIONS = 'Operations';

    public const TEXT_SCHEMAS_TITLE = 'Schemas';

    public const TEXT_SCHEMAS_INTRO = 'Every body the API reads or sends. A field is required when the schema says so; a nested field is shown under its parent (`lines[].quantity`).';

    public const LABEL_WHO = 'Who may call it';

    public const LABEL_KEY = 'Idempotency-Key';

    public const LABEL_KEY_REQUIRED = 'required: send a fresh key with each new request, and the same key to retry it.';

    public const LABEL_PARAMETERS = 'Parameters';

    public const LABEL_BODY = 'Request body';

    public const LABEL_RESPONSES = 'Responses';

    public const LABEL_REFUSED = 'Refusals';

    public const LABEL_NONE_SIGNED_IN = 'No permission code: any signed-in person.';

    public const LABEL_NONE_PUBLIC = 'No sign-in.';

    public const LABEL_DERIVED = 'The permission depends on `resource`:';

    public const LABEL_SUBJECT = 'and the read permission of `subject_type`:';

    public const LABEL_HEADER = 'Header';

    public const LABEL_TABLE_PARAMETERS = '| Name | In | Type | Required | Description |';

    public const LABEL_TABLE_FIELDS = '| Field | Type | Required | Nullable | Limits | Description |';

    public const LABEL_TABLE_INDEX = '| Operation | Method and path | Permission |';

    public const LABEL_TABLE_RESOURCE = '| `resource` | Permission |';

    public const LABEL_TABLE_SUBJECT = '| `subject_type` | Permission |';

    public const LABEL_TABLE_PROBLEM = '| Field | Type | Description |';

    public const LABEL_PAGE = 'Page:';

    public const LABEL_YES = 'yes';

    public const LABEL_NO = 'no';

    public const LABEL_SAME_AS = 'as in';

    private const METHOD_ORDER = ['get' => 0, 'post' => 1, 'patch' => 2, 'put' => 3, 'delete' => 4];

    private const REF = '#/components/schemas/';

    /**
     * @param array<string, mixed>  $spec
     * @param array<string, mixed>  $surface
     * @param array<string, string> $moduleOf  operationId => module (a key of {@see self::MODULES})
     * @param array<string, string> $readCodes subject_type => the permission reading its history needs
     *
     * @return array<string, string> file name => Markdown
     */
    public static function render(array $spec, array $surface, array $moduleOf, array $readCodes): array
    {
        $byModule = [];
        foreach (GeneratedSpec::operations($spec) as $entry) {
            $id = (string) ($entry['operation']['operationId'] ?? '');
            $module = $moduleOf[$id] ?? throw new \LogicException(\sprintf('Operation "%s" has no module, so the reference cannot place it.', $id));
            if (!isset(self::MODULES[$module])) {
                throw new \LogicException(\sprintf('Module "%s" (operation "%s") has no page in the reference: add it to ApiReference::MODULES with a title.', $module, $id));
            }
            $byModule[$module][] = $entry;
        }
        uksort($byModule, static fn (string $a, string $b): int => strcmp(self::MODULES[$a][1], self::MODULES[$b][1]));
        foreach ($byModule as &$entries) {
            usort($entries, static fn (array $a, array $b): int => [$a['path'], self::METHOD_ORDER[$a['method']] ?? 9] <=> [$b['path'], self::METHOD_ORDER[$b['method']] ?? 9]);
        }
        unset($entries);

        $shared = self::sharedRefusals($spec);
        $files = [self::README => self::readme($spec, $surface, $byModule, $shared)];
        foreach ($byModule as $module => $entries) {
            [$file, $title] = self::MODULES[$module];
            $files[$file] = self::modulePage($title, $entries, $spec, $surface, $shared, $readCodes);
        }
        $files[self::SCHEMAS] = self::schemasPage($spec);
        ksort($files);

        return $files;
    }

    /** GitHub's heading anchor: lower case; letters, digits, spaces, `-` and `_` kept; spaces to `-`. */
    public static function slug(string $heading): string
    {
        return str_replace(' ', '-', (string) preg_replace('/[^\p{L}\p{N} _-]/u', '', mb_strtolower($heading)));
    }

    /** A schema's display name: the spec's name without the `.jsonMergePatch` suffix. */
    public static function schemaName(string $component): string
    {
        return str_ends_with($component, '.jsonMergePatch') ? substr($component, 0, -\strlen('.jsonMergePatch')) : $component;
    }

    /**
     * Every link that names a file or heading the files do not have, and every heading written twice in one file: one
     * line each.
     *
     * @param array<string, string> $files
     *
     * @return list<string>
     */
    public static function linkProblems(array $files): array
    {
        $anchors = [];
        $problems = [];
        foreach ($files as $file => $markdown) {
            preg_match_all('/^#{1,6} (.+)$/m', $markdown, $headings);
            $seen = [];
            foreach ($headings[1] as $heading) {
                $slug = self::slug($heading);
                if (isset($seen[$slug])) {
                    $problems[] = $file.': heading "'.$heading.'" is written twice';
                }
                $seen[$slug] = true;
            }
            $anchors[$file] = $seen;
        }
        foreach ($files as $file => $markdown) {
            preg_match_all('/\]\(([^)#\s]*)#([^)\s]*)\)/', $markdown, $links, \PREG_SET_ORDER);
            foreach ($links as $link) {
                $target = '' === $link[1] ? $file : $link[1];
                if (!isset($anchors[$target])) {
                    $problems[] = $file.': links to '.$link[1].', which is not written';
                } elseif (!isset($anchors[$target][$link[2]])) {
                    $problems[] = $file.': links to '.$target.'#'.$link[2].', which is no heading there';
                }
            }
        }

        return $problems;
    }

    /**
     * The most common description per refusal status: the sentence the README prints once, and each operation links
     * to when it says the same.
     *
     * @param array<string, mixed> $spec
     *
     * @return array<string, string> status => description
     */
    private static function sharedRefusals(array $spec): array
    {
        $counts = [];
        foreach (GeneratedSpec::operations($spec) as $entry) {
            foreach ($entry['operation']['responses'] ?? [] as $status => $response) {
                if ((int) $status >= 400) {
                    $text = (string) ($response['description'] ?? '');
                    $counts[(string) $status][$text] = ($counts[(string) $status][$text] ?? 0) + 1;
                }
            }
        }
        ksort($counts);
        $shared = [];
        foreach ($counts as $status => $texts) {
            uksort($texts, static fn (string $a, string $b): int => [$texts[$b], $a] <=> [$texts[$a], $b]);
            $shared[(string) $status] = (string) array_key_first($texts);
        }

        return $shared;
    }

    /**
     * @param array<string, mixed>                                                                 $spec
     * @param array<string, mixed>                                                                 $surface
     * @param array<string, list<array{method: string, path: string, operation: array<string, mixed>}>> $byModule
     * @param array<string, string>                                                                $shared
     */
    private static function readme(array $spec, array $surface, array $byModule, array $shared): string
    {
        $out = ['# '.self::TEXT_TITLE, '', self::TEXT_GENERATED, '', '## '.self::TEXT_ABOUT, '', trim((string) ($spec['info']['description'] ?? '')), ''];

        $out[] = '## '.self::TEXT_SIGN_IN;
        $out[] = '';
        foreach ($spec['components']['securitySchemes'] ?? [] as $scheme) {
            $out[] = (string) ($scheme['description'] ?? '');
            $out[] = '';
        }
        $out[] = self::TEXT_NO_TOKEN;
        $out[] = '';
        foreach ($byModule as $module => $entries) {
            foreach ($entries as $entry) {
                if ([] === ($entry['operation']['security'] ?? null)) {
                    $out[] = '- '.self::operationLink($module, $entry);
                }
            }
        }
        $out[] = '';

        $out[] = '## '.self::TEXT_REFUSALS;
        $out[] = '';
        $out[] = self::TEXT_REFUSALS_INTRO;
        $out[] = '';
        foreach (['Problem', 'ProblemFieldError'] as $component) {
            $schema = $spec['components']['schemas'][$component] ?? null;
            if (!\is_array($schema)) {
                continue;
            }
            $out[] = '**['.$component.']('.self::SCHEMAS.'#'.self::slug($component).')** — '.self::cell((string) ($schema['description'] ?? ''));
            $out[] = '';
            $out[] = self::LABEL_TABLE_PROBLEM;
            $out[] = '| --- | --- | --- |';
            foreach ($schema['properties'] ?? [] as $field => $property) {
                $out[] = '| `'.$field.'` | '.self::type((array) $property).' | '.self::cell((string) ($property['description'] ?? '')).' |';
            }
            $out[] = '';
        }
        foreach ($shared as $status => $text) {
            $out[] = '### '.$status;
            $out[] = '';
            $out[] = $text;
            $out[] = '';
        }

        $out[] = '## '.self::TEXT_PAGING;
        $out[] = '';
        foreach (['limit', 'after', 'before'] as $name) {
            $text = self::firstParameterText($spec, $name);
            if (null !== $text) {
                $out[] = '- `'.$name.'`: '.$text;
            }
        }
        if (isset($shared['428'])) {
            $out[] = '- `Idempotency-Key`: '.$shared['428'];
        }
        $out[] = '';
        $out[] = self::TEXT_PATCH;
        $out[] = '';

        $out[] = '## '.self::TEXT_OPERATIONS;
        $out[] = '';
        foreach ($byModule as $module => $entries) {
            [$file, $title] = self::MODULES[$module];
            $out[] = '### '.$title;
            $out[] = '';
            $out[] = self::LABEL_PAGE.' ['.$file.']('.$file.'#'.self::slug($title).')';
            $out[] = '';
            $out[] = self::LABEL_TABLE_INDEX;
            $out[] = '| --- | --- | --- |';
            foreach ($entries as $entry) {
                $out[] = '| '.self::operationLink($module, $entry).' | `'.strtoupper($entry['method']).' '.$entry['path'].'` | '.self::permissionShort($surface, (string) $entry['operation']['operationId']).' |';
            }
            $out[] = '';
        }

        return implode("\n", $out);
    }

    /**
     * @param list<array{method: string, path: string, operation: array<string, mixed>}> $entries
     * @param array<string, mixed>                                                      $spec
     * @param array<string, mixed>                                                      $surface
     * @param array<string, string>                                                     $shared
     * @param array<string, string>                                                     $readCodes
     */
    private static function modulePage(string $title, array $entries, array $spec, array $surface, array $shared, array $readCodes): string
    {
        $out = ['# '.$title, '', self::TEXT_GENERATED, ''];
        foreach ($entries as $entry) {
            $operation = $entry['operation'];
            $id = (string) $operation['operationId'];
            $out[] = '## '.(string) ($operation['summary'] ?? $id);
            $out[] = '';
            $out[] = '`'.strtoupper($entry['method']).' '.$entry['path'].'` · `'.$id.'`';
            $out[] = '';
            $out[] = (string) ($operation['description'] ?? '');
            $out[] = '';
            array_push($out, ...self::who($surface, $operation, $readCodes));
            if (true === ($surface['operations'][$id]['idempotency_key_required'] ?? null)) {
                $out[] = '**'.self::LABEL_KEY.':** '.self::LABEL_KEY_REQUIRED;
                $out[] = '';
            }

            if ([] !== ($operation['parameters'] ?? [])) {
                $out[] = '**'.self::LABEL_PARAMETERS.'**';
                $out[] = '';
                $out[] = self::LABEL_TABLE_PARAMETERS;
                $out[] = '| --- | --- | --- | --- | --- |';
                foreach ($operation['parameters'] as $parameter) {
                    $out[] = '| `'.$parameter['name'].'` | '.$parameter['in'].' | '.self::type((array) ($parameter['schema'] ?? [])).' | '.(true === ($parameter['required'] ?? false) ? self::LABEL_YES : self::LABEL_NO).' | '.self::cell((string) ($parameter['description'] ?? '')).' |';
                }
                $out[] = '';
            }

            if (\is_array($operation['requestBody'] ?? null)) {
                $out[] = '**'.self::LABEL_BODY.':** '.self::contentLinks((array) ($operation['requestBody']['content'] ?? [])).(isset($operation['requestBody']['description']) ? ' — '.$operation['requestBody']['description'] : '');
                $out[] = '';
            }

            $out[] = '**'.self::LABEL_RESPONSES.'**';
            $out[] = '';
            $refusals = [];
            foreach ($operation['responses'] ?? [] as $status => $response) {
                $text = (string) ($response['description'] ?? '');
                if ((int) $status >= 400) {
                    $refusals[] = ($shared[(string) $status] ?? null) === $text
                        ? '['.$status.']('.self::README.'#'.self::slug((string) $status).')'
                        : $status.' ('.$text.')';

                    continue;
                }
                $line = '- **'.$status.'** '.$text;
                $links = self::contentLinks((array) ($response['content'] ?? []));
                if ('' !== $links) {
                    $line .= ' '.$links;
                }
                foreach ($response['headers'] ?? [] as $header => $definition) {
                    $line .= ' '.self::LABEL_HEADER.' `'.$header.'`: '.($definition['description'] ?? '');
                }
                $out[] = $line;
            }
            if ([] !== $refusals) {
                $out[] = '- **'.self::LABEL_REFUSED.'** '.implode(' · ', $refusals);
            }
            $out[] = '';
        }

        return implode("\n", $out);
    }

    /**
     * @param array<string, mixed>  $surface
     * @param array<string, mixed>  $operation
     * @param array<string, string> $readCodes
     *
     * @return list<string>
     */
    private static function who(array $surface, array $operation, array $readCodes): array
    {
        $id = (string) $operation['operationId'];
        $permission = $surface['operations'][$id]['permission'] ?? ['kind' => 'none'];
        $line = '**'.self::LABEL_WHO.':** ';
        $out = [];
        switch ($permission['kind'] ?? 'none') {
            case 'all_of':
                $out[] = $line.implode(', ', array_map(static fn (string $code): string => '`'.$code.'`', (array) ($permission['codes'] ?? [])))
                    .('ListAuditRecords' === $id ? ', '.self::LABEL_SUBJECT : '');
                $out[] = '';
                if ('ListAuditRecords' === $id) {
                    $out[] = self::LABEL_TABLE_SUBJECT;
                    $out[] = '| --- | --- |';
                    ksort($readCodes);
                    foreach ($readCodes as $subject => $code) {
                        $out[] = '| `'.$subject.'` | `'.$code.'` |';
                    }
                    $out[] = '';
                }
                break;
            case 'derived':
                $out[] = $line.self::LABEL_DERIVED;
                $out[] = '';
                $out[] = self::LABEL_TABLE_RESOURCE;
                $out[] = '| --- | --- |';
                $table = 'CreateExport' === $id ? (array) ($surface['export'] ?? []) : (array) ($surface['bulk']['resources'] ?? []);
                ksort($table);
                foreach ($table as $resource => $row) {
                    $out[] = '| `'.$resource.'` | `'.($row['permission'] ?? '').'` |';
                }
                $out[] = '';
                break;
            default:
                $out[] = $line.([] === ($operation['security'] ?? null) ? self::LABEL_NONE_PUBLIC : self::LABEL_NONE_SIGNED_IN);
                $out[] = '';
        }

        return $out;
    }

    /** @param array<string, mixed> $surface */
    private static function permissionShort(array $surface, string $id): string
    {
        $permission = $surface['operations'][$id]['permission'] ?? ['kind' => 'none'];

        return match ($permission['kind'] ?? 'none') {
            'all_of' => implode(', ', array_map(static fn (string $code): string => '`'.$code.'`', (array) ($permission['codes'] ?? []))),
            'derived' => self::LABEL_DERIVED,
            default => '—',
        };
    }

    /** @param array{method: string, path: string, operation: array<string, mixed>} $entry */
    private static function operationLink(string $module, array $entry): string
    {
        $summary = (string) ($entry['operation']['summary'] ?? $entry['operation']['operationId']);

        return '['.$summary.']('.self::MODULES[$module][0].'#'.self::slug($summary).')';
    }

    /** @param array<string, mixed> $content */
    private static function contentLinks(array $content): string
    {
        $links = [];
        foreach ($content as $mediaType => $media) {
            $ref = $media['schema']['$ref'] ?? null;
            $links[] = \is_string($ref) && str_starts_with($ref, self::REF)
                ? '['.self::schemaName(substr($ref, \strlen(self::REF))).']('.self::SCHEMAS.'#'.self::slug(self::schemaName(substr($ref, \strlen(self::REF)))).') (`'.$mediaType.'`)'
                : '`'.$mediaType.'`';
        }

        return implode(', ', $links);
    }

    /** @param array<string, mixed> $spec */
    private static function firstParameterText(array $spec, string $name): ?string
    {
        foreach (GeneratedSpec::operations($spec) as $entry) {
            foreach ($entry['operation']['parameters'] ?? [] as $parameter) {
                if ($name === ($parameter['name'] ?? null) && isset($parameter['description'])) {
                    return (string) $parameter['description'];
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $spec */
    private static function schemasPage(array $spec): string
    {
        $out = ['# '.self::TEXT_SCHEMAS_TITLE, '', self::TEXT_GENERATED, '', self::TEXT_SCHEMAS_INTRO, ''];
        $schemas = (array) ($spec['components']['schemas'] ?? []);
        $names = array_keys($schemas);
        usort($names, static fn (string $a, string $b): int => strcmp(self::schemaName($a), self::schemaName($b)));
        foreach ($names as $component) {
            $schema = (array) $schemas[$component];
            $out[] = '## '.self::schemaName((string) $component);
            $out[] = '';
            if (isset($schema['description'])) {
                $out[] = (string) $schema['description'];
                $out[] = '';
            }
            $rows = self::fieldRows($schema, '');
            if ([] === $rows) {
                $out[] = self::type($schema);
                $out[] = '';

                continue;
            }
            $out[] = self::LABEL_TABLE_FIELDS;
            $out[] = '| --- | --- | --- | --- | --- | --- |';
            array_push($out, ...$rows);
            $out[] = '';
        }

        return implode("\n", $out);
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    private static function fieldRows(array $schema, string $prefix): array
    {
        $rows = [];
        $required = (array) ($schema['required'] ?? []);
        foreach ((array) ($schema['properties'] ?? []) as $field => $property) {
            $property = (array) $property;
            $name = $prefix.$field;
            $nullable = \in_array('null', (array) ($property['type'] ?? []), true);
            $rows[] = '| `'.$name.'` | '.self::type($property).' | '.(\in_array($field, $required, true) ? self::LABEL_YES : self::LABEL_NO).' | '.($nullable ? self::LABEL_YES : self::LABEL_NO).' | '.self::limits($property).' | '.self::cell((string) ($property['description'] ?? '')).' |';
            if (isset($property['properties'])) {
                array_push($rows, ...self::fieldRows($property, $name.'.'));
            }
            $items = (array) ($property['items'] ?? []);
            if (isset($items['properties'])) {
                array_push($rows, ...self::fieldRows($items, $name.'[].'));
            }
        }

        return $rows;
    }

    /** @param array<string, mixed> $schema */
    private static function type(array $schema): string
    {
        if (isset($schema['$ref']) && \is_string($schema['$ref'])) {
            $name = self::schemaName(substr($schema['$ref'], \strlen(self::REF)));

            return '['.$name.']('.self::SCHEMAS.'#'.self::slug($name).')';
        }
        $types = array_values(array_filter((array) ($schema['type'] ?? []), static fn (mixed $type): bool => 'null' !== $type));
        $type = [] === $types ? 'any' : implode(' or ', $types);
        if ('array' === $type) {
            $type = 'array of '.self::type((array) ($schema['items'] ?? []));
        }
        if (isset($schema['format'])) {
            $type .= ' (`'.$schema['format'].'`)';
        }
        if (isset($schema['enum'])) {
            $type .= ': '.implode(', ', array_map(static fn (mixed $value): string => '`'.(null === $value ? 'null' : (string) $value).'`', (array) $schema['enum']));
        }

        return $type;
    }

    /** @param array<string, mixed> $schema */
    private static function limits(array $schema): string
    {
        $limits = [];
        foreach (['minimum', 'maximum', 'minLength', 'maxLength', 'minItems', 'maxItems', 'pattern', 'default'] as $key) {
            if (\array_key_exists($key, $schema)) {
                $value = $schema[$key];
                $limits[] = $key.' `'.(\is_bool($value) ? ($value ? 'true' : 'false') : (\is_scalar($value) ? (string) $value : json_encode($value))).'`';
            }
        }

        return self::cell(implode(', ', $limits));
    }

    /** A table cell: one line, pipes escaped. */
    private static function cell(string $text): string
    {
        return str_replace(['|', "\n"], ['\|', ' '], $text);
    }
}

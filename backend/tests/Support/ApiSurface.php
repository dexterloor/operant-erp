<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * **What the web app needs to know about the API besides the spec itself** (W-4, `contracts/api-surface.json`).
 *
 * A PURE function over plain arrays: the test reads the registries (export sources, bulk members, each operation's
 * permission and retry-key flag) and the generated spec, and hands them here. Nothing in this class reads a container or
 * an operation's extra properties, so a planted input can show each check biting.
 *
 * The file holds:
 *  - `export`: each resource `CreateExport` accepts, the List it renders and the one code that List is gated on;
 *  - `bulk.resources`: each resource the bulk archive and restore accept, its single Delete / Restore / archived List, and
 *    the `<resource>.delete` code both are gated on; `bulk.max_ids`, the enforced cap;
 *  - `operations`: for every operationId in the spec, how it is gated (`none`, `all_of` with its codes, `derived`) and
 *    whether it requires an `Idempotency-Key`;
 *  - `pathParameters`: the distinct path-variable names, every one of which names a record id (a canonical uuid).
 *
 * Not in the file, deliberately: the export row cap (it differs under the test environment; it is only ever shown inside
 * a refusal's sentence), refusal rule codes (thrown in domain code), and why a resource is NOT admitted.
 */
final class ApiSurface
{
    /** A path variable is a record id: `id`, or a lower-camel name ending in `Id`. */
    public const PATH_PARAMETER_SHAPE = '/^(id|[a-z][A-Za-z]*Id)$/';

    /**
     * @param array<string, mixed>                                                            $spec          the generated OpenAPI document, decoded to arrays
     * @param array<string, array{kind: string, codes: list<string>, idempotency: bool}>     $registry      operationId => its declared facts
     * @param array<string, string>                                                           $exportLists   resource => the List it renders
     * @param array<string, string>                                                           $exportCodes   resource => the code the export gate resolves
     * @param array<string, array{delete: string, restore: string, list: string}>            $bulkMembers   resource => its operations
     * @param array<string, string>                                                           $bulkCodes     resource => the code the archive gate resolves
     * @param array<string, string>                                                           $restoreCodes  resource => the code the restore gate resolves
     *
     * @return array{surface: array<string, mixed>, problems: list<string>}
     */
    public static function build(array $spec, array $registry, array $exportLists, array $exportCodes, array $bulkMembers, array $bulkCodes, array $restoreCodes, int $maxIds): array
    {
        $problems = [];
        $specOperations = self::specOperations($spec);

        $operations = [];
        foreach ($specOperations as $operationId => $operation) {
            $facts = $registry[$operationId] ?? null;
            if (null === $facts) {
                $problems[] = \sprintf('%s is in the spec but no operation of that name is in the registry.', $operationId);
                continue;
            }
            $permission = ['kind' => $facts['kind']];
            if ('all_of' === $facts['kind']) {
                $permission['codes'] = $facts['codes'];
            }
            $operations[$operationId] = ['idempotency_key_required' => $facts['idempotency'], 'permission' => $permission];
        }

        $export = [];
        foreach ($exportLists as $resource => $list) {
            if (!isset($specOperations[$list])) {
                $problems[] = \sprintf('export.%s names %s, which is not an operation in the spec.', $resource, $list);
                continue;
            }
            $facts = $registry[$list] ?? null;
            if (null === $facts || 'all_of' !== $facts['kind'] || 1 !== \count($facts['codes'])) {
                $problems[] = \sprintf('export.%s names %s, which is not gated on exactly one code.', $resource, $list);
                continue;
            }
            if (($exportCodes[$resource] ?? null) !== $facts['codes'][0]) {
                $problems[] = \sprintf('export.%s: %s declares %s but the export gate resolves %s.', $resource, $list, $facts['codes'][0], $exportCodes[$resource] ?? '(nothing)');
            }
            $export[$resource] = ['list' => $list, 'permission' => $facts['codes'][0]];
        }
        if (array_keys($exportCodes) !== array_keys($exportLists)) {
            $problems[] = 'The export gate resolves a different set of resources from the export sources.';
        }

        $resources = [];
        foreach ($bulkMembers as $resource => $member) {
            foreach (['delete', 'restore', 'list'] as $role) {
                if (!isset($specOperations[$member[$role]])) {
                    $problems[] = \sprintf('bulk.resources.%s.%s names %s, which is not an operation in the spec.', $resource, $role, $member[$role]);
                }
            }
            $code = $bulkCodes[$resource] ?? null;
            if (null === $code || ($restoreCodes[$resource] ?? null) !== $code) {
                $problems[] = \sprintf('bulk.resources.%s: archived under %s but restored under %s (ST-9).', $resource, $code ?? '(nothing)', $restoreCodes[$resource] ?? '(nothing)');
            }
            $resources[$resource] = ['delete' => $member['delete'], 'list' => $member['list'], 'permission' => (string) $code, 'restore' => $member['restore']];
        }

        foreach (['CreateBulkArchival', 'CreateBulkRestoration'] as $operationId) {
            if (!isset($specOperations[$operationId])) {
                $problems[] = $operationId.' is not in the spec, so its declared cap cannot be checked.';
                continue;
            }
            $declared = self::declaredIdsCaps($spec, $specOperations[$operationId]);
            if ([] === $declared || [$maxIds] !== array_values(array_unique($declared))) {
                $problems[] = \sprintf('%s declares ids.maxItems %s; the enforced cap is %d.', $operationId, [] === $declared ? '(none)' : implode(', ', $declared), $maxIds);
            }
        }

        // Both the template's own `{variables}` and the declared `in: path` parameters are read: the generator names a
        // parameter after the operation's uri variable, which need not match the template text (a template `{code}` was
        // measured to emit a parameter named `id`), and the web app builds URLs from the template.
        $names = [];
        foreach (GeneratedSpec::operations($spec) as $data) {
            $operationId = (string) ($data['operation']['operationId'] ?? $data['path']);
            preg_match_all('/\{([^}]*)\}/', $data['path'], $matches);
            $inTemplate = $matches[1];
            $declared = [];
            foreach ($data['operation']['parameters'] ?? [] as $parameter) {
                if ('path' === ($parameter['in'] ?? null)) {
                    $declared[] = (string) ($parameter['name'] ?? '');
                }
            }
            $sortedTemplate = $inTemplate;
            sort($sortedTemplate, \SORT_STRING);
            sort($declared, \SORT_STRING);
            if ($sortedTemplate !== $declared) {
                $problems[] = \sprintf('%s: the path template names {%s} but the declared path parameters are {%s}.', $operationId, implode('}, {', $sortedTemplate), implode('}, {', $declared));
            }
            foreach (array_unique([...$inTemplate, ...$declared]) as $name) {
                if (1 !== preg_match(self::PATH_PARAMETER_SHAPE, $name)) {
                    $problems[] = \sprintf('%s has a path variable {%s} that does not name a record id; the web app would validate it as a uuid.', $operationId, $name);
                    continue;
                }
                $names[$name] = true;
            }
        }
        $names = array_keys($names);
        sort($names, \SORT_STRING);

        return [
            'surface' => [
                'bulk' => ['max_ids' => $maxIds, 'resources' => $resources],
                'export' => $export,
                'operations' => $operations,
                'pathParameters' => ['format' => 'uuid', 'names' => $names],
            ],
            'problems' => $problems,
        ];
    }

    /**
     * @param array<string, mixed> $spec
     *
     * @return array<string, array<string, mixed>> operationId => the operation object
     */
    public static function specOperations(array $spec): array
    {
        $byId = [];
        foreach (GeneratedSpec::operations($spec) as $data) {
            $byId[(string) ($data['operation']['operationId'] ?? '')] = $data['operation'];
        }
        unset($byId['']);

        return $byId;
    }

    /**
     * Every `ids.maxItems` the operation's request body declares, one per content type.
     *
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $operation
     *
     * @return list<int>
     */
    private static function declaredIdsCaps(array $spec, array $operation): array
    {
        $caps = [];
        foreach ($operation['requestBody']['content'] ?? [] as $media) {
            $schema = self::resolve($spec, $media['schema'] ?? []);
            $ids = self::resolve($spec, $schema['properties']['ids'] ?? []);
            if (isset($ids['maxItems'])) {
                $caps[] = (int) $ids['maxItems'];
            }
        }

        return $caps;
    }

    /**
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function resolve(array $spec, array $schema): array
    {
        $guard = 0;
        while (isset($schema['$ref']) && \is_string($schema['$ref']) && str_starts_with($schema['$ref'], '#/') && $guard++ < 10) {
            $node = $spec;
            foreach (explode('/', substr($schema['$ref'], 2)) as $segment) {
                $node = $node[str_replace(['~1', '~0'], ['/', '~'], $segment)] ?? [];
            }
            $schema = \is_array($node) ? $node : [];
        }

        return $schema;
    }
}

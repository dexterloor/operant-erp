<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\AuditLog\Application\ExportSources;
use App\Platform\Http\Export\CsvExportRenderer;
use App\Tests\Support\ExportRowFixture;
use App\Tests\Support\ExportRowFixtureRef;
use App\Tests\Support\GeneratedSpec;
use PHPUnit\Framework\Attributes\Test;

/**
 * **The export's columns (G6-C0; design Response H-2; final RED M-5).**
 *
 * - `theHeaderEqualsTheListItemSchemasLeaves` — per source, the header (derived from the SERIALIZER's metadata of the row
 *   class) equals, two-way, the dotted leaves of the generated OpenAPI item schema of its List, `$ref`s and nullable
 *   `anyOf`s resolved. The schema is the pin; the metadata is the run-time source. *Mutation:* derive the header from PHP
 *   property names (`getName()` instead of the serialized name) → `unit_of_measure_code` becomes `unitOfMeasureCode` → red.
 * - `everyRowHasExactlyTheHeadersColumns` — rendered row WIDTH (never the normalized key set): a null reference and a key
 *   the serializer omits (`SKIP_NULL_VALUES`, the `SalesReturnResource` shape) both render as empty cells, so every row is
 *   as wide as the header. *Mutation:* derive the header from the first row → the null reference shortens it → red.
 */
final class ExportCsvTest extends ApiTestCase
{
    #[Test]
    public function theHeaderEqualsTheListItemSchemasLeaves(): void
    {
        $spec = GeneratedSpec::regenerate(self::$kernel ?? throw new \LogicException('booted in setUp()'));
        $byOperationId = [];
        foreach (GeneratedSpec::operations($spec) as $data) {
            $byOperationId[(string) ($data['operation']['operationId'] ?? '')] = $data['operation'];
        }

        $checked = 0;
        foreach ($this->sources()->all() as $resource => $source) {
            $operation = $byOperationId[$source->listOperationName()] ?? null;
            self::assertNotNull($operation, $resource.': its List operation is not in the generated spec.');

            $collection = self::resolve($spec, $operation['responses']['200']['content']['application/json']['schema']);
            $item = self::resolve($spec, $collection['properties']['items']['items']);
            $leaves = self::leaves($spec, $item, '');
            sort($leaves);

            $header = $this->renderer()->header($source->rowClass());
            $sortedHeader = $header;
            sort($sortedHeader);

            self::assertSame($leaves, $sortedHeader, $resource.': the export header and the List item schema\'s leaves differ.');
            self::assertSame(\count($header), \count(array_unique($header)), $resource.': the header names a column twice.');
            ++$checked;
        }

        self::assertGreaterThanOrEqual(3, $checked, 'Fewer sources than the Inventory family were checked.');
    }

    #[Test]
    public function everyRowHasExactlyTheHeadersColumns(): void
    {
        $header = $this->renderer()->header(ExportRowFixture::class);
        self::assertSame(['id', 'display_name', 'ref.id', 'ref.label', 'withheld', 'tags', 'count', 'active'], $header);

        $rows = [
            new ExportRowFixture('1', 'Has everything', new ExportRowFixtureRef('r1', 'Ref one'), 'shown', ['a', 'b'], 2, true),
            new ExportRowFixture('2', 'Null reference, withheld absent', null, null, [], 0, false),
        ];
        $stream = $this->renderer()->render(ExportRowFixture::class, $rows);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        self::assertSame([], $rows, 'The renderer must release each row once written.');
        self::assertStringStartsWith(CsvExportRenderer::BOM, $csv);

        $handle = fopen('php://memory', 'w+b');
        \assert(false !== $handle);
        fwrite($handle, substr($csv, \strlen(CsvExportRenderer::BOM)));
        rewind($handle);
        $parsed = [];
        while (false !== ($row = fgetcsv($handle, null, ',', '"', ''))) {
            $parsed[] = $row;
        }

        self::assertSame([
            $header,
            ['1', 'Has everything', 'r1', 'Ref one', 'shown', '["a","b"]', '2', 'true'],
            ['2', 'Null reference, withheld absent', '', '', '', '[]', '0', 'false'],
        ], $parsed);
        foreach ($parsed as $row) {
            self::assertCount(\count($header), $row, 'A rendered row is not as wide as the header.');
        }
    }

    /**
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function resolve(array $spec, array $schema): array
    {
        if (isset($schema['$ref'])) {
            $name = substr((string) $schema['$ref'], \strlen('#/components/schemas/'));

            return self::resolve($spec, $spec['components']['schemas'][$name] ?? throw new \LogicException('Dangling $ref '.$schema['$ref']));
        }

        foreach (['anyOf', 'oneOf'] as $keyword) {
            if (isset($schema[$keyword])) {
                $branches = array_values(array_filter($schema[$keyword], static fn (array $b): bool => 'null' !== ($b['type'] ?? null)));
                self::assertCount(1, $branches, 'A nullable schema with more than one non-null branch has no single set of leaves.');

                return self::resolve($spec, $branches[0]);
            }
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    private static function leaves(array $spec, array $schema, string $prefix): array
    {
        $leaves = [];
        foreach ($schema['properties'] ?? [] as $name => $property) {
            $resolved = self::resolve($spec, $property);
            $type = $resolved['type'] ?? null;
            $isObject = 'object' === $type || (\is_array($type) && \in_array('object', $type, true));
            if ($isObject && isset($resolved['properties'])) {
                array_push($leaves, ...self::leaves($spec, $resolved, $prefix.$name.'.'));
            } else {
                $leaves[] = $prefix.$name;
            }
        }

        return $leaves;
    }

    private function sources(): ExportSources
    {
        $sources = self::getContainer()->get(ExportSources::class);
        \assert($sources instanceof ExportSources);

        return $sources;
    }

    private function renderer(): CsvExportRenderer
    {
        $renderer = self::getContainer()->get(CsvExportRenderer::class);
        \assert($renderer instanceof CsvExportRenderer);

        return $renderer;
    }
}

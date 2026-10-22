<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Platform\Http\Export\CsvExportRenderer;

/**
 * Shared by the `CreateExport` tests (G6-C0): send an export, read the CSV back as rows, and read a List the same way.
 *
 * @phpstan-require-extends ApiTestCase
 */
trait ExportTestSupport
{
    /**
     * @param array<string, mixed> $body
     */
    protected function export(array $body, string $accept = 'text/csv'): void
    {
        $this->send('POST', '/exports', $body, [], $accept);
    }

    /** The streamed body, as the client received it. */
    protected function exportBody(): string
    {
        return (string) $this->client()->getInternalResponse()->getContent();
    }

    /**
     * The export as rows of cells, header first, BOM asserted and stripped, parsed as RFC 4180 (`escape` disabled, so a
     * backslash is data).
     *
     * @return list<list<string>>
     */
    protected function exportRows(): array
    {
        $body = $this->exportBody();
        self::assertSame(200, $this->httpStatus(), substr($body, 0, 600));
        self::assertStringStartsWith(CsvExportRenderer::BOM, $body, 'The export does not start with the UTF-8 BOM.');

        return self::parseCsv(substr($body, \strlen(CsvExportRenderer::BOM)));
    }

    /** @return list<list<string>> */
    protected static function parseCsv(string $csv): array
    {
        $handle = fopen('php://memory', 'w+b');
        \assert(false !== $handle);
        fwrite($handle, $csv);
        rewind($handle);

        $rows = [];
        while (false !== ($row = fgetcsv($handle, null, ',', '"', ''))) {
            if ([null] === $row) {
                continue;
            }
            $rows[] = array_map('strval', $row);
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Every item a List returns for `$query`, following `next_cursor` to the end.
     *
     * @param array<string, string> $query
     *
     * @return list<array<string, mixed>>
     */
    protected function listAll(string $path, array $query): array
    {
        $items = [];
        $after = null;
        do {
            $q = $query + (null === $after ? [] : ['after' => $after]);
            $this->send('GET', $path.([] === $q ? '' : '?'.http_build_query($q)));
            self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
            $page = $this->json();
            array_push($items, ...$page['items']);
            $after = $page['next_cursor'] ?? null;
        } while (null !== $after);

        return $items;
    }
}

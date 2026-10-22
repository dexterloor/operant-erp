<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\AuditLog\Infrastructure\ExportAuditWriter;
use PHPUnit\Framework\Attributes\Test;

/**
 * **E-16 for `CreateExport`, reconciled with E-17 (G6-C0; design §6.7, L-5; `API.md` §4.4.5).**
 *
 * One `data.export.completed` row per export: SUBJECTLESS, SECURITY (it carries the request's IP), metadata exactly
 * `{resource, filters, sort, row_count}`, written inside the tenancy transaction before COMMIT. A free-text filter (`q`) is
 * recorded as `true` — never its text. An over-cap export writes no row.
 */
final class ExportAuditTest extends ApiTestCase
{
    use ExportTestSupport;

    #[Test]
    public function oneRowRecordsTheResourceTheFiltersAndTheRowCount(): void
    {
        $this->seedItem(self::TENANT_A, 'SKU-1');
        $this->seedItem(self::TENANT_A, 'SKU-2');
        $this->seedItem(self::TENANT_A, 'OTHER-3');

        $this->export(['resource' => 'inventory_item', 'filters' => ['q' => 'SKU', 'archived' => 'false']]);
        $rows = $this->exportRows();
        self::assertCount(1 + 2, $rows, 'A header and the two SKU- items.');

        $trail = $this->auditRowsOf('data.export.completed');
        self::assertCount(1, $trail);
        self::assertNull($trail[0]['subject_type']);
        self::assertNull($trail[0]['subject_id']);
        self::assertNull($trail[0]['permission_code']);
        self::assertSame($this->actorId, $trail[0]['actor_user_id']);
        self::assertNotNull($trail[0]['ip_address'], 'data.export.completed is a SECURITY event and carries the request\'s IP.');
        // jsonb stores keys in its own order, so the comparison is of the decoded object, keys sorted.
        $metadata = json_decode((string) $trail[0]['metadata'], true);
        ksort($metadata);
        ksort($metadata['filters']);
        self::assertSame(
            ['filters' => ['archived' => 'false', 'q' => true], 'resource' => 'inventory_item', 'row_count' => 2, 'sort' => null],
            $metadata,
        );
    }

    /** E-17: no row carries a search term. *Mutation:* record `q`'s value → the distinctive term reaches the trail → red. */
    #[Test]
    public function theSearchTermNeverReachesTheTrail(): void
    {
        $term = 'ZQX-'.bin2hex(random_bytes(4));
        $this->seedItem(self::TENANT_A, $term);

        $this->export(['resource' => 'inventory_item', 'filters' => ['q' => $term]]);
        self::assertCount(2, $this->exportRows());

        $hits = $this->owner()->fetchOne(
            'SELECT count(*) FROM audit_log WHERE tenant_id = ?::uuid AND (metadata::text LIKE ? OR actor_label LIKE ? OR coalesce(subject_type, \'\') LIKE ?)',
            [self::TENANT_A, '%'.$term.'%', '%'.$term.'%', '%'.$term.'%'],
        );
        self::assertSame(0, (int) $hits, 'The search text reached audit_log (E-17).');
        self::assertCount(1, $this->auditRowsOf('data.export.completed'), 'The export wrote no row at all, so the absence above is vacuous.');
    }

    /**
     * `g6-c0-red.md` L-2: an empty `q` applies no search on the List, so the trail does not say a search was applied.
     * *Mutation:* record every free-text filter as `true`, empty or not → `q: true` reaches the row → red.
     */
    #[Test]
    public function anEmptySearchIsNotRecordedAsASearch(): void
    {
        $this->seedItem(self::TENANT_A, 'SKU-1');

        $this->export(['resource' => 'inventory_item', 'filters' => ['q' => '']]);
        self::assertSame(200, $this->httpStatus());

        $rows = $this->auditRowsOf('data.export.completed');
        self::assertCount(1, $rows);
        self::assertSame([], json_decode((string) $rows[0]['metadata'], true)['filters']);
    }

    /**
     * §4.4.5: the count is captured INSIDE the tenancy transaction. The writer refuses outside one (so moving the write
     * after COMMIT is a 500), and the request's statements show the audit INSERT after the List's read in one transaction.
     * *Mutation:* move `recordCompleted()` after `TenantContext::run()` returns → every successful export 500s → red.
     */
    #[Test]
    public function theCountIsCapturedInsideTheTransaction(): void
    {
        $writer = self::getContainer()->get(ExportAuditWriter::class);
        \assert($writer instanceof ExportAuditWriter);
        try {
            $writer->recordCompleted(self::TENANT_A, (string) $this->actorId, 'Fixture Actor', 'inventory_item', [], null, 0, null);
            self::fail('The export audit writer wrote outside a transaction.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('inside the export\'s tenancy transaction', $e->getMessage());
        }

        $this->seedItem(self::TENANT_A, 'SKU-1');
        $this->recorder()->start();
        $this->export(['resource' => 'inventory_item']);
        $this->recorder()->stop();
        self::assertCount(2, $this->exportRows());

        $statements = $this->recorder()->statements();
        $read = self::firstIndex($statements, '/\bFROM inventory_item\b/i');
        $insert = self::firstIndex($statements, '/INSERT INTO audit_log/i');
        self::assertNotNull($read, 'The export issued no read of inventory_item.');
        self::assertNotNull($insert, 'The export wrote no audit row.');
        self::assertGreaterThan($read, $insert, 'The audit row must be written after the read it counts.');
        self::assertSame(1, (int) json_decode((string) $this->auditRowsOf('data.export.completed')[0]['metadata'], true)['row_count']);
    }

    #[Test]
    public function anOverCapExportWritesNoRow(): void
    {
        $cap = (int) self::getContainer()->getParameter('app.export.row_cap');
        for ($i = 1; $i <= $cap + 1; ++$i) {
            $this->seedItem(self::TENANT_A, \sprintf('SKU-%03d', $i));
        }

        $this->export(['resource' => 'inventory_item']);
        self::assertSame(409, $this->httpStatus());
        self::assertSame([], $this->auditRowsOf('data.export.completed'), 'Nothing was exported, so nothing is recorded.');
    }

    /** @param list<string> $statements */
    private static function firstIndex(array $statements, string $pattern): ?int
    {
        foreach ($statements as $index => $sql) {
            if (1 === preg_match($pattern, $sql)) {
                return $index;
            }
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **"What has already been credited against this return line" gets an index** (`docs/session13/owed-data-c5.md` follow-up
 * 2, `DATA`, measured 2026-10-14 on real volume in a rolled-back transaction).
 *
 * `credit_note_line_tenant_return_line_idx ON credit_note_line (tenant_id, sales_return_line_id) WHERE
 * sales_return_line_id IS NOT NULL`. `SalesReturnReader::creditedOnReturnLine()` is read on hot paths —
 * `owesACredit()` (`ListSalesReturns`, `IssueCreditNote`, `DeleteCustomer`'s leg 4), the return-line reads, and
 * `CreditNoteWriter` under the order lock — and no index had `sales_return_line_id` in any position. Measured: each
 * evaluation read every workspace's `credit_note_line` rows and applied RLS's `tenant_id` predicate as a **Filter**, not an
 * `Index Cond` (the failure `SchemaQueryPlanTest::assertPlan()` refuses), so one large workspace slowed every other
 * workspace's credit paths. With this index each evaluation is an `Index Scan` (~0.002 ms).
 *
 * Partial, because adjustment lines carry NULL and are never looked up by return line (`sales_return_tenant_invoice_idx`'s
 * shape); the query's strict equality implies `IS NOT NULL`, so the index serves custom and generic plans alike. Plain
 * `CREATE INDEX` inside the house's transactional migration — the table is small; use `CONCURRENTLY` with
 * `isTransactional(): false` only if this ever runs against a large hosted table. No grant: an index needs none.
 * `SchemaQueryPlanTest::theCreditedOnReturnLineSubqueryUsesTheReturnLineIndex` pins it; the ORM attribute on
 * `CreditNoteLine` matches it (`DoctrineMappingTest`).
 */
final class Version20261015120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'credit_note_line_tenant_return_line_idx: what has been credited against a return line, without reading other workspaces';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX credit_note_line_tenant_return_line_idx ON credit_note_line (tenant_id, sales_return_line_id) WHERE sales_return_line_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX credit_note_line_tenant_return_line_idx');
    }
}

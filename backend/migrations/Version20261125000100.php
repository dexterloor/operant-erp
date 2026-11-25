<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **The customer name, normalised, as a stored column with its index** (BK-47, 2026-11-25; owner ruling "Have the new column
 * and its index in BK-47 now"; `docs/session13/bk47-option-a-design.md`). `CustomerWriter::NAME_SHARED` compares a new name
 * against every other customer's, live or archived; it reads this column, so the comparison is an index condition.
 *
 * **Why a generated column and not an expression index on `lower(btrim(name))`:** under row security the planner never uses
 * such an index — `lower` and `btrim` are not leakproof, so a clause applying them to a column cannot be evaluated ahead
 * of the row-security qual (measured on a scratch database, `docs/session13/bk47-measurements.md` Session 78). Here the
 * functions apply to the bound value only, and the column is compared bare.
 *
 * The trim set is written with `chr()`: space, tab, line feed, carriage return, vertical tab — the second copy of
 * `CustomerWriter::NAME_TRIM_CHARACTERS` (a standard string `' \t\n\r\x0B'` would trim the letters t, n, r, B). The two
 * copies are held equal by `CustomerSharedNameTest` (P-1). Not partial: archived customers count. `ADD COLUMN … STORED`
 * rewrites the table under ACCESS EXCLUSIVE (about 0.9 s at 300,000 rows, measured); no grant changes — the table-level
 * SELECT covers the column, and nothing may insert or update a generated column (`GrantFootprintTest` skips generated
 * columns, derived from `pg_attribute.attgenerated`, and pins that `erp_app` holds neither).
 *
 * After any change to the server's case mapping (an image bump on the same volume, a glibc or ICU server, a collation
 * change) the stored values must be recomputed: `RESIDUAL_GAPS.md` G-39.
 *
 * `customer.name_normalised` (BK-47): derived from `name` by the database (lower-cased, outer white space trimmed); same
 * class as `name`, personal data whenever `name` is, and follows any change or erasure of `name` automatically; readable
 * wherever `name` is (`erp_app`, `erp_ai` SELECT, inherited); written by nothing. SEC TO CONFIRM.
 */
final class Version20261125000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'customer.name_normalised (generated) and customer_tenant_name_normalised';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE customer ADD COLUMN name_normalised text GENERATED ALWAYS AS (lower(btrim(name, ' ' || chr(9) || chr(10) || chr(13) || chr(11)))) STORED");
        $this->addSql('CREATE INDEX customer_tenant_name_normalised ON customer (tenant_id, name_normalised)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX customer_tenant_name_normalised');
        $this->addSql('ALTER TABLE customer DROP COLUMN name_normalised');
    }
}

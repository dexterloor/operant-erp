<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Customers slice 1, C0 — `customer.country_code`, which §4.2 has always accepted and the table never had.**
 *
 * `API.md` §4.2's `CreateCustomer` / `UpdateCustomer` rows accept `country_code`; the table carried no such column
 * (`Version20260906000100`), so the first writer would have had nowhere to put it. Decided 2026-10-09 (customers
 * slice 1, D2): **`text NOT NULL`, with no default, foreign-keyed onto the `country` reference table** exactly as
 * `customer_address_country_fk` and `warehouse.country_code` are.
 *
 * **No backfill, and this migration fails loudly on a database that holds a customer row.** That is deliberate,
 * `Version20260922000100`'s shape: no value this file could invent for an existing customer would be true. A
 * `DEFAULT` would give every existing row a silent wrong country; a backfill from the first shipping address is a
 * guess that can still leave NULLs. Precondition, verified as far as it can be: nothing in `src/` has ever written
 * `customer` (`erp_app` held SELECT only since `Version20260916000300`), no deployed database exists, the demo seed
 * is deferred, and test fixtures insert after migrating.
 *
 * **Grants: none.** A new column inherits the table-level ACL (M-1, re-measured for this column in this commit with
 * `erp_ai` INSERT = false as the positive control), so it arrives readable and not writable. `CreateCustomer`'s
 * column INSERT lands with its writer (customers slice 1 C2, ADR-002 A4.1).
 *
 * Locks: `ADD COLUMN … NOT NULL` with no default on an empty table is catalogue-only; the foreign key scans an empty
 * table.
 */
final class Version20261009000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Customers slice 1 C0: customer.country_code text NOT NULL, FK country, no default';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer ADD COLUMN country_code text NOT NULL');
        $this->addSql('ALTER TABLE customer ADD CONSTRAINT customer_country_fk FOREIGN KEY (country_code) REFERENCES country (code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer DROP CONSTRAINT IF EXISTS customer_country_fk');
        $this->addSql('ALTER TABLE customer DROP COLUMN IF EXISTS country_code');
    }
}

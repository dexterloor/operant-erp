<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **`cascade_deletion_to_lines` keeps an individually discarded line discarded** (Output 9, sales slice 5, commit 1,
 * 2026-09-21 — V2-R-12b's second trigger, and the owner's Q3 ruling).
 *
 * ---------------------------------------------------------------------------------------------------
 * What the shipped body did, measured (`DeletionPolicyTest`, both parents)
 * ---------------------------------------------------------------------------------------------------
 * `Version20260906000100` wrote ONE statement for both directions:
 * `UPDATE <child> SET deleted_at = $1 … WHERE … AND deleted_at IS DISTINCT FROM $1`. That was correct while a line could
 * only ever be archived WITH its order. It stopped being correct the day a line could be archived on its own
 * (`DeletePurchaseOrderLine`, then `DeleteSalesOrderLine`):
 *
 *   - **archive:** `IS DISTINCT FROM $1` matched a line the user had ALREADY discarded, and re-stamped it with the order's
 *     instant. From then on nothing could tell it from the lines the order took with it. On purchasing this is visible on
 *     the wire today: `GET /purchase-orders/{id}/lines?archived=true` reports the order's `archived_at` for a line the user
 *     deleted earlier. **A shipped purchasing behaviour changes here, deliberately: that `archived_at` is no longer
 *     re-stamped.**
 *   - **restore:** clearing the order's stamp set EVERY line live — silently resurrecting a line deleted on purpose when
 *     nothing collided, and raising `23505` on `…_line_unique` (first) or `…_line_tenant_idem_live` when a replacement had
 *     taken the discarded line's number or retry key.
 *
 * ---------------------------------------------------------------------------------------------------
 * The new body, and the identity it rests on
 * ---------------------------------------------------------------------------------------------------
 *   - **archive** (`OLD` NULL → `NEW` set): LIVE lines only — `deleted_at IS NULL`.
 *   - **restore or re-stamp** (anything else): only the lines carrying the parent's **OLD** stamp — `deleted_at = OLD`.
 *
 * The owner ruled Q3 on 2026-09-21: **a restore revives only the lines deleted with the order.** This is that rule, in
 * the only place that sees every writer, raw SQL included.
 *
 * **The identity is "same stamp", and what it needs is DISTINCTNESS, not order** (`RED` §2.2, measured: a line stamped
 * LATER than its order is handled correctly too). A line archived on its own must not carry exactly the instant its
 * order is later archived with. On the API path a line archive and its order's archive take the same order row lock in
 * two different transactions, so their stamps come from two different instants. `now()` is the transaction's START, so
 * two such stamps coincide only if both transactions began in the same microsecond; the sales delete (`DeleteSalesOrder`,
 * built after this commit) stamps with `clock_timestamp()` taken after the lock is held, which removes even that. Either
 * way the instants are **distinct except across a backward wall-clock step** — which this project's own Colima clock-skew
 * procedure (`date -s`) can take; even then a collision needs an exact microsecond match. **Where it genuinely does not hold:** raw
 * SQL that archives a line and its order in ONE transaction with `now()` — both get the transaction's start instant, and
 * the line then returns with the order. That is a schema-owner fixture shape, not an API path; disclosed, not engineered
 * against. A marker column would close it, at the price of a column on BOTH line tables; recorded as the fallback.
 *
 * ---------------------------------------------------------------------------------------------------
 * `CREATE OR REPLACE` in BOTH directions — never `DROP` + `CREATE`
 * ---------------------------------------------------------------------------------------------------
 * `CREATE OR REPLACE` with the identical `()` signature keeps the function's owner, its ACL (`erp_app=X`, no PUBLIC) and
 * its `SECURITY INVOKER`, and leaves both triggers attached — measured by `RED` inside a rolled-back transaction. A
 * `DROP FUNCTION` would reset the ACL to PUBLIC EXECUTE (`GrantFootprintTest` catches that), drop `erp_app`'s EXECUTE so
 * every cascade fails `42501`, and under `CASCADE` silently remove **both** triggers. `down()` therefore restores the
 * shipped body verbatim by the same route. `SchemaGuardTest::theSchemaContainsNoSecurityDefinerObjects` forbids
 * "fixing" anything here with `SECURITY DEFINER`. **Grants nothing.**
 *
 * **No CHECK here.** `sales_order_archived_only_while_draft` (CD-4) is a sales-only ruling, not a defect fix; it ships in
 * its own commit.
 */
final class Version20260921000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'V2-R-12b / Q3: cascade_deletion_to_lines archives only live lines and restores only the lines archived with their order';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION cascade_deletion_to_lines() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.deleted_at IS NULL AND NEW.deleted_at IS NOT NULL THEN
                    -- archive: LIVE lines only; a line discarded on its own keeps its own stamp
                    EXECUTE format(
                        'UPDATE %I SET deleted_at = $1, updated_at = now() '
                        'WHERE tenant_id = $2 AND %I = $3 AND deleted_at IS NULL',
                        TG_ARGV[0], TG_ARGV[1]
                    ) USING NEW.deleted_at, NEW.tenant_id, NEW.id;
                ELSE
                    -- restore (NEW NULL) or re-stamp: only the lines the parent's own archive stamped
                    EXECUTE format(
                        'UPDATE %I SET deleted_at = $1, updated_at = now() '
                        'WHERE tenant_id = $2 AND %I = $3 AND deleted_at = $4',
                        TG_ARGV[0], TG_ARGV[1]
                    ) USING NEW.deleted_at, NEW.tenant_id, NEW.id, OLD.deleted_at;
                END IF;

                RETURN NULL;
            END;
            $$
            SQL);
    }

    public function down(Schema $schema): void
    {
        // The shipped body of Version20260906000100, verbatim, by the same CREATE OR REPLACE route (see the docblock).
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION cascade_deletion_to_lines() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                EXECUTE format(
                    'UPDATE %I SET deleted_at = $1, updated_at = now() '
                    'WHERE tenant_id = $2 AND %I = $3 AND deleted_at IS DISTINCT FROM $1',
                    TG_ARGV[0], TG_ARGV[1]
                ) USING NEW.deleted_at, NEW.tenant_id, NEW.id;

                RETURN NULL;
            END;
            $$
            SQL);
    }
}

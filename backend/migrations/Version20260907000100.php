<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * B-1 replacement action 1 — close queue DML on `erp_app`.
 *
 * Version20260906000100 withdrew the blanket `ALTER DEFAULT PRIVILEGES` and
 * wrote every grant out per table, which is what made this one visible. It then
 * wrote the queue grant out longhand with its own comment saying an INSERT here
 * is worker code execution by another name — and granted it anyway, because the
 * grant already existed and the migration was reproducing the footprint rather
 * than deciding it. `SchemaManifest` declared the same four privileges, and two
 * tests asserted they were present. **The exposure had four committed artifacts
 * pinning it in place and no artifact closing it.** A ruling is not a migration;
 * this is the migration.
 *
 * ---------------------------------------------------------------------------
 * Why now, with nothing to enqueue
 * ---------------------------------------------------------------------------
 * `symfony/messenger` is not installed — 14 packages in `composer.json`, nothing
 * in `vendor/symfony/`, no `messenger.yaml`. Nothing dispatches and nothing
 * consumes, so the grant is dead and this costs nothing today. That is the
 * argument *for* doing it now rather than against: deferring it means the queue
 * is writable by the request-serving role by default on the day Messenger is
 * installed, decided by nobody.
 *
 * ADR-006 V-10 / ARCHITECTURE S-13 is the exposure. `messenger_messages` has no
 * `tenant_id`, therefore no RLS (S-8: a worker must select the next message
 * before it knows the tenant), so a row `erp_app` inserts is a payload no policy
 * inspects, consumed by a worker that executes what the payload names.
 *
 * ---------------------------------------------------------------------------
 * What is revoked, and what deliberately is not
 * ---------------------------------------------------------------------------
 * `SELECT` stays, and the reason is **not** that it keeps consumption working.
 * It does not. `DELIVERY_LEDGER.md:113` records the split: Messenger's Doctrine
 * transport dispatches with `INSERT` — the application's own write — and
 * consumes with `SELECT … FOR UPDATE`, `UPDATE` and `DELETE`, which are the
 * *consumer's*. Revoking `UPDATE`/`DELETE` already makes `messenger:consume`
 * impossible for `erp_app`, so the retained `SELECT` preserves no working path.
 *
 * It is kept as a **named residual, not a justification**: `erp_app` can read
 * every tenant's serialized payloads on a table with no RLS (S-8). That is a
 * real cross-tenant read, it is declared in `SchemaManifest` so the footprint
 * test pins it as present rather than letting it drift, and its disposition
 * belongs to **B-1 action 4** — the worker-principal question — not here.
 * Revoking it now would decide that question by side effect.
 *
 * The **sequence** goes with the write. `Version20260905000100:39` recorded that
 * the sequence grant "is not decorative — messenger_messages uses a BIGSERIAL
 * id", i.e. it exists solely to serve `nextval()` on an INSERT. With the INSERT
 * denied it is a live privilege whose only justification has been deleted, and
 * it is exactly what a later reintroduced INSERT would ride on without anyone
 * re-reviewing the sequence.
 *
 * ---------------------------------------------------------------------------
 * The coupling this creates, which is intentional
 * ---------------------------------------------------------------------------
 * Messenger can no longer be installed and work without **B-1 action 2** also
 * being done: a `SECURITY DEFINER` dispatch function, owned by the schema owner,
 * `EXECUTE` granted to `erp_app`, taking typed and validated parameters rather
 * than a caller-supplied serialized body. That design is not built here and must
 * not be improvised at install time — a definer function that accepts a message
 * body as an argument restores V-10 in full while every *privilege* check in the
 * suite stays green.
 *
 * That coupling is a control rather than a hope only because
 * `GrantFootprintTest::noSecurityDefinerFunctionExistsInAnyNonSystemSchema`
 * lands with this migration. Without it the revoke would guard the loud path
 * with five assertion sites and leave the quiet one entirely unwatched. That
 * check is ADR-001's T-5 — specified at Output 1, never implemented until now;
 * nothing in the suite read `prosecdef` before this change.
 *
 * ---------------------------------------------------------------------------
 * Locks (postgres-database-migration)
 * ---------------------------------------------------------------------------
 * `REVOKE` takes `AccessExclusiveLock` on the table for the catalogue update and
 * does not scan or rewrite it, so it is metadata-only and completes in
 * milliseconds at any table size. It is not in that skill's DDL tables at all —
 * the risk it carries is not duration but ordering: on a live system the lock
 * still queues behind a long-running query and blocks everything behind it, so a
 * `lock_timeout` would be required. Nothing is deployed and the queue is empty,
 * so this runs unguarded here. Recorded so the pattern is not copied.
 *
 * A forward migration rather than an edit to Version20260906000100:
 * `doctrine_migration_versions` already records that migration as executed, so
 * amending it in place would leave every database that has run it — including
 * the one this was measured on — silently divergent from the file, which is the
 * project's primary defect class. That migration's own docblock also states that
 * "a migration is frozen history and must not change behaviour"; editing it to
 * close a hole would contradict the invariant it declares. This form is also
 * reversible and legible in git as a security change rather than as a two-line
 * edit inside 74 KB.
 */
final class Version20260907000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'B-1: revoke INSERT/UPDATE/DELETE on messenger_messages, and its sequence, from erp_app. SELECT stays.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('REVOKE INSERT, UPDATE, DELETE ON messenger_messages FROM erp_app');

        // ALL rather than USAGE, SELECT: the declared footprint is now empty, so
        // naming the two privileges that happen to be held would leave UPDATE —
        // a sequence reset — reachable if it were ever granted. An exhaustive
        // revoke does not need a privilege list to stay current.
        $this->addSql('REVOKE ALL ON SEQUENCE messenger_messages_id_seq FROM erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('GRANT INSERT, UPDATE, DELETE ON messenger_messages TO erp_app');
        $this->addSql('GRANT USAGE, SELECT ON SEQUENCE messenger_messages_id_seq TO erp_app');
    }
}

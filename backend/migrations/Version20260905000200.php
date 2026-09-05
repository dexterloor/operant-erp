<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * R-34, made falsifiable.
 *
 * This migration contains no GRANT of any kind. It exists so that the suite can
 * prove that a table created after the grant block is still reachable by
 * erp_app -- which is only true because Version20260905000100 issued
 * ALTER DEFAULT PRIVILEGES. Add a GRANT here and the test stops testing anything.
 *
 * stock_movement is tenant-scoped, so it must also pick up ENABLE + FORCE and a
 * policy of its own: default privileges carry rights forward, but RLS defaults
 * to off on every new table and nothing carries that forward. That asymmetry is
 * the whole reason the drift check exists.
 *
 * messenger_messages is not tenant-scoped -- ADR-002 §7 propagates tenancy
 * through a TenantIdStamp, not a column. Its BIGSERIAL id is the real case for
 * the sequence grant. (Note for Output 2: a column declared
 * GENERATED ALWAYS AS IDENTITY needs no sequence privilege at all; BIGSERIAL is
 * used here only because that is what Symfony Messenger's own transport creates.)
 */
final class Version20260905000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'stock_movement (tenant-scoped) and messenger_messages (BIGSERIAL) -- deliberately with no GRANT';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE stock_movement (
                id           uuid        NOT NULL,
                tenant_id    uuid        NOT NULL,
                quantity     integer     NOT NULL,
                occurred_at  timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT stock_movement_pkey             PRIMARY KEY (id),
                CONSTRAINT stock_movement_tenant_id_id_key UNIQUE (tenant_id, id)
            )
            SQL);
        $this->addSql('CREATE INDEX stock_movement_tenant_occurred_idx ON stock_movement (tenant_id, occurred_at)');

        $this->addSql('ALTER TABLE stock_movement ENABLE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE stock_movement FORCE  ROW LEVEL SECURITY');
        $this->addSql(<<<'SQL'
            CREATE POLICY tenant_isolation ON stock_movement
                USING      (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)
                WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE messenger_messages (
                id            BIGSERIAL   NOT NULL PRIMARY KEY,
                body          text        NOT NULL,
                headers       text        NOT NULL,
                queue_name    text        NOT NULL,
                created_at    timestamptz NOT NULL DEFAULT now(),
                available_at  timestamptz NOT NULL DEFAULT now(),
                delivered_at  timestamptz DEFAULT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX messenger_messages_queue_name_idx   ON messenger_messages (queue_name)');
        $this->addSql('CREATE INDEX messenger_messages_available_at_idx ON messenger_messages (available_at)');
        $this->addSql('CREATE INDEX messenger_messages_delivered_at_idx ON messenger_messages (delivered_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS messenger_messages');
        $this->addSql('DROP TABLE IF EXISTS stock_movement');
    }
}

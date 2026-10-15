<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stock reads C2 — **the movement ledger's two list indexes gain a trailing `id`** (`DATA`,
 * `docs/session13/stock-reads-data.md` §1; unconditional, overriding the design's "only if T-5′ shows a Sort"). No grant,
 * no behaviour change: `ListStockMovements` reads the same rows in the same order; only the cost of a page changes.
 *
 * **Why `id` is in the index.** `stock_movement.occurred_at` is the caller's business time at whole-second precision —
 * `StockReceiver`, `StockDepleter` and `StockReturner` each bind `->occurredAt->format(\DATE_ATOM)` — so every document
 * back-dated to one second shares a TIE GROUP, and nothing bounds its size. The list's keyset is
 * `(occurred_at, id) < (?, ?)` ordered `occurred_at DESC, id DESC`. With `(tenant_id, occurred_at)` the planner can use only
 * a truncated `occurred_at <= ?` as the index condition and must `Incremental Sort` the whole group on every page. With
 * `id` as the next column the whole row comparison is an index condition and a backward index scan stops at `LIMIT`.
 * `SchemaQueryPlanTest`'s two movement-ledger tests pin that plan inside a 5,000-row group.
 *
 * Column order: `tenant_id` first (the RLS policy is an equality on it), then `inventory_item_id` in the filtered index (an
 * equality too), then `(occurred_at, id)` consecutive — the only position where `ROW(occurred_at, id) < ROW(…)` can be an
 * index condition. No `DESC` (a backward scan serves `DESC, DESC`; `SCHEMA.md` §9), no partial predicate (deletion class
 * `none`, no `deleted_at`). The item index's `(tenant_id, inventory_item_id, occurred_at)` prefix still serves ADR-004's
 * forecast input (`SchemaQueryPlanTest::theForecastInputUsesTheItemHistoryIndex`).
 *
 * Create before drop. Plain `CREATE INDEX`, not `CONCURRENTLY`: migrations run in a transaction and no real-volume tenant
 * exists — revisit at the first non-demo tenant. `down()` recreates the two old indexes with their exact old definitions
 * (`Version20260905000200`, `Version20260906000100`), then drops the new ones.
 */
final class Version20261015000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stock reads C2: end both stock_movement list indexes with (occurred_at, id).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX stock_movement_tenant_occurred_id_idx ON stock_movement (tenant_id, occurred_at, id)');
        $this->addSql('CREATE INDEX stock_movement_tenant_item_occurred_id_idx ON stock_movement (tenant_id, inventory_item_id, occurred_at, id)');
        $this->addSql('DROP INDEX stock_movement_tenant_occurred_idx');
        $this->addSql('DROP INDEX stock_movement_tenant_item_idx');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE INDEX stock_movement_tenant_occurred_idx ON stock_movement (tenant_id, occurred_at)');
        $this->addSql('CREATE INDEX stock_movement_tenant_item_idx ON stock_movement (tenant_id, inventory_item_id, occurred_at)');
        $this->addSql('DROP INDEX stock_movement_tenant_occurred_id_idx');
        $this->addSql('DROP INDEX stock_movement_tenant_item_occurred_id_idx');
    }
}

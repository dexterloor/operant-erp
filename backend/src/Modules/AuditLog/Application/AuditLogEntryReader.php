<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Application;

use App\Platform\Http\Pagination\Cursor;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;

/**
 * **The read behind `GET /audit-log` — `ListAuditLogEntries`** (audit reads C1; `docs/session13/audit-reads-design.md`
 * §3.1, §5 and Revision 1 R1.3; PC-11's whole-trail half, `audit_log.view`).
 *
 * **Every event, every actor** (PC-11): no allowlist here — that is `ListAuditRecords`' bound, not this read's. Runs as
 * `erp_app` inside {@see TenantContext::run()}, so `audit_log`'s FORCE RLS tenant predicate applies; never an autowired
 * `Connection` (the schema owner).
 *
 * **Order: newest first**, `occurred_at DESC, id DESC`, in all three views. Every writer stamps `occurred_at` with `now()`,
 * the TRANSACTION start instant, so every row one transaction writes shares it — a transfer's two
 * `stock_movement.adjusted` rows, a bulk assignment's N `role.assigned` rows — and nothing bounds such a TIE GROUP. The
 * keyset is therefore the pair, as ONE row comparison `(a.occurred_at, a.id) < (?::timestamptz, ?::uuid)` (forward; `>`
 * backward, reversed in PHP), served by `audit_log_tenant_occurred_id_idx` / `…_actor_occurred_id_idx` /
 * `…_event_occurred_id_idx`, which end `(occurred_at, id)` so the whole comparison is an index condition and `LIMIT` stops
 * the scan inside a group (C1's migration, `Version20261021000100`; the stock-ledger precedent, `Version20261015000100`).
 *
 * **The page is cut from `audit_log` ALONE, and only then joined to the actor's live name** — the shipped-defect lesson of
 * `StockMovementReader` (2026-10-16): a join beside the `LIMIT` let the planner flip, seed by seed, to a plan that read and
 * sorted a whole tie group. The join is **LEFT**: `actor_user_id` is nullable (a system action has no actor), and a row
 * with no actor must not vanish (mutation M5). Where it is set, `audit_log_actor_fk` makes it name an `app_user` row and
 * `app_user` is soft-delete only, so the join neither drops nor multiplies a row. **The live name is an A-2 cross-module SQL
 * read of `app_user`** (a `UserManagement` table) from `AuditLog` — the residual the project already carries for the
 * Purchasing and Sales readers that join `app_user` (`ARCH` TO CONFIRM, design §9).
 *
 * **The cursor key is the column's text exactly as Postgres returns it** (microseconds and offset), never the wire's
 * millisecond rendering: audit rows carry `now()`'s microseconds, so a millisecond key would skip or repeat rows. The
 * provider refuses a cursor whose key is not such a timestamp (`malformed_cursor`) before the cast can raise.
 *
 * **What a forward walk can miss (L-8, inherent, stated not fixed):** `now()` is the transaction START, so a transaction
 * that began before a reader's page and committed after it lands BEHIND the cursor with an earlier instant. **And what
 * the rows prove (A-3's shape):** `erp_app` holds `INSERT` on `audit_log`, so SQL running as `erp_app` can write a row this
 * read serves as genuine. The trail is evidence against an in-app actor, not against the database.
 *
 * An `actor_user_id` naming no visible user — unknown, or another workspace's — is a predicate nothing satisfies under RLS:
 * the same empty page, never a 404.
 */
final readonly class AuditLogEntryReader
{
    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
    ) {
    }

    public function list(AuditLogListCriteria $criteria): AuditLogPage
    {
        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em) use ($criteria): AuditLogPage {
            $connection = $em->getConnection();
            [$sql, $parameters] = self::listStatement($criteria);

            // One row more than the page answers "is there another page" without a COUNT (TB-2b).
            $rows = $connection->fetchAllAssociative($sql, $parameters);
            $hasMore = \count($rows) > $criteria->limit;
            if ($hasMore) {
                array_splice($rows, $criteria->limit);
            }
            if ($criteria->isBackwards()) {
                $rows = array_reverse($rows);
            }

            [$next, $previous] = self::pageCursors($criteria->isBackwards(), null !== $criteria->after, $rows, $hasMore);

            return new AuditLogPage(
                items: array_map(self::view(...), $rows),
                nextCursor: $next,
                previousCursor: $previous,
                // API-22: an EXISTS probe with no filter, only when the page is empty.
                tenantHasRows: [] === $rows ? (bool) $connection->fetchOne('SELECT EXISTS (SELECT 1 FROM audit_log)') : true,
            );
        });
    }

    /**
     * The one statement a `ListAuditLogEntries` page runs — public so `SchemaQueryPlanTest`'s audit-trail tests explain
     * **this** statement, never a hand copy of it (the `StockMovementReader::listStatement()` precedent).
     *
     * @return array{string, list<mixed>}
     */
    public static function listStatement(AuditLogListCriteria $criteria): array
    {
        $where = [];
        $parameters = [];

        if (null !== $criteria->actorUserId) {
            $where[] = 'a.actor_user_id = ?::uuid';
            $parameters[] = $criteria->actorUserId;
        }
        if (null !== $criteria->eventType) {
            $where[] = 'a.event_type = ?';
            $parameters[] = $criteria->eventType;
        }

        $descending = !$criteria->isBackwards();
        $cursor = $criteria->after ?? $criteria->before;
        if (null !== $cursor) {
            // API-18: the pair as ONE row comparison, never the key alone — a tie group shares its `occurred_at`.
            $where[] = \sprintf('(a.occurred_at, a.id) %s (?::timestamptz, ?::uuid)', $descending ? '<' : '>');
            $parameters[] = $cursor->sortKey;
            $parameters[] = $cursor->id;
        }

        $direction = $descending ? 'DESC' : 'ASC';
        $order = \sprintf(' ORDER BY a.occurred_at %s, a.id %s', $direction, $direction);
        // The page first, from `audit_log` alone, so `LIMIT` stops the index scan whatever join follows; then the actor's
        // live name, LEFT (a row with no actor stays), and the order again over at most `limit + 1` rows.
        $sql = self::SELECT
            .' FROM (SELECT '.self::ENTRY_COLUMNS.' FROM audit_log a'
            .([] === $where ? '' : ' WHERE '.implode(' AND ', $where))
            .$order.' LIMIT ?) a '
            .self::JOINS
            .$order;
        $parameters[] = $criteria->limit + 1;

        return [$sql, $parameters];
    }

    private const SELECT = <<<'SQL'
        SELECT a.id, a.occurred_at, a.event_type, a.actor_user_id, u.display_name AS actor_display_name, a.actor_label,
               a.subject_type, a.subject_id, a.metadata, a.ip_address
        SQL;

    /** What the derived page carries: everything {@see self::SELECT} and {@see self::JOINS} read from `a`. */
    private const ENTRY_COLUMNS = 'a.id, a.tenant_id, a.occurred_at, a.event_type, a.actor_user_id, a.actor_label, a.subject_type, a.subject_id, a.metadata, a.ip_address';

    private const JOINS = 'LEFT JOIN app_user u ON u.tenant_id = a.tenant_id AND u.id = a.actor_user_id';

    /**
     * A page's two cursors over the `(occurred_at, id)` keyset — shared with {@see AuditRecordReader} (audit reads C2), which
     * pages the same table on the same key.
     *
     * @param list<array<string, mixed>> $rows in the list's own order
     *
     * @return array{?string, ?string} next, previous
     */
    public static function pageCursors(bool $backwards, bool $hasAfter, array $rows, bool $hasMore): array
    {
        if ([] === $rows) {
            return [null, null];
        }

        // `occurred_at` is NOT NULL, so `6fe8b7d`'s empty-key cursor cannot arise. The key is the raw column text.
        $first = new Cursor((string) $rows[0]['occurred_at'], (string) $rows[0]['id']);
        $lastRow = $rows[array_key_last($rows)];
        $last = new Cursor((string) $lastRow['occurred_at'], (string) $lastRow['id']);

        if ($backwards) {
            return [$last->encode(), $hasMore ? $first->encode() : null];
        }

        return [$hasMore ? $last->encode() : null, $hasAfter ? $first->encode() : null];
    }

    /** @param array<string, mixed> $row */
    private static function view(array $row): AuditLogEntryView
    {
        $metadata = json_decode((string) $row['metadata'], true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($metadata) || (array_is_list($metadata) && [] !== $metadata)) {
            throw new \LogicException(\sprintf('audit_log %s carries metadata that is not a JSON object.', (string) $row['id']));
        }
        $hasActor = null !== $row['actor_user_id'];

        return new AuditLogEntryView(
            id: (string) $row['id'],
            occurredAt: new \DateTimeImmutable((string) $row['occurred_at'])->format(\DateTimeInterface::RFC3339_EXTENDED),
            eventType: (string) $row['event_type'],
            actorId: $hasActor ? (string) $row['actor_user_id'] : null,
            actorDisplayName: $hasActor ? (string) $row['actor_display_name'] : null,
            actorLabel: (string) $row['actor_label'],
            subjectType: null === $row['subject_type'] ? null : (string) $row['subject_type'],
            subjectId: null === $row['subject_id'] ? null : (string) $row['subject_id'],
            metadata: $metadata,
            ipAddress: null === $row['ip_address'] ? null : (string) $row['ip_address'],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Application;

use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\ValueObject\AuditEvent;
use Doctrine\ORM\EntityManagerInterface;

/**
 * **The read behind `GET /audit-log/records` — `ListAuditRecords`**, one record's History (audit reads C2;
 * `docs/session13/audit-reads-design.md` §3.2, §4.2, §5.3 and R1.5; PC-11's colleague-readable half,
 * `audit_log.view_record`). The caller's subject code has already been checked ({@see AuditRecordAccess}, E-5).
 *
 * **Bounded to ONE subject** — `subject_type = ? AND subject_id = ?::uuid`, both halves (a uuid alone would leak across
 * types: mutation N7) — and **only allowlisted events**: the `IN` list is built from
 * {@see AuditEvent::isReadableAsRecordHistory()} at runtime and bound as parameters — the method, never a typed copy of
 * `BUSINESS` and never an exclusion (`NOT IN` / `NOT LIKE` / `<> ALL`; PC-11's own warning, mutation N3's source scan). As a
 * second, independent layer every fetched row is checked against the method again before it is mapped: a miss means the
 * SQL and the method disagree, and is a `LogicException`, never a row served. So denials and sign-ins naming a business
 * subject (`authz.permission.denied` rows carry one) never appear, and `role.*` — SECURITY events subjecting `app_user`
 * and `role` — make those Histories an empty page.
 *
 * **No `metadata`, no `ip_address`** (§4.4.3; `SEC`/`PO` C.2). **Code-level, not row-level** (H-3 (ii)): no
 * "is the row still openable" filter — a deleted record's History is served by id to its type's code holders
 * (`RESIDUAL_GAPS.md` G-52; mutation N16).
 *
 * **Order, keyset, statement shape: `AuditLogEntryReader`'s exactly** (including what a stamp means under the
 * audit-order trigger, `Version20261022004000`) — newest first, `(occurred_at, id)` as one row
 * comparison, the page cut from `audit_log` alone, then `LEFT JOIN app_user` for the live name. Served by
 * `audit_log_tenant_subject_occurred_id_idx (tenant_id, subject_type, subject_id, occurred_at, id)` (C2's migration,
 * `Version20261021000200`), so a tie group pages without a sort; the allowlist is a `Filter`, discarding only security
 * rows on this subject (denials, capped per actor/code/day by E-3a). Runs as `erp_app` inside {@see TenantContext::run()}.
 * The same caveats as the trail: SQL as `erp_app` can forge a row (A-3), and a forward walk can miss a late commit (L-8).
 *
 * An unknown id, another workspace's id, and a real id with no allowlisted event are the same empty page (never a 404).
 */
final readonly class AuditRecordReader
{
    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
    ) {
    }

    public function list(AuditRecordCriteria $criteria): AuditRecordPage
    {
        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em) use ($criteria): AuditRecordPage {
            $connection = $em->getConnection();
            [$sql, $parameters] = self::listStatement($criteria);

            $rows = $connection->fetchAllAssociative($sql, $parameters);
            $hasMore = \count($rows) > $criteria->limit;
            if ($hasMore) {
                array_splice($rows, $criteria->limit);
            }
            if ($criteria->isBackwards()) {
                $rows = array_reverse($rows);
            }

            [$next, $previous] = AuditLogEntryReader::pageCursors($criteria->isBackwards(), null !== $criteria->after, $rows, $hasMore);

            return new AuditRecordPage(
                items: array_map(self::view(...), $rows),
                nextCursor: $next,
                previousCursor: $previous,
                // API-22: an EXISTS probe with no filter, only when the page is empty (design §9: unfiltered, `SEC` TO CONFIRM).
                tenantHasRows: [] === $rows ? (bool) $connection->fetchOne('SELECT EXISTS (SELECT 1 FROM audit_log)') : true,
            );
        });
    }

    /**
     * The events a History may show — {@see AuditEvent::isReadableAsRecordHistory()} over the catalogue, derived here and
     * nowhere else.
     *
     * @return list<string>
     */
    public static function admittedEvents(): array
    {
        return array_values(array_filter(AuditEvent::ALL, AuditEvent::isReadableAsRecordHistory(...)));
    }

    /**
     * The one statement a `ListAuditRecords` page runs — public so `SchemaQueryPlanTest` explains THIS statement.
     *
     * @return array{string, list<mixed>}
     */
    public static function listStatement(AuditRecordCriteria $criteria): array
    {
        $admitted = self::admittedEvents();
        $where = [
            'a.subject_type = ?',
            'a.subject_id = ?::uuid',
            'a.event_type IN ('.implode(', ', array_fill(0, \count($admitted), '?')).')',
        ];
        $parameters = [$criteria->subjectType, $criteria->subjectId, ...$admitted];

        $descending = !$criteria->isBackwards();
        $cursor = $criteria->after ?? $criteria->before;
        if (null !== $cursor) {
            $where[] = \sprintf('(a.occurred_at, a.id) %s (?::timestamptz, ?::uuid)', $descending ? '<' : '>');
            $parameters[] = $cursor->sortKey;
            $parameters[] = $cursor->id;
        }

        $direction = $descending ? 'DESC' : 'ASC';
        $order = \sprintf(' ORDER BY a.occurred_at %s, a.id %s', $direction, $direction);
        $sql = self::SELECT
            .' FROM (SELECT '.self::RECORD_COLUMNS.' FROM audit_log a WHERE '.implode(' AND ', $where)
            .$order.' LIMIT ?) a '
            .self::JOINS
            .$order;
        $parameters[] = $criteria->limit + 1;

        return [$sql, $parameters];
    }

    private const SELECT = <<<'SQL'
        SELECT a.id, a.occurred_at, a.event_type, a.actor_user_id, u.display_name AS actor_display_name, a.actor_label,
               a.subject_type, a.subject_id
        SQL;

    /** What the derived page carries — never `metadata` or `ip_address`, which this read does not serve. */
    private const RECORD_COLUMNS = 'a.id, a.tenant_id, a.occurred_at, a.event_type, a.actor_user_id, a.actor_label, a.subject_type, a.subject_id';

    private const JOINS = 'LEFT JOIN app_user u ON u.tenant_id = a.tenant_id AND u.id = a.actor_user_id';

    /** @param array<string, mixed> $row */
    private static function view(array $row): AuditRecordView
    {
        $eventType = (string) $row['event_type'];
        if (!AuditEvent::isReadableAsRecordHistory($eventType)) {
            throw new \LogicException(\sprintf('audit_log %s (%s) reached a History page although PC-11\'s allowlist does not admit it: the statement and AuditEvent::isReadableAsRecordHistory() disagree.', (string) $row['id'], $eventType));
        }
        $hasActor = null !== $row['actor_user_id'];

        return new AuditRecordView(
            id: (string) $row['id'],
            occurredAt: new \DateTimeImmutable((string) $row['occurred_at'])->format(\DateTimeInterface::RFC3339_EXTENDED),
            eventType: $eventType,
            actorId: $hasActor ? (string) $row['actor_user_id'] : null,
            actorDisplayName: $hasActor ? (string) $row['actor_display_name'] : null,
            actorLabel: (string) $row['actor_label'],
            subjectType: (string) $row['subject_type'],
            subjectId: (string) $row['subject_id'],
        );
    }
}

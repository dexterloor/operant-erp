<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Infrastructure;

use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\ValueObject\AuditEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **E-16: `data.export.completed`, one row per export (G6-C0; design §6.7, E-16 reconciled with E-17).**
 *
 * Written by `CreateExport` INSIDE its one tenancy transaction, after the rows are read and before COMMIT, as `erp_app`
 * (`API.md` §4.4.5: "the count is captured inside the tenancy transaction"). `run()` joins the caller's transaction, so the
 * row and the read are one unit: an export that fails before COMMIT leaves no row. (A render failure AFTER commit leaves a
 * row for a file nobody received — the accepted over-report, M-7.)
 *
 * The event is SUBJECTLESS (`AuditEvent::SUBJECTLESS`: a filtered list has no single row to name) and SECURITY, so it
 * carries the request's IP (`AuditWriterTest::PINNED_IP_BEARING_EVENTS`). Its metadata is exactly
 * `{"resource", "filters", "sort", "row_count"}`:
 * - `filters` — every filter the request applied, by name: its value as sent, EXCEPT a free-text filter (a string with no
 *   enum and no format, e.g. `q`), which is recorded as `true` — "a search was applied", never what was searched for (E-17:
 *   no row carries a search term; design L-5). The CALLER (`ExportRunner`) decides which filters are free text from the
 *   List's own declaration; this writer only refuses a value that is neither a string nor `true`, so the E-17 rule is held
 *   by `ExportAuditTest::theSearchTermNeverReachesTheTrail`, not here (`g6-c0-red.md` L-3).
 * - `sort` — the requested order or `null`; `row_count` — the rows the file will hold.
 * Never a row's content.
 */
final readonly class ExportAuditWriter
{
    public function __construct(
        private TenantContext $tenants,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<string, string|true> $filters free-text filters already reduced to `true`
     */
    public function recordCompleted(
        string $tenantId,
        string $actorUserId,
        string $actorLabel,
        string $resource,
        array $filters,
        ?string $sort,
        int $rowCount,
        ?string $ipAddress,
    ): void {
        // §4.4.5: "the count is captured inside the tenancy transaction". Refused here rather than trusted to the caller, so
        // moving the write after COMMIT is a 500, never a row written in a transaction of its own
        // (`ExportAuditTest::theCountIsCapturedInsideTheTransaction`).
        if (!$this->em->getConnection()->isTransactionActive()) {
            throw new \LogicException('data.export.completed is written inside the export\'s tenancy transaction, before COMMIT.');
        }
        if ('' === $resource) {
            throw new \LogicException('data.export.completed names the resource that was exported.');
        }
        if ($rowCount < 0) {
            throw new \LogicException('data.export.completed counts the rows exported.');
        }
        foreach ($filters as $name => $value) {
            if (!\is_string($name) || '' === $name || (true !== $value && !\is_string($value))) {
                throw new \LogicException('data.export.completed records each filter by name, as its value or as true.');
            }
        }
        ksort($filters, \SORT_STRING);

        $metadata = json_encode([
            'resource' => $resource,
            'filters' => (object) $filters,
            'sort' => $sort,
            'row_count' => $rowCount,
        ], \JSON_THROW_ON_ERROR);

        $event = AuditEvent::DATA_EXPORT_COMPLETED;

        $this->tenants->run($tenantId, static function (EntityManagerInterface $em) use ($tenantId, $actorUserId, $actorLabel, $event, $ipAddress, $metadata): void {
            $em->getConnection()->executeStatement(<<<'SQL'
                INSERT INTO audit_log
                    (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label,
                     subject_type, subject_id, ip_address, metadata)
                VALUES
                    (?::uuid, ?::uuid, now(), ?, ?::uuid, ?, NULL, NULL, ?::inet, ?::jsonb)
                SQL, [
                    Uuid::v4()->toRfc4122(),
                    $tenantId,
                    $event,
                    $actorUserId,
                    $actorLabel,
                    $ipAddress,
                    $metadata,
                ]);
        });
    }
}

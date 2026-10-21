<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\AuditLog\ApiResource\State\AuditRecordCollectionProvider;
use App\Modules\AuditLog\Application\AuditLogListCriteria;
use App\Modules\AuditLog\Application\RecordHistorySubjects;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * API-22 — the list envelope, and **`ListAuditRecords`** (audit reads C2; `docs/session13/audit-reads-design.md` §3.2,
 * R1.5; `audit-reads-owner-q-ruling.md` §3, §5 C2). `Get` on `/audit-log/records`, `AuditLogEntryCollection`'s reason.
 *
 * **PC-11's colleague-readable half — one record's History** (L-7): gated `audit_log.view_record` (E-1), then the
 * subject's own read code through PC-9's map (E-5, `AuditRecordAccess`), bounded to one `(subject_type, subject_id)`, and
 * admitting only `AuditEvent::isReadableAsRecordHistory()` events. **Code-level** (H-3 (ii)): a deleted record's History
 * is served by id to its type's code holders (`RESIDUAL_GAPS.md` G-52).
 *
 * **Parameters:** `subject_type` and `subject_id`, **both required** — refused in the provider AFTER the gate (the
 * `ListStockLevels` precedent: a non-holder sending nothing gets the gate's 403, never a 422; mutation N9), so the schema
 * does not mark them `required`; `limit`; `after`│`before`. `subject_type`'s enum is `RecordHistorySubjects::TYPES`.
 * **No `sort`**: one order, newest first, `occurred_at DESC, id DESC`.
 */
#[ApiResource(
    shortName: 'AuditRecordCollection',
    operations: [
        new Get(
            uriTemplate: '/audit-log/records',
            name: 'ListAuditRecords',
            openapi: new OpenApiOperation(operationId: 'ListAuditRecords'),
            provider: AuditRecordCollectionProvider::class,
            parameters: [
                'subject_type' => new QueryParameter(
                    description: 'Required. The table of the record whose History this is — one of the subjects with a read code (RecordHistorySubjects, PC-9\'s map). Refused (422 required) only after the permission check, so a caller without audit_log.view_record is told 403 first.',
                    schema: ['type' => 'string', 'enum' => RecordHistorySubjects::TYPES],
                ),
                'subject_id' => new QueryParameter(
                    description: 'Required. The record\'s id. The sort manifest\'s "History section (L-7) on any detail page" predicate, with subject_type, served by audit_log_tenant_subject_occurred_id_idx. An id this workspace has no History for — unknown, another workspace\'s, or a record with no admitted event — is an empty page, never a 404.',
                    schema: ['type' => 'string', 'format' => 'uuid'],
                ),
                'limit' => new QueryParameter(
                    description: 'Page size. API-17: default 50, maximum 200.',
                    schema: [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => AuditLogListCriteria::MAXIMUM_LIMIT,
                        'default' => AuditLogListCriteria::DEFAULT_LIMIT,
                    ],
                ),
                'after' => new QueryParameter(
                    description: 'An opaque cursor from a previous response\'s next_cursor. Mutually exclusive with before.',
                    schema: ['type' => 'string'],
                ),
                'before' => new QueryParameter(
                    description: 'An opaque cursor from a previous response\'s previous_cursor. Mutually exclusive with after.',
                    schema: ['type' => 'string'],
                ),
            ],
            // §4.2's `Perm` cell, enforced before the provider runs (E-1). The subject's own code is the provider's (E-5).
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'audit_log.view_record'],
        ),
    ],
)]
final class AuditRecordCollection
{
    /**
     * @param list<AuditRecordResource> $items
     */
    public function __construct(
        // INLINE, not a `$ref`: no operation outputs a single History row, so no component exists to point at.
        #[ApiProperty(schema: ['type' => 'array', 'items' => AuditRecordResource::WIRE_SCHEMA])]
        public array $items,
        /** Null on the last page and only there (API-22). Passed back as `after`. */
        #[SerializedName('next_cursor')]
        public ?string $nextCursor,
        /** Passed back as `before`. */
        #[SerializedName('previous_cursor')]
        public ?string $previousCursor,
        /** ST-2's discriminator, computed only when `items` is empty. */
        #[SerializedName('tenant_has_rows')]
        public bool $tenantHasRows,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\AuditLog\ApiResource\State\AuditLogEntryCollectionProvider;
use App\Modules\AuditLog\Application\AuditLogListCriteria;
use App\Platform\Security\PermissionGate;
use App\SharedKernel\ValueObject\AuditEvent;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

/**
 * API-22 — the list envelope, and **`ListAuditLogEntries`** (audit reads C1; `docs/session13/audit-reads-design.md` §3.1,
 * R1.3). `Get` on `/audit-log`, not `GetCollection`, for `StockMovementCollection`'s reason (a named envelope, no total).
 *
 * **PC-11's whole-trail half:** every event, every actor — authentication failures and permission denials with their
 * `ip_address` included — to holders of `audit_log.view` (`owner`, `admin`; `viewer` withholds it). A colleague's view of
 * one record's History is `ListAuditRecords`, a different operation.
 *
 * **The parameter set is §4.2's cell less `sort`:** `actor_user_id`; `event_type`; `limit`; `after`│`before`. **No
 * `sort`:** the cell declared it and was corrected in this commit (`API.md` §14.2 item 30, item 29's precedent): the three
 * `/admin/audit-log` rows of `IA.md` §5.6 give one order and no alternate, so `?sort` answers `undeclared_parameter`.
 * **One filter at a time:** both together are 422 `one_filter_at_a_time` on both pointers — no index serves the pair.
 * `event_type`'s enum is `AuditEvent::ALL`, read at runtime, so a catalogue member added later joins it with no edit here.
 *
 * **Newest first**, `occurred_at DESC, id DESC`, in all three views.
 */
#[ApiResource(
    shortName: 'AuditLogEntryCollection',
    operations: [
        new Get(
            uriTemplate: '/audit-log',
            name: 'ListAuditLogEntries',
            openapi: new OpenApiOperation(operationId: 'ListAuditLogEntries'),
            provider: AuditLogEntryCollectionProvider::class,
            parameters: [
                'actor_user_id' => new QueryParameter(
                    description: 'Restrict to one person\'s events, newest first. The sort manifest\'s "/admin/audit-log filtered to an actor" predicate, served by audit_log_tenant_actor_occurred_id_idx. Not with event_type (one_filter_at_a_time). A user this workspace cannot see matches nothing: the answer is an empty page, never a 404.',
                    schema: ['type' => 'string', 'format' => 'uuid'],
                ),
                'event_type' => new QueryParameter(
                    description: 'Restrict to one kind of event, newest first. The sort manifest\'s "/admin/audit-log filtered to an event type" predicate, served by audit_log_tenant_event_occurred_id_idx. Not with actor_user_id (one_filter_at_a_time).',
                    schema: ['type' => 'string', 'enum' => AuditEvent::ALL],
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
            // An empty `metadata` is the JSON object `{}`, never `[]` (it is an object in every row; `AuditLogEntryResource`).
            normalizationContext: [AbstractObjectNormalizer::PRESERVE_EMPTY_OBJECTS => true],
            // §4.2's `Perm` cell, enforced before the provider runs (E-1).
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'audit_log.view'],
        ),
    ],
)]
final class AuditLogEntryCollection
{
    /**
     * @param list<AuditLogEntryResource> $items
     */
    public function __construct(
        // INLINE, not a `$ref`: no operation outputs a single entry, so no component exists to point at.
        #[ApiProperty(schema: ['type' => 'array', 'items' => AuditLogEntryResource::WIRE_SCHEMA])]
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

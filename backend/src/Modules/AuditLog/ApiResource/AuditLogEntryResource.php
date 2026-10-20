<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\ApiResource;

use App\Modules\AuditLog\Application\AuditLogEntryView;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

/**
 * **One audit trail entry — `ListAuditLogEntries`' item** (audit reads C1; `API.md` §4.4.3's AuditLogEntry row; PC-11's
 * whole-trail half, `audit_log.view`).
 *
 * Not an `ApiResource` of its own: no §4.2 row reads one entry, so API Platform mints no component for it and
 * `AuditLogEntryCollection` publishes the item schema INLINE ({@see self::WIRE_SCHEMA}), held equal to this class's members
 * by `AuditLogReadTest::theWireSchemaIsTheResourcesOwnMembers` (the `StockLevelResource` precedent). `ResponseShapeTest`
 * finds it by the `<Resource>Resource` convention beside its envelope.
 *
 * - `actor` is `{id, display_name}` (the live name) or `null` when the row has no actor; `actor_label` is always served,
 *   the name as recorded.
 * - `metadata` is **opaque** (§4.4.3; `SEC` + `PO`, `audit-reads-sec-po.md` C.1): a server-written object, served as
 *   stored, outside API-23b (reference expansion — it may carry bare `*_id` keys) and API-23c (wire scale — it may carry
 *   decimals at any scale), and never a money amount (PC-12; `WireScaleTest` asserts no scale-4 decimal appears in it).
 *   Always a JSON object, `{}` included (never `[]`).
 * - `ip_address` is non-null only on a security event.
 * - **Not served:** `permission_code` (a denial's code) — `SEC` has not agreed to it (design §3.1; owed).
 */
final readonly class AuditLogEntryResource
{
    /** The published item schema — the members below, nothing more. */
    public const WIRE_SCHEMA = [
        'type' => 'object',
        'required' => ['id', 'occurred_at', 'event_type', 'actor', 'actor_label', 'subject_type', 'subject_id', 'metadata', 'ip_address'],
        'properties' => [
            'id' => ['type' => 'string', 'format' => 'uuid'],
            'occurred_at' => ['type' => 'string', 'format' => 'date-time'],
            'event_type' => ['type' => 'string'],
            'actor' => ['type' => ['object', 'null'], 'properties' => ['id' => ['type' => 'string', 'format' => 'uuid'], 'display_name' => ['type' => 'string']]],
            'actor_label' => ['type' => 'string'],
            'subject_type' => ['type' => ['string', 'null']],
            'subject_id' => ['type' => ['string', 'null'], 'format' => 'uuid'],
            'metadata' => ['type' => 'object', 'description' => 'Opaque, server-written; outside API-23b and API-23c; never a money amount.'],
            'ip_address' => ['type' => ['string', 'null']],
        ],
    ];

    public function __construct(
        public string $id,
        #[SerializedName('occurred_at')]
        public string $occurredAt,
        #[SerializedName('event_type')]
        public string $eventType,
        public ?AuditActorReference $actor,
        #[SerializedName('actor_label')]
        public string $actorLabel,
        #[SerializedName('subject_type')]
        public ?string $subjectType,
        #[SerializedName('subject_id')]
        public ?string $subjectId,
        /** @var \ArrayObject<string, mixed> */
        #[Context([AbstractObjectNormalizer::PRESERVE_EMPTY_OBJECTS => true])]
        public \ArrayObject $metadata,
        #[SerializedName('ip_address')]
        public ?string $ipAddress,
    ) {
    }

    public static function fromView(AuditLogEntryView $view): self
    {
        return new self(
            id: $view->id,
            occurredAt: $view->occurredAt,
            eventType: $view->eventType,
            actor: null === $view->actorId ? null : new AuditActorReference($view->actorId, (string) $view->actorDisplayName),
            actorLabel: $view->actorLabel,
            subjectType: $view->subjectType,
            subjectId: $view->subjectId,
            metadata: new \ArrayObject($view->metadata),
            ipAddress: $view->ipAddress,
        );
    }
}

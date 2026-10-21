<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\ApiResource;

use App\Modules\AuditLog\Application\AuditRecordView;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **One History row — `ListAuditRecords`' item** (audit reads C2; `API.md` §4.4.3's AuditRecord row; PC-11's
 * colleague-readable half, `audit_log.view_record`).
 *
 * `AuditLogEntryResource`'s members **minus `metadata` and `ip_address`**: an IP address is the personal datum API-16
 * restricts to security events, and `metadata` is withheld until `SEC` rules a per-event key list (`audit-reads-sec-po.md`
 * C.2 — never a `*_id` key, never a value copied from a table). Not an `ApiResource` of its own: no operation reads one row,
 * so `AuditRecordCollection` publishes the schema INLINE ({@see self::WIRE_SCHEMA}), held equal to these members by
 * `AuditRecordReadTest::theWireSchemaIsTheResourcesOwnMembers` (the `AuditLogEntryResource` precedent).
 */
final readonly class AuditRecordResource
{
    /** The published item schema — the members below, nothing more. */
    public const WIRE_SCHEMA = [
        'type' => 'object',
        'required' => ['id', 'occurred_at', 'event_type', 'actor', 'actor_label', 'subject_type', 'subject_id'],
        'properties' => [
            'id' => ['type' => 'string', 'format' => 'uuid'],
            'occurred_at' => ['type' => 'string', 'format' => 'date-time'],
            'event_type' => ['type' => 'string'],
            'actor' => ['type' => ['object', 'null'], 'properties' => ['id' => ['type' => 'string', 'format' => 'uuid'], 'display_name' => ['type' => 'string']]],
            'actor_label' => ['type' => 'string'],
            'subject_type' => ['type' => 'string'],
            'subject_id' => ['type' => 'string', 'format' => 'uuid'],
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
        public string $subjectType,
        #[SerializedName('subject_id')]
        public string $subjectId,
    ) {
    }

    public static function fromView(AuditRecordView $view): self
    {
        return new self(
            id: $view->id,
            occurredAt: $view->occurredAt,
            eventType: $view->eventType,
            actor: null === $view->actorId ? null : new AuditActorReference($view->actorId, (string) $view->actorDisplayName),
            actorLabel: $view->actorLabel,
            subjectType: $view->subjectType,
            subjectId: $view->subjectId,
        );
    }
}

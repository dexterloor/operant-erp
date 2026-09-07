<?php

declare(strict_types=1);

namespace App\Entity;

use App\Audit\AuditEvent;
use App\Doctrine\Type\InetType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Modules\AuditLog. One row per thing that happened.
 *
 * **Append-only at the database level** (ARCHITECTURE S-9): the application
 * role holds INSERT and SELECT on `audit_log` and neither UPDATE nor DELETE, so
 * a compromised request cannot rewrite the record of its own compromise. This
 * class exposes no mutators at all, and that is not politeness -- a setter here
 * would compile, pass review, and then fail at runtime against a grant, which
 * is a worse way to learn the rule than not having the method.
 *
 * Every field is server-generated (ADR-006 §2.2). Nothing here is copied from a
 * request body, which retires audit-log injection as a category rather than
 * mitigating it: there is no user-controlled string to escape because there is
 * no user-controlled string.
 *
 * `actorUser` is nullable and `actorLabel` is not. A system action -- the
 * reorder agent, a scheduled job -- has no actor row; and the label carries the
 * display name *as it was*, so renaming or soft-deleting a user does not
 * retroactively rewrite what the trail says they did. The association is
 * single-column on `actor_user_id`; the composite FOREIGN KEY
 * (tenant_id, actor_user_id) REFERENCES app_user (tenant_id, id) is written by
 * hand in Version20260906000100 and deliberately not mapped (ADR-002
 * Amendment 1 §A1.6).
 *
 * `eventType` is not free text. It is constrained to App\Audit\AuditEvent's
 * enumerated catalogue by `audit_log_event_type_values` (B-4,
 * Version20260907000200) and by the constructor, because
 * `security-audit-log-design` requires an enumerated taxonomy defined in code
 * and because PC-11's `.view_record` allowlist and any retention predicate both
 * have to classify this column.
 *
 * `subjectType`/`subjectId` are a nullable pair held together by a CHECK: both
 * or neither. They are a deliberate polymorphic reference with no foreign key,
 * because the trail has to outlive the row it describes.
 *
 * `ipAddress` is Postgres `inet`, mapped through the registered
 * App\Doctrine\Type\InetType rather than as a string. A string mapping does not
 * work here and the failure is not cosmetic: SchemaTool forces length 255 onto
 * any column whose type name is `string`, so the mapping declares VARCHAR(255)
 * against an `inet` column and every schema comparison proposes
 * `ALTER TABLE audit_log ALTER ip_address TYPE VARCHAR(255)` -- a rewrite that
 * would silently drop the address validation Postgres is doing.
 * `columnDefinition: 'inet'` does not rescue it, because DBAL nulls
 * columnDefinition out before comparing columns. Measured, not assumed.
 *
 * The four indexes each answer one screen, and none of them declares DESC: a
 * B-tree is scanned backwards at the same cost, so `ORDER BY occurred_at DESC`
 * within a tenant is served by the ascending index. Declaring a direction would
 * buy nothing and would put a shape into the catalogue that the ORM attributes
 * cannot express -- diff noise for no gain.
 */
#[ORM\Entity]
#[ORM\Table(name: 'audit_log')]
#[ORM\UniqueConstraint(name: 'audit_log_tenant_id_id_key', columns: ['tenant_id', 'id'])]
// "The tenant's audit trail, newest first" -- the default screen.
#[ORM\Index(name: 'audit_log_tenant_occurred_idx', columns: ['tenant_id', 'occurred_at'])]
// "What did this user do" -- the access review.
#[ORM\Index(name: 'audit_log_tenant_actor_idx', columns: ['tenant_id', 'actor_user_id', 'occurred_at'])]
// "The history of this purchase order" -- the per-record timeline.
#[ORM\Index(name: 'audit_log_tenant_subject_idx', columns: ['tenant_id', 'subject_type', 'subject_id', 'occurred_at'])]
// "Every failed login in the last hour" -- ADR-006 §2.2's whole point.
#[ORM\Index(name: 'audit_log_tenant_event_idx', columns: ['tenant_id', 'event_type', 'occurred_at'])]
class AuditLogEntry
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** An ordinary mapped field -- ADR-002 Amendment 1 §A1.6. */
    #[ORM\Column(name: 'tenant_id', type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\Column(name: 'occurred_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(name: 'event_type', type: Types::TEXT)]
    private string $eventType;

    /** Null for a system action: the reorder agent has no user row. */
    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'actor_user_id', referencedColumnName: 'id', nullable: true)]
    private ?AppUser $actorUser = null;

    /** The actor's name as it was. Never re-resolved -- see the class docblock. */
    #[ORM\Column(name: 'actor_label', type: Types::TEXT)]
    private string $actorLabel;

    #[ORM\Column(name: 'subject_type', type: Types::TEXT, nullable: true)]
    private ?string $subjectType = null;

    #[ORM\Column(name: 'subject_id', type: UuidType::NAME, nullable: true)]
    private ?Uuid $subjectId = null;

    /** Postgres `inet`. See the class docblock for why this mapping and not a plain text column. */
    #[ORM\Column(name: 'ip_address', type: InetType::NAME, nullable: true)]
    private ?string $ipAddress = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    private array $metadata = [];

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        Uuid $id,
        Uuid $tenantId,
        \DateTimeImmutable $occurredAt,
        string $eventType,
        string $actorLabel,
        ?AppUser $actorUser = null,
        ?string $subjectType = null,
        ?Uuid $subjectId = null,
        ?string $ipAddress = null,
        array $metadata = [],
    ) {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->occurredAt = $occurredAt;
        $this->eventType = $eventType;
        $this->actorLabel = $actorLabel;
        $this->actorUser = $actorUser;
        // B-4: the same argument the subject-pair guard below already makes.
        // `audit_log_event_type_values` refuses this at the database; enforcing
        // it here means an unclassified event fails at construction rather than
        // at flush, where the failure is a raw constraint violation carrying no
        // hint that a catalogue exists or how to extend it.
        if (!AuditEvent::isKnown($eventType)) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" is not in the audit event catalogue. Add it to App\\Audit\\AuditEvent '
                .'(constant, ALL, SECURITY or BUSINESS, and a subject declaration) and to a '
                .'forward migration; see that class for the whole process and why every step '
                .'of it is required.',
                $eventType,
            ));
        }
        // Both or neither, matching audit_log_subject_pair. Enforced here too so
        // the half-populated pair fails at construction rather than at flush.
        if ((null === $subjectType) !== (null === $subjectId)) {
            throw new \InvalidArgumentException('subjectType and subjectId must be given together or not at all.');
        }
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->ipAddress = $ipAddress;
        $this->metadata = $metadata;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenantId(): Uuid
    {
        return $this->tenantId;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getActorUser(): ?AppUser
    {
        return $this->actorUser;
    }

    public function getActorLabel(): string
    {
        return $this->actorLabel;
    }

    public function getSubjectType(): ?string
    {
        return $this->subjectType;
    }

    public function getSubjectId(): ?Uuid
    {
        return $this->subjectId;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    /** @return array<string, mixed> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}

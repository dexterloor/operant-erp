<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Infrastructure\Entity;

use App\Modules\UserManagement\Infrastructure\Entity\AppUser;
use App\Platform\Persistence\Type\InetType;
use App\SharedKernel\ValueObject\AuditEvent;
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
 * `eventType` is not free text. It is constrained to App\SharedKernel\ValueObject\AuditEvent's
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
 * App\Platform\Persistence\Type\InetType rather than as a string. A string mapping does not
 * work here and the failure is not cosmetic: SchemaTool forces length 255 onto
 * any column whose type name is `string`, so the mapping declares VARCHAR(255)
 * against an `inet` column and every schema comparison proposes
 * `ALTER TABLE audit_log ALTER ip_address TYPE VARCHAR(255)` -- a rewrite that
 * would silently drop the address validation Postgres is doing.
 * `columnDefinition: 'inet'` does not rescue it, because DBAL nulls
 * columnDefinition out before comparing columns. Measured, not assumed.
 *
 * `permissionCode` is E-3a's cap key, third column: `NULL` on every event that
 * is not `authz.permission.denied`, and refused outright on any other event
 * type by the constructor and by `audit_log_permission_code_scope`. The
 * database does **not** require it on a denial row and neither does this class;
 * Version20260909000200 records why that asymmetry is deliberate.
 *
 * Five of the six indexes each answer one screen, and none of them declares DESC: a
 * B-tree is scanned backwards at the same cost, so `ORDER BY occurred_at DESC`
 * within a tenant is served by the ascending index. Declaring a direction would
 * buy nothing and would put a shape into the catalogue that the ORM attributes
 * cannot express -- diff noise for no gain. The sixth, `audit_log_denial_cap_idx`,
 * answers no screen: it is E-3a's cap probe and it is partial, so the `where`
 * option is not decoration -- a mapping without it proposes to rebuild the index
 * as a total one on every schema comparison.
 */
#[ORM\Entity]
#[ORM\Table(name: 'audit_log')]
#[ORM\UniqueConstraint(name: 'audit_log_tenant_id_id_key', columns: ['tenant_id', 'id'])]
// "The tenant's audit trail, newest first" -- the default screen. Ends `id` since audit reads C1
// (`Version20261021000100`): `ListAuditLogEntries`' keyset `(occurred_at, id) < (?, ?)` is then a whole index condition.
#[ORM\Index(name: 'audit_log_tenant_occurred_id_idx', columns: ['tenant_id', 'occurred_at', 'id'])]
// "What did this user do" -- the access review. Ends `id` since audit reads C1 (same reason).
#[ORM\Index(name: 'audit_log_tenant_actor_occurred_id_idx', columns: ['tenant_id', 'actor_user_id', 'occurred_at', 'id'])]
// "The history of this purchase order" -- the per-record timeline.
#[ORM\Index(name: 'audit_log_tenant_subject_idx', columns: ['tenant_id', 'subject_type', 'subject_id', 'occurred_at'])]
// "Every failed login in the last hour" -- ADR-006 §2.2's whole point. Ends `id` since audit reads C1 (same reason).
#[ORM\Index(name: 'audit_log_tenant_event_occurred_id_idx', columns: ['tenant_id', 'event_type', 'occurred_at', 'id'])]
// E-3a's cap key. Partial on the denial event type: the predicate has to be
// provable from the read-back's own WHERE clause, and it keeps the index
// proportional to the denial rows rather than to the whole table.
#[ORM\Index(name: 'audit_log_denial_cap_idx', columns: ['tenant_id', 'actor_user_id', 'permission_code', 'occurred_at'], options: ['where' => "(event_type = 'authz.permission.denied'::text)"])]
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

    /**
     * E-3a's cap key, third column. `NULL` on every event that is not a
     * denial -- see `Version20260909000200` for why `NULL` and not a sentinel,
     * and for why the database `CHECK` is one-directional.
     */
    #[ORM\Column(name: 'permission_code', type: Types::TEXT, nullable: true)]
    private ?string $permissionCode = null;

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
        ?string $permissionCode = null,
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
                '"%s" is not in the audit event catalogue. Add it to App\\SharedKernel\\ValueObject\\AuditEvent '
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
        // API.md §9.1's layer 2 -- API-16, built under E4-5 with the `CHECK`
        // below it (`audit_log_ip_address_scope`, `Version20260910000300`).
        //
        // `ip_address` is personal data with forensic value on the
        // authentication path and none on the bulk business event, which the
        // project's own committed fixture makes the overwhelming majority of
        // this table. §9.1 specifies three layers "in increasing strength,
        // and all three are specified because the weakest is the one an
        // implementer reaches for": the writer's predicate, this guard, and the
        // database `CHECK`.
        //
        // **This layer is the diagnosability one and that is its whole job.**
        // The `CHECK` alone would convert a bad pair from silently accepted into
        // a raw 23514 at flush naming a constraint and not a cause -- the same
        // argument the catalogue-membership and subject-pair guards above each
        // make for themselves, and the reason §9.1 asks for this guard "in the
        // same shape as the two guards already there".
        //
        // Derived from `AuditEvent::SECURITY` rather than restating it, exactly
        // as the catalogue guard derives from `isKnown()`. The independent copy
        // that makes this falsifiable lives in the test file, as
        // `AuditWriterTest::PINNED_IP_BEARING_EVENTS`; a guard and a test that
        // both read this constant would compare it against itself through one
        // indirection, which is the tautology §9.1 records revision 1 shipping.
        //
        // One-directional, matching `audit_log_ip_address_scope`: a security
        // event carrying a null `ip_address` is accepted. Refusing it would let
        // a console command or a queued writer lose an audit record on the
        // authentication path, and losing the record is worse than losing the
        // field -- `Version20260909000200` makes the same trade for
        // `permission_code` and records why.
        if (null !== $ipAddress && !\in_array($eventType, AuditEvent::SECURITY, true)) {
            throw new \InvalidArgumentException(sprintf(
                'ipAddress belongs only on an App\\SharedKernel\\ValueObject\\AuditEvent::SECURITY event; '
                .'"%s" is a business event. API.md §9.1 keeps personal data off the bulk business row, and '
                .'audit_log_ip_address_scope refuses this at the database. If this event should carry an IP, '
                .'that is a reclassification: it is a SEC decision under PC-11 (4a) and it needs a forward '
                .'migration re-issuing the CHECK and a line in AuditWriterTest::PINNED_IP_BEARING_EVENTS '
                .'(AuditEvent\'s extension process, step 6).',
                $eventType,
            ));
        }
        $this->ipAddress = $ipAddress;
        $this->metadata = $metadata;
        // The direction `audit_log_permission_code_scope` constrains, enforced
        // here for the reason the subject-pair guard above gives: a code on an
        // event that has no permission fails at construction rather than at
        // flush, where it is a raw 23514 naming a constraint and not a cause.
        //
        // The other direction -- a denial that carries no code -- is
        // deliberately NOT refused, here or in the database. E-3 requires the
        // denial row to be written; refusing it would lose the audit record to
        // save the cap, and the cap exists to make E-3 affordable rather than
        // the other way round. Requiring it needs a per-event declaration on
        // App\SharedKernel\ValueObject\AuditEvent, which is a catalogue extension and E4-1's.
        if (null !== $permissionCode && AuditEvent::AUTHZ_PERMISSION_DENIED !== $eventType) {
            throw new \InvalidArgumentException(sprintf(
                'permissionCode is E-3a\'s cap key and belongs only on a "%s" row; "%s" was given. '
                .'audit_log_permission_code_scope refuses this at the database.',
                AuditEvent::AUTHZ_PERMISSION_DENIED,
                $eventType,
            ));
        }
        $this->permissionCode = $permissionCode;
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

    /** E-3a's cap key, third column. Null on every event that is not a denial. */
    public function getPermissionCode(): ?string
    {
        return $this->permissionCode;
    }

    /** @return array<string, mixed> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}

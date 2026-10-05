<?php

declare(strict_types=1);

namespace App\Modules\Sales\Infrastructure\Entity;

use App\Modules\UserManagement\Infrastructure\Entity\AppUser;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * **`adjustment_decision`** (G-29 / G-30 part 3, C1, `Version20261005000400`): which returned units an adjustment credit
 * already covered — `same` + quantity, `distinct`, or the owner's `reversal` of one row. Mapped READ-ONLY so the schema
 * diff and the ownership checks see it; every write is `AdjustmentDecisionWriter`'s (and, from C2, `CreditNoteWriter`'s)
 * raw INSERT naming the granted columns. Class none, append-only: no UPDATE or DELETE exists for any application role.
 * The composite FKs are database constraints; the single-column associations below are the ORM's view of them (A1.6).
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'adjustment_decision')]
#[ORM\UniqueConstraint(name: 'adjustment_decision_tenant_id_id_key', columns: ['tenant_id', 'id'])]
// A retried CreateAdjustmentDecision collides here; NULL keys (the adjust path's rows) never collide.
#[ORM\UniqueConstraint(name: 'adjustment_decision_idem_unique', columns: ['tenant_id', 'idempotency_key'])]
// A row is reversed at most once.
#[ORM\UniqueConstraint(name: 'adjustment_decision_reversed_once', columns: ['tenant_id', 'reverses_id'])]
// One `distinct` per pair per decision round (design §2.4). The `where` is the catalogue's normalised spelling.
#[ORM\UniqueConstraint(name: 'adjustment_decision_one_distinct_per_round', columns: ['tenant_id', 'sales_return_line_id', 'credit_note_line_id', 'decision_round'], options: ['where' => "(kind = 'distinct'::text)"])]
#[ORM\Index(name: 'adjustment_decision_tenant_return_line_idx', columns: ['tenant_id', 'sales_return_line_id'])]
#[ORM\Index(name: 'adjustment_decision_tenant_credit_note_line_idx', columns: ['tenant_id', 'credit_note_line_id'])]
class AdjustmentDecision
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** An ordinary mapped field (A1.6). */
    #[ORM\Column(name: 'tenant_id', type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\ManyToOne(targetEntity: SalesReturnLine::class)]
    #[ORM\JoinColumn(name: 'sales_return_line_id', referencedColumnName: 'id', nullable: false)]
    private SalesReturnLine $salesReturnLine;

    /** A line of an ADJUSTMENT (a credit note with no return) — an application rule under the invoice lock. */
    #[ORM\ManyToOne(targetEntity: CreditNoteLine::class)]
    #[ORM\JoinColumn(name: 'credit_note_line_id', referencedColumnName: 'id', nullable: false)]
    private CreditNoteLine $creditNoteLine;

    /** `same`, `distinct` or `reversal` (`adjustment_decision_kind_values`). */
    #[ORM\Column(name: 'kind', type: Types::TEXT)]
    private string $kind;

    /** S-12: numeric(19,6), a string in PHP. Present exactly for `same`. */
    #[ORM\Column(type: Types::DECIMAL, precision: 19, scale: 6, nullable: true)]
    private ?string $quantity;

    /** The row a `reversal` undoes; present exactly for `reversal`. */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'reverses_id', referencedColumnName: 'id', nullable: true)]
    private ?AdjustmentDecision $reverses;

    #[ORM\Column(name: 'decision_round', type: Types::SMALLINT, options: ['default' => 1])]
    private int $decisionRound;

    /** Who recorded it — the authenticated caller, never a request field (PC-11 (4a), `SEC` 2026-10-05). */
    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'recorded_by_user_id', referencedColumnName: 'id', nullable: false)]
    private AppUser $recordedBy;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    /** The hex SHA-256 of the `Idempotency-Key` header (D-B); NULL on the adjust path's rows (C2). */
    #[ORM\Column(name: 'idempotency_key', type: Types::TEXT, nullable: true)]
    private ?string $idempotencyKey;

    #[ORM\Column(name: 'request_digest', type: Types::TEXT, nullable: true)]
    private ?string $requestDigest;

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getKind(): string
    {
        return $this->kind;
    }
}

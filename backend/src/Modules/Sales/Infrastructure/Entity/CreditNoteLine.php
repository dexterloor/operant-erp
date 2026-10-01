<?php

declare(strict_types=1);

namespace App\Modules\Sales\Infrastructure\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Modules\Sales. What a credit note credited, against which shipped line — sales step 7 (`Version20261001000500`).
 *
 * Read-only to the ORM and without a constructor, for `CreditNote`'s reasons. Three tenant-scoped parents,
 * single-column associations over hand-written composite keys (A1.6):
 *
 *   FOREIGN KEY (tenant_id, credit_note_id)       REFERENCES credit_note       (tenant_id, id)
 *   FOREIGN KEY (tenant_id, fulfilment_line_id)   REFERENCES fulfilment_line   (tenant_id, id)
 *   FOREIGN KEY (tenant_id, sales_return_line_id) REFERENCES sales_return_line (tenant_id, id)   -- nullable
 *
 * That the shipped line belongs to the invoice's fulfilment, and the quantity bounds, are application rules.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'credit_note_line')]
#[ORM\UniqueConstraint(name: 'credit_note_line_tenant_id_id_key', columns: ['tenant_id', 'id'])]
// One line per shipped line per note.
#[ORM\UniqueConstraint(name: 'credit_note_line_once_per_line', columns: ['tenant_id', 'credit_note_id', 'fulfilment_line_id'])]
// "Everything credited against this shipped line" — the credited quantity and the bounds.
#[ORM\Index(name: 'credit_note_line_tenant_fulfilment_line_idx', columns: ['tenant_id', 'fulfilment_line_id'])]
class CreditNoteLine
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** An ordinary mapped field (A1.6). */
    #[ORM\Column(name: 'tenant_id', type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\ManyToOne(targetEntity: CreditNote::class)]
    #[ORM\JoinColumn(name: 'credit_note_id', referencedColumnName: 'id', nullable: false)]
    private CreditNote $creditNote;

    #[ORM\ManyToOne(targetEntity: FulfilmentLine::class)]
    #[ORM\JoinColumn(name: 'fulfilment_line_id', referencedColumnName: 'id', nullable: false)]
    private FulfilmentLine $fulfilmentLine;

    /** The return line credited; NULL for an adjustment. */
    #[ORM\ManyToOne(targetEntity: SalesReturnLine::class)]
    #[ORM\JoinColumn(name: 'sales_return_line_id', referencedColumnName: 'id', nullable: true)]
    private ?SalesReturnLine $salesReturnLine;

    /** S-12: a quantity is numeric(19,6) and a string in PHP, never a float. CHECK-constrained positive. */
    #[ORM\Column(type: Types::DECIMAL, precision: 19, scale: 6)]
    private string $quantity;

    /** numeric(19,4), CHECK `>= 0`: a telescoped line amount may be 0 (design §4.4 step 8b). */
    #[ORM\Column(type: Types::DECIMAL, precision: 19, scale: 4)]
    private string $amount;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $updatedAt;

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCreditNote(): CreditNote
    {
        return $this->creditNote;
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }
}

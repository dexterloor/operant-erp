<?php

declare(strict_types=1);

namespace App\Modules\Sales\Infrastructure\Entity;

use App\Modules\UserManagement\Infrastructure\Entity\AppUser;
use App\SharedKernel\Reference\Currency;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Modules\Sales. A reduction of one issued invoice — the only one OI-2 allows (sales step 7, `Version20261001000500`,
 * C0). Written by `IssueCreditNote` (C1) and `IssueAdjustmentCreditNote` (C1b), never edited and never deleted.
 *
 * **Read-only to the ORM, and it has no constructor** — `SalesReturn`'s reasons: the writers are raw SQL naming their
 * columns, nothing issued is edited, and the entity exists because every table the domain owns carries one
 * (`SchemaOwnershipTest`) and so its composite keys are pinned against associations (`DoctrineMappingTest`).
 *
 * Four parents, each a single-column association over a hand-written composite key (A1.6):
 *
 *   FOREIGN KEY (tenant_id, invoice_id)        REFERENCES invoice      (tenant_id, id)
 *   FOREIGN KEY (tenant_id, sales_return_id)   REFERENCES sales_return (tenant_id, id)   -- nullable (adjustments)
 *   FOREIGN KEY (tenant_id, issued_by_user_id) REFERENCES app_user     (tenant_id, id)
 *   FOREIGN KEY (currency_code)                REFERENCES currency     (code)
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'credit_note')]
#[ORM\UniqueConstraint(name: 'credit_note_tenant_id_id_key', columns: ['tenant_id', 'id'])]
#[ORM\UniqueConstraint(name: 'credit_note_number_unique', columns: ['tenant_id', 'credit_note_number'])]
// A retried IssueCreditNote collides here. TOTAL, like `fulfilment_idem_unique`: the table has no `deleted_at`.
#[ORM\UniqueConstraint(name: 'credit_note_idem_unique', columns: ['tenant_id', 'idempotency_key'])]
// "This invoice's credit notes" — `amount_credited`, the Credited segment, V2-R-17's EXISTS.
#[ORM\Index(name: 'credit_note_tenant_invoice_idx', columns: ['tenant_id', 'invoice_id'])]
class CreditNote
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** An ordinary mapped field (A1.6), shared with none of the associations below. */
    #[ORM\Column(name: 'tenant_id', type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(name: 'invoice_id', referencedColumnName: 'id', nullable: false)]
    private Invoice $invoice;

    /** The return credited; NULL only for an adjustment (`credit_note_return_iff_goods_returned`). */
    #[ORM\ManyToOne(targetEntity: SalesReturn::class)]
    #[ORM\JoinColumn(name: 'sales_return_id', referencedColumnName: 'id', nullable: true)]
    private ?SalesReturn $salesReturn;

    #[ORM\Column(name: 'credit_note_number', type: Types::TEXT)]
    private string $creditNoteNumber;

    #[ORM\Column(name: 'reason', type: Types::TEXT)]
    private string $reason;

    #[ORM\ManyToOne(targetEntity: Currency::class)]
    #[ORM\JoinColumn(name: 'currency_code', referencedColumnName: 'code', nullable: false)]
    private Currency $currency;

    /** S-12: money is numeric(19,4) and a string in PHP. CHECK-constrained positive. */
    #[ORM\Column(name: 'total_amount', type: Types::DECIMAL, precision: 19, scale: 4)]
    private string $totalAmount;

    #[ORM\Column(name: 'issued_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $issuedAt;

    /** Who issued it — SoD-5's evidence, the authenticated caller, never a request field. */
    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'issued_by_user_id', referencedColumnName: 'id', nullable: false)]
    private AppUser $issuedBy;

    /** The hex SHA-256 of the `Idempotency-Key` header (D-B), never the header. */
    #[ORM\Column(name: 'idempotency_key', type: Types::TEXT)]
    private string $idempotencyKey;

    /** `RequestDigest::of()` of the request that created the row. Request plumbing, never read into the domain. */
    #[ORM\Column(name: 'request_digest', type: Types::TEXT)]
    private string $requestDigest;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $updatedAt;

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getInvoice(): Invoice
    {
        return $this->invoice;
    }

    public function getSalesReturn(): ?SalesReturn
    {
        return $this->salesReturn;
    }

    public function getCreditNoteNumber(): string
    {
        return $this->creditNoteNumber;
    }

    public function getTotalAmount(): string
    {
        return $this->totalAmount;
    }

    public function getIssuedBy(): AppUser
    {
        return $this->issuedBy;
    }
}

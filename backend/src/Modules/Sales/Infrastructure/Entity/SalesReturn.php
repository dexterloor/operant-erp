<?php

declare(strict_types=1);

namespace App\Modules\Sales\Infrastructure\Entity;

use App\Modules\UserManagement\Infrastructure\Entity\AppUser;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Modules\Sales. Goods a customer sent back against one shipment — sales step 7 (`Version20261001000100`, R1).
 *
 * **Read-only to the ORM, and it has no constructor.** The writer (`CreateSalesReturn`, R2) is raw SQL naming its
 * columns, the `InvoiceIssuer` precedent, and a return is never edited (OI-2: documents, not transitions; deletion
 * class `none`, no `deleted_at`, no UPDATE or DELETE grant). The entity exists because every table the domain owns
 * carries one (`SchemaOwnershipTest`) and so the composite keys are pinned against associations
 * (`DoctrineMappingTest`), not to be persisted.
 *
 * Three tenant-scoped parents, each a single-column association whose composite key is hand-written in the migration
 * and deliberately not mapped (A1.6):
 *
 *   FOREIGN KEY (tenant_id, fulfilment_id)       REFERENCES fulfilment (tenant_id, id)
 *   FOREIGN KEY (tenant_id, invoice_id)          REFERENCES invoice    (tenant_id, id)   -- nullable
 *   FOREIGN KEY (tenant_id, recorded_by_user_id) REFERENCES app_user   (tenant_id, id)
 *
 * `invoice` is the fulfilment's LIVE invoice when the return was recorded (design F-2), NULL if none; `deducts` is
 * owner ruling Q-L (a′), decided once at record time — `sales_return_deducts_only_before_invoice` holds that a
 * deducting return names no invoice.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'sales_return')]
#[ORM\UniqueConstraint(name: 'sales_return_tenant_id_id_key', columns: ['tenant_id', 'id'])]
#[ORM\UniqueConstraint(name: 'sales_return_number_unique', columns: ['tenant_id', 'sales_return_number'])]
// A retried CreateSalesReturn collides here. TOTAL, like `fulfilment_idem_unique`: the table has no `deleted_at`.
#[ORM\UniqueConstraint(name: 'sales_return_idem_unique', columns: ['tenant_id', 'idempotency_key'])]
// "This shipment's returns" — the per-line bound and the deduction read.
#[ORM\Index(name: 'sales_return_tenant_fulfilment_idx', columns: ['tenant_id', 'fulfilment_id'])]
// "Returns recorded against this invoice" — the credit queue. The `where` is the catalogue's normalised spelling,
// measured with `pg_get_expr(indpred, indrelid)` after the migration: `(invoice_id IS NOT NULL)`.
#[ORM\Index(name: 'sales_return_tenant_invoice_idx', columns: ['tenant_id', 'invoice_id'], options: ['where' => '(invoice_id IS NOT NULL)'])]
class SalesReturn
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** An ordinary mapped field (A1.6), shared with none of the associations below. */
    #[ORM\Column(name: 'tenant_id', type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\ManyToOne(targetEntity: Fulfilment::class)]
    #[ORM\JoinColumn(name: 'fulfilment_id', referencedColumnName: 'id', nullable: false)]
    private Fulfilment $fulfilment;

    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(name: 'invoice_id', referencedColumnName: 'id', nullable: true)]
    private ?Invoice $invoice;

    #[ORM\Column(name: 'sales_return_number', type: Types::TEXT)]
    private string $salesReturnNumber;

    /** The document's reason (`sales_return_reason_values`), not `stock_movement.reason`. */
    #[ORM\Column(name: 'reason', type: Types::TEXT)]
    private string $reason;

    #[ORM\Column(name: 'returned_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $returnedAt;

    /** Who recorded the return — SoD-5's evidence, the authenticated caller, never a request field. */
    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'recorded_by_user_id', referencedColumnName: 'id', nullable: false)]
    private AppUser $recordedBy;

    #[ORM\Column(name: 'deducts', type: Types::BOOLEAN)]
    private bool $deducts;

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

    public function getTenantId(): Uuid
    {
        return $this->tenantId;
    }

    public function getFulfilment(): Fulfilment
    {
        return $this->fulfilment;
    }

    public function getInvoice(): ?Invoice
    {
        return $this->invoice;
    }

    public function getSalesReturnNumber(): string
    {
        return $this->salesReturnNumber;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getReturnedAt(): \DateTimeImmutable
    {
        return $this->returnedAt;
    }

    public function getRecordedBy(): AppUser
    {
        return $this->recordedBy;
    }

    public function deducts(): bool
    {
        return $this->deducts;
    }
}

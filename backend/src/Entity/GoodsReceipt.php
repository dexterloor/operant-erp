<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Modules\Purchasing. One delivery arriving against one purchase order.
 *
 * A document, not a record: Version20260906000100 grants erp_app
 * `SELECT, INSERT, UPDATE` and no DELETE. A receipt entered in error is voided
 * or corrected by a compensating receipt, never removed -- the stock movements
 * it produced are already immutable facts (StockMovement), and deleting the
 * document that explains them would leave the ledger unaccountable.
 *
 * `receiptNumber` is unique per tenant *totally*, not on a live-row predicate:
 * unlike a supplier code or a warehouse code, a receipt number is never
 * reissued, so there is no soft-delete to work around.
 *
 * This is the idempotency anchor for ARCHITECTURE O-6: the receipt's `id` is
 * what StockMovement records as `source_id`, so a redelivered posting message
 * can ask whether this receipt already moved stock before moving it again.
 *
 * Three tenant-scoped parents, single-column associations per A1.6:
 *
 *   FOREIGN KEY (tenant_id, purchase_order_id)   REFERENCES purchase_order (tenant_id, id)
 *   FOREIGN KEY (tenant_id, warehouse_id)        REFERENCES warehouse      (tenant_id, id)
 *   FOREIGN KEY (tenant_id, received_by_user_id) REFERENCES app_user       (tenant_id, id)
 */
#[ORM\Entity]
#[ORM\Table(name: 'goods_receipt')]
#[ORM\UniqueConstraint(name: 'goods_receipt_tenant_id_id_key', columns: ['tenant_id', 'id'])]
#[ORM\UniqueConstraint(name: 'goods_receipt_number_unique', columns: ['tenant_id', 'receipt_number'])]
// "What has been received against this order" — the receiving screen.
#[ORM\Index(name: 'goods_receipt_tenant_order_idx', columns: ['tenant_id', 'purchase_order_id', 'received_at'])]
class GoodsReceipt
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** An ordinary mapped field. Never an #[ORM\Id], never a join column (A1.6). */
    #[ORM\Column(name: 'tenant_id', type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\ManyToOne(targetEntity: PurchaseOrder::class)]
    #[ORM\JoinColumn(name: 'purchase_order_id', referencedColumnName: 'id', nullable: false)]
    private PurchaseOrder $purchaseOrder;

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'warehouse_id', referencedColumnName: 'id', nullable: false)]
    private Warehouse $warehouse;

    /** Nullable: a receipt posted by an integration or a scanner has no human author. */
    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'received_by_user_id', referencedColumnName: 'id', nullable: true)]
    private ?AppUser $receivedBy = null;

    #[ORM\Column(name: 'receipt_number', type: Types::TEXT)]
    private string $receiptNumber;

    #[ORM\Column(name: 'received_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Uuid $id,
        Uuid $tenantId,
        PurchaseOrder $purchaseOrder,
        Warehouse $warehouse,
        string $receiptNumber,
        ?\DateTimeImmutable $receivedAt = null,
    ) {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->purchaseOrder = $purchaseOrder;
        $this->warehouse = $warehouse;
        $this->receiptNumber = $receiptNumber;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->receivedAt = $receivedAt ?? $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenantId(): Uuid
    {
        return $this->tenantId;
    }

    public function getPurchaseOrder(): PurchaseOrder
    {
        return $this->purchaseOrder;
    }

    public function getWarehouse(): Warehouse
    {
        return $this->warehouse;
    }

    public function getReceivedBy(): ?AppUser
    {
        return $this->receivedBy;
    }

    public function setReceivedBy(?AppUser $user): void
    {
        $this->receivedBy = $user;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getReceiptNumber(): string
    {
        return $this->receiptNumber;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }
}

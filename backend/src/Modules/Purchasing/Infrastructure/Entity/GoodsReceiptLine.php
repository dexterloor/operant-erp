<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Infrastructure\Entity;

use App\Modules\Warehouses\Infrastructure\Entity\StorageLocation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Modules\Purchasing. What actually arrived, and which bin it went into.
 *
 * The row that connects three things that would otherwise drift apart: the
 * purchase order line it satisfies, the storage location the stock landed in,
 * and -- through GoodsReceipt -- the StockMovement whose `source_id` names the
 * receipt. Quantity is strictly positive (`goods_receipt_line_qty_positive`); a
 * short delivery is a smaller receipt and a return is a movement in the other
 * direction, never a negative receipt line.
 *
 * Immutable for the same reason as its parent: erp_app holds `SELECT, INSERT`
 * and no UPDATE or DELETE (ADR-002 Amendment 4), and the movements this line
 * produced are append-only facts.
 *
 * Note that `storageLocation` and not `warehouse` is what is recorded here.
 * GoodsReceipt names the warehouse; the line names the bin, because that is the
 * grain stock_level is keyed at, and a receipt that only knew the site could not
 * post a stock level at all.
 *
 * Three tenant-scoped parents, single-column associations per A1.6:
 *
 *   FOREIGN KEY (tenant_id, goods_receipt_id)       REFERENCES goods_receipt       (tenant_id, id)
 *   FOREIGN KEY (tenant_id, purchase_order_line_id) REFERENCES purchase_order_line (tenant_id, id)
 *   FOREIGN KEY (tenant_id, storage_location_id)    REFERENCES storage_location    (tenant_id, id)
 */
#[ORM\Entity]
#[ORM\Table(name: 'goods_receipt_line')]
#[ORM\UniqueConstraint(name: 'goods_receipt_line_tenant_id_id_key', columns: ['tenant_id', 'id'])]
// "The lines of this receipt" — the document view.
#[ORM\Index(name: 'goods_receipt_line_tenant_receipt_idx', columns: ['tenant_id', 'goods_receipt_id'])]
// "How much of this order line has arrived" — drives the PO status.
#[ORM\Index(name: 'goods_receipt_line_tenant_po_line_idx', columns: ['tenant_id', 'purchase_order_line_id'])]
class GoodsReceiptLine
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** An ordinary mapped field. Never an #[ORM\Id], never a join column (A1.6). */
    #[ORM\Column(name: 'tenant_id', type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\ManyToOne(targetEntity: GoodsReceipt::class)]
    #[ORM\JoinColumn(name: 'goods_receipt_id', referencedColumnName: 'id', nullable: false)]
    private GoodsReceipt $goodsReceipt;

    #[ORM\ManyToOne(targetEntity: PurchaseOrderLine::class)]
    #[ORM\JoinColumn(name: 'purchase_order_line_id', referencedColumnName: 'id', nullable: false)]
    private PurchaseOrderLine $purchaseOrderLine;

    #[ORM\ManyToOne(targetEntity: StorageLocation::class)]
    #[ORM\JoinColumn(name: 'storage_location_id', referencedColumnName: 'id', nullable: false)]
    private StorageLocation $storageLocation;

    /** S-12: quantities are numeric(19,6) and strings in PHP. Strictly positive. */
    #[ORM\Column(name: 'quantity_received', type: Types::DECIMAL, precision: 19, scale: 6)]
    private string $quantityReceived;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Uuid $id,
        Uuid $tenantId,
        GoodsReceipt $goodsReceipt,
        PurchaseOrderLine $purchaseOrderLine,
        StorageLocation $storageLocation,
        string $quantityReceived,
    ) {
        if (1 !== bccomp($quantityReceived, '0', 6)) {
            throw new \InvalidArgumentException('A goods receipt line must record a positive quantity.');
        }

        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->goodsReceipt = $goodsReceipt;
        $this->purchaseOrderLine = $purchaseOrderLine;
        $this->storageLocation = $storageLocation;
        $this->quantityReceived = $quantityReceived;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenantId(): Uuid
    {
        return $this->tenantId;
    }

    public function getGoodsReceipt(): GoodsReceipt
    {
        return $this->goodsReceipt;
    }

    public function getPurchaseOrderLine(): PurchaseOrderLine
    {
        return $this->purchaseOrderLine;
    }

    public function getStorageLocation(): StorageLocation
    {
        return $this->storageLocation;
    }

    public function getQuantityReceived(): string
    {
        return $this->quantityReceived;
    }
}

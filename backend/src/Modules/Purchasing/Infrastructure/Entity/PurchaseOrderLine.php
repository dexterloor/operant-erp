<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Infrastructure\Entity;

use App\Modules\Inventory\Infrastructure\Entity\InventoryItem;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Modules\Purchasing. One item on one purchase order.
 *
 * `unitCost` and `leadTimeDays` are snapshots taken from SupplierProduct when
 * the line is raised, not references to it. What the supplier charges today and
 * what they charged when this was ordered are different facts, and a line that
 * followed the live catalogue would rewrite the value of every historical order
 * every time a price list changes. The lead time is snapshotted for the same
 * reason it has no default on SupplierProduct (R-24, now an Output 9 acceptance
 * criterion): "when was this expected" must stay answerable from the order.
 *
 * `quantityReceived` is maintained by goods receipt posting -- the sum of the
 * GoodsReceiptLines against this line -- and `purchase_order_line_qty_sane`
 * keeps it non-negative. Not soft-deletable: a line that should not have been
 * ordered is cancelled with the order.
 *
 * Two tenant-scoped parents, single-column associations per A1.6:
 *
 *   FOREIGN KEY (tenant_id, purchase_order_id) REFERENCES purchase_order (tenant_id, id)
 *   FOREIGN KEY (tenant_id, inventory_item_id) REFERENCES inventory_item (tenant_id, id)
 */
#[ORM\Entity]
#[ORM\Table(name: 'purchase_order_line')]
#[ORM\UniqueConstraint(name: 'purchase_order_line_tenant_id_id_key', columns: ['tenant_id', 'id'])]
// Line numbers are unique among the order's live lines; a discarded number is
// reused (the mint is MAX+1 over live lines). The receiving screen and the
// supplier's paperwork both address lines by number.
#[ORM\UniqueConstraint(name: 'purchase_order_line_unique', columns: ['tenant_id', 'purchase_order_id', 'line_number'], options: ['where' => '(deleted_at IS NULL)'])]
// "How much of this item is already on order" — the reorder-point calculation's
// other input (ADR-004), and the reason a reorder run does not raise a second
// purchase order for stock already coming.
#[ORM\Index(name: 'purchase_order_line_tenant_item_idx', columns: ['tenant_id', 'inventory_item_id'], options: ['where' => '(deleted_at IS NULL)'])]
class PurchaseOrderLine
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

    #[ORM\ManyToOne(targetEntity: InventoryItem::class)]
    #[ORM\JoinColumn(name: 'inventory_item_id', referencedColumnName: 'id', nullable: false)]
    private InventoryItem $inventoryItem;

    #[ORM\Column(name: 'line_number', type: Types::INTEGER)]
    private int $lineNumber;

    /** S-12: quantities are numeric(19,6) and strings in PHP. */
    #[ORM\Column(name: 'quantity_ordered', type: Types::DECIMAL, precision: 19, scale: 6)]
    private string $quantityOrdered;

    #[ORM\Column(name: 'quantity_received', type: Types::DECIMAL, precision: 19, scale: 6, options: ['default' => '0'])]
    private string $quantityReceived = '0';

    /** S-12: money is numeric(19,4) and a string in PHP. Never a float. */
    #[ORM\Column(name: 'unit_cost', type: Types::DECIMAL, precision: 19, scale: 4)]
    private string $unitCost;

    /** Snapshotted from SupplierProduct at ordering time. See the class docblock. */
    #[ORM\Column(name: 'lead_time_days', type: Types::INTEGER)]
    private int $leadTimeDays;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $updatedAt;

    /**
     * Written by the purchase order's cascade trigger, never by this class. An
     * aggregate whose root is deleted and whose lines are not is the defect
     * this column exists to close, and a setter here would be one more
     * place to forget.
     */
    #[ORM\Column(name: 'deleted_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct(
        Uuid $id,
        Uuid $tenantId,
        PurchaseOrder $purchaseOrder,
        InventoryItem $inventoryItem,
        int $lineNumber,
        string $quantityOrdered,
        string $unitCost,
        int $leadTimeDays,
    ) {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->purchaseOrder = $purchaseOrder;
        $this->inventoryItem = $inventoryItem;
        $this->lineNumber = $lineNumber;
        $this->quantityOrdered = $quantityOrdered;
        $this->unitCost = $unitCost;
        $this->leadTimeDays = $leadTimeDays;
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

    public function getPurchaseOrder(): PurchaseOrder
    {
        return $this->purchaseOrder;
    }

    public function getInventoryItem(): InventoryItem
    {
        return $this->inventoryItem;
    }

    public function getLineNumber(): int
    {
        return $this->lineNumber;
    }

    public function getQuantityOrdered(): string
    {
        return $this->quantityOrdered;
    }

    public function getQuantityReceived(): string
    {
        return $this->quantityReceived;
    }

    public function getUnitCost(): string
    {
        return $this->unitCost;
    }

    public function getLeadTimeDays(): int
    {
        return $this->leadTimeDays;
    }


    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }
}

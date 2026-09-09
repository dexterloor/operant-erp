<?php

declare(strict_types=1);

namespace App\Modules\Sales\Infrastructure\Entity;

use App\Modules\Inventory\Infrastructure\Entity\InventoryItem;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Modules\Sales. A line on a sales order — two tenant-scoped parents, the order
 * and the item, each a single-column association whose composite key is
 * hand-written in Version20260906000100 and not mapped (A1.6):
 *
 *   FOREIGN KEY (tenant_id, sales_order_id)    REFERENCES sales_order    (tenant_id, id)
 *   FOREIGN KEY (tenant_id, inventory_item_id) REFERENCES inventory_item (tenant_id, id)
 *
 * No `deleted_at`: a line belongs to its order and is removed with it or not at
 * all, so `sales_order_line_unique` stays total rather than partial.
 *
 * Quantities are numeric(19,6) and prices numeric(19,4) — both strings in PHP
 * (S-12). A float here is a rounding error that reaches an invoice.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sales_order_line')]
#[ORM\UniqueConstraint(name: 'sales_order_line_tenant_id_id_key', columns: ['tenant_id', 'id'])]
#[ORM\UniqueConstraint(name: 'sales_order_line_unique', columns: ['tenant_id', 'sales_order_id', 'line_number'], options: ['where' => '(deleted_at IS NULL)'])]
// "Demand history for this SKU" — ADR-004's forecast input.
#[ORM\Index(name: 'sales_order_line_tenant_item_idx', columns: ['tenant_id', 'inventory_item_id'], options: ['where' => '(deleted_at IS NULL)'])]
class SalesOrderLine
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** An ordinary mapped field (A1.6), shared with neither association below. */
    #[ORM\Column(name: 'tenant_id', type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\ManyToOne(targetEntity: SalesOrder::class)]
    #[ORM\JoinColumn(name: 'sales_order_id', referencedColumnName: 'id', nullable: false)]
    private SalesOrder $salesOrder;

    #[ORM\ManyToOne(targetEntity: InventoryItem::class)]
    #[ORM\JoinColumn(name: 'inventory_item_id', referencedColumnName: 'id', nullable: false)]
    private InventoryItem $inventoryItem;

    #[ORM\Column(name: 'line_number', type: Types::INTEGER)]
    private int $lineNumber;

    #[ORM\Column(type: Types::DECIMAL, precision: 19, scale: 6)]
    private string $quantity;

    #[ORM\Column(name: 'quantity_fulfilled', type: Types::DECIMAL, precision: 19, scale: 6, options: ['default' => '0'])]
    private string $quantityFulfilled = '0';

    /** S-12: money is numeric(19,4) and a string in PHP. Never a float. */
    #[ORM\Column(name: 'unit_price', type: Types::DECIMAL, precision: 19, scale: 4)]
    private string $unitPrice;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $updatedAt;

    /**
     * Written by the sales order's cascade trigger, never by this class. An
     * aggregate whose root is deleted and whose lines are not is the defect
     * this column exists to close, and a setter here would be one more
     * place to forget.
     */
    #[ORM\Column(name: 'deleted_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct(
        Uuid $id,
        Uuid $tenantId,
        SalesOrder $salesOrder,
        InventoryItem $inventoryItem,
        int $lineNumber,
        string $quantity,
        string $unitPrice,
    ) {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->salesOrder = $salesOrder;
        $this->inventoryItem = $inventoryItem;
        $this->lineNumber = $lineNumber;
        $this->quantity = $quantity;
        $this->unitPrice = $unitPrice;
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

    public function getSalesOrder(): SalesOrder
    {
        return $this->salesOrder;
    }

    public function getInventoryItem(): InventoryItem
    {
        return $this->inventoryItem;
    }

    public function getLineNumber(): int
    {
        return $this->lineNumber;
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function getQuantityFulfilled(): string
    {
        return $this->quantityFulfilled;
    }

    public function getUnitPrice(): string
    {
        return $this->unitPrice;
    }

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }
}

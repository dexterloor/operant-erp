<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Infrastructure\Entity;

use App\Modules\Inventory\Infrastructure\Entity\InventoryItem;
use App\SharedKernel\Reference\Currency;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Modules\Suppliers. What one supplier charges for one item, and how long they
 * take -- the join that makes "who should we buy this from" answerable.
 *
 * ---------------------------------------------------------------------------
 * `leadTimeDays` is a required constructor argument on purpose
 * ---------------------------------------------------------------------------
 * The column carries no DEFAULT, and this class refuses to let one in through
 * the back door. A hard-coded lead time was R-24 in the risk register and is now
 * an Output 9 acceptance criterion: a forecast built on an invented 7 days is
 * not a worse forecast, it is a confident one that nobody can tell is wrong.
 * Refusing a default makes "we do not know this supplier's lead time" a failed
 * INSERT at the point of ignorance rather than a plausible reorder suggestion
 * three screens later.
 *
 * Two tenant-scoped parents -- supplier and inventory_item -- each a
 * single-column association per A1.6, with
 *
 *   FOREIGN KEY (tenant_id, supplier_id)       REFERENCES supplier       (tenant_id, id)
 *   FOREIGN KEY (tenant_id, inventory_item_id) REFERENCES inventory_item (tenant_id, id)
 *
 * written by hand in Version20260906000100 and deliberately not mapped.
 * `currency` is reference data (ADR-002 §6): no tenant_id, no RLS, a plain
 * single-column association.
 */
#[ORM\Entity]
#[ORM\Table(name: 'supplier_product')]
#[ORM\UniqueConstraint(name: 'supplier_product_tenant_id_id_key', columns: ['tenant_id', 'id'])]
// One offer per supplier per item. A second price from the same supplier is an
// edit, not a new row.
#[ORM\UniqueConstraint(name: 'supplier_product_unique', columns: ['tenant_id', 'supplier_id', 'inventory_item_id'])]
// "Which suppliers can provide this item, and which is fastest" — ADR-004's
// named dependency, and the reverse of the unique key.
#[ORM\Index(name: 'supplier_product_tenant_item_idx', columns: ['tenant_id', 'inventory_item_id', 'lead_time_days'])]
class SupplierProduct
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** An ordinary mapped field. Never an #[ORM\Id], never a join column (A1.6). */
    #[ORM\Column(name: 'tenant_id', type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(name: 'supplier_id', referencedColumnName: 'id', nullable: false)]
    private Supplier $supplier;

    #[ORM\ManyToOne(targetEntity: InventoryItem::class)]
    #[ORM\JoinColumn(name: 'inventory_item_id', referencedColumnName: 'id', nullable: false)]
    private InventoryItem $inventoryItem;

    /** The supplier's own catalogue number, which is what goes on the order. */
    #[ORM\Column(name: 'supplier_sku', type: Types::TEXT)]
    private string $supplierSku;

    /** No default. See the class docblock: R-24, now an Output 9 acceptance criterion. */
    #[ORM\Column(name: 'lead_time_days', type: Types::INTEGER)]
    private int $leadTimeDays;

    /** S-12: quantities are numeric(19,6) and strings in PHP. */
    #[ORM\Column(name: 'min_order_quantity', type: Types::DECIMAL, precision: 19, scale: 6, options: ['default' => '1'])]
    private string $minOrderQuantity = '1';

    /** S-12: money is numeric(19,4) and a string in PHP. Never a float. */
    #[ORM\Column(name: 'unit_cost', type: Types::DECIMAL, precision: 19, scale: 4)]
    private string $unitCost;

    #[ORM\ManyToOne(targetEntity: Currency::class)]
    #[ORM\JoinColumn(name: 'currency_code', referencedColumnName: 'code', nullable: false)]
    private Currency $currency;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Uuid $id,
        Uuid $tenantId,
        Supplier $supplier,
        InventoryItem $inventoryItem,
        string $supplierSku,
        int $leadTimeDays,
        string $unitCost,
        Currency $currency,
    ) {
        if ($leadTimeDays < 0) {
            throw new \InvalidArgumentException('lead_time_days cannot be negative.');
        }

        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->supplier = $supplier;
        $this->inventoryItem = $inventoryItem;
        $this->supplierSku = $supplierSku;
        $this->leadTimeDays = $leadTimeDays;
        $this->unitCost = $unitCost;
        $this->currency = $currency;
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

    public function getSupplier(): Supplier
    {
        return $this->supplier;
    }

    public function getInventoryItem(): InventoryItem
    {
        return $this->inventoryItem;
    }

    public function getSupplierSku(): string
    {
        return $this->supplierSku;
    }

    public function getLeadTimeDays(): int
    {
        return $this->leadTimeDays;
    }

    public function getMinOrderQuantity(): string
    {
        return $this->minOrderQuantity;
    }

    public function getUnitCost(): string
    {
        return $this->unitCost;
    }

    public function getCurrency(): Currency
    {
        return $this->currency;
    }
}

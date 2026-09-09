<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure\Entity;

use App\Modules\Warehouses\Infrastructure\Entity\StorageLocation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Modules\Inventory. **The contended row in the whole system** (ARCHITECTURE
 * W-5, S-12): one row per (item, location), and every movement of stock rewrites
 * one.
 *
 * ---------------------------------------------------------------------------
 * Why `version` exists
 * ---------------------------------------------------------------------------
 * The concurrency case is a fulfilment and a goods receipt racing on the same
 * SKU in the same bin. Both read qty_on_hand, both compute a new figure from
 * what they read, and the second write silently discards the first -- a lost
 * update that shows up days later as stock that does not exist. `version` is
 * the optimistic lock that turns that into an OptimisticLockException the
 * caller can retry, and the column default of 1 is kept so a row inserted by
 * anything other than the ORM starts on the same counter.
 *
 * W-5's lock ordering is the other half and lives in the services, not here:
 * **this row is always locked LAST in any transaction.** Purchase order, then
 * receipt, then lines, then stock_level. Every writer taking the same order is
 * what makes deadlock impossible rather than merely unlikely.
 *
 * ---------------------------------------------------------------------------
 * Two tenant-scoped parents
 * ---------------------------------------------------------------------------
 * inventory_item AND storage_location, both reached through `tenant_id`:
 *
 *   FOREIGN KEY (tenant_id, inventory_item_id)   REFERENCES inventory_item   (tenant_id, id)
 *   FOREIGN KEY (tenant_id, storage_location_id) REFERENCES storage_location (tenant_id, id)
 *
 * This is exactly the shape A1.6 disqualified the composite-identifier mapping
 * over: two associations cannot each own the same `tenant_id` column, and the
 * one that writes it last wins. Under this mapping neither owns it -- the field
 * does -- and Postgres enforces the tenant agreement whatever the ORM believes.
 * The composite keys are hand-written in Version20260906000100 and not mapped.
 */
#[ORM\Entity]
#[ORM\Table(name: 'stock_level')]
#[ORM\UniqueConstraint(name: 'stock_level_tenant_id_id_key', columns: ['tenant_id', 'id'])]
// Also the upsert key: "the stock of this item in this bin" is created on first
// movement and updated forever after.
#[ORM\UniqueConstraint(name: 'stock_level_unique', columns: ['tenant_id', 'inventory_item_id', 'storage_location_id'])]
// "What is physically in this bin" — the pick list, and the reverse of the
// unique key's prefix.
#[ORM\Index(name: 'stock_level_tenant_location_idx', columns: ['tenant_id', 'storage_location_id', 'inventory_item_id'])]
class StockLevel
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** An ordinary mapped field. Never an #[ORM\Id], never a join column (A1.6). */
    #[ORM\Column(name: 'tenant_id', type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\ManyToOne(targetEntity: InventoryItem::class)]
    #[ORM\JoinColumn(name: 'inventory_item_id', referencedColumnName: 'id', nullable: false)]
    private InventoryItem $inventoryItem;

    #[ORM\ManyToOne(targetEntity: StorageLocation::class)]
    #[ORM\JoinColumn(name: 'storage_location_id', referencedColumnName: 'id', nullable: false)]
    private StorageLocation $storageLocation;

    /** S-12: quantities are numeric(19,6) and strings in PHP. Half a kilo is not an edge case. */
    #[ORM\Column(name: 'qty_on_hand', type: Types::DECIMAL, precision: 19, scale: 6, options: ['default' => '0'])]
    private string $qtyOnHand = '0';

    #[ORM\Column(name: 'qty_reserved', type: Types::DECIMAL, precision: 19, scale: 6, options: ['default' => '0'])]
    private string $qtyReserved = '0';

    /**
     * The optimistic lock. The column default stays 1 so a row written outside
     * the ORM is on the same counter as one written through it.
     */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    private int $version = 1;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Uuid $id,
        Uuid $tenantId,
        InventoryItem $inventoryItem,
        StorageLocation $storageLocation,
    ) {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->inventoryItem = $inventoryItem;
        $this->storageLocation = $storageLocation;
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

    public function getInventoryItem(): InventoryItem
    {
        return $this->inventoryItem;
    }

    public function getStorageLocation(): StorageLocation
    {
        return $this->storageLocation;
    }

    public function getQtyOnHand(): string
    {
        return $this->qtyOnHand;
    }

    public function getQtyReserved(): string
    {
        return $this->qtyReserved;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * The only mutator, and it takes an absolute figure rather than a delta: the
     * caller has already locked this row last (W-5) and computed the new total
     * from the movements it posted. A delta applied here would be a second place
     * arithmetic can happen.
     *
     * `stock_level_reserved_sane` in the database is what actually rejects
     * qty_reserved > qty_on_hand; this refuses the obvious cases early so the
     * failure names the field rather than the constraint.
     */
    public function adjustTo(string $qtyOnHand, string $qtyReserved): void
    {
        if (-1 === bccomp($qtyReserved, '0', 6)) {
            throw new \InvalidArgumentException('qty_reserved cannot be negative.');
        }

        if (1 === bccomp($qtyReserved, $qtyOnHand, 6)) {
            throw new \InvalidArgumentException('qty_reserved cannot exceed qty_on_hand.');
        }

        $this->qtyOnHand = $qtyOnHand;
        $this->qtyReserved = $qtyReserved;
        $this->updatedAt = new \DateTimeImmutable();
    }
}

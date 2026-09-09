<?php

declare(strict_types=1);

namespace App\Modules\Sales\Infrastructure\Entity;

use App\Modules\Warehouses\Infrastructure\Entity\StorageLocation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Modules\Sales. What actually left which bin, against which order line.
 *
 * **Three** tenant-scoped parents — the shipment, the order line and the
 * storage location it was picked from — each a single-column association whose
 * composite foreign key is hand-written in Version20260906000100 and
 * deliberately not mapped (A1.6):
 *
 *   FOREIGN KEY (tenant_id, fulfilment_id)       REFERENCES fulfilment       (tenant_id, id)
 *   FOREIGN KEY (tenant_id, sales_order_line_id) REFERENCES sales_order_line (tenant_id, id)
 *   FOREIGN KEY (tenant_id, storage_location_id) REFERENCES storage_location (tenant_id, id)
 *
 * The location is on the line rather than on the shipment because one shipment
 * legitimately picks from several bins, and the stock movement it posts has to
 * name the bin it decremented.
 *
 * Never deleted, for the same reason as Fulfilment: no `deleted_at` column and
 * no DELETE grant. `quantity` is CHECK-constrained positive, so a correction is
 * a compensating movement rather than a negative line.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fulfilment_line')]
#[ORM\UniqueConstraint(name: 'fulfilment_line_tenant_id_id_key', columns: ['tenant_id', 'id'])]
// "The contents of this shipment" — the packing note.
#[ORM\Index(name: 'fulfilment_line_tenant_fulfilment_idx', columns: ['tenant_id', 'fulfilment_id'])]
// "How much of this order line has shipped" — drives the sales order status.
#[ORM\Index(name: 'fulfilment_line_tenant_order_line_idx', columns: ['tenant_id', 'sales_order_line_id'])]
class FulfilmentLine
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /**
     * An ordinary mapped field (A1.6). Not shared with any of the three
     * associations below — three associations cannot each own one column.
     */
    #[ORM\Column(name: 'tenant_id', type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\ManyToOne(targetEntity: Fulfilment::class)]
    #[ORM\JoinColumn(name: 'fulfilment_id', referencedColumnName: 'id', nullable: false)]
    private Fulfilment $fulfilment;

    #[ORM\ManyToOne(targetEntity: SalesOrderLine::class)]
    #[ORM\JoinColumn(name: 'sales_order_line_id', referencedColumnName: 'id', nullable: false)]
    private SalesOrderLine $salesOrderLine;

    #[ORM\ManyToOne(targetEntity: StorageLocation::class)]
    #[ORM\JoinColumn(name: 'storage_location_id', referencedColumnName: 'id', nullable: false)]
    private StorageLocation $storageLocation;

    /** S-12: a quantity is numeric(19,6) and a string in PHP, never a float. */
    #[ORM\Column(type: Types::DECIMAL, precision: 19, scale: 6)]
    private string $quantity;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Uuid $id,
        Uuid $tenantId,
        Fulfilment $fulfilment,
        SalesOrderLine $salesOrderLine,
        StorageLocation $storageLocation,
        string $quantity,
    ) {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->fulfilment = $fulfilment;
        $this->salesOrderLine = $salesOrderLine;
        $this->storageLocation = $storageLocation;
        $this->quantity = $quantity;
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

    public function getFulfilment(): Fulfilment
    {
        return $this->fulfilment;
    }

    public function getSalesOrderLine(): SalesOrderLine
    {
        return $this->salesOrderLine;
    }

    public function getStorageLocation(): StorageLocation
    {
        return $this->storageLocation;
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }
}

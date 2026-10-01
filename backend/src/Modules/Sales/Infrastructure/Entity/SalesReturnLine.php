<?php

declare(strict_types=1);

namespace App\Modules\Sales\Infrastructure\Entity;

use App\Modules\Warehouses\Infrastructure\Entity\StorageLocation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Modules\Sales. What came back, against which shipped line, into which bin — sales step 7 (`Version20261001000100`).
 *
 * Read-only to the ORM and without a constructor, for `SalesReturn`'s reasons: written by raw SQL, never edited,
 * never deleted. Three tenant-scoped parents, single-column associations over hand-written composite keys (A1.6):
 *
 *   FOREIGN KEY (tenant_id, sales_return_id)     REFERENCES sales_return     (tenant_id, id)
 *   FOREIGN KEY (tenant_id, fulfilment_line_id)  REFERENCES fulfilment_line  (tenant_id, id)
 *   FOREIGN KEY (tenant_id, storage_location_id) REFERENCES storage_location (tenant_id, id)
 *
 * That the shipped line belongs to the return's fulfilment is an application rule, not a constraint: a three-column
 * key is refused (every composite FK is `(tenant_id, col) -> (tenant_id, id)`).
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'sales_return_line')]
#[ORM\UniqueConstraint(name: 'sales_return_line_tenant_id_id_key', columns: ['tenant_id', 'id'])]
// One line per shipped line per return: the per-line bound reads one row per return.
#[ORM\UniqueConstraint(name: 'sales_return_line_once_per_line', columns: ['tenant_id', 'sales_return_id', 'fulfilment_line_id'])]
// "Everything returned against this shipped line" — the per-line bound and the credited quantity.
#[ORM\Index(name: 'sales_return_line_tenant_fulfilment_line_idx', columns: ['tenant_id', 'fulfilment_line_id'])]
class SalesReturnLine
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** An ordinary mapped field (A1.6). */
    #[ORM\Column(name: 'tenant_id', type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\ManyToOne(targetEntity: SalesReturn::class)]
    #[ORM\JoinColumn(name: 'sales_return_id', referencedColumnName: 'id', nullable: false)]
    private SalesReturn $salesReturn;

    #[ORM\ManyToOne(targetEntity: FulfilmentLine::class)]
    #[ORM\JoinColumn(name: 'fulfilment_line_id', referencedColumnName: 'id', nullable: false)]
    private FulfilmentLine $fulfilmentLine;

    #[ORM\ManyToOne(targetEntity: StorageLocation::class)]
    #[ORM\JoinColumn(name: 'storage_location_id', referencedColumnName: 'id', nullable: false)]
    private StorageLocation $storageLocation;

    /** S-12: a quantity is numeric(19,6) and a string in PHP, never a float. CHECK-constrained positive. */
    #[ORM\Column(type: Types::DECIMAL, precision: 19, scale: 6)]
    private string $quantity;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $updatedAt;

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSalesReturn(): SalesReturn
    {
        return $this->salesReturn;
    }

    public function getFulfilmentLine(): FulfilmentLine
    {
        return $this->fulfilmentLine;
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

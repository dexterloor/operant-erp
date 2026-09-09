<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\Infrastructure\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Modules\Warehouses. The bin, shelf or zone stock is actually held in.
 *
 * Soft-deleted for the same reason a warehouse is: stock movements and
 * fulfilment lines name a location and must keep naming it after the bin is
 * retired. Which is why the two kinds of uniqueness here are not
 * interchangeable:
 *
 *   storage_location_tenant_id_id_key             TOTAL. ADR-002 §5's composite
 *                                                 foreign key target -- a
 *                                                 composite FK cannot reference
 *                                                 a partial index, and
 *                                                 stock_level legitimately
 *                                                 references a retired bin.
 *   storage_location_tenant_warehouse_code_live   PARTIAL (ADR-007 §1). Without
 *                                                 the predicate, retiring bin
 *                                                 A-01-01 and relabelling a new
 *                                                 one collides with a row the
 *                                                 user cannot see.
 *
 * `warehouse` is a single-column association per A1.6. The composite key
 * FOREIGN KEY (tenant_id, warehouse_id) REFERENCES warehouse (tenant_id, id) is
 * written by hand in Version20260906000100 and deliberately not mapped.
 */
#[ORM\Entity]
#[ORM\Table(name: 'storage_location')]
#[ORM\UniqueConstraint(name: 'storage_location_tenant_id_id_key', columns: ['tenant_id', 'id'])]
#[ORM\UniqueConstraint(name: 'storage_location_tenant_warehouse_code_live', columns: ['tenant_id', 'warehouse_id', 'code'], options: ['where' => '(deleted_at IS NULL)'])]
class StorageLocation
{
    public const KIND_BIN = 'bin';
    public const KIND_SHELF = 'shelf';
    public const KIND_ZONE = 'zone';
    public const KIND_STAGING = 'staging';
    public const KIND_QUARANTINE = 'quarantine';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** An ordinary mapped field. Never an #[ORM\Id], never a join column (A1.6). */
    #[ORM\Column(name: 'tenant_id', type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'warehouse_id', referencedColumnName: 'id', nullable: false)]
    private Warehouse $warehouse;

    #[ORM\Column(type: Types::TEXT)]
    private string $code;

    #[ORM\Column(type: Types::TEXT, options: ['default' => 'bin'])]
    private string $kind = self::KIND_BIN;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'deleted_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct(
        Uuid $id,
        Uuid $tenantId,
        Warehouse $warehouse,
        string $code,
        string $kind = self::KIND_BIN,
    ) {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->warehouse = $warehouse;
        $this->code = $code;
        $this->kind = $kind;
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

    public function getWarehouse(): Warehouse
    {
        return $this->warehouse;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }

    public function delete(): void
    {
        $this->deletedAt = new \DateTimeImmutable();
        $this->updatedAt = $this->deletedAt;
    }
}

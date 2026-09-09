<?php

declare(strict_types=1);

namespace App\Modules\Sales\Infrastructure\Entity;

use App\Modules\Warehouses\Infrastructure\Entity\Warehouse;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Modules\Sales. A shipment against a sales order.
 *
 * ---------------------------------------------------------------------------
 * Never deleted, and the schema says so rather than the code
 * ---------------------------------------------------------------------------
 * This table has no `deleted_at` **and no DELETE grant** — erp_app holds
 * SELECT, INSERT and UPDATE on it and nothing more (Version20260906000100's
 * "documents that are voided or corrected, never removed" block). A shipment
 * that left the building is a fact about the physical world; the correction for
 * a mistaken one is a return, not an erasure. Making that a missing privilege
 * rather than a convention means a stray `DELETE` is permission denied and not
 * a code review someone skipped.
 *
 * Because nothing here is ever soft-deleted, `fulfilment_number_unique` is
 * total: there is no invisible row for a re-used number to collide with, so
 * ADR-007 §1's partial-index rule does not apply.
 *
 * Two tenant-scoped parents, both single-column associations whose composite
 * keys are hand-written in the migration and not mapped (A1.6):
 *
 *   FOREIGN KEY (tenant_id, sales_order_id) REFERENCES sales_order (tenant_id, id)
 *   FOREIGN KEY (tenant_id, warehouse_id)   REFERENCES warehouse   (tenant_id, id)
 */
#[ORM\Entity]
#[ORM\Table(name: 'fulfilment')]
#[ORM\UniqueConstraint(name: 'fulfilment_tenant_id_id_key', columns: ['tenant_id', 'id'])]
#[ORM\UniqueConstraint(name: 'fulfilment_number_unique', columns: ['tenant_id', 'fulfilment_number'])]
// "What has shipped against this order" — the fulfilment screen, newest last.
#[ORM\Index(name: 'fulfilment_tenant_order_idx', columns: ['tenant_id', 'sales_order_id', 'shipped_at'])]
class Fulfilment
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

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'warehouse_id', referencedColumnName: 'id', nullable: false)]
    private Warehouse $warehouse;

    #[ORM\Column(name: 'fulfilment_number', type: Types::TEXT)]
    private string $fulfilmentNumber;

    #[ORM\Column(name: 'shipped_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $shippedAt;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Uuid $id,
        Uuid $tenantId,
        SalesOrder $salesOrder,
        Warehouse $warehouse,
        string $fulfilmentNumber,
        ?\DateTimeImmutable $shippedAt = null,
    ) {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->salesOrder = $salesOrder;
        $this->warehouse = $warehouse;
        $this->fulfilmentNumber = $fulfilmentNumber;
        $this->createdAt = new \DateTimeImmutable();
        $this->shippedAt = $shippedAt ?? $this->createdAt;
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

    public function getWarehouse(): Warehouse
    {
        return $this->warehouse;
    }

    public function getFulfilmentNumber(): string
    {
        return $this->fulfilmentNumber;
    }

    public function getShippedAt(): \DateTimeImmutable
    {
        return $this->shippedAt;
    }
}

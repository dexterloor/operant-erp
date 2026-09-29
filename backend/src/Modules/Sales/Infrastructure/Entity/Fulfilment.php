<?php

declare(strict_types=1);

namespace App\Modules\Sales\Infrastructure\Entity;

use App\Modules\UserManagement\Infrastructure\Entity\AppUser;
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
 * This table has no `deleted_at` **and no DELETE grant** — and today erp_app
 * holds SELECT on it and nothing more: no `src/` path writes a fulfilment yet, so
 * ADR-002 Amendment 4 (`Version20260916000300`) revoked the INSERT and UPDATE
 * Version20260906000100 granted, and `CreateFulfilment` grants what its writer
 * issues when built — never DELETE. A shipment
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
// A retried CreateFulfilment collides here (Version20260922000100, sales step 5 C2). TOTAL, like
// `goods_receipt_idem_unique`, because this table has no `deleted_at`.
#[ORM\UniqueConstraint(name: 'fulfilment_idem_unique', columns: ['tenant_id', 'idempotency_key'])]
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

    /**
     * Who declared the goods shipped — the authenticated caller at write time. **NOT NULL**, unlike
     * `GoodsReceipt::$receivedBy`: owner ruling R6 of 2026-09-22 (V2-R-23). A fulfilment is the event
     * SoD-4 exists to attribute. Since B-4a (2026-09-29) `fulfilment.created` names the shipper on the trail as
     * well; this column is still the one a read shows. Evidence for SoD-4, not enforcement.
     */
    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'shipped_by_user_id', referencedColumnName: 'id', nullable: false)]
    private AppUser $shippedBy;

    #[ORM\Column(name: 'fulfilment_number', type: Types::TEXT)]
    private string $fulfilmentNumber;

    /**
     * The hex SHA-256 of the `Idempotency-Key` header (D-B), never the header. **Non-nullable,
     * following `GoodsReceipt::$idempotencyKey`**: no fulfilment predates it (`Version20260922000100`).
     * Set once, in the `INSERT`, and there is no setter.
     */
    #[ORM\Column(name: 'idempotency_key', type: Types::TEXT)]
    private string $idempotencyKey;

    /** `RequestDigest::of()` of the request that created the row. Request plumbing, never read into the domain. */
    #[ORM\Column(name: 'request_digest', type: Types::TEXT)]
    private string $requestDigest;

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
        AppUser $shippedBy,
        string $fulfilmentNumber,
        string $idempotencyKey,
        string $requestDigest,
        ?\DateTimeImmutable $shippedAt = null,
    ) {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->salesOrder = $salesOrder;
        $this->warehouse = $warehouse;
        $this->shippedBy = $shippedBy;
        $this->fulfilmentNumber = $fulfilmentNumber;
        $this->idempotencyKey = $idempotencyKey;
        $this->requestDigest = $requestDigest;
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

    public function getShippedBy(): AppUser
    {
        return $this->shippedBy;
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

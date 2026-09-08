<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\GuardsStatusTransitions;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Modules\Purchasing. **The entity W0-a could not test.**
 *
 * A1.6 disqualified the composite ORM identifier because the association, not
 * the field, owns a shared `tenant_id` column: with `$supplier = null` Doctrine
 * writes NULL into tenant_id, and with the field set to one tenant and the
 * parent belonging to another the parent silently wins. W0-a proved the
 * replacement works -- scalar id, `tenantId` as a plain field, single-column
 * association, composite FK by hand -- but it proved it on an entity with
 * **one** parent. Its second composite key was on `stock_movement`, which had
 * no mapping at all, so "does this survive an entity that is a child of two
 * tenant-scoped parents at once" stayed open. That is the realistic ERP shape
 * and it is exactly where the rejected mapping failed.
 *
 * This class has **three** tenant-scoped parents -- supplier, warehouse and the
 * ordering user -- each a single-column association whose composite foreign key
 * is written by hand in Version20260906000100:
 *
 *   FOREIGN KEY (tenant_id, supplier_id)        REFERENCES supplier  (tenant_id, id)
 *   FOREIGN KEY (tenant_id, warehouse_id)       REFERENCES warehouse (tenant_id, id)
 *   FOREIGN KEY (tenant_id, created_by_user_id) REFERENCES app_user  (tenant_id, id)
 *
 * All three share the single `tenant_id` column, which is the case the rejected
 * mapping could not express at all: three associations cannot each own the same
 * column. Under this shape none of them owns it -- the field does -- and
 * Postgres enforces the tenant agreement regardless of what the ORM believes.
 * TwoParentCompositeKeyTest is the measurement.
 */
#[ORM\Entity]
#[ORM\Table(name: 'purchase_order')]
#[ORM\UniqueConstraint(name: 'purchase_order_tenant_id_id_key', columns: ['tenant_id', 'id'])]
#[ORM\UniqueConstraint(name: 'purchase_order_tenant_number_live', columns: ['tenant_id', 'order_number'], options: ['where' => '(deleted_at IS NULL)'])]
#[ORM\Index(name: 'purchase_order_tenant_open_idx', columns: ['tenant_id', 'ordered_at'], options: ['where' => "((deleted_at IS NULL) AND (status = ANY (ARRAY['ordered'::text, 'partially_received'::text])))"])]
#[ORM\Index(name: 'purchase_order_tenant_supplier_idx', columns: ['tenant_id', 'supplier_id', 'ordered_at'], options: ['where' => '(deleted_at IS NULL)'])]
class PurchaseOrder
{
    use GuardsStatusTransitions;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ORDERED = 'ordered';
    public const STATUS_PARTIALLY_RECEIVED = 'partially_received';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /**
     * An ordinary mapped field. Never an #[ORM\Id], never a join column, and in
     * particular not shared with any of the three associations below -- that is
     * the whole of A1.6.
     */
    #[ORM\Column(name: 'tenant_id', type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\Column(name: 'order_number', type: Types::TEXT)]
    private string $orderNumber;

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(name: 'supplier_id', referencedColumnName: 'id', nullable: false)]
    private Supplier $supplier;

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'warehouse_id', referencedColumnName: 'id', nullable: false)]
    private Warehouse $warehouse;

    /** Nullable: a purchase order raised by the reorder agent has no human author. */
    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'created_by_user_id', referencedColumnName: 'id', nullable: true)]
    private ?AppUser $createdBy = null;

    #[ORM\Column(type: Types::TEXT, options: ['default' => 'draft'])]
    private string $status = self::STATUS_DRAFT;

    #[ORM\ManyToOne(targetEntity: Currency::class)]
    #[ORM\JoinColumn(name: 'currency_code', referencedColumnName: 'code', nullable: false)]
    private Currency $currency;

    /** S-12: money is numeric(19,4) and a string in PHP. Never a float. */
    #[ORM\Column(name: 'total_amount', type: Types::DECIMAL, precision: 19, scale: 4, options: ['default' => '0'])]
    private string $totalAmount = '0';

    #[ORM\Column(name: 'ordered_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $orderedAt = null;

    #[ORM\Column(name: 'expected_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expectedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'deleted_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct(
        Uuid $id,
        Uuid $tenantId,
        string $orderNumber,
        Supplier $supplier,
        Warehouse $warehouse,
        Currency $currency,
    ) {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->orderNumber = $orderNumber;
        $this->supplier = $supplier;
        $this->warehouse = $warehouse;
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

    public function getOrderNumber(): string
    {
        return $this->orderNumber;
    }

    public function getSupplier(): Supplier
    {
        return $this->supplier;
    }

    public function getWarehouse(): Warehouse
    {
        return $this->warehouse;
    }

    public function getCreatedBy(): ?AppUser
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?AppUser $user): void
    {
        $this->createdBy = $user;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCurrency(): Currency
    {
        return $this->currency;
    }

    public function getTotalAmount(): string
    {
        return $this->totalAmount;
    }

    /**
     * E-19's *"guarded call"*, and until now it was guarded by nothing: it would
     * re-open a `cancelled` or a `received` order, and `purchase_order_ordered_at_set`
     * is satisfied by that write. §8.3's matrix makes `draft` the only legal source.
     */
    public function place(\DateTimeImmutable $orderedAt, ?\DateTimeImmutable $expectedAt = null): void
    {
        $this->refuseUnlessStatusIsOneOf(__FUNCTION__, [self::STATUS_DRAFT]);

        $this->status = self::STATUS_ORDERED;
        $this->orderedAt = $orderedAt;
        $this->expectedAt = $expectedAt;
        $this->updatedAt = new \DateTimeImmutable();
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

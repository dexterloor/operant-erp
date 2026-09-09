<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure\Entity;

use App\Modules\Suppliers\Infrastructure\Entity\Supplier;
use App\SharedKernel\Reference\Currency;
use App\SharedKernel\Reference\UnitOfMeasure;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Tenant-scoped child.
 *
 * ADR-002 §5 mandates FOREIGN KEY (tenant_id, supplier_id) REFERENCES
 * supplier (tenant_id, id). That asks tenant_id to be both a mapped field and
 * half a pointer. Three mappings were measured against ORM 3.6 and the outcome
 * decided this class:
 *
 *   composite ORM identifier (tenant_id, id) + two-column JoinColumn
 *       Doctrine ACCEPTS it and emits exactly the DDL the ADR asks for -- but the
 *       association, not the field, writes the shared column. With
 *       $supplier = null Doctrine emits NULL for tenant_id and the insert dies on
 *       the NOT NULL constraint; and with the field set to one tenant and the
 *       parent belonging to another, the parent silently wins. It also makes the
 *       identifier composite, which propagates into every find(), every DQL
 *       parameter bind and every API Platform IRI.
 *
 *   scalar id + UNIQUE (tenant_id, id) + shared join column marked
 *   insertable:false, updatable:false
 *       Rejected by Doctrine outright: join columns must reference ALL identifier
 *       columns of the target. insertable/updatable does not change that.
 *
 *   THIS ONE -- scalar id, tenantId as a plain field, a single-column association
 *       on supplier_id, and the composite foreign key written by hand in the
 *       migration.
 *       The ADR's security property is a database constraint, and Postgres
 *       enforces it whatever the ORM believes. The cost is one known divergence
 *       between mapping and schema, pinned by
 *       DoctrineMappingTest::theOnlyDivergenceFromTheDatabaseIsTheCompositeForeignKey.
 */
#[ORM\Entity]
#[ORM\Table(name: 'inventory_item')]
#[ORM\UniqueConstraint(name: 'inventory_item_tenant_id_id_key', columns: ['tenant_id', 'id'])]
#[ORM\UniqueConstraint(name: 'inventory_item_tenant_sku_live', columns: ['tenant_id', 'sku'], options: ['where' => '(deleted_at IS NULL)'])]
#[ORM\Index(name: 'inventory_item_tenant_supplier_idx', columns: ['tenant_id', 'supplier_id'])]
class InventoryItem
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(name: 'tenant_id', type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\Column(type: Types::TEXT)]
    private string $sku;

    #[ORM\Column(type: Types::TEXT)]
    private string $name;

    // Reference data: no tenant_id, no RLS, single-column association.
    #[ORM\ManyToOne(targetEntity: UnitOfMeasure::class)]
    #[ORM\JoinColumn(name: 'unit_of_measure_code', referencedColumnName: 'code', nullable: false)]
    private UnitOfMeasure $unitOfMeasure;

    #[ORM\Column(name: 'unit_cost', type: Types::DECIMAL, precision: 19, scale: 4, options: ['default' => '0'])]
    private string $unitCost = '0';

    #[ORM\ManyToOne(targetEntity: Currency::class)]
    #[ORM\JoinColumn(name: 'currency_code', referencedColumnName: 'code', nullable: false)]
    private Currency $currency;

    // ADR-004's reorder policy parameters. Quantities are numeric(19,6) (S-12):
    // a reorder point of 2.5 kg is ordinary, and a float would make the forecast
    // non-deterministic, which ADR-004 forbids outright.
    #[ORM\Column(name: 'reorder_point', type: Types::DECIMAL, precision: 19, scale: 6, options: ['default' => '0'])]
    private string $reorderPoint = '0';

    #[ORM\Column(name: 'reorder_quantity', type: Types::DECIMAL, precision: 19, scale: 6, options: ['default' => '0'])]
    private string $reorderQuantity = '0';

    #[ORM\Column(name: 'safety_stock', type: Types::DECIMAL, precision: 19, scale: 6, options: ['default' => '0'])]
    private string $safetyStock = '0';

    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(name: 'supplier_id', referencedColumnName: 'id', nullable: true)]
    private ?Supplier $supplier = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'deleted_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct(
        Uuid $id,
        Uuid $tenantId,
        string $sku,
        string $name,
        UnitOfMeasure $unitOfMeasure,
        Currency $currency,
    ) {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->sku = $sku;
        $this->name = $name;
        $this->unitOfMeasure = $unitOfMeasure;
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

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getSupplier(): ?Supplier
    {
        return $this->supplier;
    }

    public function setSupplier(?Supplier $supplier): void
    {
        $this->supplier = $supplier;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getUnitOfMeasure(): UnitOfMeasure
    {
        return $this->unitOfMeasure;
    }

    public function getCurrency(): Currency
    {
        return $this->currency;
    }

    public function getReorderPoint(): string
    {
        return $this->reorderPoint;
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

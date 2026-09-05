<?php

declare(strict_types=1);

namespace App\Entity;

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
#[ORM\UniqueConstraint(name: 'inventory_item_tenant_sku_key', columns: ['tenant_id', 'sku'])]
#[ORM\Index(name: 'inventory_item_tenant_supplier_idx', columns: ['tenant_id', 'supplier_id'])]
#[ORM\Index(name: 'inventory_item_tenant_created_idx', columns: ['tenant_id', 'created_at'])]
class InventoryItem
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(name: 'tenant_id', type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\Column(type: Types::TEXT)]
    private string $sku;

    #[ORM\Column(name: 'qty_on_hand', type: Types::INTEGER, options: ['default' => 0])]
    private int $qtyOnHand = 0;

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(name: 'supplier_id', referencedColumnName: 'id', nullable: true)]
    private ?Supplier $supplier = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    public function __construct(Uuid $id, Uuid $tenantId, string $sku)
    {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->sku = $sku;
        $this->createdAt = new \DateTimeImmutable();
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
}

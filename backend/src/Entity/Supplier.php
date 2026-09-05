<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Tenant-scoped parent.
 *
 * `tenantId` is an ordinary mapped field. It is deliberately NOT part of the ORM
 * identifier and NOT part of any association's join columns -- see
 * InventoryItem for the measurement that forced that.
 *
 * The database additionally carries UNIQUE (tenant_id, id) so that
 * InventoryItem's composite foreign key has something to reference
 * (ADR-002 §5). Doctrine neither knows nor needs to know.
 */
#[ORM\Entity]
#[ORM\Table(name: 'supplier')]
// Declared here as well as in the migration on purpose: anything the mapping
// does not know about, SchemaTool proposes to drop, which would bury the one
// divergence that actually matters under a pile of noise.
#[ORM\UniqueConstraint(name: 'supplier_tenant_id_id_key', columns: ['tenant_id', 'id'])]
#[ORM\UniqueConstraint(name: 'supplier_tenant_name_key', columns: ['tenant_id', 'name'])]
#[ORM\Index(name: 'supplier_tenant_created_idx', columns: ['tenant_id', 'created_at'])]
class Supplier
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(name: 'tenant_id', type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\Column(type: Types::TEXT)]
    private string $name;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    public function __construct(Uuid $id, Uuid $tenantId, string $name)
    {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->name = $name;
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

    public function getName(): string
    {
        return $this->name;
    }
}

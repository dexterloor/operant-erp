<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Modules\Warehouses. Soft-deleted: purchase orders and fulfilments name a
 * warehouse and must keep naming it after the site closes.
 *
 * Note the two kinds of uniqueness, and that they are not interchangeable:
 *
 *   warehouse_tenant_id_id_key   TOTAL. ADR-002 §5's composite foreign key
 *                                target. A composite FK cannot reference a
 *                                partial index, and a child legitimately
 *                                references a soft-deleted parent.
 *   warehouse_tenant_code_live   PARTIAL. ADR-007 §1. Without the predicate,
 *                                closing a site and opening a new one with the
 *                                same code fails against a row the user cannot
 *                                see.
 */
#[ORM\Entity]
#[ORM\Table(name: 'warehouse')]
#[ORM\UniqueConstraint(name: 'warehouse_tenant_id_id_key', columns: ['tenant_id', 'id'])]
#[ORM\UniqueConstraint(name: 'warehouse_tenant_code_live', columns: ['tenant_id', 'code'], options: ['where' => '(deleted_at IS NULL)'])]
class Warehouse
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(name: 'tenant_id', type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\Column(type: Types::TEXT)]
    private string $code;

    #[ORM\Column(type: Types::TEXT)]
    private string $name;

    #[ORM\Column(name: 'address_line', type: Types::TEXT, options: ['default' => ''])]
    private string $addressLine = '';

    #[ORM\Column(type: Types::TEXT, options: ['default' => ''])]
    private string $city = '';

    #[ORM\Column(name: 'postal_code', type: Types::TEXT, options: ['default' => ''])]
    private string $postalCode = '';

    // Reference data (ADR-002 §6): no tenant_id, no RLS, and never the tenant
    // side of a composite key, so a plain single-column association is right.
    #[ORM\ManyToOne(targetEntity: Country::class)]
    #[ORM\JoinColumn(name: 'country_code', referencedColumnName: 'code', nullable: false)]
    private Country $country;

    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'deleted_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct(Uuid $id, Uuid $tenantId, string $code, string $name, Country $country)
    {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->code = $code;
        $this->name = $name;
        $this->country = $country;
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

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCountry(): Country
    {
        return $this->country;
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

<?php

declare(strict_types=1);

namespace App\Modules\Customers\Infrastructure\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Modules\Customers. A person at an account.
 *
 * One tenant-scoped parent, mapped as a single-column association on
 * `customer_id`; the composite foreign key
 *
 *   FOREIGN KEY (tenant_id, customer_id) REFERENCES customer (tenant_id, id)
 *
 * is written by hand in Version20260906000100 and deliberately not mapped
 * (A1.6). The database enforces the tenant agreement whatever the ORM believes.
 *
 * No `deleted_at`: a contact is never referenced by a document, so removing one
 * strands nothing and there is no history to preserve. Uniqueness is therefore
 * total everywhere here, and no partial index is needed.
 */
#[ORM\Entity]
#[ORM\Table(name: 'customer_contact')]
#[ORM\UniqueConstraint(name: 'customer_contact_tenant_id_id_key', columns: ['tenant_id', 'id'])]
// "The people at this account" — this table is only ever reached from the
// customer, so the tenant-led composite is the only access path worth an index.
#[ORM\Index(name: 'customer_contact_tenant_customer_idx', columns: ['tenant_id', 'customer_id'])]
class CustomerContact
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** An ordinary mapped field (A1.6), not shared with the association below. */
    #[ORM\Column(name: 'tenant_id', type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id', nullable: false)]
    private Customer $customer;

    #[ORM\Column(type: Types::TEXT)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(name: 'is_primary', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isPrimary = false;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Uuid $id, Uuid $tenantId, Customer $customer, string $name)
    {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->customer = $customer;
        $this->name = $name;
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

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }
}

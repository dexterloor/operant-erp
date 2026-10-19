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
 * strands nothing and there is no history to preserve. Every uniqueness that is
 * about identity is therefore total here — the surrogate and the retry key.
 * **The one partial index is a business rule, not a live-row filter**
 * (customers slice 2 C0, `Version20261019000100`; owner ruling Q-1 (a)):
 * `customer_contact_one_primary` allows at most one `is_primary` contact per
 * customer. The writer keeps it unreachable by locking the customer and demoting
 * the old primary first; the index is the database's backstop.
 */
#[ORM\Entity]
#[ORM\Table(name: 'customer_contact')]
#[ORM\UniqueConstraint(name: 'customer_contact_tenant_id_id_key', columns: ['tenant_id', 'id'])]
// "The people at this account" — this table is only ever reached from the
// customer, so the tenant-led composite is the only access path worth an index.
#[ORM\Index(name: 'customer_contact_tenant_customer_idx', columns: ['tenant_id', 'customer_id'])]
// CreateCustomerContact's retry key (customers slice 2 C0, Version20261019000100): TOTAL, the table is class `hard`.
#[ORM\UniqueConstraint(name: 'customer_contact_idem_unique', columns: ['tenant_id', 'idempotency_key'])]
// One primary contact per customer (owner Q-1 (a)); see the class docblock.
#[ORM\UniqueConstraint(name: 'customer_contact_one_primary', columns: ['tenant_id', 'customer_id'], options: ['where' => 'is_primary'])]
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

    /**
     * The hex SHA-256 of the `Idempotency-Key` header (D-B), never the header. **Non-nullable, following
     * `CustomerAddress::$idempotencyKey`**: no contact predates it and one request writes one row
     * (`Version20261019000100`). Set once, in the `INSERT`, and there is no setter.
     */
    #[ORM\Column(name: 'idempotency_key', type: Types::TEXT)]
    private string $idempotencyKey;

    /** `RequestDigest::of()` of the request that created the row, the path's customer id included. Never read into the domain. */
    #[ORM\Column(name: 'request_digest', type: Types::TEXT)]
    private string $requestDigest;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Uuid $id,
        Uuid $tenantId,
        Customer $customer,
        string $name,
        string $idempotencyKey,
        string $requestDigest,
    ) {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->customer = $customer;
        $this->name = $name;
        $this->idempotencyKey = $idempotencyKey;
        $this->requestDigest = $requestDigest;
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

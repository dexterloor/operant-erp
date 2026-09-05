<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Modules\Customers. **Hard-deleted on purpose** — the one table on the sales
 * side that carries no `deleted_at`, and the reason is a deliberate design
 * decision rather than an oversight.
 *
 * `sales_order` does not point at an address row. It carries `ship_to_line`,
 * `ship_to_city`, `ship_to_postal_code` and `ship_to_country_code` as
 * **snapshot columns**, so an order records where it actually shipped. An order
 * pointing at a live address row would silently rewrite the history of a past
 * shipment every time the customer moves.
 *
 * Because nothing references an address, nothing is stranded when one is
 * removed, so a soft delete would buy nothing and would cost a partial index on
 * every uniqueness rule (ADR-007 §1). Removing the row is the correct act.
 *
 * One tenant-scoped parent, mapped as a single-column association on
 * `customer_id`; the composite key
 *
 *   FOREIGN KEY (tenant_id, customer_id) REFERENCES customer (tenant_id, id)
 *
 * is hand-written in Version20260906000100 and not mapped (A1.6).
 */
#[ORM\Entity]
#[ORM\Table(name: 'customer_address')]
#[ORM\UniqueConstraint(name: 'customer_address_tenant_id_id_key', columns: ['tenant_id', 'id'])]
// "The shipping addresses for this account" — the picker on the order form,
// which filters by kind and never wants the billing rows.
#[ORM\Index(name: 'customer_address_tenant_customer_idx', columns: ['tenant_id', 'customer_id', 'kind'])]
class CustomerAddress
{
    public const KIND_BILLING = 'billing';
    public const KIND_SHIPPING = 'shipping';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** An ordinary mapped field (A1.6), not shared with the association below. */
    #[ORM\Column(name: 'tenant_id', type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id', nullable: false)]
    private Customer $customer;

    /** CHECK-constrained to 'billing' or 'shipping' in the migration. */
    #[ORM\Column(type: Types::TEXT)]
    private string $kind;

    #[ORM\Column(name: 'address_line', type: Types::TEXT)]
    private string $addressLine;

    #[ORM\Column(type: Types::TEXT)]
    private string $city;

    #[ORM\Column(type: Types::TEXT, options: ['default' => ''])]
    private string $region = '';

    #[ORM\Column(name: 'postal_code', type: Types::TEXT)]
    private string $postalCode;

    // Reference data (ADR-002 §6): no tenant_id, no RLS, and never the tenant
    // side of a composite key, so a plain single-column association is right.
    #[ORM\ManyToOne(targetEntity: Country::class)]
    #[ORM\JoinColumn(name: 'country_code', referencedColumnName: 'code', nullable: false)]
    private Country $country;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Uuid $id,
        Uuid $tenantId,
        Customer $customer,
        string $kind,
        string $addressLine,
        string $city,
        string $postalCode,
        Country $country,
    ) {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->customer = $customer;
        $this->kind = $kind;
        $this->addressLine = $addressLine;
        $this->city = $city;
        $this->postalCode = $postalCode;
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

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getAddressLine(): string
    {
        return $this->addressLine;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function getRegion(): string
    {
        return $this->region;
    }

    public function getPostalCode(): string
    {
        return $this->postalCode;
    }

    public function getCountry(): Country
    {
        return $this->country;
    }
}

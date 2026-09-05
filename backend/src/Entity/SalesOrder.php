<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Modules\Sales. PurchaseOrder's mirror on the sell side, and the second
 * entity with **three** tenant-scoped parents — customer, warehouse and the
 * ordering user — each a single-column association whose composite foreign key
 * is written by hand in Version20260906000100:
 *
 *   FOREIGN KEY (tenant_id, customer_id)        REFERENCES customer  (tenant_id, id)
 *   FOREIGN KEY (tenant_id, warehouse_id)       REFERENCES warehouse (tenant_id, id)
 *   FOREIGN KEY (tenant_id, created_by_user_id) REFERENCES app_user  (tenant_id, id)
 *
 * All three share the single `tenant_id` column, which is precisely what the
 * rejected composite-identifier mapping could not express: three associations
 * cannot each own the same column. Under this shape none of them owns it — the
 * field does — and Postgres enforces the tenant agreement regardless of what
 * the ORM believes (A1.6).
 *
 * ---------------------------------------------------------------------------
 * Why `ship_to_*` are snapshot columns and not a customer_address_id
 * ---------------------------------------------------------------------------
 * `customer_address` is hard-deletable exactly because these four columns
 * exist. An order that pointed at a live address row would silently rewrite the
 * history of a past shipment every time the customer moves — the shipment that
 * went to the old warehouse district would, a year later, claim it went to the
 * new head office. Copying the address at order time makes the order a record
 * of what happened rather than a view of what is currently true.
 *
 * `ship_to_country_code` is the one exception, and it is not an exception to
 * that rule: it is a plain reference-data association to `Country` (ADR-002 §6,
 * immutable catalogue), not a link back to the address row.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sales_order')]
#[ORM\UniqueConstraint(name: 'sales_order_tenant_id_id_key', columns: ['tenant_id', 'id'])]
// ADR-007 §1: business uniqueness goes partial the moment the row can be
// soft-deleted. UNIQUE (tenant_id, id) above stays total, because a composite
// FK cannot reference a partial index and children reference deleted parents.
#[ORM\UniqueConstraint(name: 'sales_order_tenant_number_live', columns: ['tenant_id', 'order_number'], options: ['where' => '(deleted_at IS NULL)'])]
// "Orders waiting to ship" — the warehouse's work queue.
#[ORM\Index(name: 'sales_order_tenant_open_idx', columns: ['tenant_id', 'ordered_at'], options: ['where' => "((deleted_at IS NULL) AND (status = ANY (ARRAY['confirmed'::text, 'partially_fulfilled'::text])))"])]
// "This customer's order history" — the account screen.
#[ORM\Index(name: 'sales_order_tenant_customer_idx', columns: ['tenant_id', 'customer_id', 'ordered_at'], options: ['where' => '(deleted_at IS NULL)'])]
class SalesOrder
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_PARTIALLY_FULFILLED = 'partially_fulfilled';
    public const STATUS_FULFILLED = 'fulfilled';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /**
     * An ordinary mapped field. Never an #[ORM\Id], never a join column, and in
     * particular not shared with any of the three associations below — that is
     * the whole of A1.6.
     */
    #[ORM\Column(name: 'tenant_id', type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\Column(name: 'order_number', type: Types::TEXT)]
    private string $orderNumber;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id', nullable: false)]
    private Customer $customer;

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'warehouse_id', referencedColumnName: 'id', nullable: false)]
    private Warehouse $warehouse;

    /** Nullable: an order raised by an agent or an import has no human author. */
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

    // ---- The shipping snapshot. Copied at order time, never a foreign key. ----

    #[ORM\Column(name: 'ship_to_line', type: Types::TEXT, options: ['default' => ''])]
    private string $shipToLine = '';

    #[ORM\Column(name: 'ship_to_city', type: Types::TEXT, options: ['default' => ''])]
    private string $shipToCity = '';

    #[ORM\Column(name: 'ship_to_postal_code', type: Types::TEXT, options: ['default' => ''])]
    private string $shipToPostalCode = '';

    /**
     * Reference data (ADR-002 §6), not a link to the address row. A country
     * code is an immutable catalogue entry, so pointing at it cannot rewrite
     * where a past shipment went; the three text columns above are what carry
     * the mutable part of the address.
     */
    #[ORM\ManyToOne(targetEntity: Country::class)]
    #[ORM\JoinColumn(name: 'ship_to_country_code', referencedColumnName: 'code', nullable: false)]
    private Country $shipToCountry;

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
        Customer $customer,
        Warehouse $warehouse,
        Currency $currency,
        Country $shipToCountry,
    ) {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->orderNumber = $orderNumber;
        $this->customer = $customer;
        $this->warehouse = $warehouse;
        $this->currency = $currency;
        $this->shipToCountry = $shipToCountry;
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

    public function getCustomer(): Customer
    {
        return $this->customer;
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

    public function getShipToCountry(): Country
    {
        return $this->shipToCountry;
    }

    /**
     * Takes the address by value rather than by reference. The caller reads a
     * CustomerAddress and hands over its fields; nothing here holds on to the
     * row, so deleting it later cannot change what this order says.
     */
    public function shipTo(string $line, string $city, string $postalCode, Country $country): void
    {
        $this->shipToLine = $line;
        $this->shipToCity = $city;
        $this->shipToPostalCode = $postalCode;
        $this->shipToCountry = $country;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function confirm(\DateTimeImmutable $orderedAt): void
    {
        $this->status = self::STATUS_CONFIRMED;
        $this->orderedAt = $orderedAt;
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

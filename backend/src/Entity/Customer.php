<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Modules\Customers. The tenant-scoped parent of the sales side.
 *
 * Soft-deleted, for the same reason `supplier` is: sales orders, invoices and
 * contacts name a customer and must keep naming it after the account is closed.
 * That forces the two kinds of uniqueness apart:
 *
 *   customer_tenant_id_id_key   TOTAL. ADR-002 §5's composite foreign key
 *                               target. A composite FK cannot reference a
 *                               partial index, and a child legitimately
 *                               references a soft-deleted parent.
 *   customer_tenant_code_live   PARTIAL (ADR-007 §1). Without the predicate,
 *                               closing an account and re-opening it with the
 *                               same code collides with a row the caller
 *                               cannot see.
 *
 * `customer_tenant_name_live` is a plain index, not a unique one: two customers
 * may share a name, and the index exists only so the module's landing screen
 * sorts alphabetically without a sort node.
 *
 * Every constraint and index the migration creates is repeated here on purpose.
 * Anything the mapping does not know about, SchemaTool proposes to drop, which
 * would bury the one divergence that matters under a pile of noise.
 */
#[ORM\Entity]
#[ORM\Table(name: 'customer')]
#[ORM\UniqueConstraint(name: 'customer_tenant_id_id_key', columns: ['tenant_id', 'id'])]
#[ORM\UniqueConstraint(name: 'customer_tenant_code_live', columns: ['tenant_id', 'code'], options: ['where' => '(deleted_at IS NULL)'])]
#[ORM\Index(name: 'customer_tenant_name_live', columns: ['tenant_id', 'name'], options: ['where' => '(deleted_at IS NULL)'])]
class Customer
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /**
     * An ordinary mapped field (A1.6). Never an #[ORM\Id], never a join column,
     * never shared with an association.
     */
    #[ORM\Column(name: 'tenant_id', type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\Column(type: Types::TEXT)]
    private string $code;

    #[ORM\Column(type: Types::TEXT)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $phone = null;

    /** S-12: money is numeric(19,4) and a string in PHP. Never a float. */
    #[ORM\Column(name: 'credit_limit', type: Types::DECIMAL, precision: 19, scale: 4, options: ['default' => '0'])]
    private string $creditLimit = '0';

    // Reference data (ADR-002 §6): no tenant_id, no RLS, and never the tenant
    // side of a composite key, so a plain single-column association is right.
    #[ORM\ManyToOne(targetEntity: Currency::class)]
    #[ORM\JoinColumn(name: 'currency_code', referencedColumnName: 'code', nullable: false)]
    private Currency $currency;

    #[ORM\Column(name: 'payment_terms_days', type: Types::INTEGER, options: ['default' => 30])]
    private int $paymentTermsDays = 30;

    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'deleted_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct(Uuid $id, Uuid $tenantId, string $code, string $name, Currency $currency)
    {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->code = $code;
        $this->name = $name;
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

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCurrency(): Currency
    {
        return $this->currency;
    }

    public function getCreditLimit(): string
    {
        return $this->creditLimit;
    }

    public function getPaymentTermsDays(): int
    {
        return $this->paymentTermsDays;
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

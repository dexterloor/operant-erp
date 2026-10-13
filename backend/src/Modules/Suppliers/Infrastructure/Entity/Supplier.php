<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Infrastructure\Entity;

use App\SharedKernel\Reference\Country;
use App\SharedKernel\Reference\Currency;
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
// ADR-007 §1: business uniqueness goes partial the moment the row can be
// soft-deleted, or "remove a supplier, re-add it with the same code" collides
// with a row the caller cannot see. UNIQUE (tenant_id, id) above stays total.
#[ORM\UniqueConstraint(name: 'supplier_tenant_name_live', columns: ['tenant_id', 'name'], options: ['where' => '(deleted_at IS NULL)'])]
#[ORM\UniqueConstraint(name: 'supplier_tenant_code_live', columns: ['tenant_id', 'code'], options: ['where' => '(deleted_at IS NULL)'])]
// C5 C2a (Version20261013000500): the /suppliers Archived view's index, IA §5.7 D-a, with `id` for the keyset tie.
#[ORM\Index(name: 'supplier_tenant_archived_idx', columns: ['tenant_id', 'deleted_at', 'id'], options: ['where' => '(deleted_at IS NOT NULL)'])]
class Supplier
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(name: 'tenant_id', type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\Column(type: Types::TEXT)]
    private string $name;

    #[ORM\Column(type: Types::TEXT)]
    private string $code;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(name: 'payment_terms_days', type: Types::INTEGER, options: ['default' => 30])]
    private int $paymentTermsDays = 30;

    #[ORM\ManyToOne(targetEntity: Currency::class)]
    #[ORM\JoinColumn(name: 'currency_code', referencedColumnName: 'code', nullable: false)]
    private Currency $currency;

    // `supplier.country_code` (suppliers slice 1 C0, Version20261012000100): NOT NULL with no default, so a
    // supplier cannot exist without the country §4.2 has always accepted. Reference data, as `currency` above.
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

    public function __construct(Uuid $id, Uuid $tenantId, string $name, string $code, Country $country, Currency $currency)
    {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->name = $name;
        $this->code = $code;
        $this->country = $country;
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

    public function getName(): string
    {
        return $this->name;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getCountry(): Country
    {
        return $this->country;
    }

    public function getCurrency(): Currency
    {
        return $this->currency;
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

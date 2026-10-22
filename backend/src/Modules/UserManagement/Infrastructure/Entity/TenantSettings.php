<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Infrastructure\Entity;

use App\SharedKernel\Reference\Currency;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One row per workspace: how this tenant is configured, as opposed to who it
 * is — the registry (`Platform\Tenancy\Entity\Tenant`) answers the second.
 *
 * ---------------------------------------------------------------------------
 * Why it exists, which is not the Settings screen
 * ---------------------------------------------------------------------------
 * `app.inventory.default_currency_code: 'EUR'` was an **instance-wide container
 * parameter supplying a per-tenant business value**, autowired into
 * `ItemWriter`. Every tenant on the deployment was priced in euros by
 * construction, and nothing could catch it: a PHP constant never reaches the
 * database, so RLS, `TenantContext` and the composite-FK work are all beside
 * the point — it sits *above* the isolation boundary.
 * `Version20260906000100` had dropped `inventory_item.currency_code`'s default
 * so that a row could not be created *"with no stated currency and be silently
 * priced in euros"*, and a constant one layer up did exactly that. See
 * `Version20260910000100` and the ledger's E4-3.
 *
 * ---------------------------------------------------------------------------
 * Why it lives in this module, which was not the first answer
 * ---------------------------------------------------------------------------
 * `ARCHITECTURE.md` §4 names no owner for tenant-wide configuration.
 * `Platform\Tenancy` is the semantic fit — the tenant's settings beside the
 * tenant's registry row — and **§5.1 refuses it**: L0 may not depend on L1, so
 * a Platform entity cannot reference `SharedKernel\Reference\Currency`, and the
 * foreign key into the seeded currency list is worth more than the placement.
 * `IA.md` routes Administration → *Settings* here beside Users and Roles, and
 * `SECURITY_MODEL.md` E-15b already makes `tenant_settings.edit` this module's
 * business: it is the code that distinguishes `owner` from every other seat.
 * Flagged for `ARCH` rather than settled here — the placement follows from the
 * ruleset, not from the module map.
 *
 * ---------------------------------------------------------------------------
 * The mapping is A1.6's, and it is not a style choice
 * ---------------------------------------------------------------------------
 * Scalar `id`, `tenantId` as a **plain field** and never part of the identifier
 * or of a join, and the composite foreign-key target `UNIQUE (tenant_id, id)`
 * declared here so the mapping and the migration agree. A composite ORM
 * identifier `(tenant_id, id)` validates cleanly, emits correct DDL, and then
 * lets an association in another tenant silently override an explicitly-set
 * `tenantId` — measured, ADR-002 Amendment 1, and it cost this project a full
 * build-and-measure cycle. `InventoryItem`'s docblock carries the three
 * measured alternatives in full; they are not repeated here.
 *
 * `tenant_settings_tenant_key` — `UNIQUE (tenant_id)` — is the "one settings
 * row per workspace" rule and simultaneously satisfies R-15: its only column is
 * `tenant_id`, so it leads with it.
 *
 * There is no `deleted_at`. The deletion class is `none`: a settings row is
 * never removed while its tenant exists, because removing it would restore the
 * "no stated currency" state the whole table exists to make unreachable.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tenant_settings')]
#[ORM\UniqueConstraint(name: 'tenant_settings_tenant_id_id_key', columns: ['tenant_id', 'id'])]
#[ORM\UniqueConstraint(name: 'tenant_settings_tenant_key', columns: ['tenant_id'])]
class TenantSettings
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(name: 'tenant_id', type: UuidType::NAME)]
    private Uuid $tenantId;

    /**
     * The workspace's functional currency: the denomination of a standard cost
     * on an item, which belongs to the tenant rather than to the document.
     *
     * Per-document currency stays on the document — `purchase_order`,
     * `sales_order`, `invoice`, `customer`, `supplier` and `supplier_product`
     * each carry their own `currency_code`, and that is correct ERP practice.
     * `inventory_item` is the odd one out, which is why the fix is a tenant
     * setting and not a request field.
     *
     * Reference data, so a single-column association: `currency` has no
     * `tenant_id` (ADR-002 §6) and is never the tenant side of a composite key.
     * `nullable: false`, and no default anywhere — the value is stated or the
     * workspace is not configured.
     */
    #[ORM\ManyToOne(targetEntity: Currency::class)]
    #[ORM\JoinColumn(name: 'default_currency_code', referencedColumnName: 'code', nullable: false)]
    private Currency $defaultCurrency;

    /**
     * The workspace's display name (G7b, 2026-10-22; owner ruling RO-S1) — kept here, never in the registry's `tenant.name`,
     * which has no RLS and which no application principal writes. Backfilled from `btrim(tenant.name)` by
     * `Version20261022020000`; `NOT NULL`, no default, `tenant_settings_display_name_shape` (`role_name_shape`'s predicate).
     * Written only by `SettingsWriter` (raw SQL under `erp_app`'s column grant), never through this entity.
     */
    #[ORM\Column(name: 'display_name', type: Types::TEXT)]
    private string $displayName;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Uuid $id, Uuid $tenantId, Currency $defaultCurrency, string $displayName)
    {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->defaultCurrency = $defaultCurrency;
        $this->displayName = $displayName;
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

    public function getDefaultCurrency(): Currency
    {
        return $this->defaultCurrency;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }
}

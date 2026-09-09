<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Infrastructure\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Reference data (ADR-002 §6). No tenant_id, no RLS, and never the tenant side
 * of a composite foreign key.
 *
 * A permission is a code constant, not tenant data. Duplicating the catalogue
 * per tenant would let two tenants disagree about what `purchase_order.approve`
 * means, and an authorisation check that depends on whose row it read is not an
 * authorisation check. The tenant-specific half is RolePermission, which is
 * tenant-scoped and RLS'd; this table is the vocabulary it draws from.
 */
#[ORM\Entity]
#[ORM\Table(name: 'permission')]
class Permission
{
    #[ORM\Id]
    #[ORM\Column(type: Types::TEXT)]
    private string $code;

    /**
     * The owning module, in the shape `SchemaManifest` writes it on all 33 tables:
     * `Modules\Purchasing`, `Modules\Inventory`, `Platform\Tenancy`, `SharedKernel\Reference`.
     * Namespace-shaped and capitalised, never lowercase -- flattening it would discard the
     * `Platform\` vs `Modules\` distinction that A-10 and S-7 are written against.
     * PC-1a states the rule; the manifest is the domain it derives from.
     *
     * Not settled: a module that owns no table -- `Ai`, `Analytics` -- has no manifest value to
     * copy and no namespace on disk, so its form here is open (SEC-O-12, owner ARCH). That has
     * to be answered before this catalogue is seeded; after that it is a data migration.
     */
    #[ORM\Column(type: Types::TEXT)]
    private string $module;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    public function __construct(string $code, string $module, string $description)
    {
        $this->code = $code;
        $this->module = $module;
        $this->description = $description;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getModule(): string
    {
        return $this->module;
    }

    public function getDescription(): string
    {
        return $this->description;
    }
}

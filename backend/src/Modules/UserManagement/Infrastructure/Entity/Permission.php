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
     * Settled 2026-09-07 by ARCH, recorded in ARCHITECTURE.md section 4.0, and this
     * docblock asserted the opposite until 2026-09-10: a module that owns no table --
     * `Ai`, `Analytics` -- takes the same `App\\Modules\\<Name>` form as every other,
     * whether or not a namespace exists on disk yet. SEC-O-12 is closed.
     *
     * Retained rather than deleted, because the failure is worth more than the fact.
     * The ruling was made, the register recorded it closed, and this sentence went on
     * saying "open, owner ARCH" for three days inside committed code. The only gate
     * aimed at it sat at M1 -- after the waves that read this file -- so the
     * check existed and was pointed past the work it protected. It is now also gated
     * at M0-c exit, which is where the claim stops being true rather than where it
     * gets expensive.
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

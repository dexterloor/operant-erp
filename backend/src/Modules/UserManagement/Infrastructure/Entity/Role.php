<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Infrastructure\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Modules\UserManagement. A tenant-scoped parent, and the tenant-specific half
 * of authorisation: the permission catalogue is global reference data, the
 * bundle of permissions a tenant chooses to call "Warehouse Manager" is not.
 *
 * Soft-deleted, because an audit entry that says a grant was revoked has to be
 * able to name the role it revoked.
 *
 * `is_system` marks the roles provisioning creates. It is a flag rather than a
 * separate table so that a system role and a tenant role are the same thing to
 * every read path, and only the write path has to care.
 *
 * Two kinds of uniqueness, as everywhere else:
 *
 *   role_tenant_id_id_key  TOTAL. ADR-002 §5's composite foreign key target for
 *                          role_permission and user_role; a composite FK cannot
 *                          reference a partial index.
 *   role_tenant_name_live  PARTIAL (ADR-007 §1). Delete "Buyer" and create it
 *                          again, and without the predicate it collides with a
 *                          row the caller cannot see.
 */
#[ORM\Entity]
#[ORM\Table(name: 'role')]
#[ORM\UniqueConstraint(name: 'role_tenant_id_id_key', columns: ['tenant_id', 'id'])]
#[ORM\UniqueConstraint(name: 'role_tenant_name_live', columns: ['tenant_id', 'name'], options: ['where' => '(deleted_at IS NULL)'])]
#[ORM\UniqueConstraint(name: 'role_tenant_default_key_live', columns: ['tenant_id', 'default_key'], options: ['where' => '(deleted_at IS NULL)'])]
class Role
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** An ordinary mapped field -- ADR-002 Amendment 1 §A1.6. */
    #[ORM\Column(name: 'tenant_id', type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\Column(type: Types::TEXT)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, options: ['default' => ''])]
    private string $description = '';

    #[ORM\Column(name: 'is_system', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isSystem = false;

    /**
     * Which built-in role this is (`DefaultRoles::names()`), on a system role only (`role_default_key_only_on_system_roles`);
     * NULL on every other role. Written only by an owner process (the local demo seed, W-3): `erp_app` holds no column
     * grant on it, so the ORM never names it (`insertable: false, updatable: false`, `AppUser::$identityMac`'s precedent).
     */
    #[ORM\Column(name: 'default_key', type: Types::TEXT, nullable: true, insertable: false, updatable: false)]
    private ?string $defaultKey = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'deleted_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct(Uuid $id, Uuid $tenantId, string $name, bool $isSystem = false)
    {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->name = $name;
        $this->isSystem = $isSystem;
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

    public function getDescription(): string
    {
        return $this->description;
    }

    /** A system role is provisioning's, not the tenant's: the UI refuses to edit or delete it. */
    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    public function describe(string $description): void
    {
        $this->description = $description;
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

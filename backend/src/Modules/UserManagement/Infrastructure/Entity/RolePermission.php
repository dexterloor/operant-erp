<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Infrastructure\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Modules\UserManagement. The tenant-specific half of authorisation: which
 * global permission codes a tenant's role actually carries.
 *
 * Two parents of two different kinds, and the difference is the whole point:
 *
 *   role        tenant-scoped. A single-column ORM association on `role_id`;
 *               the composite FOREIGN KEY (tenant_id, role_id) REFERENCES
 *               role (tenant_id, id) is written by hand in
 *               Version20260906000100 and deliberately not mapped
 *               (ADR-002 Amendment 1 §A1.6).
 *   permission  reference data (ADR-002 §6). No tenant_id and no RLS, so a
 *               plain single-column association on the `permission_code` string
 *               key is the whole story -- there is no tenant agreement for
 *               Postgres to enforce.
 *
 * `tenantId` is an ordinary mapped field, not an #[ORM\Id] and not shared with
 * the role association. Under the rejected composite-identifier mapping the
 * association would have owned `tenant_id`, and a null role would have written
 * a null tenant.
 *
 * Not soft-deleted: a revoked grant is a deleted row and the audit log is what
 * remembers it. Keeping revoked grants alive would mean every permission check
 * has to filter them, which is one forgotten predicate away from a privilege
 * escalation.
 */
#[ORM\Entity]
#[ORM\Table(name: 'role_permission')]
// Declared here as well as in the migration on purpose: anything the mapping
// does not know about, SchemaTool proposes to drop.
#[ORM\UniqueConstraint(name: 'role_permission_tenant_id_id_key', columns: ['tenant_id', 'id'])]
// Total, not partial: the row cannot be soft-deleted, so ADR-007 §1 does not
// apply and a grant may be held exactly once.
#[ORM\UniqueConstraint(name: 'role_permission_unique', columns: ['tenant_id', 'role_id', 'permission_code'])]
class RolePermission
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** An ordinary mapped field -- ADR-002 Amendment 1 §A1.6. */
    #[ORM\Column(name: 'tenant_id', type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(name: 'role_id', referencedColumnName: 'id', nullable: false)]
    private Role $role;

    #[ORM\ManyToOne(targetEntity: Permission::class)]
    #[ORM\JoinColumn(name: 'permission_code', referencedColumnName: 'code', nullable: false)]
    private Permission $permission;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Uuid $id, Uuid $tenantId, Role $role, Permission $permission)
    {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->role = $role;
        $this->permission = $permission;
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

    public function getRole(): Role
    {
        return $this->role;
    }

    public function getPermission(): Permission
    {
        return $this->permission;
    }
}

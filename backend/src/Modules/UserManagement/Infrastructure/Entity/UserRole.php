<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Infrastructure\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Modules\UserManagement. Which roles a user holds.
 *
 * **This is the shape that disqualified the composite-identifier mapping.** A
 * join entity with *two* tenant-scoped parents -- app_user and role -- both of
 * which want FOREIGN KEY (tenant_id, X) REFERENCES parent (tenant_id, id), and
 * both of which would therefore have to own the same `tenant_id` column. Two
 * associations cannot own one column, so under the rejected mapping this table
 * was simply not expressible; and where it was expressible, with one parent, a
 * null parent nulled the tenant (ADR-002 Amendment 1 §A1.6). W0-a never
 * exercised it, because its only other composite key sat on an unmapped table.
 *
 * Under the accepted shape nobody owns `tenant_id` but the field:
 *
 *   FOREIGN KEY (tenant_id, user_id) REFERENCES app_user (tenant_id, id)
 *   FOREIGN KEY (tenant_id, role_id) REFERENCES role     (tenant_id, id)
 *
 * Both are written by hand in Version20260906000100 and neither is mapped.
 * Postgres enforces that the user, the role and the grant belong to the same
 * tenant whatever the ORM believes, which is the property that matters: a
 * cross-tenant role grant is a privilege escalation, not a data-quality issue.
 *
 * Not soft-deleted -- see RolePermission for why a revoked grant is a deleted
 * row.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_role')]
#[ORM\UniqueConstraint(name: 'user_role_tenant_id_id_key', columns: ['tenant_id', 'id'])]
#[ORM\UniqueConstraint(name: 'user_role_unique', columns: ['tenant_id', 'user_id', 'role_id'])]
// "Who holds this role" -- the access review, and the reverse of the unique
// index's prefix, which only serves "what does this user hold".
#[ORM\Index(name: 'user_role_tenant_role_idx', columns: ['tenant_id', 'role_id'])]
class UserRole
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /**
     * An ordinary mapped field, shared with neither association below. That is
     * the entire content of ADR-002 Amendment 1 §A1.6, and this class is where
     * it is load-bearing.
     */
    #[ORM\Column(name: 'tenant_id', type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private AppUser $user;

    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(name: 'role_id', referencedColumnName: 'id', nullable: false)]
    private Role $role;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Uuid $id, Uuid $tenantId, AppUser $user, Role $role)
    {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->user = $user;
        $this->role = $role;
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

    public function getUser(): AppUser
    {
        return $this->user;
    }

    public function getRole(): Role
    {
        return $this->role;
    }
}

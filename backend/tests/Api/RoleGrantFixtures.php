<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use Symfony\Component\Uid\Uuid;

/**
 * Roles slice 3's shared fixtures (C1): roles seeded as the owner connection — DEFAULT roles as system roles carrying
 * their catalogue codes, composed roles as `is_system = false` — and proposals seeded by owner INSERT (never by UPDATE: a
 * decided row is final, `role_grant_proposal_decided_is_final`, `DATA` §3.5). `RoleAssignTest` keeps its own copies so
 * its bytes do not move (design §14 C1).
 *
 * @phpstan-require-extends ApiTestCase
 */
trait RoleGrantFixtures
{
    /** @param list<string> $codes */
    private function composedRole(array $codes): string
    {
        $role = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, ?)',
            [$role, self::TENANT_A, 'Composed '.substr($role, 0, 8)]);
        foreach ($codes as $code) {
            $this->insertAsOwnerForTenant(self::TENANT_A,
                'INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?)',
                [self::TENANT_A, $role, $code]);
        }

        return $role;
    }

    /** A system role holding `$name`'s default codes, named `<name> <id prefix>`. */
    private function systemRole(string $name): string
    {
        $role = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO role (id, tenant_id, name, is_system) VALUES (?::uuid, ?::uuid, ?, true)',
            [$role, self::TENANT_A, $name.' '.substr($role, 0, 8)]);
        foreach (DefaultRoles::codesFor($name) as $code) {
            $this->insertAsOwnerForTenant(self::TENANT_A,
                'INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?)',
                [self::TENANT_A, $role, $code]);
        }

        return $role;
    }

    /**
     * A fresh user holding exactly `$roles` (seeded as the owner).
     *
     * @param list<string> $roles
     */
    private function holderOf(array $roles): string
    {
        $user = $this->seedPrincipal(self::TENANT_A, []);
        foreach ($roles as $role) {
            $this->insertAsOwnerForTenant(self::TENANT_A,
                'INSERT INTO user_role (id, tenant_id, user_id, role_id) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?::uuid)',
                [self::TENANT_A, $user, $role]);
        }

        return $user;
    }

    /** A fresh user of `TENANT_A` holding one composed role with `$role`'s default codes. */
    private function principal(string $role): string
    {
        return $this->seedPrincipal(self::TENANT_A, DefaultRoles::codesFor($role));
    }

    private function propose(string $userId, string $roleId): void
    {
        $this->send('POST', '/users/'.$userId.'/role-grant-proposals', ['role_id' => $roleId], $this->key());
    }

    /** @return array<string, string> */
    private function key(): array
    {
        return [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()];
    }

    /**
     * A PENDING proposal seeded by owner INSERT, `$ageHours` old (`proposed_at = clock_timestamp() - age`; the window
     * CHECK holds `expires_at` at + 336 hours). Its snapshot is the role's live codes, sorted.
     */
    private function seedPendingProposal(string $userId, string $roleId, string $proposerId, int $ageHours = 0): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO role_grant_proposal (id, tenant_id, user_id, role_id, permission_codes, proposed_by_user_id, proposed_at, expires_at)
            SELECT ?::uuid, ?::uuid, ?::uuid, ?::uuid,
                   COALESCE((SELECT jsonb_agg(rp.permission_code ORDER BY rp.permission_code) FROM role_permission rp WHERE rp.role_id = ?::uuid), '["role.view"]'::jsonb),
                   ?::uuid, c.t, c.t + interval '336 hours'
              FROM (SELECT clock_timestamp() - make_interval(hours => ?) AS t) c
            SQL, [$id, self::TENANT_A, $userId, $roleId, $roleId, $proposerId, $ageHours]);

        return $id;
    }

    /** @return list<array<string, mixed>> */
    private function proposalRows(): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT id::text AS id, user_id::text AS user_id, role_id::text AS role_id, status, lapse_reason FROM role_grant_proposal ORDER BY proposed_at, id');
    }
}

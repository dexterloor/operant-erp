<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Infrastructure\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Modules\UserManagement. A tenant-scoped parent: purchase orders, audit
 * entries and role grants all name a user, so the row is soft-deleted rather
 * than removed -- history that loses its actor is not history.
 *
 * `email` is stored already lower-cased and a CHECK (`email = lower(email)`)
 * holds it that way. ADR-006 §6.4(a) is the reason: the rate-limit key and the
 * lookup key have to be the same string, or `ACME@example.com` buys a fresh
 * limiter bucket for `acme@example.com` and the throttle is bypassed by the
 * shift key. Normalising once at the edge only works if the store refuses
 * anything else, so the shape is pinned in the database, not in a setter.
 *
 * **There is deliberately no lockout counter on this class, and none in the
 * table.** ARCHITECTURE S-14 / ADR-006 §6.6: a failed-attempt column here would
 * have to be UPDATEd before authentication succeeds, which hands an
 * unauthenticated attacker a write against a tenant-owned row and lets them
 * lock any real user out by guessing their address. Lockout state lives in the
 * non-tenant `auth_lockout` table, keyed by the normalised identifier, where
 * the pre-auth write touches nothing a tenant owns.
 *
 * Two kinds of uniqueness, and they are not interchangeable:
 *
 *   app_user_tenant_id_id_key   TOTAL. ADR-002 §5's composite foreign key
 *                               target. A composite FK cannot reference a
 *                               partial index, and a purchase order
 *                               legitimately still names a deleted author.
 *   app_user_tenant_email_live  PARTIAL. ADR-006 §7. Without the predicate a
 *                               deactivated address could never be reissued,
 *                               and the collision would be reported against a
 *                               row the caller is not allowed to see.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'app_user_tenant_id_id_key', columns: ['tenant_id', 'id'])]
#[ORM\UniqueConstraint(name: 'app_user_tenant_email_live', columns: ['tenant_id', 'email'], options: ['where' => '(deleted_at IS NULL)'])]
class AppUser
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /**
     * An ordinary mapped field. Never an #[ORM\Id], never a join column, and
     * never shared with an association -- ADR-002 Amendment 1 §A1.6.
     */
    #[ORM\Column(name: 'tenant_id', type: UuidType::NAME)]
    private Uuid $tenantId;

    /** Lower-case by CHECK constraint. See the class docblock: ADR-006 §6.4(a). */
    #[ORM\Column(type: Types::TEXT)]
    private string $email;

    #[ORM\Column(name: 'password_hash', type: Types::TEXT)]
    private string $passwordHash;

    #[ORM\Column(name: 'display_name', type: Types::TEXT)]
    private string $displayName;

    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'last_login_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'deleted_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct(
        Uuid $id,
        Uuid $tenantId,
        string $email,
        string $passwordHash,
        string $displayName,
    ) {
        $this->id = $id;
        $this->tenantId = $tenantId;
        // Normalised on the way in as well as held by the CHECK: the constraint
        // is the guarantee, this is the cheap path that stops it firing.
        $this->email = mb_strtolower($email);
        $this->passwordHash = $passwordHash;
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

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    /**
     * The single writer of `password_hash` after construction. **It has one
     * caller: `ChangePassword` (E-23)** — the password changes, and the change is
     * audited. The method replaces the stored digest and moves `updated_at`.
     *
     * **Rehash on login (ADR-006) is NOT built.** This docblock named it as a
     * first caller until 2026-09-22, and that was a sentence without a
     * mechanism: `VerifiesPasswords::needsRehash()` (`PasswordVerifier.php`)
     * has no caller in `src/`, and `SignIn` never writes a hash. The method
     * would serve a rehash unchanged — the hash changes and the password does
     * not — but nothing does one today, so do not cite this method as evidence
     * that stored hashes are upgraded when the KDF's cost moves.
     *
     * **What it deliberately does not do**, so no caller has to guess: it does
     * not hash (the KDF runs outside every transaction — ADR-006 §6.1, O-1's
     * 1505 ms of pooled-backend pinning), it does not audit (E-23's row is
     * `PasswordChangeAuditWriter`'s, written inside the caller's transaction),
     * and it revokes nothing (there is no session store — **API-O-13** — so a
     * live token outlives this call for up to E-20's eight hours).
     *
     * The class-level rule holds: **nothing on this class may be written before
     * authentication has succeeded.** A change happens behind
     * `AuthenticationGate` and costs the current password as well; a rehash, if
     * one is ever built, must run only after the credential has verified.
     */
    public function replacePasswordHash(string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Written only after authentication has already succeeded. Nothing on this
     * class may be written before that -- see the class docblock.
     */
    public function recordLogin(\DateTimeImmutable $at): void
    {
        $this->lastLoginAt = $at;
        $this->updatedAt = $at;
    }

    /**
     * No caller yet (E-15g). `erp_app` holds no UPDATE on `is_active` or `deleted_at` (H0,
     * `Version20260922000300`), so the first caller of this or `delete()` is a `42501` until it grants
     * `UPDATE (is_active, deleted_at)` in its own migration (ADR-002 A4.4 duty 1) — never table-level UPDATE.
     */
    public function deactivate(): void
    {
        $this->isActive = false;
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

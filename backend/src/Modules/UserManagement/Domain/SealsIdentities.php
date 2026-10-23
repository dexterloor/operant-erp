<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Domain;

/**
 * **The identity seal and the reset-token verifier MAC** (Mail-C1, `docs/session13/mail-c1-build-plan.md` Revision 1 FINAL
 * R1.4; design D-3, owed from Cred-C1). The mail worker depends on this port, never on the credential half
 * ({@see SealsCredentials}), so the class that may decide where a reset link goes cannot seal a password.
 *
 * - **`identity_mac`** binds `(tenant_id, id, email)` under `k_ident`: SQL as `erp_app` (or as the owner) that rewrites a
 *   sealed user's `email` cannot recompute it, so the worker refuses to mail the new address (`Mismatch`). It never binds
 *   the password.
 * - **`verifier_mac`** binds `(tenant_id, user_id, purpose, selector, expires_at, verifier)` under `k_tok`: a token row
 *   written without the key does not verify at redeem (Mail-C2).
 *
 * Each is `v1.<kid8>.<64 hex>`, the same shape and the same key id as the credential seal: one root key, three HKDF
 * purposes, and a seal under one purpose never verifies under another (`CredentialSealTest`'s cross-purpose vector).
 */
interface SealsIdentities
{
    /** @return string `v1.<kid8>.<64 hex>`, the shape `app_user_identity_mac_shape` admits */
    public function sealIdentity(string $tenantId, string $userId, string $email): string;

    /** Fail-closed like {@see SealsCredentials::check()}: NULL, empty or malformed is a mismatch; a foreign key id is told apart. */
    public function checkIdentity(string $tenantId, string $userId, string $email, ?string $stored): SealCheck;

    /** @return string `v1.<kid8>.<64 hex>`, the shape `password_reset_token_verifier_mac_shape` admits */
    public function macVerifier(string $tenantId, string $userId, string $purpose, string $selector, int $expiresAtUnixSeconds, string $verifierHex): string;

    /** Mail-C2's redeem check, built at Mail-C1 so the encoding is pinned by the commit that first writes it. */
    public function verifierMatches(string $tenantId, string $userId, string $purpose, string $selector, int $expiresAtUnixSeconds, string $verifierHex, string $stored): bool;

    /** The configured key's id (8 hex characters). Not a secret. */
    public function keyId(): string;
}

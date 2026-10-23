<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Domain;

/**
 * The credential-seal port (Cred-C1; `RESIDUAL_GAPS.md` G-12's closing work `(e)-cred`).
 *
 * A `Domain` interface for the reason {@see VerifiesPasswords} is one: A-7 forbids `Application` from naming an
 * `Infrastructure` class, and `SignIn` and `ChangePassword` both need the seal. It is also the counting seam
 * (`App\Tests\Support\CountingSeal`, `when@test` only), so `API.md` §7.3's "the MAC is computed on every path" can be
 * asserted rather than read.
 *
 * **The seal binds `(tenant_id, id, password_hash)` with a key `erp_app` does not hold.** SQL running as `erp_app` can
 * still write `password_hash` (ChangePassword needs that grant) and `password_mac` (so does the reset), but it cannot
 * compute a seal, so a copied or transplanted hash fails at sign-in. It never binds `email`.
 */
interface SealsCredentials
{
    /**
     * The tuple a miss path seals against, so the HMAC runs on every sign-in path (E-7's constant cost; `API.md` §7.3),
     * exactly as the KDF runs against a dummy hash. Fixed and public: no row carries these ids, so it verifies nothing.
     */
    public const DUMMY_TENANT_ID = '00000000-0000-4000-8000-00000000c0de';

    public const DUMMY_USER_ID = '00000000-0000-4000-8000-0000000c0de1';

    public const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$ZHVtbXlkdW1teQ$ZHVtbXlkdW1teWR1bW15ZHVtbXlkdW1teWR1bW15';

    /** The `metadata` flag an `auth.login.credential_mismatch` row carries when the stored key id is not the configured one. */
    public const UNKNOWN_KEY_ID = 'unknown_key_id';

    /** @return string `v1.<kid8>.<64 hex>`, the shape `app_user_password_mac_shape` admits */
    public function seal(string $tenantId, string $userId, string $passwordHash): string;

    /**
     * Fail-closed: NULL, an empty string or any malformed value is {@see SealCheck::Mismatch}, never a skip. The MAC is
     * computed on every call, whatever `$stored` holds, so a caller that runs this on a miss path pays the same cost as
     * on a hit.
     */
    public function check(string $tenantId, string $userId, string $passwordHash, ?string $stored): SealCheck;

    /** The configured key's id (8 hex characters). Not a secret: an HMAC of a constant under the key. */
    public function keyId(): string;
}

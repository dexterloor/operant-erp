<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Domain;

/**
 * The three answers a credential seal can give (Cred-C1, `docs/session13/cred-mail-user-design.md` R1.3; final RED M-1).
 *
 * Only {@see self::Verified} lets a credential through. The other two both refuse; they differ only in what the trail
 * records when the password itself was right: {@see self::UnknownKey} adds `unknown_key_id` to the
 * `auth.login.credential_mismatch` row, because a stored key id that is not the configured one is either a wrong key on
 * this server or an attacker's planted value, and `app:credentials:check-key` is what tells the two apart. Neither is
 * routed to an ordinary wrong-password row: that would let SQL as `erp_app` switch the tamper alarm off for free.
 */
enum SealCheck
{
    case Verified;

    /** NULL, or a well-formed seal under the current key that does not match this row. */
    case Mismatch;

    /** A well-formed seal whose key id is not the configured key's. */
    case UnknownKey;
}

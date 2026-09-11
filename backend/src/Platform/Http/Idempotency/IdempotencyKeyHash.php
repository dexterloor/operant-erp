<?php

declare(strict_types=1);

namespace App\Platform\Http\Idempotency;

/**
 * **D-B (ruled 2026-09-11) — a retry key is stored as the hex SHA-256 of the
 * `Idempotency-Key` header, never as the header's value.**
 *
 * API-O-32 found that `erp_ai` reads `purchase_order.idempotency_key` by
 * table-level inheritance, and the obvious fix — a column-level `REVOKE` — was
 * measured to do nothing under a table-level grant. So the column is made
 * harmless to read instead: what it holds lets a replay be *recognised* (the
 * lookup hashes the incoming header and compares) and does not hand anybody a
 * key they could send.
 *
 * The output is 64 characters, which fits `IdempotencyKeyGuard::COLUMN`'s
 * existing `text` + `octet_length BETWEEN 1 AND 255` column unchanged; the
 * column name stays, so `IdempotencyContractTest`'s header-source clauses keep
 * their subject.
 *
 * ---------------------------------------------------------------------------
 * The bounds are re-checked here, because hashing erased the database's copy
 * ---------------------------------------------------------------------------
 * Before D-B an empty key reaching the `INSERT` was refused by
 * `purchase_order_idempotency_key_bytes` (`octet_length BETWEEN 1 AND 255`).
 * `sha256('')` is a perfectly well-formed 64-character hex string, so after
 * hashing that CHECK can no longer see an empty key — and every keyless create
 * in a workspace would share one stored key and collide as "replays" of each
 * other. `IdempotencyKeyGuard` refuses both cases at the HTTP edge; this is the
 * copy for every caller that is not HTTP (the reorder agent mints its own key).
 *
 * ---------------------------------------------------------------------------
 * ⚠ The accepted residual: plain, unsalted SHA-256
 * ---------------------------------------------------------------------------
 * A guessable key stays guessable. Anyone who can read the column can hash
 * candidate keys offline — `"1"`, `"retry"`, a timestamp — and recognise one.
 * That was accepted when D-B was ruled: §6.2 tells clients to send an opaque
 * random value, a UUID's 122 random bits are not enumerable, RLS already bounds
 * any reader to its own workspace, and every `.create` holder in a workspace
 * also holds `.view`. It cannot be salted per row, because the lookup has to
 * find the row *from* the key; a keyed hash (HMAC with a server secret) would
 * close it and would need a secret and its rotation, which nobody ruled.
 */
final class IdempotencyKeyHash
{
    /** @return string lower-case hex SHA-256 of the header value */
    public static function of(string $key): string
    {
        if ('' === $key || \strlen($key) > IdempotencyKeyGuard::MAXIMUM_KEY_BYTES) {
            throw new \LogicException(\sprintf(
                'An Idempotency-Key must be 1 to %d bytes. IdempotencyKeyGuard refuses this at the HTTP edge; '
                .'a caller reaching here with one is a defect, not an input.',
                IdempotencyKeyGuard::MAXIMUM_KEY_BYTES,
            ));
        }

        return hash('sha256', $key);
    }
}

<?php

declare(strict_types=1);

namespace App\Platform\Http\Idempotency;

use App\Platform\Http\Rfc3339Timestamp;
use Symfony\Component\Uid\Uuid;

/**
 * **D-B (2026-09-11) — what a `unique:` operation compares a replay against, so
 * that the same key with a different body is `idempotency-key-conflict` (409).**
 *
 * §6.2: *"A replay with the same key and a **different** body digest is
 * `idempotency-key-conflict` (409)."* The `unique:` route shipped without that
 * clause — `PurchaseOrderWriter::create()` matched on the key alone, so a key
 * reused for a different order returned the first order as a 201 — and this is
 * the piece that closes it. It is Platform rather than Purchasing because it is
 * not a purchasing rule: step 8's `CreateGoodsReceipt` takes the same kind of
 * key on the same kind of column and will digest its request here too.
 *
 * ===========================================================================
 * What is digested: the deserialised input, normalised — never the raw bytes
 * ===========================================================================
 * A digest of the request body's bytes would call two identical requests a
 * conflict because one client wrote `Z` and its retry library wrote `+00:00`,
 * or because a proxy re-serialised the JSON with its keys in another order. A
 * conflict answer for a genuine retry is the worst failure available here: the
 * client did exactly what P-5 tells it to do and is told it did something else.
 *
 * So the caller hands in **the values its input object holds**, each normalised
 * by one of the functions below, and this class fixes everything else:
 *
 *   - **the operation id is part of the digest**, so one request shape can never
 *     match another operation's row, whatever the two tables' columns look like;
 *   - **object keys are sorted** (byte order, recursively), so the order a caller
 *     builds its array in is not part of the digest and a refactor that reorders
 *     it cannot turn every stored row into a conflict;
 *   - **lists keep their order**, because for a list the order *is* the request —
 *     when §4.2's `lines[]` arrive, line 1 and line 2 swapped is a different
 *     order;
 *   - **nulls are explicit**: the caller passes every field the operation
 *     declares, present or not, so "absent" and "null" digest alike exactly when
 *     the input object cannot tell them apart;
 *   - **the encoding is JSON**, unescaped slashes and unicode, because it is
 *     unambiguous for the value types admitted and it is readable when somebody
 *     has to reproduce a digest by hand.
 *
 * **Only strings, ints, bools and null are admitted as leaves.** A float is
 * refused rather than encoded: money and quantities in this API are decimal
 * strings (S-12), and a float's JSON form is exactly the kind of cosmetic
 * difference this class exists to remove.
 *
 * ===========================================================================
 * Digest what was SENT, not what the write resolved it to
 * ===========================================================================
 * `CreatePurchaseOrder`'s `currency_code` falls back to the workspace default
 * when omitted, and that default can change between a timeout and its retry.
 * Digesting the resolved value would turn a genuine retry into a conflict the
 * day an administrator changed the setting. The caller passes the field as the
 * client sent it — null when omitted.
 *
 * And it is **not** a comparison against the row. `UpdatePurchaseOrder` can move
 * a draft's supplier, warehouse and `expected_at` between the first attempt and
 * a late retry, so a row comparison would false-409 a genuine retry of a request
 * that did succeed. The digest is stored at insert and never touched again.
 */
final class RequestDigest
{
    /** Lower-case hex SHA-256: 64 characters. */
    public const PATTERN = '/^[0-9a-f]{64}$/';

    /**
     * @param array<string, mixed> $fields every field the operation declares, each already normalised
     *
     * @return string lower-case hex SHA-256
     */
    public static function of(string $operationId, array $fields): string
    {
        return hash('sha256', json_encode(
            ['operation' => $operationId, 'fields' => self::canonical($fields)],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * RFC 4122, lower case. `A0B1…` and `a0b1…` name the same row, and Postgres's
     * `uuid` type agrees, so they must digest alike.
     *
     * Refuses anything that is not an RFC 4122 string — including the base58 and
     * base32 forms `Uuid::fromString()` would otherwise quietly accept — because
     * the caller is expected to have validated the field already, and a value
     * that reaches here unvalidated is a defect in the caller, not an input.
     */
    public static function uuid(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!Uuid::isValid($value)) {
            throw new \InvalidArgumentException('Not an RFC 4122 UUID; validate the field before digesting it.');
        }

        return Uuid::fromString($value)->toRfc4122();
    }

    /**
     * An RFC 3339 timestamp as a UTC instant in one fixed form,
     * `Y-m-d\TH:i:s.u\Z`. `…T00:00:00Z`, `…T00:00:00+00:00`,
     * `…T02:00:00+02:00` and `…T00:00:00.000Z` are one instant and digest alike.
     *
     * Parsed by `Rfc3339Timestamp`, the validator's own parser, so nothing the
     * validator lets through can fail here — and an impossible date is refused
     * rather than digested as the day it rolls over to.
     */
    public static function instant(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $parsed = Rfc3339Timestamp::parse($value)
            ?? throw new \InvalidArgumentException('Not an RFC 3339 timestamp; validate the field before digesting it.');

        return $parsed->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    private static function canonical(mixed $value): mixed
    {
        if (\is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value, \SORT_STRING);
            }

            return array_map(self::canonical(...), $value);
        }

        if (null === $value || \is_string($value) || \is_int($value) || \is_bool($value)) {
            return $value;
        }

        throw new \InvalidArgumentException(\sprintf(
            'A %s cannot be digested. Pass decimals as strings (S-12) and normalise everything else first.',
            get_debug_type($value),
        ));
    }
}

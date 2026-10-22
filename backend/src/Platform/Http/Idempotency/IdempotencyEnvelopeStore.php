<?php

declare(strict_types=1);

namespace App\Platform\Http\Idempotency;

use App\Platform\Http\Problem\ApiProblem;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * **The envelope replay store** — `API.md` §6.2's `store` mechanism, for the three bulk operations and nothing else
 * (remaining-ops G5-C0, 2026-10-22; authority `docs/session13/g5-g6-final-red.md` > `g5-g6-bulk-export-design.md`
 * Revision 1 FINAL; RO-B1 (a′)).
 *
 * **What it stores:** one row per `(tenant, operation, key)` — the key as `IdempotencyKeyHash::of(header)` (D-B: never the
 * header), the request digest, and the whole response envelope (status, body bytes, the normative headers). Only a **200**
 * is stored (D-8): a refusal has no side effect to protect, and storing it would freeze a 409 a later state no longer
 * justifies. The database holds the shape: the body is 2..1024 bytes, its digest is `sha256(response_body)`, the headers
 * are a closed object, and the key is lower-case hex SHA-256 (`Version20261022003000`).
 *
 * **The protocol the calling processor owns** (design C-1, final RED L-1/L-2), all inside ONE `TenantContext::run()`:
 *  1. {@see self::lookup()} — a replay, a refusal, or nothing;
 *  2. `SAVEPOINT bulk_work`, the member work (each member under its own savepoint), the audit rows;
 *  3. {@see self::record()} — `INSERT … ON CONFLICT (tenant_id, operation_id, key) DO NOTHING`, issued LAST;
 *  4. if it inserted nothing, a same-key request committed while this one waited at the unique: `ROLLBACK TO SAVEPOINT
 *     bulk_work`, then {@see self::replayTheWinner()} on the SAME connection (READ COMMITTED: the next statement sees the
 *     winner). Never a second `run()` from a `catch` (`NoRunAfterAFailedRunTest`): a 23505 would have closed the manager.
 *
 * **Every method takes the `Connection`** (final RED L-7): callers pass the run's `$em->getConnection()`, which is
 * `erp_app`'s. It is never autowired — an unbound `Connection` is the schema owner — so A-10 / `ConnectionBindingTest` see
 * no new binding; and C0's tests can drive `record()` on an owner connection while `erp_app` holds no INSERT (the column
 * grant ships with the first caller — G5-C2's `CreateBulkArchival`, `Version20261022050000`: ADR-002 A4.1). **The store cannot see which principal it was handed**
 * (deptrac A-10 deviation, registered 2026-10-22 — unlike `ItemWriter`, these are public methods taking a caller-supplied
 * connection), so it refuses what it can check instead: a connection with no open transaction, and a `record()` whose
 * tenant is not the session's; and the lookup names the session's tenant in SQL, so it holds even on a principal that
 * bypasses RLS (the Neon owner, ADR-002 C-1). A check that every caller passes the run's `$em->getConnection()` is owed
 * with the first caller — built at G5-C2: `EnvelopeStoreCallerConnectionTest` (every call passes the run's `$em->getConnection()`).
 *
 * **Expiry is refused at read, never overwritten, never purged** (RO-B1 (a′)): a key older than {@see self::WINDOW_HOURS}
 * is 422 `key_expired` and its row is left as it is. The age is compared in SQL on the database clock against a
 * database-clock `created_at`, which `erp_app` cannot set (it is outside the C1 column grant).
 */
final class IdempotencyEnvelopeStore
{
    /** The operations whose §4.2 `Idem` cell reads `store` — `IdempotencyContractTest` ties this list to the contract. */
    public const OPERATIONS = ['CreateBulkArchival', 'CreateBulkRestoration', 'CreateBulkRoleAssignment'];

    /** `DATA`'s figure, the low end of API-O-31's 24–72 h (D-6): a bulk retry is a client recovering within minutes. */
    public const WINDOW_HOURS = 24;

    /** The only status the store holds (D-8). */
    public const STORED_STATUS = 200;

    /** §6.2's normative headers, lower case — the `idempotency_key_headers_closed` CHECK's list. */
    public const NORMATIVE_HEADERS = ['content-type', 'location', 'content-disposition'];

    public const EXPIRED_CODE = 'key_expired';
    public const EXPIRED_MESSAGE = 'This retry key has expired. Send the request again with a new key.';
    public const CONFLICT_HINT = 'This key was used for a different request.';

    private const LOOKUP = <<<'SQL'
        SELECT (encode(request_digest, 'hex') = ?)::int AS same_request,
               response_status,
               encode(response_body, 'hex') AS body,
               response_headers::text AS headers,
               (created_at > now() - make_interval(hours => ?))::int AS live
          FROM idempotency_key
         WHERE tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid AND operation_id = ? AND key = ?
        SQL;

    private const RECORD = <<<'SQL'
        INSERT INTO idempotency_key (id, tenant_id, operation_id, key, request_digest, response_status, response_digest,
                                     response_body, response_headers)
        VALUES (?::uuid, ?::uuid, ?, ?, decode(?, 'hex'), ?, sha256(decode(?, 'hex')), decode(?, 'hex'), ?::jsonb)
        ON CONFLICT (tenant_id, operation_id, key) DO NOTHING
        SQL;

    /**
     * The request digest of a store operation: `RequestDigest::of()` over the request's fields **and the actor's user id**
     * (design RED L-1; final RED L-8). Keys are scoped to `(tenant, operation)` (§6.2), so without the actor a second
     * person reusing a colleague's key would be handed the colleague's counts; with it, that retry is a 409. Only for these
     * three operations — the domain retry keys (D-B, `unique:`) keep their digests without the actor. The actor is the
     * server-derived identity's user id, never a body field; the fields are nested under their own key so a body field
     * cannot collide with it. The caller normalises the fields (ids lower-cased and sorted: design §3.4).
     *
     * @param array<string, mixed> $fields
     */
    public static function requestDigest(string $operationId, string $actorUserId, array $fields): string
    {
        self::refuseUnlessAStoreOperation($operationId);

        return RequestDigest::of($operationId, ['actor' => RequestDigest::uuid($actorUserId), 'request' => $fields]);
    }

    /**
     * ONE statement. No row → null (proceed). Expired → 422 `key_expired`, the row untouched. Live with another digest → 409
     * `idempotency-key-conflict`. Live with the same digest → the stored envelope, to be returned byte for byte.
     */
    public function lookup(Connection $connection, string $operationId, string $keyHash, string $requestDigest): ?Replay
    {
        $row = $this->read($connection, $operationId, $keyHash, $requestDigest);

        if (null === $row) {
            return null;
        }

        if (!$row['live']) {
            throw ApiProblem::validationFailed([
                ['pointer' => '#Idempotency-Key', 'code' => self::EXPIRED_CODE, 'message' => self::EXPIRED_MESSAGE],
            ]);
        }

        if (!$row['same_request']) {
            throw ApiProblem::idempotencyKeyConflict(self::CONFLICT_HINT);
        }

        return $row['replay'];
    }

    /**
     * The envelope, written LAST in the request's transaction. Returns whether this request's row was inserted: false means
     * a same-key request committed first, and the caller rolls its work back to `bulk_work` and calls
     * {@see self::replayTheWinner()}.
     *
     * @param array<string, string> $headers lower-case names from {@see self::NORMATIVE_HEADERS}
     */
    public function record(
        Connection $connection,
        string $tenantId,
        string $operationId,
        string $keyHash,
        string $requestDigest,
        int $status,
        string $body,
        array $headers,
    ): bool {
        self::refuseUnlessAStoreOperation($operationId);
        self::refuseOutsideATransaction($connection);

        if (strtolower($tenantId) !== $connection->fetchOne("SELECT NULLIF(current_setting('app.tenant_id', true), '')")) {
            throw new \LogicException('An envelope is recorded only for the tenant whose context this transaction holds.');
        }

        if (self::STORED_STATUS !== $status) {
            throw new \LogicException(\sprintf('Only a %d is stored (D-8); a refusal has no side effect to protect.', self::STORED_STATUS));
        }

        if (1 !== preg_match(RequestDigest::PATTERN, $keyHash)) {
            throw new \LogicException('The key is stored as IdempotencyKeyHash::of(header), never the header (D-B).');
        }

        if ([] === $headers || [] !== array_diff(array_keys($headers), self::NORMATIVE_HEADERS)) {
            throw new \LogicException('An envelope carries only §6.2\'s normative headers, in lower case, and at least its content-type.');
        }

        return 1 === $connection->executeStatement(self::RECORD, [
            Uuid::v4()->toRfc4122(),
            $tenantId,
            $operationId,
            $keyHash,
            $requestDigest,
            $status,
            bin2hex($body),
            bin2hex($body),
            json_encode($headers, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * The same-transaction re-read after {@see self::record()} inserted nothing: the winner's envelope if the digests
     * match, else the 409. The winner committed seconds ago, so finding no row, or an expired one, "cannot happen" — and is
     * therefore a `LogicException` (a 500), never a fall-through to a 200 with this request's counts (final RED L-1).
     */
    public function replayTheWinner(Connection $connection, string $operationId, string $keyHash, string $requestDigest): Replay
    {
        $row = $this->read($connection, $operationId, $keyHash, $requestDigest)
            ?? throw new \LogicException('The envelope store inserted nothing for this key, and no row holds it.');

        if (!$row['live']) {
            throw new \LogicException('The envelope store inserted nothing for this key, and the row holding it has expired.');
        }

        if (!$row['same_request']) {
            throw ApiProblem::idempotencyKeyConflict(self::CONFLICT_HINT);
        }

        return $row['replay'];
    }

    /** @return array{same_request: bool, live: bool, replay: Replay}|null */
    private function read(Connection $connection, string $operationId, string $keyHash, string $requestDigest): ?array
    {
        self::refuseUnlessAStoreOperation($operationId);
        self::refuseOutsideATransaction($connection);

        $row = $connection->fetchAssociative(self::LOOKUP, [$requestDigest, self::WINDOW_HOURS, $operationId, $keyHash]);

        if (false === $row) {
            return null;
        }

        /** @var array<string, string> $headers */
        $headers = json_decode((string) $row['headers'], true, 2, \JSON_THROW_ON_ERROR);

        return [
            'same_request' => 1 === (int) $row['same_request'],
            'live' => 1 === (int) $row['live'],
            'replay' => new Replay((int) $row['response_status'], (string) hex2bin((string) $row['body']), $headers),
        ];
    }

    /** The protocol runs inside one tenancy transaction (`TenantContext::run()`); a bare connection is a caller defect. */
    private static function refuseOutsideATransaction(Connection $connection): void
    {
        if (!$connection->isTransactionActive()) {
            throw new \LogicException('The envelope store runs inside the request\'s tenancy transaction, on the run\'s own connection.');
        }
    }

    private static function refuseUnlessAStoreOperation(string $operationId): void
    {
        if (!\in_array($operationId, self::OPERATIONS, true)) {
            throw new \LogicException(\sprintf(
                '%s is not a store operation. §6.2 scopes the envelope store to the three bulk operations; every other '
                .'retry key is a domain column (unique:).',
                $operationId,
            ));
        }
    }
}

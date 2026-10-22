<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Http\Idempotency\IdempotencyKeyHash;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\Test;

/**
 * API-O-1 / `DELIVERY_LEDGER.md` E9-2 — the storage half of `idempotency_key`.
 *
 * ---------------------------------------------------------------------------
 * What this file does NOT assert, and why that is not a gap
 * ---------------------------------------------------------------------------
 * Nothing here checks that the table has RLS enabled and forced, carries a
 * `USING`/`WITH CHECK` policy pair, pins its primary key to UUIDv4, references
 * the registry, exposes a total `UNIQUE (tenant_id, id)`, leads every unique
 * index with `tenant_id`, or that `erp_app` holds exactly `SELECT` on it (ADR-002
 * Amendment 4 revoked its INSERT on 2026-09-16: the store has no `src/` writer). Every one of those is already asserted for **every** table by
 * `SchemaManifestTest`, `SchemaGuardTest`, `SchemaOwnershipTest` and
 * `GrantFootprintTest`, all of which derive their domain from
 * `SchemaManifest::TABLES` and the live catalogue and none of which has an
 * allow-list. Restating them here would be a second, weaker copy of a check
 * that already covers the case.
 *
 * What is left is what those cannot see: the isolation as *behaviour*, the three
 * write refusals as *statements* rather than as `has_table_privilege`, the
 * replay refusal the whole table exists to produce, the scope of the key, and
 * the column constraints that make `IdempotencyKeyGuard`'s 255-byte ceiling and
 * the store's ceiling the same limit rather than two limits that agree today.
 *
 * ---------------------------------------------------------------------------
 * What is deliberately untested here, because it is not built
 * ---------------------------------------------------------------------------
 * `API.md` §6.2's *"returns the stored response, byte for byte"* is not tested
 * here. **Until 2026-10-22 it could not be**: §6.1's column list held the
 * response **digest**, not the response. **G5-C0 (`Version20261022003000`) added
 * the envelope** — `response_body` (2..1024 bytes, `sha256` = `response_digest`),
 * `response_headers` (a closed object of §6.2's normative headers) and a key that
 * must be lower-case hex SHA-256 — and the replay is now `IdempotencyEnvelopeStore`'s,
 * tested in `IdempotencyEnvelopeStoreTest`; the HTTP byte-identical test lands with
 * the first store operation (G5-C2, `CreateBulkArchival`; the design expected G5-C1). Every fixture below therefore writes a full
 * envelope and a hashed key (design Response to RED M-2, 2026-10-22).
 */
final class IdempotencyKeyStorageTest extends TenancyTestCase
{
    private const OPERATION = 'CreateBulkArchival';

    /** A bulk body: counts only (`idempotency_key_body_bytes`, 2..1024). */
    private const BODY = '{"requested":1,"applied":1}';

    /** How a privilege refusal is named: by message, since RLS refusals share its SQLSTATE (R3). */
    private const PRIVILEGE_REFUSED = 'permission denied for table';

    /**
     * The isolation as behaviour. `SchemaManifestTest` proves the policy exists;
     * this proves it answers.
     *
     * It matters more here than on most tables: an idempotency record is
     * keyed on a string the *client* chose, so a policy that did not filter
     * would let one workspace's replay be answered from another workspace's
     * row — a cross-tenant read whose trigger is a value the attacker supplies.
     *
     * Mutation, run: `ALTER POLICY tenant_isolation ON idempotency_key USING
     * (true)` — tenant A's read returns both keys and the assertion fails on
     * the extra element.
     */
    #[Test]
    public function eachWorkspaceReadsOnlyTheKeysItRecorded(): void
    {
        $this->recordKey(self::TENANT_A, self::OPERATION, 'alpha');
        $this->recordKey(self::TENANT_B, self::OPERATION, 'beta');

        $read = fn (string $tenantId): array => $this->tenantContext()->run(
            $tenantId,
            fn (): array => $this->app()->fetchFirstColumn('SELECT key FROM idempotency_key ORDER BY key'),
        );

        self::assertSame([IdempotencyKeyHash::of('alpha')], $read(self::TENANT_A));
        self::assertSame([IdempotencyKeyHash::of('beta')], $read(self::TENANT_B));
    }

    /**
     * **`erp_app` cannot record a key YET** — the first of the three claims the
     * old `…CanNeitherRecordNorRewriteNorRemoveAKey` held together (split
     * 2026-10-22, G5-C0, design Response to RED M-2).
     *
     * `GrantFootprintTest::everyRoleHoldsExactlyTheTablePrivilegesTheManifestDeclares`
     * is the exhaustive check; this is the refused statement, named by its
     * message (`permission denied for table`), never by `42501` alone, which an
     * RLS refusal shares (ADR-002 Amendment 4, rule R3). G5-C0 added the envelope
     * columns and the store class and **deliberately granted nothing**: the INSERT
     * column list ships with its first caller (ADR-002 A4.1). **G5-C2 (2026-10-22)
     * is that commit** — `CreateBulkArchival` shipped before `CreateBulkRoleAssignment`
     * (`g5-c2-progress.md` CD-A) — **and turned this test into `recordsTheNineColumns`**
     * as the docblock said it would: `erp_app` INSERTs a row naming exactly the nine
     * granted columns (`Version20261022050000`). *(Was `theApplicationPrincipalCannotRecordYet`,
     * asserting the refusal.)*
     *
     * Mutation: `Version20261022050000`'s grant absent → reads `permission denied for table`.
     */
    #[Test]
    public function recordsTheNineColumns(): void
    {
        self::assertSame('accepted',
            $this->attemptAsApplicationRole($this->insertSql(), $this->insertParams(self::TENANT_A, self::OPERATION, 'written-once')),
            'erp_app cannot INSERT the nine envelope columns, so IdempotencyEnvelopeStore::record() cannot write (G5-C2\'s grant).');
    }

    /**
     * **`erp_app` can never set `created_at`.** The expiry (RO-B1 (a′): 24 h,
     * refused at read) is computed against that column on the database clock, so
     * a principal that could backdate it could make a key replay forever, and one
     * that could forward-date it could make a key expire early. The first caller's column
     * grant leaves `created_at` out, so the row always takes `DEFAULT now()`. Since
     * G5-C2 this statement is refused while the nine-column INSERT is accepted
     * ({@see self::recordsTheNineColumns()}) — so this test now carries weight on its own.
     *
     * Mutation (run at G5-C2): `created_at` added to the column grant → reads
     * `accepted`.
     */
    #[Test]
    public function theApplicationPrincipalCannotSetCreatedAt(): void
    {
        self::assertSame(self::PRIVILEGE_REFUSED, $this->attemptAsApplicationRole(<<<'SQL'
            INSERT INTO idempotency_key (id, tenant_id, operation_id, key, request_digest, response_status, response_digest,
                                         response_body, response_headers, created_at)
            VALUES (gen_random_uuid(), ?::uuid, ?, ?, sha256(convert_to(?, 'UTF8')), 200, sha256(convert_to(?, 'UTF8')),
                    convert_to(?, 'UTF8'), '{"content-type":"application/json"}', now() - interval '1 year')
            SQL, [self::TENANT_A, self::OPERATION, IdempotencyKeyHash::of('backdated'), 'r', self::BODY, self::BODY]),
            'erp_app set created_at, so it can move a key\'s expiry (RO-B1 (a′)).');
    }

    /**
     * **`erp_app` can neither rewrite nor remove a key — ever.** **No `UPDATE`**
     * is §6.1's rule that a replayed key is answered from the stored row rather
     * than rewritten, and it is what forces the write-once-at-commit protocol
     * (`INSERT … ON CONFLICT DO NOTHING`, then a same-transaction re-read: design
     * C-1). **No `DELETE`** is the larger one: a principal that can delete its own
     * idempotency records can replay any request it likes by first deleting the
     * row that would refuse it, which is the control removed by the party it
     * constrains. An expired key is refused and KEPT (RO-B1 (a′)); there is no
     * purge until a scheduler exists.
     *
     * Each verb gets its own connection, and that is not tidiness: a refused
     * statement inside `TenantContext::run()` closes the entity manager, so
     * verbs sharing one unit of work would measure the first refusal and then an
     * `EntityManagerClosed` — a fake-green wearing a red hat.
     *
     * Mutation, run (V4 batch B7): `GRANT UPDATE, DELETE ON idempotency_key TO
     * erp_app` — both read `accepted` and the assertion fails naming them.
     */
    #[Test]
    public function theApplicationPrincipalCanNeitherRewriteNorRemoveAKey(): void
    {
        self::assertSame(
            ['UPDATE' => self::PRIVILEGE_REFUSED, 'DELETE' => self::PRIVILEGE_REFUSED],
            [
                'UPDATE' => $this->attemptAsApplicationRole('UPDATE idempotency_key SET response_status = 500'),
                'DELETE' => $this->attemptAsApplicationRole('DELETE FROM idempotency_key'),
            ],
            'erp_app must not be able to rewrite or remove a key. API.md §6.1: a replayed key is answered from the '
            .'stored row, never rewritten — and a principal holding DELETE can replay anything by deleting the row '
            .'that refuses it. The purge is the owner\'s per-tenant lifecycle loop (SEC-O-3\'s ruling), not the '
            .'application\'s.');
    }

    /**
     * The refusal the table exists to produce.
     *
     * E9-2's consequence, stated in the ledger, is *"a client that retries a
     * timed-out bulk import and gets a second one"*. This is the database half
     * of stopping that: the second `INSERT` of one key raises `23505`, which
     * aborts the retry's whole transaction, so the second child document is
     * never committed either.
     *
     * Mutation, run: `ALTER TABLE idempotency_key DROP CONSTRAINT
     * idempotency_key_tenant_operation_key` — the replay reads `accepted` and
     * the assertion fails.
     */
    #[Test]
    public function aKeyAlreadyRecordedForAnOperationIsRefusedTheSecondTime(): void
    {
        $this->recordKey(self::TENANT_A, self::OPERATION, 'retried');

        self::assertSame('23505',
            $this->attemptAsOwnerForTenant(self::TENANT_A, $this->insertSql(), $this->insertParams(self::TENANT_A, self::OPERATION, 'retried')),
            'A key already recorded for this operation in this workspace was accepted a second time. '
            .'That is E9-2\'s exact consequence: the client that retries a timed-out bulk request gets it '
            .'applied twice. (IdempotencyEnvelopeStore::record() relies on this unique as its ON CONFLICT target.)');
    }

    /**
     * §6.2's first and fourth bullets: the key is scoped to `(tenant,
     * operation)`, and it is **not** a row id.
     *
     * Without this the previous test is satisfied by a uniqueness that is too
     * wide — a bare `UNIQUE (key)` refuses the replay and also refuses two
     * unrelated workspaces that happened to generate the same string, which is
     * a cross-tenant collision on a client-supplied value. Both halves are
     * asserted because the two failures look identical from inside the replay
     * test.
     *
     * Mutation, run: `CREATE UNIQUE INDEX mutant ON idempotency_key (key)` —
     * both inserts read `23505` and the assertion fails on both.
     */
    #[Test]
    public function theSameKeyIsFreeInAnotherWorkspaceAndOnAnotherOperation(): void
    {
        $this->recordKey(self::TENANT_A, self::OPERATION, 'collides');

        self::assertSame(
            ['other operation' => 'accepted', 'other workspace' => 'accepted'],
            [
                'other operation' => $this->attemptAsOwnerForTenant(self::TENANT_A, $this->insertSql(), $this->insertParams(self::TENANT_A, 'CreateBulkRestoration', 'collides')),
                'other workspace' => $this->attemptAsOwnerForTenant(self::TENANT_B, $this->insertSql(), $this->insertParams(self::TENANT_B, self::OPERATION, 'collides')),
            ],
            'A client-generated string in one workspace displaced another workspace\'s — or one '
            .'operation\'s displaced another\'s. §6.2: the key is scoped to (tenant, operation) and is '
            .'never a row id. Uniqueness wider than that is a cross-tenant collision triggered by a '
            .'value the caller chooses.');
    }

    /**
     * The 255-byte ceiling is **the guard's alone**, and the store holds its hash.
     *
     * **Re-anchored 2026-10-22 (G5-C0, design Response to RED M-2).** Until then
     * this test probed the `idempotency_key_key_bytes` constraint with raw keys of
     * the guard's length, because the column held the header. It now holds
     * `IdempotencyKeyHash::of(header)` — always 64 bytes of lower-case hex
     * (`idempotency_key_key_is_sha256_hex`, D-B's rule on every other retry key) —
     * so the column can no longer disagree with the guard about anything, and the
     * bytes CHECK stays only as a cheap backstop under the hex one. The ceiling
     * that matters is the edge's: `IdempotencyKeyGuard::MAXIMUM_KEY_BYTES`, which
     * `IdempotencyKeyHash::of()` restates as its precondition. **Every probe is
     * positioned from the guard's constant** — nothing here restates 255 — and the
     * multibyte probe is still why the limit counts bytes, not characters.
     *
     * Mutations: `IdempotencyKeyHash::of()`'s bound changed to `> MAXIMUM_KEY_BYTES + 1`
     * → "one byte over" is hashed → red; `strlen` replaced by `mb_strlen` → the
     * multibyte probe is hashed → red; the hex CHECK dropped → the raw key row
     * reads `accepted` → red.
     */
    #[Test]
    public function theGuardHoldsTheKeyCeilingAndTheStoreHoldsOnlyTheHash(): void
    {
        $maximum = IdempotencyKeyGuard::MAXIMUM_KEY_BYTES;
        $hashes = function (string $header): string {
            try {
                return 64 === \strlen(IdempotencyKeyHash::of($header)) ? 'hashed' : 'not 64 bytes';
            } catch (\LogicException) {
                return 'refused';
            }
        };

        self::assertSame([
            'one byte' => 'hashed',
            'at the ceiling' => 'hashed',
            'one byte over' => 'refused',
            'empty' => 'refused',
            'multibyte, under the character count and over the byte count' => 'refused',
        ], [
            'one byte' => $hashes('k'),
            'at the ceiling' => $hashes(str_repeat('k', $maximum)),
            'one byte over' => $hashes(str_repeat('k', $maximum + 1)),
            'empty' => $hashes(''),
            // 255 characters, 510 bytes. A character-counting guard would take it.
            'multibyte, under the character count and over the byte count' => $hashes(str_repeat('é', $maximum)),
        ], 'The key ceiling has moved off IdempotencyKeyGuard::MAXIMUM_KEY_BYTES, or started counting characters.');

        self::assertSame(['a hash' => 'accepted', 'a raw header' => '23514'], [
            'a hash' => $this->attemptAsOwnerForTenant(self::TENANT_A, $this->insertSql(), $this->insertParams(self::TENANT_A, self::OPERATION, str_repeat('k', $maximum))),
            'a raw header' => $this->attemptAsOwnerForTenant(self::TENANT_A, $this->insertSql(),
                [self::TENANT_A, self::OPERATION, str_repeat('k', 64), 'r', self::BODY, self::BODY]),
        ], 'The store accepted a key that is not IdempotencyKeyHash::of(header).');
    }

    /**
     * The two columns §6.2's conflict test is computed from, constrained so a
     * mis-encoded value fails at the `INSERT` rather than silently weakening it.
     *
     * §6.2's third bullet — same key, **different** body digest, 409 — is a
     * comparison of two digests. A truncated, hex-encoded or empty digest still
     * compares equal to itself, so a writer that stored the wrong thing would
     * produce a store that never conflicts and always replays. `bytea` fixed at
     * 32 octets is what makes *"this column holds a SHA-256"* a database
     * statement rather than a convention.
     *
     * `response_status` is bounded to the HTTP range for the same reason: the
     * status is the one part of the stored response a replay can actually
     * return (see the class docblock), so a nonsense value is returned to a
     * client verbatim.
     *
     * Mutations, run separately: `ALTER TABLE idempotency_key DROP CONSTRAINT
     * idempotency_key_request_sha256`, then `... DROP CONSTRAINT
     * idempotency_key_status_is_http` — the corresponding entry reads
     * `accepted` each time.
     */
    #[Test]
    public function aDigestThatIsNotASha256AndAStatusThatIsNotHttpAreBothRefused(): void
    {
        $insert = <<<'SQL'
            INSERT INTO idempotency_key (id, tenant_id, operation_id, key, request_digest, response_status, response_digest,
                                         response_body, response_headers)
            VALUES (gen_random_uuid(), ?::uuid, ?, ?, ?::bytea, ?, sha256(convert_to('{}', 'UTF8')), convert_to('{}', 'UTF8'),
                    '{"content-type":"application/json"}')
            SQL;

        $attempt = fn (string $key, string $digestExpression, int $status): string => $this->attemptAsOwnerForTenant(
            self::TENANT_A,
            str_replace('?::bytea', $digestExpression, $insert),
            [self::TENANT_A, self::OPERATION, IdempotencyKeyHash::of($key), $status],
        );

        self::assertSame([
            'a real digest, a real status' => 'accepted',
            'a truncated digest' => '23514',
            'an empty digest' => '23514',
            'a status outside the HTTP range' => '23514',
        ], [
            'a real digest, a real status' => $attempt('sound', "sha256(convert_to('{}', 'UTF8'))", 201),
            'a truncated digest' => $attempt('short', "substring(sha256(convert_to('{}', 'UTF8')) from 1 for 31)", 201),
            'an empty digest' => $attempt('empty', "''::bytea", 201),
            'a status outside the HTTP range' => $attempt('status', "sha256(convert_to('{}', 'UTF8'))", 999),
        ]);
    }

    /**
     * A committed row, written as the owner in the workspace's own context.
     *
     * The owner is used rather than `erp_app` because these rows have to survive
     * the statement that creates them: the refusal helper below rolls back by
     * design, and `FORCE ROW LEVEL SECURITY` binds the owner too, so it declares
     * a tenant like anyone else (`insertAsOwnerForTenant`).
     */
    private function recordKey(string $tenantId, string $operationId, string $key): void
    {
        $this->insertAsOwnerForTenant($tenantId, $this->insertSql(), $this->insertParams($tenantId, $operationId, $key));
    }

    private function insertSql(): string
    {
        return <<<'SQL'
            INSERT INTO idempotency_key (id, tenant_id, operation_id, key, request_digest, response_status, response_digest,
                                         response_body, response_headers)
            VALUES (gen_random_uuid(), ?::uuid, ?, ?, sha256(convert_to(?, 'UTF8')), 200, sha256(convert_to(?, 'UTF8')),
                    convert_to(?, 'UTF8'), '{"content-type":"application/json"}')
            SQL;
    }

    /**
     * The key is stored as `IdempotencyKeyHash::of(header)` (G5-C0), so every fixture hashes its label.
     *
     * @return list<string>
     */
    private function insertParams(string $tenantId, string $operationId, string $key): array
    {
        return [$tenantId, $operationId, IdempotencyKeyHash::of($key), '{"body":"'.$key.'"}', self::BODY, self::BODY];
    }

    /**
     * One statement, as `erp_app`, in a workspace's context, on a connection of
     * its own, always rolled back.
     *
     * Tenant context is established through `TenantContext::establishOn()`
     * rather than by hand: that is the sanctioned writer
     * (`TenantContextContainmentTest` allows exactly one production file), and
     * routing through it keeps this file off that test's allow-list.
     *
     * **A privilege refusal is named by its message, never by `42501` alone**
     * (ADR-002 Amendment 4, rule R3; V4 batch B7): an RLS refusal raises `42501`
     * too, so the SQLSTATE cannot say which control fired. `permission denied for
     * table` is returned as {@see self::PRIVILEGE_REFUSED}; anything else returns
     * its SQLSTATE and message, so it cannot masquerade as the privilege.
     *
     * The constraint proofs (`23505`, `23514`) no longer come through here. They
     * run as the owner in the workspace's context (`attemptAsOwnerForTenant`),
     * because a constraint binds every role and the subject of those tests is
     * the constraint, not the principal.
     *
     * @param list<mixed> $params
     */
    private function attemptAsApplicationRole(string $sql, array $params = [], string $tenantId = self::TENANT_A): string
    {
        $connection = $this->secondClient();
        $connection->beginTransaction();

        try {
            $this->tenantContext()->establishOn($connection, $tenantId);
            $connection->executeStatement($sql, $params);

            return 'accepted';
        } catch (DriverException $e) {
            return str_contains($e->getMessage(), self::PRIVILEGE_REFUSED)
                ? self::PRIVILEGE_REFUSED
                : ($e->getSQLState() ?? '?').': '.$e->getMessage();
        } finally {
            $connection->rollBack();
            $connection->close();
        }
    }
}

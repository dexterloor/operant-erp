<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
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
 * `API.md` §6.2's *"returns the stored response, byte for byte"* has no test in
 * this file and cannot have one: §6.1's column list holds the response
 * **digest**, not the response, so no replay path can reproduce a body. That is
 * a contract defect rather than a missing test, and it is recorded as such in
 * `Version20260910000200`'s docblock. `IdempotencyTest::everyIdempotentOperationRefusesAReplay`
 * — §6.1's own named phase-2 check, over the operation registry — is Output 9's
 * and is not written here.
 */
final class IdempotencyKeyStorageTest extends TenancyTestCase
{
    private const OPERATION = 'CreateGoodsReceipt';

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

        self::assertSame(['alpha'], $read(self::TENANT_A));
        self::assertSame(['beta'], $read(self::TENANT_B));
    }

    /**
     * The grant footprint as three statements rather than as a catalogue lookup.
     *
     * `GrantFootprintTest::everyRoleHoldsExactlyTheTablePrivilegesTheManifestDeclares`
     * asserts `has_table_privilege` is `SELECT` and nothing else, in both
     * directions, and that is the exhaustive check. This one asserts what a
     * reader wants to know — that the application login can neither record, nor
     * rewrite, nor remove a key — because a privilege check and a refused
     * statement have come apart before on this project. Each refusal is named by
     * its message, `permission denied for table`, never by `42501` alone, which an
     * RLS refusal shares (ADR-002 Amendment 4, rule R3).
     *
     * **No `INSERT`** since ADR-002 Amendment 4 (`Version20260916000300`,
     * 2026-09-16): nothing under `src/` writes this store — `IdempotencyKeyGuard`
     * requires and discards the header, and every built retry key is a domain
     * column on its business table — so the privilege had no caller. Until then
     * this test was `…RecordsAKeyOnceAndThenCannotRewriteOrRemoveIt` and asserted
     * INSERT `accepted`. The first bulk operation that writes the store grants
     * INSERT in its own migration and moves this pin.
     *
     * The two older refusals are not symmetry. **No `UPDATE`** is §6.1's rule that a
     * replayed key is answered from the stored row rather than rewritten, and it
     * is what forces the write-once-at-commit protocol. **No `DELETE`** is the
     * larger one: a principal that can delete its own idempotency records can
     * replay any request it likes by first deleting the row that would refuse
     * it, which is the control removed by the party it constrains.
     *
     * Each verb gets its own connection, and that is not tidiness: a refused
     * statement inside `TenantContext::run()` closes the entity manager, so
     * three verbs sharing one unit of work would measure the first refusal and
     * then an `EntityManagerClosed` — a fake-green wearing a red hat.
     *
     * Mutations, run (V4 batch B7): `GRANT UPDATE, DELETE ON idempotency_key TO
     * erp_app` — both read `accepted` and the assertion fails naming them; and
     * the migration's revoke absent — INSERT reads `accepted`.
     */
    #[Test]
    public function theApplicationPrincipalCanNeitherRecordNorRewriteNorRemoveAKey(): void
    {
        $outcomes = [
            'INSERT' => $this->attemptAsApplicationRole($this->insertSql(), $this->insertParams(self::TENANT_A, self::OPERATION, 'written-once')),
            'UPDATE' => $this->attemptAsApplicationRole('UPDATE idempotency_key SET response_status = 500'),
            'DELETE' => $this->attemptAsApplicationRole('DELETE FROM idempotency_key'),
        ];

        self::assertSame(
            ['INSERT' => self::PRIVILEGE_REFUSED, 'UPDATE' => self::PRIVILEGE_REFUSED, 'DELETE' => self::PRIVILEGE_REFUSED],
            $outcomes,
            'erp_app must not be able to record, rewrite or remove a key. INSERT has no src/ caller (ADR-002 '
            .'Amendment 4). API.md §6.1: a replayed key is answered from the stored row, never rewritten — and a '
            .'principal holding DELETE can replay anything by deleting the row that refuses it. The '
            .'purge is the owner\'s per-tenant lifecycle loop (SEC-O-3\'s ruling), not the application\'s.');
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
            .'That is E9-2\'s exact consequence: the client that retries a timed-out request gets a '
            .'second goods receipt, and no precondition refuses it because partial receipt is legal.');
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
                'other operation' => $this->attemptAsOwnerForTenant(self::TENANT_A, $this->insertSql(), $this->insertParams(self::TENANT_A, 'CreateFulfilment', 'collides')),
                'other workspace' => $this->attemptAsOwnerForTenant(self::TENANT_B, $this->insertSql(), $this->insertParams(self::TENANT_B, self::OPERATION, 'collides')),
            ],
            'A client-generated string in one workspace displaced another workspace\'s — or one '
            .'operation\'s displaced another\'s. §6.2: the key is scoped to (tenant, operation) and is '
            .'never a row id. Uniqueness wider than that is a cross-tenant collision triggered by a '
            .'value the caller chooses.');
    }

    /**
     * The 255-byte ceiling, asserted as **one** limit rather than two that agree.
     *
     * `IdempotencyKeyGuard::MAXIMUM_KEY_BYTES` refuses an over-long header at
     * the edge; the `idempotency_key_key_bytes` constraint refuses an over-long
     * key at the store. Two numbers in two files drift, so **every probe below
     * is positioned from the guard's constant** — nothing here restates 255.
     * Move either limit and the run is red.
     *
     * The column is `text` with an octet-length check rather than
     * `varchar(255)`, and the multibyte case is why: `varchar(n)` counts
     * *characters*, so a 255-character accented key is 510 bytes and would be
     * accepted by a `varchar(255)` store and refused by the guard, which counts
     * bytes. The two would then disagree about what the limit measures rather
     * than about its value, which is the harder failure to see.
     *
     * **A second half was written, observed to be redundant, and deleted** —
     * recorded because the deletion is the finding. It read the bounds out of
     * `pg_get_constraintdef` and compared them with the guard's constant. Two
     * mutations were run against it and *neither reached it*: dropping the
     * constraint stops the run at the statements above, and changing the
     * constant moves the probes above with it, so both are red before the
     * catalogue is ever queried. It was a textual restatement of a binding the
     * statements already prove semantically, and its only reachable failure was
     * an equivalent constraint written in a different form — a false alarm.
     * A check that cannot be made to fail for its own reason is not evidence.
     *
     * Mutations, run:
     *   (a) `ALTER TABLE idempotency_key DROP CONSTRAINT idempotency_key_key_bytes`
     *       — the over-long, empty and multibyte keys all read `accepted`.
     *   (b) `IdempotencyKeyGuard::MAXIMUM_KEY_BYTES` changed to 256 — *"at the
     *       ceiling"* reads `23514`, because the probe moved and the database
     *       did not. That is the two limits being one limit.
     *   (c) the constraint rewritten with `length(key)` in place of
     *       `octet_length(key)` — the three ASCII probes are unmoved and the
     *       multibyte one reads `accepted`. That is the `varchar(255)` defect,
     *       and it is the reason that probe exists.
     */
    #[Test]
    public function theStoreAndTheGuardShareOneKeyCeilingMeasuredInBytes(): void
    {
        $maximum = IdempotencyKeyGuard::MAXIMUM_KEY_BYTES;

        self::assertSame([
            'at the ceiling' => 'accepted',
            'one byte over' => '23514',
            'empty' => '23514',
            'multibyte, under the character count and over the byte count' => '23514',
        ], [
            'at the ceiling' => $this->attemptKeyOfBytes(str_repeat('k', $maximum)),
            'one byte over' => $this->attemptKeyOfBytes(str_repeat('k', $maximum + 1)),
            'empty' => $this->attemptKeyOfBytes(''),
            // 255 characters, 510 bytes. A `varchar(255)` store would take it.
            'multibyte, under the character count and over the byte count' => $this->attemptKeyOfBytes(str_repeat('é', $maximum)),
        ], 'The store\'s key ceiling and IdempotencyKeyGuard::MAXIMUM_KEY_BYTES have come apart. A key '
            .'the guard accepts and the store refuses is a 500 on a legal request; a key the store '
            .'accepts and the guard refuses is a limit with no meaning. If only the multibyte row moved, '
            .'the constraint has started counting characters instead of bytes.');
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
            INSERT INTO idempotency_key (id, tenant_id, operation_id, key, request_digest, response_status, response_digest)
            VALUES (gen_random_uuid(), ?::uuid, ?, ?, ?::bytea, ?, sha256(convert_to('{}', 'UTF8')))
            SQL;

        $attempt = fn (string $key, string $digestExpression, int $status): string => $this->attemptAsOwnerForTenant(
            self::TENANT_A,
            str_replace('?::bytea', $digestExpression, $insert),
            [self::TENANT_A, self::OPERATION, $key, $status],
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
            INSERT INTO idempotency_key (id, tenant_id, operation_id, key, request_digest, response_status, response_digest)
            VALUES (gen_random_uuid(), ?::uuid, ?, ?, sha256(convert_to(?, 'UTF8')), 201, sha256(convert_to('{}', 'UTF8')))
            SQL;
    }

    /** @return list<string> */
    private function insertParams(string $tenantId, string $operationId, string $key): array
    {
        return [$tenantId, $operationId, $key, '{"body":"'.$key.'"}'];
    }

    private function attemptKeyOfBytes(string $key): string
    {
        return $this->attemptAsOwnerForTenant(self::TENANT_A, $this->insertSql(), $this->insertParams(self::TENANT_A, self::OPERATION, $key));
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

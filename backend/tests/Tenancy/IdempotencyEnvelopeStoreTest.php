<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Platform\Http\Idempotency\IdempotencyEnvelopeStore;
use App\Platform\Http\Idempotency\IdempotencyKeyHash;
use App\Platform\Http\Idempotency\Replay;
use App\Platform\Http\Problem\ApiProblem;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\Test;

/**
 * `IdempotencyEnvelopeStore` — the `store` mechanism of `API.md` §6.2, tested at service level (remaining-ops G5-C0,
 * 2026-10-22). The HTTP byte-identical test and the two-connection race landed with the first store operation, G5-C2
 * (`BulkArchivalTest::aReplayIsByteIdenticalOverHttp`, `::aConcurrentDuplicateReplaysTheWinnerByteForByteAndLeavesNoLoserWrites`).
 *
 * **Dated note (2026-10-22, final RED L-7): every test here drives the store on the SCHEMA OWNER's connection**, under
 * `TenantContext::establishOn()`, inside a transaction that is always rolled back. `erp_app` holds SELECT only on
 * `idempotency_key` until the first caller's migration (G5-C2, `Version20261022050000`) granted the INSERT column list
 * (ADR-002 A4.1), so at G5-C0 `record()` could not run as `erp_app` — and without this it would have shipped unexecuted. The store takes a `Connection`
 * precisely so that this is possible. Locally the owner is NOBYPASSRLS and the table is FORCE ROW LEVEL SECURITY, so the
 * tenant context is real and the CHECKs bind exactly as they will for `erp_app`. Since G5-C2 `erp_app`'s own path is exercised
 * over HTTP (`BulkArchivalTest`); these stay on the owner as written (a constraint binds every role).
 */
final class IdempotencyEnvelopeStoreTest extends TenancyTestCase
{
    private const OPERATION = 'CreateBulkArchival';
    private const ACTOR = '0b6f1d8e-2c51-4d7a-9e3b-5a0c6f2d1e44';
    private const BODY = '{"requested":3,"applied":2}';
    private const HEADERS = ['content-type' => 'application/json'];

    /**
     * The store's reason to exist: the replay is the stored BYTES, status and headers, never a re-serialisation.
     *
     * Mutation: `lookup()` returns the body re-encoded through `json_decode`/`json_encode` (pretty-printed) → red on the
     * byte comparison. The body below carries a key order and spacing no encoder would choose, so equality is of bytes.
     */
    #[Test]
    public function aRecordedEnvelopeIsReplayedByteForByte(): void
    {
        $body = '{ "requested": 3,"applied" :2 }';

        $replay = $this->inOwnerContext(function (Connection $c) use ($body): ?Replay {
            self::assertTrue($this->store()->record($c, self::TENANT_A, self::OPERATION, $this->key('k-1'), $this->digest(['ids' => ['a']]), 200, $body, self::HEADERS));

            return $this->store()->lookup($c, self::OPERATION, $this->key('k-1'), $this->digest(['ids' => ['a']]));
        });

        self::assertNotNull($replay);
        self::assertSame(200, $replay->status);
        self::assertSame($body, $replay->body, 'The replay is not the stored bytes.');
        self::assertSame(self::HEADERS, $replay->headers);
    }

    /**
     * No row → null, and the key is scoped to `(tenant, operation)`: the same key under another store operation, or in
     * another workspace, is not this request's envelope.
     *
     * Mutation: the lookup's `WHERE operation_id = ?` dropped → the other-operation read returns the envelope → red.
     */
    #[Test]
    public function anUnknownKeyIsNothingAndAKeyIsScopedToItsOperationAndWorkspace(): void
    {
        [$unknown, $otherOperation] = $this->inOwnerContext(function (Connection $c): array {
            $this->store()->record($c, self::TENANT_A, self::OPERATION, $this->key('scoped'), $this->digest(['ids' => ['a']]), 200, self::BODY, self::HEADERS);

            return [
                $this->store()->lookup($c, self::OPERATION, $this->key('never-sent'), $this->digest(['ids' => ['a']])),
                $this->store()->lookup($c, 'CreateBulkRestoration', $this->key('scoped'), $this->digest(['ids' => ['a']], 'CreateBulkRestoration')),
            ];
        });
        self::assertNull($unknown);
        self::assertNull($otherOperation, 'A key recorded for CreateBulkArchival answered CreateBulkRestoration.');

        $otherWorkspace = $this->inOwnerContext(function (Connection $c): ?Replay {
            $this->store()->record($c, self::TENANT_A, self::OPERATION, $this->key('scoped'), $this->digest(['ids' => ['a']]), 200, self::BODY, self::HEADERS);
            $this->tenantContext()->establishOn($c, self::TENANT_B);

            return $this->store()->lookup($c, self::OPERATION, $this->key('scoped'), $this->digest(['ids' => ['a']]));
        });
        self::assertNull($otherWorkspace, 'Workspace B was answered from workspace A\'s envelope.');
    }

    /**
     * RO-B1 (a′): an expired key is REFUSED, 422 `key_expired` on `#Idempotency-Key`, and its row is left exactly as it
     * was — never overwritten, never purged. The row is seeded with a backdated `created_at` as the owner (`erp_app` will
     * not be able to set it: it is outside the column grant, G5-C2). A row 23 hours old is still live (the boundary control,
     * so an always-expired predicate is red too).
     *
     * Mutations: the `live` predicate dropped (always live) → the 25-hour row replays → red; the window flipped to always
     * expired → the 23-hour row is refused → red.
     */
    #[Test]
    public function anExpiredKeyIsRefusedAndTheRowIsUntouched(): void
    {
        $this->inOwnerContext(function (Connection $c): void {
            $this->seedAged($c, 'old', 25);
            $this->seedAged($c, 'recent', 23);
            $before = $this->rowOf($c, 'old');

            try {
                $this->store()->lookup($c, self::OPERATION, $this->key('old'), $this->digest(['ids' => ['a']]));
                self::fail('A 25-hour-old key was replayed; RO-B1 (a′) refuses it.');
            } catch (ApiProblem $refused) {
                self::assertSame(422, $refused->getStatus());
                self::assertSame('urn:erp:problem:validation-failed', $refused->getType());
                self::assertSame([[
                    'pointer' => '#Idempotency-Key',
                    'code' => 'key_expired',
                    'message' => 'This retry key has expired. Send the request again with a new key.',
                ]], $refused->getErrors());
            }

            self::assertSame($before, $this->rowOf($c, 'old'), 'The expired row was changed by the refusal.');
            self::assertNotNull($this->store()->lookup($c, self::OPERATION, $this->key('recent'), $this->digest(['ids' => ['a']])),
                'A 23-hour-old key was refused; the window is 24 hours.');
        });
    }

    /**
     * §6.2: the same key with another request digest is 409 `idempotency-key-conflict`, never a replay.
     *
     * Mutation: the lookup compares on the key alone (`same_request` forced true) → the other body replays → red.
     */
    #[Test]
    public function aLiveKeyWithAnotherDigestIsAConflict(): void
    {
        $this->inOwnerContext(function (Connection $c): void {
            $this->store()->record($c, self::TENANT_A, self::OPERATION, $this->key('reused'), $this->digest(['ids' => ['a']]), 200, self::BODY, self::HEADERS);

            $this->assertConflict(fn () => $this->store()->lookup($c, self::OPERATION, $this->key('reused'), $this->digest(['ids' => ['b']])));
        });
    }

    /**
     * Design RED L-1: the actor's user id is in the digest of a store operation, so a colleague reusing a key is a
     * conflict rather than handed the first person's counts. And the actor is nested apart from the fields, so a body
     * field named `actor` cannot stand in for it. Only the three store operations digest this way.
     *
     * Mutation: `requestDigest()` drops the actor → the two actors digest alike → red.
     */
    #[Test]
    public function theActorIsInTheDigestOfAStoreOperationAndNowhereElse(): void
    {
        $fields = ['resource' => 'customer', 'ids' => ['a', 'b']];
        $other = 'c1a5e9b2-7d3f-4a60-8b1e-2f9d4c6a0b17';

        self::assertNotSame(
            IdempotencyEnvelopeStore::requestDigest(self::OPERATION, self::ACTOR, $fields),
            IdempotencyEnvelopeStore::requestDigest(self::OPERATION, $other, $fields),
            'Two actors sending one request digest alike, so a reused key would replay the first actor\'s counts.');
        self::assertSame(
            IdempotencyEnvelopeStore::requestDigest(self::OPERATION, self::ACTOR, $fields),
            IdempotencyEnvelopeStore::requestDigest(self::OPERATION, strtoupper(self::ACTOR), ['ids' => ['a', 'b'], 'resource' => 'customer']),
            'The same actor and request (key order and UUID case aside) must digest alike.');
        self::assertNotSame(
            IdempotencyEnvelopeStore::requestDigest(self::OPERATION, self::ACTOR, $fields),
            IdempotencyEnvelopeStore::requestDigest(self::OPERATION, $other, $fields + ['actor' => self::ACTOR]),
            'A body field named actor stood in for the server-derived actor.');

        $this->expectException(\LogicException::class);
        IdempotencyEnvelopeStore::requestDigest('CreateGoodsReceipt', self::ACTOR, $fields);
    }

    /**
     * `record()` is `INSERT … ON CONFLICT (tenant_id, operation_id, key) DO NOTHING` (design C-1): a same-key row that is
     * already there makes it insert nothing and say so — no 23505, so nothing leaves `run()` and the manager stays open —
     * and the first envelope is unchanged (no UPDATE, ever).
     *
     * Mutation: the `ON CONFLICT` clause removed → the second record raises 23505 → red.
     */
    #[Test]
    public function aSecondRecordOfOneKeyInsertsNothingAndLeavesTheFirstEnvelope(): void
    {
        $replay = $this->inOwnerContext(function (Connection $c): Replay {
            self::assertTrue($this->store()->record($c, self::TENANT_A, self::OPERATION, $this->key('twice'), $this->digest(['ids' => ['a']]), 200, self::BODY, self::HEADERS));
            self::assertFalse($this->store()->record($c, self::TENANT_A, self::OPERATION, $this->key('twice'), $this->digest(['ids' => ['a']]), 200, '{"requested":3,"applied":0}', self::HEADERS),
                'A second record of one key reported an insert.');

            return $this->store()->replayTheWinner($c, self::OPERATION, $this->key('twice'), $this->digest(['ids' => ['a']]));
        });

        self::assertSame(self::BODY, $replay->body, 'The second record overwrote the first envelope.');
    }

    /**
     * Final RED L-1: after `record()` inserted nothing, the same-transaction re-read must find the winner. A missing or
     * expired row there "cannot happen", so it is a `LogicException` (a 500) — never a fall-through to a 200 with the
     * loser's counts. A different digest on that branch is the ordinary 409.
     *
     * Mutations: `replayTheWinner()` returns null-safe (no throw) on a missing row → red; on an expired row it replays → red.
     */
    #[Test]
    public function replayingTheWinnerFailsLoudWhenThereIsNoLiveWinner(): void
    {
        $this->inOwnerContext(function (Connection $c): void {
            $this->store()->record($c, self::TENANT_A, self::OPERATION, $this->key('won'), $this->digest(['ids' => ['a']]), 200, self::BODY, self::HEADERS);
            $this->assertConflict(fn () => $this->store()->replayTheWinner($c, self::OPERATION, $this->key('won'), $this->digest(['ids' => ['b']])));

            $this->seedAged($c, 'stale', 25);
            foreach (['missing' => 'never-recorded', 'expired' => 'stale'] as $case => $key) {
                try {
                    $this->store()->replayTheWinner($c, self::OPERATION, $this->key($key), $this->digest(['ids' => ['a']]));
                    self::fail('replayTheWinner() answered for a '.$case.' winner instead of failing loud.');
                } catch (\LogicException) {
                    // expected
                }
            }
        });
    }

    /**
     * The store holds only a 200 (D-8), only for the three store operations, and only §6.2's normative headers.
     */
    #[Test]
    public function onlyASuccessOfAStoreOperationWithNormativeHeadersCanBeRecorded(): void
    {
        $this->inOwnerContext(function (Connection $c): void {
            $attempts = [
                'a 409' => fn () => $this->store()->record($c, self::TENANT_A, self::OPERATION, $this->key('x'), $this->digest(['ids' => ['a']]), 409, self::BODY, self::HEADERS),
                'a domain-key operation' => fn () => $this->store()->record($c, self::TENANT_A, 'CreateGoodsReceipt', $this->key('x'), $this->digest(['ids' => ['a']]), 200, self::BODY, self::HEADERS),
                'a non-normative header' => fn () => $this->store()->record($c, self::TENANT_A, self::OPERATION, $this->key('x'), $this->digest(['ids' => ['a']]), 200, self::BODY, self::HEADERS + ['x-request-id' => '1']),
                'no headers' => fn () => $this->store()->record($c, self::TENANT_A, self::OPERATION, $this->key('x'), $this->digest(['ids' => ['a']]), 200, self::BODY, []),
                'a key that is not a hash' => fn () => $this->store()->record($c, self::TENANT_A, self::OPERATION, 'raw-header', $this->digest(['ids' => ['a']]), 200, self::BODY, self::HEADERS),
            ];

            $refused = [];
            foreach ($attempts as $case => $attempt) {
                try {
                    $attempt();
                } catch (\LogicException) {
                    $refused[] = $case;
                }
            }

            self::assertSame(array_keys($attempts), $refused);
            self::assertSame(0, (int) $c->fetchOne('SELECT count(*) FROM idempotency_key'));
        });
    }

    /**
     * What the store checks because it cannot see which principal it was handed (deptrac A-10 deviation, fresh RED
     * 2026-10-22): a connection with no open transaction is refused for every method, a `record()` for a tenant other than
     * the session's is refused, and the lookup names the session's tenant in SQL (so a principal that bypasses RLS still
     * reads only its own workspace — asserted here by the GUC, since the local owner is bound by RLS anyway).
     *
     * Mutations: the transaction check removed → the bare-connection calls run → red; the tenant check removed → the
     * cross-tenant record is accepted → red; the lookup's `tenant_id` predicate removed → still green locally (RLS answers
     * the same), which is why it is defence in depth, recorded, not a tested control.
     */
    #[Test]
    public function theStoreRefusesABareConnectionAndAnotherTenantsRecord(): void
    {
        $bare = $this->secondClient();
        $refused = [];
        foreach ([
            'lookup' => fn () => $this->store()->lookup($bare, self::OPERATION, $this->key('bare'), $this->digest(['ids' => ['a']])),
            'record' => fn () => $this->store()->record($bare, self::TENANT_A, self::OPERATION, $this->key('bare'), $this->digest(['ids' => ['a']]), 200, self::BODY, self::HEADERS),
            'replayTheWinner' => fn () => $this->store()->replayTheWinner($bare, self::OPERATION, $this->key('bare'), $this->digest(['ids' => ['a']])),
        ] as $method => $call) {
            try {
                $call();
            } catch (\LogicException) {
                $refused[] = $method;
            }
        }
        $bare->close();
        self::assertSame(['lookup', 'record', 'replayTheWinner'], $refused, 'The store ran outside a transaction.');

        $this->inOwnerContext(function (Connection $c): void {
            try {
                $this->store()->record($c, self::TENANT_B, self::OPERATION, $this->key('cross'), $this->digest(['ids' => ['a']]), 200, self::BODY, self::HEADERS);
                self::fail('An envelope was recorded for workspace B inside workspace A\'s context.');
            } catch (\LogicException) {
                // expected
            }
            self::assertTrue($this->store()->record($c, strtoupper(self::TENANT_A), self::OPERATION, $this->key('cross'), $this->digest(['ids' => ['a']]), 200, self::BODY, self::HEADERS),
                'The session\'s own tenant, in upper case, was refused.');
        });
    }

    /**
     * The four CHECKs `Version20261022003000` adds, as statements (a constraint binds every role, so the owner proves it).
     *
     * Mutations, each run separately: drop `idempotency_key_body_digest` → "a body without its digest" accepted; drop
     * `idempotency_key_headers_closed` → the three header rows accepted; drop `idempotency_key_key_is_sha256_hex` → the raw
     * and upper-case keys accepted; drop `idempotency_key_body_bytes` → the one-byte and 1025-byte bodies accepted.
     */
    #[Test]
    public function theDatabaseHoldsTheEnvelopesShape(): void
    {
        $insert = <<<'SQL'
            INSERT INTO idempotency_key (id, tenant_id, operation_id, key, request_digest, response_status, response_digest,
                                         response_body, response_headers)
            VALUES (gen_random_uuid(), ?::uuid, 'CreateBulkArchival', ?, sha256('\x00'::bytea), 200, sha256(convert_to(?, 'UTF8')),
                    convert_to(?, 'UTF8'), ?::jsonb)
            SQL;
        $attempt = fn (string $key, string $body, string $headers, ?string $digestOf = null): string => $this->attemptAsOwnerForTenant(
            self::TENANT_A, $insert, [self::TENANT_A, $key, $digestOf ?? $body, $body, $headers]);
        $hex = $this->key('shape');
        $json = '{"content-type":"application/json"}';

        self::assertSame([
            'a sound envelope' => 'accepted',
            'all three normative headers' => 'accepted',
            'a body of 1024 bytes' => 'accepted',
            'a body without its digest' => '23514',
            'a header outside the normative set' => '23514',
            'a header name in upper case' => '23514',
            'headers that are not an object' => '23514',
            'a raw header value as the key' => '23514',
            'an upper-case hex key' => '23514',
            'a one-byte body' => '23514',
            'a body of 1025 bytes' => '23514',
        ], [
            'a sound envelope' => $attempt($hex, self::BODY, $json),
            'all three normative headers' => $attempt($hex, self::BODY, '{"content-type":"text/csv","location":"/x","content-disposition":"attachment"}'),
            'a body of 1024 bytes' => $attempt($hex, str_repeat('7', 1024), $json),
            'a body without its digest' => $attempt($hex, self::BODY, $json, '{"requested":3,"applied":3}'),
            'a header outside the normative set' => $attempt($hex, self::BODY, '{"content-type":"application/json","x-foo":"1"}'),
            'a header name in upper case' => $attempt($hex, self::BODY, '{"Content-Type":"application/json"}'),
            'headers that are not an object' => $attempt($hex, self::BODY, '["content-type"]'),
            'a raw header value as the key' => $attempt('my-retry-key', self::BODY, $json),
            'an upper-case hex key' => $attempt(strtoupper($hex), self::BODY, $json),
            'a one-byte body' => $attempt($hex, '7', $json),
            'a body of 1025 bytes' => $attempt($hex, str_repeat('7', 1025), $json),
        ]);
    }

    private function store(): IdempotencyEnvelopeStore
    {
        return new IdempotencyEnvelopeStore();
    }

    private function key(string $header): string
    {
        return IdempotencyKeyHash::of($header);
    }

    /** @param array<string, mixed> $fields */
    private function digest(array $fields, string $operationId = self::OPERATION): string
    {
        return IdempotencyEnvelopeStore::requestDigest($operationId, self::ACTOR, $fields);
    }

    /**
     * The owner's connection in workspace A's context, always rolled back (the class docblock's dated note).
     *
     * @template T
     *
     * @param callable(Connection): T $work
     *
     * @return T
     */
    private function inOwnerContext(callable $work): mixed
    {
        $c = $this->owner();
        $c->beginTransaction();
        try {
            $this->tenantContext()->establishOn($c, self::TENANT_A);

            return $work($c);
        } finally {
            $c->rollBack();
        }
    }

    /** An envelope written `$hours` ago (owner-set `created_at`; `erp_app` cannot set it). */
    private function seedAged(Connection $c, string $header, int $hours): void
    {
        $c->executeStatement(<<<'SQL'
            INSERT INTO idempotency_key (id, tenant_id, operation_id, key, request_digest, response_status, response_digest,
                                         response_body, response_headers, created_at)
            VALUES (gen_random_uuid(), ?::uuid, ?, ?, decode(?, 'hex'), 200, sha256(convert_to(?, 'UTF8')), convert_to(?, 'UTF8'),
                    '{"content-type":"application/json"}', now() - make_interval(hours => ?))
            SQL, [self::TENANT_A, self::OPERATION, $this->key($header), $this->digest(['ids' => ['a']]), self::BODY, self::BODY, $hours]);
    }

    /** @return array<string, mixed> every column, as text */
    private function rowOf(Connection $c, string $header): array
    {
        return (array) $c->fetchAssociative(
            'SELECT id::text, operation_id, key, encode(request_digest, \'hex\') AS rd, response_status, encode(response_digest, \'hex\') AS sd, '
            .'encode(response_body, \'hex\') AS body, response_headers::text AS headers, created_at::text AS created_at '
            .'FROM idempotency_key WHERE key = ?', [$this->key($header)]);
    }

    private function assertConflict(callable $attempt): void
    {
        try {
            $attempt();
            self::fail('A reused key with another request was not refused as a conflict.');
        } catch (ApiProblem $refused) {
            self::assertSame(409, $refused->getStatus());
            self::assertSame('urn:erp:problem:idempotency-key-conflict', $refused->getType());
        } catch (DriverException $e) {
            self::fail('The conflict surfaced as a database error: '.$e->getMessage());
        }
    }
}

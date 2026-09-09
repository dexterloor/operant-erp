<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Audit\AuditEvent;
use App\Audit\DenialAuditWriter;
use App\Tenancy\SchemaManifest;
use App\Tests\Support\SecurityModelDocument;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **E-3 and E-3a — every denial is audited, and the audit is capped.**
 *
 * `DenialCapStorageTest` (E4-2) proved that the *storage* can serve a bounded
 * probe: the column, the partial index, and a plan shape measured both ways
 * against a 10,000-row fixture. This file is about the writer that uses it — that
 * the row is written at all, that it carries what E-3 requires, that the cap is
 * keyed on `(actor, code)` and not on the resource, and that the statement the
 * application actually issues is the one whose plan was proved.
 *
 * ---------------------------------------------------------------------------
 * Why the plan assertion here is not a copy of the storage test's
 * ---------------------------------------------------------------------------
 * That one measures a probe transcribed into the test file. This one measures
 * `DenialAuditWriter::READ_BACK` — the constant the writer executes — so a writer
 * that quietly grew a different statement fails here even though the schema is
 * untouched and the storage test is green. A proved plan for a statement nobody
 * runs is the same shape of nothing as a test with no subject.
 */
final class PermissionDenialCapTest extends ApiTestCase
{
    private const VIEW = 'inventory_item.view';
    private const DELETE = 'inventory_item.delete';

    /**
     * The fixture the plan is measured against, and it is **pinned here with its
     * reason** — a free fixture size is a knob that configures the test green.
     *
     * `API.md` §9.4a pins *"fifty times E-3a's ceiling"* = 100 rows. Measured on
     * this server, 100 rows is two heap pages and the planner chooses a `Seq Scan`
     * **with the correct index in place**, so §9.4a's own assertion goes red for a
     * reason unrelated to the property it asserts — a fake-*red*, which gets
     * closed by weakening the assertion. The changeover is between 150 and 200
     * rows; §9.4a's own cited plan A reports `Rows Removed by Filter: 9997`, i.e.
     * a 10,000-row fixture, so the contract's evidence and its stated size already
     * disagree. Raised as API-O-20.
     *
     * 2,000 is an order of magnitude above the changeover and an order of
     * magnitude below `DenialCapStorageTest`'s, which is deliberate: this file
     * measures the *writer's* statement rather than re-measuring the schema, and a
     * second 20,000-row fixture would cost a suite minute to re-prove something
     * already proved.
     */
    private const PLAN_FIXTURE_DENIALS = 2000;

    /** Business-event rows for the same actor on the same day: what the partial index excludes. */
    private const PLAN_FIXTURE_BUSINESS = 2000;

    /** Distinct codes the fixture denials are spread over — §3's catalogue is 60. */
    private const PLAN_FIXTURE_CODES = 60;

    // -----------------------------------------------------------------------
    // E-3 — the row, and what it carries
    // -----------------------------------------------------------------------

    /**
     * *"the actor, the required permission code, the resource type and id, and
     * the source IP."* Every one of them, on a real refused request.
     *
     * `subject_type` is derived from the code's own resource half and checked
     * against `SchemaManifest::TABLES`, so the assertion below reads the table
     * name out of the manifest rather than restating it.
     *
     * *Mutation, run:* the `record()` call removed from `PermissionGate`. The
     * request is still a correct 403 and the trail is empty — red, and it is the
     * shape E-3's violation clause names (*"a voter returning false with no audit
     * write"*).
     */
    #[Test]
    public function aDenialWritesTheRowEminusThreeRequires(): void
    {
        $id = $this->seedItem(self::TENANT_A, 'SKU-1');
        $this->actAs(self::TENANT_A, []);

        $this->client()->setServerParameter('REMOTE_ADDR', '203.0.113.9');
        $this->send('GET', '/items/'.$id);
        self::assertSame(403, $this->httpStatus());

        $rows = $this->denialRows();
        self::assertCount(1, $rows, 'A denial reached the API and the trail has no row for it.');

        $resource = strstr(self::VIEW, '.', true);
        self::assertIsString($resource);
        self::assertArrayHasKey($resource, SchemaManifest::TABLES,
            'The code\'s resource half does not name a table, so `subject_type` could not be a table name.');

        self::assertSame(AuditEvent::AUTHZ_PERMISSION_DENIED, $rows[0]['event_type']);
        self::assertSame($this->actorId, $rows[0]['actor_user_id'], 'The actor is not the caller.');
        self::assertSame('Fixture Actor', $rows[0]['actor_label']);
        self::assertSame(self::VIEW, $rows[0]['permission_code'], 'E-3a\'s key column is empty on a denial row.');
        self::assertSame($resource, $rows[0]['subject_type']);
        self::assertSame($id, $rows[0]['subject_id']);
        self::assertSame('203.0.113.9', $rows[0]['ip_address']);
    }

    /**
     * The write is on the request's own connection and inside it — §9.2's
     * *"synchronously"*, and `erp_app` is the principal that holds
     * `SELECT, INSERT` on `audit_log` and no `UPDATE`.
     *
     * Recorded rather than inferred from the row existing afterwards: a write
     * dispatched to a queue would also produce a row eventually, and E-3a's
     * read-back cannot work at all on a principal that cannot read.
     *
     * *Mutation, run:* `DenialAuditWriter` rebound to
     * `doctrine.dbal.authlog_connection` in `services.yaml`. `erp_authlog` holds
     * `INSERT` and **no `SELECT`**, so the read-back raises `permission denied for
     * table audit_log` and this is red immediately — which is SEC-O-5(a)'s point
     * arriving as a test failure rather than as a design note.
     */
    #[Test]
    public function theDenialIsWrittenSynchronouslyOnTheApplicationConnection(): void
    {
        $id = $this->seedItem(self::TENANT_A, 'SKU-1');
        $this->actAs(self::TENANT_A, []);

        $this->recorder()->start();
        $this->send('GET', '/items/'.$id);
        $this->recorder()->stop();

        self::assertSame(403, $this->httpStatus());
        self::assertNotSame([], $this->recorder()->matching('/INSERT INTO audit_log/i'),
            "The denial row was not inserted on the application connection during the request:\n"
            .implode("\n", $this->recorder()->statements()));
        self::assertNotSame([], $this->recorder()->matching('/SELECT id, subject_id FROM audit_log/i'),
            'The cap read-back did not run, so the first row was written without consulting the cap at all.');
    }

    // -----------------------------------------------------------------------
    // E-3a — the cap
    // -----------------------------------------------------------------------

    /**
     * **The cap's key is `(tenant_id, actor_user_id, permission_code)` and the
     * resource is not in it.**
     *
     * Twenty distinct ids, one actor, one code: two rows, and the second names a
     * different resource from the first. §9.4a's `::theCapKeyIsTheActorAndCode`.
     *
     * The loop count is deliberately **not** derived from
     * `DenialAuditWriter::DAILY_ROWS_PER_KEY` — a loop that runs `CONSTANT` times
     * and asserts against `CONSTANT` is green at every value of `CONSTANT`, which
     * is the sixteenth fake-green this project found. The literal 20 and the
     * literal 2 are independent, and the pin below reconciles the 2 against E-3a's
     * own text.
     *
     * *Mutation, run:* `permission_code = ?` in `READ_BACK` replaced by
     * `subject_id = ?::uuid` (the cap keyed on the resource, which E-3a rejects by
     * name). One row per iteration — 20 rows — and this is red.
     *
     * *Second mutation, run:* the `count($seen) >= DAILY_ROWS_PER_KEY` guard
     * removed. Every iteration whose id differs from the first row's writes a row;
     * 19 rows, red.
     */
    #[Test]
    public function theCapKeyIsTheActorAndCodeAndNotTheResource(): void
    {
        $this->actAs(self::TENANT_A, []);

        $ids = [];
        for ($i = 0; $i < 20; ++$i) {
            $ids[] = Uuid::v4()->toRfc4122();
        }

        foreach ($ids as $id) {
            $this->send('GET', '/items/'.$id);
            self::assertSame(403, $this->httpStatus());
        }

        $rows = $this->denialRows();

        self::assertCount(2, $rows,
            'The cap did not hold at two rows per (tenant, actor, code) per UTC day. Rows: '.\count($rows));
        self::assertSame([self::VIEW, self::VIEW], array_column($rows, 'permission_code'));
        self::assertNotSame($rows[0]['subject_id'], $rows[1]['subject_id'],
            'The second row names the same resource as the first. It exists to record that a *second* '
            .'resource was tried, and E-3a says so in terms.');
        self::assertContains($rows[0]['subject_id'], $ids);
        self::assertContains($rows[1]['subject_id'], $ids);
    }

    /**
     * **E-3a's absolute prohibition: the id list is never written to `metadata`.**
     *
     * Not "is empty today" — the twenty ids the loop above denied are searched for
     * in every `metadata` value in the table, so a writer that started recording
     * them under any key at all fails.
     *
     * *Mutation, run:* the insert changed to write
     * `jsonb_build_object('subject_ids', ?::jsonb)` with the ids seen so far. Red
     * on the first id it finds.
     */
    #[Test]
    public function theIdListIsNeverWrittenToMetadata(): void
    {
        $this->actAs(self::TENANT_A, []);

        $ids = [];
        for ($i = 0; $i < 8; ++$i) {
            $ids[] = Uuid::v4()->toRfc4122();
            $this->send('GET', '/items/'.end($ids));
        }

        $metadata = array_column($this->denialRows(), 'metadata');
        self::assertNotSame([], $metadata, 'No denial row was written, so this assertion had no subject.');

        foreach ($metadata as $value) {
            self::assertSame('{}', $value,
                'A denial row carries metadata. PC-11 reads `metadata` as colleague-visible content and '
                .'E-3a forbids the resource list living there.');
        }

        // The prohibition, checked against the whole table rather than against
        // the rows this test expects: a writer that put the list on some *other*
        // row would pass the clause above.
        $everything = json_encode($this->trail(), \JSON_THROW_ON_ERROR);
        foreach ($ids as $index => $id) {
            if ($index < 2) {
                continue; // the two rows the cap does write name a resource, legitimately
            }
            self::assertStringNotContainsString($id, $everything,
                'A resource id the cap suppressed appears in the trail, so the enumeration the cap exists '
                .'to bound is being rebuilt somewhere in it.');
        }
    }

    /**
     * A second code is a second key: the cap is per code, so `RED`'s
     * lateral-movement signature — the count of distinct `permission_code` values
     * for one actor in one day — is unchanged by the cap.
     *
     * E-3a states that property in terms and it is the reason `permission_code`
     * stays in the key.
     */
    #[Test]
    public function eachPermissionCodeGetsItsOwnDailyAllowance(): void
    {
        $this->actAs(self::TENANT_A, []);

        for ($i = 0; $i < 5; ++$i) {
            $this->send('GET', '/items/'.Uuid::v4()->toRfc4122());
            $this->send('DELETE', '/items/'.Uuid::v4()->toRfc4122());
        }

        $byCode = [];
        foreach ($this->denialRows() as $row) {
            $byCode[(string) $row['permission_code']] = 1 + ($byCode[(string) $row['permission_code']] ?? 0);
        }

        ksort($byCode);
        self::assertSame([self::DELETE => 2, self::VIEW => 2], $byCode,
            'The cap is not per permission code, so the distinct-code signature is not preserved.');
    }

    /**
     * The cap is per UTC **day**, and yesterday's rows for the same key do not
     * consume today's allowance.
     *
     * The prior rows are seeded rather than driven, because a request cannot be
     * made to happen yesterday. `DenialCapStorageTest::theDayBoundExcludesYesterdaysRowsForTheSameKey`
     * asserts the same bound on the probe; this asserts it on the writer, which is
     * where a missing lower bound would actually silence today's trail.
     *
     * *Mutation, run:* both `occurred_at` predicates removed from `READ_BACK`. The
     * two seeded rows are then found, today's denial writes nothing, and this is
     * red.
     */
    #[Test]
    public function yesterdaysRowsDoNotConsumeTodaysAllowance(): void
    {
        $this->actAs(self::TENANT_A, []);

        foreach ([Uuid::v4()->toRfc4122(), Uuid::v4()->toRfc4122()] as $old) {
            $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                INSERT INTO audit_log (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label,
                                       subject_type, subject_id, permission_code)
                VALUES (gen_random_uuid(), ?::uuid,
                        date_trunc('day', now() AT TIME ZONE 'UTC') AT TIME ZONE 'UTC' - interval '3 hours',
                        ?, ?::uuid, 'Fixture Actor', 'inventory_item', ?::uuid, ?)
                SQL, [self::TENANT_A, AuditEvent::AUTHZ_PERMISSION_DENIED, (string) $this->actorId, $old, self::VIEW]);
        }

        self::assertCount(2, $this->denialRows(), 'The yesterday fixture did not land, so the assertion below is about nothing.');

        $this->send('GET', '/items/'.Uuid::v4()->toRfc4122());
        self::assertSame(403, $this->httpStatus());

        self::assertCount(3, $this->denialRows(),
            'Today\'s first denial for this key was suppressed by rows from a previous UTC day.');
    }

    // -----------------------------------------------------------------------
    // §9.4a — the read-back's plan shape, measured on the statement the writer runs
    // -----------------------------------------------------------------------

    /**
     * **The bound is on work, so the work is what is measured.**
     *
     * All three of §9.4a's clauses, on `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)`
     * **per node**: the scan node is `Index Scan using audit_log_denial_cap_idx`,
     * its `Index Cond` names all four key columns, and it carries no `Filter` key
     * at all.
     *
     * **Per node, and in JSON, for a named reason.** `READ_BACK` leaves `tenant_id`
     * to the RLS policy, so the plan carries no `Result` node — but a read-back
     * that wrote `tenant_id = ?` itself would, and PostgreSQL folds the policy into
     * a `One-Time Filter:` there. That string contains `Filter:`, so a **text**
     * assertion written to §9.4a's wording fails on a correct plan. In `FORMAT
     * JSON` they are different keys on different nodes and cannot be confused.
     * `DenialCapStorageTest::theExplicitTenantPredicateFormAddsAOneTimeFilterNode`
     * is where that was measured.
     *
     * **Anti-vacuity, §9.4a's third mutation:** the fixture is asserted to be the
     * world it claims to be before any plan is read. A probe over an empty table
     * satisfies every clause here trivially.
     *
     * *Mutation, run:* `permission_code = ?` in `READ_BACK` replaced by
     * `metadata->>'permission_code' = ?` — §9.4a's plan A, the design this column
     * replaced. Red on all three clauses at once.
     */
    #[Test]
    public function theWritersOwnReadBackIsServedByTheDenialCapIndexWithNoFilter(): void
    {
        $actor = $this->seedPlanFixture();

        $plan = $this->planFor(DenialAuditWriter::READ_BACK,
            [$actor, self::VIEW, AuditEvent::AUTHZ_PERMISSION_DENIED]);

        $scan = self::findNode($plan, 'Index Scan');
        self::assertNotNull($scan,
            'The writer\'s read-back is not an index scan at all. Node types: '.implode(', ', self::nodeTypes($plan)));

        self::assertSame('audit_log_denial_cap_idx', $scan['Index Name'],
            'The read-back used '.$scan['Index Name'].'. Only the denial-cap index carries all three equality '
            .'columns of E-3a\'s key, so anything else is scanning the actor\'s whole day.');

        foreach (['tenant_id', 'actor_user_id', 'permission_code', 'occurred_at'] as $column) {
            self::assertStringContainsString($column, (string) $scan['Index Cond'],
                $column." is not an index condition, so it is applied after rows are read:\n".$scan['Index Cond']);
        }

        self::assertArrayNotHasKey('Filter', $scan,
            "The scan node carries a Filter, so rows are being read and discarded rather than not read:\n"
            .($scan['Filter'] ?? ''));
        self::assertSame(0, $scan['Rows Removed by Filter'] ?? 0);

        $buffers = ($scan['Shared Hit Blocks'] ?? 0) + ($scan['Shared Read Blocks'] ?? 0);
        self::assertLessThan(100, $buffers,
            "The probe read $buffers buffers against a fixture of thousands of rows. E-3a's bound is on work "
            .'done, and that is the fixture\'s order of magnitude rather than the ceiling\'s.');
    }

    /**
     * E-3a's ceiling is **two**, and the figure is checked against the register
     * rather than against the constant that implements it.
     *
     * `SecurityModelDocument`'s own docblock records why: the first version of two
     * other tests drove their loops from the constants under test, and both
     * mutations survived. The literal 2 here and `DAILY_ROWS_PER_KEY` are
     * independent, and E-3a's row is the third side.
     */
    #[Test]
    public function theCeilingThisWriterEnforcesIsTheOneTheRegisterStates(): void
    {
        self::assertSame(2, DenialAuditWriter::DAILY_ROWS_PER_KEY);
        self::assertStringContainsString('LIMIT 2', DenialAuditWriter::READ_BACK,
            'The read-back stops at a different number of matches from the one the writer caps at.');

        if (!SecurityModelDocument::isAvailable()) {
            self::markTestSkipped('docs/SECURITY_MODEL.md is gitignored and absent from this checkout, so the figure is checked against the code only. See the enforcement-slice report.');
        }

        $row = SecurityModelDocument::enforcementRow('E-3a');
        self::assertNotSame('', $row, 'E-3a has left the register, so the figure above has no source.');
        self::assertStringContainsString(
            'At most two `authz.permission.denied` rows per `(tenant_id, actor_user_id, permission_code)` per UTC day',
            $row,
            'E-3a no longer states the ceiling or the key this writer enforces.');
    }

    // -----------------------------------------------------------------------

    /** @return list<array<string, mixed>> every `authz.permission.denied` row in tenant A, oldest first */
    private function denialRows(): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT event_type, actor_user_id::text, actor_label, subject_type, subject_id::text,
                   host(ip_address) AS ip_address, permission_code, metadata::text AS metadata
            FROM audit_log WHERE event_type = ? ORDER BY occurred_at, id
            SQL, [AuditEvent::AUTHZ_PERMISSION_DENIED]);
    }

    /** @return list<array<string, mixed>> the whole trail, for the `metadata` prohibition */
    private function trail(): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT event_type, subject_id::text, permission_code, metadata::text AS metadata FROM audit_log');
    }

    /**
     * The world the plan is measured against: one actor's denials for today spread
     * over the catalogue's worth of codes, plus the business rows the partial index
     * excludes. `ANALYZE` is an owner operation — without it the planner works from
     * defaults and the plan is a measurement of a guess.
     *
     * @return string the fixture actor's id
     */
    private function seedPlanFixture(): string
    {
        $actor = Uuid::v4()->toRfc4122();

        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO app_user (id, tenant_id, email, password_hash, display_name)
            VALUES (?::uuid, ?::uuid, 'plan-probe@example.test', 'not-a-hash', 'Plan Probe')
            SQL, [$actor, self::TENANT_A]);

        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO audit_log (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label, permission_code)
            SELECT gen_random_uuid(), ?::uuid,
                   date_trunc('day', now() AT TIME ZONE 'UTC') AT TIME ZONE 'UTC' + (g || ' milliseconds')::interval,
                   ?, ?::uuid, 'Plan Probe',
                   CASE WHEN g % ? = 0 THEN ? ELSE 'other.code_' || (g % ?) END
            FROM generate_series(1, ?) g
            SQL, [self::TENANT_A, AuditEvent::AUTHZ_PERMISSION_DENIED, $actor,
                self::PLAN_FIXTURE_CODES, self::VIEW, self::PLAN_FIXTURE_CODES, self::PLAN_FIXTURE_DENIALS]);

        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO audit_log (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label)
            SELECT gen_random_uuid(), ?::uuid,
                   date_trunc('day', now() AT TIME ZONE 'UTC') AT TIME ZONE 'UTC' + (g || ' milliseconds')::interval,
                   ?, ?::uuid, 'Plan Probe'
            FROM generate_series(1, ?) g
            SQL, [self::TENANT_A, AuditEvent::PURCHASE_ORDER_PLACED, $actor, self::PLAN_FIXTURE_BUSINESS]);

        $this->owner()->executeStatement('ANALYZE audit_log');

        // §9.4a's third mutation: the fixture is shown to be the world it claims
        // to be, read back through `erp_app` under the same policy the probe runs
        // under, before any plan is read.
        $counts = [
            'denials' => (int) $this->fetchAsOwnerForTenant(self::TENANT_A,
                'SELECT count(*) AS n FROM audit_log WHERE event_type = ? AND actor_user_id = ?::uuid',
                [AuditEvent::AUTHZ_PERMISSION_DENIED, $actor])[0]['n'],
            'this_key' => (int) $this->fetchAsOwnerForTenant(self::TENANT_A,
                'SELECT count(*) AS n FROM audit_log WHERE event_type = ? AND actor_user_id = ?::uuid AND permission_code = ?',
                [AuditEvent::AUTHZ_PERMISSION_DENIED, $actor, self::VIEW])[0]['n'],
            'business' => (int) $this->fetchAsOwnerForTenant(self::TENANT_A,
                'SELECT count(*) AS n FROM audit_log WHERE event_type = ?',
                [AuditEvent::PURCHASE_ORDER_PLACED])[0]['n'],
        ];

        self::assertSame([
            'denials' => self::PLAN_FIXTURE_DENIALS,
            'this_key' => intdiv(self::PLAN_FIXTURE_DENIALS, self::PLAN_FIXTURE_CODES),
            'business' => self::PLAN_FIXTURE_BUSINESS,
        ], $counts, 'The fixture is not the world this plan is measured against, so the plan is about nothing.');

        self::assertGreaterThan(DenialAuditWriter::DAILY_ROWS_PER_KEY, $counts['this_key'],
            'The key\'s daily rows are already within the ceiling, so an unbounded read would be cheap and '
            .'the index would be cost without benefit — the plan below would prove nothing.');

        return $actor;
    }

    /**
     * @param list<mixed> $params
     *
     * @return array<string, mixed> the plan tree's root node
     */
    private function planFor(string $sql, array $params): array
    {
        $json = $this->tenantContext()->run(self::TENANT_A, fn (): string => (string) $this->app()->fetchOne(
            'EXPLAIN (ANALYZE, BUFFERS, COSTS OFF, FORMAT JSON) '.$sql, $params));

        $text = $this->tenantContext()->run(self::TENANT_A, fn (): string => implode("\n",
            $this->app()->fetchFirstColumn('EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) '.$sql, $params)));
        fwrite(\STDERR, "\n  [EXPLAIN] DenialAuditWriter::READ_BACK\n    ".str_replace("\n", "\n    ", $text)."\n");

        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));

        return $decoded[0]['Plan'];
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>|null
     */
    private static function findNode(array $node, string $type): ?array
    {
        if ($node['Node Type'] === $type) {
            return $node;
        }

        foreach ($node['Plans'] ?? [] as $child) {
            if (null !== $found = self::findNode($child, $type)) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return list<string>
     */
    private static function nodeTypes(array $node): array
    {
        $types = [$node['Node Type']];

        foreach ($node['Plans'] ?? [] as $child) {
            $types = array_merge($types, self::nodeTypes($child));
        }

        return $types;
    }
}

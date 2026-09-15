<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Modules\AuditLog\Infrastructure\Entity\AuditLogEntry;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * E4-2 / API-O-10 — the storage E-3a's cap needs, and the proof that the probe
 * it enables is actually bounded.
 *
 * `SECURITY_MODEL.md` E-3a caps `authz.permission.denied` at two rows per
 * `(tenant_id, actor_user_id, permission_code)` per UTC day, enforced by reading
 * back at most two matching rows for that key before writing. `API.md` §9.4a is
 * the contract for that read-back and its `fails if:` rider ends on a **plan
 * shape**: an index scan on `audit_log_denial_cap_idx`, all four key columns in
 * its `Index Cond`, and no `Filter:` line.
 *
 * ---------------------------------------------------------------------------
 * Why the assertion is on the plan and not on anything easier to read
 * ---------------------------------------------------------------------------
 * §9.4a records two fake-greens on this one check, and both are worth restating
 * because this file exists to not be the third.
 *
 * **Statement text.** `… WHERE tenant_id = ? AND actor_user_id = ? AND
 * occurred_at >= ? AND metadata->>'permission_code' = ? LIMIT 2` carries a day
 * predicate, carries a row limit and is not an aggregate — so it satisfies every
 * clause of a text assertion while sequentially scanning the whole table. An
 * assertion about SQL text is not an assertion about work done.
 *
 * **`actual rows`.** Under `LIMIT 2` the planner reports 2 rows on every node of
 * *both* plans, because the limit caps them by construction. Measured again
 * here, not taken on trust: `theSameReadBackOverMetadataIsUnbounded` below
 * builds the rejected plan and its scan node also returns 2.
 *
 * What separates them is the work: one node reads four buffers, the other reads
 * hundreds and reports `Rows Removed by Filter` in the thousands.
 *
 * ---------------------------------------------------------------------------
 * `FORMAT JSON`, and why that is not a stylistic preference
 * ---------------------------------------------------------------------------
 * The plans below are read as JSON and asserted per node. Text `EXPLAIN` cannot
 * express "this node has no `Filter:`" safely, and the reason is concrete and
 * measured here: when the read-back writes `tenant_id = ?` itself, the RLS
 * policy's own predicate folds into a `Result` node above the scan carrying
 * **`One-Time Filter:`** — which contains the substring `Filter:`. A
 * `assertStringNotContainsString('Filter:', $plan)` written against §9.4a's
 * wording therefore fails on a plan that is completely correct, and the obvious
 * repair is to loosen the assertion. `theExplicitTenantPredicateFormAddsAOne`
 * `TimeFilterNode` pins that hazard so phase 2 meets it as a documented fact
 * rather than as a puzzling red.
 *
 * In JSON the two are different keys on different nodes: `One-Time Filter` on
 * the `Result`, `Filter` on the `Index Scan`. The clause §9.4a means is the
 * second one, and only the second one.
 *
 * ---------------------------------------------------------------------------
 * The fixture size is pinned, and it is NOT §9.4a's figure
 * ---------------------------------------------------------------------------
 * §9.4a pins its fixture at "fifty times E-3a's ceiling" — 100 rows. **Measured
 * on this PostgreSQL 17.11, that figure makes the specified assertion fail with
 * the correct index in place:** 100 rows is two heap pages, so the planner
 * chooses a `Seq Scan` and the plan-shape check goes red on all three clauses
 * for a reason that has nothing to do with the property it asserts. The
 * changeover measured here is between 150 and 200 rows; 100 and 150 seq-scan,
 * 200 upward use the cap index.
 *
 * A *fake-red* is not better than a fake-green. It gets closed by weakening the
 * assertion, which is how the check that survives ends up proving nothing. So
 * the figure here is 10,000 — which is also the size §9.4a's own measured plan A
 * reports (`Rows Removed by Filter: 9997`), so the contract's evidence and its
 * stated fixture size already disagree with each other. Raised for phase 2 to
 * settle in §9.4a; this file states what it measured and why it chose otherwise
 * rather than silently following either number.
 *
 * @see SECURITY_MODEL.md §5 E-3, E-3a; API.md §9.4, §9.4a, API-O-10
 * @see DELIVERY_LEDGER.md Output 4 entry condition E4-2
 * @see \DoctrineMigrations\Version20260909000200 for every ruling this asserts
 */
final class DenialCapStorageTest extends TenancyTestCase
{
    private const INDEX = 'audit_log_denial_cap_idx';
    private const CONSTRAINT = 'audit_log_permission_code_scope';

    private const ACTOR = 'aaaaaaaa-1111-4111-8111-111111111111';
    private const OTHER_ACTOR = 'aaaaaaaa-2222-4222-8222-222222222222';

    /** The key the probes below use. Any text value; there is no foreign key, by ruling. */
    private const CODE = 'inventory_item.view';

    /**
     * Denial rows for the fixture actor, today. See the class docblock: this is
     * deliberately not §9.4a's 100, which seq-scans.
     */
    private const DENIALS_TODAY = 10000;

    /** Distinct permission codes the denials are spread over -- §3's catalogue is 60. */
    private const CODES = 60;

    /** Business-event rows for the same actor on the same day: what the partial index excludes. */
    private const BUSINESS_TODAY = 10000;

    /** Same actor, same code, yesterday. The rows the day bound must exclude. */
    private const DENIALS_YESTERDAY = 500;

    /** A second actor, same code, today. The rows actor_user_id must exclude. */
    private const DENIALS_OTHER_ACTOR = 500;

    private bool $seeded = false;

    /**
     * The probe E-3a specifies, in the form that leaves `tenant_id` to the RLS
     * policy -- which is how every read on this connection is written, because
     * `TenantContext` sets the GUC and the policy supplies the predicate.
     *
     * `event_type` is in the WHERE clause and is not decoration: PostgreSQL
     * proves a partial index usable from the query's own restriction clauses and
     * does not consult table CHECK constraints to do it, so a read-back that
     * omits it cannot use this index at all.
     */
    private const PROBE = <<<'SQL'
        SELECT id, subject_id FROM audit_log
        WHERE actor_user_id = ?::uuid
          AND permission_code = ?
          AND event_type = ?
          AND occurred_at >= date_trunc('day', now() AT TIME ZONE 'UTC') AT TIME ZONE 'UTC'
          AND occurred_at <  date_trunc('day', now() AT TIME ZONE 'UTC') AT TIME ZONE 'UTC' + interval '1 day'
        LIMIT 2
        SQL;

    /**
     * Revision 1's read-back: the same statement with the cap's third column
     * living in `metadata`. Text-identical in every property a statement
     * assertion can see -- day predicate, row limit, not an aggregate.
     */
    private const METADATA_PROBE = <<<'SQL'
        SELECT id, subject_id FROM audit_log
        WHERE actor_user_id = ?::uuid
          AND metadata->>'permission_code' = ?
          AND event_type = ?
          AND occurred_at >= date_trunc('day', now() AT TIME ZONE 'UTC') AT TIME ZONE 'UTC'
          AND occurred_at <  date_trunc('day', now() AT TIME ZONE 'UTC') AT TIME ZONE 'UTC' + interval '1 day'
        LIMIT 2
        SQL;

    private function seedDenials(): void
    {
        if ($this->seeded) {
            return;
        }

        // As the owner: no `src/` path creates a user, so `erp_app` holds no
        // INSERT on app_user (ADR-002 Amendment 4).
        foreach ([[self::ACTOR, 'probe'], [self::OTHER_ACTOR, 'other']] as [$id, $slug]) {
            $this->insertAsOwnerForTenant(self::TENANT_A,
                'INSERT INTO app_user (id, tenant_id, email, password_hash, display_name) VALUES (?::uuid, ?::uuid, ?, ?, ?)',
                [$id, self::TENANT_A, $slug.'@example.test', 'not-a-hash', ucfirst($slug)],
            );
        }

        $this->tenantContext()->run(self::TENANT_A, function (): void {

            // Today's denials, spread over the catalogue's worth of codes. The
            // `g % CODES` distribution is what makes permission_code a real
            // discriminator rather than a column with one value in it.
            //
            // `metadata` carries the same code as well as the column, on
            // purpose: that is the rejected design -- `API.md` §9.4a's plan A --
            // and `theSameReadBackOverMetadataIsUnbounded` cannot compare the
            // two plans unless the rejected one can actually find its rows. It
            // is a fixture reproducing a design this schema refuses, not a
            // pattern for any writer: PC-11 reads `metadata` as
            // colleague-visible content and E-3a's key does not live there.
            $this->app()->executeStatement(<<<'SQL'
                INSERT INTO audit_log (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label, permission_code, metadata)
                SELECT gen_random_uuid(), ?::uuid,
                       date_trunc('day', now() AT TIME ZONE 'UTC') AT TIME ZONE 'UTC' + (g || ' milliseconds')::interval,
                       ?, ?::uuid, 'Probe',
                       CASE WHEN g % ? = 0 THEN ? ELSE 'other.code.' || (g % ?) END,
                       jsonb_build_object('permission_code',
                           CASE WHEN g % ? = 0 THEN ? ELSE 'other.code.' || (g % ?) END)
                FROM generate_series(1, ?) g
                SQL, [self::TENANT_A, AuditEvent::AUTHZ_PERMISSION_DENIED, self::ACTOR,
                    self::CODES, self::CODE, self::CODES,
                    self::CODES, self::CODE, self::CODES, self::DENIALS_TODAY]);

            // The rows the partial predicate excludes. The project's own fixture
            // models audit_log as ~98% business events; without them here the
            // partial index would be doing nothing the total index could not.
            $this->app()->executeStatement(<<<'SQL'
                INSERT INTO audit_log (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label)
                SELECT gen_random_uuid(), ?::uuid,
                       date_trunc('day', now() AT TIME ZONE 'UTC') AT TIME ZONE 'UTC' + (g || ' milliseconds')::interval,
                       ?, ?::uuid, 'Probe'
                FROM generate_series(1, ?) g
                SQL, [self::TENANT_A, AuditEvent::PURCHASE_ORDER_PLACED, self::ACTOR, self::BUSINESS_TODAY]);

            // Same actor, same code, yesterday: the rows the day bound excludes.
            $this->app()->executeStatement(<<<'SQL'
                INSERT INTO audit_log (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label, permission_code)
                SELECT gen_random_uuid(), ?::uuid,
                       date_trunc('day', now() AT TIME ZONE 'UTC') AT TIME ZONE 'UTC' - interval '1 day' + (g || ' milliseconds')::interval,
                       ?, ?::uuid, 'Probe', ?
                FROM generate_series(1, ?) g
                SQL, [self::TENANT_A, AuditEvent::AUTHZ_PERMISSION_DENIED, self::ACTOR, self::CODE, self::DENIALS_YESTERDAY]);

            // A second actor on the same code today: the rows actor_user_id excludes.
            $this->app()->executeStatement(<<<'SQL'
                INSERT INTO audit_log (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label, permission_code)
                SELECT gen_random_uuid(), ?::uuid,
                       date_trunc('day', now() AT TIME ZONE 'UTC') AT TIME ZONE 'UTC' + (g || ' milliseconds')::interval,
                       ?, ?::uuid, 'Other', ?
                FROM generate_series(1, ?) g
                SQL, [self::TENANT_A, AuditEvent::AUTHZ_PERMISSION_DENIED, self::OTHER_ACTOR, self::CODE, self::DENIALS_OTHER_ACTOR]);
        });

        // ANALYZE is an owner operation. Without it the planner works from
        // defaults and every plan below is a measurement of a guess.
        $this->owner()->executeStatement('ANALYZE audit_log');

        $this->seeded = true;
    }

    /**
     * §9.4a's third mutation, the anti-vacuity probe: a plan over an empty table
     * satisfies every clause of the shape assertion trivially, so the fixture has
     * to be shown to be the world it claims to be before any plan is read.
     *
     * The counts are read back through `erp_app` under RLS -- the same principal
     * and the same policy the probes run under -- so a seed that silently landed
     * in no tenant, or landed and is invisible, fails here rather than two
     * assertions later.
     */
    private function assertFixtureIsWhatItClaims(): void
    {
        $counts = $this->tenantContext()->run(self::TENANT_A, fn (): array => [
            'denials_today' => (int) $this->app()->fetchOne(
                "SELECT count(*) FROM audit_log WHERE event_type = ? AND actor_user_id = ?::uuid
                 AND occurred_at >= date_trunc('day', now() AT TIME ZONE 'UTC') AT TIME ZONE 'UTC'",
                [AuditEvent::AUTHZ_PERMISSION_DENIED, self::ACTOR]),
            'this_key_today' => (int) $this->app()->fetchOne(
                "SELECT count(*) FROM audit_log WHERE event_type = ? AND actor_user_id = ?::uuid AND permission_code = ?
                 AND occurred_at >= date_trunc('day', now() AT TIME ZONE 'UTC') AT TIME ZONE 'UTC'",
                [AuditEvent::AUTHZ_PERMISSION_DENIED, self::ACTOR, self::CODE]),
            'this_key_yesterday' => (int) $this->app()->fetchOne(
                "SELECT count(*) FROM audit_log WHERE event_type = ? AND actor_user_id = ?::uuid AND permission_code = ?
                 AND occurred_at < date_trunc('day', now() AT TIME ZONE 'UTC') AT TIME ZONE 'UTC'",
                [AuditEvent::AUTHZ_PERMISSION_DENIED, self::ACTOR, self::CODE]),
            'business_today' => (int) $this->app()->fetchOne(
                'SELECT count(*) FROM audit_log WHERE event_type = ?', [AuditEvent::PURCHASE_ORDER_PLACED]),
        ]);

        self::assertSame([
            'denials_today' => self::DENIALS_TODAY,
            'this_key_today' => intdiv(self::DENIALS_TODAY, self::CODES),
            'this_key_yesterday' => self::DENIALS_YESTERDAY,
            'business_today' => self::BUSINESS_TODAY,
        ], $counts, 'The fixture is not the world these plans are measured against, so every plan below is about nothing.');

        // More than the two rows the cap returns, by a wide margin. Stated as
        // its own assertion because it is the whole reason a bound matters: if
        // the key's daily rows were already few, the unbounded read would be
        // cheap and this index would be cost without benefit.
        self::assertGreaterThan(2, $counts['this_key_today']);
    }

    /**
     * @return array<string, mixed> the plan tree's root node
     */
    private function planNode(string $sql, array $params, string $label): array
    {
        $this->seedDenials();

        $json = $this->tenantContext()->run(self::TENANT_A, fn (): string => (string) $this->app()->fetchOne(
            'EXPLAIN (ANALYZE, BUFFERS, COSTS OFF, FORMAT JSON) '.$sql, $params));

        $text = $this->tenantContext()->run(self::TENANT_A, fn (): string => implode("\n",
            $this->app()->fetchFirstColumn('EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) '.$sql, $params)));
        fwrite(STDERR, "\n  [EXPLAIN] $label\n    ".str_replace("\n", "\n    ", $text)."\n");

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $decoded[0]['Plan'];
    }

    /** The first node of the given type anywhere in the tree, or null. */
    private function findNode(array $node, string $type): ?array
    {
        if ($node['Node Type'] === $type) {
            return $node;
        }

        foreach ($node['Plans'] ?? [] as $child) {
            if (null !== $found = $this->findNode($child, $type)) {
                return $found;
            }
        }

        return null;
    }

    /** Every node type in the tree, root first. @return list<string> */
    private function nodeTypes(array $node): array
    {
        $types = [$node['Node Type']];
        foreach ($node['Plans'] ?? [] as $child) {
            $types = array_merge($types, $this->nodeTypes($child));
        }

        return $types;
    }

    // -----------------------------------------------------------------------
    // The storage itself, read back from the catalogue rather than restated
    // -----------------------------------------------------------------------

    /**
     * The column exists, is `text`, and is nullable.
     *
     * Nullable is the ruling, not an oversight: only `authz.permission.denied`
     * rows carry a code, `NULL` is PostgreSQL's word for "not applicable", and a
     * sentinel would put the cap's key back into the class of values a writer can
     * spell differently -- which is the argument that disqualified `metadata`.
     *
     * **Mutation:** change the migration to `permission_code text NOT NULL
     * DEFAULT ''`; `attnotnull` becomes true and this goes red.
     */
    #[Test]
    public function thePermissionCodeColumnIsNullableTextOnAuditLog(): void
    {
        $column = $this->owner()->fetchAssociative(<<<'SQL'
            SELECT format_type(a.atttypid, a.atttypmod) AS typ, a.attnotnull, a.atthasdef
            FROM pg_attribute a
            WHERE a.attrelid = 'audit_log'::regclass AND a.attname = 'permission_code'
              AND a.attnum > 0 AND NOT a.attisdropped
            SQL);

        self::assertNotFalse($column,
            'audit_log has no permission_code column, so E-3a\'s cap key has no third column and the '
            .'read-back cannot be bounded. That is API-O-10 and entry condition E4-2.');
        self::assertSame('text', $column['typ']);
        self::assertFalse((bool) $column['attnotnull'],
            'permission_code is NOT NULL, which forces every non-denial event to carry a sentinel.');
        self::assertFalse((bool) $column['atthasdef'],
            'permission_code has a DEFAULT, which is a sentinel wearing a different hat.');
    }

    /**
     * The index, derived from `pg_index` -- its key columns in order, its
     * partial predicate, and its validity. Read from the catalogue rather than
     * from `pg_get_indexdef`'s string, so column *order* is asserted rather than
     * pattern-matched.
     *
     * `indisvalid` is not decoration. The migration builds this index with
     * `CREATE INDEX CONCURRENTLY`; a failed concurrent build leaves an index of
     * the right name that no planner will use, which is a fake-green in DDL
     * form. This is the check that catches it.
     *
     * **Mutation:** reorder the migration's column list to put `occurred_at`
     * before `permission_code`; red on the column assertion. Drop the `WHERE`
     * clause; red on the predicate assertion.
     */
    #[Test]
    public function theCapIndexIsTheFourColumnPartialIndexTheProbeNeeds(): void
    {
        $index = $this->owner()->fetchAssociative(<<<'SQL'
            SELECT (SELECT array_agg(a.attname ORDER BY k.ord)
                    FROM unnest(x.indkey) WITH ORDINALITY AS k(attnum, ord)
                    JOIN pg_attribute a ON a.attrelid = x.indrelid AND a.attnum = k.attnum) AS cols,
                   x.indisvalid, x.indisready, x.indisunique,
                   pg_get_expr(x.indpred, x.indrelid) AS predicate,
                   am.amname
            FROM pg_index x
            JOIN pg_class i ON i.oid = x.indexrelid
            JOIN pg_am am ON am.oid = i.relam
            WHERE x.indexrelid = ?::regclass
            SQL, [self::INDEX]);

        self::assertNotFalse($index, self::INDEX.' does not exist.');
        self::assertSame('{tenant_id,actor_user_id,permission_code,occurred_at}', $index['cols'],
            'The cap index does not carry E-3a\'s key in the order the probe needs. tenant_id leads '
            .'(ADR-002 §5), the three equalities come next, and the one range column comes last -- a '
            .'range column ahead of an equality ends the usable index prefix.');
        self::assertSame('btree', $index['amname']);
        self::assertFalse((bool) $index['indisunique'],
            'The cap index is unique, which would refuse E-3a\'s own second row.');
        self::assertTrue((bool) $index['indisvalid'],
            self::INDEX.' exists but is invalid -- a failed CREATE INDEX CONCURRENTLY leaves exactly this: '
            .'the name is there and no plan will use it.');
        self::assertTrue((bool) $index['indisready']);
        self::assertSame(
            "(event_type = '".AuditEvent::AUTHZ_PERMISSION_DENIED."'::text)",
            $index['predicate'],
            'The cap index is not partial on the denial event type, so it is proportional to a table '
            .'the project\'s own fixture models as 98% business events.');
    }

    /**
     * The `CHECK`, behaviourally: a permission code on an event that is not a
     * denial is refused, and refused **by this constraint** rather than by a
     * grant, a NOT NULL or a type error. A test that only asserts "the insert
     * failed" cannot tell those apart -- `AuditEventCatalogueTest` makes the
     * same point about `audit_log_event_type_values`.
     *
     * **Mutation:** drop `audit_log_permission_code_scope` from the migration;
     * the insert succeeds, `$thrown` is null, red on the first assertion.
     */
    #[Test]
    public function aPermissionCodeOnANonDenialEventIsRefusedByTheNamedConstraint(): void
    {
        $thrown = null;

        try {
            $this->tenantContext()->run(self::TENANT_A, function (): void {
                $this->app()->executeStatement(
                    'INSERT INTO audit_log (id, tenant_id, event_type, actor_label, permission_code) VALUES (?::uuid, ?::uuid, ?, ?, ?)',
                    [Uuid::v4()->toRfc4122(), self::TENANT_A, AuditEvent::PURCHASE_ORDER_PLACED, 'system', self::CODE],
                );
            });
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown,
            'A permission code was accepted on a business event. The cap\'s key column has no domain, and '
            .'the partial index would be indexing rows nobody classified.');
        self::assertStringContainsString(self::CONSTRAINT, $thrown->getMessage(),
            'The insert failed, but not because of '.self::CONSTRAINT.': '.$thrown->getMessage());
    }

    /**
     * The other direction, asserted **as a decision** rather than left implicit.
     *
     * A denial row carrying no permission code is accepted. That is not an
     * oversight and it is not free: such a row is invisible to the cap's key, so
     * the cap does not bound it. It is chosen because the alternative -- the
     * biconditional `(permission_code IS NOT NULL) = (event_type = '…denied')` --
     * makes the database **refuse an audit write on the enforcement path**, and
     * E-3 requires every denial to be recorded. Losing the record is worse than
     * losing the bound.
     *
     * If a later pass adds the biconditional, this test goes red and its failure
     * message is where that decision gets re-argued. Requiring the code needs a
     * per-event declaration on `App\SharedKernel\ValueObject\AuditEvent`, which is a catalogue
     * extension, which is **E4-1's**, in five ordered steps.
     */
    #[Test]
    public function aDenialRowWithNoPermissionCodeIsAcceptedAndThatIsARuling(): void
    {
        $this->tenantContext()->run(self::TENANT_A, function (): void {
            $this->app()->executeStatement(
                'INSERT INTO audit_log (id, tenant_id, event_type, actor_label) VALUES (?::uuid, ?::uuid, ?, ?)',
                [Uuid::v4()->toRfc4122(), self::TENANT_A, AuditEvent::AUTHZ_PERMISSION_DENIED, 'system'],
            );
        });

        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM audit_log WHERE event_type = ? AND permission_code IS NULL',
            [AuditEvent::AUTHZ_PERMISSION_DENIED]),
            'A denial with no permission code was refused. That is the biconditional CHECK, and it trades '
            .'E-3\'s record away for E-3a\'s bound -- see this test\'s docblock and Version20260909000200.');
    }

    /**
     * `AuditLogEntry` refuses the direction the database refuses, at
     * construction, for the reason its own subject-pair guard gives: a raw 23514
     * at flush names a constraint and not a cause.
     *
     * **Mutation:** delete the guard from the constructor; red.
     */
    #[Test]
    public function theEntityRefusesAPermissionCodeOnANonDenialEvent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote(self::CONSTRAINT, '/').'/');

        new AuditLogEntry(
            Uuid::v4(),
            Uuid::fromString(self::TENANT_A),
            new \DateTimeImmutable(),
            AuditEvent::PURCHASE_ORDER_PLACED,
            'system',
            null, null, null, null, [],
            self::CODE,
        );
    }

    // -----------------------------------------------------------------------
    // The plan shape -- §9.4a's actual requirement
    // -----------------------------------------------------------------------

    /**
     * §9.4a's three clauses, asserted per node: the scan node is an `Index Scan`
     * on `audit_log_denial_cap_idx`; its `Index Cond` names **all four** key
     * columns; and it carries **no `Filter`** at all.
     *
     * The corroborating pair §9.4a asks for alongside, because they degrade
     * gracefully if a future planner renders the shape differently:
     * `Rows Removed by Filter` absent or zero, and buffers in the ceiling's order
     * of magnitude rather than the fixture's.
     *
     * **Mutation, and it is the one both earlier revisions failed:** drop
     * `audit_log_denial_cap_idx`. Statement text is unchanged, `actual rows` is
     * still 2, and this goes red on all three clauses at once -- measured: the
     * probe falls back to `audit_log_tenant_event_idx` with
     * `Filter: (actor_user_id = … AND permission_code = …)` and
     * `Rows Removed by Filter: 65`.
     *
     * **Second mutation:** remove `AND event_type = ?` from PROBE. The partial
     * predicate is then unprovable from the query, the index is unusable, and
     * this goes red -- which is why the read-back must carry a predicate that
     * looks redundant against the CHECK.
     */
    #[Test]
    public function theReadBackIsAnIndexScanOnTheCapIndexWithNoFilter(): void
    {
        $root = $this->planNode(self::PROBE,
            [self::ACTOR, self::CODE, AuditEvent::AUTHZ_PERMISSION_DENIED],
            'E-3a read-back: (tenant_id via RLS, actor, code, UTC day) LIMIT 2');

        $this->assertFixtureIsWhatItClaims();

        $scan = $this->findNode($root, 'Index Scan');
        self::assertNotNull($scan,
            'The read-back is not an index scan at all. Node types in this plan: '
            .implode(', ', $this->nodeTypes($root)));

        self::assertSame(self::INDEX, $scan['Index Name'],
            'The read-back used '.$scan['Index Name'].'. Only '.self::INDEX.' carries all three equality '
            .'columns of E-3a\'s key, so anything else is scanning the actor\'s whole day.');

        foreach (['tenant_id', 'actor_user_id', 'permission_code', 'occurred_at'] as $column) {
            self::assertStringContainsString($column, $scan['Index Cond'],
                "$column is not an index condition, so it is being applied after rows are read:\n"
                .$scan['Index Cond']);
        }

        self::assertArrayNotHasKey('Filter', $scan,
            "The scan node carries a Filter, so rows are being read and discarded rather than not read:\n"
            .($scan['Filter'] ?? ''));
        self::assertSame(0, $scan['Rows Removed by Filter'] ?? 0);

        // The corroborating figure. The bound is on work, so the work is what is
        // measured: a handful of buffers against a fixture of tens of thousands
        // of rows. Two orders of magnitude of headroom rather than a tight pin,
        // because buffer counts move with page layout and this must not be a
        // test that fails on a vacuum.
        $buffers = ($scan['Shared Hit Blocks'] ?? 0) + ($scan['Shared Read Blocks'] ?? 0);
        self::assertLessThan(100, $buffers,
            "The probe read $buffers buffers. E-3a's bound is on work done, and this is the fixture's "
            .'order of magnitude rather than the ceiling\'s.');
    }

    /**
     * The proof that the assertion above discriminates: the same read-back with
     * the cap's third column in `metadata`, which is revision 1's statement and
     * `API.md` §9.4a's rejected plan A.
     *
     * Everything a weaker assertion looks at is identical -- the statement
     * carries a day predicate, carries `LIMIT 2`, is not an aggregate, and its
     * scan node returns rows the limit caps. What differs is the work, and this
     * test asserts the difference rather than describing it.
     *
     * **Mutation:** point PROBE at this statement; the test above goes red on all
     * three clauses. That is the point of this one existing.
     */
    #[Test]
    public function theSameReadBackOverMetadataIsUnbounded(): void
    {
        $bounded = $this->planNode(self::PROBE,
            [self::ACTOR, self::CODE, AuditEvent::AUTHZ_PERMISSION_DENIED],
            'the bounded probe, for comparison');
        $rejected = $this->planNode(self::METADATA_PROBE,
            [self::ACTOR, self::CODE, AuditEvent::AUTHZ_PERMISSION_DENIED],
            'REJECTED plan A -- the cap key in metadata, unindexed');

        $this->assertFixtureIsWhatItClaims();

        $boundedScan = $this->findNode($bounded, 'Index Scan');
        $rejectedScan = $this->findNode($rejected, 'Index Scan') ?? $this->findNode($rejected, 'Seq Scan');
        self::assertNotNull($boundedScan);
        self::assertNotNull($rejectedScan, 'Node types: '.implode(', ', $this->nodeTypes($rejected)));

        // Nothing in the rejected plan touches the cap index: `metadata` is
        // jsonb and unindexed, so the third key column cannot be an index
        // condition. If it could, the two plans would not be being compared.
        self::assertNotSame(self::INDEX, $rejectedScan['Index Name'] ?? null,
            'The metadata read-back used the cap index, which cannot happen -- it names no column the '
            .'index carries. This test would then be comparing a plan with itself.');

        // Clause 3 of the shape assertion, shown to discriminate.
        self::assertArrayNotHasKey('Filter', $boundedScan);
        self::assertArrayHasKey('Filter', $rejectedScan,
            'The rejected plan has no Filter, so the clause that rejects it is not discriminating.');
        self::assertStringContainsString('permission_code', $rejectedScan['Filter']);

        // **The fake-green, reproduced rather than cited.** Both scan nodes
        // return the same number of rows, because LIMIT 2 caps rows *returned*
        // by construction. Revision 2's assertion was on this quantity and it is
        // identical on the plan it was written to accept and the plan it was
        // written to reject. Asserted as an equality so that if it ever stops
        // being true, §9.4a gets re-measured rather than trusted.
        self::assertSame((float) $boundedScan['Actual Rows'], (float) $rejectedScan['Actual Rows'],
            '`actual rows` now differs between the two plans on this PostgreSQL. That is the assertion '
            .'§9.4a withdrew as non-discriminating; if it discriminates again, re-measure before relying '
            .'on either version.');

        // Where the plans really differ: work. Stated as a ratio against the
        // bounded plan measured in the same run rather than as a pinned figure,
        // because a magic number here is a knob that configures the test green.
        $work = static fn (array $n): int => ($n['Shared Hit Blocks'] ?? 0) + ($n['Shared Read Blocks'] ?? 0);

        self::assertSame(0, $boundedScan['Rows Removed by Filter'] ?? 0);
        self::assertGreaterThan(0, $rejectedScan['Rows Removed by Filter'] ?? 0,
            'The rejected plan discarded no rows, so it did no work the bounded plan avoids.');
        self::assertGreaterThan(10 * $work($boundedScan), $work($rejectedScan), sprintf(
            'The rejected plan read %d buffers against the bounded plan\'s %d. An order of magnitude is '
            .'the margin this fixture produces; below it the two plans are not distinguishable by work '
            .'either, and nothing is left to assert on.',
            $work($rejectedScan), $work($boundedScan)));

        // §9.4a's own cited figure is the *worst* case, not this one: its plan A
        // reports `Rows Removed by Filter: 9997` because its probe found nothing
        // and ran to the end of the day. Reproduced here, because a probe that
        // stops early is the rejected plan's best case and the bound has to hold
        // in both.
        $missing = $this->planNode(self::METADATA_PROBE,
            [self::ACTOR, 'code.that.was.never.denied', AuditEvent::AUTHZ_PERMISSION_DENIED],
            'REJECTED plan A, worst case -- a key with no matching row');
        $missingScan = $this->findNode($missing, 'Index Scan') ?? $this->findNode($missing, 'Seq Scan');
        self::assertGreaterThanOrEqual(self::DENIALS_TODAY, $missingScan['Rows Removed by Filter'] ?? 0,
            'A miss on the rejected plan did not read the actor\'s whole day. That reading is the growth '
            .'vector E-3a exists to close, arriving through E-3a\'s own implementation.');
    }

    /**
     * When the read-back writes `tenant_id = ?` itself, the RLS policy's own
     * predicate is constant-folded into a `Result` node above the scan carrying
     * **`One-Time Filter`**. The plan is correct and the scan node still has no
     * `Filter` -- but the *text* of the plan contains the substring `Filter:`.
     *
     * This is pinned rather than left to be discovered because §9.4a's wording is
     * "no `Filter:` line at all", and a text assertion written to that wording
     * fails here on a plan that is exactly right. The clause means the scan
     * node's own qual; in `FORMAT JSON` that ambiguity does not exist.
     *
     * **Mutation:** assert `assertStringNotContainsString('Filter:', $text)`
     * against this plan; red, on a correct plan.
     */
    #[Test]
    public function theExplicitTenantPredicateFormAddsAOneTimeFilterNode(): void
    {
        $sql = str_replace('WHERE actor_user_id', 'WHERE tenant_id = ?::uuid AND actor_user_id', self::PROBE);

        $root = $this->planNode($sql,
            [self::TENANT_A, self::ACTOR, self::CODE, AuditEvent::AUTHZ_PERMISSION_DENIED],
            'the same probe writing tenant_id itself -- correct, and carrying One-Time Filter');

        $scan = $this->findNode($root, 'Index Scan');
        self::assertNotNull($scan);
        self::assertSame(self::INDEX, $scan['Index Name']);
        self::assertArrayNotHasKey('Filter', $scan);

        $result = $this->findNode($root, 'Result');
        self::assertNotNull($result,
            'The redundant tenant predicate no longer folds RLS into a Result node. That is fine, but '
            .'this test exists to pin the shape phase 2 will meet, so re-measure before deleting it.');
        self::assertArrayHasKey('One-Time Filter', $result,
            'The Result node carries no One-Time Filter, so the hazard this test pins has moved.');
        self::assertStringContainsString('app.tenant_id', $result['One-Time Filter']);
    }

    /**
     * The day bound is doing work, asserted on rows rather than on the plan and
     * without depending on the order any plan happens to return them in.
     *
     * §9.4a's clause is that the read-back is "range-bounded to the current UTC
     * day". A plan-shape assertion cannot tell a correct bound from one that is
     * wrong by a day: both are index conditions and both look identical in
     * `EXPLAIN`. The fixture carries 500 rows for this exact key *yesterday*
     * precisely so that the predicate has something to exclude.
     *
     * **Mutation:** delete the `occurred_at >=` clause from PROBE; the matched
     * set grows by yesterday's rows and the second assertion goes red. **Second
     * mutation:** set `DENIALS_YESTERDAY` to 0; the fixture no longer straddles
     * midnight, and `assertFixtureIsWhatItClaims` goes red before this does --
     * which is the anti-vacuity check doing its job.
     */
    #[Test]
    public function theDayBoundExcludesYesterdaysRowsForTheSameKey(): void
    {
        $this->seedDenials();
        $this->assertFixtureIsWhatItClaims();

        $today = intdiv(self::DENIALS_TODAY, self::CODES);

        $result = $this->tenantContext()->run(self::TENANT_A, function () use ($today): array {
            $ids = $this->app()->fetchFirstColumn(self::PROBE,
                [self::ACTOR, self::CODE, AuditEvent::AUTHZ_PERMISSION_DENIED]);

            return [
                'returned' => \count($ids),
                // Every row the bounded probe returned is from the current UTC
                // day. Checked by id rather than by trusting the predicate that
                // selected them.
                'returned_from_today' => (int) $this->app()->fetchOne(
                    "SELECT count(*) FROM audit_log WHERE id = ANY(?::uuid[])
                     AND occurred_at >= date_trunc('day', now() AT TIME ZONE 'UTC') AT TIME ZONE 'UTC'",
                    ['{'.implode(',', $ids).'}']),
                // What the bound excludes, counted rather than inferred: the same
                // key without the range predicate matches yesterday's rows too.
                'matched_bounded' => (int) $this->app()->fetchOne(
                    "SELECT count(*) FROM audit_log WHERE actor_user_id = ?::uuid AND permission_code = ?
                     AND event_type = ?
                     AND occurred_at >= date_trunc('day', now() AT TIME ZONE 'UTC') AT TIME ZONE 'UTC'",
                    [self::ACTOR, self::CODE, AuditEvent::AUTHZ_PERMISSION_DENIED]),
                'matched_unbounded' => (int) $this->app()->fetchOne(
                    'SELECT count(*) FROM audit_log WHERE actor_user_id = ?::uuid AND permission_code = ? AND event_type = ?',
                    [self::ACTOR, self::CODE, AuditEvent::AUTHZ_PERMISSION_DENIED]),
            ];
        });

        self::assertSame([
            'returned' => 2,
            'returned_from_today' => 2,
            'matched_bounded' => $today,
            'matched_unbounded' => $today + self::DENIALS_YESTERDAY,
        ], $result,
            'Either the read-back did not stop at E-3a\'s second match, or it returned a row from outside '
            .'the current UTC day, or the day bound excludes nothing and is decorative.');
    }
}

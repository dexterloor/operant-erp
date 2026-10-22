<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Modules\UserManagement\Infrastructure\AuthTrailWriter;
use App\SharedKernel\ValueObject\AuditEvent;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **The audit trail stays in the order things were recorded when the database clock steps back** — the trigger
 * `audit_log_monotonic_occurred_at` (`Version20261022004000`; `docs/session13/audit-order-clock-design.md` Revision 1
 * FINAL as amended by `audit-order-clock-final-red.md`; Move 702's History flake on erp3, whose Lima host agent steps the
 * guest clock back ~0.1 s every few seconds).
 *
 * **No clock is moved.** The precedent's method (Moves 650–651): the predecessor is seeded in the database's FUTURE
 * (`clock_timestamp() + 5 s`), which is exactly what a backward step makes the last row look like to the next writer.
 *
 * **What `occurred_at` now means (H-2):** the writer's transaction start, raised to 1 µs after the newest row in the
 * tenant's trail that is visible when the row is inserted — ignoring any row dated more than 60 s ahead (M-1) — and only
 * for a row stamped "now" or later by a principal that can read the trail (H-1). Read newest first that is insert order
 * for causally related writes.
 *
 * Each method starts from the tenant's audit row count (M-2): `TenancyTestCase::setUp()` truncates the trail before every
 * method, so the only rows a method sees are the ones it writes.
 *
 * Mutations (run, not predicted — `docs/session13/audit-order-progress.md`): M1 no trigger · M2 `>` → `>=` (T-E) · M3′ no
 * 60 s bound in the probe (T-C) · M4 no `>= now()` (T-D) · M5 raise to `latest` (T-A) · M6 always raise (T-B) · M7 no
 * `has_table_privilege` (T-G) · M8 no tenant predicate (a REVIEW CHECK, pinned by T-I's text: locally RLS isolates every
 * principal, so T-F cannot go red — final RED F-1) · M9 `SECURITY DEFINER` (T-I) · M12 no `SET search_path` (T-I) · M13
 * `VOLATILE` → `STABLE` (T-E′).
 */
final class AuditLogOrderTest extends TenancyTestCase
{
    private const SEED = 'Order Seed';

    /** T-A: a row stamped "now" behind the tenant's latest row is raised to exactly 1 µs after it. */
    #[Test]
    public function aRowStampedBehindTheLatestIsRaisedToJustAfterIt(): void
    {
        $p = $this->seed(self::TENANT_A, '5 seconds');
        [, $q] = $this->appInsert(self::TENANT_A);

        self::assertSame($this->plusOneMicrosecond(self::TENANT_A, $p), $this->stamp(self::TENANT_A, $q),
            'A row written while the trail\'s latest row was 5 s ahead (a backward clock step) was not raised to 1 µs after it, '
            .'so History would show it BELOW the event it followed.');
    }

    /** T-B: behind nothing, a row keeps its own transaction start. Kills "always raise" (M6). */
    #[Test]
    public function aRowBehindNothingKeepsItsOwnTransactionStart(): void
    {
        $this->seed(self::TENANT_A, '-1 minute');
        self::assertSame(1, $this->rows(self::TENANT_A), 'Control: the tenant holds more than the one seeded past row.');

        [$atItsOwnStart] = $this->appInsert(self::TENANT_A);

        self::assertTrue($atItsOwnStart, 'A row on a healthy clock was moved off its transaction start.');
    }

    /** T-C: the probe ignores a row more than 60 s ahead and still raises behind the newest one within 60 s (M-1). */
    #[Test]
    public function aFarFutureRowDoesNotSwitchTheRaiseOff(): void
    {
        // The near row first: seeded second, it would itself be raised behind the far one under an unbounded probe (M3′),
        // and the assertion below could no longer tell the two probes apart.
        $near = $this->seed(self::TENANT_A, '5 seconds');
        $this->seed(self::TENANT_A, '2 minutes');
        [, $q] = $this->appInsert(self::TENANT_A);

        self::assertSame($this->plusOneMicrosecond(self::TENANT_A, $near), $this->stamp(self::TENANT_A, $q),
            'A row planted 2 minutes ahead switched the raise off (or dragged the stamp past the 60 s bound): the bound must sit '
            .'inside the probe, not after it.');
    }

    /** T-C′: a row more than 60 s ahead alone is ignored; the new row stays below it. */
    #[Test]
    public function aRowMoreThanSixtySecondsAheadIsNotFollowed(): void
    {
        $far = $this->seed(self::TENANT_A, '2 minutes');
        [, $q] = $this->appInsert(self::TENANT_A);

        self::assertTrue($this->before(self::TENANT_A, $q, $this->stamp(self::TENANT_A, $far)),
            'A row was raised behind a row more than 60 s ahead: a planted far-future row could drag every later stamp.');
    }

    /** T-D: a deliberately dated write (earlier than its transaction start) is never moved. Kills dropping `>= now()` (M4). */
    #[Test]
    public function aBackDatedWriteIsNeverMoved(): void
    {
        $this->appInsert(self::TENANT_A);
        self::assertSame(1, $this->rows(self::TENANT_A));

        $r = $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO audit_log (id, tenant_id, occurred_at, event_type, actor_label)
            VALUES (?::uuid, ?::uuid, now() - interval '10 seconds', 'customer.updated', 'Dated')
            RETURNING occurred_at::text AS stamped, (now() - interval '10 seconds')::text AS given
            SQL, [Uuid::v4()->toRfc4122(), self::TENANT_A])[0];

        self::assertSame($r['given'], $r['stamped'], 'A back-dated write was moved: every tie-group and dated fixture would drift.');
    }

    /** T-E: one unraised transaction's rows stay a tie group. Kills `>=` for `>` (M2). */
    #[Test]
    public function oneTransactionsRowsStayTied(): void
    {
        self::assertSame(0, $this->rows(self::TENANT_A));
        $stamps = $this->tenantContext()->run(self::TENANT_A, function (EntityManagerInterface $em): array {
            $c = $em->getConnection();
            $ids = [];
            for ($i = 0; $i < 3; ++$i) {
                $ids[] = $id = Uuid::v4()->toRfc4122();
                $c->executeStatement("INSERT INTO audit_log (id, tenant_id, event_type, actor_label) VALUES (?::uuid, ?::uuid, 'customer.updated', 'Tie')",
                    [$id, self::TENANT_A]);
            }

            return $c->fetchFirstColumn('SELECT occurred_at::text FROM audit_log WHERE id IN (?::uuid, ?::uuid, ?::uuid)', $ids);
        });

        self::assertCount(3, $stamps);
        self::assertCount(1, array_unique($stamps), 'One transaction\'s rows no longer share now(): the raise fired on a tie.');
    }

    /**
     * T-E′ (L-5, final RED F-4): behind a row ahead, ONE three-row INSERT statement gives distinct, strictly increasing stamps,
     * all above the seed — each row's probe sees the rows processed earlier IN THE SAME COMMAND, which only a VOLATILE
     * function does. No claim about which row is first (processing order is not promised). Kills M13 (`STABLE`).
     */
    #[Test]
    public function aRaisedMultiRowStatementGivesEachRowItsOwnPlace(): void
    {
        $seed = $this->seed(self::TENANT_A, '5 seconds');
        $ids = [Uuid::v4()->toRfc4122(), Uuid::v4()->toRfc4122(), Uuid::v4()->toRfc4122()];
        $this->tenantContext()->run(self::TENANT_A, static fn (EntityManagerInterface $em): int => (int) $em->getConnection()->executeStatement(<<<'SQL'
            INSERT INTO audit_log (id, tenant_id, event_type, actor_label) VALUES
                (?::uuid, ?::uuid, 'customer.updated', 'Multi'), (?::uuid, ?::uuid, 'customer.updated', 'Multi'), (?::uuid, ?::uuid, 'customer.updated', 'Multi')
            SQL, [$ids[0], self::TENANT_A, $ids[1], self::TENANT_A, $ids[2], self::TENANT_A]));

        $rows = $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT a.occurred_at::text AS t, a.occurred_at > s.occurred_at AS above
              FROM audit_log a CROSS JOIN (SELECT occurred_at FROM audit_log WHERE id = ?::uuid) s
             WHERE a.actor_label = 'Multi' ORDER BY a.occurred_at
            SQL, [$seed]);

        self::assertCount(3, $rows);
        self::assertSame([true, true, true], array_map(static fn (array $r): bool => (bool) $r['above'], $rows), 'A raised row stayed below the seed.');
        self::assertCount(3, array_unique(array_column($rows, 't')),
            'A raised multi-row statement put two rows on one stamp: the probe did not see the rows of its own command (the function '
            .'must be VOLATILE).');
    }

    /** T-F: another tenant's latest row is not this tenant's predecessor. A plain isolation assertion (F-1: M8 is a review check). */
    #[Test]
    public function anotherTenantsLatestIsIgnored(): void
    {
        $pB = $this->seed(self::TENANT_B, '5 seconds');
        $stampB = $this->stamp(self::TENANT_B, $pB);

        $q = $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO audit_log (id, tenant_id, event_type, actor_label) VALUES (?::uuid, ?::uuid, 'customer.updated', 'Owner')
            RETURNING id::text AS id
            SQL, [Uuid::v4()->toRfc4122(), self::TENANT_A])[0]['id'];

        self::assertTrue($this->before(self::TENANT_A, (string) $q, $stampB), 'Tenant A\'s row was raised behind tenant B\'s trail.');
    }

    /**
     * T-G (H-1): a principal that may not read the trail — `erp_authlog`, the pre-authentication sign-in writer — still
     * writes (no 42501) and keeps its own stamp, unraised. `RESIDUAL_GAPS.md` G-54 clause (i). Kills dropping the
     * `has_table_privilege` clause (M7): every sign-in would then fail.
     */
    #[Test]
    public function aWriterThatCannotReadTheTrailWritesUnraised(): void
    {
        $p = $this->seed(self::TENANT_A, '5 seconds');
        $user = Uuid::v4()->toRfc4122();
        $this->executeAsOwnerForTenant(self::TENANT_A,
            "INSERT INTO app_user (id, tenant_id, email, password_hash, display_name) VALUES (?::uuid, ?::uuid, ?, 'not-a-hash', 'Order User')",
            [$user, self::TENANT_A, 'order-'.$user.'@example.test']);

        $writer = self::getContainer()->get(AuthTrailWriter::class);
        \assert($writer instanceof AuthTrailWriter);
        $writer->record(AuditEvent::AUTH_LOGIN_SUCCESS, self::TENANT_A, $user, 'Order User', null);

        $rows = $this->fetchAsOwnerForTenant(self::TENANT_A, "SELECT id::text AS id FROM audit_log WHERE event_type = 'auth.login.success'");
        self::assertCount(1, $rows, 'The sign-in writer wrote nothing.');
        self::assertTrue($this->before(self::TENANT_A, (string) $rows[0]['id'], $this->stamp(self::TENANT_A, $p)),
            'A sign-in row was raised: erp_authlog cannot read the trail, so either the clause skipping it was removed or erp_authlog '
            .'gained SELECT on audit_log — a reviewed change to G-54, not a fix.');
    }

    /**
     * T-K (H-2, final RED F-2): the raise is about insert order, not only clock steps. T1 opens (records `now()` = t1); inside
     * it T2 — a separate, unpooled `erp_app` client (`freshUnpooledClient()`: the pooled endpoint has ONE server connection,
     * so a second pooled client would wait on T1 and hang) — inserts and commits; then T1 inserts with its own, earlier
     * `now()`. T1's row lands 1 µs after T2's. **T2's stamp is pinned** to `GREATEST(now(), t1 + 1 ms)`, so the assertion
     * tests the concurrency semantics without depending on the VM clock (a step between T1 and T2 cannot invert them).
     */
    #[Test]
    public function aTransactionThatInsertsAfterAQuickerOneCommittedFollowsIt(): void
    {
        [$t1Row, $t2Row] = $this->tenantContext()->run(self::TENANT_A, function (EntityManagerInterface $em): array {
            $t1 = $em->getConnection();
            $start = (string) $t1->fetchOne('SELECT now()::text');

            $t2 = $this->freshUnpooledClient();
            $t2Row = Uuid::v4()->toRfc4122();
            $t2->beginTransaction();
            try {
                $this->tenantContext()->establishOn($t2, self::TENANT_A);
                $t2->executeStatement(<<<'SQL'
                    INSERT INTO audit_log (id, tenant_id, occurred_at, event_type, actor_label)
                    VALUES (?::uuid, ?::uuid, GREATEST(now(), ?::timestamptz + interval '1 millisecond'), 'customer.updated', 'T2')
                    SQL, [$t2Row, self::TENANT_A, $start]);
                $t2->commit();
            } finally {
                $t2->close();
            }

            $t1Row = Uuid::v4()->toRfc4122();
            $t1->executeStatement("INSERT INTO audit_log (id, tenant_id, event_type, actor_label) VALUES (?::uuid, ?::uuid, 'customer.updated', 'T1')",
                [$t1Row, self::TENANT_A]);

            return [$t1Row, $t2Row];
        });

        self::assertSame($this->plusOneMicrosecond(self::TENANT_A, $t2Row), $this->stamp(self::TENANT_A, $t1Row),
            'A transaction that inserted after a quicker one committed kept its earlier start and sorts below it.');
    }

    /**
     * T-I: the catalogue pin. The trigger exists, is enabled, BEFORE INSERT FOR EACH ROW; its function runs as the INVOKER,
     * carries the measured `proconfig` exactly (final RED F-8: `pg_temp` LAST), and its probe names the tenant (F-1: M8 is
     * unobservable here — the owner is NOBYPASSRLS under FORCE RLS — so the text is the pin; on a BYPASSRLS owner (Neon) that
     * predicate is the only isolation of owner-process audit writes).
     */
    #[Test]
    public function theTriggerAndItsFunctionAreAsDeclared(): void
    {
        $trigger = $this->owner()->fetchAllAssociative(<<<'SQL'
            SELECT t.tgenabled::text AS enabled, t.tgtype::int AS type, lower(pg_get_triggerdef(t.oid)) AS def
              FROM pg_trigger t
             WHERE t.tgrelid = 'public.audit_log'::regclass AND t.tgname = 'audit_log_monotonic_occurred_at' AND NOT t.tgisinternal
            SQL);
        self::assertCount(1, $trigger, 'The audit-order trigger is gone.');
        self::assertSame('O', $trigger[0]['enabled'], 'The audit-order trigger is disabled.');
        self::assertSame(1 | 2 | 4, $trigger[0]['type'], 'Not BEFORE INSERT FOR EACH ROW (tgtype ROW=1|BEFORE=2|INSERT=4).');

        $function = $this->owner()->fetchAllAssociative(<<<'SQL'
            SELECT p.prosecdef AS definer, p.proconfig::text AS config,
                   lower(regexp_replace(pg_get_functiondef(p.oid), '\s+', ' ', 'g')) AS def
              FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
             WHERE n.nspname = 'public' AND p.proname = 'audit_log_monotonic_occurred_at'
            SQL);
        self::assertCount(1, $function);
        self::assertFalse((bool) $function[0]['definer'], 'The audit-order function runs as its definer.');
        self::assertSame('{"search_path=pg_catalog, public, pg_temp"}', $function[0]['config'],
            'The function\'s search_path is not pinned to pg_catalog, public, pg_temp (pg_temp last; F-8).');
        self::assertStringContainsString('a.tenant_id = new.tenant_id', (string) $function[0]['def'],
            'The probe no longer names the tenant. Locally RLS hides the omission; on a BYPASSRLS owner it reads every tenant\'s trail.');
        self::assertStringContainsString('from public.audit_log a', (string) $function[0]['def'],
            'The probe reads an unqualified audit_log: a temp table could shadow it (pg_temp is searched first for relations).');
    }

    // -----------------------------------------------------------------------

    /** An owner-seeded row at `clock_timestamp() + $offset` (a positive offset is what a backward step looks like). */
    private function seed(string $tenant, string $offset): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->executeAsOwnerForTenant($tenant, <<<'SQL'
            INSERT INTO audit_log (id, tenant_id, occurred_at, event_type, actor_label)
            VALUES (?::uuid, ?::uuid, clock_timestamp() + ?::interval, 'customer.updated', ?)
            SQL, [$id, $tenant, $offset, self::SEED]);

        return $id;
    }

    /** @return array{0: bool, 1: string} whether the row carries its own transaction's `now()`, and its id — written as `erp_app` with `now()` */
    private function appInsert(string $tenant): array
    {
        return $this->tenantContext()->run($tenant, function (EntityManagerInterface $em) use ($tenant): array {
            $c = $em->getConnection();
            $id = Uuid::v4()->toRfc4122();
            $c->executeStatement("INSERT INTO audit_log (id, tenant_id, event_type, actor_label) VALUES (?::uuid, ?::uuid, 'customer.updated', 'Writer')",
                [$id, $tenant]);

            return [(bool) $c->fetchOne('SELECT occurred_at = now() FROM audit_log WHERE id = ?::uuid', [$id]), $id];
        });
    }

    private function stamp(string $tenant, string $id): string
    {
        return (string) $this->fetchAsOwnerForTenant($tenant, 'SELECT occurred_at::text AS t FROM audit_log WHERE id = ?::uuid', [$id])[0]['t'];
    }

    private function plusOneMicrosecond(string $tenant, string $id): string
    {
        return (string) $this->fetchAsOwnerForTenant($tenant,
            "SELECT (occurred_at + interval '1 microsecond')::text AS t FROM audit_log WHERE id = ?::uuid", [$id])[0]['t'];
    }

    private function before(string $tenant, string $id, string $stamp): bool
    {
        return (bool) $this->fetchAsOwnerForTenant($tenant, 'SELECT occurred_at < ?::timestamptz AS b FROM audit_log WHERE id = ?::uuid', [$stamp, $id])[0]['b'];
    }

    private function rows(string $tenant): int
    {
        return $this->countAsOwnerForTenant($tenant, 'SELECT count(*) FROM audit_log');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Modules\Inventory\Application\Contract\DepleteStock;
use App\Modules\Inventory\Application\Contract\ReceiveStock;
use App\Modules\Inventory\Application\StockDepleter;
use App\Modules\Inventory\Application\StockReceiver;
use App\Modules\Inventory\Infrastructure\Entity\StockMovement;
use App\Modules\Purchasing\Application\GoodsReceiptWriter;
use App\Modules\Sales\Application\FulfilmentWriter;
use App\Modules\UserManagement\Infrastructure\AuthTrailWriter;
use App\Platform\Tenancy\SchemaManifest;
use App\Tests\Support\PhpSourceScan;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * **`ARCHITECTURE.md` S-7(c): a module writes, in raw SQL or DQL, only the
 * tables `SchemaManifest` says it owns.**
 *
 * ---------------------------------------------------------------------------
 * Why this exists (owner ruling on API-O-27, 2026-09-14)
 * ---------------------------------------------------------------------------
 * S-7 is "exactly one module writes each table", in three parts. (a) is
 * `SchemaOwnershipTest`: no module *maps an entity* to a table it does not own.
 * (b) said deptrac's A-10 closed the raw-SQL route, and that was false: A-10
 * sees a named `Doctrine\DBAL\Connection`, and module code runs its SQL through
 * `EntityManager::getConnection()`, which names no such type. (c) was specified
 * and **never built**. So until this test, a module could `INSERT INTO` another
 * module's table and nothing in the suite would say so.
 *
 * ---------------------------------------------------------------------------
 * What is scanned, and how
 * ---------------------------------------------------------------------------
 * Every string literal in every PHP file under `src/Modules/<M>/` — inside a
 * method body **or** outside one (a `const SQL = 'UPDATE …'` is a write wherever
 * it is declared) — through `PhpSourceScan`, the token walk the purchase-order
 * lock-first scan runs on. Comments and docblocks are not literals, so prose in
 * them is never a statement. A literal is read as its **runtime value**, escapes
 * decoded: `"SELECT 1;\nUPDATE t"` has a newline before the keyword, not an `n`.
 *
 * A **write statement** is `INSERT INTO`, `DELETE FROM`, `UPDATE`,
 * `MERGE INTO`, `TRUNCATE [TABLE] [ONLY] a, b, …` (every table in the list) or
 * `COPY <name> [(cols)] FROM`, in any case, with whitespace **or SQL comments**
 * between the words and before the target, except:
 *  - `DO UPDATE` (the arm of `ON CONFLICT`, whose table is the `INSERT`'s) and
 *    `FOR UPDATE` / `FOR NO KEY UPDATE` (a row lock, not a write) — **unless the
 *    `DO` / `FOR` / `KEY` is in a SQL comment**, judged crudely and towards
 *    reporting (`isConflictArmOrRowLock()`);
 *  - `COPY <name> TO` and `COPY (query) TO`, which are reads;
 *  - a non-upper-case `update` / `merge` / `truncate` / `copy` that does not
 *    name a manifest table or mapped entity, is not followed by
 *    `<name> [alias] SET`, and does not end the literal piece — which is how an
 *    exception message such as *"Update is not allowed"* stays prose. An
 *    upper-case keyword is always a statement.
 *
 * **Pinned by the `EVASIONS` fixture (RED review, 2026-09-14):** each shape the
 * review planted past `18a4750` — escaped `\n` / `\t` before the keyword,
 * lower-case `update` with an escaped newline, a comment ending in `DO`,
 * `MERGE` / `TRUNCATE` / `COPY` — plus the five it confirmed were already
 * caught.
 *
 * Its **target** is the name after the keyword (after `ONLY`): a SQL table
 * name, bare, quoted or schema-qualified, or a DQL entity FQCN, which is
 * resolved through the entity's own `#[ORM\Table]`. The writer's module is its
 * namespace below `App\` (`ARCHITECTURE.md` §4.0), and the table's is
 * `SchemaManifest::TABLES[<table>]['module']`. They must be equal.
 *
 * ---------------------------------------------------------------------------
 * Fail closed
 * ---------------------------------------------------------------------------
 * A statement whose target **cannot be read as a manifest table** is a failure,
 * not a pass: an interpolated `"INSERT INTO {$table}"` (the walk yields the piece
 * `INSERT INTO ` and nothing after it), a concatenation `'UPDATE '.$table`, a
 * `sprintf('DELETE FROM %s')`, a class constant from somewhere else, or a name
 * the manifest does not declare. The fix is a literal table name, or an entry in
 * {@see self::REGISTERED_DEVIATIONS} under {@see self::UNRESOLVED} with a reason.
 *
 * ---------------------------------------------------------------------------
 * What this does NOT see — named residuals
 * ---------------------------------------------------------------------------
 *  - **`Platform\Tenancy` is out of scope**, and so is the rest of `src/Platform`
 *    and `src/SharedKernel`: `Platform\Tenancy` is the RLS / `set_config` layer
 *    (A-10 lets it take a `Connection` directly), its tables are not a module's,
 *    and `SchemaManifest`'s own `why` strings are full of upper-case `UPDATE`
 *    prose that would fail closed here for no reason.
 *  - **ORM writes.** A module that `persist()`s another module's entity writes
 *    that table without spelling SQL. S-7(a) checks where entities are mapped,
 *    not who persists them.
 *  - **DBAL's convenience writers** — `Connection::insert('t', …)`, `update()`,
 *    `delete()` and the query builder's `->insert('t')` / `->update('t')` /
 *    `->delete('t')`. None is used under `src/Modules` today (grep, 2026-09-14);
 *    a name scan cannot tell them from a service method called `update()`.
 *  - **SQL assembled from pieces with no keyword in them**, and triggers — the
 *    same blind spots `PurchaseOrderRowLock`'s docblock names for the lock scan.
 *  - **Other statements that write**: DDL (`ALTER`, `DROP`, `CREATE … AS`),
 *    `SELECT` of a function or procedure that writes, `CALL`, `EXECUTE` of a
 *    prepared name, and a `U&"…"` identifier (it fails closed as an unknown
 *    table, it is not resolved).
 *  - **A comment trick the crude comment test misjudges**: a `--` inside a
 *    SQL string literal on the same line as a real `ON CONFLICT … DO UPDATE`
 *    denies the exception (a false report, not a hidden write). The reverse is
 *    not known: a comment ending between the word and `UPDATE` leaves its
 *    closer there, and a `--` comment puts `--` on the word's line (reasoned,
 *    not exhaustively tested).
 *  - **The count pin is per module**: a scanner that stops seeing one statement
 *    in the same commit that adds another *to the same module* keeps the pin.
 *  - **Reads.** Cross-module SQL reads are a disclosed residual, not a rule:
 *    `UserManagement\Application\PasswordChangeThrottle` reads `audit_log` by
 *    design.
 *
 * **A `KernelTestCase` since 2026-09-22, for one method only:**
 * `everyStockMovementReasonIsRegisteredToItsWriterAndPermission` reads its domain
 * from the live `stock_movement_reason_values` CHECK, so it boots the kernel for
 * the `erp_app` connection. Every other method is a pure source scan and boots
 * nothing.
 */
final class WriteOwnershipTest extends KernelTestCase
{
    /**
     * **The exact write-statement count, per module — a pin, not a floor.**
     *
     * Counted 2026-09-14 at `526bfef` independently of this scanner with
     *
     *     grep -rnE "INSERT INTO|DELETE FROM|UPDATE [a-z_]+( |$)" src/Modules
     *
     * which prints 34 lines: 9 are comment or docblock prose ("UPDATE is
     * retained", "no UPDATE restates the field list" — the reason a line grep is
     * not the check) and 25 are statements. Re-checked at `45df678` after the
     * 2026-09-14 parser fix: the scan's statement list is byte-identical.
     *
     * **Why exact, and why per module.** Until the RED review of 2026-09-14 this
     * was `assertGreaterThanOrEqual(25, …)` — and a `>=` floor goes quiet the day
     * the tree grows: add five writes and the scanner can lose five, anywhere,
     * with the suite green. An exact count cannot lose one silently. Per module,
     * because one total lets a new write in Purchasing pay for a statement the
     * scanner stopped seeing in UserManagement.
     *
     * **Why not a count from an independent mechanism.** Two were considered: a
     * second regex over the same literals is the same parser's idea of a
     * statement and would share its blind spots (circular); a line grep over the
     * source cannot tell a statement from prose (9 of its 34 lines above). The
     * pin's cost is that **every commit that adds or removes a raw write edits
     * this constant** — deliberately: the change is then in the diff, where a
     * reviewer can see that the number moved by the writes the commit added.
     */
    private const WRITE_STATEMENTS_PER_MODULE = [
        // 4 -> 5 on 2026-09-19: `SalesOrderAuditWriter::recordCancelled()`'s single `INSERT INTO
        // audit_log`, which is `PurchaseOrderAuditWriter`'s statement with one event name changed.
        // `Modules\\Sales` did NOT move and that was read off the run rather than predicted --
        // `SalesOrderCancellation` issues two `SELECT`s and an ORM flush, and the row lock is a
        // `FOR NO KEY UPDATE` this scan excludes.
        'Modules\\AuditLog' => 5,
        // **6 -> 8 on 2026-09-22** with `DepleteStock` (sales step 5, commit 1), read off this test's own
        // failure: `StockDepleter`'s one `INSERT INTO stock_movement` and one conditional `UPDATE stock_level`.
        // The reason-to-writer binding that pin used to be credited with is NOT this count -- see
        // everyStockMovementReasonIsRegisteredToItsWriterAndPermission, and the message below.
        'Modules\\Inventory' => 8,
        'Modules\\Purchasing' => 10,
        // ⚠ **This block read "Two … **There is no UPDATE**, which is the whole of that ruling —
        // `total_amount` is summed inside the order's own INSERT" and is FALSE as of 2026-09-21.**
        // `CreateSalesOrderLine` deleted that expression -- `insertOrderInside()`'s own docblock named
        // this commit as the one that would -- and `recomputeTotal()` now issues a real
        // `UPDATE sales_order`. The grant it needs was already held: `Version20260919000300` widened
        // `erp_app` for `ConfirmSalesOrder` on 2026-09-20, re-measured with `has_table_privilege()` here.
        // **4 -> 5 on 2026-09-21**, read off this test's own failure: `recomputeTotal()`'s single
        // `UPDATE sales_order`. `insertOrderInside()` did NOT move -- collapsing its two statement shapes
        // to one removed a second *shape*, not a second statement *text*: the column list was always
        // written once and shared, which is what this scan counts.
        // **2 -> 4 on 2026-09-21**, read off this test's own failure twice: `SalesOrderWriter::update()`
        // spells **two** `UPDATE sales_order` texts and issues exactly **one** of them per request --
        // the snapshot shape and the shape without it. The split is not cosmetic and it is not a second
        // write: `everyWriteOfAShipToColumnResolvesTheAddressFirst` matches a single literal spelling
        // both a write keyword and a `ship_to_` column, and the first version of `update()` scattered
        // the column names into keyword-less fragments, which that check's own anti-vacuity control
        // caught. `insertOrderInside()` already carries the same two-texts-one-statement shape. ⚠ **The statement shape is a choice, not an
        // accident.** `RESIDUAL_GAPS.md` G-6 measured that an ORM write reached through an association
        // (`$fulfilment->getSalesOrder()->shipTo(…)`) reddens NEITHER this scan nor
        // `PurchaseOrderRowLockTest`, while a raw `UPDATE sales_order` reddens both — so the update path
        // writes raw SQL deliberately, to be visible here. The entity mutator that made the blind shape
        // possible (`SalesOrder::shipTo()`) was deleted in the same commit; it had zero callers.
        // **5 -> 6 on 2026-09-21** with `UpdateSalesOrderLine`: one `UPDATE sales_order_line`, built from
        // `LINE_COLUMNS`/`LINE_CASTS` so the column list is written once.
        // **6 -> 7 on 2026-09-21** with `DeleteSalesOrderLine`: one `UPDATE sales_order_line SET
        // deleted_at`. A soft delete, so it is an UPDATE this scan counts and not a DELETE it would
        // have to see a grant for.
        // **7 -> 8 on 2026-09-21** with `RestoreSalesOrderLine`: one `UPDATE sales_order_line SET
        // deleted_at = NULL`, the mirror of the archive above.
        // **8 -> 9 on 2026-09-21** with `DeleteSalesOrder`: one `UPDATE sales_order SET deleted_at =
        // clock_timestamp()`. A soft delete; the lines are archived by the cascade trigger, which is outside
        // `src/` and outside this scan.
        // **9 -> 12 on 2026-09-22** with `CreateFulfilment` (sales step 5, commit 4), read off this test's own
        // failure: `FulfilmentWriter`'s `INSERT INTO fulfilment`, `INSERT INTO fulfilment_line` and `UPDATE
        // sales_order_line SET quantity_fulfilled`. The status is an ORM flush and is not counted; the stock
        // statements are Inventory's (`StockDepleter`), counted there.
        'Modules\\Sales' => 12,
        'Modules\\UserManagement' => 5,
    ];

    /** The target key for a registered statement whose table the scan cannot read. */
    private const UNRESOLVED = '(unresolved)';

    /**
     * **Cross-module raw writes that are deliberate: writer FQCN => target table
     * (or {@see self::UNRESOLVED}) => reason.** Every entry must still match a
     * statement the scan finds, so a deviation cannot outlive the write it
     * excuses.
     *
     * @var array<class-string, array<string, non-empty-string>>
     */
    private const REGISTERED_DEVIATIONS = [
        AuthTrailWriter::class => [
            'audit_log' => 'ADR-006 / API.md §7.1 step 8: authentication events are written on the pre-authentication '
                .'erp_authlog principal, which holds INSERT on audit_log and no SELECT. Every AuditLog writer runs on '
                .'erp_app, which §7.2 forbids on this path, so the write cannot go through the owning module. Deliberate, '
                .'owner ruling on API-O-27 (2026-09-14).',
        ],
    ];

    #[Test]
    public function everyRawWriteInAModuleTargetsATableThatModuleOwns(): void
    {
        $statements = self::statementsUnder(\dirname(__DIR__, 2).'/src/Modules');

        // 1. The pin: a scanner that matches nothing, or less than the tree
        //    holds, is red before any ownership assertion can pass vacuously —
        //    and so is one that loses a statement while another is added.
        $perModule = array_count_values(array_column($statements, 'module'));
        ksort($perModule);
        self::assertSame(self::WRITE_STATEMENTS_PER_MODULE, $perModule,
            'The write scan found a different number of write statements per module under src/Modules than '
            .'WRITE_STATEMENTS_PER_MODULE pins. If this commit added or removed a raw SQL/DQL write, update the pin in '
            .'the same commit so the change is visible in review. If it did not, the scanner has stopped seeing '
            .'statements (or started seeing prose) and every ownership assertion below holds over the wrong set.'
            ."\n\nIf the statement you added is an INSERT INTO stock_movement, the count is the least of it: "
            ."everyStockMovementReasonIsRegisteredToItsWriterAndPermission must also be red, and its registry entry is "
            ."the review point (SECURITY_MODEL.md E-25 / E-26 / E-27; history in docs/RESIDUAL_GAPS.md CD-2, closed). "
            ."If that test is green after you add such a write, the scan has gone blind -- stop.");

        // 2. The positive control, in this test so a --filter run carries it:
        //    the same statement parser must resolve a write to every manifest
        //    table and every mapped module entity, and must read a fixture
        //    exactly — prose, locks and upserts out; interpolation, concatenation
        //    and unknown names in, as unresolved.
        foreach (array_keys(SchemaManifest::TABLES) as $table) {
            foreach (["INSERT INTO $table (id) VALUES (1)", "UPDATE $table SET x = 1", "DELETE FROM $table WHERE id = 1", "UPDATE ONLY \"$table\" AS t SET x = 1", "delete from public.$table"] as $sql) {
                $found = self::statementsInLiteral($sql);
                self::assertCount(1, $found, 'The statement parser does not see exactly one write in: '.$sql);
                self::assertSame($table, $found[0]['table'], 'The statement parser does not resolve '.$table.' in: '.$sql);
            }
        }
        $src = \dirname(__DIR__, 2).'/src/';
        $entities = 0;
        foreach (PhpSourceScan::phpFilesUnder($src.'Modules') as $path) {
            // PSR-4: `App\` is `src/`, so the file names its class.
            $class = 'App\\'.str_replace('/', '\\', substr($path, \strlen($src), -\strlen('.php')));
            if (null === ($table = self::tableOfEntity($class))) {
                continue;
            }
            ++$entities;
            foreach (['UPDATE '.$class.' e SET e.x = 1', 'DELETE FROM '.str_replace('\\', '\\\\', $class).' e'] as $dql) {
                self::assertSame([$table], array_column(self::statementsInLiteral($dql), 'table'),
                    'The statement parser does not resolve entity '.$class.' to '.$table.' in: '.$dql);
            }
        }
        self::assertGreaterThan(0, $entities, 'No mapped entity was found under src/Modules, so the DQL arm had no control.');
        self::assertSame(self::FIXTURE_EXPECTED, array_map(
            static fn (array $s): array => [$s['where'], $s['table']],
            self::statementsIn(self::FIXTURE, 'fixture'),
        ), 'The statement parser misreads its fixture.');
        self::assertSame(self::EVASIONS_EXPECTED, array_map(
            static fn (array $s): array => [$s['where'], $s['table']],
            self::statementsIn(self::EVASIONS, 'evasions'),
        ), 'The statement parser misreads its evasions fixture — the writes a RED review planted past it on 2026-09-14, '
            .'and the shapes it already caught that must stay caught.');

        // 3. Ownership, fail closed, with registered deviations.
        $violations = [];
        $matched = [];
        foreach ($statements as $s) {
            $target = $s['table'] ?? self::UNRESOLVED;
            if (isset(self::REGISTERED_DEVIATIONS[$s['class']][$target])) {
                $matched[$s['class']][$target] = true;
                continue;
            }
            if (null === $s['table']) {
                $violations[] = \sprintf('  %s (%s) — %s: %s', $s['where'], $s['at'], $s['problem'], $s['statement']);
            } elseif (($owner = SchemaManifest::TABLES[$s['table']]['module']) !== $s['module']) {
                $violations[] = \sprintf('  %s (%s) — %s writes %s, which SchemaManifest assigns to %s: %s',
                    $s['where'], $s['at'], $s['module'], $s['table'], $owner, $s['statement']);
            }
        }

        self::assertSame([], $violations,
            "ARCHITECTURE.md S-7(c) / A-13: a module may write only the tables SchemaManifest assigns to it, and every "
            ."write's table must be a literal the scan can read. A cross-module write goes through the owning module's "
            ."Application\\Contract command interface; a deliberate exception needs an owner decision and an entry in "
            ."REGISTERED_DEVIATIONS with its reason. A computed table name must become a literal.\n\n"
            ."A stock_movement write belongs to Inventory: a writer in another module goes through an "
            ."Inventory\\Application\\Contract command (W-3; PC-10 names the only two), and its reason is bound to that "
            ."writer by SECURITY_MODEL.md E-26 and checked by everyStockMovementReasonIsRegisteredToItsWriterAndPermission.\n"
            .implode("\n", $violations));

        $stale = [];
        foreach (self::REGISTERED_DEVIATIONS as $class => $targets) {
            foreach ($targets as $target => $reason) {
                if (!isset($matched[$class][$target])) {
                    $stale[] = '  '.$class.' → '.$target.' ('.$reason.')';
                }
            }
        }
        self::assertSame([], $stale,
            "A registered deviation no longer matches any write statement the scan finds. Remove it, so that it cannot "
            ."excuse a different write later:\n".implode("\n", $stale));
    }

    /**
     * **`stock_level.qty_reserved` appears in NO write statement under `src/`** — the tripwire for
     * `docs/RESIDUAL_GAPS.md` **G-7**, and the reason it is here rather than where the design put it.
     *
     * ---------------------------------------------------------------------------
     * The trigger this replaces was MEASURED not to fire
     * ---------------------------------------------------------------------------
     * V2-R-22's design deferred every reservation writer and keyed the deferral to
     * `WriteOwnershipTest::WRITE_STATEMENTS_PER_MODULE`, saying the first commit writing `qty_reserved`
     * *"cannot land without moving the pin"*. **False, and measured false.** Probe **U-2** (`LEAD`,
     * 2026-09-19) added `qty_reserved` to `StockReceiver`'s existing `INSERT … ON CONFLICT DO UPDATE` and
     * ran both named mechanisms: this file's own test answered `OK (1 test, 418 assertions)` and
     * `GrantFootprintTest` answered `OK (17 tests, 784 assertions)`. Both green. The pin counts
     * **statements, not columns**, and this class's docblock excludes `DO UPDATE` as a separate write; and
     * `erp_app` already holds `UPDATE` on `stock_level`, so no grant moves either. That is `SEC-O-1`'s
     * defect inverted — a gap keyed to a mechanism that does not trip.
     *
     * So the residual gets the **Move 219 anti-vacuity shape** instead: assert the absence directly, and
     * carry the residual's text in the failure message. **Proven non-vacuous by re-running U-2's mutation
     * against THIS test: it goes red.** *(This sentence said "the way the ownership assertion above carries
     * CD-2's" until 2026-09-22. That pointer sat on an assertion a correct Inventory writer never trips --
     * measured by `RED` and the spar -- and it was replaced by
     * `everyStockMovementReasonIsRegisteredToItsWriterAndPermission` when CD-2 closed.)*
     *
     * ---------------------------------------------------------------------------
     * What it asserts, and why the whole literal rather than the statement
     * ---------------------------------------------------------------------------
     * Every PHP string literal under `src/` — the whole tree, not only `src/Modules`, because a reservation
     * writer put in `Platform` would evade a module-scoped scan — that spells at least one write statement
     * must not name `qty_reserved`. The unit is the **literal**, not the statement, deliberately: the
     * statement text this scan records is truncated to the keyword plus sixty characters, so a column named
     * late in a long upsert would fall outside it. A literal that both writes and merely reads the column
     * is reported, which is the conservative direction.
     *
     * **A read is not a write and is not refused.** `SELECT qty_reserved FROM stock_level` spells no write
     * statement, so `ListStockLevels` and model D can be built without touching this test — which is
     * correct, because G-7 is about *storing* a reservation.
     *
     * The positive control is inside this test rather than beside it (the empty-set trap, and this project
     * has a named lesson about it): the same predicate must flag a planted upsert that names the column,
     * and must leave a read and an unrelated write alone. The domain is asserted non-empty too, so a
     * scanner that stopped seeing literals cannot pass here by finding nothing.
     */
    #[Test]
    public function noWriteStatementUnderSrcNamesQtyReserved(): void
    {
        // 1. The positive control: the predicate must flag exactly the planted upsert.
        self::assertSame(
            ['Probe::plant'],
            array_column(self::qtyReservedWritesIn(self::QTY_RESERVED_PROBE, 'probe'), 'where'),
            'The qty_reserved predicate does not read its own probe: it must flag an upsert naming the column, and leave '
            .'a SELECT of the column and a write that does not name it alone. Until it does, the assertion below proves '
            .'nothing.'
        );

        // 2. The domain: the same walk must find write statements under src/ at all.
        $src = \dirname(__DIR__, 2).'/src';
        $literals = 0;
        $writes = 0;
        foreach (PhpSourceScan::phpFilesUnder($src) as $path) {
            foreach (self::literalsIn((string) file_get_contents($path)) as [, $literal]) {
                ++$literals;
                $writes += \count(self::statementsInLiteral($literal['value']));
            }
        }
        self::assertGreaterThan(0, $literals, 'The scan found no string literal under src/, so it is not reading the tree.');
        self::assertGreaterThan(0, $writes, 'The scan found no write statement under src/, so the absence below is vacuous.');

        // 3. The assertion.
        $found = [];
        foreach (PhpSourceScan::phpFilesUnder($src) as $path) {
            $at = substr($path, \strlen(\dirname(__DIR__, 2)) + 1);
            foreach (self::qtyReservedWritesIn((string) file_get_contents($path), $at) as $hit) {
                $found[] = '  '.$hit['where'].' ('.$hit['at'].'): '.$hit['statement'];
            }
        }

        self::assertSame([], $found,
            "A write statement under src/ names stock_level.qty_reserved, and nothing has ever written that column.\n\n"
            ."STOP and read docs/RESIDUAL_GAPS.md G-7 before going further. V2-R-22 Half 2 ruled model A on 2026-09-19: "
            ."ConfirmSalesOrder writes no reservation, qty_reserved is a FORWARD DECLARATION in SchemaManifest's "
            ."stock_level entry, and available-to-promise EQUALS on-hand. Overselling is REACHABLE since CreateFulfilment "
            ."(2026-09-22) and model A was RENEWED on that premise by owner ruling R1: refused at the pick in domain terms, "
            ."never negative stock, never a 500, no priority. Writing this column ends that acceptance, and it is not a "
            ."one-line change:\n"
            ."  - the SUCCESSOR MODEL IS RULED (owner ruling R4, 2026-09-22): model D at WAREHOUSE grain (derive demand "
            ."from sales_order_line.quantity - quantity_fulfilled), over model E, and it DERIVES -- it never stores a "
            ."reservation in this column. 'Needs V2-C-12 first' was STRUCK as measured false. Model D at bin grain, as the "
            ."V2-R-22 design wrote it, is NOT ratified. Storing a reservation at confirm (model B) is REFUSED; do not "
            ."re-open it. So a write of this column contradicts a ruling -- it needs the owner first.\n"
            ."  - stock_level_reserved_sane (qty_reserved >= 0 AND qty_reserved <= qty_on_hand) is currently the ONLY "
            ."constraint in the schema refusing negative on-hand, because there is no standalone "
            ."CHECK (qty_on_hand >= 0). Writing a non-zero qty_reserved changes what that constraint means. Do not "
            ."relax it.\n"
            ."  - CreateFulfilment pre-checks on-hand and refuses in domain terms (built 2026-09-22) so an oversell never "
            ."reaches a user as a raw 23514 -- keep it that way.\n"
            ."  - StockDepleter's conditional decrement (WHERE ... AND qty_on_hand >= ?) ASSUMES qty_reserved = 0 and "
            ."deliberately does not name the column. Whoever writes the column must change that predicate in the same "
            ."commit.\n"
            ."  - A WHERE or any other clause naming the column counts here, not only a SET: the unit is the whole "
            ."write literal. If you tripped this with a read inside a write's predicate, the test is not wrong -- it is "
            ."telling you the statement now depends on the column.\n"
            ."If the successor has been ruled and built, this test is what you move, in that commit, with its "
            ."docblock.\nWrites found:\n".implode("\n", $found));
    }

    /**
     * A planted upsert naming `qty_reserved`, a read of the same column, and a write that does not name it
     * — the three cases `noWriteStatementUnderSrcNamesQtyReserved()`'s predicate must separate. This is
     * U-2's mutation in miniature: the column added to an existing `INSERT … ON CONFLICT DO UPDATE`, which
     * is the exact shape the replaced trigger could not see.
     */
    private const QTY_RESERVED_PROBE = <<<'PHP'
        <?php

        namespace App\Modules\Probe\Infrastructure;

        final class Probe
        {
            public function plant(): void
            {
                $upsert = 'INSERT INTO stock_level (id, qty_on_hand, qty_reserved) VALUES (1, 2, 3) '
                    . 'ON CONFLICT (id) DO UPDATE SET qty_on_hand = 1, qty_reserved = stock_level.qty_reserved';
            }

            public function reads(): void
            {
                $read = 'SELECT qty_on_hand, qty_reserved FROM stock_level WHERE id = ?::uuid';
            }

            public function writesSomethingElse(): void
            {
                $other = 'UPDATE stock_level SET qty_on_hand = qty_on_hand + 1 WHERE id = ?::uuid';
            }
        }
        PHP;

    /**
     * Every literal in `$source` that spells a write statement **and** names `qty_reserved`.
     *
     * The match is on the literal's decoded runtime value, so an escaped or concatenated spelling inside
     * one literal is seen; a column name split across two concatenated literals is not, which is the same
     * blind spot every scan in this file has and is disclosed rather than papered over.
     *
     * @return list<array{where: string, at: string, statement: string}>
     */
    private static function qtyReservedWritesIn(string $source, string $at): array
    {
        $found = [];
        foreach (self::literalsIn($source) as [$where, $literal]) {
            if (1 !== preg_match('/qty_reserved/i', $literal['value'])) {
                continue;
            }
            foreach (self::statementsInLiteral($literal['value']) as $statement) {
                $found[] = ['where' => $where, 'at' => $at.':'.$literal['line'], 'statement' => $statement['statement']];
            }
        }

        return $found;
    }

    /**
     * **`SECURITY_MODEL.md` E-26's write-path half: every `stock_movement` reason is bound to the one
     * writer class that may post it, and through that writer to the permission that authorises it.** CD-2
     * closed whole on it (owner ruling R3 of 2026-09-22, option (c)); E-25 and E-27 are its per-kind
     * messages.
     *
     * The registry is keyed on **reason**, and its keys must equal the **live**
     * `stock_movement_reason_values` CHECK (read from `pg_constraint`, never typed) and
     * `StockMovement::REASON_*`. Each entry names the writer classes allowed to post that reason — one
     * today, and `adjustment` / `transfer_*` none — the permission, and the rule the failure prints.
     *
     * **What reddens it** (each one planted in the probe below, which must be read exactly):
     *  1. an unregistered *(class, reason)* pair — a new reason in a registered writer, **or an
     *     already-registered reason written by a NEW class**: a concealing adjustment posted as a receipt
     *     or a shipment, which is CD-2 (ii)'s exact shape;
     *  2. a registered pair no longer found (a stale entry cannot excuse a later write);
     *  3. a `reason` that is not a SQL string literal — a `?`, a concatenation, an interpolation, a
     *     constant — which fails closed as unresolved; and an `INSERT` with no column list, or that is not
     *     `INSERT … VALUES` (`INSERT … SELECT`, `DEFAULT VALUES`);
     *  4. **every tuple of a multi-row `VALUES`** is read — a transfer writer posts a pair (E-25 (b));
     *  5. any other write of the table — `UPDATE`, `DELETE`, `MERGE`, `COPY … FROM` — which the grant
     *     forbids and this names;
     *  6. `'return'` anywhere: refused under E-27;
     *  7. a string literal that is exactly the table name (`'stock_movement'`, bare, quoted or
     *     schema-qualified) outside the three files that legitimately hold it — the shape a DBAL
     *     convenience writer takes, `$connection->insert('stock_movement', …)`, which spells no `INSERT
     *     INTO` (`-sec-red.md` F-2);
     *  8. **any reference to the `StockMovement` entity** — `new StockMovement(`, an import (aliased or
     *     not, grouped or not), `StockMovement::class`, a qualified name, or a literal naming its FQCN —
     *     anywhere under `src/` but the entity's own file. **No constructor site is registered: there
     *     were none on 2026-09-22**, so any is red, and an aliased `new M(` or a
     *     `newInstanceWithoutConstructor()` still needs the class named once (F-3.1).
     *
     * **The whole of `src/`, and the whole literal** (F-3.2, F-3.3): not `src/Modules` only, and not the
     * sixty-character statement excerpt the ownership scan records — a column list alone is longer than
     * that.
     *
     * **Controls:** positive — `StockReceiver → 'receipt'` must be found in the real tree; anti-vacuity —
     * the probe must be read exactly, and the live CHECK must yield at least one value.
     *
     * **Disclosed limits:** the registry binds a reason to a **class**; the class → permission link is
     * derived by {@see self::everyStockWriteContractIsCalledOnlyByItsRegisteredCaller()}, not by this
     * test. SQL assembled from keyword-less pieces, a table name built at run time, `new $class` of a name
     * held in configuration, and SQL issued outside `src/` (a console session as `erp_app`) are outside
     * every static scan in this project.
     */
    #[Test]
    public function everyStockMovementReasonIsRegisteredToItsWriterAndPermission(): void
    {
        // 1. The domain, from the server.
        self::bootKernel();
        $definition = (string) self::getContainer()->get('doctrine.dbal.app_connection')->fetchOne(
            "SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'stock_movement_reason_values'",
        );
        preg_match_all("/'([a-z_]+)'::text/", $definition, $m);
        $live = $m[1];
        sort($live);
        self::assertNotSame([], $live, 'The live stock_movement_reason_values CHECK yielded no value: '.$definition);

        $registered = array_keys(self::STOCK_MOVEMENT_REASONS);
        sort($registered);
        self::assertSame($live, $registered,
            "STOCK_MOVEMENT_REASONS' keys are not the live stock_movement_reason_values CHECK. A reason added to or "
            .'dropped from the CHECK is a decision SECURITY_MODEL.md E-26 must be edited for in the same commit, and so '
            .'is its registry entry here.');

        $declared = [];
        foreach ((new \ReflectionClass(StockMovement::class))->getReflectionConstants() as $constant) {
            if (str_starts_with($constant->getName(), 'REASON_') && \is_string($value = $constant->getValue())) {
                $declared[] = $value;
            }
        }
        sort($declared);
        self::assertSame($live, $declared, 'StockMovement::REASON_* is not the live CHECK.');

        // 2. The probe must be read exactly, or the assertions below hold over the wrong set.
        self::assertSame(self::REASON_PROBE_EXPECTED, self::stockMovementFindingsIn(self::REASON_PROBE, 'probe'),
            'The stock_movement reason scan misreads its probe -- the shapes CD-2 (ii), -sec-red.md F-2 and F-3 name. '
            .'Until it reads them exactly, the registry assertion below proves nothing.');

        // 3. The real tree.
        $src = \dirname(__DIR__, 2).'/src';
        $found = [];
        foreach (PhpSourceScan::phpFilesUnder($src) as $path) {
            $at = substr($path, \strlen(\dirname(__DIR__, 2)) + 1);
            array_push($found, ...self::stockMovementFindingsIn((string) file_get_contents($path), $at));
        }

        self::assertContains(['write', StockReceiver::class, 'receipt'], array_map(
            static fn (array $f): array => [$f[0], $f[1], $f[2]], $found),
            'The positive control failed: the scan did not find StockReceiver writing reason = \'receipt\', so it is not '
            .'reading the tree it is asserting over.');

        $violations = [];
        $seen = [];
        foreach ($found as [$kind, $class, $detail, $where]) {
            if ('write' === $kind) {
                $entry = self::STOCK_MOVEMENT_REASONS[$detail] ?? null;
                if (null === $entry) {
                    $violations[] = \sprintf('  %s (%s) writes reason \'%s\', which the live CHECK does not hold.', $class, $where, $detail);
                } elseif (!\in_array($class, $entry['writers'], true)) {
                    $violations[] = \sprintf("  %s (%s) writes reason '%s', which is not registered to it.\n      %s",
                        $class, $where, $detail, $entry['rule']);
                } else {
                    $seen[$detail][$class] = true;
                }
                continue;
            }
            if ('allowed-name' === $kind && \in_array($where, self::STOCK_MOVEMENT_NAME_HOLDERS, true)) {
                $seen['(name)'][$where] = true;
                continue;
            }
            $violations[] = \sprintf('  %s (%s) — %s', $class, $where, $detail);
        }

        foreach (self::STOCK_MOVEMENT_REASONS as $reason => $entry) {
            foreach ($entry['writers'] as $writer) {
                if (!isset($seen[$reason][$writer])) {
                    $violations[] = \sprintf("  STALE: %s is registered to write '%s' and the scan does not find it doing so. "
                        .'Remove the entry, so it cannot excuse a different write later.', $writer, $reason);
                }
            }
        }
        foreach (self::STOCK_MOVEMENT_NAME_HOLDERS as $holder) {
            if (!isset($seen['(name)'][$holder])) {
                $violations[] = '  STALE: '.$holder.' is allowed to hold the literal \'stock_movement\' and no longer does.';
            }
        }

        self::assertSame([], $violations,
            "A stock_movement write, or a route to one, is not registered to its writer and permission "
            ."(SECURITY_MODEL.md E-25 / E-26 / E-27; history in docs/RESIDUAL_GAPS.md CD-2, closed 2026-09-22).\n\n"
            ."This is the review point for any new stock_movement writer. Register the (class, reason) pair in "
            ."STOCK_MOVEMENT_REASONS in the commit that builds the writer, with the permission that authorises it, and "
            ."only if SECURITY_MODEL.md already binds that reason to that writer -- if it does not, the binding is a SEC "
            ."decision, not an edit here. Do NOT register a class to a reason another writer already posts to make this "
            ."green: that is the concealment E-26 exists to make visible.\n"
            .implode("\n", $violations));
    }

    /**
     * **The class → permission half of E-26, derived rather than declared (`-sec-red.md` F-1, High).**
     *
     * E-26 binds each reason to a writer **class**. That alone does not close CD-2 (ii): a concealing
     * adjustment writer need not write its own `INSERT` at all — it can inject `ReceiveStock` and hand it
     * a made-up goods-receipt id, and the row it produces comes from the *registered* pair
     * `StockReceiver → 'receipt'`. So each stock-write contract's `src/` consumers are pinned here, by
     * **reflection on declared types** — every constructor and method parameter and every property of
     * every class under `src/`, union and intersection members included — the method §8.3a clause (b)
     * already uses (`EditWindowGuardTest`), plus a token scan for the names spelled any other way
     * (`::class`, `new`, a static call). Reflection resolves `use … as` aliases, which a name scan cannot.
     *
     * A consumer of the interface **or** its implementation, other than the implementation itself, must be
     * exactly the registered caller: `GoodsReceiptWriter` for `ReceiveStock` (PC-10's first named write),
     * and `FulfilmentWriter` for `DepleteStock` (PC-10's second) — registered in sales step 5's last commit,
     * the one that built it; the contract had no caller from commit 1 until then.
     *
     * **Disclosed limits:** a service fetched from the container by id, a class name held in configuration,
     * and a closure typed `callable` are not types this sees. The positive control is inside the test:
     * `GoodsReceiptWriter` must be found as a consumer of `ReceiveStock`.
     */
    #[Test]
    public function everyStockWriteContractIsCalledOnlyByItsRegisteredCaller(): void
    {
        $src = \dirname(__DIR__, 2).'/src/';
        $consumers = [];
        foreach (self::STOCK_WRITE_CONTRACTS as $contract => $spec) {
            $consumers[$contract] = [];
        }

        foreach (PhpSourceScan::phpFilesUnder(rtrim($src, '/')) as $path) {
            $class = 'App\\'.str_replace('/', '\\', substr($path, \strlen($src), -\strlen('.php')));
            $source = (string) file_get_contents($path);

            foreach (self::STOCK_WRITE_CONTRACTS as $contract => $spec) {
                if ($class === $contract || $class === $spec['implementation']) {
                    continue;
                }
                $names = [$contract, $spec['implementation']];
                if (self::declaresATypeOf($class, $names) || self::namesAnyOf($source, $names)) {
                    $consumers[$contract][] = $class;
                }
            }
        }

        self::assertContains(GoodsReceiptWriter::class, $consumers[ReceiveStock::class],
            'The positive control failed: GoodsReceiptWriter was not found consuming ReceiveStock, so the resolution '
            .'step is not reading the tree.');

        $expected = array_map(static fn (array $spec): array => $spec['callers'], self::STOCK_WRITE_CONTRACTS);
        foreach ($consumers as &$list) {
            sort($list);
        }
        unset($list);

        self::assertSame($expected, $consumers,
            "A stock-write contract is consumed by a class that is not its registered caller.\n\n"
            ."SECURITY_MODEL.md E-26: each stock_movement reason is bound to one writer, and each writer is reached only "
            ."through its one Application\\Contract interface, whose one src/ caller is the operation PC-10 names -- "
            ."ReceiveStock by CreateGoodsReceipt's writer (goods_receipt.create), DepleteStock by CreateFulfilment's "
            ."(fulfilment.create). A second caller posts that reason under ITS operation's permission: a stock "
            ."adjustment calling ReceiveStock with a made-up goods receipt id books a concealing adjustment as a "
            ."receipt, from the registered pair, and everyStockMovementReasonIsRegisteredToItsWriterAndPermission stays "
            ."green. That is -sec-red.md F-1. A new caller is a SEC decision (PC-10 names exactly two cross-module "
            ."writes); register it in STOCK_WRITE_CONTRACTS only in the commit that builds it, with that ruling.");
    }

    /**
     * Contract interface => its one implementation, and the `src/` classes that may consume either.
     *
     * @var array<class-string, array{implementation: class-string, callers: list<class-string>}>
     */
    private const STOCK_WRITE_CONTRACTS = [
        ReceiveStock::class => [
            'implementation' => StockReceiver::class,
            'callers' => [GoodsReceiptWriter::class],
        ],
        // `CreateFulfilment`'s writer, registered in the commit that builds it (sales step 5, commit 4) -- PC-10's
        // second named cross-module write. It had no caller from commit 1 until then.
        DepleteStock::class => [
            'implementation' => StockDepleter::class,
            'callers' => [FulfilmentWriter::class],
        ],
    ];

    /**
     * **`stock_movement.reason` → the writer classes that may post it → the permission → the rule the
     * failure prints.** Keys equal the live `stock_movement_reason_values` CHECK, asserted above.
     *
     * @var array<string, array{writers: list<class-string>, permission: ?string, rule: non-empty-string}>
     */
    private const STOCK_MOVEMENT_REASONS = [
        'receipt' => [
            'writers' => [StockReceiver::class],
            'permission' => 'goods_receipt.create',
            'rule' => "SECURITY_MODEL.md E-26: 'receipt' is posted by StockReceiver alone, reached through ReceiveStock by "
                ."CreateGoodsReceipt (goods_receipt.create). A second writer of it is a concealment shape, not a refactor.",
        ],
        'fulfilment' => [
            'writers' => [StockDepleter::class],
            'permission' => 'fulfilment.create',
            'rule' => "SECURITY_MODEL.md E-26: 'fulfilment' is posted by StockDepleter alone, reached through DepleteStock "
                ."by CreateFulfilment (fulfilment.create). A second writer of it is a concealment shape, not a refactor.",
        ],
        'adjustment' => [
            'writers' => [],
            'permission' => 'stock_movement.adjust',
            'rule' => "SECURITY_MODEL.md E-25 (a): 'adjustment' has NO registered writer. The first is CreateStockAdjustment's "
                ."(stock_movement.adjust); register it in the commit that builds it, as its own class posting 'adjustment' "
                ."and no other reason, with an audit_log entry naming its actor (stock_movement has no actor column) -- "
                ."that audit half is owed as a test in the same commit. Pre-registering it silences this trigger.",
        ],
        'transfer_out' => [
            'writers' => [],
            'permission' => 'stock_movement.adjust',
            'rule' => "SECURITY_MODEL.md E-25 (b): 'transfer_out' has NO registered writer. The first is CreateStockTransfer's "
                ."(stock_movement.adjust): one class posting transfer_out AND transfer_in only, as pairs of one item, equal "
                ."and opposite, between two distinct locations. The net-zero property is behaviour, not shape: it is OWED "
                ."AS A TEST in the commit that builds CreateStockTransfer.",
        ],
        'transfer_in' => [
            'writers' => [],
            'permission' => 'stock_movement.adjust',
            'rule' => "SECURITY_MODEL.md E-25 (b): 'transfer_in' has NO registered writer -- see transfer_out. A lone "
                ."transfer_in is stock from nowhere: an adjustment wearing a transfer's name.",
        ],
        'return' => [
            'writers' => [],
            'permission' => null,
            'rule' => "SECURITY_MODEL.md E-27: reason = 'return' is REFUSED on every write path until CreateSalesReturn "
                ."ships sales_return.create in §3, in the commit that lifts StockMovement's constructor refusal, lands "
                ."V2-R-15 and rewrites E-27. No permission authorises it, so no writer may be registered to it here.",
        ],
    ];

    /** The three files that legitimately hold the literal `'stock_movement'` (measured 2026-09-22, F-2). */
    private const STOCK_MOVEMENT_NAME_HOLDERS = [
        'src/Modules/Inventory/Infrastructure/Entity/StockMovement.php',
        'src/Platform/Tenancy/SchemaManifest.php',
        'src/SharedKernel/ValueObject/AuditEvent.php',
    ];

    private const STOCK_MOVEMENT_ENTITY = 'App\\Modules\\Inventory\\Infrastructure\\Entity\\StockMovement';

    /**
     * Every finding one source yields: `['write', class, reason, at]` per parsed tuple, and
     * `[problem-kind, class, detail, at]` for everything that fails closed. `allowed-name` findings carry
     * the file path as `at`, so the caller can hold them against {@see self::STOCK_MOVEMENT_NAME_HOLDERS}.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function stockMovementFindingsIn(string $source, string $at): array
    {
        $scan = PhpSourceScan::scan($source);
        $namespace = $scan['namespace'];
        $findings = [];

        $literals = [];
        foreach ($scan['outside'] as $literal) {
            $literals[] = [(string) $literal['class'], $literal];
        }
        foreach ($scan['methods'] as $method) {
            foreach ($method['literals'] as $literal) {
                $literals[] = [$method['class'], $literal];
            }
        }

        $isEntityFile = str_ends_with($at, 'Inventory/Infrastructure/Entity/StockMovement.php');

        foreach ($literals as [$short, $literal]) {
            $class = ltrim($namespace.'\\'.$short, '\\');
            $where = $at.':'.$literal['line'];
            $value = self::blankDollarQuoteTags($literal['value']);

            // F-2: the bare table name, the shape a DBAL convenience writer takes.
            if (1 === preg_match('/\A\s*(?:"?public"?\.)?"?stock_movement"?\s*\z/i', $value)) {
                $findings[] = ['allowed-name', $class, 'holds the literal \'stock_movement\' -- the shape of a DBAL '
                    .'convenience writer ($connection->insert(\'stock_movement\', ...)), which spells no INSERT INTO', $at];
            }

            // F-3.1: the entity's FQCN in a literal (DQL, a class name held as a string).
            if (!$isEntityFile && false !== stripos(str_replace('\\\\', '\\', $value), 'Entity\\StockMovement')) {
                $findings[] = ['entity', $class, 'names the StockMovement entity in a string literal', $where];
            }

            $writes = array_values(array_filter(self::statementsInLiteral($literal['value']),
                static fn (array $s): bool => 'stock_movement' === $s['table']));
            if ([] === $writes) {
                continue;
            }

            $gap = self::GAP;
            preg_match_all("~(?<![\\w\$\\\\])INSERT{$gap}+INTO{$gap}+(?:\"?public\"?\\.)?\"?stock_movement\"?(?![\\w\$])~is",
                $value, $inserts, \PREG_OFFSET_CAPTURE);

            foreach ($inserts[0] as [$match, $offset]) {
                foreach (self::reasonsOfInsert(substr($value, $offset + \strlen($match))) as [$ok, $detail]) {
                    $findings[] = $ok ? ['write', $class, $detail, $where] : ['unresolved', $class, $detail, $where];
                }
            }

            if (\count($writes) > \count($inserts[0])) {
                $findings[] = ['non-insert', $class, 'writes stock_movement other than by INSERT … VALUES (UPDATE, '
                    .'DELETE, MERGE, COPY or a form this scan cannot read). The table is append-only: erp_app holds '
                    .'SELECT and INSERT on it and nothing else.', $where];
            }
        }

        // F-3.1: any reference to the entity class other than from its own file.
        if (!$isEntityFile) {
            foreach (self::entityReferencesIn($source) as $line) {
                $findings[] = ['entity', ltrim($namespace, '\\'), 'references the StockMovement entity (new, an import, '
                    .'::class or a qualified name). No constructor site is registered: an ORM writer of stock_movement is '
                    .'a new writer and owes a registry entry and SECURITY_MODEL.md E-26\'s review', $at.':'.$line];
            }
        }

        return $findings;
    }

    /**
     * The `reason` of every tuple of one `INSERT INTO stock_movement`, from the text right after the table
     * name: the column list, then `VALUES` and its tuples. Each result is `[true, reason]` for a SQL string
     * literal, or `[false, why]` for anything that fails closed.
     *
     * @return list<array{0: bool, 1: string}>
     */
    private static function reasonsOfInsert(string $rest): array
    {
        $gap = self::GAP;
        if (1 !== preg_match("~\\A{$gap}*\\(~s", $rest, $lead)) {
            return [[false, 'an INSERT INTO stock_movement with no column list, so its reason cannot be located']];
        }
        $columns = self::balanced($rest, \strlen($lead[0]) - 1);
        if (null === $columns) {
            return [[false, 'an INSERT INTO stock_movement whose column list does not close inside the literal']];
        }
        $names = array_map(static fn (string $c): string => strtolower(trim($c, " \t\n\r\"")), self::topLevelSplit($columns[0]));
        $position = array_search('reason', $names, true);
        if (false === $position) {
            return [[false, 'an INSERT INTO stock_movement that names no reason column (the column has no default, so this is unreadable)']];
        }

        $after = substr($rest, $columns[1]);
        if (1 !== preg_match("~\\A{$gap}*VALUES{$gap}*~is", $after, $values)) {
            return [[false, 'an INSERT INTO stock_movement that is not INSERT … VALUES (INSERT … SELECT, DEFAULT VALUES): its reason is not a literal this scan can read']];
        }

        $results = [];
        $cursor = \strlen($values[0]);
        while (true) {
            if ('(' !== ($after[$cursor] ?? null)) {
                $results[] = [false, 'a VALUES tuple that does not open where one should'];
                break;
            }
            $tuple = self::balanced($after, $cursor);
            if (null === $tuple) {
                $results[] = [false, 'a VALUES tuple that does not close inside the literal (interpolated, concatenated or computed)'];
                break;
            }
            $elements = self::topLevelSplit($tuple[0]);
            $element = trim($elements[$position] ?? '');
            $results[] = 1 === preg_match("/\\A'([a-z_]+)'(?:::text)?\\z/", $element, $r)
                ? [true, $r[1]]
                : [false, 'a reason that is not a SQL string literal ('.('' === $element ? 'missing' : $element).'): it fails closed as unresolved'];

            if (1 !== preg_match("~\\A{$gap}*,{$gap}*~s", substr($after, $tuple[1]), $comma)) {
                break;
            }
            $cursor = $tuple[1] + \strlen($comma[0]);
        }

        return $results;
    }

    /**
     * The inside of the parenthesis opening at `$open`, and the offset just past its close, respecting
     * single-quoted SQL strings; null when it does not close.
     *
     * @return array{0: string, 1: int}|null
     */
    private static function balanced(string $text, int $open): ?array
    {
        $depth = 0;
        $quoted = false;
        for ($i = $open, $n = \strlen($text); $i < $n; ++$i) {
            $c = $text[$i];
            if ($quoted) {
                if ("'" === $c) {
                    $quoted = false;
                }
                continue;
            }
            if ("'" === $c) {
                $quoted = true;
            } elseif ('(' === $c) {
                ++$depth;
            } elseif (')' === $c && 0 === --$depth) {
                return [substr($text, $open + 1, $i - $open - 1), $i + 1];
            }
        }

        return null;
    }

    /** @return list<string> `$text` split on commas outside parentheses and single-quoted strings */
    private static function topLevelSplit(string $text): array
    {
        $parts = [];
        $depth = 0;
        $quoted = false;
        $current = '';
        for ($i = 0, $n = \strlen($text); $i < $n; ++$i) {
            $c = $text[$i];
            if ($quoted) {
                $quoted = "'" !== $c;
            } elseif ("'" === $c) {
                $quoted = true;
            } elseif ('(' === $c) {
                ++$depth;
            } elseif (')' === $c) {
                --$depth;
            } elseif (',' === $c && 0 === $depth) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $c;
        }
        $parts[] = $current;

        return $parts;
    }

    /**
     * Lines of `$source` on which the `StockMovement` entity is named as code: a short name `StockMovement`
     * (after `new`, before `::`, in a `use` list or as a type), or any qualified name ending in it.
     *
     * @return list<int>
     */
    private static function entityReferencesIn(string $source): array
    {
        $lines = [];
        foreach (token_get_all($source) as $token) {
            if (!\is_array($token)) {
                continue;
            }
            [$id, $text, $line] = $token;
            if (\T_STRING === $id && 'StockMovement' === $text) {
                $lines[] = $line;
            } elseif (\in_array($id, [\T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_NAME_RELATIVE], true)
                && 1 === preg_match('/(?:^|\\\\)StockMovement$/', $text)) {
                $lines[] = $line;
            }
        }

        return array_values(array_unique($lines));
    }

    /**
     * Whether `$class` (if it loads) declares any of `$names` as a type: constructor and method parameters,
     * properties, union and intersection members. Reflection resolves `use … as` aliases.
     *
     * @param list<class-string> $names
     */
    private static function declaresATypeOf(string $class, array $names): bool
    {
        if (!class_exists($class) && !interface_exists($class) && !trait_exists($class)) {
            return false;
        }
        $reflection = new \ReflectionClass($class);

        $types = [];
        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            foreach ($method->getParameters() as $parameter) {
                $types[] = $parameter->getType();
            }
            $types[] = $method->getReturnType();
        }
        foreach ($reflection->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() === $class) {
                $types[] = $property->getType();
            }
        }

        foreach ($types as $type) {
            $named = match (true) {
                $type instanceof \ReflectionNamedType => [$type],
                $type instanceof \ReflectionUnionType, $type instanceof \ReflectionIntersectionType => $type->getTypes(),
                default => [],
            };
            foreach ($named as $one) {
                if ($one instanceof \ReflectionNamedType && !$one->isBuiltin()) {
                    foreach ($names as $name) {
                        if (is_a($one->getName(), $name, true)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Whether `$source` names any of `$names` as code — the short name or a qualified name ending in it —
     * which catches `X::class`, `new X`, `X::method()` and an import, whether or not a type declares it.
     *
     * @param list<class-string> $names
     */
    private static function namesAnyOf(string $source, array $names): bool
    {
        $shorts = array_map(static fn (string $n): string => substr((string) strrchr('\\'.$n, '\\'), 1), $names);
        foreach (token_get_all($source) as $token) {
            if (!\is_array($token)) {
                continue;
            }
            if (\T_STRING === $token[0] && \in_array($token[1], $shorts, true)) {
                return true;
            }
            if (\in_array($token[0], [\T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_NAME_RELATIVE], true)) {
                foreach ($shorts as $short) {
                    if (1 === preg_match('/(?:^|\\\\)'.preg_quote($short, '/').'$/', $token[1])) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * The shapes the reason scan must separate. Every class here is in `Modules\Inventory` so none of it
     * is a cross-module finding for the ownership scan; each method is one case.
     */
    private const REASON_PROBE = <<<'PHP'
        <?php

        namespace App\Modules\Inventory\Application;

        use App\Modules\Inventory\Infrastructure\Entity\StockMovement as M;

        final class ReasonProbe
        {
            private const SQL = "INSERT INTO stock_movement (id, reason, source_type) VALUES (?::uuid, 'receipt', 'goods_receipt')";

            public function pairOfReasons(): void
            {
                $sql = "INSERT INTO stock_movement (id, quantity, reason) VALUES (?, -1, 'transfer_out'), (?, 1, 'transfer_in')";
            }

            public function bound(): void
            {
                $sql = 'INSERT INTO stock_movement (id, reason) VALUES (?::uuid, ?)';
            }

            public function concatenated(string $r): void
            {
                $sql = "INSERT INTO stock_movement (id, reason) VALUES (?::uuid, '".$r."')";
            }

            public function selected(): void
            {
                $sql = 'INSERT INTO stock_movement (id, reason) SELECT id, reason FROM stock_movement';
            }

            public function columnless(): void
            {
                $sql = "INSERT INTO stock_movement VALUES (?::uuid, 'receipt')";
            }

            public function updated(): void
            {
                $sql = "UPDATE stock_movement SET reason = 'receipt' WHERE id = ?::uuid";
            }

            public function convenience(): void
            {
                $this->connection->insert('stock_movement', ['reason' => 'adjustment']);
            }

            public function constructed(): void
            {
                $movement = new M();
            }
        }
        PHP;

    /** What the probe must yield, in order: literals in the class body first, then per method. */
    private const REASON_PROBE_EXPECTED = [
        ['write', 'App\\Modules\\Inventory\\Application\\ReasonProbe', 'receipt', 'probe:9'],
        ['write', 'App\\Modules\\Inventory\\Application\\ReasonProbe', 'transfer_out', 'probe:13'],
        ['write', 'App\\Modules\\Inventory\\Application\\ReasonProbe', 'transfer_in', 'probe:13'],
        ['unresolved', 'App\\Modules\\Inventory\\Application\\ReasonProbe', 'a reason that is not a SQL string literal (?): it fails closed as unresolved', 'probe:18'],
        ['unresolved', 'App\\Modules\\Inventory\\Application\\ReasonProbe', 'a VALUES tuple that does not close inside the literal (interpolated, concatenated or computed)', 'probe:23'],
        ['unresolved', 'App\\Modules\\Inventory\\Application\\ReasonProbe', 'an INSERT INTO stock_movement that is not INSERT … VALUES (INSERT … SELECT, DEFAULT VALUES): its reason is not a literal this scan can read', 'probe:28'],
        ['unresolved', 'App\\Modules\\Inventory\\Application\\ReasonProbe', 'an INSERT INTO stock_movement with no column list, so its reason cannot be located', 'probe:33'],
        ['non-insert', 'App\\Modules\\Inventory\\Application\\ReasonProbe', 'writes stock_movement other than by INSERT … VALUES (UPDATE, DELETE, MERGE, COPY or a form this scan cannot read). The table is append-only: erp_app holds SELECT and INSERT on it and nothing else.', 'probe:38'],
        ['allowed-name', 'App\\Modules\\Inventory\\Application\\ReasonProbe', 'holds the literal \'stock_movement\' -- the shape of a DBAL convenience writer ($connection->insert(\'stock_movement\', ...)), which spells no INSERT INTO', 'probe'],
        ['entity', 'App\\Modules\\Inventory\\Application', 'references the StockMovement entity (new, an import, ::class or a qualified name). No constructor site is registered: an ORM writer of stock_movement is a new writer and owes a registry entry and SECURITY_MODEL.md E-26\'s review', 'probe:5'],
    ];

    /**
     * **`RESIDUAL_GAPS.md` G-6's closure check — option (a): one shared ownership method, and every
     * writer of `sales_order.ship_to_*` reaches it.**
     *
     * ---------------------------------------------------------------------------
     * Why this test exists at all
     * ---------------------------------------------------------------------------
     * `sales_order` copies four address columns from `customer_address` and **stores no address id**.
     * `SalesOrderWriter::shipToInside()` proves at write time that the address belongs to the order's
     * customer and is a `shipping` address (V2-S-9), but the check leaves **no residue**: nothing in the
     * row, the trail or any index records which address was read, so nothing can ever detect afterwards
     * that a snapshot came from another customer's book. The owner accepted that on 2026-09-19 and
     * refused both offered mitigations, on the condition that the **second writer** decides how it is
     * held to the same rule. `UpdateSalesOrder` is that second writer, and this is that decision: **one
     * shared method, with its callers derived from `src/`.**
     *
     * ---------------------------------------------------------------------------
     * ⚠ What this check CANNOT see, and it is the shape G-6 itself calls the likely one
     * ---------------------------------------------------------------------------
     * The domain is **write statements naming a `ship_to_` column**. An ORM mutator call — the
     * `$fulfilment->getSalesOrder()->shipTo(…)` shape — **names no column and is not a statement**, so
     * it is outside this domain exactly as it is outside `PurchaseOrderRowLockTest`'s. **This check does
     * not close that hole and must not be read as closing it.** What the same commit did instead was
     * remove the hole's only vehicle: `SalesOrder::shipTo()` was **deleted**, having been measured at
     * zero callers. A new entity mutator for these columns re-opens G-6 and owes its entry an edit.
     *
     * The positive control is inside the test (the empty-set trap): a planted `UPDATE` naming a
     * `ship_to_` column in a method that does **not** resolve the address must be flagged, and one that
     * does must not. Without it, a scan that stopped reading literals would pass by finding nothing.
     */
    #[Test]
    public function everyWriteOfAShipToColumnResolvesTheAddressFirst(): void
    {
        // 1. The positive control: the predicate must flag the unresolved plant and spare the resolved one.
        self::assertSame(
            ['ShipToProbe::unresolved'],
            self::shipToWritesNotResolvingOwnership(self::SHIP_TO_PROBE, 'probe'),
            'The ship-to predicate does not read its own probe: it must flag a write naming a ship_to_ column in a '
            .'method that never calls shipToInside(), and leave one that does alone. Until it does, the assertion '
            .'below proves nothing.'
        );

        // 2. The domain: the real tree must contain such writes at all, or the absence is vacuous.
        $src = \dirname(__DIR__, 2).'/src';
        $writers = 0;
        foreach (PhpSourceScan::phpFilesUnder($src) as $path) {
            foreach (self::literalsIn((string) file_get_contents($path)) as [$where, $literal]) {
                if (1 === preg_match('/ship_to_/i', $literal['value']) && [] !== self::statementsInLiteral($literal['value'])) {
                    ++$writers;
                }
            }
        }
        self::assertGreaterThan(1, $writers,
            'The scan found fewer than two write statements naming a ship_to_ column under src/. G-6 fired on the '
            .'SECOND writer, so if this is 1 or 0 the scan has stopped seeing one of them and the assertion below is '
            .'vacuous.');

        // 3. The assertion.
        $found = [];
        foreach (PhpSourceScan::phpFilesUnder($src) as $path) {
            $at = substr($path, \strlen(\dirname(__DIR__, 2)) + 1);
            $found = [...$found, ...self::shipToWritesNotResolvingOwnership((string) file_get_contents($path), $at)];
        }

        self::assertSame([], $found,
            "A write statement under src/ sets a sales_order.ship_to_* column from a method that never reaches "
            ."SalesOrderWriter::shipToInside().\n\n"
            ."STOP and read docs/RESIDUAL_GAPS.md G-6. Those four columns are a SNAPSHOT of a customer_address row "
            ."and the order stores no address id, so a snapshot taken from another customer's address book is "
            ."undetectable afterwards -- by any constraint, any index and any trail row. shipToInside() is the one "
            ."place the rule 'this address belongs to this customer' is written (WHERE id = ? AND customer_id = ? AND "
            ."kind = 'shipping'), and G-6 was closed by option (a): every writer reaches it.\n"
            ."  - If you are adding a fulfilment- or invoice-side snapshot, call shipToInside() -- or state in G-6 "
            ."why that rule does not apply to it, which is a DECISION WITH AN OWNER and not a refactor.\n"
            ."  - Do NOT satisfy this test by moving the write into a method that merely mentions shipToInside().\n"
            ."  - ⚠ This scan is COLUMN-shaped. An ORM mutator (\$x->getSalesOrder()->shipTo(...)) names no column "
            ."and is invisible here AND in PurchaseOrderRowLockTest. SalesOrder::shipTo() was deleted on 2026-09-21 "
            ."to remove that shape's vehicle; re-adding one re-opens G-6.\n\n"
            .implode("\n", $found));
    }

    /**
     * Methods holding a write statement that names a `ship_to_` column while never reaching
     * `shipToInside()`.
     *
     * The reachability step is the same fixpoint over `calls` the rest of this file uses, so a write
     * placed in a private helper still counts as its public caller's.
     *
     * @return list<string>
     */
    private static function shipToWritesNotResolvingOwnership(string $source, string $at): array
    {
        $scanned = PhpSourceScan::scan($source);

        $resolves = [];
        $calls = [];
        foreach ($scanned['methods'] as $method) {
            $calls[$method['name']] = $method['calls'];
            if (\in_array('shipToInside', $method['calls'], true)) {
                $resolves[$method['name']] = true;
            }
        }

        // 1. Forward: a method that reaches `shipToInside()` through its own calls resolves.
        do {
            $before = \count($resolves);
            foreach ($calls as $name => $called) {
                foreach ($called as $one) {
                    if (isset($resolves[$one])) {
                        $resolves[$name] = true;
                    }
                }
            }
        } while (\count($resolves) !== $before);

        // 2. Backward, and this half was added because the check FAILED without it on the tree it was
        // written for -- which is the outcome that proves it is doing something. `CreateSalesOrder`
        // resolves the address in `create()` and hands the four values to `insertOrderInside()`, so the
        // helper holding the write reaches nothing itself. The rule that covers it honestly is *"every
        // caller of this helper resolved the address"* -- **every**, not *some*: a second caller that
        // skips the resolver must redden this, which is exactly the harm G-6 describes.
        //
        // ⚠ A helper with NO caller in its own class is never credited this way.
        $callers = [];
        foreach ($calls as $name => $called) {
            foreach ($called as $one) {
                $callers[$one][] = $name;
            }
        }
        do {
            $before = \count($resolves);
            foreach ($callers as $name => $its) {
                if (isset($resolves[$name])) {
                    continue;
                }
                $its = array_values(array_unique(array_filter($its, static fn (string $caller): bool => $caller !== $name)));
                if ([] !== $its && $its === array_values(array_filter($its, static fn (string $caller): bool => isset($resolves[$caller])))) {
                    $resolves[$name] = true;
                }
            }
        } while (\count($resolves) !== $before);

        $found = [];
        foreach (self::literalsIn($source) as [$where, $literal]) {
            if (1 !== preg_match('/ship_to_/i', $literal['value']) || [] === self::statementsInLiteral($literal['value'])) {
                continue;
            }

            $method = str_contains($where, '::') ? substr((string) strrchr($where, ':'), 1) : $where;
            if (isset($resolves[$method])) {
                continue;
            }

            $found[] = $where;
        }

        return array_values(array_unique($found));
    }

    /**
     * G-6's probe, covering **both** directions the predicate walks:
     *
     *   - `resolved` writes the columns and reaches the ownership method through a private helper
     *     (forward);
     *   - `writesOnBehalf` holds a write and reaches nothing, but its only caller `handsOff` resolved
     *     the address first (backward) — this is `CreateSalesOrder`'s real shape;
     *   - `unresolved` writes them and neither reaches nor is called by a resolver.
     *
     * **Only `unresolved` may be flagged.** If the backward half is deleted the probe reddens on
     * `writesOnBehalf`, and if the forward half is deleted it reddens on `resolved` — so neither can be
     * removed silently.
     */
    private const SHIP_TO_PROBE = <<<'PHP'
        <?php

        namespace App\Modules\Probe\Application;

        final class ShipToProbe
        {
            public function resolved(): void
            {
                $this->resolve();
                $sql = 'UPDATE sales_order SET ship_to_city = ? WHERE id = ?';
            }

            public function unresolved(): void
            {
                $sql = 'UPDATE sales_order SET ship_to_city = ? WHERE id = ?';
            }

            public function handsOff(): void
            {
                $this->shipToInside();
                $this->writesOnBehalf();
            }

            private function writesOnBehalf(): void
            {
                $sql = 'UPDATE sales_order SET ship_to_city = ? WHERE id = ?';
            }

            private function resolve(): void
            {
                $this->shipToInside();
            }
        }
        PHP;

    /**
     * Every string literal in `$source`, each with the `Class::method` (or `Class (class body)`) that holds
     * it. The same walk `statementsIn()` makes, without the module and ownership bookkeeping that is
     * meaningless outside `src/Modules`.
     *
     * @return list<array{0: string, 1: array{text: string, value: string, line: int}}>
     */
    private static function literalsIn(string $source): array
    {
        $scan = PhpSourceScan::scan($source);
        $literals = [];

        foreach ($scan['outside'] as $literal) {
            $literals[] = [($literal['class'] ?? '(file)').' (class body)', $literal];
        }
        foreach ($scan['methods'] as $key => $method) {
            foreach ($method['literals'] as $literal) {
                $literals[] = [$key, $literal];
            }
        }

        return $literals;
    }

    private const FIXTURE = <<<'PHP'
        <?php

        namespace App\Modules\Probe\Infrastructure;

        /**
         * Prose in a docblock: INSERT INTO audit_log and UPDATE purchase_order SET x are not statements here.
         */
        final class Fixture
        {
            private const LOCK = 'SELECT 1 FROM purchase_order WHERE id = ?::uuid FOR NO KEY UPDATE';

            private const CLEAR = 'DELETE FROM auth_lockout WHERE slug_normalised = ?';

            public function write(string $table): void
            {
                // UPDATE stock_level SET qty_on_hand = 0 -- a comment, not a statement
                $upsert = 'INSERT INTO stock_level (id) VALUES (1) ON CONFLICT (id) DO UPDATE SET qty_on_hand = 1';
                $interpolated = "UPDATE {$table} SET x = 1";
                $concatenated = 'DELETE FROM '.$table.' WHERE id = 1';
                $prose = 'Update is not allowed for this operation.';
                $dql = 'UPDATE App\\Modules\\Purchasing\\Infrastructure\\Entity\\PurchaseOrder o SET o.status = 1';
                $unknown = <<<SQL
                    INSERT INTO no_such_table (id) VALUES (1)
                    SQL;
                $locked = "SELECT 1 FROM purchase_order FOR UPDATE";
            }
        }
        PHP;

    private const FIXTURE_EXPECTED = [
        ['Fixture (class body)', 'auth_lockout'],
        ['Fixture::write', 'stock_level'],
        ['Fixture::write', null],
        ['Fixture::write', null],
        ['Fixture::write', 'purchase_order'],
        ['Fixture::write', null],
    ];

    /**
     * **Planted writes, RED review 2026-09-14.** Every method under `planted`
     * wrote `audit_log` from a Purchasing class, and the scan at `18a4750` stayed
     * green on each: the look-behind read the `n` / `t` of an escaped `\n` / `\t`
     * as a word character; a lower-case `update` followed by an escaped newline
     * was prose; a SQL comment ending in `DO` switched on the `ON CONFLICT`
     * exception; `MERGE`, `TRUNCATE` and `COPY … FROM` were not keywords. The
     * methods under `caught` were caught then and are pinned so the fix cannot
     * lose them. The methods under `notWrites` are what the new keywords must
     * not turn into statements.
     *
     * **`$k`…`$p` and `notWrites::$d`, V1-R-6(a), V4 B4c (2026-09-16):** a keyword
     * glued to a dollar-quote opening tag — tagged (`$q$UPDATE`), an underscore
     * tag spelt with `\$` escapes in a double-quoted string (`$l`), untagged in a
     * SQL function body (`$m`), a comment between the glued keyword and its
     * target (`$n`), a nowdoc `DO $body$ … $q$MERGE INTO` (`$o`) and an escaped
     * heredoc `TRUNCATE` (`$p`). **All six were missed before the fix** (the
     * fixture comparison lost exactly six `planted` rows); `a$b$UPDATE` is an
     * identifier, not a tag, and stays not-a-statement.
     */
    private const EVASIONS = <<<'PHP'
        <?php

        namespace App\Modules\Purchasing\Infrastructure;

        final class Evasions
        {
            public function planted(): void
            {
                $a = "SELECT 1;\nINSERT INTO audit_log (id) VALUES (1)";
                $b = "SELECT 1;\tDELETE FROM audit_log WHERE id = 1";
                $c = "update audit_log\nset id = 107";
                $d = <<<'SQL'
                    SELECT 1; -- nothing more to DO
                    UPDATE audit_log SET id = 108
                    SQL;
                $e = 'MERGE INTO audit_log a USING (SELECT 1 AS id) s ON a.id = s.id WHEN MATCHED THEN DELETE';
                $f = 'TRUNCATE audit_log';
                $g = 'COPY audit_log FROM STDIN';
                $h = "update /* then */ audit_log set id = 109";
                $i = "SELECT 1; /* DO */\nUPDATE audit_log SET id = 110";
                $j = 'TRUNCATE TABLE ONLY stock_level, audit_log';
                $k = 'DO $$BEGIN EXECUTE $q$UPDATE audit_log SET id = 111$q$; END$$';
                $l = "DO \$\$BEGIN EXECUTE \$_x\$DELETE FROM audit_log WHERE id = 112\$_x\$; END\$\$";
                $m = 'CREATE FUNCTION p() RETURNS void LANGUAGE sql AS $$INSERT INTO audit_log (id) VALUES (113)$$';
                $n = 'DO $$BEGIN EXECUTE $q$UPDATE/* glued */audit_log SET id = 114$q$; END$$';
                $o = <<<'SQL'
                    DO $body$BEGIN EXECUTE $q$MERGE INTO audit_log a USING (SELECT 1 AS id) s ON a.id = s.id WHEN MATCHED THEN DELETE$q$; END$body$
                    SQL;
                $p = <<<SQL
                    DO \$\$BEGIN EXECUTE \$q\$TRUNCATE audit_log\$q\$; END\$\$
                    SQL;
            }

            public function caught(string $t): void
            {
                $a = 'WITH x AS (SELECT 1) INSERT INTO audit_log (id) SELECT 1';
                $b = 'insert into "public"."audit_log" (id) values (1)';
                $c = sprintf('UPDATE %s SET x = 1', $t);
                $d = <<<'SQL'
                    DELETE FROM audit_log WHERE id = 1
                    SQL;
                $e = 'UPDATE App\\Modules\\AuditLog\\Infrastructure\\Entity\\AuditLogEntry e SET e.id = 1';
            }

            public function notWrites(): void
            {
                $a = 'COPY audit_log TO STDOUT';
                $b = 'Copy the order before you merge into another, or truncate the note.';
                $c = "SELECT 1 FROM purchase_order WHERE id = ?\nFOR NO KEY UPDATE";
                $d = 'SELECT a$b$UPDATE FROM purchase_order';
            }
        }
        PHP;

    private const EVASIONS_EXPECTED = [
        ['Evasions::planted', 'audit_log'],
        ['Evasions::planted', 'audit_log'],
        ['Evasions::planted', 'audit_log'],
        ['Evasions::planted', 'audit_log'],
        ['Evasions::planted', 'audit_log'],
        ['Evasions::planted', 'audit_log'],
        ['Evasions::planted', 'audit_log'],
        ['Evasions::planted', 'audit_log'],
        ['Evasions::planted', 'audit_log'],
        ['Evasions::planted', 'stock_level'],
        ['Evasions::planted', 'audit_log'],
        ['Evasions::planted', 'audit_log'],
        ['Evasions::planted', 'audit_log'],
        ['Evasions::planted', 'audit_log'],
        ['Evasions::planted', 'audit_log'],
        ['Evasions::planted', 'audit_log'],
        ['Evasions::planted', 'audit_log'],
        ['Evasions::caught', 'audit_log'],
        ['Evasions::caught', 'audit_log'],
        ['Evasions::caught', null],
        ['Evasions::caught', 'audit_log'],
        ['Evasions::caught', 'audit_log'],
    ];

    /**
     * @return list<array{class: string, module: string, where: string, at: string, statement: string, table: ?string, problem: ?string}>
     */
    private static function statementsUnder(string $directory): array
    {
        $statements = [];
        foreach (PhpSourceScan::phpFilesUnder($directory) as $path) {
            $at = substr($path, \strlen(\dirname(__DIR__, 2)) + 1);
            array_push($statements, ...self::statementsIn((string) file_get_contents($path), $at));
        }

        return $statements;
    }

    /**
     * @return list<array{class: string, module: string, where: string, at: string, statement: string, table: ?string, problem: ?string}>
     */
    private static function statementsIn(string $source, string $at): array
    {
        $scan = PhpSourceScan::scan($source);
        $namespace = $scan['namespace'];
        $module = 1 === preg_match('/^App\\\\(Modules\\\\[A-Za-z0-9_]+)(?:\\\\|$)/', $namespace, $m) ? $m[1] : '(not a module: '.$namespace.')';

        $literals = [];
        foreach ($scan['outside'] as $literal) {
            $literals[] = [(string) $literal['class'], ($literal['class'] ?? '(file)').' (class body)', $literal];
        }
        foreach ($scan['methods'] as $key => $method) {
            foreach ($method['literals'] as $literal) {
                $literals[] = [$method['class'], $key, $literal];
            }
        }

        $statements = [];
        foreach ($literals as [$class, $where, $literal]) {
            foreach (self::statementsInLiteral($literal['value']) as $found) {
                $statements[] = [
                    'class' => ltrim($namespace.'\\'.$class, '\\'),
                    'module' => $module,
                    'where' => $where,
                    'at' => $at.':'.$literal['line'],
                ] + $found;
            }
        }

        return $statements;
    }

    /** The dollar-quote delimiters in `$text`, replaced by spaces; see `statementsInLiteral()`. */
    private static function blankDollarQuoteTags(string $text): string
    {
        $offset = 0;
        while (1 === preg_match('/(?<![\w$])\$(?:[A-Za-z_\x80-\xff][\w\x80-\xff]*)?\$/', $text, $m, \PREG_OFFSET_CAPTURE, $offset)) {
            [$tag, $at] = $m[0];
            $length = \strlen($tag);
            $text = substr_replace($text, str_repeat(' ', $length), $at, $length);
            if (false !== ($close = strpos($text, $tag, $at + $length))) {
                $text = substr_replace($text, str_repeat(' ', $length), $close, $length);
            }
            $offset = $at + $length;
        }

        return $text;
    }

    /**
     * One unit of what PostgreSQL reads as a token separator: whitespace, a
     * `--` line comment, or a slash-star block comment. Used between the words of
     * a keyword and before its target, so a comment is never where a write hides.
     */
    private const GAP = '(?:\s++|--[^\n]*+|/\*.*?\*/)';

    /**
     * The write statements one literal piece spells, each with its resolved
     * table or, if it cannot be read as a manifest table, the reason.
     *
     * `$text` is the literal's runtime value (`PhpSourceScan`'s `value`): quotes
     * removed and escapes decoded, so `"SELECT 1;\nUPDATE t"` has a newline
     * before the keyword and not an `n`.
     *
     * @return list<array{statement: string, table: ?string, problem: ?string}>
     */
    private static function statementsInLiteral(string $text): array
    {
        // **A dollar-quote delimiter is a token separator** (V1-R-6(a), V4 B4c).
        // The look-behind below refuses a keyword preceded by `$`, so an
        // identifier such as `a$b$UPDATE` is not a statement — and until
        // 2026-09-16 that also hid `EXECUTE $q$UPDATE audit_log …$q$` and
        // `AS $$INSERT INTO …$$`, whose keyword is glued to an opening tag.
        // Every opening `$tag$` / `$$` — one not glued to an identifier, as an
        // opening tag never is per PostgreSQL's lexer — is blanked to spaces of
        // the same length, and so is the next occurrence of the same tag, which
        // closes it and MAY be glued (`TRUNCATE audit_log$q$`). Offsets and the
        // `DO` / `FOR` look-back are unchanged.
        $text = self::blankDollarQuoteTags($text);
        $gap = self::GAP;
        preg_match_all(
            "~(?<![\\w\$\\\\])(INSERT{$gap}+INTO|DELETE{$gap}+FROM|MERGE{$gap}+INTO|UPDATE|TRUNCATE|COPY)(?![\\w\$])~is",
            $text, $matches, \PREG_OFFSET_CAPTURE,
        );

        $found = [];
        foreach ($matches[1] as [$keyword, $offset]) {
            $kind = strtoupper((string) strstr($keyword.' ', ' ', true) ?: $keyword);
            $kind = (string) preg_replace('/[^A-Z].*$/s', '', $kind);
            $isUpperCase = str_starts_with($keyword, $kind);
            $before = substr($text, 0, $offset);
            $rest = substr($text, $offset + \strlen($keyword));
            $statement = (string) preg_replace('/\s+/', ' ', trim($keyword.substr($rest, 0, 60)));

            if ('UPDATE' === $kind && self::isConflictArmOrRowLock($before)) {
                continue; // ON CONFLICT … DO UPDATE, or FOR [NO KEY] UPDATE
            }

            $modifiers = match ($kind) {
                'TRUNCATE' => "(?:TABLE{$gap}+)?(?:ONLY{$gap}+)?",
                'INSERT', 'COPY' => '',
                default => "(?:ONLY{$gap}+)?",
            };
            preg_match("~^{$gap}*{$modifiers}~is", $rest, $lead);
            $afterLead = substr($rest, \strlen($lead[0]));
            $target = self::nameAt($afterLead);

            // A non-upper-case UPDATE / MERGE / TRUNCATE / COPY is prose ("Update
            // is not allowed", "copy the order") unless it names a manifest
            // table or mapped entity, has the `<name> [alias] SET` shape, or ends
            // the literal piece. INSERT INTO and DELETE FROM are two words and
            // always statements.
            if (!$isUpperCase && \in_array($kind, ['UPDATE', 'MERGE', 'TRUNCATE', 'COPY'], true)
                && 1 !== preg_match("~^{$gap}*$~s", $rest)
                && null === $target['table']
                && !('UPDATE' === $kind && 1 === preg_match('/^\s+(?:ONLY\s+)?\S+(?:\s+(?:AS\s+)?\w+)?\s+SET\b/i', $rest))
            ) {
                continue;
            }

            if ('COPY' === $kind) {
                if (null === $target['length'] && 1 === preg_match('/^\(/', $afterLead)) {
                    continue; // COPY (SELECT …) TO: a read
                }
                if (null !== $target['length'] && 1 === preg_match("~^{$gap}*(?:\\([^)]*\\){$gap}*)?TO(?![\\w\$])~is", substr($afterLead, $target['length']))) {
                    continue; // COPY t TO: a read
                }
            }

            if (null === $target['length']) {
                $found[] = ['statement' => $statement, 'table' => null, 'problem' => 'no literal table name follows the keyword (interpolated, concatenated or computed)'];
                continue;
            }

            $found[] = ['statement' => $statement, 'table' => $target['table'], 'problem' => $target['problem']];

            // TRUNCATE a, b, c writes every table in its list.
            while ('TRUNCATE' === $kind
                && 1 === preg_match("~^{$gap}*,{$gap}*(?:ONLY{$gap}+)?~is", $afterLead = substr($afterLead, $target['length']), $comma)
                && null !== ($target = self::nameAt($afterLead = substr($afterLead, \strlen($comma[0]))))['length']
            ) {
                $found[] = ['statement' => $statement, 'table' => $target['table'], 'problem' => $target['problem']];
            }
        }

        return $found;
    }

    /**
     * Whether the `UPDATE` that follows `$before` is the arm of `ON CONFLICT … DO`
     * or a `FOR [NO KEY] UPDATE` row lock. **Not if that `DO` / `FOR` / `KEY` sits
     * in a SQL comment**: a nowdoc reading `-- nothing to DO` and then
     * `UPDATE audit_log SET …` was planted past this exception on 2026-09-14.
     * The comment test is deliberately crude — a `--` anywhere earlier on the
     * word's line, or more comment openers than closers before it — and it errs towards
     * denying the exception, which reports a write rather than hiding one.
     */
    private static function isConflictArmOrRowLock(string $before): bool
    {
        if (1 !== preg_match('/\b(?:DO|FOR|KEY)\s+$/i', $before, $m, \PREG_OFFSET_CAPTURE)) {
            return false;
        }
        $head = substr($before, 0, $m[0][1]);
        $line = substr($head, (int) strrpos("\n".$head, "\n"));

        return !str_contains($line, '--') && substr_count($head, '/*') <= substr_count($head, '*/');
    }

    /**
     * The table name, or DQL entity FQCN, at the very start of `$text`.
     *
     * @return array{length: ?int, table: ?string, problem: ?string} `length` null when no name is there
     */
    private static function nameAt(string $text): array
    {
        if (1 === preg_match('/^(\\\\*[A-Za-z_]\w*(?:\\\\+[A-Za-z_]\w*)+)(?![\w\\\\])/', $text, $t)) {
            $entity = ltrim((string) preg_replace('/\\\\+/', '\\', $t[1]), '\\');
            $table = self::tableOfEntity($entity);

            return null !== $table && isset(SchemaManifest::TABLES[$table])
                ? ['length' => \strlen($t[0]), 'table' => $table, 'problem' => null]
                : ['length' => \strlen($t[0]), 'table' => null, 'problem' => 'DQL target '.$entity.' is not an entity mapped to a SchemaManifest table'];
        }

        if (1 === preg_match('/^(?:"?([A-Za-z_][\w$]*)"?\.)?"?([A-Za-z_][\w$]*)"?(?![\w$\\\\])/', $text, $t)) {
            return isset(SchemaManifest::TABLES[$t[2]])
                ? ['length' => \strlen($t[0]), 'table' => $t[2], 'problem' => null]
                : ['length' => \strlen($t[0]), 'table' => null, 'problem' => '`'.$t[2].'` is not a SchemaManifest table'];
        }

        return ['length' => null, 'table' => null, 'problem' => null];
    }

    /** @param string $class a FQCN that may not exist */
    private static function tableOfEntity(string $class): ?string
    {
        if (!class_exists($class)) {
            return null;
        }
        $attributes = (new \ReflectionClass($class))->getAttributes(\Doctrine\ORM\Mapping\Table::class);

        return [] === $attributes ? null : $attributes[0]->newInstance()->name;
    }
}

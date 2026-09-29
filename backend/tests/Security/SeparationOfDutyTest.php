<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Modules\UserManagement\Domain\AdministrativeCharter;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\UserManagement\Domain\OwnerMarker;
use App\Modules\UserManagement\Domain\PermissionCatalogue;
use App\Modules\UserManagement\Domain\SeparationOfDuty;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ADR-008 open item **D2**, discharged — the committed port of PC-4, PC-13 and
 * the near-owner ceiling. Carries `V2-R-2`.
 *
 * ---------------------------------------------------------------------------
 * The two outcomes D2 refuses, and how each is refused here
 * ---------------------------------------------------------------------------
 * D2: *"porting it now would produce exactly the two outcomes this project has
 * learned to refuse: a test that fails on a fresh checkout (it reads gitignored
 * documents), or one that skips into a green."*
 *
 *  - **It reads no document.** Every domain in this file comes from
 *    `PermissionCatalogue`, `SeparationOfDuty`, `AdministrativeCharter`,
 *    `OwnerMarker` and `DefaultRoles`, which are committed under `src/`. There is
 *    no `docs/` path, no `isAvailable()` probe and no file read. **Measured, not
 *    argued:** `git clone`d into a scratch directory, where `docs/` is absent
 *    because it is gitignored, this file runs `OK (8 tests, 176 assertions)`
 *    with **0 skipped** — the same figures as in a working tree that has the
 *    documents, and `DefaultRoleCatalogueDocumentTest` skips all four beside it.
 *    *(The first attempt at that measurement was worthless and is recorded so
 *    nobody repeats it: the clone's `backend/vendor` was **symlinked** to the
 *    real one, and Composer's `autoload_psr4.php` computes `$baseDir` from the
 *    symlink's resolved path — so `App\` and `App\Tests\` both loaded from the
 *    original working tree and the run read the real `docs/`. It printed a
 *    confident green about a file it never executed. Copy `vendor/`.)* The
 *    reconciliation *against* the documents is
 *    a separate file, `DefaultRoleCatalogueDocumentTest`, which is allowed to
 *    skip because losing it costs a cross-check and not the rule.
 *  - **It cannot skip.** There is no `markTestSkipped` here, and every clause
 *    asserts its own subject is non-empty before asserting anything about it —
 *    *the empty-set trap*, the shape this project has now found more than a
 *    dozen times. A parser or a derivation that silently returns nothing fails
 *    loudly rather than passing vacuously.
 *
 * ---------------------------------------------------------------------------
 * Every figure below is a LITERAL, and that is deliberate
 * ---------------------------------------------------------------------------
 * `60`, `53`, `7`, `4`, `56` are typed here and compared against what the
 * artifact computes *(`54` and `6` until A2.1a, 2026-09-29 — each re-derived by
 * running this file against the widened table, never by arithmetic)*. `docs/adr/sod_check.py`'s own docstring records why:
 * its `ADMIN_EXCL` constant and its `== 54` assert are edited together, so the
 * assertion *"validates a number the same edit chose"*. A pin in the test and a
 * derivation in the code are two independent statements; a constant and an
 * assert in the same file are one.
 *
 * ---------------------------------------------------------------------------
 * ⚠ `docs/adr/probe.py`'s third run is labelled "(must move)" and DOES NOT MOVE
 * ---------------------------------------------------------------------------
 * D2 names *"`probe.py`'s three mutations as its falsifiability cases"*. Run on
 * 2026-09-18 before this port was written, all **three** of its runs print the
 * same three numbers — `PC-4 fires on 2 / PC-13 pairs: 6 / cover 4 -> ceiling
 * 56` — so `probe.py` contains no case that can distinguish a correct table from
 * a mutated one, and its `PROBE (must move)` label is false.
 *
 * **ADR-008 already knows this and the label contradicts it.** A1.2's recorded
 * negative result is that *"for a change on the cancellation side all three
 * checks return baseline whichever predicate is chosen"*, and A1.4's violation
 * list repeats it: *"without A1.2's negative result being read: for a
 * cancellation-side change the run is not the evidence."*
 *
 * So all three runs are reproduced below **with their measured outcome**, which
 * is a recorded property and not evidence of anything, and the non-vacuity
 * evidence is `theThreeChecksAreNotConstantFunctionsOfTheTable`, which uses
 * mutations that do move. Porting `probe.py`'s label unexamined would have
 * shipped three green cases that cannot fail — the fake-green this suite exists
 * to refuse.
 *
 * ---------------------------------------------------------------------------
 * Mutations — run against the committed artifact, not reasoned about
 * ---------------------------------------------------------------------------
 * **(1)** `invoice.void` added to SoD-3's controlled half (Amendment 2's A2.1
 * landing early): **4 of 8 red** — five controlled codes becomes six, `X(admin)`
 * becomes seven, the conflict graph gains an edge. `admin` stays PC-4-clean,
 * because the derivation takes `invoice.void` off it in the same breath. That is
 * PC-14's *"clean by construction, present and future"*, observed rather than
 * claimed. **⚖ 2026-09-29: this mutation became the real state — A2.1a (ADR-008
 * A2.7, sales step 6 C7).** Applied for real it reddened the same four clauses
 * (`xAdmin…`, `e15bsMarker…`, the ceiling's edge count, and the old simulation
 * (i)), the pins moved by derivation, and (i) was rewritten to simulate the
 * ordering hazard against the real table.
 *
 * **(2)** E-15b's marker dropped from `AdministrativeCharter::excludedCodes()`,
 * i.e. `X(admin)` derived wholly from §2: **2 red**, and the second names the
 * consequence rather than the count — *"E-15b's marker must be held by `owner`
 * alone"*, because `admin` is now 55 and holds `tenant_settings.edit`, so
 * E-15b's predicate would admit `admin` and the rule would confer nothing.
 *
 * **(3)** SoD-3 written the wrong way round (`invoice.pay` preparing,
 * `invoice.issue` controlled): **2 red**. PC-4 and the ceiling do not move — they
 * are symmetric in a row's two halves — but PC-13's table does (`admin + finance`
 * becomes `admin + sales_rep`) and so does `X(admin)`. This is why ADR-008 makes
 * the row convention a property of the table with its own violation clause.
 *
 * **(4)** `ROWS` emptied: **8 of 8 red**, every one of them on an empty-set clause
 * rather than on a comparison. The anti-vacuity control is non-vacuous.
 *
 * **(5) The honest limit of this file, measured rather than assumed.** Replacing
 * the derivation in `AdministrativeCharter::excludedCodes()` with the six literals
 * it computed then (seven since A2.1a) is **green** — no test can tell a derivation from a list that
 * agrees with it. What tells them apart is the *next* table change, and that was
 * run too: with the literals in place, mutation (1) turns **`admin` into a third
 * PC-4 exception** (`pc4` becomes `admin, owner, purchasing_manager`), where with
 * the derivation it stayed clean. The god role returns the first time the table
 * moves. That difference — not a green — is the evidence for `V2-R-2`'s *fails
 * if* clause.
 */
final class SeparationOfDutyTest extends TestCase
{
    /** `SECURITY_MODEL.md` §3's own heading. Pinned, never derived from the code under test. */
    private const CATALOGUE_SIZE = 60;

    /**
     * §4's `admin` row: `60 − 7 = 53`. *(54 until 2026-09-29: A2.1a (ADR-008 A2.7, sales step 6 C7) put
     * `invoice.void` on SoD-3's controlled half and PC-14's derivation took it off `admin`. Re-derived by running this
     * file against the widened table, never by arithmetic: `admin` = |catalogue| − |X(admin)|.)*
     */
    private const ADMIN_SIZE = 53;

    /** ADR-008 §2 — four rows, six codes on their controlled halves (five until A2.1a added `invoice.void`). */
    private const SOD_ROWS = 4;
    private const CONTROLLED_CODES = 6;

    /** PC-14's `X(admin)`: the six above **plus** E-15b's marker (six until A2.1a). */
    private const X_ADMIN_SIZE = 7;

    /** §4's eight default roles. */
    private const DEFAULT_ROLES = 8;

    /** PC-4 fires on exactly `owner` and `purchasing_manager`. */
    private const PC4_ROLES = ['owner', 'purchasing_manager'];

    /** PC-13's enumerated pair table. */
    private const PC13_PAIRS = [
        'admin + finance' => ['SoD-3'],
        'admin + warehouse_operator' => ['SoD-2', 'SoD-4'],
        'buyer + warehouse_operator' => ['SoD-2'],
        'finance + sales_rep' => ['SoD-3'],
        'purchasing_manager + warehouse_operator' => ['SoD-2'],
        'sales_rep + warehouse_operator' => ['SoD-4'],
    ];

    /**
     * The conflict graph, and E-15b/E-15c's ceiling over it. A2.1a added one vertex (`invoice.void`) and one edge
     * (`invoice.issue`–`invoice.void`) — 8 / 7 until 2026-09-29; the cover and the ceiling did not move, because the new
     * edge is already covered by `invoice.issue` (ADR-008 A2.5, variant A).
     */
    private const CONFLICT_VERTICES = 9;
    private const CONFLICT_EDGES = 8;
    private const MINIMUM_VERTEX_COVER = 4;
    private const NEAR_OWNER_CEILING = 56;

    // -----------------------------------------------------------------------
    // The empty-set trap. Everything below depends on these being non-empty.
    // -----------------------------------------------------------------------

    /**
     * **The positive control for every other clause in this file.**
     *
     * A derivation that returns nothing makes PC-4 fire on no role, PC-13 produce
     * no pair and the conflict graph have no edge — three clean greens over three
     * empty domains. This clause is what stops that, and it is first on purpose.
     */
    #[Test]
    public function everyDerivedDomainIsNonEmptyAndTheRolesDrawFromTheCatalogue(): void
    {
        $catalogue = PermissionCatalogue::codes();

        self::assertNotSame([], $catalogue, 'The catalogue is empty: every clause below would pass vacuously.');
        self::assertCount(self::CATALOGUE_SIZE, $catalogue, '§3 publishes 60 permission codes.');
        self::assertSame($catalogue, array_values(array_unique($catalogue)), 'A code appears twice in the catalogue.');
        self::assertNotSame([], PermissionCatalogue::modules(), 'No module: PC-1a has no subject.');

        self::assertNotSame([], SeparationOfDuty::rowIds(), 'The SoD table is empty: PC-4 and PC-13 would have nothing to fire on.');
        self::assertCount(self::SOD_ROWS, SeparationOfDuty::rowIds(), 'ADR-008 §2 carries four rows.');

        foreach (SeparationOfDuty::rowIds() as $id) {
            self::assertNotSame([], SeparationOfDuty::preparingHalf($id), $id.' has no preparing half.');
            self::assertNotSame([], SeparationOfDuty::controlledHalf($id), $id.' has no controlled half.');

            foreach ([...SeparationOfDuty::preparingHalf($id), ...SeparationOfDuty::controlledHalf($id)] as $code) {
                self::assertTrue(
                    PermissionCatalogue::has($code),
                    $id.' names `'.$code.'`, which is not a §3 permission code.',
                );
            }
        }

        $roles = DefaultRoles::all();

        self::assertCount(self::DEFAULT_ROLES, $roles, '§4 publishes eight default roles.');

        foreach ($roles as $name => $codes) {
            self::assertNotSame([], $codes, 'Role `'.$name.'` resolved to no permission at all.');
            self::assertSame(
                [],
                array_values(array_diff($codes, $catalogue)),
                'Role `'.$name.'` holds a code that is not in §3.',
            );
            self::assertSame($codes, array_values(array_unique($codes)), 'Role `'.$name.'` holds a code twice.');
        }
    }

    // -----------------------------------------------------------------------
    // PC-14 — X(admin) is a derivation, and its two provenances are separate
    // -----------------------------------------------------------------------

    /**
     * **`V2-R-2`'s *fails if* clause, asserted rather than asserted about.**
     *
     * `X(admin)` = the controlled half of every SoD row **plus** E-15b's marker,
     * and the equality below is over the artifact's own two sources. Nothing in
     * `AdministrativeCharter` or `DefaultRoles` types a code.
     */
    #[Test]
    public function xAdminIsTheControlledHalvesPlusE15bsMarkerAndIsNeverTyped(): void
    {
        $controlled = SeparationOfDuty::controlledCodes();

        self::assertNotSame([], $controlled, 'No controlled half at all: X(admin) would be the marker alone.');
        self::assertCount(self::CONTROLLED_CODES, $controlled, 'ADR-008 §2 puts six codes on its controlled halves (A2.1a).');

        $expected = [...$controlled, OwnerMarker::CODE];
        sort($expected);

        $actual = AdministrativeCharter::excludedCodes();
        sort($actual);

        self::assertSame($expected, $actual, 'X(admin) is not the union of §2s controlled halves and E-15bs marker.');
        self::assertCount(self::X_ADMIN_SIZE, AdministrativeCharter::excludedCodes(), 'X(admin) is seven codes on todays table.');

        // The forward and inverse clauses of PC-14's violation test, over the
        // artifact. Both are structural here — `admin` IS the complement — and
        // both are asserted anyway, because "structurally impossible" is what
        // every one of this project's fake greens was before it was measured.
        $admin = DefaultRoles::codesFor(DefaultRoles::ADMIN);

        self::assertCount(self::ADMIN_SIZE, $admin, '`admin` holds 60 − 7 = 53 codes.');
        self::assertSame(
            [],
            array_values(array_intersect($admin, AdministrativeCharter::excludedCodes())),
            'Forward clause: `admin` holds a member of X(admin).',
        );
        // Sorted on both sides: the complement comes back in §3's order and
        // `X(admin)` in §2's, and this clause is about the SET, not the order.
        $omitted = array_values(array_diff(PermissionCatalogue::codes(), $admin));
        sort($omitted);

        self::assertSame(
            $expected,
            $omitted,
            'Inverse clause: `admin` omits a §3 code that is not in X(admin).',
        );
    }

    /**
     * **The trap `sod_check.py`'s six-literal `ADMIN_EXCL` hides.**
     *
     * Five of `X(admin)`'s six members come from ADR-008 §2. The sixth,
     * `tenant_settings.edit`, appears on **no** SoD row — it is E-15b's
     * owner-equivalence marker, and PC-14 gives it its own clause for exactly
     * this reason. Derive all six from §2 and `admin` is **55**; type all six and
     * nothing derives. This clause pins the difference so that neither mistake
     * can be made silently.
     */
    #[Test]
    public function e15bsMarkerIsNotASeparationOfDutyAndIsNotOnAnyRow(): void
    {
        $governed = SeparationOfDuty::governedCodes();

        self::assertNotSame([], $governed, 'No row names any code: this clause would pass over nothing.');
        self::assertNotContains(
            OwnerMarker::CODE,
            $governed,
            '`'.OwnerMarker::CODE.'` has been written onto an SoD row. It is E-15bs configuration marker, '
            .'not a separation of duty, and folding it in is what makes X(admin) untraceable.',
        );

        self::assertTrue(PermissionCatalogue::has(OwnerMarker::CODE), 'E-15bs marker is not a §3 code.');

        // E-15b's predicate is only a predicate because `owner` holds the marker
        // alone. If `admin` held it, E-15b would confer nothing (PC-14).
        $holders = [];

        foreach (DefaultRoles::all() as $name => $codes) {
            if (\in_array(OwnerMarker::CODE, $codes, true)) {
                $holders[] = $name;
            }
        }

        self::assertSame([DefaultRoles::OWNER], $holders, 'E-15bs marker must be held by `owner` alone.');

        // And the arithmetic the trap turns on, stated so it cannot drift:
        // a fully §2-derived X(admin) would be six codes and `admin` would be 54
        // (five and 55 until A2.1a, 2026-09-29).
        self::assertCount(
            self::CATALOGUE_SIZE - self::CONTROLLED_CODES,
            array_values(array_diff(PermissionCatalogue::codes(), AdministrativeCharter::separationOfDutyExclusions())),
            'Without E-15bs marker `admin` is 54, which is the number a §2-only derivation produces.',
        );
    }

    // -----------------------------------------------------------------------
    // CHECK 1 — PC-4
    // -----------------------------------------------------------------------

    /**
     * **PC-4 run mechanically: which roles hold both halves of any row.**
     *
     * ADR-008 §2 names two and says they are not the same case — `owner` (an
     * accepted, unenforced residual) and `purchasing_manager` (answered by
     * DSoD-1 at operation time). A third name here is a finding, not a
     * refactoring detail.
     */
    #[Test]
    public function pc4FiresOnExactlyOwnerAndPurchasingManager(): void
    {
        $rows = $this->table();
        $fires = [];

        foreach (DefaultRoles::all() as $name => $codes) {
            $spanned = $this->rowsSpannedBy($rows, $codes);

            if ([] !== $spanned) {
                $fires[$name] = $spanned;
            }

            // The shipped predicate and this file's harness must agree, or a
            // mutation of the table would move one and not the other.
            self::assertSame(
                $spanned,
                SeparationOfDuty::rowsSpannedBy($codes),
                'SeparationOfDuty::rowsSpannedBy() disagrees with this files harness on `'.$name.'`.',
            );
        }

        self::assertSame(self::PC4_ROLES, array_keys($fires));
        self::assertSame(SeparationOfDuty::rowIds(), $fires['owner'], '`owner` spans all four rows.');
        self::assertSame(['SoD-1'], $fires['purchasing_manager'], '`purchasing_manager` spans SoD-1 and nothing else.');

        // Positive control. Without it "fires on exactly two" is satisfied by a
        // predicate that has stopped looking: a synthetic role holding both
        // halves of SoD-3 must fire, and one holding neither must not.
        self::assertSame(
            ['SoD-3'],
            $this->rowsSpannedBy($rows, ['invoice.issue', 'invoice.pay']),
            'The PC-4 predicate did not fire on a set holding both halves of SoD-3.',
        );
        self::assertSame(
            [],
            $this->rowsSpannedBy($rows, ['invoice.view', 'customer.view']),
            'The PC-4 predicate fired on a set holding no controlled code.',
        );
    }

    // -----------------------------------------------------------------------
    // CHECK 2 — PC-13
    // -----------------------------------------------------------------------

    /**
     * **PC-13: pairs whose union spans a split neither member spans alone.**
     *
     * The qualifier is the rule. Without it every pair containing `owner` or
     * `purchasing_manager` is refused, which forbids the ordinary and prevents
     * nothing — PC-13's own text says so. The three properties the model claims
     * about its own table are asserted below rather than restated: SoD-1
     * produces no pair, no pair contains `owner`, and `viewer` appears in none.
     */
    #[Test]
    public function pc13ProducesTheSixPairsTheModelEnumerates(): void
    {
        $pairs = $this->conflictingPairs($this->table(), DefaultRoles::all());

        self::assertNotSame([], $pairs, 'No pair at all: this clause would assert nothing.');
        self::assertSame(self::PC13_PAIRS, $pairs);

        foreach ($pairs as $pair => $splits) {
            self::assertNotContains('SoD-1', $splits, 'SoD-1 must produce no pair: both `.approve` holders span it alone.');
            self::assertStringNotContainsString('owner', $pair, '`owner` spans every split alone and can create no pair.');
            self::assertStringNotContainsString('viewer', $pair, '`viewer` holds no controlled code and pairs safely with all seven others.');
        }

        // Positive control: a planted pair the defaults do not contain must be
        // found by the same function, and a pair that spans nothing must not be.
        $planted = $this->conflictingPairs($this->table(), [
            'issuer' => ['invoice.issue'],
            'payer' => ['invoice.pay'],
            'bystander' => ['customer.view'],
        ]);

        self::assertSame(['issuer + payer' => ['SoD-3']], $planted, 'The PC-13 predicate did not find a planted pair.');
    }

    // -----------------------------------------------------------------------
    // CHECK 3 — the near-owner ceiling
    // -----------------------------------------------------------------------

    /**
     * **E-15b/E-15c's ceiling: the catalogue minus a *minimum vertex cover* of
     * the conflict graph.**
     *
     * ADR-008 §2 states the method and states why the obvious arithmetic is
     * wrong: splits share codes, so `60 −` one per split is right today only by
     * coincidence and a fifth split can cost 0 or 2. The cover is therefore
     * searched for, ascending by size, rather than assumed.
     */
    #[Test]
    public function theNearOwnerCeilingIsTheCatalogueMinusAMinimumVertexCover(): void
    {
        $edges = SeparationOfDuty::conflictEdges();

        self::assertNotSame([], $edges, 'The conflict graph has no edge: any set would be a cover and the ceiling would be 60.');
        self::assertCount(self::CONFLICT_EDGES, $edges);

        $vertices = $this->vertices($edges);

        self::assertCount(self::CONFLICT_VERTICES, $vertices);

        $cover = $this->minimumVertexCover($edges);

        self::assertCount(self::MINIMUM_VERTEX_COVER, $cover);
        self::assertTrue($this->covers($cover, $edges), 'The search returned a set that is not a cover.');

        // It is minimum because nothing smaller covers. Asserted, not inferred
        // from the search's loop order.
        foreach ($this->subsets($vertices, \count($cover) - 1) as $smaller) {
            self::assertFalse($this->covers($smaller, $edges), 'A smaller cover exists: the minimum is not '.\count($cover).'.');
        }

        self::assertSame(
            self::NEAR_OWNER_CEILING,
            \count(PermissionCatalogue::codes()) - \count($cover),
            'The largest PC-4-clean single role is the catalogue minus a minimum vertex cover.',
        );

        // And the ceiling is real rather than arithmetic: the set it names must
        // itself be PC-4-clean, and one code larger must not be.
        $clean = array_values(array_diff(PermissionCatalogue::codes(), $cover));

        self::assertCount(self::NEAR_OWNER_CEILING, $clean);
        self::assertSame([], $this->rowsSpannedBy($this->table(), $clean), 'The ceiling set spans a split, so it is not PC-4-clean.');

        foreach ($cover as $restored) {
            self::assertNotSame(
                [],
                $this->rowsSpannedBy($this->table(), [...$clean, $restored]),
                'Adding `'.$restored.'` back left the set PC-4-clean, so the cover is not tight.',
            );
        }
    }

    // -----------------------------------------------------------------------
    // Falsifiability
    // -----------------------------------------------------------------------

    /**
     * **`docs/adr/probe.py`'s three runs, executed — and two of the three do not
     * move.**
     *
     * Reproduced because D2 names them, asserted at their **measured** outcome
     * rather than at their labels. `probe.py`'s third run is captioned
     * `PROBE (must move)`; it does not, and ADR-008 A1.2's recorded negative
     * result says it cannot. Treat the three greens below as a record of that
     * fact, never as evidence that the checks work —
     * `theThreeChecksAreNotConstantFunctionsOfTheTable` is the evidence.
     */
    #[Test]
    public function probePysThreeRunsReproduce_andTwoOfThemDoNotMove(): void
    {
        $baseline = $this->summarise($this->table());

        self::assertSame(
            ['pc4' => self::PC4_ROLES, 'pairs' => 6, 'cover' => 4, 'ceiling' => 56],
            $baseline,
            'BASELINE — the SoD table as ADR-008 §2 stands today.',
        );

        // COUNTERFACTUAL A — `canceller != approver` written as a fifth STATIC
        // split. ADR-008 A1.3 refuses it on the argument and records that the
        // numbers do not distinguish it: *"a future reader who re-runs the
        // numbers and finds them equal has confirmed nothing about the choice."*
        $counterfactualA = $this->table();
        $counterfactualA['SoD-5(hypothetical) approve/cancel'] = [
            'preparing' => ['purchase_order.approve'],
            'controlled' => ['purchase_order.cancel'],
        ];

        self::assertSame($baseline, $this->summarise($counterfactualA), 'COUNTERFACTUAL A moved. A1.3 records that it does not.');

        // PROBE, captioned "(must move)" — R-3's rejected shape, `.cancel` back
        // on the create side. It does not move, and A1.2 is why: for a
        // cancellation-side change all three checks return baseline whichever
        // predicate is chosen.
        $r3 = $this->table();
        $r3['SoD-1'] = [
            'preparing' => ['purchase_order.create', 'purchase_order.cancel'],
            'controlled' => ['purchase_order.approve'],
        ];

        self::assertSame(
            $baseline,
            $this->summarise($r3),
            'probe.pys third run moved. If this ever fires, probe.pys "(must move)" label has become true and '
            .'ADR-008 A1.2s negative result needs re-reading.',
        );
    }

    /**
     * **The non-vacuity evidence: three mutations that DO move.**
     *
     * These are not in `probe.py` and had to be found, because all three of its
     * runs return baseline. Each moves a different check, so no single clause
     * above is carrying the file.
     *
     * A fourth mutation — deleting every row — is included because it is the
     * empty-set trap's own case, and it must move rather than produce a serene
     * green over nothing.
     */
    #[Test]
    public function theThreeChecksAreNotConstantFunctionsOfTheTable(): void
    {
        $baseline = $this->summarise($this->table());

        // (i) A2.1a's ordering hazard, against the REAL table. *(Rewritten 2026-09-29, sales step 6 C7, when A2.1a
        // landed. Until then this simulated A2.1 landing on §2 EARLY — `invoice.void` appended to a copy of SoD-3 while
        // §4's `admin` still held it — and asserted `admin` became a third PC-4 exception. A2.1a is now the real state,
        // so the same hazard is simulated from the other side.)* §4's `admin` as seeded BEFORE A2.1a — the derived set
        // plus `invoice.void` — spans SoD-3 against today's table, so it would be a PC-4 exception; the derived `admin`
        // spans nothing. That difference is what A2.6's "in the same commit" buys, observed rather than claimed.
        $real = $this->table();
        self::assertContains('invoice.void', $real['SoD-3']['controlled'] ?? [], 'A2.1a is not in the table: SoD-3s controlled half lacks `invoice.void`.');

        $admin = DefaultRoles::codesFor(DefaultRoles::ADMIN);
        self::assertSame([], $this->rowsSpannedBy($real, $admin), 'The derived `admin` spans a split.');
        self::assertSame(['SoD-3'], $this->rowsSpannedBy($real, [...$admin, 'invoice.void']),
            '`admin` still holding `invoice.void` after A2.1a must span SoD-3 — the PC-4 exception A2.1a removes.');

        // And A2.1a undone on a copy moves none of the three checks: ADR-008 A2.5's recorded result ("moves PC-4, PC-13
        // and the ceiling not at all"), because `admin` is derived from the real table either way. A record, not
        // evidence — the evidence is the clause above.
        $undone = $real;
        $undone['SoD-3']['controlled'] = array_values(array_diff($real['SoD-3']['controlled'], ['invoice.void']));
        self::assertSame($baseline, $this->summarise($undone), 'A2.1a undone moved a check. A2.5 records that it does not.');

        // (ii) SoD-3 written the wrong way round. PC-4, PC-13 and the ceiling are
        // all blind to it — the conflict graph is undirected and the pair sets
        // are symmetric — but PC-14 is not, because it reads one side. This is
        // the mutation that shows why ADR-008 makes the row convention a
        // property of the table with its own violation clause.
        $inverted = $this->table();
        $inverted['SoD-3'] = ['preparing' => ['invoice.pay'], 'controlled' => ['invoice.issue']];

        self::assertSame($baseline, $this->summarise($inverted), 'The three checks are symmetric in a rows two halves; only PC-14 is not.');
        self::assertNotSame(
            $this->controlledCodesOf($this->table()),
            $this->controlledCodesOf($inverted),
            'Inverting SoD-3 left X(admin) unchanged, so the controlled half is not the side being read.',
        );

        // (iii) SoD-4 removed — the third pass undone. Moves the ceiling, because
        // `fulfilment.create` leaves the minimum cover.
        $withoutSoD4 = $this->table();
        unset($withoutSoD4['SoD-4']);

        $shrunk = $this->summarise($withoutSoD4);

        self::assertNotSame($baseline, $shrunk, 'Deleting SoD-4 moved nothing.');
        self::assertSame(3, $shrunk['cover'], 'Without SoD-4 the minimum vertex cover is three.');
        self::assertSame(57, $shrunk['ceiling'], 'Without SoD-4 the ceiling is 57, which is the figure E-15b carried while there were three splits.');

        // (iv) the empty table. Every check must collapse visibly.
        $empty = $this->summarise([]);

        self::assertSame(['pc4' => [], 'pairs' => 0, 'cover' => 0, 'ceiling' => self::CATALOGUE_SIZE], $empty);
        self::assertNotSame($baseline, $empty, 'An empty SoD table produced the same answer as the real one.');
    }

    // -----------------------------------------------------------------------
    // The harness. Generic in the table, so that a mutated table runs the same
    // code the real one does.
    // -----------------------------------------------------------------------

    /**
     * The artifact's table, as data this file can mutate a copy of.
     *
     * @return array<string, array{preparing: list<string>, controlled: list<string>}>
     */
    private function table(): array
    {
        $rows = [];

        foreach (SeparationOfDuty::rowIds() as $id) {
            $rows[$id] = SeparationOfDuty::row($id);
        }

        return $rows;
    }

    /**
     * @param array<string, array{preparing: list<string>, controlled: list<string>}> $rows
     * @param list<string>                                                            $codes
     *
     * @return list<string>
     */
    private function rowsSpannedBy(array $rows, array $codes): array
    {
        $spanned = [];

        foreach ($rows as $id => $row) {
            // `?? []` so that a mutation which removes a half reports as a moved
            // answer rather than as a TypeError. Measured: emptying `ROWS`
            // produced `array_intersect(): Argument #1 must be of type array,
            // null given` — a red, but a red about this harness instead of about
            // the table.
            $preparing = $row['preparing'] ?? [];
            $controlled = $row['controlled'] ?? [];

            if ([] !== array_intersect($preparing, $codes) && [] !== array_intersect($controlled, $codes)) {
                $spanned[] = $id;
            }
        }

        return $spanned;
    }

    /**
     * @param array<string, array{preparing: list<string>, controlled: list<string>}> $rows
     * @param array<string, list<string>>                                             $roles
     *
     * @return array<string, list<string>> "a + b" => the splits their union spans
     */
    private function conflictingPairs(array $rows, array $roles): array
    {
        $names = array_keys($roles);
        sort($names);

        $pairs = [];

        foreach ($names as $i => $first) {
            foreach (\array_slice($names, $i + 1) as $second) {
                $union = array_values(array_unique([...$roles[$first], ...$roles[$second]]));

                $splits = array_values(array_diff(
                    $this->rowsSpannedBy($rows, $union),
                    $this->rowsSpannedBy($rows, $roles[$first]),
                    $this->rowsSpannedBy($rows, $roles[$second]),
                ));

                if ([] !== $splits) {
                    $pairs[$first.' + '.$second] = $splits;
                }
            }
        }

        return $pairs;
    }

    /**
     * @param array<string, array{preparing: list<string>, controlled: list<string>}> $rows
     *
     * @return list<array{0: string, 1: string}>
     */
    private function edgesOf(array $rows): array
    {
        $edges = [];

        foreach ($rows as $row) {
            foreach ($row['preparing'] ?? [] as $preparing) {
                foreach ($row['controlled'] ?? [] as $controlled) {
                    $edge = [$preparing, $controlled];
                    sort($edge);

                    if (!\in_array($edge, $edges, true)) {
                        $edges[] = $edge;
                    }
                }
            }
        }

        return $edges;
    }

    /**
     * @param array<string, array{preparing: list<string>, controlled: list<string>}> $rows
     *
     * @return list<string>
     */
    private function controlledCodesOf(array $rows): array
    {
        $codes = [];

        foreach ($rows as $row) {
            foreach ($row['controlled'] ?? [] as $code) {
                if (!\in_array($code, $codes, true)) {
                    $codes[] = $code;
                }
            }
        }

        sort($codes);

        return $codes;
    }

    /**
     * @param list<array{0: string, 1: string}> $edges
     *
     * @return list<string>
     */
    private function vertices(array $edges): array
    {
        $vertices = [];

        foreach ($edges as [$a, $b]) {
            foreach ([$a, $b] as $vertex) {
                if (!\in_array($vertex, $vertices, true)) {
                    $vertices[] = $vertex;
                }
            }
        }

        sort($vertices);

        return $vertices;
    }

    /**
     * Brute force, ascending by size — the same search `sod_check.py` runs, and
     * deliberately not a greedy approximation: an approximation that returned 5
     * would print a ceiling of 55 and nobody would know it was wrong.
     *
     * @param list<array{0: string, 1: string}> $edges
     *
     * @return list<string>
     */
    private function minimumVertexCover(array $edges): array
    {
        $vertices = $this->vertices($edges);

        for ($size = 0; $size <= \count($vertices); ++$size) {
            foreach ($this->subsets($vertices, $size) as $candidate) {
                if ($this->covers($candidate, $edges)) {
                    return $candidate;
                }
            }
        }

        return $vertices;
    }

    /**
     * @param list<string>                      $cover
     * @param list<array{0: string, 1: string}> $edges
     */
    private function covers(array $cover, array $edges): bool
    {
        foreach ($edges as [$a, $b]) {
            if (!\in_array($a, $cover, true) && !\in_array($b, $cover, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $items
     *
     * @return list<list<string>>
     */
    private function subsets(array $items, int $size): array
    {
        if ($size <= 0) {
            return [[]];
        }
        if ($size > \count($items)) {
            return [];
        }

        $subsets = [];

        foreach ($items as $index => $item) {
            foreach ($this->subsets(\array_slice($items, $index + 1), $size - 1) as $rest) {
                $subsets[] = [$item, ...$rest];
            }
        }

        return $subsets;
    }

    /**
     * The three checks over one table, in `probe.py`'s shape, so that a baseline
     * and a mutation are compared as whole answers rather than one figure at a
     * time.
     *
     * @param array<string, array{preparing: list<string>, controlled: list<string>}> $rows
     *
     * @return array{pc4: list<string>, pairs: int, cover: int, ceiling: int}
     */
    private function summarise(array $rows): array
    {
        $fires = [];

        foreach (DefaultRoles::all() as $name => $codes) {
            if ([] !== $this->rowsSpannedBy($rows, $codes)) {
                $fires[] = $name;
            }
        }

        sort($fires);

        $cover = [] === $this->edgesOf($rows) ? [] : $this->minimumVertexCover($this->edgesOf($rows));

        return [
            'pc4' => $fires,
            'pairs' => \count($this->conflictingPairs($rows, DefaultRoles::all())),
            'cover' => \count($cover),
            'ceiling' => \count(PermissionCatalogue::codes()) - \count($cover),
        ];
    }
}

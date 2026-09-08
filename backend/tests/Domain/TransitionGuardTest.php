<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\IllegalTransition;
use App\Entity\Invoice;
use App\Entity\PurchaseOrder;
use App\Entity\SalesOrder;
use App\Entity\Tenant;
use App\Tests\Support\ContractDocument;
use App\Tests\Support\StatusMachines;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * API-15 — `docs/API.md` §8.3's legal-transition matrix, enforced.
 *
 * ---------------------------------------------------------------------------
 * What is data here and what is derived, because the difference is the check
 * ---------------------------------------------------------------------------
 * **`MATRIX` is the only thing written down**, and it is written down because
 * §8.3 rules that the matrix is declared in the contract and `BE-LEAD` owns
 * creating it: a legality table cannot be derived from the entities, since the
 * entity is the thing being checked, and it cannot be derived from `FLOWS.md`,
 * whose transition table is an **existence** table with three `⛔ owed by
 * Output 9` rows that a domain read would inherit as *"no legal transition
 * exists"*.
 *
 * Everything else is derived by reflection and nothing else is restated:
 *
 * - the **entities in the domain** — every class under `src/Entity/` that
 *   declares a `STATUS_*` constant and has a method assigning to `$this->status`;
 * - the **status vocabulary** of each — its `STATUS_*` constants;
 * - the **initial status** — the default value of the mapped `status` property;
 * - the **transition methods** — found by reading each method's own source for
 *   an assignment to `$this->status`, so a `cancel()` written by Output 9 joins
 *   the domain on the day it is written, without anybody adding it to a list.
 *
 * ---------------------------------------------------------------------------
 * Clause (b), and the thirteen fake-greens this project has already paid for
 * ---------------------------------------------------------------------------
 * §8.3 clause (b) — *every `STATUS_*` constant is reachable by a declared
 * transition* — **is a red assertion on today's tree and is meant to be**: the
 * constants `PINNED_UNREACHABLE` names are reachable by nothing, because `cancel`,
 * the receipt posting and the fulfilment posting have no method at all
 * (API-O-17, Output 9's). §8.3 says in terms that it *"stays red until
 * Output 9 writes `cancel()`"*.
 *
 * A suite is not allowed to ship red, and a clause (b) that passed today would be
 * the fourteenth fake-green — green over the exact defect it exists to find.
 * **The resolution taken here is a two-sided pin**, `PINNED_UNREACHABLE`:
 *
 * - it **fails if the gap widens** — a new `STATUS_*` constant that no
 *   implemented transition reaches appears in the uncovered set and not in the
 *   pin;
 * - it **fails if the gap closes and the pin is not updated** — the day
 *   `cancel()` exists, `theMatrixNamesEveryTransitionMethodEachEntityDeclares`
 *   demands the matrix row name it, naming it makes `STATUS_CANCELLED` covered,
 *   and the uncovered set no longer equals the pin;
 * - it **cannot be satisfied by inventing a pin**:
 *   `everyPinnedUnreachableStatusIsOwedByAnUnimplementedMatrixRow` requires each
 *   pinned status to be the `Result` of a matrix row that declares no method, so
 *   a status nothing has ever promised to reach cannot be pinned quiet.
 *
 * That is a **characterisation** of the gap, not a suppression of it. A
 * one-directional ignore-list would be a suppression; this one is red in both
 * directions and retires itself. The deviation from §8.3's literal *"clause (b)
 * goes red today"* is recorded in that document's §14.2.
 *
 * ---------------------------------------------------------------------------
 * Mutations — every one below was run, not reasoned about
 * ---------------------------------------------------------------------------
 * See the report; each test's docblock names its own.
 */
final class TransitionGuardTest extends TestCase
{
    /**
     * `docs/API.md` §8.3's matrix, as data.
     *
     * `method` is `null` on the four rows §8.3 marks *(no method exists)*, which
     * is what those rows say: the transition is declared legal and there is
     * nothing to perform it. `blocks` names the §4.2 operations that cannot be
     * implemented until there is — clause (c)'s subject.
     *
     * @var list<array{entity: class-string, method: ?string, from: non-empty-list<string>, to: non-empty-list<string>, blocks: list<string>}>
     */
    private const MATRIX = [
        [
            'entity' => PurchaseOrder::class, 'method' => 'place',
            'from' => [PurchaseOrder::STATUS_DRAFT],
            'to' => [PurchaseOrder::STATUS_ORDERED],
            'blocks' => [],
        ],
        [
            'entity' => PurchaseOrder::class, 'method' => null, // receipt posting, ⛔ Output 9
            'from' => [PurchaseOrder::STATUS_ORDERED, PurchaseOrder::STATUS_PARTIALLY_RECEIVED],
            'to' => [PurchaseOrder::STATUS_PARTIALLY_RECEIVED, PurchaseOrder::STATUS_RECEIVED],
            'blocks' => ['CreateGoodsReceipt'],
        ],
        [
            'entity' => PurchaseOrder::class, 'method' => null, // cancel, ⛔ Output 9
            'from' => [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_ORDERED, PurchaseOrder::STATUS_PARTIALLY_RECEIVED],
            'to' => [PurchaseOrder::STATUS_CANCELLED],
            'blocks' => ['CancelPurchaseOrder'],
        ],
        [
            'entity' => SalesOrder::class, 'method' => 'confirm',
            'from' => [SalesOrder::STATUS_DRAFT],
            'to' => [SalesOrder::STATUS_CONFIRMED],
            'blocks' => [],
        ],
        [
            'entity' => SalesOrder::class, 'method' => null, // fulfilment posting, ⛔ Output 9
            'from' => [SalesOrder::STATUS_CONFIRMED, SalesOrder::STATUS_PARTIALLY_FULFILLED],
            'to' => [SalesOrder::STATUS_PARTIALLY_FULFILLED, SalesOrder::STATUS_FULFILLED],
            'blocks' => ['CreateFulfilment'],
        ],
        [
            'entity' => SalesOrder::class, 'method' => null, // cancel, ⛔ Output 9
            'from' => [SalesOrder::STATUS_DRAFT, SalesOrder::STATUS_CONFIRMED, SalesOrder::STATUS_PARTIALLY_FULFILLED],
            'to' => [SalesOrder::STATUS_CANCELLED],
            'blocks' => ['CancelSalesOrder'],
        ],
        [
            'entity' => Invoice::class, 'method' => 'markPaid',
            'from' => [Invoice::STATUS_ISSUED, Invoice::STATUS_OVERDUE],
            'to' => [Invoice::STATUS_PAID],
            'blocks' => [],
        ],
        [
            'entity' => Invoice::class, 'method' => 'markOverdue',
            'from' => [Invoice::STATUS_ISSUED],
            'to' => [Invoice::STATUS_OVERDUE],
            'blocks' => [],
        ],
        [
            'entity' => Invoice::class, 'method' => 'void',
            'from' => [Invoice::STATUS_ISSUED, Invoice::STATUS_OVERDUE],
            'to' => [Invoice::STATUS_VOID],
            'blocks' => [],
        ],
    ];

    /**
     * §8.3's one entity exclusion, with the clause that carries it. It is not a
     * hole: `TenantStatusTest` is the rule API-O-16 asks for, and
     * `theMatrixDomainIsEveryStatusMachineUnderSrcEntity` asserts this map plus
     * the matrix's entities is exactly what the directory holds — so a fifth
     * status machine cannot arrive unnoticed by being in neither.
     *
     * @var array<class-string, string>
     */
    private const EXCLUDED = [
        Tenant::class => 'API-O-16 — §8.3 excludes `tenant` from API-15 because no §4.2 operation reaches it. Ruled and guarded on the entity; asserted by TenantStatusTest.',
    ];

    /**
     * Clause (b)'s two-sided pin: the statuses no **implemented** transition can
     * produce today. Every one of them is owed by an Output 9 method that does
     * not exist — API-O-17.
     *
     * @var array<class-string, list<string>>
     */
    private const PINNED_UNREACHABLE = [
        PurchaseOrder::class => [
            PurchaseOrder::STATUS_CANCELLED,           // ⛔ cancel()
            PurchaseOrder::STATUS_PARTIALLY_RECEIVED,  // ⛔ receipt posting
            PurchaseOrder::STATUS_RECEIVED,            // ⛔ receipt posting
        ],
        SalesOrder::class => [
            SalesOrder::STATUS_CANCELLED,              // ⛔ cancel()
            SalesOrder::STATUS_FULFILLED,              // ⛔ fulfilment posting
            SalesOrder::STATUS_PARTIALLY_FULFILLED,    // ⛔ fulfilment posting
        ],
        Invoice::class => [],
    ];

    // -----------------------------------------------------------------------
    // The domain
    // -----------------------------------------------------------------------

    /**
     * The matrix's entities plus §8.3's written exclusion are **exactly** the
     * status machines the entity directory holds.
     *
     * **Mutation:** give any other entity a `STATUS_*` constant and a method that
     * assigns `$this->status`; red, naming the class.
     */
    #[Test]
    public function theMatrixDomainIsEveryStatusMachineUnderSrcEntity(): void
    {
        $found = self::statusMachines();

        self::assertNotSame([], $found, 'The scan found no status machine at all, so every clause below would be vacuous.');

        $declared = array_values(array_unique([...self::matrixEntities(), ...array_keys(self::EXCLUDED)]));
        sort($declared);

        self::assertSame($declared, $found, 'A status machine under src/Entity/ is neither in §8.3\'s matrix nor in its written exclusions.');
    }

    /**
     * Every method that assigns to `$this->status` on a matrix entity is named by
     * a matrix row, and every method a row names exists.
     *
     * **This is what makes the clause-(b) pin self-retiring.** The day Output 9
     * writes `PurchaseOrder::cancel()`, this test is red until the matrix's cancel
     * row names it — and naming it moves `STATUS_CANCELLED` into the covered set,
     * which turns the pin red in its turn.
     *
     * **Mutation:** add a second status-assigning method to `Invoice` and leave
     * the matrix alone; red. **Reverse mutation:** point a row's `method` at a
     * name the entity does not declare; also red.
     */
    #[Test]
    public function theMatrixNamesEveryTransitionMethodEachEntityDeclares(): void
    {
        $missingFromMatrix = [];
        $missingFromEntity = [];

        foreach (self::matrixEntities() as $entity) {
            $named = [];
            foreach (self::MATRIX as $row) {
                if ($row['entity'] === $entity && null !== $row['method']) {
                    $named[] = $row['method'];
                    if (!method_exists($entity, $row['method'])) {
                        $missingFromEntity[] = $entity.'::'.$row['method'];
                    }
                }
            }

            foreach (self::transitionMethods($entity) as $method) {
                if (!\in_array($method, $named, true)) {
                    $missingFromMatrix[] = $entity.'::'.$method;
                }
            }
        }

        self::assertSame([], $missingFromMatrix, 'A method assigns to $this->status and §8.3\'s matrix does not declare what it is legal from.');
        self::assertSame([], $missingFromEntity, 'A matrix row names a method the entity does not declare.');
    }

    // -----------------------------------------------------------------------
    // Clause (a)
    // -----------------------------------------------------------------------

    /**
     * The cross product of each entity's `STATUS_*` constants and its transition
     * methods, **minus** the matrix's rows: every remaining pair must refuse.
     *
     * **Mutation:** delete `PurchaseOrder::place()`'s guard; the `ordered → ordered`
     * cell no longer throws; red.
     */
    #[Test]
    public function everyTransitionMethodRefusesEveryIllegalSourceStatus(): void
    {
        $pairs = 0;
        $accepted = [];
        $mutated = [];

        foreach (self::matrixEntities() as $entity) {
            foreach (self::transitionMethods($entity) as $method) {
                foreach (self::statusesOf($entity) as $status) {
                    if (\in_array($status, self::legalSourcesFor($entity, $method), true)) {
                        continue;
                    }

                    ++$pairs;

                    $subject = self::instance($entity, $status);
                    $before = self::snapshot($subject);

                    try {
                        self::invoke($subject, $entity, $method);
                        $accepted[] = $entity.'::'.$method.'() from "'.$status.'"';
                    } catch (IllegalTransition) {
                        // The refusal API-15 requires. A refusal that has already
                        // written half the transition is not a refusal: `place()`
                        // setting `orderedAt` before the guard leaves a managed
                        // `cancelled` order with an ordered date, which is the row
                        // `purchase_order_ordered_at_set` is then asked to accept
                        // at the next flush.
                        if ($before != self::snapshot($subject)) {
                            $mutated[] = $entity.'::'.$method.'() from "'.$status.'"';
                        }
                    }
                }
            }
        }

        self::assertGreaterThan(0, $pairs, 'The cross product was empty, so this clause had no subject.');
        self::assertSame([], $accepted, 'A transition method accepted a source status §8.3\'s matrix does not list.');
        self::assertSame([], $mutated, 'A refused transition left the entity modified — the guard runs after part of the write.');
    }

    /**
     * The other half of clause (a): a guard that refused everything would satisfy
     * the test above and break the application.
     *
     * **Mutation:** change `Invoice::markPaid()`'s legal set to `[STATUS_ISSUED]`;
     * the `overdue → paid` cell throws; red.
     */
    #[Test]
    public function everyLegalSourceStatusInTheMatrixIsAccepted(): void
    {
        $cells = 0;
        $refused = [];
        $wrongResult = [];

        foreach (self::MATRIX as $row) {
            if (null === $row['method']) {
                continue;
            }

            foreach ($row['from'] as $status) {
                ++$cells;

                $subject = self::instance($row['entity'], $status);

                try {
                    self::invoke($subject, $row['entity'], $row['method']);
                } catch (IllegalTransition $e) {
                    $refused[] = $row['entity'].'::'.$row['method'].'() from "'.$status.'": '.$e->getMessage();

                    continue;
                }

                $produced = (new \ReflectionClass($row['entity']))->getProperty('status')->getValue($subject);

                if (!\in_array($produced, $row['to'], true)) {
                    $wrongResult[] = $row['entity'].'::'.$row['method'].'() from "'.$status.'" produced "'.(string) $produced.'"';
                }
            }
        }

        self::assertGreaterThan(0, $cells, 'The matrix declared no implemented cell, so this clause had no subject.');
        self::assertSame([], $refused, 'A transition the matrix declares legal was refused.');
        self::assertSame([], $wrongResult, 'A transition produced a status the matrix does not give as its Result.');
    }

    /**
     * Clause (a)'s second limb: *"a guard enumerates statuses in its own body
     * rather than deriving them from the entity's `STATUS_*` constants"*. The
     * check is on the method's own source — no status **value** may appear in it
     * as a string literal.
     *
     * **Mutation:** rewrite `place()`'s guard as
     * `if ('draft' !== $this->status) { … }`; red, naming the method and the
     * literal.
     */
    #[Test]
    public function noGuardEnumeratesAStatusAsAStringLiteral(): void
    {
        $inspected = 0;
        $literals = [];

        foreach (self::matrixEntities() as $entity) {
            foreach (self::transitionMethods($entity) as $method) {
                ++$inspected;
                $source = self::sourceOf($entity, $method);

                foreach (self::statusesOf($entity) as $status) {
                    if (str_contains($source, "'".$status."'") || str_contains($source, '"'.$status.'"')) {
                        $literals[] = $entity.'::'.$method.'() contains the literal "'.$status.'"';
                    }
                }
            }
        }

        self::assertGreaterThan(0, $inspected, 'No method was inspected, so this clause had no subject.');
        self::assertSame([], $literals, 'A guard restates a status instead of deriving it from the entity\'s STATUS_* constants.');
    }

    // -----------------------------------------------------------------------
    // Clause (b)
    // -----------------------------------------------------------------------

    /**
     * Clause (b), as a two-sided pin. See the class docblock for why it is a pin
     * and not the literal red assertion §8.3 describes.
     *
     * **Mutation:** add `Invoice::STATUS_ARCHIVED` with no transition to it; red
     * immediately — the uncovered set gains a member the pin does not carry.
     * **Reverse mutation:** point the `PurchaseOrder` cancel row's `method` at a
     * `cancel()` that exists; `STATUS_CANCELLED` becomes covered, the uncovered
     * set shrinks below the pin; also red.
     * **Fourth mutation, which proves the restriction rather than the clause:**
     * drop the `method_exists` restriction from `coveredStatusesOf()` so that
     * every row's `Result` counts as covered — the uncovered set empties, and the
     * pin catches it; this is revision 2's behaviour, and it is red here.
     */
    #[Test]
    public function everyStatusConstantIsReachableByAnImplementedTransition(): void
    {
        $entities = self::matrixEntities();

        $pinnedEntities = array_keys(self::PINNED_UNREACHABLE);
        sort($pinnedEntities);

        self::assertSame($entities, $pinnedEntities, 'The clause (b) pin does not cover exactly the matrix\'s entities.');

        foreach ($entities as $entity) {
            $covered = self::coveredStatusesOf($entity);
            $uncovered = array_values(array_diff(self::statusesOf($entity), $covered));
            sort($uncovered);

            $pinned = self::PINNED_UNREACHABLE[$entity];
            sort($pinned);

            self::assertSame($pinned, $uncovered, \sprintf(
                '%s: the statuses no implemented transition reaches are not the ones pinned. '
                .'If a transition was just written, name its method in §8.3\'s matrix row and update the pin; '
                .'if a status was just added, it needs a transition or a matrix row that owes one (API-O-17).',
                $entity,
            ));
        }
    }

    /**
     * The pin cannot be invented. Every status it holds must be the `Result` of a
     * matrix row that declares no implemented method — i.e. a transition the
     * contract has promised and Output 9 owes.
     *
     * **Mutation:** add a status to the pin that no unimplemented row produces
     * (`Invoice::STATUS_PAID`, say); red.
     */
    #[Test]
    public function everyPinnedUnreachableStatusIsOwedByAnUnimplementedMatrixRow(): void
    {
        $pinned = 0;
        $unowed = [];

        foreach (self::PINNED_UNREACHABLE as $entity => $statuses) {
            $owed = [];
            foreach (self::MATRIX as $row) {
                if ($row['entity'] === $entity && (null === $row['method'] || !method_exists($entity, $row['method']))) {
                    $owed = [...$owed, ...$row['to']];
                }
            }

            foreach ($statuses as $status) {
                ++$pinned;
                if (!\in_array($status, $owed, true)) {
                    $unowed[] = $entity.'::"'.$status.'"';
                }
            }
        }

        self::assertGreaterThan(0, $pinned, 'The pin was empty, so this clause had no subject — and clause (b) would then be the literal assertion, which §8.3 says is red.');
        self::assertSame([], $unowed, 'A status is pinned as unreachable and no matrix row owes a transition that would reach it.');
    }

    // -----------------------------------------------------------------------
    // Clause (c)
    // -----------------------------------------------------------------------

    /**
     * Clause (c): a matrix row with no implemented method must name the §4.2
     * operations that are blocked on it.
     *
     * **§8.3 spells clause (c) as *"a row … names a method that does not exist"*,
     * and read only that way it is vacuous on this tree** — today's four rows name
     * no method at all, so no row could trigger it, and a clause that cannot fail
     * is not evidence. It is applied here to **both** spellings of an
     * unimplemented row, which is the hazard the clause is about.
     *
     * **Mutation:** empty the `blocks` list on the `CancelPurchaseOrder` row; red.
     */
    #[Test]
    public function everyUnimplementedRowNamesTheOperationsItBlocks(): void
    {
        $rows = 0;
        $unrouted = [];

        foreach (self::MATRIX as $index => $row) {
            if (null !== $row['method'] && method_exists($row['entity'], $row['method'])) {
                continue;
            }

            ++$rows;
            if ([] === $row['blocks']) {
                $unrouted[] = $row['entity'].' row '.$index.' → '.implode(', ', $row['to']);
            }
        }

        self::assertGreaterThan(0, $rows, 'No row is unimplemented, so this clause had no subject — retire it with the pin.');
        self::assertSame([], $unrouted, 'A matrix row declares a transition no method performs and names no blocked operation.');
    }

    /**
     * Clause (c) against the contract itself: every operation a row declares
     * blocked is a real §4.2 row, is named as blocked in §8.3's own prose, and
     * §14.1 carries an open item owned by Output 9.
     *
     * **Mutation:** rename a `blocks` entry to an operationId §4.2 does not
     * declare; red on the first assertion.
     */
    #[Test]
    public function everyBlockedOperationIsDeclaredInTheContractAndRoutedToOutput9(): void
    {
        if (!ContractDocument::isAvailable()) {
            self::markTestSkipped('docs/API.md is gitignored; the pins above are checked against the code on every run.');
        }

        $declared = ContractDocument::operationRows();
        $matrixSection = ContractDocument::sectionBetween('### 8.3 ', '## 9. ');
        $openItems = ContractDocument::sectionBetween('### 14.1 ', '### 14.2 ');

        $blocked = [];
        foreach (self::MATRIX as $row) {
            if (null !== $row['method'] && method_exists($row['entity'], $row['method'])) {
                continue;
            }
            $blocked = [...$blocked, ...$row['blocks']];
        }
        $blocked = array_values(array_unique($blocked));
        sort($blocked);

        self::assertNotSame([], $blocked, 'No blocked operation was collected, so this clause had no subject.');

        $undeclared = array_values(array_filter($blocked, static fn (string $id): bool => !isset($declared[$id])));
        self::assertSame([], $undeclared, 'A matrix row is blocking an operation §4.2 does not declare.');

        $unmarked = array_values(array_filter($blocked, static fn (string $id): bool => !str_contains($matrixSection, '`'.$id.'`')));
        self::assertSame([], $unmarked, '§8.3 does not name this operation as blocked on a missing method.');

        self::assertMatchesRegularExpression(
            '/\|\s*Output 9\s*\|/',
            $openItems,
            '§14.1 carries no open item owned by Output 9, so the missing transitions are routed nowhere.',
        );
    }

    /**
     * Every assignment to `$this->status` reachable from a status machine sits
     * inside a **status writer** — a method the transitive scan found — and every
     * file that contributes one is scanned, not only the entity's own.
     *
     * Two corrections from `RED`, both proven by execution rather than argued:
     * the scan read only `ReflectionClass::getFileName()`, so an assignment moved
     * into `GuardsStatusTransitions` was invisible; and it applied line ranges
     * taken from a method to whichever file it happened to be reading, so a public
     * trait method would have whitelisted an arbitrary window of the entity file.
     * Ranges are now matched per file.
     *
     * The property declaration needs no exclusion — a declaration contains no
     * `$this->status`, so {@see StatusMachines::ASSIGNMENT} cannot match it. An
     * assignment written inside a comment is skipped, because §8.2's own prose
     * quotes that string and a docblock is not a write.
     *
     * The second clause is here because a mutation stated in this docblock came
     * back **green** and the docblock was wrong, not the mutation: an assignment
     * inside a private method nothing calls is *contained* — the method holds its
     * own assignment — so containment alone cannot see dead status-writing code.
     * Reachability from a public method is what sees it.
     *
     * **Mutation (containment):** put an assignment in a second class declared in
     * the entity's own file; orphaned, named by file and line. **Mutation
     * (reachability):** add a private method that assigns the status and call it
     * from nowhere; red, naming the method. **Mutation that is deliberately
     * green:** move `Invoice::void()`'s body into a private `apply()` and call it —
     * the transitive scan keeps `void()` in clause (a)'s domain, which is the point
     * of making discovery transitive at all.
     */
    #[Test]
    public function everyStatusAssignmentSitsInsideAReachableStatusWriter(): void
    {
        $sites = 0;
        $orphans = [];
        $unreachable = [];

        foreach (StatusMachines::all() as $entity) {
            $writers = StatusMachines::writers($entity);
            $reachable = StatusMachines::reachableFromPublic($entity);

            foreach (array_keys($writers) as $name) {
                if (!isset($reachable[$name])) {
                    $unreachable[] = $entity.'::'.$name.'()';
                }
            }

            foreach (StatusMachines::filesOf($entity) as $file) {
                $ranges = [];

                foreach (array_keys($writers) as $name) {
                    $reflection = new \ReflectionMethod($entity, $name);
                    if ($reflection->getFileName() === $file) {
                        $ranges[] = [(int) $reflection->getStartLine(), (int) $reflection->getEndLine()];
                    }
                }

                foreach (StatusMachines::lines($file) as $index => $line) {
                    if (1 !== preg_match(StatusMachines::ASSIGNMENT, $line) || self::isComment($line)) {
                        continue;
                    }

                    ++$sites;
                    $number = $index + 1;

                    foreach ($ranges as [$from, $to]) {
                        if ($number >= $from && $number <= $to) {
                            continue 2;
                        }
                    }

                    $orphans[] = basename($file).':'.$number;
                }
            }
        }

        self::assertGreaterThan(0, $sites, 'No status assignment was found at all, so this clause had no subject.');
        self::assertSame([], $orphans, 'A status is assigned outside every method the transition scan found.');
        self::assertSame([], $unreachable, 'A method writes the status and no public method can reach it.');
    }

    /** A line whose content opens with a comment marker. Not a write. */
    private static function isComment(string $line): bool
    {
        return 1 === preg_match('~^\s*(//|\*|/\*)~', $line);
    }

    // -----------------------------------------------------------------------
    // Derivations
    // -----------------------------------------------------------------------

    /** @return list<class-string> */
    private static function statusMachines(): array
    {
        return StatusMachines::all();
    }

    /** @return list<class-string> */
    private static function matrixEntities(): array
    {
        $entities = array_values(array_unique(array_column(self::MATRIX, 'entity')));
        sort($entities);

        return $entities;
    }

    /**
     * @param class-string $entity
     *
     * @return list<string>
     */
    private static function statusesOf(string $entity): array
    {
        return StatusMachines::statusesOf($entity);
    }

    /**
     * The initial status. §8.3 clause (b) counts it as reached, because
     * construction reaches it.
     *
     * @param class-string $entity
     */
    private static function initialStatusOf(string $entity): string
    {
        return StatusMachines::initialStatusOf($entity);
    }

    /**
     * Clause (b)'s covered set: the initial status, plus the `Result` of every
     * matrix row whose `Method` cell names a method that **exists**.
     *
     * The `method_exists` restriction is the whole clause. Without it the four
     * rows that declare a transition nobody has written would count as coverage of
     * themselves — the declaration of the gap counted as coverage of the gap, which
     * is precisely how revision 2 of §8.3 was green over an order that can never
     * leave `ordered`.
     *
     * @param class-string $entity
     *
     * @return list<string>
     */
    private static function coveredStatusesOf(string $entity): array
    {
        $covered = [self::initialStatusOf($entity)];

        foreach (self::MATRIX as $row) {
            if ($row['entity'] !== $entity || null === $row['method'] || !method_exists($entity, $row['method'])) {
                continue;
            }

            $covered = [...$covered, ...$row['to']];
        }

        return array_values(array_unique($covered));
    }

    /**
     * The public entry points into the status machine, found transitively — see
     * {@see StatusMachines} for why the first, non-transitive version of this was
     * broken by `RED` and what it would have missed.
     *
     * @param class-string $entity
     *
     * @return list<string>
     */
    private static function transitionMethods(string $entity): array
    {
        return StatusMachines::transitionMethods($entity);
    }

    /** @param class-string $entity */
    private static function sourceOf(string $entity, string $method): string
    {
        return StatusMachines::sourceOf($entity, $method);
    }

    /**
     * @param class-string $entity
     *
     * @return list<string>
     */
    private static function legalSourcesFor(string $entity, string $method): array
    {
        $sources = [];

        foreach (self::MATRIX as $row) {
            if ($row['entity'] === $entity && $row['method'] === $method) {
                $sources = [...$sources, ...$row['from']];
            }
        }

        return $sources;
    }

    /**
     * An entity in a chosen status, with nothing else touched.
     *
     * Built with `newInstanceWithoutConstructor()` and a reflected `status`,
     * because the point of the call is the guard and the three constructors
     * between them want a supplier, a warehouse, a customer, a currency, a country
     * and a sales order — six rows of fixture to reach a branch that reads one
     * property. Every `status` property carries a declared default, so the
     * reflected set never lands on an uninitialised typed property, and every
     * transition method assigns scalars only.
     *
     * @param class-string $entity
     */
    private static function instance(string $entity, string $status): object
    {
        $reflection = new \ReflectionClass($entity);
        $subject = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('status')->setValue($subject, $status);

        return $subject;
    }

    /** @param class-string $entity */
    private static function invoke(object $subject, string $entity, string $method): void
    {
        (new \ReflectionMethod($entity, $method))->invokeArgs($subject, self::argumentsFor(new \ReflectionMethod($entity, $method)));
    }

    /**
     * Every **initialised** property of the subject. An uninitialised typed
     * property is reported as a sentinel rather than skipped, so a guard that
     * writes a field before refusing shows up as a changed snapshot rather than as
     * a key that quietly appears.
     *
     * @return array<string, mixed>
     */
    private static function snapshot(object $subject): array
    {
        $values = [];

        foreach ((new \ReflectionClass($subject))->getProperties() as $property) {
            $values[$property->getName()] = $property->isInitialized($subject)
                ? $property->getValue($subject)
                : '<uninitialised>';
        }

        return $values;
    }


    /**
     * Required arguments, synthesised from the parameter types. An unknown type
     * fails the test rather than being skipped — a transition method with a
     * signature this cannot build is one that would otherwise drop silently out of
     * clause (a)'s domain.
     *
     * @return list<mixed>
     */
    private static function argumentsFor(\ReflectionMethod $method): array
    {
        $arguments = [];

        foreach ($method->getParameters() as $parameter) {
            if ($parameter->isOptional()) {
                break;
            }

            $type = $parameter->getType();

            if ($type instanceof \ReflectionNamedType && \DateTimeImmutable::class === $type->getName()) {
                $arguments[] = new \DateTimeImmutable();

                continue;
            }

            self::fail(\sprintf(
                '%s::%s() takes $%s, whose type this test cannot synthesise. Teach it the type rather than excluding the method.',
                $method->getDeclaringClass()->getName(),
                $method->getName(),
                $parameter->getName(),
            ));
        }

        return $arguments;
    }
}

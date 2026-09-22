<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Modules\Sales\Infrastructure\Entity\Invoice;
use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use App\Platform\Domain\IllegalTransition;
use App\Platform\Tenancy\Entity\Tenant;
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
 * - the **entities in the domain** — every class under `src/` that
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
 * ⚖ **2026-09-22: the pin retired itself.** `SalesOrder::recordFulfilment()` (sales step 5, commit 4)
 * reached `fulfilled` and `partially_fulfilled`, the last two pinned statuses, so `PINNED_UNREACHABLE` is
 * empty for every entity and §8.3 clause (b) now holds LITERALLY. What follows is the history of why it
 * was a pin.
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
     * `method` is `null` on the rows §8.3 marks *(no method exists)*, which
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
            // ⚠ `method` was `null` (receipt posting, ⛔ Output 9) until step 8
            // wrote `recordReceipt()`; `blocks` empties with it.
            'entity' => PurchaseOrder::class, 'method' => 'recordReceipt',
            'from' => [PurchaseOrder::STATUS_ORDERED, PurchaseOrder::STATUS_PARTIALLY_RECEIVED],
            'to' => [PurchaseOrder::STATUS_PARTIALLY_RECEIVED, PurchaseOrder::STATUS_RECEIVED],
            'blocks' => [],
        ],
        // ⚠ **This row's `method` was `null` until 2026-09-11 and is the reason
        // the clause-(b) pin below lost a member.** §8.3 said this check *"stays
        // red until Output 9 writes `cancel()`"*; the method now exists, so the
        // row names it, `STATUS_CANCELLED` becomes covered, and `blocks` empties
        // because nothing is blocked on it any more. All three edits are forced
        // by the code — leaving any one of them undone is red.
        [
            'entity' => PurchaseOrder::class, 'method' => 'cancel',
            'from' => [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_ORDERED, PurchaseOrder::STATUS_PARTIALLY_RECEIVED],
            'to' => [PurchaseOrder::STATUS_CANCELLED],
            'blocks' => [],
        ],
        [
            'entity' => SalesOrder::class, 'method' => 'confirm',
            'from' => [SalesOrder::STATUS_DRAFT],
            'to' => [SalesOrder::STATUS_CONFIRMED],
            'blocks' => [],
        ],
        [
            // ⚠ `method` was `null` (fulfilment posting, ⛔ Output 9) and `blocks` was `['CreateFulfilment']`
            // until 2026-09-22: sales step 5 commit 4 wrote `recordFulfilment()` and ships the operation, so the
            // row names it, both statuses leave `PINNED_UNREACHABLE`, and `blocks` empties. **The last of API-O-17's
            // four.** (The Invoice payment-reversal row below still names no method.) All three edits are forced by the code.
            'entity' => SalesOrder::class, 'method' => 'recordFulfilment',
            'from' => [SalesOrder::STATUS_CONFIRMED, SalesOrder::STATUS_PARTIALLY_FULFILLED],
            'to' => [SalesOrder::STATUS_PARTIALLY_FULFILLED, SalesOrder::STATUS_FULFILLED],
            'blocks' => [],
        ],
        [
            // `method` was `null` and `blocks` was `['CancelSalesOrder']` until
            // 2026-09-19: `SalesOrder::cancel()` now exists and the operation
            // ships, so API-O-17's last sales row is discharged and clause (c)
            // has nothing to find here. `partially_fulfilled` stays among the
            // legal sources because §8.3 declares it, though nothing can reach
            // that status yet — see `cancel()`'s FORWARD DECLARATION.
            'entity' => SalesOrder::class, 'method' => 'cancel',
            'from' => [SalesOrder::STATUS_DRAFT, SalesOrder::STATUS_CONFIRMED, SalesOrder::STATUS_PARTIALLY_FULFILLED],
            'to' => [SalesOrder::STATUS_CANCELLED],
            'blocks' => [],
        ],
        [
            'entity' => Invoice::class, 'method' => 'markPaid',
            'from' => [Invoice::STATUS_ISSUED, Invoice::STATUS_OVERDUE],
            'to' => [Invoice::STATUS_PAID],
            'blocks' => [],
        ],
        // ⚠ **Added 2026-09-14 (owner ruling on OI-2).** §8.3 now declares
        // `paid → issued` legal — a payment recorded in error — and no method
        // performs it, so the matrix was green over the gap until this row named
        // it. `STATUS_ISSUED` is the initial status, so clause (b)'s pin does not
        // move; clause (c) is what this row feeds.
        [
            'entity' => Invoice::class, 'method' => null, // payment reversal, ⛔ no method
            'from' => [Invoice::STATUS_PAID],
            'to' => [Invoice::STATUS_ISSUED],
            'blocks' => ['ReverseInvoicePayment'],
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
            // `STATUS_CANCELLED` left this pin on 2026-09-11: `cancel()` exists,
            // the matrix row above names it, and the status is now reachable by
            // an implemented transition. **`SalesOrder::STATUS_CANCELLED` stays**
            // — `SalesOrder::cancel()` was not written, and it is not this
            // package's to write, so §8.3 clause (b) is still owed on that half.
            // `STATUS_PARTIALLY_RECEIVED` and `STATUS_RECEIVED` left it at step 8:
            // `recordReceipt()` reaches both. The purchase side owes nothing.
        ],
        SalesOrder::class => [
            // `STATUS_CANCELLED` LEFT this pin on 2026-09-19: `cancel()` exists,
            // the matrix row above names it, and the status is now reachable by
            // an implemented transition. It was the last of API-O-17's four and
            // the only one the note above said was "not this package's to write".
            // `STATUS_FULFILLED` and `STATUS_PARTIALLY_FULFILLED` LEFT this pin on 2026-09-22: `recordFulfilment()`
            // reaches both (sales step 5, commit 4). The sales side now owes nothing either.
        ],
        Invoice::class => [],
    ];

    /**
     * **The scalar arguments clause (a) has to synthesise, by name — and, where
     * the parameter carries a precondition, the value that FAILS it.**
     *
     * Until 2026-09-12 this register was two inline `if` blocks inside
     * `argumentsFor()` that each supplied a *satisfying* value and nothing else,
     * and that is why
     * `everyTransitionMethodRefusesEveryIllegalSourceStatus`'s third limb —
     * *"an illegal source status was refused by something other than the status
     * guard"* — **had no mutation that could redden it.** `place()` was always
     * called with `$liveLineCount = 1`, so `has_a_live_line` could not fire at
     * all, so no ordering of the two guards inside `place()` changed what that
     * limb saw. A clause with no reachable failure is the shape this project has
     * now paid for six times.
     *
     * `refusing` is what gives it one. With `$liveLineCount = 0` **both** guards
     * inside `place()` have something to say, and which of them speaks is D-C's
     * shipped ruling (the project notes, step 6a): **status first, then the line
     * guard** — a cancelled empty draft is told *"wrong status"*, not *"add a
     * line"*, because adding a line is advice it cannot follow, line writes
     * requiring `draft`. `theStatusGuardRefusesBeforeAnyPreconditionDoes` is
     * that ruling as a check.
     *
     * `refusing` is **null** where the parameter has no refusing value at all:
     * `recordReceipt()`'s flag is legal either way, and inventing a "refusing"
     * value for it would be a case that proves nothing.
     * `theStatusGuardRefusesBeforeAnyPreconditionDoes` refuses to take a stated
     * `refusing` value on trust — it requires each one to actually refuse from a
     * source the matrix allows, so a register entry cannot go quietly vacuous
     * the way the two `if` blocks did.
     *
     * The keys are `Entity::method($parameter)` and
     * `theSynthesisedScalarsAreExactlyTheRequiredScalarParametersInTheDomain`
     * derives that set by reflection, so a transition method that grows a
     * required scalar cannot be silently filled with a guessed value, and one
     * that loses a parameter cannot leave a stale entry behind.
     *
     * `rule` is the rule half of the refusal the `refusing` value must produce
     * (`IllegalTransition::unmetPrecondition()`'s `$rule`), null exactly where
     * `refusing` is. Without it the legal-source half of
     * `theStatusGuardRefusesBeforeAnyPreconditionDoes` accepted **any**
     * non-status refusal, so a value that tripped the wrong precondition passed.
     *
     * @var array<string, array{satisfying: int|bool, refusing: int|bool|null, rule: ?string, why: string}>
     */
    private const PRECONDITION_SCALARS = [
        PurchaseOrder::class.'::place($liveLineCount)' => [
            'satisfying' => 1,
            'refusing' => 0,
            'rule' => 'has_a_live_line',
            'why' => 'Step 6a / D-C: an order with no live line may not be placed. 1 satisfies the precondition, so a '
                .'legal matrix cell is not refused by a rule it is not about; 0 fails it, which is what makes the '
                .'status guard\'s precedence checkable. `DomainRefusalTest` owns what the 0 case says over HTTP.',
        ],
        PurchaseOrder::class.'::place($warehouseIsLive)' => [
            'satisfying' => true,
            'refusing' => false,
            'rule' => 'warehouse_is_live',
            'why' => 'Five-calls follow-up (owner ruling 2026-09-13): an order is not placed against an archived '
                .'warehouse. true satisfies it, so no other cell is refused by a rule it is not about; false fails it, '
                .'so the status guard\'s precedence over this guard is checked too. The order between this guard and '
                .'the line guard is `DomainRefusalTest::theLineGuardRunsBeforeTheWarehouseGuard`\'s — this register '
                .'refuses one precondition at a time.',
        ],
        SalesOrder::class.'::confirm($liveLineCount)' => [
            'satisfying' => 1,
            'refusing' => 0,
            'rule' => 'has_a_live_line',
            'why' => 'V2-R-22 Half 1, which is D-C copied to the selling side: an order with no live line may not be '
                .'confirmed. 1 satisfies the precondition, so a legal matrix cell is not refused by a rule it is not '
                .'about; 0 fails it, which is what makes the status guard\'s precedence checkable here too. The state '
                .'is reachable — CreateSalesOrder accepts an absent or null lines[] as a legal empty draft. '
                .'`SalesOrderConfirmTest` owns what the 0 case says over HTTP.',
        ],
        SalesOrder::class.'::recordFulfilment($allLinesFulfilled)' => [
            'satisfying' => true,
            'refusing' => null,
            'rule' => null,
            'why' => 'Sales step 5\'s outcome flag, `recordReceipt()`\'s mirror. Either value is legal from either source '
                .'-- it chooses between `partially_fulfilled` and `fulfilled`, it does not gate the transition -- so '
                .'there is no refusing value to give it. What the flag decides is `FulfilmentCreateTest`\'s.',
        ],
        PurchaseOrder::class.'::recordReceipt($allLinesReceived)' => [
            'satisfying' => true,
            'refusing' => null,
            'rule' => null,
            'why' => 'Step 8\'s outcome flag. Either value is legal from either source — it chooses between '
                .'`partially_received` and `received`, it does not gate the transition — so there is no refusing '
                .'value to give it. What the flag decides is `GoodsReceiptCreateTest`\'s.',
        ],
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

        self::assertSame($declared, $found, 'A status machine under src/ is neither in §8.3\'s matrix nor in its written exclusions.');
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
        $notAStatusRefusal = [];

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
                    } catch (IllegalTransition $e) {
                        // The refusal must be the STATUS guard's. Since step 6a
                        // an `IllegalTransition` can also be a content refusal —
                        // any PRECONDITION_SCALARS entry's own rule
                        // (`has_a_live_line`, `warehouse_is_live`, ...) — and one
                        // firing from an illegal source would hide a missing
                        // status guard.
                        if (!str_ends_with($e->rule(), '.'.IllegalTransition::RULE)) {
                            $notAStatusRefusal[] = $entity.'::'.$method.'() from "'.$status.'": '.$e->rule();
                        }

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
        self::assertSame([], $notAStatusRefusal, 'An illegal source status was refused by something other than the status guard.');
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

    /**
     * The scalars {@see self::PRECONDITION_SCALARS} supplies are **exactly** the
     * required scalar parameters clause (a)'s domain declares — derived by
     * reflection over the matrix's entities and their transition methods, not
     * restated.
     *
     * This is the other half of the brief's question *"is the `int → 1` synthesis
     * still scoped to `place()`'s `$liveLineCount` by name, and is any other
     * required scalar being silently filled?"*. Read once, the answer is yes and
     * no; asserted here, it stays that way. A new required scalar on a transition
     * method is red until somebody decides what satisfies it and what refuses it.
     *
     * **Mutation:** delete either entry from the register; red here, naming the
     * parameter, and `argumentsFor()` then fails the four clauses that invoke it.
     */
    #[Test]
    public function theSynthesisedScalarsAreExactlyTheRequiredScalarParametersInTheDomain(): void
    {
        $required = [];

        foreach (self::matrixEntities() as $entity) {
            foreach (self::transitionMethods($entity) as $method) {
                foreach ((new \ReflectionMethod($entity, $method))->getParameters() as $parameter) {
                    if ($parameter->isOptional()) {
                        break;
                    }

                    $type = $parameter->getType();

                    if ($type instanceof \ReflectionNamedType && $type->isBuiltin()) {
                        $required[] = self::scalarKey($entity, $method, $parameter->getName());
                    }
                }
            }
        }

        sort($required);
        $registered = array_keys(self::PRECONDITION_SCALARS);
        sort($registered);

        self::assertNotSame([], $required,
            'No transition method takes a required scalar, so the register has no subject — retire it rather than '
            .'leaving a list nothing is compared against.');

        self::assertSame($registered, $required,
            'PRECONDITION_SCALARS does not name exactly the required scalar parameters of the transition methods in '
            .'§8.3\'s matrix. A parameter this test synthesises a value for without a decision behind it is how '
            .'clause (a) ends up exercising a branch nobody chose.');
    }

    /**
     * **D-C's ordering, as a check: the status guard speaks before any
     * precondition does.**
     *
     * Run for every register entry that has a refusing value, and in both
     * directions, because either one alone is worthless:
     *
     *  - from every source the matrix **allows**, the refusing value must produce
     *    an `IllegalTransition` that is *not* a status refusal. That is the
     *    non-vacuity half — it proves the value really does fail the precondition,
     *    so a register entry cannot claim a refusing value it does not have;
     *  - from every source the matrix **refuses**, the same call must be refused
     *    by the **status** rule, with the entity untouched. That is the ordering
     *    half, and it is the case
     *    `everyTransitionMethodRefusesEveryIllegalSourceStatus` cannot reach,
     *    because it always passes the satisfying value.
     *
     * ⚠ **What this does NOT claim, because the brief that asked for it was
     * wrong about the code.** Step 9's brief said clause (a)'s status-rule limb
     * *"has no mutation that can redden it today"*, and that is true **of this
     * file**. It is not true of the project: `DomainRefusalTest::theStatusGuardRunsBeforeTheLineGuard`
     * already drives `place()` at `liveLineCount: 0` from `ordered` and already
     * reddens under the same mutation (verified — both go red together). What
     * this adds over it is that it is **derived**: one hand-written pair becomes
     * every register entry × every status the matrix refuses, so the day a
     * status is added or a second transition grows a precondition, the case
     * appears without anybody writing it; plus the vacuity guard below, which
     * the hand-written pair carries as a fixture assumption rather than an
     * assertion.
     *
     * **Mutation (the ordering):** move `refuseUnlessStatusIsOneOf()` below the
     * `$liveLineCount` check in `PurchaseOrder::place()`; red here on all three
     * illegal sources — `purchase_order.has_a_live_line` where the status rule is
     * owed — and **green everywhere else in this file**, which is precisely the
     * gap. **Mutation (the vacuity guard):** set `place()`'s `refusing` to `1`;
     * red on the first limb, because a value that satisfies the precondition
     * refuses nothing.
     *
     * **Legal-source half tightened at the five-calls review (2026-09-13).** It
     * now requires the refusal to carry the entry's own `rule` and the entity to
     * be untouched. *Mutations, run:* the two rule names inside `place()`
     * swapped — red here on both entries, and the previous version of this test
     * was green under it; `$this->orderedAt = $orderedAt` inserted just above the
     * warehouse guard — red here on the snapshot, green in the previous version
     * and in `DomainRefusalTest`. Both reverted `cmp`-identical.
     */
    #[Test]
    public function theStatusGuardRefusesBeforeAnyPreconditionDoes(): void
    {
        $legalCases = 0;
        $illegalCases = 0;
        $notRefused = [];
        $refusedByTheStatusGuard = [];
        $refusedByAnotherRule = [];
        $mutatedWhileRefusingAPrecondition = [];
        $accepted = [];
        $notAStatusRefusal = [];
        $mutated = [];

        foreach (self::PRECONDITION_SCALARS as $key => $entry) {
            if (null === $entry['refusing']) {
                continue;
            }

            [$entity, $method] = self::splitScalarKey($key);
            $legal = self::legalSourcesFor($entity, $method);

            foreach ($legal as $status) {
                ++$legalCases;
                $subject = self::instance($entity, $status);
                $before = self::snapshot($subject);

                try {
                    self::invoke($subject, $entity, $method, $key);
                    $notRefused[] = $key.' from "'.$status.'"';
                } catch (IllegalTransition $e) {
                    if (str_ends_with($e->rule(), '.'.IllegalTransition::RULE)) {
                        $refusedByTheStatusGuard[] = $key.' from "'.$status.'": '.$e->rule();
                    } elseif (!str_ends_with($e->rule(), '.'.$entry['rule'])) {
                        $refusedByAnotherRule[] = $key.' from "'.$status.'": '.$e->rule().' (expected .'.$entry['rule'].')';
                    }

                    if ($before != self::snapshot($subject)) {
                        $mutatedWhileRefusingAPrecondition[] = $key.' from "'.$status.'"';
                    }
                }
            }

            foreach (self::statusesOf($entity) as $status) {
                if (\in_array($status, $legal, true)) {
                    continue;
                }

                ++$illegalCases;
                $subject = self::instance($entity, $status);
                $before = self::snapshot($subject);

                try {
                    self::invoke($subject, $entity, $method, $key);
                    $accepted[] = $key.' from "'.$status.'"';
                } catch (IllegalTransition $e) {
                    if (!str_ends_with($e->rule(), '.'.IllegalTransition::RULE)) {
                        $notAStatusRefusal[] = $key.' from "'.$status.'": '.$e->rule();
                    }

                    if ($before != self::snapshot($subject)) {
                        $mutated[] = $key.' from "'.$status.'"';
                    }
                }
            }
        }

        self::assertGreaterThan(0, $legalCases, 'No register entry declares a refusing value, so this clause had no subject.');
        self::assertGreaterThan(0, $illegalCases, 'No illegal source was exercised, so the ordering half had no subject.');

        self::assertSame([], $notRefused,
            'A PRECONDITION_SCALARS entry states a refusing value and the method accepted it from a status the matrix '
            .'allows. The value refuses nothing, so every case below it is vacuous.');
        self::assertSame([], $refusedByTheStatusGuard,
            'A refusing value was refused by the STATUS guard from a source the matrix allows, so this case is not '
            .'about the precondition at all.');
        self::assertSame([], $refusedByAnotherRule,
            'A refusing value was refused by a precondition other than its own. The register entry refuses nothing it '
            .'names, or the method\'s guards give the wrong rule.');
        self::assertSame([], $mutatedWhileRefusingAPrecondition,
            'A transition refused by a precondition from a legal source left the entity modified — the precondition '
            .'guard runs after part of the write.');

        self::assertSame([], $accepted, 'A transition method accepted an illegal source status when its precondition also failed.');
        self::assertSame([], $notAStatusRefusal,
            'A transition was refused by its precondition from a source status the matrix does not allow. D-C ruled the '
            .'other way round (status first, then the content guard): a cancelled empty draft must be told its status is '
            .'wrong, not told to add a line — adding a line is advice it cannot follow, because line writes require draft.');
        self::assertSame([], $mutated, 'A refused transition left the entity modified — the guard runs after part of the write.');
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

        // ⚠ **The pin RETIRED ITSELF on 2026-09-22** (sales step 5, commit 4: `SalesOrder::recordFulfilment()`
        // reached the last two pinned statuses), exactly as the class docblock says it would. This assertion
        // read `assertGreaterThan(0, $pinned)` until then -- "the pin was empty, so this clause had no subject"
        // -- because an empty pin used to mean a broken rig. It now means §8.3 clause (b) holds LITERALLY, so
        // the anti-vacuity moves to what clause (b) itself asserts: every status of every matrix entity is
        // reached by an implemented transition, over a domain that is not empty.
        if (0 === $pinned) {
            $statuses = 0;
            $uncovered = [];
            foreach (self::matrixEntities() as $entity) {
                $statuses += \count(self::statusesOf($entity));
                foreach (array_diff(self::statusesOf($entity), self::coveredStatusesOf($entity)) as $status) {
                    $uncovered[] = $entity.'::"'.$status.'"';
                }
            }
            self::assertGreaterThan(0, $statuses, 'The matrix entities declare no status at all, so clause (b) holds over nothing.');
            self::assertSame([], $uncovered, 'The pin is empty, so §8.3 clause (b) must hold literally -- and a status is unreached.');
        }
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

    /**
     * @param class-string $entity
     * @param ?string      $refuseAt the {@see self::PRECONDITION_SCALARS} key whose
     *                               **refusing** value to pass instead of its satisfying one
     */
    private static function invoke(object $subject, string $entity, string $method, ?string $refuseAt = null): void
    {
        (new \ReflectionMethod($entity, $method))->invokeArgs($subject, self::argumentsFor($entity, $method, $refuseAt));
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
     * Required arguments, synthesised from the parameter types and — for the
     * scalars — from {@see self::PRECONDITION_SCALARS}, **by name**. An unknown
     * type fails the test rather than being skipped: a transition method with a
     * signature this cannot build is one that would otherwise drop silently out
     * of clause (a)'s domain.
     *
     * @param class-string $entity
     * @param ?string      $refuseAt the register key to pass the **refusing** value for
     *
     * @return list<mixed>
     */
    private static function argumentsFor(string $entity, string $method, ?string $refuseAt = null): array
    {
        $arguments = [];

        foreach ((new \ReflectionMethod($entity, $method))->getParameters() as $parameter) {
            if ($parameter->isOptional()) {
                break;
            }

            $type = $parameter->getType();

            if ($type instanceof \ReflectionNamedType && \DateTimeImmutable::class === $type->getName()) {
                $arguments[] = new \DateTimeImmutable();

                continue;
            }

            $key = self::scalarKey($entity, $method, $parameter->getName());

            if (isset(self::PRECONDITION_SCALARS[$key])) {
                $entry = self::PRECONDITION_SCALARS[$key];

                $arguments[] = $key === $refuseAt && null !== $entry['refusing']
                    ? $entry['refusing']
                    : $entry['satisfying'];

                continue;
            }

            self::fail(\sprintf(
                '%s::%s() takes $%s, which this test cannot synthesise. Give it an entry in PRECONDITION_SCALARS '
                .'with the value that satisfies it and the value that refuses it, rather than excluding the method.',
                $entity,
                $method,
                $parameter->getName(),
            ));
        }

        return $arguments;
    }

    /** The {@see self::PRECONDITION_SCALARS} key for one parameter, in one place so both sides spell it the same way. */
    private static function scalarKey(string $entity, string $method, string $parameter): string
    {
        return $entity.'::'.$method.'($'.$parameter.')';
    }

    /**
     * @return array{0: class-string, 1: string}
     */
    private static function splitScalarKey(string $key): array
    {
        [$entity, $rest] = explode('::', $key, 2);

        /* @var class-string $entity */
        return [$entity, substr($rest, 0, (int) strpos($rest, '('))];
    }
}

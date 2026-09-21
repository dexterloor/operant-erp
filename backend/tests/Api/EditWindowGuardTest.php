<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\HttpOperation;
use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use App\Tests\Support\PhpSourceScan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`API.md` §8.3a — the edit-window guards.** Which *operations* may edit a document, and in which
 * statuses.
 *
 * ===========================================================================
 * Why this class exists rather than a row in §8.3's matrix — MEASURED, not read
 * ===========================================================================
 * Owner ruling **O-2** (2026-09-19) required that *"frozen at confirm"* ship with *"a named guard and a
 * test in the commit that introduces it"*, and the obvious reading is *"put it in §8.3"*. **That
 * reading is impossible**, and it was established by planting rather than by reading:
 * `TransitionGuardTest`'s domain is `StatusMachines::writers()`, a fixpoint over methods matching
 * `ASSIGNMENT = '/\$this->status\s*…=/'`. An edit-window guard assigns no status. On 2026-09-21 `RED`
 * planted `refuseUnlessStatusIsOneOf(__FUNCTION__, [self::STATUS_DRAFT])` as the first statement of
 * `SalesOrder::shipTo()` and ran the whole suite: `OK (1011 tests, 14284 assertions)`, **byte-identical
 * to baseline**. A harsher variant with a bare `'draft'` literal also passed. **The whole project was
 * blind**, not merely §8.3.
 *
 * ===========================================================================
 * The three clauses, and what each is worth
 * ===========================================================================
 * - **(a)** {@see self::everyNamedOperationIsRefusedFromEveryStatusTheRowDoesNotList} — the cross
 *   product of each entity's `STATUS_*` constants (by **reflection**, so a new constant forces new
 *   cells with nobody editing a list) with the row's operations, minus the legal set. Each illegal cell
 *   is driven over HTTP and must answer 409 with the row's rule code.
 * - **(a)'s anti-vacuity control** — {@see self::everyLegalStatusIsNotRefusedByTheEditWindow}. It is
 *   what fails if somebody answers a red in (a) by widening a guard. It asserts on the **`rule`
 *   member**, never on the status code, because a legal drive may fail for an unrelated reason and that
 *   must not read as an edit-window refusal.
 * - **(b)** {@see self::everyWriterThatTakesAnEditWindowGuardIsNamedByARow} — **the clause that makes
 *   §8.3a a mechanism instead of a list.** It derives from `src/`, through the registry, which
 *   operations reach a `refuseUnless*()`-shaped guard, and requires each to be named by a row.
 *
 * ⚠ **Clause (a) cannot be a loop, and that is a measured constraint rather than a style.** A refused
 * request closes the shared `EntityManager`, so a second seed inside the same test method dies with
 * `Doctrine\ORM\Exception\EntityManagerClosed`. Hence `#[DataProvider]`, one cell per test case.
 *
 * ⚠ **The replay exemption is real, shipped and deliberate.** `CreatePurchaseOrderLine` **succeeds from
 * `ordered`** when the request replays an `Idempotency-Key` that already created a line —
 * `PurchaseOrderWriter::createLine()` puts `lineCreatedByKeyInside()` **before** `refuseUnlessDraft()`
 * on purpose, and `PurchaseOrderLineIdempotencyTest::aLineCreateRetryAfterApprovalReturnsTheLine`
 * asserts it. So clause (a) sends a **fresh** key for every cell: a replayed key would be answered by
 * the stored line, not by the guard, and the cell would prove nothing.
 * {@see self::theReplayExemptionIsRealAndIsNotAnEscapeHatch} drives the exemption itself, so it is a
 * tested behaviour here and not only a sentence in §8.3a's table.
 *
 * ⚠ **Reachability, so a green run is not over-read.** `SalesOrder`'s `fulfilled` and
 * `partially_fulfilled` are **not reachable through any built operation** — nothing writes
 * `quantity_fulfilled` (`RESIDUAL_GAPS.md` G-7) — so those cells are seeded by direct `UPDATE` as the
 * schema owner. Nobody may conclude from this class that the API can produce those states.
 *
 * ⚠ **Clause (b)'s blind spot, stated rather than hidden.** The scan is **name-shaped**: it looks for a
 * call whose method name matches `/^refuseUnless[A-Z]/`. A guard spelled `assertDraft()`, or inlined at
 * the call site as a bare `if`, is invisible to it. {@see self::theWriterScanFindsAPlantedGuard} is the
 * positive control that keeps the clause non-vacuous; it does not close the blind spot.
 */
final class EditWindowGuardTest extends PurchaseOrderTestCase
{
    /**
     * **§8.3a's table, and the one place it is written in code.**
     *
     * `from` holds `STATUS_*` **constants**, never string literals — §8.3a clause (c), the discipline
     * API-15 imposes on transition guards. `statuses` is the entity's whole value space, read by
     * **reflection** in {@see self::everyIllegalCell()} rather than listed here, so the illegal set is
     * derived by subtraction and a new constant appears as new cells on its own.
     *
     * @var array<string, array{entity: class-string, rule: string, from: list<string>, operations: list<string>}>
     */
    private const ROWS = [
        'PurchaseOrder' => [
            'entity' => PurchaseOrder::class,
            'rule' => 'purchase_order.is_editable_only_while_draft',
            'from' => [PurchaseOrder::STATUS_DRAFT],
            'operations' => [
                'UpdatePurchaseOrder',
                'DeletePurchaseOrder',
                'CreatePurchaseOrderLine',
                'UpdatePurchaseOrderLine',
                'DeletePurchaseOrderLine',
            ],
        ],
        'SalesOrder' => [
            'entity' => SalesOrder::class,
            'rule' => 'sales_order.is_editable_only_while_draft',
            'from' => [SalesOrder::STATUS_DRAFT],
            'operations' => [
                'UpdateSalesOrder',
                // Added 2026-09-21. Its writer calls `refuseUnlessDraft()`, so clause (b) forces the
                // name here -- and it fired in the REVERSE direction first, naming this operation as
                // derived-but-unlisted, which is what §8.3a's *fails if* (b) is for.
                'CreateSalesOrderLine',
                'UpdateSalesOrderLine',
                'DeleteSalesOrderLine',
                'RestoreSalesOrderLine',
            ],
        ],
    ];

    /**
     * **Clause (a).** One case per illegal `(operation, status)` cell.
     */
    #[Test]
    #[DataProvider('everyIllegalCell')]
    public function everyNamedOperationIsRefusedFromEveryStatusTheRowDoesNotList(string $row, string $operation, string $status): void
    {
        $this->driveEditWindow($row, $operation, $status);

        self::assertSame(409, $this->httpStatus(), \sprintf(
            '%s from %s must be refused by the edit window. Answer: %s',
            $operation, $status, substr((string) $this->client()->getResponse()->getContent(), 0, 400),
        ));

        $body = $this->json();
        self::assertSame('urn:erp:problem:business-rule-violated', $body['type'] ?? null);
        self::assertSame(self::ROWS[$row]['rule'], $body['rule'] ?? null);
    }

    /**
     * **Clause (a)'s anti-vacuity control.** From every *legal* status the same drive must not carry the
     * row's rule code.
     *
     * It asserts on the `rule` member and **not** on the status code: a legal drive may still be
     * refused for a reason of its own, and that must not be mistaken for the edit window. Without this
     * method, a guard widened to `true` would turn every cell of clause (a) green.
     */
    #[Test]
    #[DataProvider('everyLegalCell')]
    public function everyLegalStatusIsNotRefusedByTheEditWindow(string $row, string $operation, string $status): void
    {
        $this->driveEditWindow($row, $operation, $status);

        // A 204 carries no body at all -- `DeletePurchaseOrder` and `DeletePurchaseOrderLine` succeed
        // from `draft` -- so the rule member is read from the raw content rather than through `json()`,
        // which asserts the body is an object. A successful delete is the strongest possible pass here.
        $content = (string) $this->client()->getResponse()->getContent();
        $decoded = '' === $content ? [] : json_decode($content, true);

        self::assertNotSame(self::ROWS[$row]['rule'], \is_array($decoded) ? ($decoded['rule'] ?? null) : null, \sprintf(
            '%s from %s is a legal edit window and must not be refused by it. Answer: %s',
            $operation, $status, substr((string) $this->client()->getResponse()->getContent(), 0, 400),
        ));
    }

    /**
     * **V2-S-13 / D-A: `sales_order_line.unit_price` freezes once the order is confirmed — and THIS
     * ASSERTION is the only thing making a per-operation window carry a per-column rule.**
     *
     * D-A (amended 2026-09-13): *"`sales_order_line.unit_price` freezes once confirmed."* §8.3a is **per
     * operation**, not per field, so it records *that* the window is draft-only and not *why*
     * `unit_price` in particular must never move after confirm. Draft-only editing is strictly stronger
     * than the freeze — every request the freeze would refuse, the window already refuses, from
     * `confirmed`, `partially_fulfilled`, `fulfilled` and `cancelled` alike — so the freeze needs no
     * guard of its own **while the window stays draft-only**. That proviso is what this method holds.
     *
     * ⚠ **This is `RED`'s form, not the design's, and the difference was MEASURED rather than argued.**
     * The design proposed `assertSame([STATUS_DRAFT], self::ROWS['SalesOrder']['from'])`. `RED` planted
     * three states and ran them on 2026-09-21: that form is **GREEN** when `UpdateSalesOrderLine` is
     * moved to a **row of its own** whose `from` admits `confirmed` — which is exactly how §8.3a's own
     * text describes the next step, a per-document row for the line resource. Under that edit D-A's
     * freeze evaporates and **nothing anywhere goes red**: clause (b) flattens every row's operations
     * into one set and asks only for membership, so a row exists, the operation is named, and it passes.
     *
     * This form asserts the window of **every row naming the operation**, so it is red on the row split,
     * red on a widened `from`, and red if the operation is dropped from the table altogether. `RED` ran
     * all three, the positive control included.
     *
     * **If this ever needs to change**, §8.3a needs its *frozen fields* column **and** clause (a) needs
     * to drive per field, in the same commit. Widening the window without both is how a price moves on a
     * confirmed order with nothing to show for it.
     */
    #[Test]
    public function theUnitPriceFreezeIsCarriedByTheEditWindowOnlyWhileTheWindowIsDraftOnly(): void
    {
        $windows = [];
        foreach (self::ROWS as $name => $row) {
            if (\in_array('UpdateSalesOrderLine', $row['operations'], true)) {
                $windows[$name] = $row['from'];
            }
        }

        self::assertSame(['SalesOrder' => [SalesOrder::STATUS_DRAFT]], $windows,
            'D-A freezes sales_order_line.unit_price at confirm, and §8.3a is per OPERATION rather than per field: '
            ."this assertion is the only thing making the per-operation window carry that per-column rule.\n"
            ."UpdateSalesOrderLine must be named by exactly one row, 'SalesOrder', whose window is STATUS_DRAFT alone.\n"
            .'Widening that window -- or moving the operation to a row of its own with a wider one, which is what '
            ."§8.3a's own text describes as the next step -- requires §8.3a to gain a *frozen fields* column AND "
            .'clause (a) to drive per field, in the same commit. Nothing else in this project would notice.');
    }

    /**
     * **Clause (b) — the clause that makes the table a mechanism.**
     *
     * The derivation, entirely from `src/` and the registry:
     *
     *   1. every registry operation that declares a `processor`;
     *   2. that processor's source, scanned — the writer classes it names and the methods it calls;
     *   3. for each such writer under `src/Modules/<M>/Application/`, the closure of its own calls, so
     *      a guard reached through a private helper still counts;
     *   4. if the public method the processor calls reaches a `/^refuseUnless[A-Z]/` call, that
     *      operation takes an edit-window guard and §8.3a must name it.
     *
     * ⚠ **Entity-level transition guards are deliberately NOT in this domain.**
     * `refuseUnlessStatusIsOneOf()` lives on `GuardsStatusTransitions` and is called from entities, not
     * from `Application/` writers; §8.3 owns those and this clause would otherwise sweep every
     * transition into §8.3a. The boundary is *where the guard is called from*, which is the same
     * boundary §8.3a's text draws.
     */
    #[Test]
    public function everyWriterThatTakesAnEditWindowGuardIsNamedByARow(): void
    {
        $named = [];
        foreach (self::ROWS as $row) {
            $named = [...$named, ...$row['operations']];
        }
        sort($named);

        $found = array_keys(self::operationsTakingAnEditWindowGuard());
        sort($found);

        self::assertSame($found, $named, \sprintf(
            "API.md §8.3a must name exactly the operations whose writer takes an edit-window guard.\n"
            ."Derived from src/ through the registry: %s\nNamed by §8.3a's rows: %s\n"
            ."A new operation with a refuseUnless*() guard needs a row, in the same commit. Do not add it to an "
            ."exclusion list -- a list is what §8.3a exists to replace.",
            implode(', ', $found) ?: '(none)', implode(', ', $named) ?: '(none)',
        ));
    }

    /**
     * **Clause (b)'s positive control**, and the reason clause (b) is evidence rather than a green
     * nothing: the scan must find a guard it has never seen before.
     *
     * The fixture is a real source string put through the same `PhpSourceScan` and the same reachability
     * closure clause (b) uses — not a second, friendlier parser. It plants the guard **behind a private
     * helper**, so it also proves the closure step rather than only the name match.
     */
    #[Test]
    public function theWriterScanFindsAPlantedGuard(): void
    {
        $planted = <<<'PHP'
            <?php
            namespace App\Modules\Nowhere\Application;
            final class NowhereWriter
            {
                public function edit(string $id): void { $this->check($id); }
                public function read(string $id): void { }
                private function check(string $id): void { $this->refuseUnlessSomething($id); }
                private function refuseUnlessSomething(string $id): void { }
            }
            PHP;

        $guarded = self::guardedPublicMethodsIn($planted);

        self::assertContains('edit', $guarded,
            'The clause (b) scan did not find a refuseUnless*() guard reached through a private helper, so every '
            .'"no operation takes a guard" answer it gives is worthless.');
        self::assertNotContains('read', $guarded,
            'The clause (b) scan credited a method that reaches no guard, so it would name operations that owe no row.');
    }

    /**
     * **Clause (c): no edit-window guard enumerates a status as a string literal.**
     *
     * §8.3a's `Editable from` cells name `STATUS_*` constants, and the guards must read them off the
     * entity — so renaming a constant's **value** moves the rule with it instead of leaving a guard
     * comparing against a string nothing produces any more. This is the discipline
     * `TransitionGuardTest::noGuardEnumeratesAStatusAsAStringLiteral` imposes on transition guards;
     * §8.3 cannot reach these methods (they assign no status), so clause (c) is enforced here.
     *
     * The domain is derived: every method in `src/` whose name matches `/^refuseUnless[A-Z]/`, and every
     * string literal it holds, compared against the **entity's own constant values** by reflection.
     * ⚠ A guard that built the string by concatenation would be invisible — the same literal-shaped
     * blind spot every scan in this project discloses.
     */
    #[Test]
    public function noEditWindowGuardEnumeratesAStatusAsAStringLiteral(): void
    {
        $statuses = [];
        foreach (self::ROWS as $row) {
            $statuses = [...$statuses, ...self::statusesOf($row['entity'])];
        }
        $statuses = array_values(array_unique($statuses));
        self::assertNotEmpty($statuses, 'No status values were derived, so this check compares against nothing.');

        $scanned = 0;
        $offending = [];
        foreach (PhpSourceScan::phpFilesUnder(\dirname(__DIR__, 2).'/src') as $path) {
            if (1 !== preg_match('#/src/Modules/[^/]+/Application/#', $path)) {
                continue;
            }

            foreach (PhpSourceScan::scan((string) file_get_contents($path))['methods'] as $key => $method) {
                if (1 !== preg_match('/^refuseUnless[A-Z]/', $method['name'])) {
                    continue;
                }

                ++$scanned;
                foreach ($method['literals'] as $literal) {
                    if (\in_array($literal['value'], $statuses, true)) {
                        $offending[] = $key.' spells '.var_export($literal['value'], true);
                    }
                }
            }
        }

        self::assertGreaterThan(0, $scanned,
            'The scan found no refuseUnless*() method under src/Modules/*/Application/, so clause (c) is vacuous.');
        self::assertSame([], $offending, \sprintf(
            "API.md §8.3a clause (c): an edit-window guard enumerates a status as a string literal instead of reading "
            ."the entity's STATUS_* constant.\n%s", implode("\n", $offending),
        ));
    }

    /**
     * **The replay exemption of §8.3a's `CreatePurchaseOrderLine` row, driven.**
     *
     * This is the one place an operation in the table legitimately **succeeds** from a status the row
     * does not list, and it is shipped, deliberate and asserted elsewhere
     * (`PurchaseOrderLineIdempotencyTest::aLineCreateRetryAfterApprovalReturnsTheLine`). It is repeated
     * here for one reason: **§8.3a's clause (a) is worded around this exemption**, and an exemption
     * whose only evidence lives in another class's test is an exemption a later hand will "clean up".
     * If this method ever goes red, the fix is not to move the key lookup after the guard — that would
     * be a real regression — it is to re-read §8.3a's table.
     */
    #[Test]
    public function theReplayExemptionIsRealAndIsNotAnEscapeHatch(): void
    {
        $context = $this->seedDraft(self::TENANT_A, 'PO-REPLAY');
        // `purchase_order_line.supplier_lists_the_item` fires before the edit window otherwise, and the
        // exemption would never be reached -- found by running this, not by reading `createLine()`.
        $this->seedSupplierProduct(self::TENANT_A, $context['supplier'], $context['item']);
        $key = Uuid::v4()->toRfc4122();
        $body = [
            'inventory_item_id' => $context['item'],
            'quantity' => '1',
            'unit_cost' => '1',
        ];

        $this->send('POST', '/purchase-orders/'.$context['order'].'/lines', $body, ['Idempotency-Key' => $key]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $first = $this->json()['id'];

        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE purchase_order SET status = ?, ordered_at = now() WHERE id = ?::uuid',
            [PurchaseOrder::STATUS_ORDERED, $context['order']]);

        $this->send('POST', '/purchase-orders/'.$context['order'].'/lines', $body, ['Idempotency-Key' => $key]);
        self::assertSame(201, $this->httpStatus(),
            'A replayed key on an ordered purchase order must be handed the line its first attempt committed, not '
            .'refused by the edit window. §8.3a names this exemption in its own table.');
        self::assertSame($first, $this->json()['id']);
    }

    // -----------------------------------------------------------------------
    // Clause (a)'s domain, derived by subtraction from the entity's constants
    // -----------------------------------------------------------------------

    /** @return iterable<string, array{string, string, string}> */
    public static function everyIllegalCell(): iterable
    {
        yield from self::cells(legal: false);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function everyLegalCell(): iterable
    {
        yield from self::cells(legal: true);
    }

    /** @return iterable<string, array{string, string, string}> */
    private static function cells(bool $legal): iterable
    {
        foreach (self::ROWS as $name => $row) {
            $statuses = self::statusesOf($row['entity']);
            self::assertNotEmpty($statuses, 'An entity in §8.3a declares no STATUS_* constants, so its cross product is empty.');

            foreach ($statuses as $status) {
                if (\in_array($status, $row['from'], true) !== $legal) {
                    continue;
                }

                foreach ($row['operations'] as $operation) {
                    yield $operation.' from '.$status => [$name, $operation, $status];
                }
            }
        }
    }

    /**
     * The entity's whole status value space, by reflection — never a list in this file.
     *
     * @param class-string $entity
     *
     * @return list<string>
     */
    private static function statusesOf(string $entity): array
    {
        $statuses = [];
        foreach ((new \ReflectionClass($entity))->getReflectionConstants() as $constant) {
            if (str_starts_with($constant->getName(), 'STATUS_') && \is_string($value = $constant->getValue())) {
                $statuses[] = $value;
            }
        }

        return $statuses;
    }

    // -----------------------------------------------------------------------
    // Clause (b)'s derivation
    // -----------------------------------------------------------------------

    /**
     * Registry operation name => the `Writer::method` that carries its edit-window guard.
     *
     * @return array<string, string>
     */
    private static function operationsTakingAnEditWindowGuard(): array
    {
        $guarded = [];
        $resolved = 0;
        $taking = [];

        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            \assert($operation instanceof HttpOperation);
            $processor = $operation->getProcessor();
            if (!\is_string($processor) || !class_exists($processor)) {
                continue;
            }

            $reflection = new \ReflectionClass($processor);
            $file = $reflection->getFileName();
            if (!\is_string($file)) {
                continue;
            }

            // What the processor calls. `PhpSourceScan` reads these off the tokens, so a call added to
            // a processor is seen without anybody editing anything here.
            $calls = [];
            foreach (PhpSourceScan::scan((string) file_get_contents($file))['methods'] as $method) {
                $calls = [...$calls, ...$method['calls']];
            }

            // Which class it calls them ON, resolved by **reflection on the constructor's parameter
            // types** rather than from the source. `PhpSourceScan::scan()['named']` is empty for a
            // processor -- measured, 2026-09-21 -- because a promoted constructor property's type is
            // not a `new X` or an `X::class`, which is what that key collects. Reading the container's
            // own wiring is both more honest and harder to fool.
            foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
                $type = $parameter->getType();
                if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }

                $class = $type->getName();
                if (!class_exists($class)) {
                    continue;
                }

                $collaborator = (new \ReflectionClass($class))->getFileName();
                if (!\is_string($collaborator) || 1 !== preg_match('#/src/Modules/[^/]+/Application/#', $collaborator)) {
                    continue;
                }

                ++$resolved;
                $guarded[$class] ??= self::guardedPublicMethodsIn((string) file_get_contents($collaborator));

                foreach ($guarded[$class] as $method) {
                    if (\in_array($method, $calls, true)) {
                        $taking[(string) $name] = substr((string) strrchr('\\'.$class, '\\'), 1).'::'.$method;
                    }
                }
            }
        }

        // Anti-vacuity for the RESOLUTION step, which the planted-source control does not reach: if no
        // processor resolved to an `Application/` collaborator at all, every answer this method gives is
        // "(none)" for a reason that has nothing to do with guards.
        self::assertGreaterThan(0, $resolved,
            'No registry processor resolved to a class under src/Modules/*/Application/, so clause (b) is scanning '
            .'nothing and would report "no operation takes a guard" however many do.');

        return $taking;
    }

    /**
     * The public methods of one source that reach a `refuseUnless*()` call, directly or through the
     * private helpers they call.
     *
     * The closure is over `calls` within the same class, which is what makes a guard behind a helper
     * visible — `PurchaseOrderWriter::archiveLine()` calls `refuseUnlessDraft()` directly, but nothing
     * guarantees the next one will.
     *
     * @return list<string>
     */
    private static function guardedPublicMethodsIn(string $source): array
    {
        $scanned = PhpSourceScan::scan($source);

        $direct = [];
        $calls = [];
        $public = [];
        foreach ($scanned['methods'] as $method) {
            $name = $method['name'];
            $calls[$name] = $method['calls'];
            if ($method['public']) {
                $public[] = $name;
            }
            foreach ($method['calls'] as $called) {
                if (1 === preg_match('/^refuseUnless[A-Z]/', $called)) {
                    $direct[$name] = true;
                }
            }
        }

        // Fixpoint: a method is guarded if it calls a guarded method.
        do {
            $before = \count($direct);
            foreach ($calls as $name => $called) {
                foreach ($called as $one) {
                    if (isset($direct[$one])) {
                        $direct[$name] = true;
                    }
                }
            }
        } while (\count($direct) !== $before);

        // The guard declaration itself is not a caller of anything, and a private helper is not an
        // operation's entry point: only public methods are what a processor can call.
        return array_values(array_filter($public, static fn (string $name): bool => isset($direct[$name])
            && 1 !== preg_match('/^refuseUnless[A-Z]/', $name)));
    }

    // -----------------------------------------------------------------------
    // Driving one cell
    // -----------------------------------------------------------------------

    /**
     * Seed the document in `$status` and send the operation's request.
     *
     * Every request carries a **fresh** `Idempotency-Key`, so `CreatePurchaseOrderLine` reaches its
     * guard rather than its replay branch — see the class docblock.
     */
    private function driveEditWindow(string $row, string $operation, string $status): void
    {
        if ('SalesOrder' === $row) {
            $this->driveSalesOrderEdit($operation, $status);

            return;
        }

        $this->drivePurchaseOrderEdit($operation, $status);
    }

    private function driveSalesOrderEdit(string $operation, string $status): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'EW Customer '.$status);
        $address = $this->seedCustomerAddress(self::TENANT_A, $customer);
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'EW WH '.$status);
        // `sales_order` carries NO `ordered_at` CHECK -- measured against `pg_constraint`, and unlike
        // `purchase_order`, which is why this seed needs no date. `fulfilled` and `partially_fulfilled`
        // are seeded here and are reachable through no built operation (G-7).
        $order = $this->seedSalesOrder(self::TENANT_A, 'SO-EW-'.substr(md5($status), 0, 6), $customer, $warehouse, $status);
        $line = $this->seedSalesOrderLine(self::TENANT_A, $order, $this->seedItem(self::TENANT_A, 'SKU-EW-'.substr(md5($status), 0, 6)));
        // A SECOND line, archived, so `RestoreSalesOrderLine` has a subject its own guard admits. Without
        // it that operation's cells would answer 409 for `sales_order_line.is_not_archived` and prove
        // nothing about the edit window -- a refusal for the wrong reason is not evidence.
        $archived = $this->seedSalesOrderLine(self::TENANT_A, $order,
            $this->seedItem(self::TENANT_A, 'SKU-EWA-'.substr(md5($status), 0, 6)), lineNumber: 2);
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'UPDATE sales_order_line SET deleted_at = now() WHERE id = ?::uuid', [$archived]);

        $key = ['Idempotency-Key' => Uuid::v4()->toRfc4122()];

        // ⚠ **This was a bare `assertSame('UpdateSalesOrder', $operation)` until 2026-09-21**, so the
        // FIRST cell of any second sales operation failed here rather than where it meant anything.
        // `RED` measured the cost before it was paid: planting one extra operation into the row produced
        // **five** failures from that single line -- the four illegal-status cells and clause (a)'s
        // legal-status anti-vacuity drive. The `default` arm keeps the loudness the `assertSame` had,
        // which is the half worth keeping: a row naming an operation nothing can drive must fail, not
        // silently pass.
        match ($operation) {
            'UpdateSalesOrder' => $this->send('PATCH', '/sales-orders/'.$order, ['ship_to_address_id' => $address], $key),
            'CreateSalesOrderLine' => $this->send('POST', '/sales-orders/'.$order.'/lines', [
                'inventory_item_id' => $this->seedItem(self::TENANT_A, 'SKU-EW2-'.substr(md5($operation.$status), 0, 6)),
                'quantity' => '1', 'unit_price' => '1',
            ], $key),
            // ⚠ **`unit_price` is sent, and that is the difference between a test that carries V2-S-13
            // and a test that sits next to it.** The purchase twin's drive sends `['quantity' => '2']`;
            // copying it verbatim would give four cells proving the window refuses a *quantity* change
            // and saying nothing about D-A's frozen column.
            'UpdateSalesOrderLine' => $this->send('PATCH', '/sales-orders/'.$order.'/lines/'.$line, ['quantity' => '2', 'unit_price' => '3'], $key),
            'DeleteSalesOrderLine' => $this->send('DELETE', '/sales-orders/'.$order.'/lines/'.$line, null, $key),
            // Driven at the archived line: a live one is refused by the restore's own guard, which would
            // answer 409 for the wrong reason and make the cell prove nothing about the edit window.
            'RestoreSalesOrderLine' => $this->send('POST', '/sales-orders/'.$order.'/lines/'.$archived.'/restoration', null, $key),
            default => self::fail('§8.3a names a sales-order operation this test cannot drive: '.$operation),
        };
    }

    private function drivePurchaseOrderEdit(string $operation, string $status): void
    {
        $context = $this->seedDraft(self::TENANT_A, 'PO-EW-'.substr(md5($operation.$status), 0, 6));
        $order = $context['order'];
        $line = $this->seedLine(self::TENANT_A, $order, $context['item']);

        // `seedOrder()` handles `purchase_order_ordered_at_set`
        // (`CHECK (status = ANY (ARRAY['draft','cancelled']) OR ordered_at IS NOT NULL)`), but this row
        // already exists, so the date is set here in the same statement that moves the status.
        $this->executeAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            UPDATE purchase_order
               SET status = ?,
                   ordered_at = CASE WHEN ? = ANY (ARRAY['draft', 'cancelled']) THEN ordered_at ELSE now() END
             WHERE id = ?::uuid
            SQL, [$status, $status, $order]);

        $key = ['Idempotency-Key' => Uuid::v4()->toRfc4122()];

        match ($operation) {
            'UpdatePurchaseOrder' => $this->send('PATCH', '/purchase-orders/'.$order, ['expected_at' => '2030-01-01T00:00:00Z'], $key),
            'DeletePurchaseOrder' => $this->send('DELETE', '/purchase-orders/'.$order, null, $key),
            'CreatePurchaseOrderLine' => $this->send('POST', '/purchase-orders/'.$order.'/lines', [
                'inventory_item_id' => $context['item'], 'quantity' => '1', 'unit_cost' => '1',
            ], $key),
            'UpdatePurchaseOrderLine' => $this->send('PATCH', '/purchase-orders/'.$order.'/lines/'.$line, ['quantity' => '2'], $key),
            'DeletePurchaseOrderLine' => $this->send('DELETE', '/purchase-orders/'.$order.'/lines/'.$line, null, $key),
            default => self::fail('§8.3a names a purchase-order operation this test cannot drive: '.$operation),
        };
    }
}

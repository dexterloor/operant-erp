<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`CreateSalesOrderLine`'s write behaviour — its retry key, its edit window and its money** (Output 9, sales
 * slice 4, 2026-09-21).
 *
 * The line create mints a new id and `MAX(line_number) + 1` on every request, and the only unique on the table is
 * `(tenant_id, sales_order_id, line_number)` among live lines. A retried timeout would therefore write a second line and
 * `recomputeTotal()` would raise the order's total — an order a rep would then confirm to a customer at the wrong amount.
 *
 * The mechanism is `CreatePurchaseOrderLine`'s exactly: the `Idempotency-Key` header's SHA-256 in
 * `sales_order_line.idempotency_key` on the live-row unique `sales_order_line_tenant_idem_live`, with a `request_digest`
 * (D-B) that includes the **path's order id**, so the same key sent to another order is a 409 and never a replay of
 * another order's line.
 *
 * ⚠ **What this class proves that `IdempotencyTest::replayCreateSalesOrderLine` does not:** that one drives the ordinary
 * retry. These drive the two edges — §8.3a's **replay exemption** (a retry arriving after the order was confirmed) and
 * the digest conflict — and the exemption is the one that would otherwise be carried by a sentence in §8.3a's table with
 * nothing exercising it. That is the prose-carry shape this project keeps finding, so it is driven here.
 *
 * **Rows are counted in the database, not in responses:** a replay that answered 201 twice while writing twice passes
 * every response-level assertion.
 */
final class SalesOrderLineWriteTest extends PurchaseOrderTestCase
{
    /** @return array{order: string, item: string} */
    private function draftWithASecondItem(string $number = 'SO-1'): array
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Acme Retail '.$number);
        $order = $this->seedSalesOrder(self::TENANT_A, $number, $customer, $this->seedWarehouse(self::TENANT_A, 'WH '.$number));

        return ['order' => $order, 'item' => $this->seedItem(self::TENANT_A, 'SKU-'.$number)];
    }

    /** @param array<string, string> $body */
    private function createLine(string $orderId, array $body, ?string $key): void
    {
        $this->send('POST', '/sales-orders/'.$orderId.'/lines', $body, null === $key ? [] : [IdempotencyKeyGuard::HEADER => $key]);
    }

    /** @return array<string, string> */
    private static function body(string $item, string $quantity = '2', string $unitPrice = '3'): array
    {
        return ['inventory_item_id' => $item, 'quantity' => $quantity, 'unit_price' => $unitPrice];
    }

    private function lineCount(string $orderId): int
    {
        return $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM sales_order_line WHERE sales_order_id = ?::uuid', [$orderId]);
    }

    /**
     * **§8.3a's replay exemption, driven rather than declared.**
     *
     * `createLine()` runs `lineCreatedByKeyInside()` **before** `refuseUnlessDraft()` on purpose, so a retry that
     * arrives after the order was confirmed is handed the line its first attempt committed rather than refused for an
     * act that already happened. §8.3a's `Replay exemption` cell names this operation, and this is what makes that cell
     * a behaviour instead of a sentence.
     *
     * The last two assertions are the half that stops the exemption being an escape hatch: a **new** key on a confirmed
     * order is still refused by the edit window, with the named rule.
     */
    #[Test]
    public function aLineCreateRetryAfterConfirmationReturnsTheLine(): void
    {
        $draft = $this->draftWithASecondItem();
        $key = Uuid::v4()->toRfc4122();

        $this->createLine($draft['order'], self::body($draft['item']), $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $first = $this->json()['id'];

        $this->insertAsOwnerForTenant(self::TENANT_A,
            'UPDATE sales_order SET status = ?, ordered_at = now() WHERE id = ?::uuid',
            [SalesOrder::STATUS_CONFIRMED, $draft['order']]);

        $this->createLine($draft['order'], self::body($draft['item']), $key);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($first, $this->json()['id']);
        self::assertSame(1, $this->lineCount($draft['order']));

        // And a NEW key on the confirmed order is still refused by the edit window.
        $this->createLine($draft['order'], self::body($draft['item']), Uuid::v4()->toRfc4122());
        self::assertSame(409, $this->httpStatus());
        self::assertSame('sales_order.is_editable_only_while_draft', $this->json()['rule']);
    }

    /**
     * §6.2: the same key with a different body is a **conflict**, not a replay — and nothing is written.
     *
     * The digest is what tells the two apart, so this is the assertion that fails if the digest ever stops covering a
     * field the caller can change.
     */
    #[Test]
    public function theSameKeyWithADifferentBodyIsAConflictAndWritesNothing(): void
    {
        $draft = $this->draftWithASecondItem();
        $key = Uuid::v4()->toRfc4122();

        $this->createLine($draft['order'], self::body($draft['item']), $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->createLine($draft['order'], self::body($draft['item'], quantity: '5'), $key);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('urn:erp:problem:idempotency-key-conflict', $this->json()['type'] ?? null);
        self::assertSame(1, $this->lineCount($draft['order']));
    }

    /**
     * **The digest carries the PATH'S ORDER ID, and this is what proves it.**
     *
     * The unique is tenant-wide — a header supplies one value — so without the order id in the digest the same key sent
     * to a *second* order would be answered with the *first* order's line: a replay of a line the caller never asked
     * for, on a document they did not address. Instead it is a 409, and the second order stays empty.
     */
    #[Test]
    public function theSameKeyOnAnotherOrderIsAConflictAndNeverAnotherOrdersLine(): void
    {
        $first = $this->draftWithASecondItem('SO-1');
        $second = $this->draftWithASecondItem('SO-2');
        $key = Uuid::v4()->toRfc4122();

        $this->createLine($first['order'], self::body($first['item']), $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $line = $this->json()['id'];

        $this->createLine($second['order'], self::body($second['item']), $key);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertNotSame($line, $this->json()['id'] ?? null, 'A key reused on another order was answered with the first order\'s line.');
        self::assertSame(0, $this->lineCount($second['order']));
    }

    /** API-14 as amended: a `unique:` cell demands the header. No key → 428, and nothing is written. */
    #[Test]
    public function aLineCreateWithoutAKeyIs428(): void
    {
        $draft = $this->draftWithASecondItem();

        $this->createLine($draft['order'], self::body($draft['item']), null);

        self::assertSame(428, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(0, $this->lineCount($draft['order']));
    }

    /**
     * **The total is recomputed from the TABLE, not from the request** — `insertOrderInside()`'s throwaway `VALUES`
     * expression is deleted and this is what would catch its return.
     *
     * The order is raised through the API with one line at `2 × 1`, then a second line is added at `2 × 3`. A writer
     * that summed only the request it saw would leave `6.0000`; the table says `8.0000`.
     */
    #[Test]
    public function addingALineRecomputesTheTotalFromEveryLiveLine(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Acme Retail TOT');
        $this->send('POST', '/sales-orders', [
            'customer_id' => $customer,
            'warehouse_id' => $this->seedWarehouse(self::TENANT_A, 'WH TOT'),
            'ship_to_address_id' => $this->seedCustomerAddress(self::TENANT_A, $customer),
            'lines' => [['inventory_item_id' => $this->seedItem(self::TENANT_A, 'SKU-TOT-A'), 'quantity' => '2', 'unit_price' => '1']],
        ], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $order = $this->json()['id'];

        // The nested create's own total, recomputed from the table by the same statement.
        self::assertSame('2.0000', $this->json()['total_amount']);

        $this->createLine($order, self::body($this->seedItem(self::TENANT_A, 'SKU-TOT-B')), Uuid::v4()->toRfc4122());
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        self::assertSame([['total_amount' => '8.0000']], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT total_amount FROM sales_order WHERE id = ?::uuid', [$order]),
            'The total was not recomputed over every live line of the order.');
    }

    /**
     * **A SUM that overflows `numeric(19,4)` is refused in domain terms, never answered with a 500 — and this test
     * exists because a mutation proved nothing was checking it.**
     *
     * ⚠ **Found by running, not by reading.** The slice-4 design listed *"replace `recomputeTotal()`'s
     * read-judge-write with one `UPDATE … SET total = (SELECT SUM(…))` — expect the 22003 probe red"*, and added:
     * *"if it is green, the probe does not exist on this side and must be written."* **It was green.** The mutation was
     * planted, the whole sales suite stayed green, and the purchase side's overflow probes turned out to cover only the
     * **nested** create's pre-flight bound — nothing covered the sub-resource path at all.
     *
     * **Why the per-line bound cannot catch this.** `malformedLineFields()` bounds *this line's* product, and this
     * line's product is `1.0000`. The order already holds `999999999999999.9999`, so the SUM is sixteen integer digits
     * and `numeric(19,4)` holds fifteen. Only a guard that reads the **whole order back** can see it, which is exactly
     * what `recomputeTotal()`'s read-judge-write does and what one `UPDATE … SET total = (SELECT SUM(…))` cannot:
     * `22003` is neither constraint class any translator catches, so it surfaces as an untyped `DriverException` and a
     * **500**.
     *
     * The last assertion is the half that makes this more than a status check: the refusal happens after the `INSERT`,
     * inside the transaction, so the line must not survive it.
     */
    #[Test]
    public function aLineThatTipsTheOrdersTotalPastItsColumnIsRefusedRatherThanAnsweringA500(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Acme Retail OVF');
        $order = $this->seedSalesOrder(self::TENANT_A, 'SO-OVF', $customer, $this->seedWarehouse(self::TENANT_A, 'WH OVF'));
        // Individually legal: `numeric(19,4)` holds fifteen integer digits, and so does this.
        $this->seedSalesOrderLine(self::TENANT_A, $order, $this->seedItem(self::TENANT_A, 'SKU-OVF-A'),
            quantity: '1.000000', unitPrice: '999999999999999.9999');

        // Also individually legal -- its own product is 1.0000 -- and it is the line that tips the sum.
        $this->createLine($order, self::body($this->seedItem(self::TENANT_A, 'SKU-OVF-B'), quantity: '1', unitPrice: '1'),
            Uuid::v4()->toRfc4122());

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('total_out_of_range',
            array_column($this->json()['errors'], 'code', 'pointer')['/unit_price'] ?? null,
            'The refusal must name a field of THIS request, which is the only thing the caller can lower.');
        self::assertSame(1, $this->lineCount($order), 'The refused line survived the transaction it was refused in.');
    }

    /**
     * **An empty `PATCH` is legal: no statement, the row read back, 200.**
     *
     * This is not a curiosity — `ArchivedRowSubjects`' write bodies are `static`, so they may carry no
     * seeded id, and the trait's control requires the **live** twin to succeed with the same body. A
     * writer that refused an empty `PATCH` would make that control impossible to satisfy.
     */
    #[Test]
    public function anEmptyPatchOfALineIsLegalAndChangesNothing(): void
    {
        $draft = $this->draftWithASecondItem();
        $line = $this->seedSalesOrderLine(self::TENANT_A, $draft['order'], $draft['item'],
            quantity: '4.000000', unitPrice: '2.5000');

        $this->send('PATCH', '/sales-orders/'.$draft['order'].'/lines/'.$line, []);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('4.000000', $this->json()['quantity']);
        self::assertSame('2.5000', $this->json()['unit_price']);
    }

    /**
     * **A line of ANOTHER order is `not-found`, not an edit of somebody else's line.**
     *
     * The pair `(id, lineId)` is what names the row, in the provider and again in the writer's own read
     * inside the transaction. A provider's read is outside the transaction, so the second one is not
     * redundant.
     */
    #[Test]
    public function aLineIdBelongingToAnotherOrderIsNotFound(): void
    {
        $a = $this->draftWithASecondItem('SO-1');
        $b = $this->draftWithASecondItem('SO-2');
        $lineOfB = $this->seedSalesOrderLine(self::TENANT_A, $b['order'], $b['item']);

        $this->send('PATCH', '/sales-orders/'.$a['order'].'/lines/'.$lineOfB, ['quantity' => '9']);

        self::assertSame(404, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([['quantity' => '10.000000']], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT quantity FROM sales_order_line WHERE id = ?::uuid', [$lineOfB]),
            'The line of the other order was edited through a path that does not name it.');
    }

    /**
     * **`sales_order_line_qty_sane`'s third clause, refused in domain terms — and the CHECK proved to be
     * a live backstop by a direct statement.**
     *
     * ⚠ **The operation cannot reach this refusal today and the test says so out loud.** Nothing under
     * `src/` writes `quantity_fulfilled` (G-7), so the column is always `0` and `quantity > 0` already
     * refuses everything below it. The fixture therefore sets `quantity_fulfilled` **as the schema
     * owner**, which no built operation can do. *A green run here says nothing about what the API can
     * produce* — the same caveat `EditWindowGuardTest` carries for `fulfilled` and `partially_fulfilled`.
     * It is written now because *"unreachable today"* is the premise that expires at `CreateFulfilment`,
     * where G-7's trigger list names it first.
     *
     * The second half is the anti-vacuity control **and** the reason the pre-check is not merely
     * decorative: the same write issued as raw SQL, bypassing the writer, is refused by the database with
     * `23514`. So the CHECK is real, the pre-check is what turns it into a 409 with a rule name instead
     * of an untyped `DriverException` and an HTTP 500.
     */
    #[Test]
    public function loweringAQuantityBelowWhatIsFulfilledIsRefusedInDomainTermsAndTheCheckStillBacksIt(): void
    {
        $draft = $this->draftWithASecondItem();
        $line = $this->seedSalesOrderLine(self::TENANT_A, $draft['order'], $draft['item'], quantity: '10.000000');
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'UPDATE sales_order_line SET quantity_fulfilled = 4 WHERE id = ?::uuid', [$line]);

        $this->send('PATCH', '/sales-orders/'.$draft['order'].'/lines/'.$line, ['quantity' => '3']);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('sales_order_line.quantity_is_not_below_what_is_fulfilled', $this->json()['rule'] ?? null);
        self::assertSame([['quantity' => '10.000000']], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT quantity FROM sales_order_line WHERE id = ?::uuid', [$line]));

        // The backstop, proved by a statement the writer does not issue.
        $refused = false;
        try {
            $this->insertAsOwnerForTenant(self::TENANT_A,
                'UPDATE sales_order_line SET quantity = 3 WHERE id = ?::uuid', [$line]);
        } catch (\Throwable $e) {
            $refused = str_contains($e->getMessage(), 'sales_order_line_qty_sane');
        }

        self::assertTrue($refused,
            'sales_order_line_qty_sane did not refuse a direct write that lowers quantity below quantity_fulfilled, '
            .'so the pre-check above is the only thing standing between this and a corrupt line.');
    }

    /**
     * **`DeleteSalesOrderLine` is a SOFT delete, and the order's total falls with it.**
     *
     * Three things at once, because they are one behaviour: the row survives with `deleted_at` set (a
     * hard `DELETE` would fail at `fulfilment_line_order_line_fk` or orphan a shipment record), the
     * answer is 204 with no body (API-21 branch 3), and `recomputeTotal()` runs with a **null pointer**
     * because a `DELETE` carries no field a refusal could name.
     *
     * `erp_app` holds no `DELETE` on this table and needs none — measured in this commit, not inherited.
     */
    #[Test]
    public function deletingALineArchivesItAndLowersTheOrdersTotal(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Acme Retail DEL');
        $this->send('POST', '/sales-orders', [
            'customer_id' => $customer,
            'warehouse_id' => $this->seedWarehouse(self::TENANT_A, 'WH DEL'),
            'ship_to_address_id' => $this->seedCustomerAddress(self::TENANT_A, $customer),
            'lines' => [
                ['inventory_item_id' => $this->seedItem(self::TENANT_A, 'SKU-DEL-A'), 'quantity' => '2', 'unit_price' => '1'],
                ['inventory_item_id' => $this->seedItem(self::TENANT_A, 'SKU-DEL-B'), 'quantity' => '3', 'unit_price' => '1'],
            ],
        ], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $order = $this->json()['id'];
        self::assertSame('5.0000', $this->json()['total_amount']);

        $this->send('GET', '/sales-orders/'.$order.'/lines');
        $line = $this->json()['items'][1]['id'];

        $this->send('DELETE', '/sales-orders/'.$order.'/lines/'.$line);

        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('', (string) $this->client()->getResponse()->getContent(), 'API-21 branch 3: 204 and no body.');

        // Soft: the row is still there, archived.
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM sales_order_line WHERE id = ?::uuid AND deleted_at IS NOT NULL', [$line]),
            'The line was hard-deleted, or not archived at all.');

        self::assertSame([['total_amount' => '2.0000']], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT total_amount FROM sales_order WHERE id = ?::uuid', [$order]),
            'The order total did not fall when a line was archived, so recomputeTotal() is not counting live lines only.');

        // A repeat is refused by the line guard -- which is why §4.2's Idem cell is `no` and needs no key.
        $this->send('DELETE', '/sales-orders/'.$order.'/lines/'.$line);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('sales_order_line.is_archived', $this->json()['rule'] ?? null);
    }

    /**
     * **V2-R-12b on the sales side: restoring a line whose retry key a LIVE line now holds is refused in
     * domain terms, never with a 500 — and the four steps are DRIVEN rather than asserted.**
     *
     * The hazard is created by the retry key's own partiality and it is reachable through nothing but
     * shipped operations:
     *
     *   1. `CreateSalesOrderLine` with key **K** → line L, live, holding K;
     *   2. `DeleteSalesOrderLine` L → archived. `sales_order_line_tenant_idem_live` is partial on
     *      `deleted_at IS NULL`, so it no longer constrains L;
     *   3. `CreateSalesOrderLine` with key **K again** → `lineCreatedByKeyInside()` looks only at live
     *      rows, finds none, and inserts **L2, also holding K**. This step is a success, not a replay,
     *      and that is the whole trap;
     *   4. `RestoreSalesOrderLine` L → two live rows on one key → **`23505`**.
     *
     * Untranslated, step 4 is an HTTP 500 on an operation whose caller sent no key at all and could not
     * possibly have understood the answer. `translatingConstraintFailures()` is handed a **different**
     * refusal for the restore path than for the create path, precisely because
     * `idempotency-key-conflict` would name a mechanism this caller never used.
     *
     * ⚠ **The identical hazard had a SECOND trigger this test cannot reach: `RestoreSalesOrder`**, whose
     * `sales_order_cascade_deletion` trigger set every line live in one statement the writer did not
     * issue. `Version20260921000200` changed that trigger to revive only the lines archived WITH the order,
     * so the same-order case is now proved impossible at the database by
     * `DeletionPolicyTest::restoringAnOrderWhoseReplacementLineHoldsTheArchivedLinesNumberAndKeySucceeds`,
     * on both parents. A key reused on ANOTHER order's line can still collide on the order path, because
     * the key is tenant-wide; that is `RestoreSalesOrder`'s to translate when it is built.
     */
    #[Test]
    public function restoringALineWhoseRetryKeyALiveLineNowHoldsIsRefusedAndNeverA500(): void
    {
        $draft = $this->draftWithASecondItem();
        $key = Uuid::v4()->toRfc4122();

        // 1. the line that holds the key
        $this->createLine($draft['order'], self::body($draft['item']), $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $first = $this->json()['id'];

        // 2. archived, so the partial unique stops constraining it
        $this->send('DELETE', '/sales-orders/'.$draft['order'].'/lines/'.$first);
        self::assertSame(204, $this->httpStatus());

        // 3. the same key again — and this SUCCEEDS, which is the trap
        $this->createLine($draft['order'], self::body($this->seedItem(self::TENANT_A, 'SKU-R12B')), $key);
        self::assertSame(201, $this->httpStatus(),
            'Step 3 was not a success, so the rest of this test proves nothing: the hazard needs TWO rows holding K.');
        self::assertNotSame($first, $this->json()['id'], 'Step 3 replayed the archived line instead of inserting a second one.');

        // 4. the restore that would collide
        $this->send('POST', '/sales-orders/'.$draft['order'].'/lines/'.$first.'/restoration');

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        // ⚠ **`line_number`, not the retry key — and the difference was found by RUNNING this test.**
        // The hazard was written up as a key collision. It is BOTH, and `sales_order_line_unique` fires
        // first, because `line_number` is minted as `MAX + 1` over **live** lines: step 3's line took the
        // archived line's number as well as its key. The rule name is asserted rather than just the
        // status, so a future change that silently answers with the other one shows up here.
        self::assertSame('sales_order_line.line_number_is_taken_by_a_live_line', $this->json()['rule'] ?? null,
            'V2-R-12b: the unique violation must be translated BY NAME to a domain refusal. A 500 here is the defect '
            .'this test exists for -- and so is the shipped create-path message "another line was added at the same '
            .'moment, try again", which on a restore is advice into a wall.');

        // And the archived line really is still archived: the refusal rolled the restore back.
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM sales_order_line WHERE id = ?::uuid AND deleted_at IS NOT NULL', [$first]));
    }

    /**
     * **The same hazard reaching the RETRY KEY index instead of the line-number one — so the second
     * translation is not dead code.**
     *
     * The test above cannot distinguish them: there, one line is archived and its replacement takes both
     * its number and its key. Here a second live line is kept in between, so `MAX + 1` gives the
     * replacement a **fresh number** while it still takes the **archived line's key** — and the restore
     * therefore collides on `sales_order_line_tenant_idem_live` alone.
     *
     * Without this case the `LINE_IDEMPOTENCY_INDEX` branch of `restoreLine()`'s collision map would
     * never execute, and a translation nothing reaches is a translation nobody can trust.
     */
    #[Test]
    public function restoringALineCollidesOnTheRetryKeyAloneWhenItsNumberIsFree(): void
    {
        $draft = $this->draftWithASecondItem();
        $key = Uuid::v4()->toRfc4122();

        // L1, number 1, key K.
        $this->createLine($draft['order'], self::body($draft['item']), $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $first = $this->json()['id'];

        // L2, number 2, a different key -- this is what keeps number 1 from being re-minted below.
        $this->createLine($draft['order'], self::body($this->seedItem(self::TENANT_A, 'SKU-KEY-B')), Uuid::v4()->toRfc4122());
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->send('DELETE', '/sales-orders/'.$draft['order'].'/lines/'.$first);
        self::assertSame(204, $this->httpStatus());

        // L3 takes K again -- and number 3, because MAX over live lines is 2.
        $this->createLine($draft['order'], self::body($this->seedItem(self::TENANT_A, 'SKU-KEY-C')), $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(3, $this->json()['line_number'],
            'The replacement line did not get a fresh number, so this case collapses into the previous test and '
            .'proves nothing about the retry-key branch.');

        $this->send('POST', '/sales-orders/'.$draft['order'].'/lines/'.$first.'/restoration');

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('sales_order_line.retry_key_is_held_by_a_live_line', $this->json()['rule'] ?? null,
            'The retry-key branch of restoreLine()\'s collision map was not reached, so it is untested code.');
    }
}

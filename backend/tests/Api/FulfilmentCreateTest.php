<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use App\Modules\UserManagement\Domain\DefaultRoles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`CreateFulfilment` and `GetFulfilment` — sales step 5, commit 4.** Stock
 * actually moves. Owner rulings R1 (model A renewed: overselling is reachable and
 * refused at the pick, in domain terms, never negative stock, never a 500), R2
 * (only live, non-quarantine locations are pickable), R5 (OI-2's remainder,
 * applied as ruled and driven through the API), R6 (`shipped_by`, NOT NULL,
 * exposed) and R9 (`GetFulfilment` in this step).
 *
 * The races — two fulfilments of one order, two orders on one bin, and the W-5
 * two-row cross-flow against a receipt — are in `PurchaseOrderRowLockTest`,
 * beside the harness they share.
 */
final class FulfilmentCreateTest extends PurchaseOrderTestCase
{
    /**
     * The operations shipped under `/fulfilments` — `GoodsReceiptCreateTest::PINNED_GOODS_RECEIPT_OPERATIONS`'s
     * twin. `CreateFulfilment` hangs off the order (N-8) and is pinned in `SalesOrderCreateTest`; `GetFulfilment` has
     * a path of its own, so it is pinned here and joined into `AuthOperationContractTest`'s whole-registry union.
     * `ListFulfilments` is declared in §4.2 and stays unbuilt until step 6 (owner ruling R9).
     *
     * @var array<string, array{method: string, path: string, idem: bool}>
     */
    public const PINNED_FULFILMENT_OPERATIONS = [
        'GetFulfilment' => ['method' => 'GET', 'path' => '/fulfilments/{id}', 'idem' => false],
    ];

    /** The registry's `/fulfilments` operations are exactly the pin, method and path included. */
    #[Test]
    public function theFulfilmentPathsDeclareExactlyThePinnedOperations(): void
    {
        $declared = [];
        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            if (str_starts_with((string) $operation->getUriTemplate(), '/fulfilments')) {
                $declared[$name] = ['method' => $operation->getMethod(), 'path' => $operation->getUriTemplate()];
            }
        }

        self::assertSame(
            array_map(static fn (array $pin): array => ['method' => $pin['method'], 'path' => $pin['path']], self::PINNED_FULFILMENT_OPERATIONS),
            $declared,
        );
    }

    /**
     * The shape and the effects of one partial shipment: 201 with `Location` naming `GetFulfilment`, the
     * body API-23a derives, `quantity_fulfilled` raised, the order `partially_fulfilled`, the stock lowered by
     * one negative movement, and `shipped_by` the caller (R6) — the authenticated user, never a body field.
     */
    #[Test]
    public function aWarehouseOperatorShipsPartOfAConfirmedOrder(): void
    {
        $f = $this->confirmedOrder('SO-SHIP', ['10']);
        $bin = $this->stockedBin($f['warehouse'], 'A-01', $f['items'][0], '8');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR));

        $this->fulfil($f['order'], [['sales_order_line_id' => $f['lines'][0], 'quantity' => '3', 'storage_location_id' => $bin]]);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertSame('/api/v1/fulfilments/'.$body['id'], $this->client()->getResponse()->headers->get('Location'));
        self::assertMatchesRegularExpression('/^FUL-[0-9]{6}$/', $body['fulfilment_number']);
        self::assertSame(['id' => $f['order'], 'order_number' => 'SO-SHIP'], $body['sales_order']);
        self::assertSame($this->actorId, $body['shipped_by']['id']);
        self::assertSame([[
            'id' => $body['lines'][0]['id'],
            'sales_order_line' => ['id' => $f['lines'][0], 'line_number' => 1],
            'inventory_item' => ['id' => $f['items'][0], 'sku' => 'SKU-SO-SHIP-1', 'name' => 'SKU-SO-SHIP-1'],
            'quantity' => '3.000000',
            'storage_location' => ['id' => $bin, 'code' => 'A-01'],
        ]], $body['lines']);
        self::assertSame(['id', 'fulfilment_number', 'shipped_at', 'sales_order', 'lines', 'shipped_by'], array_keys($body));

        self::assertSame(SalesOrder::STATUS_PARTIALLY_FULFILLED, $this->orderStatus($f['order']));
        self::assertSame('3.000000', $this->quantityFulfilled($f['lines'][0]));
        self::assertSame('5.000000', $this->onHand($bin));
        self::assertSame([['quantity' => '-3.000000', 'reason' => 'fulfilment', 'source_type' => 'fulfilment', 'source_id' => $body['id']]],
            $this->fetchAsOwnerForTenant(self::TENANT_A,
                'SELECT quantity::text AS quantity, reason, source_type, source_id::text AS source_id FROM stock_movement WHERE quantity < 0'));
        self::assertSame($this->actorId, $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT shipped_by_user_id::text AS u FROM fulfilment')[0]['u']);

        // GetFulfilment — the Location answers, with the same body.
        $this->send('GET', '/fulfilments/'.$body['id']);
        self::assertSame(200, $this->httpStatus());
        self::assertSame($body, $this->json());
    }

    /**
     * Shipping every remaining unit fulfils the order, and **B1N-12's re-read works**: the pick list shows the
     * resulting status (`fulfilled`) and nothing outstanding, so the actor who shipped sees what their shipment
     * did. Two bins for one line, and a second line, in one request.
     */
    #[Test]
    public function shippingEverythingFulfilsTheOrderAndThePickListShowsIt(): void
    {
        $f = $this->confirmedOrder('SO-ALL', ['5', '2']);
        $a = $this->stockedBin($f['warehouse'], 'A-01', $f['items'][0], '3');
        $b = $this->stockedBin($f['warehouse'], 'A-02', $f['items'][0], '3');
        $c = $this->stockedBin($f['warehouse'], 'A-03', $f['items'][1], '2');

        $this->fulfil($f['order'], [
            ['sales_order_line_id' => $f['lines'][0], 'quantity' => '3', 'storage_location_id' => $a],
            ['sales_order_line_id' => $f['lines'][0], 'quantity' => '2', 'storage_location_id' => $b],
            ['sales_order_line_id' => $f['lines'][1], 'quantity' => '2', 'storage_location_id' => $c],
        ]);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(SalesOrder::STATUS_FULFILLED, $this->orderStatus($f['order']));
        self::assertSame(['0.000000', '1.000000', '0.000000'], [$this->onHand($a), $this->onHand($b), $this->onHand($c)]);

        $this->send('GET', '/sales-orders/'.$f['order'].'/pick-list');
        self::assertSame(200, $this->httpStatus());
        self::assertSame(SalesOrder::STATUS_FULFILLED, $this->json()['status']);
        self::assertSame(['0.000000', '0.000000'], array_column($this->json()['lines'], 'quantity_outstanding'));
    }

    /**
     * **The replay reads BEFORE the status guard** (step 3). The retry of the fulfilment that COMPLETED the
     * order — now `fulfilled`, from which `recordFulfilment()` refuses — is answered with that fulfilment, and
     * nothing is posted twice. **Mutation M5** (replay read moved after the status guard) turns this 409.
     */
    #[Test]
    public function theRetryOfTheFulfilmentThatCompletedTheOrderIsAReplay(): void
    {
        $f = $this->confirmedOrder('SO-RPL', ['4']);
        $bin = $this->stockedBin($f['warehouse'], 'A-01', $f['items'][0], '10');
        $key = Uuid::v4()->toRfc4122();
        $lines = [['sales_order_line_id' => $f['lines'][0], 'quantity' => '4', 'storage_location_id' => $bin]];

        $this->fulfil($f['order'], $lines, $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $first = $this->json();
        self::assertSame(SalesOrder::STATUS_FULFILLED, $this->orderStatus($f['order']));

        $this->fulfil($f['order'], $lines, $key);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($first, $this->json());
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM fulfilment'));
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM stock_movement WHERE quantity < 0'));
        self::assertSame('6.000000', $this->onHand($bin));
    }

    /** The same key with a different body is a conflict, and so is the same key sent at another order. */
    #[Test]
    public function aKeyReusedForADifferentRequestIsAConflict(): void
    {
        $f = $this->confirmedOrder('SO-K1', ['4']);
        $g = $this->confirmedOrder('SO-K2', ['4'], $f['warehouse']);
        $bin = $this->stockedBin($f['warehouse'], 'A-01', $f['items'][0], '10');
        $this->stock($g['items'][0], $bin, '10');
        $key = Uuid::v4()->toRfc4122();

        $this->fulfil($f['order'], [['sales_order_line_id' => $f['lines'][0], 'quantity' => '1', 'storage_location_id' => $bin]], $key);
        self::assertSame(201, $this->httpStatus());

        $this->fulfil($f['order'], [['sales_order_line_id' => $f['lines'][0], 'quantity' => '2', 'storage_location_id' => $bin]], $key);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('urn:erp:problem:idempotency-key-conflict', $this->json()['type']);

        $this->fulfil($g['order'], [['sales_order_line_id' => $g['lines'][0], 'quantity' => '1', 'storage_location_id' => $bin]], $key);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('urn:erp:problem:idempotency-key-conflict', $this->json()['type']);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM fulfilment'));
    }

    /** Over-fulfilment is refused at the order line, by its line number and in QR-2's rendering; nothing moves. */
    #[Test]
    public function shippingMoreThanWasOrderedIsRefusedAndNothingMoves(): void
    {
        $f = $this->confirmedOrder('SO-OVER', ['4']);
        $a = $this->stockedBin($f['warehouse'], 'A-01', $f['items'][0], '10');
        $b = $this->stockedBin($f['warehouse'], 'A-02', $f['items'][0], '10');

        $this->fulfil($f['order'], [
            ['sales_order_line_id' => $f['lines'][0], 'quantity' => '3', 'storage_location_id' => $a],
            ['sales_order_line_id' => $f['lines'][0], 'quantity' => '1.5', 'storage_location_id' => $b],
        ]);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('sales_order_line.is_not_over_fulfilled', $this->json()['rule']);
        self::assertSame('Order line 1 would reach 4.5 shipped of 4 ordered. Shipping more than was ordered is not accepted.',
            $this->json()['detail']);
        $this->assertNothingMoved($f, [$a => '10.000000', $b => '10.000000']);
    }

    /**
     * **R1: the oversell is refused at the pick, in domain terms — 409, never a raw 23514, never a 500, never
     * negative stock.** The message names the item's sku, the bin's code, the line and what the bin held.
     */
    #[Test]
    public function aBinThatHoldsTooLittleIsRefusedInDomainTermsAndNothingMoves(): void
    {
        $f = $this->confirmedOrder('SO-SHORT', ['10']);
        $bin = $this->stockedBin($f['warehouse'], 'A-01', $f['items'][0], '2.5');

        $this->fulfil($f['order'], [['sales_order_line_id' => $f['lines'][0], 'quantity' => '4', 'storage_location_id' => $bin]]);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('stock_level.on_hand_covers_the_depletion', $this->json()['rule']);
        self::assertSame('Order line 1 asks for 4 of SKU-SO-SHORT-1 from location A-01, which held 2.5 when this was checked. Nothing was shipped.',
            $this->json()['detail']);
        $this->assertNothingMoved($f, [$bin => '2.500000']);
    }

    /**
     * Two order lines for one item into one bin are one decrement: the refusal names BOTH line numbers (RED
     * R-4(c)), and a bin that never held the item reads 0 (R-4(b)).
     */
    #[Test]
    public function twoLinesOfOneItemFromOneBinAreRefusedTogetherNamingBoth(): void
    {
        $f = $this->confirmedOrder('SO-TWO', ['3', '3'], sameItem: true);
        $bin = $this->seedBin($f['warehouse'], 'A-01');

        $this->fulfil($f['order'], [
            ['sales_order_line_id' => $f['lines'][0], 'quantity' => '2', 'storage_location_id' => $bin],
            ['sales_order_line_id' => $f['lines'][1], 'quantity' => '2', 'storage_location_id' => $bin],
        ]);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('Order lines 1, 2 ask for 4 of SKU-SO-TWO-1 from location A-01, which held 0 when this was checked. Nothing was shipped.',
            $this->json()['detail']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function locationsThatCannotBePicked(): iterable
    {
        yield 'a quarantine location (R2)' => ['quarantine', 'storage_location.is_pickable'];
        yield 'an archived location (R2)' => ['archived', 'storage_location.is_pickable'];
        yield 'a location of another warehouse' => ['elsewhere', 'storage_location.belongs_to_fulfilling_warehouse'];
    }

    /** R2 at the write: pointed at the line's `storage_location_id`, and nothing moves. */
    #[Test]
    #[DataProvider('locationsThatCannotBePicked')]
    public function aLocationThatCannotBePickedIsRefusedAtTheLine(string $case, string $rule): void
    {
        $f = $this->confirmedOrder('SO-LOC', ['5']);
        $warehouse = 'elsewhere' === $case ? $this->seedWarehouse(self::TENANT_A, 'Elsewhere') : $f['warehouse'];
        $bin = $this->seedBin($warehouse, 'X-01', 'quarantine' === $case ? 'quarantine' : 'bin');
        $this->stock($f['items'][0], $bin, '9');
        if ('archived' === $case) {
            $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE storage_location SET deleted_at = now() WHERE id = ?::uuid', [$bin]);
        }

        $this->fulfil($f['order'], [['sales_order_line_id' => $f['lines'][0], 'quantity' => '1', 'storage_location_id' => $bin]]);

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([['pointer' => '/lines/0/storage_location_id', 'code' => $rule]],
            array_map(static fn (array $e): array => ['pointer' => $e['pointer'], 'code' => $e['code']], $this->json()['errors']));
        $this->assertNothingMoved($f, [$bin => '9.000000']);
    }

    #[Test]
    public function anUnknownBinIsAnUnknownReference(): void
    {
        $f = $this->confirmedOrder('SO-UNK', ['5']);

        $this->fulfil($f['order'], [['sales_order_line_id' => $f['lines'][0], 'quantity' => '1', 'storage_location_id' => Uuid::v4()->toRfc4122()]]);

        self::assertSame(422, $this->httpStatus());
        self::assertSame('unknown_reference', $this->json()['errors'][0]['code']);
    }

    /** @return iterable<string, array{string}> */
    public static function ordersThatCannotBeFulfilled(): iterable
    {
        yield 'a draft' => [SalesOrder::STATUS_DRAFT];
        yield 'a cancelled order' => [SalesOrder::STATUS_CANCELLED];
        yield 'a fulfilled order' => [SalesOrder::STATUS_FULFILLED];
    }

    /** §8.3: only `confirmed` and `partially_fulfilled` may be fulfilled; the guard refuses before anything moves. */
    #[Test]
    #[DataProvider('ordersThatCannotBeFulfilled')]
    public function anOrderInTheWrongStatusIsRefusedByTheGuard(string $status): void
    {
        $f = $this->confirmedOrder('SO-ST', ['5']);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE sales_order SET status = ? WHERE id = ?::uuid', [$status, $f['order']]);
        $bin = $this->stockedBin($f['warehouse'], 'A-01', $f['items'][0], '9');

        $this->fulfil($f['order'], [['sales_order_line_id' => $f['lines'][0], 'quantity' => '1', 'storage_location_id' => $bin]]);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('urn:erp:problem:business-rule-violated', $this->json()['type']);
        $this->assertNothingMoved($f, [$bin => '9.000000']);
    }

    /** §5.3 clause 2: a line that is not a live line of THIS order is not-found — an archived one, or another order's. */
    #[Test]
    public function aLineThatIsNotALiveLineOfThisOrderIsNotFound(): void
    {
        $f = $this->confirmedOrder('SO-NL', ['5']);
        $other = $this->confirmedOrder('SO-NL2', ['5'], $f['warehouse']);
        $archived = $this->seedSalesOrderLine(self::TENANT_A, $f['order'], $f['items'][0], lineNumber: 2);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE sales_order_line SET deleted_at = now() WHERE id = ?::uuid', [$archived]);
        $bin = $this->stockedBin($f['warehouse'], 'A-01', $f['items'][0], '9');

        foreach ([$archived, $other['lines'][0]] as $line) {
            $this->fulfil($f['order'], [['sales_order_line_id' => $line, 'quantity' => '1', 'storage_location_id' => $bin]]);
            self::assertSame(404, $this->httpStatus());
        }
        $this->assertNothingMoved($f, [$bin => '9.000000']);
    }

    /** An archived warehouse is a closed site: refused, `warehouse.is_live`. */
    #[Test]
    public function anArchivedWarehouseIsRefused(): void
    {
        $f = $this->confirmedOrder('SO-WHX', ['5']);
        $bin = $this->stockedBin($f['warehouse'], 'A-01', $f['items'][0], '9');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE warehouse SET deleted_at = now() WHERE id = ?::uuid', [$f['warehouse']]);

        $this->fulfil($f['order'], [['sales_order_line_id' => $f['lines'][0], 'quantity' => '1', 'storage_location_id' => $bin]]);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('warehouse.is_live', $this->json()['rule']);
    }

    /** @return iterable<string, array{string}> */
    public static function defaultRolesThatMayNotShip(): iterable
    {
        yield 'sales_rep (SoD-4)' => [DefaultRoles::SALES_REP];
        yield 'admin (SoD-4)' => [DefaultRoles::ADMIN];
    }

    /** SoD-4: `fulfilment.create` is held by `owner` and `warehouse_operator` only. */
    #[Test]
    #[DataProvider('defaultRolesThatMayNotShip')]
    public function aRoleWithoutFulfilmentCreateIsRefused(string $role): void
    {
        $f = $this->confirmedOrder('SO-SOD', ['5']);
        $bin = $this->stockedBin($f['warehouse'], 'A-01', $f['items'][0], '9');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor($role));

        $this->fulfil($f['order'], [['sales_order_line_id' => $f['lines'][0], 'quantity' => '1', 'storage_location_id' => $bin]]);

        self::assertSame(403, $this->httpStatus());
        self::assertSame('fulfilment.create', $this->json()['required_permission']);
    }

    /**
     * SoD-4 and B-4a: a fulfilment issues no invoice and writes no `audit_log` row — there is no `fulfilment.*`
     * member in the catalogue, and `shipped_by` is its attribution.
     */
    #[Test]
    public function aFulfilmentWritesNoInvoiceAndNoAuditRow(): void
    {
        $f = $this->confirmedOrder('SO-NOINV', ['5']);
        $bin = $this->stockedBin($f['warehouse'], 'A-01', $f['items'][0], '9');
        $audit = $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM audit_log');

        $this->fulfil($f['order'], [['sales_order_line_id' => $f['lines'][0], 'quantity' => '5', 'storage_location_id' => $bin]]);

        self::assertSame(201, $this->httpStatus());
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM invoice'));
        self::assertSame($audit, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM audit_log'),
            'CreateFulfilment wrote an audit_log row, and the catalogue holds no fulfilment.* event (B-4a owns it).');
    }

    /**
     * **Owner ruling R5 — OI-2's remainder, applied as ruled and made non-vacuous THROUGH THE API.** An order
     * is raised, confirmed, partly shipped and then cancelled, every step an operation: the cancelled line owes
     * `0.000000` (derived, never written) with `quantity_fulfilled` preserved, and a live partially fulfilled
     * order in the same test owes its remainder — so neither an always-zero derivation nor an empty read passes.
     */
    #[Test]
    public function cancellingAPartlyShippedOrderCancelsTheRemainderAndKeepsWhatShipped(): void
    {
        $cancelled = $this->raisedAndConfirmed('SO-OI2-A');
        $live = $this->raisedAndConfirmed('SO-OI2-B');
        foreach ([$cancelled, $live] as $o) {
            $bin = $this->stockedBin($o['warehouse'], 'A-01', $o['item'], '10');
            $this->fulfil($o['order'], [['sales_order_line_id' => $o['line'], 'quantity' => '2', 'storage_location_id' => $bin]]);
            self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        }

        $this->send('POST', '/sales-orders/'.$cancelled['order'].'/cancellation', ['reason' => 'Customer took what shipped.']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(SalesOrder::STATUS_CANCELLED, $this->json()['status']);

        $this->send('GET', '/sales-orders/'.$cancelled['order'].'/lines');
        self::assertSame([['5.000000', '2.000000', '0.000000']], array_map(
            static fn (array $l): array => [$l['quantity'], $l['quantity_fulfilled'], $l['quantity_outstanding']], $this->json()['items']));

        $this->send('GET', '/sales-orders/'.$live['order'].'/lines');
        self::assertSame([['5.000000', '2.000000', '3.000000']], array_map(
            static fn (array $l): array => [$l['quantity'], $l['quantity_fulfilled'], $l['quantity_outstanding']], $this->json()['items']));
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM fulfilment WHERE sales_order_id = ?::uuid', [$cancelled['order']]),
            'The shipped fulfilment of the cancelled order must survive: it stays billable under D-A.');
    }

    #[Test]
    public function aFulfilmentOfAnotherWorkspaceIsNotFound(): void
    {
        $f = $this->confirmedOrder('SO-GF', ['5']);
        $bin = $this->stockedBin($f['warehouse'], 'A-01', $f['items'][0], '9');
        $this->fulfil($f['order'], [['sales_order_line_id' => $f['lines'][0], 'quantity' => '1', 'storage_location_id' => $bin]]);
        $id = $this->json()['id'];

        $this->actAs(self::TENANT_B);
        $this->send('GET', '/fulfilments/'.$id);
        self::assertSame(404, $this->httpStatus());
        $this->send('GET', '/fulfilments/'.Uuid::v4()->toRfc4122());
        self::assertSame(404, $this->httpStatus());
    }

    // -----------------------------------------------------------------------

    /** @param list<array<string, string>> $lines */
    private function fulfil(string $orderId, array $lines, ?string $key = null): void
    {
        $this->send('POST', '/sales-orders/'.$orderId.'/fulfilments', ['lines' => $lines],
            ['Idempotency-Key' => $key ?? Uuid::v4()->toRfc4122()]);
    }

    /**
     * A confirmed order, seeded as the owner, with one line per quantity.
     *
     * @param list<string> $quantities
     *
     * @return array{order: string, warehouse: string, lines: list<string>, items: list<string>}
     */
    private function confirmedOrder(string $number, array $quantities, ?string $warehouseId = null, bool $sameItem = false): array
    {
        $warehouse = $warehouseId ?? $this->seedWarehouse(self::TENANT_A, 'WH '.$number);
        $order = $this->seedSalesOrder(self::TENANT_A, $number, null, $warehouse, SalesOrder::STATUS_CONFIRMED, '2026-09-20T10:00:00Z');
        $lines = [];
        $items = [];
        foreach ($quantities as $i => $quantity) {
            $item = $sameItem && [] !== $items ? $items[0] : $this->seedItem(self::TENANT_A, 'SKU-'.$number.'-'.($i + 1));
            $items[] = $item;
            $lines[] = $this->seedSalesOrderLine(self::TENANT_A, $order, $item, lineNumber: $i + 1, quantity: $quantity);
        }

        return ['order' => $order, 'warehouse' => $warehouse, 'lines' => $lines, 'items' => $items];
    }

    /**
     * An order raised and confirmed THROUGH THE API, so every state the OI-2 test reads was written by an
     * operation (R5).
     *
     * @return array{order: string, warehouse: string, line: string, item: string}
     */
    private function raisedAndConfirmed(string $tag): array
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Customer '.$tag);
        $address = $this->seedCustomerAddress(self::TENANT_A, $customer);
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH '.$tag);
        $item = $this->seedItem(self::TENANT_A, 'SKU-'.$tag);

        $this->send('POST', '/sales-orders', [
            'customer_id' => $customer, 'warehouse_id' => $warehouse, 'currency_code' => 'EUR', 'ship_to_address_id' => $address,
            'lines' => [['inventory_item_id' => $item, 'quantity' => '5', 'unit_price' => '2']],
        ], ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $order = $this->json()['id'];
        $this->send('POST', '/sales-orders/'.$order.'/confirmation');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->send('GET', '/sales-orders/'.$order.'/lines');

        return ['order' => $order, 'warehouse' => $warehouse, 'line' => $this->json()['items'][0]['id'], 'item' => $item];
    }

    private function seedBin(string $warehouseId, string $code, string $kind = 'bin'): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'INSERT INTO storage_location (id, tenant_id, warehouse_id, code, kind) VALUES (?::uuid, ?::uuid, ?::uuid, ?, ?)',
            [$id, self::TENANT_A, $warehouseId, $code, $kind]);

        return $id;
    }

    private function stock(string $itemId, string $binId, string $qty): void
    {
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric)',
            [Uuid::v4()->toRfc4122(), self::TENANT_A, $itemId, $binId, $qty]);
    }

    private function stockedBin(string $warehouseId, string $code, string $itemId, string $qty): string
    {
        $bin = $this->seedBin($warehouseId, $code);
        $this->stock($itemId, $bin, $qty);

        return $bin;
    }

    private function orderStatus(string $orderId): string
    {
        return (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT status FROM sales_order WHERE id = ?::uuid', [$orderId])[0]['status'];
    }

    private function quantityFulfilled(string $lineId): string
    {
        return (string) $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT quantity_fulfilled::text AS q FROM sales_order_line WHERE id = ?::uuid', [$lineId])[0]['q'];
    }

    private function onHand(string $binId): string
    {
        return (string) $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT qty_on_hand::text AS q FROM stock_level WHERE storage_location_id = ?::uuid', [$binId])[0]['q'];
    }

    /**
     * @param array{order: string, lines: list<string>} $f
     * @param array<string, string>                     $levels bin => the on-hand it must still hold
     */
    private function assertNothingMoved(array $f, array $levels): void
    {
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM fulfilment WHERE sales_order_id = ?::uuid', [$f['order']]));
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM stock_movement WHERE quantity < 0'));
        foreach ($f['lines'] as $line) {
            self::assertSame('0.000000', $this->quantityFulfilled($line));
        }
        foreach ($levels as $bin => $onHand) {
            self::assertSame($onHand, $this->onHand($bin));
        }
    }
}

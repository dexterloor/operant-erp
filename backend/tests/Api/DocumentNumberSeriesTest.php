<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use App\Platform\Http\Problem\ProblemType;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **CD-7 — the four document-number series are six digits wide, and the millionth is refused, not misordered.**
 * *(And the fifth, `INV-`, since `IssueInvoice` shipped in sales step 6 C3 — see its two methods below.)*
 *
 * Owner ruling CD7-1 (2026-09-28, `docs/session13/cd7-ruling.md`): `SO-`, `PO-`, `FUL-` and `GR-` take the invoice's
 * Q12 shape. Before it, the writers zero-padded to six digits with no cap, so a workspace's 1,000,000th sales order
 * was `SO-1000000`, which `sort=order_number` (a text sort) placed beside `SO-100000`. `IA.md` R-1a / D-f: the order
 * is lexical because the width is fixed — `[0-9]{6}` is the constraint and `[0-9]{6,}` the defect.
 *
 * Three mechanisms, each held here:
 *
 *  1. **The CHECK** (`<table>_number_shape`, `Version20260928000100`), proved as the schema owner because nothing the
 *     API can do reaches it once the refusal exists. Each case asserts the constraint **by name** — `23514` alone is
 *     also what the key-shape and status CHECKs answer — beside a positive control inside the same test.
 *  2. **The refusal** at `…999999`, in domain terms: 409 `business-rule-violated`, rule
 *     `<table>.number_series_is_exhausted`, **asserted by rule** (`…number_is_unique_per_workspace` is also a 409),
 *     with nothing written. The boundary is exclusive: `…999998` → the create mints `…999999`.
 *  3. **Its position, after the replay read** (`RED` F4): a retry of the 999,999th create with its own key and body
 *     returns that document, not the exhaustion refusal. A guard hoisted into an early pre-flight would break D-B at
 *     exactly the cap, and every other case here would stay green (mutation M7).
 *
 * The seven-digit number the CD-7 row asked a test to seed is the schema case below: under this fix such a number
 * cannot exist, which is the fix. The sort code did not change (a fixed width makes text order numeric order), so no
 * sort mutation is meaningful; the R-1 test at the end is an end-to-end pin of the property, not a CD-7 mutation
 * target (`RED` F6).
 */
final class DocumentNumberSeriesTest extends PurchaseOrderTestCase
{
    private const TABLES = [
        'sales_order' => 'SO',
        'purchase_order' => 'PO',
        'fulfilment' => 'FUL',
        'goods_receipt' => 'GR',
    ];

    // -----------------------------------------------------------------------
    // 1. The CHECK
    // -----------------------------------------------------------------------

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function numbersTheShapeMustRefuse(): iterable
    {
        foreach (self::TABLES as $table => $prefix) {
            $other = 'SO' === $prefix ? 'PO' : 'SO';
            yield $table.': seven digits (the CD-7 misorder)' => [$table, $prefix.'-1000000'];
            yield $table.': five digits' => [$table, $prefix.'-99999'];
            yield $table.': letters' => [$table, $prefix.'-ABCDEF'];
            yield $table.': another series\' prefix' => [$table, $other.'-000001'];
            yield $table.': a trailing character' => [$table, $prefix.'-000001x'];
        }
    }

    #[Test]
    #[DataProvider('numbersTheShapeMustRefuse')]
    public function aNumberOfAnyOtherShapeIsRefusedByTheDatabase(string $table, string $number): void
    {
        $refused = $this->refusalOf(fn () => $this->insertRow($table, $number));

        self::assertStringStartsWith('23514', $refused);
        self::assertStringContainsString($table.'_number_shape', $refused,
            'The row was refused, but not by the number shape CHECK this case is about.');

        // The positive control, inside the same test: the product's own shape lands in the same table, so the
        // refusal above is the shape's and not a fixture every write would fail.
        $this->insertRow($table, self::TABLES[$table].'-000001');
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM '.$table.' WHERE '.self::numberColumn($table).' = ?', [self::TABLES[$table].'-000001']));
    }

    // -----------------------------------------------------------------------
    // 2 + 3. The refusal at the cap, and its position after the replay read
    // -----------------------------------------------------------------------

    /** @return iterable<string, array{string}> */
    public static function series(): iterable
    {
        foreach (array_keys(self::TABLES) as $table) {
            yield $table => [$table];
        }
    }

    #[Test]
    #[DataProvider('series')]
    public function theLastNumberIsIssuedTheNextIsRefusedAndARetryOfTheLastStillGetsItsOwnDocument(string $table): void
    {
        $prefix = self::TABLES[$table];
        $create = $this->creatorFor($table);
        $this->insertRow($table, $prefix.'-999998');

        // Boundary: the cap is `> 999999`, not `>= 999999`.
        $key = Uuid::v4()->toRfc4122();
        $create($key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $last = $this->json();
        self::assertSame($prefix.'-999999', $last[self::numberField($table)]);
        $written = $this->footprint($table);

        // The millionth: refused in domain terms, by rule, and nothing written.
        $create(Uuid::v4()->toRfc4122());
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(ProblemType::BusinessRuleViolated->uri(), $this->json()['type']);
        self::assertSame($table.'.number_series_is_exhausted', $this->json()['rule'],
            'The millionth '.$table.' was refused, but not by the exhaustion rule.');
        self::assertSame($written, $this->footprint($table), 'A refused create left something behind.');

        // D-B at the cap: a retry of the 999,999th create, same key and same body, is its own document.
        $create($key);
        self::assertSame(201, $this->httpStatus(), 'A retry of the last create was refused -- is the exhaustion guard '
            .'ahead of the replay read? '.(string) $this->client()->getResponse()->getContent());
        self::assertSame($last['id'], $this->json()['id']);
        self::assertSame($written, $this->footprint($table));
    }

    /**
     * Q4 (numbers are never reissued) meets the cap: an ARCHIVED draft holding `…999999` still exhausts the series,
     * because the mint counts every row (`nextOrderNumber()`'s docblock). Sales and purchase orders only -- the
     * other two tables have no archive.
     */
    #[Test]
    public function anArchivedDraftHoldingTheLastNumberStillExhaustsTheSeries(): void
    {
        foreach (['sales_order', 'purchase_order'] as $table) {
            $id = $this->insertRow($table, self::TABLES[$table].'-999999');
            $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE '.$table.' SET deleted_at = now() WHERE id = ?::uuid', [$id]);

            $this->creatorFor($table)(Uuid::v4()->toRfc4122());
            self::assertSame(409, $this->httpStatus(), $table.': '.(string) $this->client()->getResponse()->getContent());
            self::assertSame($table.'.number_series_is_exhausted', $this->json()['rule']);
        }
    }

    /**
     * **The fifth series, `INV-` (Q12 + CD7-1) — shipped with `IssueInvoice` (sales step 6, C3).** Its CHECK is
     * `invoice_number_shape` (C2, proved in `InvoiceSchemaTest`); this is the refusal and its position.
     *
     * `IssueInvoice` has no replay — a repeat is refused, never replayed (§4.2 item 1) — so CD-7's *"a retry of the
     * last create gets its own document"* has no invoice analogue. What stands in its place is the ORDER of the two
     * refusals: the live-invoice pre-check (step 3) runs before the number (step 6), so a repeat on the shipment billed
     * as `INV-999999` is told it is already invoiced, **not** that the series is exhausted. A cap hoisted ahead of the
     * pre-check (mutation M7-INV) answers the wrong rule there.
     */
    #[Test]
    public function theLastInvoiceNumberIsIssuedTheNextIsRefusedAndARepeatIsStillToldItIsAlreadyInvoiced(): void
    {
        $ship = $this->creatorFor('fulfilment');
        $ship(Uuid::v4()->toRfc4122());
        $first = $this->json()['id'];
        $ship(Uuid::v4()->toRfc4122());
        $second = $this->json()['id'];
        $this->seedInvoice($this->insertRow('fulfilment', self::freshNumber('FUL')), 'INV-999998');

        // Boundary: the cap is `> 999999`, not `>= 999999`.
        $this->send('POST', '/fulfilments/'.$first.'/invoices', null, ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('INV-999999', $this->json()['invoice_number']);
        $written = $this->invoiceFootprint();

        // The millionth: refused in domain terms, by rule, and nothing written (no invoice, no trail row).
        $this->send('POST', '/fulfilments/'.$second.'/invoices', null, ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(ProblemType::BusinessRuleViolated->uri(), $this->json()['type']);
        self::assertSame('invoice.number_series_is_exhausted', $this->json()['rule'],
            'The millionth invoice was refused, but not by the exhaustion rule.');
        self::assertSame($written, $this->invoiceFootprint(), 'A refused issue left something behind.');

        // A repeat on the shipment that took INV-999999 meets the pre-check first.
        $this->send('POST', '/fulfilments/'.$first.'/invoices', null, ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('invoice.fulfilment_is_not_already_invoiced', $this->json()['rule'],
            'A repeat on the last-numbered shipment was not told it is already invoiced -- is the cap ahead of the pre-check?');
        self::assertSame($written, $this->invoiceFootprint());
    }

    /** Numbers are never reissued: a VOIDED invoice holding `INV-999999` still exhausts the series. */
    #[Test]
    public function aVoidedInvoiceHoldingTheLastNumberStillExhaustsTheInvoiceSeries(): void
    {
        $ship = $this->creatorFor('fulfilment');
        $ship(Uuid::v4()->toRfc4122());
        $fulfilment = $this->json()['id'];
        $held = $this->seedInvoice($this->insertRow('fulfilment', self::freshNumber('FUL')), 'INV-999999');
        $this->executeAsOwnerForTenant(self::TENANT_A, "UPDATE invoice SET status = 'void' WHERE id = ?::uuid", [$held]);

        $this->send('POST', '/fulfilments/'.$fulfilment.'/invoices', null, ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('invoice.number_series_is_exhausted', $this->json()['rule']);
    }

    /**
     * **IA R-1, end to end — an R-1 pin, not a CD-7 mutation target** (`RED` F6). Eleven orders created through the
     * API, crossing `…000009` → `…000010`, come back from `sort=order_number` in the order they were created.
     */
    #[Test]
    public function documentNumbersSortInTheOrderTheyWereCreated(): void
    {
        foreach (['sales_order' => '/sales-orders', 'purchase_order' => '/purchase-orders'] as $table => $path) {
            $create = $this->creatorFor($table);
            $created = [];
            for ($i = 0; $i < 11; ++$i) {
                $create(Uuid::v4()->toRfc4122());
                self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
                $created[] = $this->json()['order_number'];
            }
            self::assertSame(self::TABLES[$table].'-000011', end($created), 'Control: the series did not cross a digit.');

            $this->send('GET', $path.'?sort=order_number&limit=50');
            self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
            self::assertSame($created, array_column($this->json()['items'], 'order_number'));
        }
    }

    // -----------------------------------------------------------------------

    private static function numberColumn(string $table): string
    {
        return match ($table) {
            'fulfilment' => 'fulfilment_number',
            'goods_receipt' => 'receipt_number',
            default => 'order_number',
        };
    }

    private static function numberField(string $table): string
    {
        return self::numberColumn($table);
    }

    /** One row of `$table` carrying `$number`, written as the schema owner; parents are seeded fresh. */
    private function insertRow(string $table, string $number): string
    {
        switch ($table) {
            case 'sales_order':
                return $this->seedSalesOrder(self::TENANT_A, $number, $this->seedCustomer(self::TENANT_A, 'C '.Uuid::v4()),
                    $this->seedWarehouse(self::TENANT_A, 'WH '.Uuid::v4()));
            case 'purchase_order':
                return $this->seedOrder(self::TENANT_A, $number, $this->seedSupplier(self::TENANT_A, 'S '.Uuid::v4()),
                    $this->seedWarehouse(self::TENANT_A, 'WH '.Uuid::v4()));
            case 'fulfilment':
                $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH '.Uuid::v4());
                $order = $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'), null, $warehouse,
                    SalesOrder::STATUS_CONFIRMED, '2026-09-20T10:00:00Z');
                $id = Uuid::v4()->toRfc4122();
                $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                    INSERT INTO fulfilment
                        (id, tenant_id, sales_order_id, warehouse_id, fulfilment_number, shipped_by_user_id,
                         idempotency_key, request_digest)
                    VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, ?::uuid,
                            encode(sha256(gen_random_uuid()::text::bytea), 'hex'),
                            encode(sha256(gen_random_uuid()::text::bytea), 'hex'))
                    SQL, [$id, self::TENANT_A, $order, $warehouse, $number, (string) $this->actorId]);

                return $id;
            case 'goods_receipt':
                $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH '.Uuid::v4());
                $order = $this->seedOrder(self::TENANT_A, self::freshNumber('PO'), $this->seedSupplier(self::TENANT_A, 'S '.Uuid::v4()),
                    $warehouse, status: PurchaseOrder::STATUS_ORDERED);
                $id = Uuid::v4()->toRfc4122();
                $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                    INSERT INTO goods_receipt (id, tenant_id, purchase_order_id, warehouse_id, receipt_number, idempotency_key, request_digest)
                    VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?,
                            encode(sha256(gen_random_uuid()::text::bytea), 'hex'),
                            encode(sha256(gen_random_uuid()::text::bytea), 'hex'))
                    SQL, [$id, self::TENANT_A, $order, $warehouse, $number]);

                return $id;
        }

        throw new \LogicException('No row fixture for '.$table);
    }

    /**
     * A create through the API for `$table`, taking the Idempotency-Key; every call sends the same body, so a
     * repeated key is a true retry. Fulfilment and receipt ship or receive ONE unit of a line holding ten, so the
     * second and third calls are never refused for running out of anything but numbers.
     *
     * @return callable(string): void
     */
    private function creatorFor(string $table): callable
    {
        switch ($table) {
            case 'sales_order':
                $customer = $this->seedCustomer(self::TENANT_A, 'Customer '.Uuid::v4());
                $body = [
                    'customer_id' => $customer,
                    'warehouse_id' => $this->seedWarehouse(self::TENANT_A, 'WH '.Uuid::v4()),
                    'currency_code' => 'EUR',
                    'ship_to_address_id' => $this->seedCustomerAddress(self::TENANT_A, $customer),
                ];

                return fn (string $key) => $this->send('POST', '/sales-orders', $body, ['Idempotency-Key' => $key]);
            case 'purchase_order':
                $body = [
                    'supplier_id' => $this->seedSupplier(self::TENANT_A, 'Supplier '.Uuid::v4()),
                    'warehouse_id' => $this->seedWarehouse(self::TENANT_A, 'WH '.Uuid::v4()),
                ];

                return fn (string $key) => $this->send('POST', '/purchase-orders', $body, ['Idempotency-Key' => $key]);
            case 'fulfilment':
                $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH '.Uuid::v4());
                $order = $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'), null, $warehouse,
                    SalesOrder::STATUS_CONFIRMED, '2026-09-20T10:00:00Z');
                $item = $this->seedItem(self::TENANT_A, 'SKU-'.Uuid::v4());
                $line = $this->seedSalesOrderLine(self::TENANT_A, $order, $item, quantity: '10.000000');
                $bin = Uuid::v4()->toRfc4122();
                $this->insertAsOwnerForTenant(self::TENANT_A,
                    "INSERT INTO storage_location (id, tenant_id, warehouse_id, code, kind) VALUES (?::uuid, ?::uuid, ?::uuid, 'A-01', 'bin')",
                    [$bin, self::TENANT_A, $warehouse]);
                $this->insertAsOwnerForTenant(self::TENANT_A,
                    "INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, '10')",
                    [Uuid::v4()->toRfc4122(), self::TENANT_A, $item, $bin]);
                $body = ['lines' => [['sales_order_line_id' => $line, 'quantity' => '1', 'storage_location_id' => $bin]]];

                return fn (string $key) => $this->send('POST', '/sales-orders/'.$order.'/fulfilments', $body, ['Idempotency-Key' => $key]);
            case 'goods_receipt':
                $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH '.Uuid::v4());
                $order = $this->seedOrder(self::TENANT_A, self::freshNumber('PO'), $this->seedSupplier(self::TENANT_A, 'S '.Uuid::v4()),
                    $warehouse, status: PurchaseOrder::STATUS_ORDERED);
                $line = $this->seedLine(self::TENANT_A, $order, $this->seedItem(self::TENANT_A, 'SKU-'.Uuid::v4()), quantity: '10.000000');
                $bin = Uuid::v4()->toRfc4122();
                $this->insertAsOwnerForTenant(self::TENANT_A,
                    "INSERT INTO storage_location (id, tenant_id, warehouse_id, code) VALUES (?::uuid, ?::uuid, ?::uuid, 'A-01')",
                    [$bin, self::TENANT_A, $warehouse]);
                $body = ['lines' => [['purchase_order_line_id' => $line, 'quantity' => '1', 'storage_location_id' => $bin]]];

                return fn (string $key) => $this->send('POST', '/purchase-orders/'.$order.'/goods-receipts', $body, ['Idempotency-Key' => $key]);
        }

        throw new \LogicException('No creator for '.$table);
    }

    /**
     * Everything a create of `$table` writes, read as the owner: the header rows, and for the two stock-moving
     * creates the movements and the on-hand they leave (`RED` F4: a header count alone would miss a stock
     * movement that outlived a refusal).
     *
     * @return array<string, mixed>
     */
    private function footprint(string $table): array
    {
        $footprint = ['rows' => $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM '.$table)];
        if (\in_array($table, ['fulfilment', 'goods_receipt'], true)) {
            $footprint['movements'] = $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM stock_movement');
            $footprint['on_hand'] = $this->fetchAsOwnerForTenant(self::TENANT_A,
                'SELECT qty_on_hand::text AS q FROM stock_level ORDER BY id');
        }

        return $footprint;
    }

    /** An invoice of `$fulfilmentId` carrying `$number`, written as the schema owner from the shipment's own order. */
    private function seedInvoice(string $fulfilmentId, string $number): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO invoice (id, tenant_id, fulfilment_id, sales_order_id, customer_id, invoice_number, currency_code,
                                 total_amount, due_at, issued_by_user_id)
            SELECT ?::uuid, f.tenant_id, f.id, so.id, so.customer_id, ?, so.currency_code, 1, now() + interval '30 days', ?::uuid
              FROM fulfilment f JOIN sales_order so ON so.tenant_id = f.tenant_id AND so.id = f.sales_order_id
             WHERE f.id = ?::uuid
            SQL, [$id, $number, (string) $this->actorId, $fulfilmentId]);

        return $id;
    }

    /** @return array{invoices: int, issued_rows: int} */
    private function invoiceFootprint(): array
    {
        return [
            'invoices' => $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM invoice'),
            'issued_rows' => $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM audit_log WHERE event_type = 'invoice.issued'"),
        ];
    }

    private function refusalOf(callable $write): string
    {
        try {
            $write();
        } catch (DriverException $e) {
            return $e->getSQLState().' '.$e->getMessage();
        }

        self::fail('The write was accepted.');
    }
}

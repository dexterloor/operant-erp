<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Inventory\Application\Contract\DepleteStock;
use App\Modules\Inventory\Application\Contract\StockDepletion;
use App\Modules\Inventory\Application\Contract\StockDepletionLine;
use App\Modules\Inventory\Application\Contract\StockDepletionRefused;
use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use App\Tests\Support\SecurityModelDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`GetPickList` — V2-S-1b's money-free pick read, sales step 5 commit 3.**
 * Owner rulings R1 (on-hand per candidate bin), R2 (only live, non-quarantine
 * locations are pickable), R7 (the 116th §4.2 row; `SEC` names the columns in
 * PC-9's second exception) and R8 (V2-S-1b ships in part).
 *
 * Which clause each test holds:
 *  - clause 1 (its own resource, money-free) — `thePickReadCarriesNoMoneyColumn`,
 *    server-derived, with `GetSalesOrder` as the positive control;
 *  - clause 3 (`fulfilment.view`, not `sales_order.view`) —
 *    `aWarehouseOperatorWithoutSalesOrderViewReadsIt` and the E-1 test;
 *  - clause 6 (PC-9's second exception names these columns) —
 *    `pc9sSecondExceptionNamesExactlyTheColumnsThisReadProjects` (SKIPs without
 *    `docs/`, disclosed);
 *  - clause 2 is `ResponseShapeTest`'s; clause 4 is `AiToolDtoTest`'s pin
 *    (re-carried to `SEC`); clause 5's frontend half is
 *    `OwedAmendmentTripwireTest::noFrontendShipsWithoutThePickRouteCarry`.
 */
final class PickListReadTest extends PurchaseOrderTestCase
{
    /**
     * The whole body, for a confirmed order with one line: the order, the
     * snapshot, the customer and warehouse, the line with its quantities, and
     * **only** the pickable bins — the bin in another warehouse, the archived
     * bin, the quarantine location and the empty bin are all absent (R2, and
     * SEC's `qty_on_hand > 0`).
     */
    #[Test]
    public function aConfirmedOrderShowsWhatToPickAndOnlyWhereItCanBePicked(): void
    {
        $f = $this->confirmedOrder('SO-PICK');
        $good = $this->seedBin($f['warehouse'], 'A-01');
        $second = $this->seedBin($f['warehouse'], 'A-02');
        $quarantine = $this->seedBin($f['warehouse'], 'Q-01', 'quarantine');
        $archived = $this->seedBin($f['warehouse'], 'A-09');
        $empty = $this->seedBin($f['warehouse'], 'A-00');
        $elsewhere = $this->seedBin($this->seedWarehouse(self::TENANT_A, 'Elsewhere'), 'B-01');
        $this->stock($f['item'], $good, '4');
        $this->stock($f['item'], $second, '7.5');
        $this->stock($f['item'], $quarantine, '100');
        $this->stock($f['item'], $archived, '100');
        $this->stock($f['item'], $empty, '0');
        $this->stock($f['item'], $elsewhere, '100');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE storage_location SET deleted_at = now() WHERE id = ?::uuid', [$archived]);

        $this->actAs(self::TENANT_A, ['fulfilment.view']);
        $this->send('GET', '/sales-orders/'.$f['order'].'/pick-list');

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([
            'id' => $f['order'],
            'order_number' => 'SO-PICK',
            'status' => SalesOrder::STATUS_CONFIRMED,
            'customer' => ['id' => $f['customer'], 'name' => 'Customer SO-PICK'],
            'warehouse' => ['id' => $f['warehouse'], 'name' => 'WH SO-PICK'],
            'ship_to_line' => '',
            'ship_to_city' => '',
            'ship_to_postal_code' => '',
            'ship_to_country_code' => 'GB',
            'lines' => [[
                'sales_order_line' => ['id' => $f['line'], 'line_number' => 1],
                'inventory_item' => ['id' => $f['item'], 'sku' => 'SKU-SO-PICK', 'name' => 'SKU-SO-PICK'],
                'quantity' => '10.000000',
                'quantity_fulfilled' => '0.000000',
                'quantity_outstanding' => '10.000000',
                'candidate_bins' => [
                    ['storage_location' => ['id' => $good, 'code' => 'A-01'], 'qty_on_hand' => '4.000000'],
                    ['storage_location' => ['id' => $second, 'code' => 'A-02'], 'qty_on_hand' => '7.500000'],
                ],
            ]],
        ], $this->json());
    }

    /**
     * **The pick read and the depletion agree on what is pickable** (R2 is one rule, not two): the
     * quarantine location the read omits is the one `DepleteStock` refuses, and a bin the read lists is one
     * it accepts. `PickableStock::NEVER_PICKABLE_KIND` is what both read.
     */
    #[Test]
    public function aBinThePickReadOmitsIsABinTheDepletionRefuses(): void
    {
        $f = $this->confirmedOrder('SO-AGREE');
        $good = $this->seedBin($f['warehouse'], 'A-01');
        $quarantine = $this->seedBin($f['warehouse'], 'Q-01', 'quarantine');
        $this->stock($f['item'], $good, '5');
        $this->stock($f['item'], $quarantine, '5');

        $this->send('GET', '/sales-orders/'.$f['order'].'/pick-list');
        $listed = array_map(static fn (array $bin): string => $bin['storage_location']['id'], $this->json()['lines'][0]['candidate_bins']);
        self::assertSame([$good], $listed);

        // The HTTP request above replaced the non-HTTP identity; re-establish it for the direct call.
        $this->actAs(self::TENANT_A);
        $depleter = self::getContainer()->get('test.inventory.deplete_stock');
        \assert($depleter instanceof DepleteStock);
        try {
            $this->tenantContext()->run(self::TENANT_A, fn () => $depleter->deplete(new StockDepletion(
                Uuid::v4()->toRfc4122(), $f['warehouse'], new \DateTimeImmutable(),
                [new StockDepletionLine($f['item'], $quarantine, '1')],
            )));
            self::fail('The depletion accepted the location the pick read omits.');
        } catch (StockDepletionRefused $refused) {
            self::assertSame('storage_location.is_pickable', $refused->rule());
        }
    }

    /**
     * Clause 3: `warehouse_operator` holds `fulfilment.view` and **no** `sales_order.view` (PC-12 restated,
     * 2026-09-15) — the role this read exists for. Its own order read is refused; this one answers.
     */
    #[Test]
    public function aWarehouseOperatorWithoutSalesOrderViewReadsIt(): void
    {
        $f = $this->confirmedOrder('SO-WHO');

        $this->actAs(self::TENANT_A, ['fulfilment.view', 'fulfilment.create', 'stock_level.view']);
        $this->send('GET', '/sales-orders/'.$f['order']);
        self::assertSame(403, $this->httpStatus(), 'The positive control failed: the caller could read the order itself.');

        $this->send('GET', '/sales-orders/'.$f['order'].'/pick-list');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /**
     * **E-1: `fulfilment.view` is checked before the order is loaded.** A caller without it gets the same
     * 403 — status, full header map and body — for a real order, another workspace's and no order at all.
     */
    #[Test]
    public function aCallerWithoutFulfilmentViewGetsTheSame403ForEveryId(): void
    {
        $mine = $this->confirmedOrder('SO-E1')['order'];
        $theirs = $this->seedSalesOrder(self::TENANT_B, 'SO-THEIRS', status: SalesOrder::STATUS_CONFIRMED, orderedAt: '2026-09-20T10:00:00Z');

        $this->actAs(self::TENANT_A, ['sales_order.view']);

        $observed = [];
        foreach (['mine' => $mine, 'theirs' => $theirs, 'nowhere' => Uuid::v4()->toRfc4122(), 'not a uuid' => 'not-a-uuid'] as $label => $id) {
            $this->send('GET', '/sales-orders/'.$id.'/pick-list');
            $response = $this->client()->getResponse();
            $headers = $response->headers->all();
            ksort($headers);
            if (\array_key_exists('date', $headers)) {
                $headers['date'] = ['<present>'];
            }
            $observed[$label] = ['status' => $response->getStatusCode(), 'headers' => $headers, 'body' => (string) $response->getContent()];
        }

        self::assertSame(403, $observed['mine']['status']);
        self::assertSame('fulfilment.view', json_decode($observed['mine']['body'], true)['required_permission']);
        self::assertCount(1, array_unique(array_map(json_encode(...), $observed)),
            "The ids are distinguishable to a caller without fulfilment.view:\n".print_r($observed, true));
    }

    /** @return iterable<string, array{string}> */
    public static function ordersNeverReleasedToTheWarehouse(): iterable
    {
        yield 'a draft' => ['draft'];
        yield 'an archived order (always a draft)' => ['archived'];
        yield 'an order cancelled while still a draft' => ['cancelled-from-draft'];
        yield 'another workspace\'s confirmed order' => ['other-tenant'];
        yield 'no order at all' => ['nowhere'];
        yield 'not a uuid' => ['not-a-uuid'];
    }

    /**
     * **E-2: an order never released to the warehouse is not-found, byte-identical to an id in another
     * workspace** — never a 409 "not confirmed yet", which would show a role with no `sales_order.view`
     * which drafts exist (`SEC`'s visibility rule, PC-9's second exception).
     */
    #[Test]
    #[DataProvider('ordersNeverReleasedToTheWarehouse')]
    public function anOrderNeverReleasedToTheWarehouseIsNotFound(string $case): void
    {
        $id = match ($case) {
            'draft' => $this->seedSalesOrder(self::TENANT_A, 'SO-DRAFT'),
            'archived' => $this->archivedDraft(),
            'cancelled-from-draft' => $this->seedSalesOrder(self::TENANT_A, 'SO-CXD', status: SalesOrder::STATUS_CANCELLED),
            'other-tenant' => $this->seedSalesOrder(self::TENANT_B, 'SO-B', status: SalesOrder::STATUS_CONFIRMED, orderedAt: '2026-09-20T10:00:00Z'),
            'nowhere' => Uuid::v4()->toRfc4122(),
            'not-a-uuid' => 'not-a-uuid',
        };
        $this->actAs(self::TENANT_A, ['fulfilment.view']);

        $this->send('GET', '/sales-orders/'.Uuid::v4()->toRfc4122().'/pick-list');
        self::assertSame(404, $this->httpStatus());
        $reference = (string) $this->client()->getResponse()->getContent();

        $this->send('GET', '/sales-orders/'.$id.'/pick-list');
        self::assertSame(404, $this->httpStatus(), $case.' was not refused as not-found.');
        self::assertSame($reference, (string) $this->client()->getResponse()->getContent(), $case.'\'s 404 differs from a random id\'s.');
    }

    /**
     * An order cancelled AFTER confirmation stays readable — it was released — and owes nothing, so it
     * discloses no stock (V2-C-14(b) through the shared derivation; SEC's "only lines with a remainder").
     *
     * ⚠ The partially fulfilled state here is SEEDED as the owner: `CreateFulfilment` is not built in this
     * commit, so the API cannot produce it yet (`RED` P9: nothing in the schema ties status to
     * `quantity_fulfilled`). The API-driven version is `CreateFulfilment`'s commit's (owner ruling R5).
     *
     * **Mutation M6:** copy `PurchaseOrderLineRow`'s bare subtraction into `PickListReader` in place of
     * the shared expression — the cancelled line reports `6.000000` outstanding and lists bins; RED.
     */
    #[Test]
    public function aCancelledOrderIsReadableAndOwesNothingSoItListsNoBins(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH SO-CXC');
        $order = $this->seedSalesOrder(self::TENANT_A, 'SO-CXC', warehouseId: $warehouse, status: SalesOrder::STATUS_CANCELLED, orderedAt: '2026-09-20T10:00:00Z');
        $item = $this->seedItem(self::TENANT_A, 'SKU-CXC');
        $this->seedSalesOrderLine(self::TENANT_A, $order, $item, quantity: '10.000000', quantityFulfilled: '4.000000');
        $live = $this->seedSalesOrder(self::TENANT_A, 'SO-LIVE', warehouseId: $warehouse, status: SalesOrder::STATUS_PARTIALLY_FULFILLED, orderedAt: '2026-09-20T10:00:00Z');
        $this->seedSalesOrderLine(self::TENANT_A, $live, $item, quantity: '10.000000', quantityFulfilled: '4.000000');
        $bin = $this->seedBin($warehouse, 'A-01');
        $this->stock($item, $bin, '9');

        $this->actAs(self::TENANT_A, ['fulfilment.view']);

        $this->send('GET', '/sales-orders/'.$order.'/pick-list');
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['4.000000', '0.000000', []], [
            $this->json()['lines'][0]['quantity_fulfilled'],
            $this->json()['lines'][0]['quantity_outstanding'],
            $this->json()['lines'][0]['candidate_bins'],
        ], 'A cancelled order must owe 0 at scale 6 and list no bin.');

        // The positive control, in the same test: a live partially fulfilled order owes its remainder.
        $this->send('GET', '/sales-orders/'.$live.'/pick-list');
        self::assertSame(['6.000000', 1], [
            $this->json()['lines'][0]['quantity_outstanding'],
            \count($this->json()['lines'][0]['candidate_bins']),
        ]);
    }

    /** SEC: nothing is pickable while the order's warehouse is archived, matching the depletion's refusal. */
    #[Test]
    public function anArchivedWarehouseListsNoBins(): void
    {
        $f = $this->confirmedOrder('SO-WHX');
        $this->stock($f['item'], $this->seedBin($f['warehouse'], 'A-01'), '5');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE warehouse SET deleted_at = now() WHERE id = ?::uuid', [$f['warehouse']]);

        $this->send('GET', '/sales-orders/'.$f['order'].'/pick-list');

        self::assertSame(200, $this->httpStatus());
        self::assertSame([], $this->json()['lines'][0]['candidate_bins']);
    }

    /** Only live lines: an archived line of the order is not on the pick list. */
    #[Test]
    public function anArchivedLineIsNotOnThePickList(): void
    {
        $f = $this->confirmedOrder('SO-ARL');
        $gone = $this->seedSalesOrderLine(self::TENANT_A, $f['order'], $this->seedItem(self::TENANT_A, 'SKU-GONE'), lineNumber: 2);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE sales_order_line SET deleted_at = now() WHERE id = ?::uuid', [$gone]);

        $this->send('GET', '/sales-orders/'.$f['order'].'/pick-list');

        self::assertSame([$f['line']], array_map(static fn (array $l): string => $l['sales_order_line']['id'], $this->json()['lines']));
    }

    /**
     * **V2-S-1b clause 1, money-free — derived from the server, never a list here.** Every member name the
     * pick read emits, at any depth, is held against the names of every `numeric(19,4)` column in the
     * schema (PC-12 clause 1's domain, read from `pg_attribute`). **Positive control, in this test:** the
     * same walk over `GetSalesOrder`'s body finds `total_amount`, so a walk that reads nothing cannot pass.
     *
     * ⚠ **Name-shaped, disclosed** (design §4 C3): a money column projected under a renamed member would
     * escape it. PC-9's column table (clause 6's test) is the bound that names every member.
     */
    #[Test]
    public function thePickReadCarriesNoMoneyColumn(): void
    {
        $money = $this->owner()->fetchFirstColumn(<<<'SQL'
            SELECT DISTINCT a.attname
              FROM pg_attribute a
              JOIN pg_class c ON c.oid = a.attrelid
              JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') AND a.attnum > 0 AND NOT a.attisdropped
               AND format_type(a.atttypid, a.atttypmod) = 'numeric(19,4)'
            SQL);
        self::assertContains('total_amount', $money, 'PC-12 clause 1\'s domain was not read from the catalogue.');

        $f = $this->confirmedOrder('SO-MONEY');
        $this->stock($f['item'], $this->seedBin($f['warehouse'], 'A-01'), '5');

        $this->send('GET', '/sales-orders/'.$f['order']);
        self::assertContains('total_amount', array_intersect(self::memberNames($this->json()), $money),
            'The positive control failed: the walk found no money column even in GetSalesOrder\'s body.');

        $this->send('GET', '/sales-orders/'.$f['order'].'/pick-list');
        $members = self::memberNames($this->json());
        self::assertContains('qty_on_hand', $members, 'The pick body walked was not the full one (no candidate bin reached).');
        self::assertSame([], array_values(array_intersect($members, $money)),
            'V2-S-1b clause 1 / PC-12 clause 1: the pick read projects a numeric(19,4) column.');
    }

    /**
     * **V2-S-1b clause 6: PC-9's second deliberate exception names exactly the members this read
     * projects.** Every leaf member of a driven pick body must appear, backticked, in the exception's
     * column table, and the exception must name `GetPickList` and `fulfilment.view`. SKIPs where `docs/`
     * is absent (it is gitignored), disclosed — the money-free check above does not.
     */
    #[Test]
    public function pc9sSecondExceptionNamesExactlyTheColumnsThisReadProjects(): void
    {
        if (!SecurityModelDocument::isAvailable()) {
            self::markTestSkipped('docs/SECURITY_MODEL.md is gitignored; PC-9\'s exception is read from it.');
        }

        $text = (string) file_get_contents(SecurityModelDocument::path());
        $start = strpos($text, '**The second deliberate exception is the pick read, `GetPickList`');
        self::assertNotFalse($start, 'PC-9 no longer states its second deliberate exception.');
        $end = strpos($text, '**Violated by:**', $start);
        self::assertNotFalse($end);
        $exception = substr($text, $start, $end - $start);
        self::assertStringContainsString('`fulfilment.view` alone', $exception);

        $f = $this->confirmedOrder('SO-PC9');
        $this->stock($f['item'], $this->seedBin($f['warehouse'], 'A-01'), '5');
        $this->send('GET', '/sales-orders/'.$f['order'].'/pick-list');

        // The wire's leaf members, mapped to the column each is read from.
        $columnOf = [
            'order_number' => 'order_number', 'status' => 'status', 'ship_to_line' => 'ship_to_line',
            'ship_to_city' => 'ship_to_city', 'ship_to_postal_code' => 'ship_to_postal_code',
            'ship_to_country_code' => 'ship_to_country_code', 'name' => 'name', 'line_number' => 'line_number',
            'sku' => 'sku', 'code' => 'code', 'quantity' => 'quantity', 'quantity_fulfilled' => 'quantity_fulfilled',
            'quantity_outstanding' => 'quantity_outstanding', 'qty_on_hand' => 'qty_on_hand', 'id' => 'id',
        ];
        $missing = [];
        foreach (array_unique(self::leafNames($this->json())) as $leaf) {
            self::assertArrayHasKey($leaf, $columnOf, 'The pick body carries a member this test does not map: '.$leaf);
            if (!str_contains($exception, '`'.$columnOf[$leaf].'`')) {
                $missing[] = $leaf;
            }
        }
        self::assertSame([], $missing, 'PC-9\'s second exception does not name every column the pick read projects.');
    }

    // -----------------------------------------------------------------------

    /** @return array{order: string, line: string, item: string, warehouse: string, customer: string} */
    private function confirmedOrder(string $number): array
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Customer '.$number);
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH '.$number);
        $order = $this->seedSalesOrder(self::TENANT_A, $number, $customer, $warehouse, SalesOrder::STATUS_CONFIRMED, '2026-09-20T10:00:00Z');
        $item = $this->seedItem(self::TENANT_A, 'SKU-'.$number);

        return [
            'order' => $order,
            'line' => $this->seedSalesOrderLine(self::TENANT_A, $order, $item),
            'item' => $item,
            'warehouse' => $warehouse,
            'customer' => $customer,
        ];
    }

    private function archivedDraft(): string
    {
        $order = $this->seedSalesOrder(self::TENANT_A, 'SO-ARCH');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE sales_order SET deleted_at = now() WHERE id = ?::uuid', [$order]);

        return $order;
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

    /**
     * Every member name at every depth.
     *
     * @param array<mixed> $body
     *
     * @return list<string>
     */
    private static function memberNames(array $body): array
    {
        $names = [];
        foreach ($body as $key => $value) {
            if (\is_string($key)) {
                $names[] = $key;
            }
            if (\is_array($value)) {
                array_push($names, ...self::memberNames($value));
            }
        }

        return $names;
    }

    /**
     * The names of members holding a scalar, at every depth.
     *
     * @param array<mixed> $body
     *
     * @return list<string>
     */
    private static function leafNames(array $body): array
    {
        $names = [];
        foreach ($body as $key => $value) {
            if (\is_array($value)) {
                array_push($names, ...self::leafNames($value));
            } elseif (\is_string($key)) {
                $names[] = $key;
            }
        }

        return $names;
    }
}

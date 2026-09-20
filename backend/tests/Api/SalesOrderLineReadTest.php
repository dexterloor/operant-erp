<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Sales\ApiResource\SalesItemReference;
use App\Modules\Sales\ApiResource\SalesOrderLineResource;
use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * `ListSalesOrderLines` — the bounded sub-list of a sales order's lines, and the
 * operation that **applies V2-C-14(b)**.
 *
 * The registry-wide machinery already covers the envelope, the undeclared-parameter
 * refusal, the scales, the reference expansions and the permission gate. What is
 * here is what those cannot see: the derivation `quantity_outstanding` is, the two
 * traps inside it, and the per-view behaviour `PurchaseOrderReadTest` covers for the
 * purchase twin.
 */
final class SalesOrderLineReadTest extends PurchaseOrderTestCase
{
    /**
     * A sales order with two lines, and an item whose `name` differs from its `sku`
     * so a read cannot pass by returning the wrong column.
     *
     * @return array{order: string, items: list<string>}
     */
    private function orderWithTwoLines(string $number, string $status = SalesOrder::STATUS_DRAFT): array
    {
        $order = $this->seedSalesOrder(self::TENANT_A, $number, status: $status);
        $items = [];
        foreach (['Alpha bolt' => 'SKU-'.$number.'-A', 'Beta nut' => 'SKU-'.$number.'-B'] as $name => $sku) {
            $item = $this->seedItem(self::TENANT_A, $sku);
            $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE inventory_item SET name = ? WHERE id = ?::uuid', [$name, $item]);
            $items[] = $item;
        }

        return ['order' => $order, 'items' => $items];
    }

    /** §4.4.3's derivation for `SalesOrderLine`, asserted as an exact key set in order. */
    #[Test]
    public function theLineCarriesExactlyItsDerivedFieldList(): void
    {
        $o = $this->orderWithTwoLines('SO-1');
        $this->seedSalesOrderLine(self::TENANT_A, $o['order'], $o['items'][0]);

        $this->send('GET', '/sales-orders/'.$o['order'].'/lines');

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([
            'id', 'line_number', 'inventory_item', 'quantity', 'unit_price',
            'quantity_fulfilled', 'quantity_outstanding', 'archived_at',
        ], array_keys($this->json()['items'][0]));
    }

    /**
     * **The second spelling of that field list, held to the first.**
     *
     * `SalesOrderLineCollection.items` publishes
     * `SalesOrderLineResource::WIRE_SCHEMA` inline rather than a
     * `$ref: '#/components/schemas/SalesOrderLine'`, because **API Platform mints
     * a component only from an operation's input or output and every §4.2 write on
     * `SalesOrderLine` is unbuilt** — measured: with the resource declared
     * `#[ApiResource(shortName: 'SalesOrderLine')]`, both with `operations: []` and
     * with an explicit `NotExposed`, `OpenApiContractTest::everyReferenceInTheSpecResolves`
     * failed with *"#/components/schemas/SalesOrderLine names no component"*.
     *
     * Two spellings of one list is the drift this project logs, so this is the
     * comparison that makes it safe — the pattern `PurchaseOrderViews::SEGMENT_NAMES`
     * established. **When the first `SalesOrderLine` write ships, delete the constant
     * and use a `$ref`;** this test is then what goes red until it is.
     */
    #[Test]
    public function theWireSchemaIsTheResourcesOwnMembers(): void
    {
        $schema = SalesOrderLineResource::WIRE_SCHEMA;

        self::assertSame(self::serializedMembers(SalesOrderLineResource::class), array_keys($schema['properties']),
            'SalesOrderLineCollection publishes a member list the resource does not have, or omits one it does.');
        self::assertSame(self::serializedMembers(SalesItemReference::class), array_keys($schema['properties']['inventory_item']['properties']),
            'The published inventory_item shape is not SalesItemReference\'s own members.');

        // The control: the comparison is against a non-empty list read off the
        // class, not against whatever the constant happens to contain.
        self::assertContains('quantity_outstanding', self::serializedMembers(SalesOrderLineResource::class),
            'The reflection helper returned nothing recognisable, so both assertions above would compare two empty lists.');
    }

    /** @return list<string> a DTO's serialized member names, in declaration order */
    private static function serializedMembers(string $class): array
    {
        $names = [];
        foreach (new \ReflectionClass($class)->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $serialized = $property->getAttributes(\Symfony\Component\Serializer\Attribute\SerializedName::class);
            $names[] = [] === $serialized ? $property->getName() : (string) $serialized[0]->newInstance()->serializedName;
        }

        return $names;
    }

    /**
     * A line shows its own number and names its item as `{id, sku, name}` (API-23b
     * clause (ii), owner ruling Q-A).
     *
     * The lines are seeded **out of order with non-contiguous numbers** (5, then 2),
     * so a positional number (`ROW_NUMBER()`, an array index) reads `[1, 2]` where
     * the column reads `[2, 5]`, and each item's `name` differs from its `sku`.
     */
    #[Test]
    public function aLineShowsItsNumberAndNamesItsItemBySkuAndName(): void
    {
        $o = $this->orderWithTwoLines('SO-1');
        $this->seedSalesOrderLine(self::TENANT_A, $o['order'], $o['items'][0], lineNumber: 5);
        $this->seedSalesOrderLine(self::TENANT_A, $o['order'], $o['items'][1], lineNumber: 2);

        $this->send('GET', '/sales-orders/'.$o['order'].'/lines');

        self::assertSame(200, $this->httpStatus());
        self::assertSame([
            [2, ['id' => $o['items'][1], 'sku' => 'SKU-SO-1-B', 'name' => 'Beta nut']],
            [5, ['id' => $o['items'][0], 'sku' => 'SKU-SO-1-A', 'name' => 'Alpha bolt']],
        ], array_map(static fn (array $l): array => [$l['line_number'], $l['inventory_item']], $this->json()['items']));
    }

    /**
     * **V2-C-14(b), the whole of it, with its own positive control** — the assertion
     * `OwedAmendmentTripwireTest::noOperationOwingTheCancelledRemainderDecisionHasBeenBuilt`
     * demanded by name before its pin was moved, and the reason that method's entry
     * was cleared.
     *
     * *"`sales_order_line.quantity_outstanding` reads 0 when the parent order's
     * status is `cancelled`. It is a DERIVATION in the read's SQL."*
     *
     * **Four things fail here independently**, which is why they are one test:
     *
     *   1. the **status clause** — a cancelled order's lines read `0`, where a bare
     *      `quantity - quantity_fulfilled` would read the full quantity. Nothing
     *      writes `quantity_fulfilled` today (G-7), so that is the widest the defect
     *      would be, not the narrowest;
     *   2. the **live positive control in the same test** — an identical line under a
     *      live parent reads its outstanding figure, so a `CASE` that returned 0 for
     *      everything, or a query that returned nothing, cannot pass;
     *   3. the **subtraction** — the live line is seeded `10 − 4`, the only reason
     *      `seedSalesOrderLine` takes a `quantityFulfilled` at all;
     *   4. the **scale** — both figures are asserted as literal strings at scale 6.
     *      A bare `0` in the `CASE` renders `"0"` (measured on the live cluster), and
     *      `WireScaleTest`'s `{4, 6}` guard only catches that while nothing else on
     *      the API emits scale 0. This literal is what holds it durably.
     */
    #[Test]
    public function aCancelledOrdersLinesOweNothingAndALiveOrdersOweTheirRemainder(): void
    {
        $live = $this->orderWithTwoLines('SO-LIVE');
        $this->seedSalesOrderLine(self::TENANT_A, $live['order'], $live['items'][0],
            quantity: '10.000000', quantityFulfilled: '4.000000');
        // Fulfilled in full: the boundary, and the other value whose scale a client compares on.
        $this->seedSalesOrderLine(self::TENANT_A, $live['order'], $live['items'][1], lineNumber: 2,
            quantity: '5.000000', quantityFulfilled: '5.000000');

        $cancelled = $this->orderWithTwoLines('SO-CANC', status: SalesOrder::STATUS_CANCELLED);
        $this->seedSalesOrderLine(self::TENANT_A, $cancelled['order'], $cancelled['items'][0],
            quantity: '10.000000', quantityFulfilled: '4.000000');

        $this->send('GET', '/sales-orders/'.$live['order'].'/lines');
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['6.000000', '0.000000'], array_column($this->json()['items'], 'quantity_outstanding'),
            'A live order did not report quantity - quantity_fulfilled at scale 6.');
        self::assertSame(['4.000000', '5.000000'], array_column($this->json()['items'], 'quantity_fulfilled'));

        $this->send('GET', '/sales-orders/'.$cancelled['order'].'/lines');
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['0.000000'], array_column($this->json()['items'], 'quantity_outstanding'),
            'A cancelled order reported outstanding work. V2-C-14(b): the remainder is derived as 0, never written.');
        // The line itself is untouched -- the zero is a derivation, not a column
        // somebody wrote, which is the half of the ruling a `quantity_cancelled`
        // column would have broken.
        self::assertSame(['10.000000'], array_column($this->json()['items'], 'quantity'));
        self::assertSame(['4.000000'], array_column($this->json()['items'], 'quantity_fulfilled'));
    }

    /**
     * **`RESIDUAL_GAPS.md` CD-3, carried by a mechanism rather than by a sentence.**
     *
     * V2-C-14(b) ruled the `cancelled` branch and only that branch. An order
     * **archived** while `confirmed` keeps `status = 'confirmed'`, so its lines still
     * report outstanding work on a deleted document — and §2.4's parent probe
     * deliberately carries no `deleted_at` predicate (§5.2a), so those lines are
     * readable. Nobody has ruled what that figure should be.
     *
     * It is **unreachable today**: no built operation archives a sales order. This
     * method pins that, so the day `DeleteSalesOrder` ships it goes red and the
     * question is asked before the behaviour exists rather than after.
     */
    #[Test]
    public function theArchivedParentBranchIsUnruledAndStillUnreachable(): void
    {
        $built = array_keys(TransactionBoundaryTest::contractOperations());

        // The control: the registry is real and spells operation names the way this
        // pin reads them, so the assertion below is not comparing against nothing.
        self::assertContains('CancelSalesOrder', $built,
            'The registry does not name operations the way this pin does, so the check below would pass on everything.');

        self::assertSame([], array_values(array_intersect(['DeleteSalesOrder', 'RestoreSalesOrder'], $built)),
            "A sales order can now be archived, and `quantity_outstanding` on an ARCHIVED parent is UNRULED.\n\n"
            ."V2-C-14(b) ruled the `cancelled` branch alone. An order archived while `confirmed` keeps\n"
            ."`status = 'confirmed'`, so `SalesOrderLineRow`'s CASE falls to the subtraction and the lines\n"
            ."report outstanding work on a deleted document. `SalesOrderReader::listLines()` probes the parent\n"
            ."with no `deleted_at` predicate (§5.2a's direct-hit carve-out), so those lines are readable.\n\n"
            ."DO THIS IN THIS COMMIT, or move this pin deliberately and say why:\n"
            ."  1. Get the branch RULED. It is not `BE-LEAD`'s to invent: OI-2 defines the cancelled\n"
            ."     remainder and says nothing about an archived one.\n"
            ."  2. Apply it in `SalesOrderLineRow::COLUMNS`, beside the cancelled branch.\n"
            ."  3. Assert it here, with a live parent in the same test as the control.\n\n"
            .'Full entry: `docs/RESIDUAL_GAPS.md` CD-3. Raised by `RED` against the slice-2 design, 2026-09-20.');
    }

    /**
     * **§4.4.2 — a `(bounded)` list carries both cursors null *always*, because the
     * whole set is in `items`.** It is the same envelope, not a second shape.
     */
    #[Test]
    public function theBoundedSubListReturnsTheWholeSetInOneEnvelope(): void
    {
        $o = $this->orderWithTwoLines('SO-1');

        foreach (range(1, 5) as $number) {
            $this->seedSalesOrderLine(self::TENANT_A, $o['order'], $o['items'][0], $number);
        }

        $this->send('GET', '/sales-orders/'.$o['order'].'/lines');

        $body = $this->json();
        self::assertSame(['items', 'next_cursor', 'previous_cursor', 'tenant_has_rows'], array_keys($body));
        self::assertCount(5, $body['items']);
        self::assertNull($body['next_cursor']);
        self::assertNull($body['previous_cursor']);
        self::assertSame([1, 2, 3, 4, 5], array_column($body['items'], 'line_number'),
            'A bounded set is loaded in line_number order -- the order sales_order_line_unique maintains.');
    }

    /** §10.2 exclusion 2 and §10.3 step 4: a bounded list declares no paging parameter, so it refuses one. */
    #[Test]
    public function theBoundedSubListRefusesAPagingParameter(): void
    {
        $o = $this->orderWithTwoLines('SO-1');

        foreach (['limit=2', 'after=x', 'before=x', 'sort=line_number'] as $parameter) {
            $this->send('GET', '/sales-orders/'.$o['order'].'/lines?'.$parameter);

            self::assertSame(422, $this->httpStatus(), $parameter.' was accepted on a bounded list.');
            self::assertSame('undeclared_parameter', $this->json()['errors'][0]['code']);
        }
    }

    /**
     * **API-19c limb 2 — `archived` selects *which* whole set is returned**, and its
     * two facts are the ones the entity's class docblock got wrong until 2026-09-21:
     * the column exists and `sales_order_line_unique` is partial on it. TB-3 still
     * holds in the default direction: a live list never returns a soft-deleted row.
     */
    #[Test]
    public function archivedSelectsTheArchivedLinesAndTheDefaultReturnsTheLiveOnes(): void
    {
        $o = $this->orderWithTwoLines('SO-1');
        $live = $this->seedSalesOrderLine(self::TENANT_A, $o['order'], $o['items'][0], 1);
        $gone = $this->seedSalesOrderLine(self::TENANT_A, $o['order'], $o['items'][1], 2);

        $this->insertAsOwnerForTenant(self::TENANT_A,
            'UPDATE sales_order_line SET deleted_at = now() WHERE id = ?::uuid', [$gone]);

        $this->send('GET', '/sales-orders/'.$o['order'].'/lines');
        self::assertSame([$live], array_column($this->json()['items'], 'id'), 'TB-3: a live list returned an archived row.');
        self::assertNull($this->json()['items'][0]['archived_at']);

        $this->send('GET', '/sales-orders/'.$o['order'].'/lines?archived=true');
        self::assertSame([$gone], array_column($this->json()['items'], 'id'));
        self::assertNotNull($this->json()['items'][0]['archived_at']);
    }

    /** API-19's *"refuse, never ignore"* applied to a boolean: `?archived=maybe` must not silently mean false. */
    #[Test]
    public function anUnparseableArchivedValueIsRefusedRatherThanCoerced(): void
    {
        $o = $this->orderWithTwoLines('SO-1');

        $this->send('GET', '/sales-orders/'.$o['order'].'/lines?archived=maybe');

        self::assertSame(422, $this->httpStatus());
        self::assertSame('not_a_boolean', $this->json()['errors'][0]['code']);
    }

    /**
     * §5.2a — an archived **item** is still the item this line was ordered for, so the
     * line stays on the list with its reference expanded. Dropping it would make a
     * sales order lose a line because somebody tidied a catalogue.
     */
    #[Test]
    public function aLineWhoseItemIsArchivedIsStillReturnedWithItsItemExpanded(): void
    {
        $o = $this->orderWithTwoLines('SO-1');
        $line = $this->seedSalesOrderLine(self::TENANT_A, $o['order'], $o['items'][0]);
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'UPDATE inventory_item SET deleted_at = now() WHERE id = ?::uuid', [$o['items'][0]]);

        $this->send('GET', '/sales-orders/'.$o['order'].'/lines');

        self::assertSame(200, $this->httpStatus());
        self::assertSame([$line], array_column($this->json()['items'], 'id'));
        self::assertSame(
            ['id' => $o['items'][0], 'sku' => 'SKU-SO-1-A', 'name' => 'Alpha bolt'],
            $this->json()['items'][0]['inventory_item'],
        );
    }

    /**
     * §5.2a's other half, from the parent's side: the sub-list of an **archived
     * order** still answers, because a fulfilment or an invoice legitimately
     * references that order. The parent probe deliberately carries no `deleted_at`
     * predicate, so the answer is 200 and not the 404 an invisible order gets.
     *
     * ⚠ **What the lines do then is the cascade's doing, and it was measured rather
     * than predicted.** `sales_order_line.deleted_at` is *"written by the sales
     * order's cascade trigger"*, so archiving the order archives every line with it:
     * the **live** set goes empty and the lines reappear under `?archived=true`.
     * This test first asserted the opposite — that the live set still held the line
     * — and was red, which is the useful half of writing it.
     */
    #[Test]
    public function theSubListOfAnArchivedOrderAnswersAndItsLinesCascadeWithIt(): void
    {
        $o = $this->orderWithTwoLines('SO-1');
        $line = $this->seedSalesOrderLine(self::TENANT_A, $o['order'], $o['items'][0]);
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'UPDATE sales_order SET deleted_at = now() WHERE id = ?::uuid', [$o['order']]);

        $this->send('GET', '/sales-orders/'.$o['order'].'/lines');
        self::assertSame(200, $this->httpStatus(),
            'An archived order\'s sub-list answered as though the order did not exist, which is the 404 §5.2a carves out.');
        self::assertSame([], $this->json()['items'], 'The cascade trigger did not archive the line with its order.');

        $this->send('GET', '/sales-orders/'.$o['order'].'/lines?archived=true');
        self::assertSame(200, $this->httpStatus());
        self::assertSame([$line], array_column($this->json()['items'], 'id'));
        self::assertNotNull($this->json()['items'][0]['archived_at']);
    }

    /**
     * §5.3 clause 2 over the parent: a sub-list of an order the caller cannot see is
     * the same `not-found` a direct hit on that order gets, byte for byte, so the
     * sub-list is not an existence oracle for its parent.
     */
    #[Test]
    public function theSubListOfAnInvisibleOrderIsNotFound(): void
    {
        $inB = $this->seedPrincipal(self::TENANT_B, []);
        $orderInB = $this->seedSalesOrder(self::TENANT_B, 'SO-B');
        self::assertNotSame('', $inB);

        $this->send('GET', '/sales-orders/'.$orderInB.'/lines');
        $foreign = (string) $this->client()->getResponse()->getContent();
        self::assertSame(404, $this->httpStatus());

        $this->send('GET', '/sales-orders/'.Uuid::v4()->toRfc4122().'/lines');
        self::assertSame(404, $this->httpStatus());
        self::assertSame($foreign, (string) $this->client()->getResponse()->getContent());

        $this->send('GET', '/sales-orders/not-a-uuid/lines');
        self::assertSame(404, $this->httpStatus());
        self::assertSame($foreign, (string) $this->client()->getResponse()->getContent());
    }

    /**
     * ST-2's discriminator on an empty sub-list. API-22 fixes the probe as an
     * `EXISTS` over **the resource's table** with no filter, so it answers *"does
     * this workspace have any sales order lines at all"* — which is what
     * distinguishes a brand-new workspace from an order somebody emptied.
     */
    #[Test]
    public function anEmptySubListReportsWhetherTheWorkspaceHasAnyLinesAtAll(): void
    {
        $first = $this->orderWithTwoLines('SO-1');

        $this->send('GET', '/sales-orders/'.$first['order'].'/lines');
        self::assertSame([], $this->json()['items']);
        self::assertFalse($this->json()['tenant_has_rows']);

        $second = $this->orderWithTwoLines('SO-2');
        $this->seedSalesOrderLine(self::TENANT_A, $second['order'], $second['items'][0]);

        $this->send('GET', '/sales-orders/'.$first['order'].'/lines');
        self::assertSame([], $this->json()['items']);
        self::assertTrue($this->json()['tenant_has_rows'],
            'The workspace has lines and this empty sub-list reported first-run, which is ST-2\'s wrong empty state.');
    }
}

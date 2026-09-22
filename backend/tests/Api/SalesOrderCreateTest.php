<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Http\Problem\ProblemType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **Output 9, sales slice 1, commit 2 — `CreateSalesOrder` over HTTP.** The read that commit 3 added is
 * `SalesOrderReadTest`; this file keeps the create, and the `/sales-orders` operation pin both share.
 *
 * Every assertion that is about what was **written** reads the database as the owner, outside the request,
 * so what is checked is what was committed rather than what the response said.
 *
 * ⚠ **It extends `PurchaseOrderTestCase`, and that is a pin rather than a taxonomy.**
 * `OperationSuccessRecipes` is `@phpstan-require-extends PurchaseOrderTestCase` and `CreateSalesOrder`'s
 * recipe has to live inside that trait (RED F-12), so the customer and address fixtures live on that class
 * too and this test reads them from the same place the recipe does. A sales rig of its own would give the
 * two paths two sets of fixtures and nothing to keep them equal.
 */
final class SalesOrderCreateTest extends PurchaseOrderTestCase
{
    /**
     * The operations shipped under `/sales-orders`. Joined into
     * `AuthOperationContractTest::theShippedSurfaceIsExactlyTheOperationsThisWaveHasPinned`'s
     * whole-registry union, so an operation added to `SalesOrderResource` and not pinned here is red there.
     *
     * **Of §4.2's `/sales-orders` rows, only `RestoreSalesOrder` is not built here** — E9-3 blocks it while
     * `ListSalesOrders` declares no `archived` parameter (owner ruling Q1, 2026-09-21), and
     * {@see self::theArchivedIdProblemIsWhyNoOrderRestoreShips} holds that both ways. *(This sentence listed
     * `ListSalesOrders`, `UpdateSalesOrder`, `CancelSalesOrder` and `CreateSalesOrderLine` as unbuilt until
     * 2026-09-21, while all four were built and pinned directly below it — the step-4 design's stale claim
     * S-3.)* `GetSalesOrder` is the resource's first item operation, and its arrival is why `CreateSalesOrder`
     * no longer declares a `CreatedRowLocation` template — the vendor's own `Location` is already §4.2's
     * path. `ConfirmSalesOrder`'s `idem` is `false` because §4.2 gives it `guard:sales_order.status`, and
     * API-14 as amended demands the header for `store` and `unique:` only.
     *
     * @var array<string, array{method: string, path: string, idem: bool}>
     */
    public const PINNED_SALES_ORDER_OPERATIONS = [
        // In declaration order: the registry preserves it and the reconciliation below is `assertSame`.
        // The two reads are first, and in **declaration** order: the registry preserves it and the
        // comparison below is `assertSame`, so a pin sorted to read well would be a second, quieter
        // fact. Both were measured against the registry rather than guessed -- the first attempt put
        // `ListSalesOrderLines` first and was red.
        // Sales step 5 C4 (2026-09-22): the fulfilment create, FIRST -- read off this test's own failure; the
        // registry orders by resource-class declaration and `FulfilmentResource` sorts first. `idem` TRUE: §4.2's
        // cell is `unique:fulfilment_idem_unique` (C2), and API-14 as amended demands the header for `unique:`.
        'CreateFulfilment' => ['method' => 'POST', 'path' => '/sales-orders/{id}/fulfilments', 'idem' => true],
        // Sales step 5 C3 (2026-09-22): the pick read. Was FIRST until C4, read off this test's own failure -- the registry
        // orders by resource-class declaration and `PickListResource` sorts before the others. `idem` false: a GET.
        'GetPickList' => ['method' => 'GET', 'path' => '/sales-orders/{id}/pick-list', 'idem' => false],
        'ListSalesOrders' => ['method' => 'GET', 'path' => '/sales-orders', 'idem' => false],
        'ListSalesOrderLines' => ['method' => 'GET', 'path' => '/sales-orders/{id}/lines', 'idem' => false],
        // `idem` **true**: §4.2's cell is `unique:sales_order_line_tenant_idem_live` since
        // `Version20260921000100`, and API-14 as amended demands the header for `unique:` and `store`.
        // **Its position was read off this test's own failure, not guessed** -- it sorts beside its
        // sibling on the same path rather than with the other POSTs, because the registry orders by
        // resource-class declaration and this operation lives on `SalesOrderLineResource`.
        'CreateSalesOrderLine' => ['method' => 'POST', 'path' => '/sales-orders/{id}/lines', 'idem' => true],
        // `idem` false: §4.2's cell is `no`, and API-14's answer is that a PATCH sets absolute values on
        // a row that already exists, so a replay converges and there is no duplicate to prevent.
        'UpdateSalesOrderLine' => ['method' => 'PATCH', 'path' => '/sales-orders/{id}/lines/{lineId}', 'idem' => false],
        // `idem` false: §4.2's cell is `no`. A repeat finds the line already archived and is refused by
        // the line guard, so there is no duplicate for a key to prevent.
        'DeleteSalesOrderLine' => ['method' => 'DELETE', 'path' => '/sales-orders/{id}/lines/{lineId}', 'idem' => false],
        // `idem` FALSE even though §4.2 marks it: the cell is `guard:sales_order_line.deleted_at`, and
        // API-14 as amended demands the header for `store` and `unique:` only. A guard refuses its own
        // repeat whether or not a header arrives.
        'RestoreSalesOrderLine' => ['method' => 'POST', 'path' => '/sales-orders/{id}/lines/{lineId}/restoration', 'idem' => false],
        'GetSalesOrder' => ['method' => 'GET', 'path' => '/sales-orders/{id}', 'idem' => false],
        'CreateSalesOrder' => ['method' => 'POST', 'path' => '/sales-orders', 'idem' => true],
        // `idem` false, and §4.2's `Idem` cell reads `no` for the reason API-14 asks for: a PATCH sets
        // absolute values on a row that already exists, so a replay converges on the same state and
        // there is no duplicate for a key to prevent. Its position here was read off this test's own
        // failure, not guessed.
        'UpdateSalesOrder' => ['method' => 'PATCH', 'path' => '/sales-orders/{id}', 'idem' => false],
        // `idem` false: §4.2's cell is `no`. A repeat finds the order archived and is refused by the archived
        // guard, `DeletePurchaseOrder`'s answer. Position read off this test's own failure.
        'DeleteSalesOrder' => ['method' => 'DELETE', 'path' => '/sales-orders/{id}', 'idem' => false],
        'ConfirmSalesOrder' => ['method' => 'POST', 'path' => '/sales-orders/{id}/confirmation', 'idem' => false],
        // `idem` false for the confirmation's reason: §4.2's cell is `guard:sales_order.status`, and API-14
        // as amended demands the header for `store` and `unique:` only.
        'CancelSalesOrder' => ['method' => 'POST', 'path' => '/sales-orders/{id}/cancellation', 'idem' => false],
    ];

    /** The registry's `/sales-orders` operations are exactly the pin, method and path included. */
    #[Test]
    public function theSalesOrderPathsDeclareExactlyThePinnedOperations(): void
    {
        $declared = [];
        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            if (str_starts_with((string) $operation->getUriTemplate(), '/sales-orders')) {
                $declared[$name] = ['method' => $operation->getMethod(), 'path' => $operation->getUriTemplate()];
            }
        }

        self::assertSame(
            array_map(static fn (array $pin): array => ['method' => $pin['method'], 'path' => $pin['path']], self::PINNED_SALES_ORDER_OPERATIONS),
            $declared,
        );
    }

    /**
     * API-21 branch 4: **201, the created resource as the body, and a `Location` that IS §4.2's declared
     * row-level URI** — not merely one that resolves.
     *
     * *(Until `GetSalesOrder` shipped, that string came from `CreatedRowLocation`, because the vendor's
     * own header would have named `/sales_orders/<uuid>` — a route that answers 404 by construction. The
     * item `GET` now supplies it and the declaration is gone. **The assertion is unchanged, which is the
     * point:** it names the string, not the mechanism, so the swap had to keep the header byte-identical
     * or this is red.)*
     */
    #[Test]
    public function aCreateAnswers201WithTheDeclaredRowUriAndTheCreatedOrder(): void
    {
        $c = $this->workspace();

        $this->create($c, [['inventory_item_id' => $c['item'], 'quantity' => '2', 'unit_price' => '3.5']]);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertTrue(Uuid::isValid((string) $body['id']));
        self::assertSame(self::BASE.'/sales-orders/'.$body['id'], $this->client()->getResponse()->headers->get('Location'));
        // ⚠ **`Content-Location` gained a `.json` suffix in this commit and that is the vendor's shipped
        // behaviour, not a regression.** `HttpResponseHeadersTrait` writes `Location` as the bare IRI and
        // `Content-Location` as `<iri>.<requestFormat>`; `CreatedRowLocation` had been overwriting both
        // with one string because the vendor's IRI named a 404 route. With `GetSalesOrder` shipped the
        // vendor's IRI is right, the listener is gone, and this response now matches `CreatePurchaseOrder`
        // and `CreateGoodsReceipt` byte for byte in shape. The suffixed URI resolves — every API Platform
        // route carries `{._format}`. **It is asserted here rather than dropped** because it is the one
        // observable difference removing the declaration made, and an unasserted header is how a silent
        // change of it would next go unnoticed.
        self::assertSame(self::BASE.'/sales-orders/'.$body['id'].'.json', $this->client()->getResponse()->headers->get('Content-Location'));

        self::assertSame(['id' => $c['customer'], 'name' => 'Acme Retail'], $body['customer']);
        self::assertSame(['id' => $c['warehouse'], 'name' => 'WH SO'], $body['warehouse']);
        self::assertSame('SO-000001', $body['order_number']);
        self::assertSame(SalesOrder::STATUS_DRAFT, $body['status']);
        self::assertSame('EUR', $body['currency_code']);
        self::assertSame('7.0000', $body['total_amount'], 'The total is computed in the order\'s own INSERT from the request\'s lines.');
        self::assertNull($body['ordered_at']);
        self::assertNull($body['archived_at']);

        // The read shape carries no `lines` (API-23 step 1's sub-list exception) and no `ship_to_address`
        // (step 1's snapshot-source exception, owner ruling O-1, written into §4.4.3 with the read). It
        // **does** carry `created_by`, ruled by B1N-2 on 2026-09-19 — and the create's body and the
        // read's body are the same shape by construction, because `SalesOrderRow` builds both.
        self::assertSame([
            'id', 'customer', 'warehouse', 'currency_code', 'order_number', 'status', 'total_amount',
            'ordered_at', 'created_by', 'ship_to_line', 'ship_to_city', 'ship_to_postal_code',
            'ship_to_country_code', 'archived_at',
        ], array_keys($body));
        self::assertSame(['id' => $this->actorId, 'display_name' => 'Fixture Actor'], $body['created_by']);

        // What was written, read as the owner outside the request.
        $rows = $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT total_amount, created_by_user_id, status FROM sales_order');
        self::assertCount(1, $rows);
        self::assertSame('7.0000', $rows[0]['total_amount']);
        // ⚠ **This read `assertNotNull` until 2026-09-19 under a message claiming the stronger property**,
        // which is B1N-2 amendment A4: the column being non-null says nothing about *whose* id it holds,
        // and a wrong id was invisible while the field was withheld. Exposure turns it into a
        // colleague's name on a screen. `assertSame` is the purchasing mirror
        // (`PurchaseOrderApprovalTest:687`), and the wrong-actor mutation was run rather than predicted.
        self::assertSame($this->actorId, $rows[0]['created_by_user_id'],
            'An order created over HTTP does not name its creator. §8.1\'s rule for the purchasing mirror: '
            .'created_by_user_id is set server-side from the JWT subject on every user-initiated create, and any other '
            .'id there is now a wrong name rendered on the order screen (B1N-2, 2026-09-19).');

        $lines = $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT line_number, quantity, unit_price, quantity_fulfilled FROM sales_order_line');
        self::assertCount(1, $lines);
        self::assertSame(1, $lines[0]['line_number']);
        self::assertSame('2.000000', $lines[0]['quantity']);
        self::assertSame('3.5000', $lines[0]['unit_price']);
        self::assertSame('0.000000', $lines[0]['quantity_fulfilled'],
            'quantity_fulfilled is the schema default and is not written. If it ever becomes server-derived, the '
            .'VALUES-list total in SalesOrderWriter::insertOrderInside() is wrong — see its docblock.');
    }

    /** D-C's atomicity, one order and its lines in one transaction, with `line_number` minted from position. */
    #[Test]
    public function anOrderAndItsLinesAreWrittenAtomicallyAndNumberedByPosition(): void
    {
        $c = $this->workspace();
        $second = $this->seedItem(self::TENANT_A, 'SKU-SO-2');

        $this->create($c, [
            ['inventory_item_id' => $c['item'], 'quantity' => '1', 'unit_price' => '10'],
            ['inventory_item_id' => $second, 'quantity' => '3', 'unit_price' => '0.5'],
        ]);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('11.5000', $this->json()['total_amount']);

        self::assertSame(
            [[1, $c['item']], [2, $second]],
            array_map(
                static fn (array $row): array => [$row['line_number'], $row['inventory_item_id']],
                $this->fetchAsOwnerForTenant(self::TENANT_A,
                    'SELECT line_number, inventory_item_id FROM sales_order_line ORDER BY line_number'),
            ),
        );
    }

    /**
     * **There is no savepoint and none is wanted.** A line the database refuses takes the order with it —
     * a create that kept the order and dropped a line is the headless draft the nested create exists to
     * make impossible.
     */
    #[Test]
    public function aLineNamingAnUnknownItemRollsTheWholeOrderBack(): void
    {
        $c = $this->workspace();

        $this->create($c, [
            ['inventory_item_id' => $c['item'], 'quantity' => '1', 'unit_price' => '10'],
            ['inventory_item_id' => Uuid::v4()->toRfc4122(), 'quantity' => '1', 'unit_price' => '10'],
        ]);

        self::assertSame(422, $this->httpStatus());
        self::assertSame(ProblemType::ValidationFailed->uri(), $this->json()['type']);
        self::assertSame('/lines/1/inventory_item_id', $this->json()['errors'][0]['pointer']);
        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM sales_order'));
        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM sales_order_line'));
    }

    /** F-5's empty draft: `lines` absent is legal, and the literal-`0` statement shape is what writes it. */
    #[Test]
    public function anOrderMayBeRaisedWithNoLinesAtAll(): void
    {
        $c = $this->workspace();

        $this->create($c, null);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('0.0000', $this->json()['total_amount']);
        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM sales_order_line'));
    }

    /**
     * `lines: []` is refused. It says nothing omitting the field does not say, and admitting it would put
     * into the digest a value canonical JSON cannot tell from `{}`.
     *
     * **Mutation:** delete the `[] === $raw` branch from `SalesOrderWriter::nestedLines()`.
     */
    #[Test]
    public function anEmptyLineListIsRefused(): void
    {
        $c = $this->workspace();

        $this->create($c, []);

        self::assertSame(422, $this->httpStatus());
        self::assertSame('at_least_one_line', $this->json()['errors'][0]['code']);
        self::assertSame('/lines', $this->json()['errors'][0]['pointer']);
        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM sales_order'));
    }

    /**
     * An absent `lines` and an explicit `"lines": null` are **one request and one digest**, because
     * `?array $lines = null` cannot tell them apart. A retry that switches between the two spellings
     * replays rather than conflicting, and both ask for an order with no lines.
     */
    #[Test]
    public function anExplicitNullLinesIsTheSameRequestAsOmittingIt(): void
    {
        $c = $this->workspace();
        $key = [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()];

        $this->send('POST', '/sales-orders', $this->body($c, null), $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $first = $this->json()['id'];

        $this->send('POST', '/sales-orders', $this->body($c, null) + ['lines' => null], $key);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($first, $this->json()['id'], 'The two spellings digested differently, so one became a 409.');
        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM sales_order'));
    }

    /** D-B: the same key with a different body is `idempotency-key-conflict`, and nothing is written. */
    #[Test]
    public function theSameKeyWithADifferentBodyIsAConflict(): void
    {
        $c = $this->workspace();
        $key = [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()];

        $this->send('POST', '/sales-orders', $this->body($c, [['inventory_item_id' => $c['item'], 'quantity' => '1', 'unit_price' => '1']]), $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->send('POST', '/sales-orders', $this->body($c, [['inventory_item_id' => $c['item'], 'quantity' => '2', 'unit_price' => '1']]), $key);

        self::assertSame(409, $this->httpStatus());
        self::assertSame(ProblemType::IdempotencyKeyConflict->uri(), $this->json()['type']);
        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM sales_order'));
    }

    /**
     * **O-2 — the ship-to is snapshotted AT CREATE**, field for field, from the `customer_address` row the
     * request names. The order stores no address id, which is why `customer_address` is deletion class
     * `hard`: an order pointing at a live address row would rewrite where a past shipment went every time
     * the customer moves.
     */
    #[Test]
    public function theShipToSnapshotEqualsTheAddressItWasCopiedFrom(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Acme Retail');
        $address = $this->seedCustomerAddress(self::TENANT_A, $customer,
            addressLine: '221B Baker Street', city: 'Manchester', postalCode: 'M1 1AE', countryCode: 'GB');
        $c = [
            'customer' => $customer,
            'address' => $address,
            'warehouse' => $this->seedWarehouse(self::TENANT_A, 'WH SO'),
            'item' => $this->seedItem(self::TENANT_A, 'SKU-SO-1'),
        ];

        $this->create($c, null);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('221B Baker Street', $this->json()['ship_to_line']);
        self::assertSame('Manchester', $this->json()['ship_to_city']);
        self::assertSame('M1 1AE', $this->json()['ship_to_postal_code']);
        self::assertSame('GB', $this->json()['ship_to_country_code']);

        self::assertSame(
            [['ship_to_line' => '221B Baker Street', 'ship_to_city' => 'Manchester', 'ship_to_postal_code' => 'M1 1AE', 'ship_to_country_code' => 'GB']],
            $this->fetchAsOwnerForTenant(self::TENANT_A,
                'SELECT ship_to_line, ship_to_city, ship_to_postal_code, ship_to_country_code FROM sales_order'),
        );
    }

    /**
     * **V2-S-9 — the address must belong to `customer_id`.**
     *
     * The refusal is deliberately the same for an address of another customer, a `billing` address and an
     * id that never existed: ST-5, because three distinguishable answers would make this endpoint an
     * oracle over the workspace's address book.
     *
     * **Mutation:** drop `AND customer_id = ?::uuid` from `SalesOrderWriter::shipToInside()`.
     * **Second mutation:** drop `AND kind = 'shipping'`.
     */
    #[Test]
    public function anAddressThatIsNotAShippingAddressOfThisCustomerIsRefused(): void
    {
        $c = $this->workspace();
        $other = $this->seedCustomer(self::TENANT_A, 'Other Co');

        foreach ([
            'another customer\'s shipping address' => $this->seedCustomerAddress(self::TENANT_A, $other),
            'this customer\'s billing address' => $this->seedCustomerAddress(self::TENANT_A, $c['customer'], kind: 'billing'),
            'an id no address has' => Uuid::v4()->toRfc4122(),
        ] as $why => $addressId) {
            $this->send('POST', '/sales-orders',
                ['customer_id' => $c['customer'], 'warehouse_id' => $c['warehouse'], 'ship_to_address_id' => $addressId],
                [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);

            self::assertSame(422, $this->httpStatus(), $why.' was accepted.');
            self::assertSame(ProblemType::ValidationFailed->uri(), $this->json()['type'], $why);
            self::assertSame(
                [['pointer' => '/ship_to_address_id', 'code' => 'unknown_reference',
                    'message' => 'No shipping address of this customer has this id.']],
                $this->json()['errors'], $why.' produced a different refusal, which makes this endpoint an oracle.');
        }

        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM sales_order'));
    }

    /**
     * `ship_to_address_id` is required, because `sales_order.ship_to_country_code` is `NOT NULL` with no
     * default: an order without one is a row Postgres refuses, and a named field error is the better
     * answer.
     */
    #[Test]
    public function theShipToAddressIsRequired(): void
    {
        $c = $this->workspace();

        $this->send('POST', '/sales-orders',
            ['customer_id' => $c['customer'], 'warehouse_id' => $c['warehouse']],
            [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);

        self::assertSame(422, $this->httpStatus());
        self::assertSame(
            [['pointer' => '/ship_to_address_id', 'code' => 'required', 'message' => 'This field is required.']],
            $this->json()['errors'],
        );
    }

    /**
     * **RED exit audit F-2.** The guard and the database must do the *same* arithmetic.
     *
     * Line 2's exact product is `0.0000989901`. A guard that accumulates at scale 4 **truncates** it to
     * `0.0000`, never moves its accumulator, and lets the request through; Postgres sums the `VALUES` list
     * at full precision and **rounds** the cast into `numeric(19,4)`, giving `1000000000000000.0000` — a
     * `22003` inside the order's own INSERT, which is neither unique violation `create()` names and reached
     * the client as an **HTTP 500**. Measured over HTTP before the fix.
     *
     * **Mutation:** put either `bcmul(..., 4)`/`bcadd(..., 4)` back into `nestedLines()` and this answers
     * 500 with `SQLSTATE[22003]` again.
     */
    #[Test]
    public function aTotalThatOverflowsOnlyWhenTheDatabaseRoundsIsRefusedRatherThanAnsweringA500(): void
    {
        $c = $this->workspace();
        $second = $this->seedItem(self::TENANT_A, 'SKU-SO-ROUND');

        $this->create($c, [
            ['inventory_item_id' => $c['item'], 'quantity' => '1', 'unit_price' => '999999999999999.9999'],
            ['inventory_item_id' => $second, 'quantity' => '0.000099', 'unit_price' => '0.9999'],
        ]);

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(ProblemType::ValidationFailed->uri(), $this->json()['type']);
        self::assertSame(
            ['/lines/1/unit_price' => 'total_out_of_range'],
            array_column($this->json()['errors'], 'code', 'pointer'),
            'The tipping line is the one the caller can act on, and it is the second.',
        );
        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM sales_order'));
        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM sales_order_line'));
    }

    /**
     * The other direction of the same boundary, so the rounding fix cannot be mistaken for the guard being
     * loosened: a total that plainly exceeds `numeric(19,4)` is still refused, at the line that tipped it.
     */
    #[Test]
    public function aTotalThatPlainlyExceedsTheColumnIsStillRefused(): void
    {
        $c = $this->workspace();
        $second = $this->seedItem(self::TENANT_A, 'SKU-SO-BIG');

        $this->create($c, [
            ['inventory_item_id' => $c['item'], 'quantity' => '1', 'unit_price' => '999999999999999.9999'],
            ['inventory_item_id' => $second, 'quantity' => '1', 'unit_price' => '999999999999999.9999'],
        ]);

        self::assertSame(422, $this->httpStatus());
        self::assertSame(
            ['/lines/1/unit_price' => 'total_out_of_range'],
            array_column($this->json()['errors'], 'code', 'pointer'),
        );
        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM sales_order'));
    }

    /**
     * And the ceiling itself is still **accepted** — the fix rounds, it does not tighten. `1 × 999…9.9999`
     * is the largest total `sales_order.total_amount` can hold, and it is written unchanged.
     */
    #[Test]
    public function aTotalSittingExactlyOnTheColumnsCeilingIsAccepted(): void
    {
        $c = $this->workspace();

        $this->create($c, [['inventory_item_id' => $c['item'], 'quantity' => '1', 'unit_price' => '999999999999999.9999']]);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('999999999999999.9999', $this->json()['total_amount']);
        self::assertSame(
            [['total_amount' => '999999999999999.9999']],
            $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT total_amount FROM sales_order'),
        );
    }

    /**
     * **The owner's Q4 ruling (2026-09-21): a deleted order's number is never issued again.**
     *
     * `nextOrderNumber()` minted `MAX + 1` over LIVE rows only, so *discard the newest order, raise another*
     * handed the discarded order's number to the new one. Two documents then share a number for good — the
     * archived one stays readable by direct hit — and the archived one can never be restored:
     * `sales_order_tenant_number_live` refuses it (`23505`, measured by the step-4 design and by `RED`). A number
     * the user cannot choose would turn ADR-007 §4's *"designed error message"* into a permanent refusal.
     *
     * The order is archived as the schema owner because `DeleteSalesOrder` was not yet built when this test
     * landed (the commit before it); the property is the MINT's, whatever archived the row. Free now — no archived sales order has ever existed —
     * and permanent once one does.
     */
    #[Test]
    public function aDiscardedOrdersNumberIsNeverIssuedAgain(): void
    {
        $c = $this->workspace();

        $this->create($c, null);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('SO-000001', $this->json()['order_number'], 'Control: the first order is not SO-000001, so the next assertion measures nothing.');
        $first = (string) $this->json()['id'];

        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE sales_order SET deleted_at = now() WHERE id = ?::uuid', [$first]);

        $this->create($c, null);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('SO-000002', $this->json()['order_number'],
            'A discarded order\'s number was issued again. The two documents now share a number for good, and the '
            .'discarded one can never be restored (sales_order_tenant_number_live). The owner ruled Q4 (2026-09-21): '
            .'never reissue — nextOrderNumber() must count archived orders too.');
    }

    // -----------------------------------------------------------------------

    /** @return array{customer: string, address: string, warehouse: string, item: string} */
    private function workspace(): array
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Acme Retail');

        return [
            'customer' => $customer,
            'address' => $this->seedCustomerAddress(self::TENANT_A, $customer),
            'warehouse' => $this->seedWarehouse(self::TENANT_A, 'WH SO'),
            'item' => $this->seedItem(self::TENANT_A, 'SKU-SO-1'),
        ];
    }

    /**
     * @param array{customer: string, address: string, warehouse: string, item: string} $c
     * @param list<array<string, string>>|null                                          $lines
     *
     * @return array<string, mixed>
     */
    private function body(array $c, ?array $lines): array
    {
        $body = [
            'customer_id' => $c['customer'],
            'warehouse_id' => $c['warehouse'],
            'currency_code' => 'EUR',
            'ship_to_address_id' => $c['address'],
        ];

        return null === $lines ? $body : $body + ['lines' => $lines];
    }

    /**
     * @param array{customer: string, address: string, warehouse: string, item: string} $c
     * @param list<array<string, string>>|null                                          $lines
     */
    private function create(array $c, ?array $lines): void
    {
        $this->send('POST', '/sales-orders', $this->body($c, $lines), [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
    }

    /**
     * **E9-3 as a CHECK rather than a category, and the clause it reconciles was rewritten to make this
     * test possible** (Output 9, sales slice 4, commit 5).
     *
     * E9-3 used to block *"`RestoreItem`, any other `Restore\*`, or `CreateBulkRestoration`"* — **a
     * category**, which is the exact defect E9-2's clause 1 was rewritten to remove on 2026-09-12: *"a
     * category is a second, unmaintained copy of a set, and the two drift."* It had drifted the same way
     * here. API-O-11's substantive ground is *"no list can produce an archived row's id"*, and that
     * ground is **false** of the line restores: `ListSalesOrderLines` declares `archived` and is built.
     *
     * So the clause now blocks on a property **computable from §4.2's own Query-parameters column**, and
     * this is its reconciliation — modelled on the **shipped**
     * `PurchaseOrderOperationContractTest::theArchivedIdProblemIsWhyNoRestoreShips`, which already
     * implements the clause bidirectionally for `RestorePurchaseOrder`. **Bidirectional matters:** it is
     * red if the operation ships while the parameter is gone, *and* red if the parameter arrives while
     * the operation is withdrawn. A gate that can only fire one way is a gate that gets walked past.
     *
     * ⚠ **Obtainability is necessary and not sufficient, and this test deliberately does not claim
     * otherwise.** The purchase twin stays unshipped on a **different** ground its own processor records
     * in shipped code — coherence with the parent — which `RestoreSalesOrderLine` answers with
     * `refuseIfArchived($order)` and `refuseUnlessDraft($order)` rather than by disputing it.
     */
    #[Test]
    public function theArchivedIdProblemIsWhyNoLineRestoreShips(): void
    {
        $operation = TransactionBoundaryTest::contractOperations()['ListSalesOrderLines'];
        \assert($operation instanceof \ApiPlatform\Metadata\HttpOperation);

        $declaredParameters = [];
        foreach ($operation->getParameters() ?? [] as $key => $parameter) {
            $declaredParameters[] = (string) ($parameter->getKey() ?? $key);
        }

        $reachable = \in_array('archived', $declaredParameters, true);
        $declared = array_keys(TransactionBoundaryTest::contractOperations());

        self::assertSame($reachable, \in_array('RestoreSalesOrderLine', $declared, true),
            'E9-3 as rewritten 2026-09-21: `RestoreSalesOrderLine` ships exactly when an archived line\'s id is '
            ."obtainable by a caller who does not already hold it.\n"
            .'A restore nobody can reach is not a shipped operation, and it is the kind that passes every test '
            ."because the test holds the id.\n"
            .'If `ListSalesOrderLines` ever drops its `archived` parameter, this operation must be withdrawn in the '
            .'same commit -- and if this operation is withdrawn while the parameter stands, that is red too.');

        // The control, and it is the carve-out E9-3's rewritten clause names explicitly: `RestoreItem`
        // SHIPS while `ListItems` declares NO `archived`. A naive computable clause makes a shipped
        // operation retroactively illegal on its first run, which is why the carve-out is written into
        // the ledger cell rather than left as a comment.
        $items = TransactionBoundaryTest::contractOperations()['ListItems'];
        \assert($items instanceof \ApiPlatform\Metadata\HttpOperation);

        $itemParameters = [];
        foreach ($items->getParameters() ?? [] as $key => $parameter) {
            $itemParameters[] = (string) ($parameter->getKey() ?? $key);
        }

        self::assertNotContains('archived', $itemParameters,
            '`ListItems` has gained an `archived` parameter. E9-3 carves `RestoreItem` out BY NAME precisely because '
            .'it ships without one; if that has changed, the carve-out is now unnecessary and the ledger cell should '
            .'say so rather than keeping an exception nothing needs.');
        self::assertContains('RestoreItem', $declared,
            'The carve-out above is vacuous unless `RestoreItem` really is shipped.');
    }

    /**
     * **E9-3 for the ORDER, and — since `DeleteSalesOrder` shipped — the only thing that goes red on
     * `RestoreSalesOrder` for E9-3's reason.** The sales twin of the shipped
     * `PurchaseOrderOperationContractTest::theArchivedIdProblemIsWhyNoRestoreShips`, bidirectional like it:
     * `RestoreSalesOrder` ships exactly when `ListSalesOrders` declares `archived`.
     *
     * **Why it lands in `DeleteSalesOrder`'s commit and not later (`RED`, sales slice 5, §1.3).** Until that
     * commit, `SalesOrderLineReadTest`'s CD-4 pin intersected `['DeleteSalesOrder', 'RestoreSalesOrder']` with the
     * registry, so a `RestoreSalesOrder` added then DID go red — for CD-4's reason. The CD-4 pin had to be re-keyed
     * when `DeleteSalesOrder` entered the registry, and after the re-key nothing would have fired on the restore at
     * all. This test and `OwedAmendmentTripwireTest::noOperationOwingTheRestoreCollisionsHasBeenBuilt` are what
     * replace it, in the same commit.
     *
     * **`RESIDUAL_GAPS.md` G-8** (owner-accepted 2026-09-21, Q1): until this goes red on purpose, a discarded draft
     * is recoverable only by direct SQL, and `IA.md` L-9's reversal-register row *"Delete a draft"* asserts a
     * reversal that does not exist for either document type. **Before `RestoreSalesOrder` ships,
     * `RequestContractTest::archiveTheRowARestoreBinds()` must know `sales-orders`** — it does since 2026-09-21 —
     * or that operation's no-write check is vacuous, the fake-green `RED` measured for `RestoreSalesOrderLine`.
     *
     * **The `status` control is `RED`'s**: without it, a renamed `ListSalesOrders` makes the lookup an undefined
     * key and the whole check leans on PHP's warning handling — the empty-set trap.
     */
    #[Test]
    public function theArchivedIdProblemIsWhyNoOrderRestoreShips(): void
    {
        $operations = TransactionBoundaryTest::contractOperations();
        self::assertArrayHasKey('ListSalesOrders', $operations,
            'Control: `ListSalesOrders` is not in the registry under that name, so the check below would compare nothing.');
        $list = $operations['ListSalesOrders'];
        \assert($list instanceof \ApiPlatform\Metadata\HttpOperation);

        $declaredParameters = [];
        foreach ($list->getParameters() ?? [] as $key => $parameter) {
            $declaredParameters[] = (string) ($parameter->getKey() ?? $key);
        }
        self::assertContains('status', $declaredParameters,
            'Control: `ListSalesOrders` no longer declares `status`, so its parameters are not being read the way '
            .'this check reads them and the `archived` test below would pass on nothing.');

        self::assertSame(\in_array('archived', $declaredParameters, true), \array_key_exists('RestoreSalesOrder', $operations),
            'E9-3: `RestoreSalesOrder` ships exactly when an archived order\'s id is obtainable by a caller who does '
            ."not already hold it -- i.e. exactly when `ListSalesOrders` declares `archived`.\n"
            .'A restore nobody can reach is not a shipped operation, and it is the kind that passes every test '
            ."because the test holds the id.\n"
            .'If this went red because `archived` arrived: `RESIDUAL_GAPS.md` G-8 is due -- build the restore, '
            .'annotate IA L-9, and read `OwedAmendmentTripwireTest`\'s restore-collision pin, which carries the '
            .'constraint translations the restore owes.');
    }
}

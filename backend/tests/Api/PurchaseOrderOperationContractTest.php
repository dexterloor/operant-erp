<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\HttpOperation;
use App\Modules\Purchasing\ApiResource\ApprovePurchaseOrderInput;
use App\Modules\Purchasing\ApiResource\CancelPurchaseOrderInput;
use App\Modules\Purchasing\ApiResource\CreatePurchaseOrderInput;
use App\Modules\Purchasing\ApiResource\CreatePurchaseOrderLineInput;
use App\Modules\Purchasing\ApiResource\UpdatePurchaseOrderInput;
use App\Modules\Purchasing\ApiResource\State\PurchaseOrderCollectionProvider;
use App\Modules\Purchasing\ApiResource\UpdatePurchaseOrderLineInput;
use App\Modules\Purchasing\Application\PurchaseOrderViews;
use App\Modules\Purchasing\Application\PurchaseOrderWriter;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Tenancy\SchemaManifest;
use App\Tests\Support\ContractDocument;
use PHPUnit\Framework\Attributes\Test;

/**
 * §4.2's Purchasing rows, reconciled against what the application declares.
 *
 * ---------------------------------------------------------------------------
 * Why there is a pin here at all
 * ---------------------------------------------------------------------------
 * `docs/` is gitignored, so a check whose only domain is the document has no
 * subject on a clean checkout — the NO ARTIFACT shape API-10a refuses. The tables
 * below are **pins** in the §12.3 sense: checked against the operation registry
 * on every run, and, wherever the document is present, against §4.2 as well, in
 * both directions.
 *
 * ---------------------------------------------------------------------------
 * Rows of §4.2's Purchasing block that are DELIBERATELY UNSHIPPED
 * ---------------------------------------------------------------------------
 * `PINNED_UNSHIPPED` names them and `thePinsMatchTheContractDocument`
 * asserts their absence, on `AuthOperationContractTest`'s pattern for
 * `RefreshToken` and `RevokeSession`. Each is blocked by something a test can
 * point at, not by a schedule:
 *
 *   `RestorePurchaseOrder`       **E9-3 / API-O-11** — no path yields an
 *   `RestorePurchaseOrderLine`   archived row's id to a caller who does not
 *                                already hold it. `theArchivedIdProblemIsWhyNoRestoreShips`
 *                                is the half of that a test can carry.
 *   `CreateGoodsReceipt`         see `PINNED_UNSHIPPED`'s own note — blocked
 *                                three times over, and the Inventory contract
 *                                class it needs does not exist.
 *
 * **It was five until 2026-09-11.** `CreatePurchaseOrder` and
 * `CancelPurchaseOrder` shipped in step 6; what unblocked each is recorded on
 * the constant rather than here, because that is where a reader looking for why
 * a name is absent will be.
 */
final class PurchaseOrderOperationContractTest extends PurchaseOrderTestCase
{
    /**
     * The rows §4.2 declares for `purchase_order` that this package ships —
     * nine at Output 4's purchasing slice, **eleven since 2026-09-11**.
     *
     * @var array<string, array{method: string, path: string, idem: bool}>
     */
    public const PINNED_PURCHASE_ORDER_OPERATIONS = [
        'ListPurchaseOrders' => ['method' => 'GET', 'path' => '/purchase-orders', 'idem' => false],
        'GetPurchaseOrder' => ['method' => 'GET', 'path' => '/purchase-orders/{id}', 'idem' => false],
        // ⚠ **The only `idem => true` row in this table, and the only one there
        // has ever been here.** `CreatePurchaseOrder`'s cell is
        // `unique:purchase_order_tenant_idem_live` and the client-supplied value
        // that index is built on arrives in the header itself — so for this
        // operation the header is not a precondition attached to a mechanism, it
        // *is* the mechanism. Dropping the flag would leave the column NULL on
        // every request, which `Version20260911000200`'s trigger then refuses.
        'CreatePurchaseOrder' => ['method' => 'POST', 'path' => '/purchase-orders', 'idem' => true],
        // `guard:purchase_order.status`, so no header — `cancel()` refuses the
        // repeat either way (API-14 as amended).
        'CancelPurchaseOrder' => ['method' => 'POST', 'path' => '/purchase-orders/{id}/cancellation', 'idem' => false],
        'UpdatePurchaseOrder' => ['method' => 'PATCH', 'path' => '/purchase-orders/{id}', 'idem' => false],
        'DeletePurchaseOrder' => ['method' => 'DELETE', 'path' => '/purchase-orders/{id}', 'idem' => false],
        // `idem` is *"requires the header"*. `ApprovePurchaseOrder` is
        // `guard:purchase_order.status` and **lost** the requirement on
        // 2026-09-10: a guard refuses its own repeat with or without a header
        // (API-14 as amended), so the 428 protected nothing.
        'ApprovePurchaseOrder' => ['method' => 'POST', 'path' => '/purchase-orders/{id}/approval', 'idem' => false],
        'ListPurchaseOrderLines' => ['method' => 'GET', 'path' => '/purchase-orders/{id}/lines', 'idem' => false],
        'CreatePurchaseOrderLine' => ['method' => 'POST', 'path' => '/purchase-orders/{id}/lines', 'idem' => false],
        'UpdatePurchaseOrderLine' => ['method' => 'PATCH', 'path' => '/purchase-orders/{id}/lines/{lineId}', 'idem' => false],
        'DeletePurchaseOrderLine' => ['method' => 'DELETE', 'path' => '/purchase-orders/{id}/lines/{lineId}', 'idem' => false],
        // Step 8: `unique:goods_receipt_idem_unique`, header-sourced like
        // `CreatePurchaseOrder`. Here because its path is under the order (N-8).
        'CreateGoodsReceipt' => ['method' => 'POST', 'path' => '/purchase-orders/{id}/goods-receipts', 'idem' => true],
    ];

    /**
     * Declared in §4.2 under a `/purchase-orders` path, deliberately not shipped.
     * See the class docblock for the first four.
     *
     * **`CreateGoodsReceipt` is the fifth and it is here because of its `Path`,
     * not its resource.** N-8a hangs the create off the parent order, so
     * `/purchase-orders/{id}/goods-receipts` falls inside this file's domain and
     * would otherwise read as a row this package failed to ship. It is blocked
     * three times over: X9-1 and §8.3 name it as one of the four transitions with
     * **no entity method**; §4.2 marks it `Idem: yes` and it is one of the six
     * create-a-child operations **E9-2** stops while API-O-1's key store
     * deduplicates nothing; and posting a receipt is
     * `ARCHITECTURE.md` W-3's cross-module write into
     * `Inventory::ReceiveStock`, which needs an Inventory contract class that
     * does not exist — `deptrac.yaml` admits `InventoryContract` into
     * `PurchasingApplication` and there is nothing under that namespace to admit.
     */
    public const PINNED_UNSHIPPED = [
        // ⚠ **`CreatePurchaseOrder` and `CancelPurchaseOrder` left this list on
        // 2026-09-11 and the reasons they were on it are both discharged rather
        // than waived.** The first was blocked on §6.2's byte-for-byte promise
        // against §6.1's columns: `Version20260911000100` gave it a
        // client-supplied retry key, §6.2 was amended to say what a `unique:`
        // replay actually returns, and E9-2 stopped naming it. The second was
        // blocked by X9-1 because `PurchaseOrder::cancel()` did not exist, and
        // it now does — §8.3's matrix row names the method, which is what makes
        // `STATUS_CANCELLED` reachable.
        'RestorePurchaseOrder',
        'RestorePurchaseOrderLine',
        // `CreateGoodsReceipt` left this list at step 8: its E9-2 block was
        // discharged by 8-i's retry key, X9-1's by `recordReceipt()`, and the
        // Inventory contract it needed shipped at step 7.
    ];

    /**
     * §4.2's `Accepted request fields` cell per write row, as this package
     * implements it.
     *
     * ⚠ **Two cells are implemented SHORT and both are `expected_at` on a line.**
     * §4.2 accepts `expected_at` on `CreatePurchaseOrderLine` and
     * `UpdatePurchaseOrderLine`; `purchase_order_line` has no such column and no
     * migration is permitted in this package.
     * `lineExpectedAtIsAcceptedIfAndOnlyIfItHasAColumn` is the tie that makes the
     * day the column arrives a red suite, and
     * `thePinsMatchTheContractDocument` subtracts the field from the document
     * side **by name**, so the subtraction is visible rather than silent.
     *
     * @var array<string, list<string>>
     */
    private const PINNED_ACCEPTED_FIELDS = [
        // ⚠ **The third short cell was this one, and step 8a closed it (D-C).**
        // §4.2 names `lines[].inventory_item_id`, `lines[].quantity`,
        // `lines[].unit_cost` and `lines[].expected_at` on this row; the nested
        // create is now built and accepts the first three.
        // **The blind spot that hid the shortfall is closed with it:**
        // `ContractDocument::acceptedFields()` used to drop every `lines[].*`
        // token, so nothing here could go red about the missing field — it now
        // folds them to the top-level `lines`, and
        // `theNestedLineCellIsExactlyTheNestedSetTheWriterAccepts` compares the
        // sub-fields with the writer's own set. `lines[].expected_at` is the one
        // subtraction, and it is the SAME subtraction the two line writes make,
        // held by the same column tie.
        'CreatePurchaseOrder' => ['supplier_id', 'warehouse_id', 'currency_code', 'expected_at', 'lines'],
        'CancelPurchaseOrder' => ['reason'],
        'UpdatePurchaseOrder' => ['supplier_id', 'warehouse_id', 'expected_at'],
        'ApprovePurchaseOrder' => ['expected_at'],
        'CreatePurchaseOrderLine' => ['inventory_item_id', 'quantity', 'unit_cost'],
        'UpdatePurchaseOrderLine' => ['quantity', 'unit_cost'],
    ];

    /** The field §4.2 accepts on both line writes and the schema cannot store. */
    private const UNSTORABLE_LINE_FIELD = 'expected_at';

    /** @var array<string, class-string> */
    private const INPUT_DTOS = [
        'CreatePurchaseOrder' => CreatePurchaseOrderInput::class,
        'CancelPurchaseOrder' => CancelPurchaseOrderInput::class,
        'UpdatePurchaseOrder' => UpdatePurchaseOrderInput::class,
        'ApprovePurchaseOrder' => ApprovePurchaseOrderInput::class,
        'CreatePurchaseOrderLine' => CreatePurchaseOrderLineInput::class,
        'UpdatePurchaseOrderLine' => UpdatePurchaseOrderLineInput::class,
    ];

    /** §4.2's `Query parameters` cell for the two list rows. */
    private const PINNED_QUERY_PARAMETERS = [
        'ListPurchaseOrders' => ['status', 'supplier_id', 'q', 'sort', 'limit', 'after', 'before'],
        'ListPurchaseOrderLines' => ['archived'],
    ];

    // -----------------------------------------------------------------------
    // The pins, against the registry. These run on every checkout.
    // -----------------------------------------------------------------------

    #[Test]
    public function theRegistryDeclaresExactlyThePinnedOperations(): void
    {
        $declared = [];

        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            $path = str_replace(self::BASE, '', (string) $operation->getUriTemplate());

            if (1 !== preg_match('#^/purchase-orders(/|$)#', $path)) {
                continue;
            }

            $declared[$name] = [
                'method' => $operation->getMethod(),
                'path' => $path,
                'idem' => true === ($operation->getExtraProperties()[IdempotencyKeyGuard::OPERATION_FLAG] ?? null),
            ];
        }

        ksort($declared);
        $pinned = self::PINNED_PURCHASE_ORDER_OPERATIONS;
        ksort($pinned);

        self::assertSame($pinned, $declared);
    }

    /**
     * B-3, read off the input classes rather than off the spec: these are what
     * the deserializer will accept, and `additionalProperties: false` in the
     * generated schema is derived from them.
     */
    #[Test]
    public function theInputDtosDeclareExactlyTheAcceptedFieldsAndNoId(): void
    {
        foreach (self::INPUT_DTOS as $operationId => $input) {
            self::assertSame(
                self::PINNED_ACCEPTED_FIELDS[$operationId],
                self::serializedPropertiesOf($input),
                $input.' does not declare '.$operationId."'s accepted set.",
            );
            self::assertNotContains('id', self::serializedPropertiesOf($input), 'B-3: '.$input.' declares an id.');
        }
    }

    /** API-19b — the declared parameter set is exactly §4.2's cell, per list. */
    #[Test]
    public function eachListDeclaresExactlyThePinnedQueryParameters(): void
    {
        foreach (self::PINNED_QUERY_PARAMETERS as $operationId => $expected) {
            $operation = TransactionBoundaryTest::contractOperations()[$operationId];
            \assert($operation instanceof HttpOperation);

            $declared = [];
            foreach ($operation->getParameters() ?? [] as $key => $parameter) {
                $declared[] = (string) ($parameter->getKey() ?? $key);
            }

            self::assertSame($expected, $declared, $operationId.' does not declare §4.2\'s query-parameter cell.');
        }
    }

    /**
     * **API-19c, both limbs, decided against their own sources.**
     *
     * `archived` is offered on a list if and only if *either* the sort manifest
     * gives that route an archived row that is not `⛔`, *or* the list is a
     * bounded sub-list (TB-1c) of a table whose `SchemaManifest` deletion class
     * is `soft`.
     *
     *   `ListPurchaseOrders`      limb 1 fails — `/purchase-orders` Archived is
     *                             `⛔ D-a`, and the index that would close it
     *                             does not exist in `backend/migrations/`.
     *                             limb 2 fails — the list is not bounded.
     *                             **So a green 200 on `?archived=true` is red.**
     *   `ListPurchaseOrderLines`  limb 2 holds — bounded, and
     *                             `purchase_order_line` is
     *                             `SchemaManifest::SOFT`.
     *
     * **The `⛔ D-a` half is tied to the migrations rather than to a note**, the
     * way `ItemOperationContractTest::itemsOffersSortIfAndOnlyIfItsOrderingIndexIsMigrated`
     * ties `sort` to D-b: D-a will be closed by somebody with no reason to read
     * this file, and the parameter would otherwise stay off until a person
     * remembered a routed decision.
     */
    #[Test]
    public function archivedIsOfferedOnTheBoundedSubListAndNotOnTheDocumentList(): void
    {
        self::assertSame(SchemaManifest::SOFT, SchemaManifest::TABLES['purchase_order_line']['deletion'],
            'API-19c limb 2 needs a `soft` table, and the manifest no longer says this one is.');

        self::assertContains('archived', self::declaredParametersOf('ListPurchaseOrderLines'),
            'API-19c limb 2 holds for this list and it does not offer the parameter.');

        // The `⛔ D-a` tie. An archived-ordering index on `purchase_order` is what
        // limb 1 waits for, and there is none.
        $indexes = self::indexesCreatedOn('purchase_order');
        self::assertNotSame([], $indexes,
            'No index on purchase_order was parsed out of backend/migrations, so the condition below would be decided by a broken reader.');

        $archivedIndex = 'purchase_order_tenant_archived_idx';

        self::assertSame(
            \in_array($archivedIndex, $indexes, true),
            \in_array('archived', self::declaredParametersOf('ListPurchaseOrders'), true),
            'D-a: `/purchase-orders` must offer `archived` exactly when the archived-ordering index is migrated. '
            .'Indexes found on purchase_order: '.implode(', ', $indexes),
        );
    }

    /**
     * The behavioural half of the clause above, because a parameter list is a
     * declaration and §4.2's own mutation note is about a **200**.
     */
    #[Test]
    public function listingPurchaseOrdersRefusesAnArchivedParameter(): void
    {
        $this->seedDraft(self::TENANT_A, 'PO-1');

        $this->send('GET', '/purchase-orders?archived=true');

        self::assertSame(422, $this->httpStatus(),
            'A green 200 on ?archived is red: API-19c offers the parameter on neither limb for this list.');
        self::assertSame('urn:erp:problem:validation-failed', $this->json()['type']);
        self::assertSame('?archived', $this->json()['errors'][0]['pointer']);
    }

    /**
     * ⚠ **`expected_at` on a purchase order line: §4.2 accepts it, the schema has
     * no column for it, and this is the tie between the two.**
     *
     * `Version20260906000100`'s `CREATE TABLE purchase_order_line` declares
     * twelve columns and none is `expected_at`. Accepting the field and
     * discarding it is the silent shape this project keeps finding; refusing it
     * is loud (API-9's `validation-failed` names it as undeclared). What this
     * test adds is that the refusal cannot outlive its reason: the day a
     * migration adds the column, the left side flips and this goes red until the
     * field is accepted — and the day somebody adds the field with no column, it
     * goes red immediately.
     *
     * **The column list is parsed out of the migrations rather than read from the
     * live database**, so it runs on a clean checkout and cannot be satisfied by
     * a schema somebody changed by hand.
     */
    #[Test]
    public function lineExpectedAtIsAcceptedIfAndOnlyIfItHasAColumn(): void
    {
        $columns = self::columnsDeclaredOn('purchase_order_line');

        self::assertContains('quantity_ordered', $columns,
            'The purchase_order_line column list was not parsed, so the comparison below would be decided by a broken reader.');

        foreach ([CreatePurchaseOrderLineInput::class, UpdatePurchaseOrderLineInput::class] as $input) {
            self::assertSame(
                \in_array(self::UNSTORABLE_LINE_FIELD, $columns, true),
                \in_array(self::UNSTORABLE_LINE_FIELD, self::serializedPropertiesOf($input), true),
                $input.' must accept '.self::UNSTORABLE_LINE_FIELD.' exactly when purchase_order_line has a column for it. '
                .'Columns found: '.implode(', ', $columns),
            );
        }

        // **The third place the same field arrives, added with step 8a's nested
        // create.** §4.2 names `lines[].expected_at` on `CreatePurchaseOrder`
        // too, so without this clause the nested path could accept the field the
        // two line writes refuse and the suite would be green on the
        // contradiction.
        self::assertSame(
            \in_array(self::UNSTORABLE_LINE_FIELD, $columns, true),
            \in_array(self::UNSTORABLE_LINE_FIELD, PurchaseOrderWriter::nestedLineFields(), true),
            'CreatePurchaseOrder\'s nested lines[] set must accept '.self::UNSTORABLE_LINE_FIELD
            .' exactly when purchase_order_line has a column for it.',
        );
    }

    /**
     * **§4.2's `lines[].*` sub-fields against the ones the writer accepts — the
     * comparison that could not be written until 2026-09-12.**
     *
     * `ContractDocument::acceptedFields()` dropped every `lines[].*` token, so
     * for a month §4.2 declared a nested create this package refused entirely and
     * **no check in the project could see it**. `CreatePurchaseOrderInput`'s
     * docblock said so and a docblock is not a gate. This is the gate.
     *
     * The one subtraction is `expected_at`, by name and in one direction, exactly
     * as `thePinsMatchTheContractDocument` subtracts it from the two line writes —
     * and `lineExpectedAtIsAcceptedIfAndOnlyIfItHasAColumn` is what makes it
     * temporary.
     */
    #[Test]
    public function theNestedLineCellIsExactlyTheNestedSetTheWriterAccepts(): void
    {
        $this->requireDocument();

        $documented = ContractDocument::nestedAcceptedFields('CreatePurchaseOrder', 'lines');

        self::assertContains(self::UNSTORABLE_LINE_FIELD, $documented,
            '§4.2 no longer names lines[].'.self::UNSTORABLE_LINE_FIELD.' on CreatePurchaseOrder, so the subtraction below is stale.');

        self::assertSame(
            array_values(array_diff($documented, [self::UNSTORABLE_LINE_FIELD])),
            PurchaseOrderWriter::nestedLineFields(),
            'The nested lines[] fields PurchaseOrderWriter accepts are not §4.2\'s, less the one the schema cannot store.',
        );
    }

    /**
     * **The nested create's line cap, against the ceiling it was taken from.**
     *
     * No document sets a number of lines (grepped: §4.2 and §2 bound page size
     * and nothing else), so `PurchaseOrderWriter::MAXIMUM_LINES` is a decision
     * taken in the writer and it says so. What this pins is that it is not an
     * arbitrary literal: it is API-17's `limit` maximum — the most rows this API
     * will return in one response — so an order cannot be raised with more lines
     * than the read side will ever hand back in one page.
     *
     * If the page ceiling moves, this goes red and somebody decides whether the
     * write cap moves with it. That is the question worth forcing; two literals
     * silently drifting apart is not.
     */
    #[Test]
    public function theNestedLineCapIsThePageCeiling(): void
    {
        self::assertSame(PurchaseOrderCollectionProvider::MAXIMUM_LIMIT, PurchaseOrderWriter::MAXIMUM_LINES,
            'The cap on lines[] in one CreatePurchaseOrder is no longer API-17\'s page ceiling.');
    }

    /**
     * `PurchaseOrderViews::SEGMENT_NAMES` is a second spelling of
     * `SEGMENTS`' keys — an attribute argument must be a constant expression and
     * `array_keys()` is a function call — so the two are compared here. Two
     * spellings of one list with nothing comparing them is this project's
     * recorded drift mechanism.
     */
    #[Test]
    public function theSegmentNamesAreTheSegmentTablesKeys(): void
    {
        self::assertSame(PurchaseOrderViews::SEGMENT_NAMES, PurchaseOrderViews::segmentNames());
    }

    /**
     * **E9-3 / API-O-11, as a check rather than a comment.**
     *
     * `Restore*` needs a path by which a caller who does not already hold an
     * archived row's id can obtain one. For `purchase_order` there is none:
     * `/purchase-orders` Archived is `⛔ D-a`, so `ListPurchaseOrders` offers no
     * `archived` parameter, and §5.2a's direct hit needs the id already. This
     * asserts the consequence — no restore operation is declared — **tied to its
     * cause**, so the day the parameter arrives this goes red and the restore
     * becomes buildable rather than merely remembered.
     */
    #[Test]
    public function theArchivedIdProblemIsWhyNoRestoreShips(): void
    {
        $reachable = \in_array('archived', self::declaredParametersOf('ListPurchaseOrders'), true);
        $declared = array_keys(TransactionBoundaryTest::contractOperations());

        self::assertSame(
            $reachable,
            \in_array('RestorePurchaseOrder', $declared, true),
            'E9-3: `RestorePurchaseOrder` ships exactly when an archived order\'s id is obtainable. '
            .'A restore nobody can reach is not a shipped operation, and it is the kind that passes every '
            .'test because the test holds the id.',
        );
    }

    // -----------------------------------------------------------------------
    // The same pins, against the document.
    // -----------------------------------------------------------------------

    #[Test]
    public function thePinsMatchTheContractDocument(): void
    {
        $this->requireDocument();

        $rows = ContractDocument::operationRows();
        self::assertGreaterThan(50, \count($rows), 'The §4.2 parser found almost nothing, so every comparison below would be vacuous.');

        $documented = [];
        foreach ($rows as $operationId => $row) {
            if (1 === preg_match('#^/purchase-orders(/|$)#', $row['path'])) {
                $documented[$operationId] = [
                    'method' => $row['method'],
                    'path' => $row['path'],
                    // §4.2's cell names the mechanism; the header requirement is
                    // derived from it rather than read as a binary.
                    'idem' => ContractDocument::headerIsRequiredFor($operationId),
                ];
            }
        }

        // The four §4.2 declares and this package does not ship. They are removed
        // by **name**, so a fifth appearing in the document fails rather than
        // joining them.
        foreach (self::PINNED_UNSHIPPED as $unshipped) {
            self::assertArrayHasKey($unshipped, $documented,
                $unshipped.' is pinned as an unshipped §4.2 row and the document no longer declares it.');
            unset($documented[$unshipped]);
        }

        ksort($documented);
        $pinned = self::PINNED_PURCHASE_ORDER_OPERATIONS;
        ksort($pinned);

        self::assertSame($pinned, $documented);

        foreach (self::PINNED_ACCEPTED_FIELDS as $operationId => $expected) {
            $fields = ContractDocument::acceptedFields($operationId);

            // The one subtraction, by name and in one direction only: the
            // document accepts `expected_at` on a line and the schema cannot
            // store it. `lineExpectedAtIsAcceptedIfAndOnlyIfItHasAColumn` is what
            // makes that temporary.
            if (\in_array($operationId, ['CreatePurchaseOrderLine', 'UpdatePurchaseOrderLine'], true)) {
                self::assertContains(self::UNSTORABLE_LINE_FIELD, $fields,
                    $operationId.' no longer accepts '.self::UNSTORABLE_LINE_FIELD.' in §4.2, so the subtraction below is stale.');
                $fields = array_values(array_diff($fields, [self::UNSTORABLE_LINE_FIELD]));
            }

            self::assertSame($expected, $fields, $operationId.'\'s accepted set is not §4.2\'s.');
        }

        foreach (self::PINNED_QUERY_PARAMETERS as $operationId => $expected) {
            self::assertSame($expected, ContractDocument::queryParameters($operationId),
                $operationId.'\'s query-parameter cell is not §4.2\'s.');
        }
    }

    /**
     * §12.3 — `operationId = Verb ++ Resource`, derived from the row's own two
     * columns rather than compared against a list, and `List` ⇒ plural.
     */
    #[Test]
    public function everyOperationIdIsItsOwnVerbAndResource(): void
    {
        $this->requireDocument();

        $rows = ContractDocument::operationRows();

        foreach (array_keys(self::PINNED_PURCHASE_ORDER_OPERATIONS) as $operationId) {
            self::assertArrayHasKey($operationId, $rows);

            [$verb, $resource] = array_map(trim(...), explode('/', $rows[$operationId]['verbResource'], 2));
            self::assertSame($verb.$resource, $operationId);

            if ('List' === $verb) {
                self::assertStringEndsWith('s', $resource, 'A collection operation names a singular resource.');
            } else {
                self::assertStringEndsNotWith('s', $resource, 'A non-collection operation names a plural resource.');
            }
        }
    }

    // -----------------------------------------------------------------------

    /** @return list<string> */
    private static function declaredParametersOf(string $operationId): array
    {
        $operation = TransactionBoundaryTest::contractOperations()[$operationId];
        \assert($operation instanceof HttpOperation);

        $declared = [];
        foreach ($operation->getParameters() ?? [] as $key => $parameter) {
            $declared[] = (string) ($parameter->getKey() ?? $key);
        }

        return $declared;
    }

    /**
     * @param class-string $class
     *
     * @return list<string>
     */
    private static function serializedPropertiesOf(string $class): array
    {
        $serialized = [];

        foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();

            foreach ($property->getAttributes(\Symfony\Component\Serializer\Attribute\SerializedName::class) as $attribute) {
                $name = $attribute->newInstance()->serializedName;
            }

            $serialized[] = $name;
        }

        return $serialized;
    }

    /**
     * Every index name the migrations create on a table, derived from the DDL
     * itself. `IF NOT EXISTS` and `UNIQUE` are both optional in the form, so both
     * are optional in the pattern.
     *
     * @return list<string>
     */
    private static function indexesCreatedOn(string $table): array
    {
        $names = [];

        foreach (self::migrationSources() as $source) {
            preg_match_all(
                '/CREATE\s+(?:UNIQUE\s+)?INDEX\s+(?:CONCURRENTLY\s+)?(?:IF\s+NOT\s+EXISTS\s+)?([a-z0-9_]+)\s+ON\s+'.preg_quote($table, '/').'\b/i',
                $source,
                $matches,
            );
            $names = [...$names, ...$matches[1]];
        }

        return array_values(array_unique($names));
    }

    /**
     * Every column name a table is declared with, from its `CREATE TABLE` body
     * plus any `ALTER TABLE … ADD COLUMN`.
     *
     * **The `ALTER` half is not optional and is the reason this is not a one-line
     * regex:** three of the columns `SCHEMA.md` documents on `stock_movement` are
     * added by an `ALTER` in a later migration, so a reader that saw only the
     * `CREATE TABLE` would report a column absent that is present — which, on
     * this check, is the direction that fails **silently**.
     *
     * @return list<string>
     */
    private static function columnsDeclaredOn(string $table): array
    {
        $columns = [];

        foreach (self::migrationSources() as $source) {
            if (1 === preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?'.preg_quote($table, '/').'\s*\((.*?)\n\s*\)\s*$/ims', $source, $body)) {
                foreach (explode("\n", $body[1]) as $line) {
                    if (1 === preg_match('/^\s*([a-z_][a-z0-9_]*)\s+[a-z]/i', $line, $column)
                        && !\in_array(strtoupper($column[1]), ['CONSTRAINT', 'PRIMARY', 'UNIQUE', 'FOREIGN', 'CHECK'], true)
                    ) {
                        $columns[] = $column[1];
                    }
                }
            }

            preg_match_all(
                '/ALTER\s+TABLE\s+'.preg_quote($table, '/').'\s+ADD\s+COLUMN\s+(?:IF\s+NOT\s+EXISTS\s+)?([a-z_][a-z0-9_]*)/i',
                $source,
                $altered,
            );
            $columns = [...$columns, ...$altered[1]];
        }

        return array_values(array_unique($columns));
    }

    /** @return list<string> */
    private static function migrationSources(): array
    {
        $directory = \dirname(__DIR__, 2).'/migrations';
        $files = glob($directory.'/*.php');
        self::assertNotSame([], $files, 'No migration was found under '.$directory.'.');
        self::assertNotFalse($files);

        return array_map(static fn (string $file): string => (string) file_get_contents($file), $files);
    }

    private function requireDocument(): void
    {
        if (!ContractDocument::isAvailable()) {
            self::markTestSkipped('docs/API.md is gitignored and absent from this checkout, so the pins above are checked against the code only.');
        }
    }
}

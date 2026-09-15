<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\HttpOperation;
use App\Modules\Purchasing\ApiResource\ApprovePurchaseOrderInput;
use App\Modules\Purchasing\ApiResource\CancelPurchaseOrderInput;
use App\Modules\Purchasing\ApiResource\CreatePurchaseOrderInput;
use App\Modules\Purchasing\ApiResource\CreatePurchaseOrderLineInput;
use App\Modules\Purchasing\ApiResource\State\PurchaseOrderCollectionProvider;
use App\Modules\Purchasing\ApiResource\UpdatePurchaseOrderInput;
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
        // ⚠ **The first `idem => true` row in this table** (the goods receipt
        // and, since V2-R-12, the line create followed). `CreatePurchaseOrder`'s cell is
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
        // V2-R-12 (2026-09-16): `unique:purchase_order_line_tenant_idem_live`, header-sourced.
        'CreatePurchaseOrderLine' => ['method' => 'POST', 'path' => '/purchase-orders/{id}/lines', 'idem' => true],
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
     * **Every entry is compared with §4.2 directly, with no subtraction.** Until
     * 2026-09-15 §4.2 accepted `expected_at` on `CreatePurchaseOrderLine`,
     * `UpdatePurchaseOrderLine` and `CreatePurchaseOrder`'s `lines[]`, which
     * `purchase_order_line` could not store, and this file subtracted it by name.
     * Owner ruling Q2 ("Withdraw it") took it out of all three cells: a purchase
     * order line has no expected date, and the order's own `expected_at` is the
     * one date.
     *
     * @var array<string, list<string>>
     */
    private const PINNED_ACCEPTED_FIELDS = [
        // Step 8a built the nested create (D-C). §4.2 names
        // `lines[].inventory_item_id`, `lines[].quantity` and `lines[].unit_cost`
        // on this row. `ContractDocument::acceptedFields()` used to drop every
        // `lines[].*` token, so nothing here could go red about a missing
        // sub-field — it now folds them to the top-level `lines`, and
        // `theNestedLineCellIsExactlyTheNestedSetTheWriterAccepts` compares the
        // sub-fields with the writer's own set, while
        // `theNestedLineSetIsExactlyTheLineCreateSet` holds that set to
        // `CreatePurchaseOrderLine`'s without reading the document.
        'CreatePurchaseOrder' => ['supplier_id', 'warehouse_id', 'currency_code', 'expected_at', 'lines'],
        'CancelPurchaseOrder' => ['reason'],
        'UpdatePurchaseOrder' => ['supplier_id', 'warehouse_id', 'expected_at'],
        'ApprovePurchaseOrder' => ['expected_at'],
        'CreatePurchaseOrderLine' => ['inventory_item_id', 'quantity', 'unit_cost'],
        'UpdatePurchaseOrderLine' => ['quantity', 'unit_cost'],
    ];

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
     * **§4.2's `lines[].*` sub-fields against the ones the writer accepts — the
     * comparison that could not be written until 2026-09-12.**
     *
     * `ContractDocument::acceptedFields()` dropped every `lines[].*` token, so
     * for a month §4.2 declared a nested create this package refused entirely and
     * **no check in the project could see it**. `CreatePurchaseOrderInput`'s
     * docblock said so and a docblock is not a gate. This is the gate.
     *
     * There is no subtraction. Until 2026-09-15 §4.2 also named
     * `lines[].expected_at`, which the writer refused and `purchase_order_line`
     * could not store; owner ruling Q2 withdrew it, so the two sets are now
     * compared exactly.
     */
    #[Test]
    public function theNestedLineCellIsExactlyTheNestedSetTheWriterAccepts(): void
    {
        $this->requireDocument();

        $documented = ContractDocument::nestedAcceptedFields('CreatePurchaseOrder', 'lines');

        self::assertSame(
            $documented,
            PurchaseOrderWriter::nestedLineFields(),
            'The nested lines[] fields PurchaseOrderWriter accepts are not §4.2\'s.',
        );
    }

    /**
     * The nested `lines[]` set `CreatePurchaseOrder` accepts is exactly the set
     * `CreatePurchaseOrderLine` accepts — checked against the code alone, so a clean
     * checkout without `docs/API.md` still pins it; with
     * `theInputDtosDeclareExactlyTheAcceptedFieldsAndNoId` the chain is DTO = pin =
     * writer's nested set. It is the code-side half of API-23 step 1's sub-list
     * condition (owner ruling Q1(a), 2026-09-15).
     *
     * Given up deliberately (owner ruling Q2): the old column tie also went red the
     * day a migration added `purchase_order_line.expected_at`. Nothing does now;
     * a per-line date is feature work that needs a ruling first.
     */
    #[Test]
    public function theNestedLineSetIsExactlyTheLineCreateSet(): void
    {
        $nested = PurchaseOrderWriter::nestedLineFields();
        $line = self::PINNED_ACCEPTED_FIELDS['CreatePurchaseOrderLine'];
        self::assertNotSame([], $nested, 'The writer declares no nested line field, so the comparison below is vacuous.');
        sort($nested);
        sort($line);
        self::assertSame($line, $nested, 'CreatePurchaseOrder\'s lines[] set and CreatePurchaseOrderLine\'s set have diverged.');
    }

    /**
     * **The nested create's line cap and the page ceiling, held at the same
     * magnitude — which is all this pins, and the docblock used to claim more.**
     *
     * No document sets a number of lines, so `PurchaseOrderWriter::MAXIMUM_LINES`
     * is a decision taken in the writer and it says so there, with its one sound
     * reason: an unbounded list is an unbounded number of INSERTs in a single
     * transaction.
     *
     * ⚠ **This is deliberately NOT a derivation, and saying it was is the claim
     * that was corrected.** The two constants bound different things —
     * `MAXIMUM_LIMIT` is §10.1 / API-17's keyset page size, and
     * `ListPurchaseOrderLines` is a `(bounded)` sub-list that takes no `limit`
     * at all, so there is no page a 201-line order would fail to fit in. What
     * this asserts is narrower and still worth asserting: the largest batch this
     * API hands over in one response and the largest it accepts in one request
     * are the same magnitude and are **reviewed together**. If either moves, this
     * goes red and somebody decides about the other. Two literals drifting apart
     * unnoticed is the outcome worth preventing.
     */
    #[Test]
    public function theNestedLineCapIsThePageCeiling(): void
    {
        self::assertSame(PurchaseOrderCollectionProvider::MAXIMUM_LIMIT, PurchaseOrderWriter::MAXIMUM_LINES,
            'The cap on lines[] in one CreatePurchaseOrder and API-17\'s page ceiling have moved apart. '
            .'Neither derives from the other; they are held together so that changing one forces a decision '
            .'about the other.');
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

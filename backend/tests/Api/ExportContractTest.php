<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\AuditLog\Application\ExportRunner;
use App\Modules\AuditLog\Application\ExportSources;
use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use App\Platform\Http\Export\CsvCell;
use App\Platform\Http\Export\CsvExportRenderer;
use App\Platform\Http\Export\ExportSource;
use App\Platform\Http\Export\ListDeclarations;
use App\Platform\Security\PermissionRequirements;
use App\Tests\Support\GeneratedSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * **`CreateExport`'s contract (G6-C0; `API.md` §4.4.5 API-25 + its *fails if*; TB-7; design §6.8).**
 *
 * - `onlyTheExportOperationReturnsANonJsonSuccessBody` — domain = the generated spec, every operation's success content.
 * - `everyExportErrorIsProblemJson` — 403, 422 unknown filter, 422 unknown resource, 409 over the cap.
 * - `theExportRowSetEqualsTheListRowSet` (the Inventory family, G6-C0) and `eachFamilyExportsTheListsView` (every family
 *   G6-C1 added) — TB-7, per source, over filter sets that include `archived`, `status`, the id filters, `q` and every
 *   `sort` a fixture view names: the export equals the List, cell by cell, after the documented escape is applied FORWARD
 *   to the List (`escape(list) == export`, design §6.6 — `escape` is not injective, so it is never reversed). Each family's
 *   fixture holds a formula-led text cell and a null reference wherever the row class allows one (design R1.2 G6-C1).
 *   `everySourceHasATb7Drive` holds the two drives' resources equal, two-way, to the export registry.
 * - `filterMembersAreTheListOperationsQueryParameters` (API-14a, both directions, from the registry) and
 *   `noQueryParameterIsNamedId` (API-10 at the expanded `filters`).
 * - `aBodyReadTwoWaysIsAServerErrorNotAnExport` — G5-D0 carry (1), a planted mismatched `_derived_permission`.
 */
final class ExportContractTest extends PurchaseOrderTestCase
{
    use ExportTestSupport;
    use ListOperationFixtures;

    /** The `/exports` path family (joined into `AuthOperationContractTest`'s shipped-surface union). */
    public const PINNED_EXPORT_OPERATIONS = [
        'CreateExport' => ['method' => 'POST', 'path' => '/exports', 'idem' => false],
    ];

    /** The one operation API-3 lets answer a non-JSON success, and the type it answers. */
    private const THE_EXCEPTION = ['CreateExport' => ['text/csv']];

    #[Test]
    public function theExportPathsDeclareExactlyThePinnedOperations(): void
    {
        $declared = [];
        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            $path = (string) $operation->getUriTemplate();
            if (1 === preg_match('#^/exports(/|$)#', $path)) {
                $declared[$name] = ['method' => $operation->getMethod(), 'path' => $path];
            }
        }
        $pinned = array_map(static fn (array $pin): array => ['method' => $pin['method'], 'path' => $pin['path']], self::PINNED_EXPORT_OPERATIONS);

        self::assertSame($pinned, $declared);
    }

    #[Test]
    public function onlyTheExportOperationReturnsANonJsonSuccessBody(): void
    {
        $spec = GeneratedSpec::regenerate(self::$kernel ?? throw new \LogicException('booted in setUp()'));

        $nonJson = [];
        $seen = 0;
        foreach (GeneratedSpec::operations($spec) as $data) {
            $id = (string) ($data['operation']['operationId'] ?? '');
            foreach ($data['operation']['responses'] ?? [] as $status => $response) {
                if ((int) $status < 200 || (int) $status > 299) {
                    continue;
                }
                foreach (array_keys($response['content'] ?? []) as $type) {
                    ++$seen;
                    if ('application/json' !== $type) {
                        $nonJson[$id][] = (string) $type;
                    }
                }
            }
        }

        self::assertGreaterThan(50, $seen, 'The spec declared almost no success content, so the check is blind.');
        self::assertSame(self::THE_EXCEPTION, $nonJson,
            'API-3 names ONE operation whose success body is not JSON (API-25, `CreateExport` → text/csv). Another declares one, or the export declares JSON.');
    }

    #[Test]
    public function aSuccessIsCsvWithAnAttachmentName(): void
    {
        $this->seedItem(self::TENANT_A, 'SKU-1');

        $this->export(['resource' => 'inventory_item']);

        self::assertSame(200, $this->httpStatus(), $this->exportBody());
        self::assertSame('text/csv; charset=utf-8', $this->contentType());
        self::assertSame(\sprintf('attachment; filename="inventory_item-%s.csv"', gmdate('Ymd')),
            (string) $this->client()->getResponse()->headers->get('Content-Disposition'));
        self::assertStringEndsWith(CsvExportRenderer::LINE_END, $this->exportBody(), 'RFC 4180: every record ends in CRLF.');
    }

    #[Test]
    public function everyExportErrorIsProblemJson(): void
    {
        $this->seedItem(self::TENANT_A, 'SKU-1');

        // 422: an unknown resource -- the gate, before the decision (G5-D0).
        $this->export(['resource' => 'no_such_table']);
        $this->assertProblem(422, 'validation-failed', [['pointer' => '/resource', 'code' => 'unknown_resource', 'message' => 'This kind of record can\'t be exported.']]);

        // 422: a filter the List does not declare (API-14a).
        $this->export(['resource' => 'inventory_item', 'filters' => ['created_by' => 'x']]);
        $this->assertProblem(422, 'validation-failed', [['pointer' => '/filters/created_by', 'code' => 'undeclared_parameter', 'message' => 'This list has no filter named "created_by".']]);

        // 422: the List's own refusal, re-pointed into the body (`archived` with `supplier_id`).
        $this->export(['resource' => 'inventory_item', 'filters' => ['archived' => 'true', 'supplier_id' => Uuid::v4()->toRfc4122()]]);
        $this->assertProblem(422, 'validation-failed', [['pointer' => '/filters/archived', 'code' => 'mutually_exclusive', 'message' => 'The archived items are their own view: send archived without supplier_id.']]);

        // 422: the List's declared parameter validation (format uuid), as API Platform runs it on the query string.
        $this->export(['resource' => 'stock_movement', 'filters' => ['inventory_item_id' => 'not-a-uuid']]);
        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('/filters/inventory_item_id', $this->json()['errors'][0]['pointer']);
        self::assertSame('invalid_parameter', $this->json()['errors'][0]['code']);

        // 422: a non-string filter value; a list where the object belongs; an unserved sort; an unknown body field.
        $this->export(['resource' => 'inventory_item', 'filters' => ['archived' => true]]);
        $this->assertProblem(422, 'validation-failed', [['pointer' => '/filters/archived', 'code' => 'not_a_string', 'message' => 'Each filter is given as text, exactly as the list\'s address would carry it.']]);
        $this->export(['resource' => 'inventory_item', 'filters' => ['true']]);
        $this->assertProblem(422, 'validation-failed', [['pointer' => '/filters', 'code' => 'not_an_object', 'message' => 'filters must be an object of the list\'s filters.']]);
        $this->export(['resource' => 'inventory_item', 'sort' => 'name']);
        $this->assertProblem(422, 'validation-failed', [['pointer' => '/sort', 'code' => 'unserved_sort', 'message' => 'This list offers no other order.']]);
        $this->export(['resource' => 'inventory_item', 'view' => 'archived']);
        self::assertSame(422, $this->httpStatus(), 'The dropped `view` field (notice N-6) must be refused as unknown, never ignored.');
        self::assertStringStartsWith('application/problem+json', $this->contentType());

        // 403: a caller without the List's code -- one decision, before any row (E-1).
        $this->actAs(self::TENANT_A, ['inventory_item.view']);
        $this->export(['resource' => 'stock_movement']);
        self::assertSame(403, $this->httpStatus());
        self::assertStringStartsWith('application/problem+json', $this->contentType());
        self::assertSame('stock_movement.view', $this->json()['required_permission']);

        // 409: over the cap (the test cap; `ExportCapTest` pins the production figure).
        $this->actAs(self::TENANT_A, ['inventory_item.view']);
        $cap = $this->runner()->cap();
        for ($i = 2; $i <= $cap + 1; ++$i) {
            $this->seedItem(self::TENANT_A, \sprintf('SKU-%02d', $i));
        }
        $this->export(['resource' => 'inventory_item']);
        self::assertSame(409, $this->httpStatus());
        self::assertStringStartsWith('application/problem+json', $this->contentType());
        self::assertSame(ExportRunner::TOO_MANY_ROWS, $this->json()['rule']);
    }

    /**
     * TB-7, per source: what the List returns for a filter set is what the export holds, row for row and cell for cell,
     * once the documented escape is applied forward to the List. *Mutation:* apply `archived` only to the list (the
     * export drops it) → the archived set diverges → red.
     */
    #[Test]
    public function theExportRowSetEqualsTheListRowSet(): void
    {
        $w = $this->seedInventoryWorkspace();

        $cases = [
            'inventory_item' => [[], ['archived' => 'true'], ['archived' => 'false'], ['q' => 'SKU-B'], ['supplier_id' => $w['supplier']]],
            'stock_level' => [['storage_location_id' => $w['bin']], ['inventory_item_id' => $w['itemA']]],
            'stock_movement' => [[], ['inventory_item_id' => $w['itemA']]],
        ];

        self::assertSame(self::INVENTORY_FAMILY, array_keys($cases));
        foreach ($cases as $resource => $filterSets) {
            foreach ($filterSets as $filters) {
                $this->assertExportEqualsList($this->sources()->get($resource), $filters);
            }
        }
    }

    /** The resources {@see self::theExportRowSetEqualsTheListRowSet()} drives with hand-written cases (G6-C0). */
    private const INVENTORY_FAMILY = ['inventory_item', 'stock_level', 'stock_movement'];

    /**
     * The resources {@see self::eachFamilyExportsTheListsView()} drives (G6-C1), each through its List's shared fixture in
     * {@see ListOperationFixtures} — the same rows `ListEnvelopeTest`, `PaginationContractTest` and `SearchContractTest`
     * seed — plus a formula-led text cell ({@see self::plantAFormula()}).
     */
    private const FAMILIES = [
        'app_user' => 'ListUsers',
        'credit_note' => 'ListCreditNotes',
        'customer' => 'ListCustomers',
        'fulfilment' => 'ListFulfilments',
        'goods_receipt' => 'ListGoodsReceipts',
        'invoice' => 'ListInvoices',
        'permission' => 'ListPermissions',
        'purchase_order' => 'ListPurchaseOrders',
        'role' => 'ListRoles',
        'sales_order' => 'ListSalesOrders',
        'sales_return' => 'ListSalesReturns',
        'supplier' => 'ListSuppliers',
        'warehouse' => 'ListWarehouses',
    ];

    /**
     * `permission` is the one family no fixture can give a formula-led cell: the catalogue is global and seeded by the
     * owner's migration, never by a tenant (IA §6.2). The escape is still applied forward to its List, cell by cell.
     */
    private const NO_TENANT_WRITTEN_TEXT = ['permission'];

    /** @return iterable<string, array{string, string}> */
    public static function families(): iterable
    {
        foreach (self::FAMILIES as $resource => $list) {
            yield $resource => [$resource, $list];
        }
    }

    /** Two-way: every export source is driven by exactly one TB-7 test here, and no drive names a source that is gone. */
    #[Test]
    public function everySourceHasATb7Drive(): void
    {
        $driven = [...self::INVENTORY_FAMILY, ...array_keys(self::FAMILIES)];
        sort($driven, \SORT_STRING);

        self::assertSame(array_keys($this->sources()->all()), $driven, 'An export source has no TB-7 drive here, or a drive names a resource no source serves.');
    }

    /**
     * TB-7 for every family G6-C1 added, one family per run (a fresh database each). The List's shared fixture seeds two
     * rows (and, where the List has one, an Archived view); the filter sets are: none; each fixture view (a query string,
     * its `sort` moved into the body); each id/flag filter the fixture names, alone; and the fixture's `q` prefix. Every
     * set is compared cell by cell; a set may be empty (an id no row carries), but at least one must not be.
     *
     * Fixture obligations, asserted rather than trusted (design R1.2 G6-C1, "TB-7, null-reference and escaping fixtures"):
     * - **Escaping:** the actor's display name (every `…_by` reference and `ListUsers`' own row) and one text cell per
     *   family start like a formula, and some exported cell must carry the escape (`'` then a trigger). No G6-C1 family
     *   serves a signed decimal that can hold a negative, so the plain-number exemption is exercised by C0's movements.
     * - **Null references:** every reference the row class declares nullable (a `?…Ref` property: `created_by`,
     *   `received_by`, `invoice`, `credit_target`, `paid_by`, `sales_return`) is null on some exported row, all of its
     *   leaves empty cells, while the row stays as wide as the header.
     */
    #[Test]
    #[DataProvider('families')]
    public function eachFamilyExportsTheListsView(string $resource, string $list): void
    {
        $source = $this->sources()->get($resource);
        self::assertSame($list, $source->listOperationName());

        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET display_name = ? WHERE id = ?::uuid',
            ['=HYPERLINK("http://x","actor")', (string) $this->actorId]);
        [, $seed] = ($this->listFixtures()[$list])();
        $given = $seed(2);
        $this->plantAFormula($resource, $given);
        $given['views'] = [...$given['views'], ...$this->plantOutsiders($resource)];
        $this->assertTheCapReplacesThePageSize($source);

        $sets = [[[], null]];
        foreach ($given['views'] as $view) {
            parse_str($view, $query);
            $sort = isset($query['sort']) ? (string) $query['sort'] : null;
            unset($query['sort']);
            $sets[] = [array_map('strval', $query), $sort];
        }
        foreach ($given['filter'] as $name => $value) {
            $sets[] = [[$name => $value], null];
        }
        if (null !== $given['search']) {
            $sets[] = [['q' => $given['search']], null];
        }

        $rows = [];
        $views = [];
        foreach ($sets as [$filters, $sort]) {
            $exported = $this->assertExportEqualsList($source, $filters, $sort, allowEmpty: true);
            $views[] = json_encode($exported, \JSON_THROW_ON_ERROR);
            array_push($rows, ...$exported);
        }
        self::assertNotSame([], $rows, $resource.': every filter set exported nothing, so TB-7 held over nothing.');
        if (!\in_array($resource, self::NO_TENANT_WRITTEN_TEXT, true)) {
            // `g6-c1-red.md` M-1: some filter set must select a different row set from another, or a source that dropped
            // its filters would stay green. `permission` declares no filter at all.
            self::assertGreaterThan(1, \count(array_unique($views)), $resource.': every filter set exported the same rows, so no filter was shown to reach the read.');
        }

        $header = $this->renderer()->header($source->rowClass());
        if (!\in_array($resource, self::NO_TENANT_WRITTEN_TEXT, true)) {
            $escaped = array_filter(array_merge(...$rows), static fn (string $cell): bool => 1 === preg_match("/^'[=+\\-@\t\r\n]/", $cell));
            self::assertNotSame([], $escaped, $resource.': no exported cell carries the formula escape, so the fixture never exercised it.');
        }
        foreach (self::nullableReferences($source->rowClass()) as $reference) {
            $leaves = array_keys(array_filter($header, static fn (string $leaf): bool => str_starts_with($leaf, $reference.'.')));
            self::assertNotSame([], $leaves, $resource.': the nullable reference '.$reference.' has no columns.');
            $nullRows = array_filter($rows, static fn (array $row): bool => [] === array_filter(array_map(static fn (int $i): string => $row[$i], $leaves), static fn (string $c): bool => '' !== $c));
            self::assertNotSame([], $nullRows, \sprintf('%s: no exported row holds a null %s, so the fixture never exercised a null reference.', $resource, $reference));
        }
        foreach ($rows as $row) {
            self::assertCount(\count($header), $row, $resource.': a rendered row is not as wide as the header.');
        }
    }

    /**
     * **A caller-dependent column is served as the List serves it** (final RED M-5; design R1.2 G6-C0/C1). `ListSalesReturns`
     * serves `credit_blocked_by_payment` and `adjustment_decision_owed` only to an `invoice.view` holder and leaves the keys
     * ABSENT otherwise (`SalesReturnResource`, `SKIP_NULL_VALUES`). The export runs the list's reader for the same caller, so
     * for a holder those cells carry the value, and for a caller without `invoice.view` they are EMPTY — the header names
     * the same columns either way (they are in the public schema), every row is as wide as the header, the export still
     * equals that caller's List cell for cell, and the invoice-only filter is refused as the list refuses it, re-pointed.
     * `ListCreditNotes`' caller-dependent members (`return_decisions[]`) cannot be observed this way: the List — and so its
     * export — is gated on `invoice.view` itself, so a caller without it is refused before any row (403 naming that code).
     * *Mutation:* the source reads as an `invoice.view` holder regardless of the caller → the cells are not empty → red.
     */
    #[Test]
    public function aCallerDependentColumnIsServedAsTheListServesIt(): void
    {
        [, $seed] = ($this->listFixtures()['ListSalesReturns'])();
        $seed(2);
        $source = $this->sources()->get('sales_return');
        $header = $this->renderer()->header($source->rowClass());
        $withheld = array_keys(array_intersect($header, ['credit_blocked_by_payment', 'adjustment_decision_owed']));
        self::assertCount(2, $withheld, 'The header no longer names both invoice-only columns.');

        $rows = $this->assertExportEqualsList($source, []);
        foreach ($rows as $row) {
            self::assertSame(['false', 'false'], [$row[$withheld[0]], $row[$withheld[1]]], 'An invoice.view holder is not served the invoice-only cells.');
        }

        $this->actAs(self::TENANT_A, ['sales_return.view']);
        $rows = $this->assertExportEqualsList($source, []);
        self::assertSame($header, $this->exportRows()[0], 'The header changed with the caller.');
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertCount(\count($header), $row);
            self::assertSame(['', ''], [$row[$withheld[0]], $row[$withheld[1]]], 'A caller without invoice.view was served an invoice-only cell.');
        }

        $this->export(['resource' => 'sales_return', 'filters' => ['credit_blocked_by_payment' => 'true']]);
        self::assertSame(422, $this->httpStatus());
        self::assertSame([['pointer' => '/filters/credit_blocked_by_payment', 'code' => 'not_permitted',
            'message' => 'This filter needs the permission to read invoices (invoice.view).']], $this->json()['errors']);

        $this->export(['resource' => 'credit_note']);
        self::assertSame(403, $this->httpStatus());
        self::assertSame('invoice.view', $this->json()['required_permission']);
    }

    /**
     * The properties of `$rowClass` that are a nullable reference — a single class type (not an enum, not a date) that
     * allows null. The renderer flattens each into dotted columns.
     *
     * @param class-string $rowClass
     *
     * @return list<string> their serialized names
     */
    private static function nullableReferences(string $rowClass): array
    {
        $names = [];
        foreach ((new \ReflectionClass($rowClass))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $type = $property->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin() || !$type->allowsNull() || $property->isStatic()) {
                continue;
            }
            $class = $type->getName();
            if (enum_exists($class) || is_a($class, \DateTimeInterface::class, true)) {
                continue;
            }
            $attribute = $property->getAttributes(\Symfony\Component\Serializer\Attribute\SerializedName::class)[0] ?? null;
            $names[] = null === $attribute ? $property->getName() : (string) $attribute->newInstance()->serializedName;
        }

        return $names;
    }

    /**
     * `g6-c1-red.md` M-1: a row OUTSIDE each filter, for the families whose fixture filters select every seeded row, and an
     * archived row (with its `archived=true` view) for the three bounded families whose reader SQL this commit changed.
     *
     * @return list<string> extra fixture views (query strings)
     */
    private function plantOutsiders(string $resource): array
    {
        $uuid = static fn (): string => Uuid::v4()->toRfc4122();
        switch ($resource) {
            case 'warehouse':
                $this->insertAsOwnerForTenant(self::TENANT_A, "INSERT INTO warehouse (id, tenant_id, code, name, country_code) VALUES (?::uuid, ?::uuid, 'ZZ-OUT', 'Outside the search', 'DE')", [$uuid(), self::TENANT_A]);
                $this->insertAsOwnerForTenant(self::TENANT_A, "INSERT INTO warehouse (id, tenant_id, code, name, country_code, deleted_at) VALUES (?::uuid, ?::uuid, 'WH-ENV-GONE', 'Archived warehouse', 'DE', '2026-10-01 10:00:00+00')", [$uuid(), self::TENANT_A]);

                return ['archived=true'];
            case 'role':
                $this->insertAsOwnerForTenant(self::TENANT_A, "INSERT INTO role (id, tenant_id, name, deleted_at) VALUES (?::uuid, ?::uuid, 'ROLE-ENV-GONE', '2026-10-01 10:00:00+00')", [$uuid(), self::TENANT_A]);

                return ['archived=true'];
            case 'app_user':
                $this->insertAsOwnerForTenant(self::TENANT_A, "INSERT INTO app_user (id, tenant_id, email, password_hash, display_name, deleted_at) VALUES (?::uuid, ?::uuid, 'user-env-gone@example.test', 'not-a-hash', 'Archived user', '2026-10-01 10:00:00+00')", [$uuid(), self::TENANT_A]);

                return ['archived=true'];
            case 'fulfilment':
                $order = $this->seedSalesOrder(self::TENANT_A, 'SO-155900', null, null, SalesOrder::STATUS_CONFIRMED, '2026-01-01T00:00:00+00:00');
                $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                    INSERT INTO fulfilment (id, tenant_id, sales_order_id, warehouse_id, fulfilment_number, shipped_by_user_id,
                                            idempotency_key, request_digest)
                    SELECT ?::uuid, so.tenant_id, so.id, so.warehouse_id, 'FUL-600001', ?::uuid,
                           encode(sha256(gen_random_uuid()::text::bytea), 'hex'), encode(sha256(gen_random_uuid()::text::bytea), 'hex')
                      FROM sales_order so WHERE so.id = ?::uuid
                    SQL, [$uuid(), (string) $this->actorId, $order]);

                return [];
            case 'goods_receipt':
                $order = $this->seedOrder(self::TENANT_A, 'PO-335900', $this->seedSupplier(self::TENANT_A, 'Outside Receipts'),
                    $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH Outside Receipts'), status: PurchaseOrder::STATUS_ORDERED);
                $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                    INSERT INTO goods_receipt (id, tenant_id, purchase_order_id, warehouse_id, receipt_number, received_at,
                                               idempotency_key, request_digest)
                    VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 'GR-600001', '2026-02-01T00:00:00+00:00',
                            encode(sha256(gen_random_uuid()::text::bytea), 'hex'), encode(sha256(gen_random_uuid()::text::bytea), 'hex'))
                    SQL, [$uuid(), self::TENANT_A, $order, $warehouse]);

                return [];
            default:
                return [];
        }
    }

    /**
     * The cap replaces the List's page size, exactly, for every source (paged or bounded): read through `prepare()` with a
     * cap far above any List's `MAXIMUM_LIMIT` (so the parse must not bound it — `g6-c1-red.md` L-4), then with the view's
     * exact size (not over) and one less (over, holding the cap's rows). *Mutation:* a provider's `$limit ??=` → `$limit =`
     * (the default page size wins) → the first read is over → red; a bounded reader's `$limit + 1` → `$limit` → the read
     * one under the size is not over → red. Read before any request, while the non-HTTP identity `actAs()` set is current.
     */
    private function assertTheCapReplacesThePageSize(ExportSource $source): void
    {
        $read = fn (int $cap): \App\Platform\Http\Export\ExportedRows => $this->tenantContext()->run(self::TENANT_A,
            static fn (): \App\Platform\Http\Export\ExportedRows => ($source->prepare([], $cap))());

        $whole = $read(10000);
        self::assertFalse($whole->overCap, $source->resource().': a cap of 10,000 is over a fixture-sized view, so the parse bounded the cap.');
        $size = \count($whole->rows);
        self::assertGreaterThanOrEqual(2, $size, $source->resource().': the fixture view is too small to test the cap.');

        $at = $read($size);
        self::assertSame([false, $size], [$at->overCap, \count($at->rows)], $source->resource().': a cap equal to the view is over, or short.');
        $under = $read($size - 1);
        self::assertSame([true, $size - 1], [$under->overCap, \count($under->rows)], $source->resource().': a cap one under the view is not over.');
    }

    /**
     * One formula-led text cell per family, on a row its fixture seeded (a name the List serves, or the receiver of one
     * receipt). The families whose only text is a `…_by` reference get it from the actor's display name instead.
     *
     * @param array{filter: array<string, string>, search: ?string, views: list<string>} $given
     */
    private function plantAFormula(string $resource, array $given): void
    {
        $sql = match ($resource) {
            'purchase_order' => ['UPDATE supplier SET name = ? WHERE id = ?::uuid', ['=SUM(1,1)', $given['filter']['supplier_id'] ?? '']],
            'sales_order', 'invoice' => ['UPDATE customer SET name = ? WHERE id = ?::uuid', ['=SUM(1,1)', $given['filter']['customer_id'] ?? '']],
            'goods_receipt' => ["UPDATE goods_receipt SET received_by_user_id = ?::uuid WHERE receipt_number = 'GR-900001'", [(string) $this->actorId]],
            'warehouse' => ["UPDATE warehouse SET name = ? WHERE code = 'WH-ENV-1'", ['@Warehouse']],
            'supplier' => ["UPDATE supplier SET name = ? WHERE name = 'SUP-ENV-2'", ['-2+3 Supplies']],
            'customer' => ["UPDATE customer SET name = ? WHERE name = 'CUS-ENV-2'", ['+44 Customer']],
            // A role name may hold no control character (`role_name_shape`), so its formula starts with `=`, not a TAB.
            'role' => ["UPDATE role SET name = ? WHERE name = 'ROLE-ENV-2'", ['=ROLE(1)']],
            // The actor's display name already reaches these families' `…_by` columns (and `ListUsers`' own row).
            'fulfilment', 'sales_return', 'credit_note', 'app_user', 'permission' => null,
        };
        if (null !== $sql) {
            $this->insertAsOwnerForTenant(self::TENANT_A, $sql[0], $sql[1]);
        }
    }

    /** API-14a, both directions: `filters` members are exactly the List's declared query parameters minus paging/sort. */
    #[Test]
    public function filterMembersAreTheListOperationsQueryParameters(): void
    {
        foreach ($this->sources()->all() as $resource => $source) {
            // The registry's own operation, read independently of the port under test.
            $list = TransactionBoundaryTest::contractOperations()[$source->listOperationName()];
            $declared = [];
            foreach ($list->getParameters() ?? [] as $key => $parameter) {
                $declared[] = (string) ($parameter->getKey() ?? $key);
            }
            $expected = array_values(array_diff($declared, ['limit', 'after', 'before', 'sort']));
            sort($expected);

            self::assertSame($expected, array_keys($this->declarations()->filterMembers($source)), $resource.': the export\'s filter members are not the List\'s query parameters.');

            // Every member is accepted (as a filter name, whatever its value says), and a name outside them is refused.
            foreach ($expected as $member) {
                $this->export(['resource' => $resource, 'filters' => [$member => '']]);
                $errors = 422 === $this->httpStatus() ? $this->json()['errors'] ?? [] : [];
                self::assertNotContains('undeclared_parameter', array_column($errors, 'code'), $resource.': the declared filter "'.$member.'" was refused as undeclared.');
            }
            foreach (['limit', 'after', 'before', 'id', 'tenant_id'] as $outside) {
                $this->export(['resource' => $resource, 'filters' => [$outside => '1']]);
                self::assertSame(422, $this->httpStatus(), $resource.': "'.$outside.'" was accepted as a filter.');
                self::assertContains('undeclared_parameter', array_column($this->json()['errors'], 'code'));
            }
        }
    }

    /** API-10 at depth: no List an export can name declares a query parameter called `id`, so `filters` cannot carry one. */
    #[Test]
    public function noQueryParameterIsNamedId(): void
    {
        $names = [];
        foreach ($this->sources()->all() as $source) {
            $names = [...$names, ...array_keys($this->declarations()->filterMembers($source))];
        }

        self::assertNotSame([], $names, 'No export source declares a filter, so this holds over nothing.');
        self::assertNotContains('id', $names);
    }

    /**
     * G5-D0 carry (1), M-3: the processor acts ONLY on the gate's resolution. A `_derived_permission` planted for another
     * resource (after the gate, before the controller) is a 500 -- never an export of `inventory_item` under a code nobody
     * checked for it -- and nothing is written to the trail.
     */
    #[Test]
    public function aBodyReadTwoWaysIsAServerErrorNotAnExport(): void
    {
        $this->seedItem(self::TENANT_A, 'SKU-1');
        $dispatcher = self::getContainer()->get('event_dispatcher');
        \assert($dispatcher instanceof \Symfony\Component\EventDispatcher\EventDispatcherInterface);
        $plant = static function (ControllerEvent $event): void {
            $event->getRequest()->attributes->set(PermissionRequirements::REQUEST_ATTRIBUTE, ['resource' => 'stock_level', 'code' => 'stock_level.view']);
        };
        $dispatcher->addListener(KernelEvents::CONTROLLER, $plant, -1000);

        try {
            $this->export(['resource' => 'inventory_item']);
        } finally {
            $dispatcher->removeListener(KernelEvents::CONTROLLER, $plant);
        }

        self::assertSame(500, $this->httpStatus(), 'A body resolved for one resource and acted on as another must be a 500.');
        self::assertSame([], $this->auditRowsOf('data.export.completed'));
    }

    /**
     * **Nothing an export renders can lazy-load after COMMIT** (P-7; `g6-c0-red.md` M-4 -- the guard the design names did
     * not exist). Rows are read inside the transaction and normalized after it, so a row class the ORM maps (or a proxy
     * among the rows) could issue SQL under no tenancy transaction. Every source's row class, and every class its header
     * recurses into, is transient to the entity manager; and the rows a TB-7 read returns are no `Proxy`. *Mutation:* a
     * source whose `rowClass()` is an ORM entity → red.
     */
    #[Test]
    public function noExportedRowCanLazyLoadAfterCommit(): void
    {
        $bin = $this->seedInventoryWorkspace()['bin'];
        $metadata = $this->em()->getMetadataFactory();
        $checked = 0;
        foreach ($this->sources()->all() as $resource => $source) {
            self::assertTrue($metadata->isTransient($source->rowClass()), $resource.': its row class is ORM-mapped and could lazy-load after commit.');
            $rows = $this->tenantContext()->run(self::TENANT_A, fn (): \App\Platform\Http\Export\ExportedRows => ($source->prepare(
                'stock_level' === $resource ? ['storage_location_id' => $bin] : [], 100,
            ))());
            foreach ($rows->rows as $row) {
                self::assertNotInstanceOf(\Doctrine\Persistence\Proxy::class, $row, $resource.': a row is a Doctrine proxy.');
                self::assertTrue($metadata->isTransient($row::class), $resource.': a row is an ORM entity.');
                ++$checked;
            }
        }
        self::assertGreaterThan(0, $checked, 'No exported row was inspected.');
    }

    /**
     * @param array<string, string> $filters
     *
     * @return list<list<string>> the exported data rows (header dropped)
     */
    private function assertExportEqualsList(ExportSource $source, array $filters, ?string $sort = null, bool $allowEmpty = false): array
    {
        $resource = $source->resource();
        $path = $this->declarations()->path($source);

        $items = $this->listAll($path, $filters + (null === $sort ? [] : ['sort' => $sort]));
        $this->export(['resource' => $resource] + ([] === $filters ? [] : ['filters' => $filters]) + (null === $sort ? [] : ['sort' => $sort]));
        $rows = $this->exportRows();

        $header = $this->renderer()->header($source->rowClass());
        self::assertSame($header, $rows[0], $resource.': the header row is not the derived header.');

        $expected = array_map(static fn (array $item): array => array_map(
            static fn (string $leaf): string => CsvCell::escape(CsvExportRenderer::cell($item, $leaf)),
            $header,
        ), $items);

        self::assertSame($expected, \array_slice($rows, 1), \sprintf('%s %s %s: the export is not the List\'s view.', $resource, json_encode($filters), $sort ?? ''));
        if (!$allowEmpty) {
            self::assertNotSame([], $items, \sprintf('%s %s: the fixture left this view empty, so the comparison holds over nothing.', $resource, json_encode($filters)));
        }

        return \array_slice($rows, 1);
    }

    /**
     * Items (one archived, one with no supplier, one whose name and supplier name look like formulas), one bin, stock
     * levels, and movements in and out (a negative quantity that must stay bare).
     *
     * @return array{supplier: string, bin: string, itemA: string, itemB: string}
     */
    private function seedInventoryWorkspace(): array
    {
        $supplier = $this->seedSupplier(self::TENANT_A, '=HYPERLINK("http://x","y")');
        $itemA = $this->seedItem(self::TENANT_A, 'SKU-A', $supplier);
        $itemB = $this->seedItem(self::TENANT_A, 'SKU-B');
        $gone = $this->seedItem(self::TENANT_A, 'SKU-GONE');
        $this->insertAsOwnerForTenant(self::TENANT_A, "UPDATE inventory_item SET name = '-2+3, \"quoted\"' WHERE id = ?::uuid", [$itemB]);
        $this->insertAsOwnerForTenant(self::TENANT_A, "UPDATE inventory_item SET deleted_at = '2026-10-01 10:00:00+00' WHERE id = ?::uuid", [$gone]);

        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH-1');
        $bin = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'A-01');
        foreach ([[$itemA, '12.500000'], [$itemB, '0.000000']] as [$item, $qty]) {
            $this->insertAsOwnerForTenant(self::TENANT_A,
                'INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?)',
                [Uuid::v4()->toRfc4122(), self::TENANT_A, $item, $bin, $qty]);
        }
        foreach ([[$itemA, '14.500000', '2026-10-01 09:00:00+00'], [$itemA, '-2.000000', '2026-10-02 09:00:00+00'], [$itemB, '1.000000', '2026-10-03 09:00:00+00']] as [$item, $qty, $at]) {
            $this->insertAsOwnerForTenant(self::TENANT_A,
                "INSERT INTO stock_movement (id, tenant_id, inventory_item_id, storage_location_id, quantity, reason, occurred_at) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, 'adjustment', ?::timestamptz)",
                [Uuid::v4()->toRfc4122(), self::TENANT_A, $item, $bin, $qty, $at]);
        }

        return ['supplier' => $supplier, 'bin' => $bin, 'itemA' => $itemA, 'itemB' => $itemB];
    }

    /** @param list<array{pointer: string, code: string, message: string}> $errors */
    private function assertProblem(int $status, string $type, array $errors): void
    {
        $content = (string) $this->client()->getResponse()->getContent();
        self::assertSame($status, $this->httpStatus(), $content);
        self::assertStringStartsWith('application/problem+json', $this->contentType());
        self::assertStringEndsWith($type, (string) $this->json()['type']);
        self::assertSame($errors, $this->json()['errors']);
    }

    private function sources(): ExportSources
    {
        $sources = self::getContainer()->get(ExportSources::class);
        \assert($sources instanceof ExportSources);

        return $sources;
    }

    private function declarations(): ListDeclarations
    {
        $declarations = self::getContainer()->get(ListDeclarations::class);
        \assert($declarations instanceof ListDeclarations);

        return $declarations;
    }

    private function renderer(): CsvExportRenderer
    {
        $renderer = self::getContainer()->get(CsvExportRenderer::class);
        \assert($renderer instanceof CsvExportRenderer);

        return $renderer;
    }

    private function runner(): ExportRunner
    {
        $runner = self::getContainer()->get(ExportRunner::class);
        \assert($runner instanceof ExportRunner);

        return $runner;
    }
}

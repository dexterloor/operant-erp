<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\AuditLog\Application\ExportRunner;
use App\Modules\AuditLog\Application\ExportSources;
use App\Platform\Http\Export\CsvCell;
use App\Platform\Http\Export\CsvExportRenderer;
use App\Platform\Http\Export\ExportSource;
use App\Platform\Http\Export\ListDeclarations;
use App\Platform\Security\PermissionRequirements;
use App\Tests\Support\GeneratedSpec;
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
 * - `theExportRowSetEqualsTheListRowSet` — TB-7, per source, over filter sets that include `archived`: the export equals
 *   the List, cell by cell, after the documented escape is applied FORWARD to the List (`escape(list) == export`, design
 *   §6.6 — `escape` is not injective, so it is never reversed).
 * - `filterMembersAreTheListOperationsQueryParameters` (API-14a, both directions, from the registry) and
 *   `noQueryParameterIsNamedId` (API-10 at the expanded `filters`).
 * - `aBodyReadTwoWaysIsAServerErrorNotAnExport` — G5-D0 carry (1), a planted mismatched `_derived_permission`.
 */
final class ExportContractTest extends PurchaseOrderTestCase
{
    use ExportTestSupport;

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

        $driven = [];
        foreach ($this->sources()->all() as $resource => $source) {
            self::assertArrayHasKey($resource, $cases, \sprintf('The export source "%s" has no TB-7 case here.', $resource));
            foreach ($cases[$resource] as $filters) {
                $this->assertExportEqualsList($source, $filters);
                $driven[] = $resource;
            }
        }

        self::assertSame(array_keys($cases), array_values(array_unique($driven)), 'A TB-7 case was written for a source that no longer exists.');
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
     */
    private function assertExportEqualsList(ExportSource $source, array $filters): void
    {
        $resource = $source->resource();
        $path = $this->declarations()->path($source);

        $items = $this->listAll($path, $filters);
        $this->export(['resource' => $resource] + ([] === $filters ? [] : ['filters' => $filters]));
        $rows = $this->exportRows();

        $header = $this->renderer()->header($source->rowClass());
        self::assertSame($header, $rows[0], $resource.': the header row is not the derived header.');

        $expected = array_map(static fn (array $item): array => array_map(
            static fn (string $leaf): string => CsvCell::escape(CsvExportRenderer::cell($item, $leaf)),
            $header,
        ), $items);

        self::assertSame($expected, \array_slice($rows, 1), \sprintf('%s %s: the export is not the List\'s view.', $resource, json_encode($filters)));
        self::assertNotSame([], $items, \sprintf('%s %s: the fixture left this view empty, so the comparison holds over nothing.', $resource, json_encode($filters)));
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

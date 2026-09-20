<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\HttpOperation;
use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;

/**
 * The list domain and one fixture per list, shared by every registry-wide list
 * check — `ListEnvelopeTest` (C5 batch B) and batch C's `PaginationContractTest`,
 * `QueryParameterContractTest`, `SortManifestTest` and `SearchContractTest`.
 *
 * **The domain is derived:** every `GET` in
 * `TransactionBoundaryTest::contractOperations()` whose operationId verb is
 * `List` (§12.3; `OperationIdTest` holds the name to §4.2's `Verb / Resource`).
 * The fixtures are the one per-operation part, because a row cannot be seeded
 * generically, and {@see self::listOperations()} holds their keys equal to the
 * domain every time it is called, so a list built tomorrow is red in every
 * consumer until it is given one.
 *
 * A fixture returns the path to read (which exists before anything is seeded, so
 * the empty page is readable first) and a seed step. The seed step returns what
 * a consumer needs to drive the list's own parameters without restating the
 * rows: `supplier` (a uuid the seeded rows carry, where the table has one),
 * `search` (a prefix every seeded row's searchable column starts with) and
 * `views` (query strings each producing a page of more than one row — a
 * consumer that uses them holds the `sort` values they name equal to the
 * operation's declared enum).
 *
 * @phpstan-require-extends PurchaseOrderTestCase
 */
trait ListOperationFixtures
{
    /**
     * @return array<string, HttpOperation>
     */
    protected function listOperations(): array
    {
        $lists = array_filter(
            TransactionBoundaryTest::contractOperations(),
            static fn (HttpOperation $operation, string $name): bool => 'GET' === $operation->getMethod() && 1 === preg_match('/^List[A-Z]/', $name),
            \ARRAY_FILTER_USE_BOTH,
        );
        ksort($lists);

        self::assertNotSame([], $lists, 'The registry produced no list operation, so nothing was examined.');

        $fixtures = array_keys($this->listFixtures());
        sort($fixtures);
        self::assertSame(array_keys($lists), $fixtures, 'Every list operation in the registry must have a fixture here, and only those.');

        return $lists;
    }

    /** The API path of an operation, without the `/api/v1` base. */
    protected static function pathOf(HttpOperation $operation): string
    {
        return str_replace(self::BASE, '', (string) $operation->getUriTemplate());
    }

    /** @return list<string> the parameter names the operation declares */
    protected static function declaredParameters(HttpOperation $operation): array
    {
        $declared = [];
        foreach ($operation->getParameters() ?? [] as $key => $parameter) {
            $declared[] = (string) ($parameter->getKey() ?? $key);
        }

        return $declared;
    }

    /**
     * @return array<string, callable(): array{0: string, 1: callable(int): array{supplier: ?string, search: ?string, views: list<string>}}>
     */
    protected function listFixtures(): array
    {
        return [
            'ListItems' => function (): array {
                $supplier = $this->seedSupplier(self::TENANT_A, 'Acme Items');

                return ['/items', function (int $rows) use ($supplier): array {
                    for ($i = 1; $i <= $rows; ++$i) {
                        $this->seedItem(self::TENANT_A, 'SKU-ENV-'.$i, $supplier);
                    }

                    return ['supplier' => $supplier, 'search' => 'SKU-ENV', 'views' => ['']];
                }];
            },
            'ListPurchaseOrderLines' => function (): array {
                $supplier = $this->seedSupplier(self::TENANT_A, 'Acme Lines');
                $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH Lines');
                $order = $this->seedOrder(self::TENANT_A, 'PO-ENV-LINES', $supplier, $warehouse);

                return ['/purchase-orders/'.$order.'/lines', function (int $rows) use ($order): array {
                    for ($i = 1; $i <= $rows; ++$i) {
                        $this->seedLine(self::TENANT_A, $order, $this->seedItem(self::TENANT_A, 'SKU-ENV-L'.$i), $i);
                    }

                    return ['supplier' => null, 'search' => null, 'views' => ['']];
                }];
            },
            'ListSalesOrderLines' => function (): array {
                $order = $this->seedSalesOrder(self::TENANT_A, 'SO-ENV-LINES');

                return ['/sales-orders/'.$order.'/lines', function (int $rows) use ($order): array {
                    for ($i = 1; $i <= $rows; ++$i) {
                        $this->seedSalesOrderLine(self::TENANT_A, $order, $this->seedItem(self::TENANT_A, 'SKU-ENV-SL'.$i), $i);
                    }

                    // Bounded (TB-1c): no `supplier`-shaped filter, no searchable column TB-5 names, and
                    // one view, because §10.2's exclusion 2 takes it out of `sort`'s domain on both
                    // sides. The same three nulls `ListPurchaseOrderLines` carries, for the same rule.
                    return ['supplier' => null, 'search' => null, 'views' => ['']];
                }];
            },
            'ListPurchaseOrders' => function (): array {
                $supplier = $this->seedSupplier(self::TENANT_A, 'Acme Orders');
                $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH Orders');

                return ['/purchase-orders', function (int $rows) use ($supplier, $warehouse): array {
                    // Every row is `ordered` with a distinct `ordered_at`, so the
                    // default view, the Open segment and the supplier view all
                    // hold every row and every sort key is non-null.
                    for ($i = 1; $i <= $rows; ++$i) {
                        $this->seedOrder(self::TENANT_A, 'PO-ENV-'.$i, $supplier, $warehouse,
                            status: PurchaseOrder::STATUS_ORDERED, orderedAt: \sprintf('2026-01-%02dT00:00:00+00:00', $i));
                    }

                    return ['supplier' => $supplier, 'search' => 'PO-ENV', 'views' => ['', 'status=open&sort=ordered_at', 'sort=order_number']];
                }];
            },
        ];
    }
}

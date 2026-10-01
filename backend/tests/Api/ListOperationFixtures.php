<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\HttpOperation;
use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Modules\Sales\Infrastructure\Entity\SalesOrder;

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
     * @return array<string, callable(): array{0: string, 1: callable(int): array{filter: array<string, string>, search: ?string, views: list<string>}}>
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

                    return ['filter' => ['supplier_id' => $supplier], 'search' => 'SKU-ENV', 'views' => ['']];
                }];
            },
            'ListPurchaseOrderLines' => function (): array {
                $supplier = $this->seedSupplier(self::TENANT_A, 'Acme Lines');
                $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH Lines');
                $order = $this->seedOrder(self::TENANT_A, 'PO-112000', $supplier, $warehouse);

                return ['/purchase-orders/'.$order.'/lines', function (int $rows) use ($order): array {
                    for ($i = 1; $i <= $rows; ++$i) {
                        $this->seedLine(self::TENANT_A, $order, $this->seedItem(self::TENANT_A, 'SKU-ENV-L'.$i), $i);
                    }

                    return ['filter' => [], 'search' => null, 'views' => ['']];
                }];
            },
            'ListFulfilments' => function (): array {
                $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH Fulfilments');
                $order = $this->seedSalesOrder(self::TENANT_A, 'SO-155000', null, $warehouse,
                    SalesOrder::STATUS_CONFIRMED, '2026-01-01T00:00:00+00:00');

                return ['/fulfilments', function (int $rows) use ($order, $warehouse): array {
                    // Line-less fulfilments of ONE order, as the owner, each with a distinct number and shipment
                    // time, so both views (the register and the order's) hold every row. Numbered from
                    // `FUL-900001` so they cannot collide with a fulfilment a success recipe booked through the
                    // API earlier in the same test (the writer mints `FUL-000001` upwards).
                    for ($i = 1; $i <= $rows; ++$i) {
                        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                            INSERT INTO fulfilment (id, tenant_id, sales_order_id, warehouse_id, fulfilment_number, shipped_at,
                                                    shipped_by_user_id, idempotency_key, request_digest)
                            VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, ?::timestamptz, ?::uuid,
                                    encode(sha256(gen_random_uuid()::text::bytea), 'hex'),
                                    encode(sha256(gen_random_uuid()::text::bytea), 'hex'))
                            SQL, [\Symfony\Component\Uid\Uuid::v4()->toRfc4122(), self::TENANT_A, $order, $warehouse,
                                \sprintf('FUL-9%05d', $i), \sprintf('2026-01-%02dT00:00:00+00:00', $i), (string) $this->actorId]);
                    }

                    // `sales_order_id` is the only filter; TB-5 names `fulfilment_number`; no `sort` (IA §5.6
                    // gives each view one order), so the two views are the register and the order's.
                    return ['filter' => ['sales_order_id' => $order], 'search' => 'FUL-9',
                        'views' => ['', 'sales_order_id='.$order]];
                }];
            },
            'ListSalesReturns' => function (): array {
                $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH Returns');
                $order = $this->seedSalesOrder(self::TENANT_A, 'SO-153000', null, $warehouse,
                    SalesOrder::STATUS_CONFIRMED, '2026-01-01T00:00:00+00:00');

                return ['/sales-returns', function (int $rows) use ($order, $warehouse): array {
                    // Sales step 7 R3. Line-less returns of ONE shipment, as the owner, each with a distinct number, so the
                    // register and the shipment's view hold every row. Numbered from `RET-900001` so they cannot collide
                    // with a return a success recipe recorded through the API (`RET-000001` upwards).
                    $fulfilment = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
                    $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                        INSERT INTO fulfilment (id, tenant_id, sales_order_id, warehouse_id, fulfilment_number,
                                                shipped_by_user_id, idempotency_key, request_digest)
                        VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 'FUL-700001', ?::uuid,
                                encode(sha256(gen_random_uuid()::text::bytea), 'hex'), encode(sha256(gen_random_uuid()::text::bytea), 'hex'))
                        SQL, [$fulfilment, self::TENANT_A, $order, $warehouse, (string) $this->actorId]);
                    for ($i = 1; $i <= $rows; ++$i) {
                        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                            INSERT INTO sales_return (id, tenant_id, fulfilment_id, sales_return_number, reason, returned_at,
                                                      recorded_by_user_id, deducts, idempotency_key, request_digest)
                            VALUES (?::uuid, ?::uuid, ?::uuid, ?, 'customer_returned', now(), ?::uuid, false,
                                    encode(sha256(gen_random_uuid()::text::bytea), 'hex'), encode(sha256(gen_random_uuid()::text::bytea), 'hex'))
                            SQL, [\Symfony\Component\Uid\Uuid::v4()->toRfc4122(), self::TENANT_A, $fulfilment,
                                \sprintf('RET-9%05d', $i), (string) $this->actorId]);
                    }

                    // `fulfilment_id` is the id filter that holds every row (`invoice_id` names a stamp none of them has);
                    // TB-5 names `sales_return_number`; one order, so one view.
                    return ['filter' => ['fulfilment_id' => $fulfilment, 'invoice_id' => \Symfony\Component\Uid\Uuid::v4()->toRfc4122(),
                        'creditable' => 'false', 'deducts' => 'false'], 'search' => 'RET-9', 'views' => ['']];
                }];
            },
            'ListInvoices' => function (): array {
                $customer = $this->seedCustomer(self::TENANT_A, 'Acme Invoices');
                $order = $this->seedSalesOrder(self::TENANT_A, 'SO-154000', $customer, null,
                    SalesOrder::STATUS_CONFIRMED, '2026-01-01T00:00:00+00:00');

                return ['/invoices', function (int $rows) use ($order, $customer): array {
                    // One shipment per invoice (at most one live invoice per shipment), every invoice `issued` for ONE
                    // customer, with distinct `issued_at` and `due_at`, so the register, Unpaid by `due_at` and the
                    // customer view by `issued_at` all hold every row. As the owner; numbered from `INV-900001` so they
                    // cannot collide with an invoice a success recipe issued through the API (`INV-000001` upwards).
                    $first = null;
                    for ($i = 1; $i <= $rows; ++$i) {
                        $fulfilment = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
                        $first ??= $fulfilment;
                        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                            INSERT INTO fulfilment (id, tenant_id, sales_order_id, warehouse_id, fulfilment_number,
                                                    shipped_by_user_id, idempotency_key, request_digest)
                            SELECT ?::uuid, so.tenant_id, so.id, so.warehouse_id, ?, ?::uuid,
                                   encode(sha256(gen_random_uuid()::text::bytea), 'hex'), encode(sha256(gen_random_uuid()::text::bytea), 'hex')
                              FROM sales_order so WHERE so.id = ?::uuid
                            SQL, [$fulfilment, \sprintf('FUL-8%05d', $i), (string) $this->actorId, $order]);
                        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                            INSERT INTO invoice (id, tenant_id, fulfilment_id, sales_order_id, customer_id, invoice_number,
                                                 currency_code, total_amount, issued_at, due_at, issued_by_user_id)
                            SELECT ?::uuid, f.tenant_id, f.id, so.id, so.customer_id, ?, so.currency_code, 10,
                                   ?::timestamptz, ?::timestamptz + interval '30 days', ?::uuid
                              FROM fulfilment f JOIN sales_order so ON so.tenant_id = f.tenant_id AND so.id = f.sales_order_id
                             WHERE f.id = ?::uuid
                            SQL, [\Symfony\Component\Uid\Uuid::v4()->toRfc4122(), \sprintf('INV-9%05d', $i),
                                \sprintf('2026-01-%02dT00:00:00+00:00', $i), \sprintf('2026-01-%02dT00:00:00+00:00', $i),
                                (string) $this->actorId, $fulfilment]);
                    }

                    // Both id filters; TB-5 names `invoice_number`; the three views decode exactly the sort enum.
                    return ['filter' => ['customer_id' => $customer, 'fulfilment_id' => (string) $first], 'search' => 'INV-9',
                        'views' => ['sort=invoice_number', 'status=unpaid&sort=due_at', 'customer_id='.$customer.'&sort=issued_at']];
                }];
            },
            'ListPermissions' => function (): array {
                // The vocabulary is global and seeded (IA §6.2), so there is nothing to seed and the "empty"
                // path is already populated. Bounded (TB-1c): no filter, no searchable column TB-5 names, one view.
                return ['/permissions', static fn (int $rows): array => ['filter' => [], 'search' => null, 'views' => ['']]];
            },
            'ListRoles' => function (): array {
                return ['/roles', function (int $rows): array {
                    for ($i = 1; $i <= $rows; ++$i) {
                        $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, ?)',
                            [\Symfony\Component\Uid\Uuid::v4()->toRfc4122(), self::TENANT_A, 'ROLE-ENV-'.$i]);
                    }

                    // Bounded (TB-1c): no filter; TB-5 names `role.name`, so `q` is probed with the seeded prefix;
                    // one view, because §10.2's exclusion 2 takes a bounded row out of `sort`'s domain.
                    return ['filter' => [], 'search' => 'ROLE-ENV', 'views' => ['']];
                }];
            },
            'ListSalesOrderLines' => function (): array {
                $order = $this->seedSalesOrder(self::TENANT_A, 'SO-156000');

                return ['/sales-orders/'.$order.'/lines', function (int $rows) use ($order): array {
                    for ($i = 1; $i <= $rows; ++$i) {
                        $this->seedSalesOrderLine(self::TENANT_A, $order, $this->seedItem(self::TENANT_A, 'SKU-ENV-SL'.$i), $i);
                    }

                    // Bounded (TB-1c): no `supplier`-shaped filter, no searchable column TB-5 names, and
                    // one view, because §10.2's exclusion 2 takes it out of `sort`'s domain on both
                    // sides. The same three nulls `ListPurchaseOrderLines` carries, for the same rule.
                    return ['filter' => [], 'search' => null, 'views' => ['']];
                }];
            },
            'ListSalesOrders' => function (): array {
                $customer = $this->seedCustomer(self::TENANT_A, 'Acme Sales Orders');
                $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH Sales Orders');

                return ['/sales-orders', function (int $rows) use ($customer, $warehouse): array {
                    // Every row is `confirmed` with a distinct `ordered_at`, so the default view, the
                    // Open segment and the customer view all hold every row and **every sort key is
                    // non-null** -- which is what `SalesOrderViews::legalSorts()` requires before it
                    // will offer `ordered_at` at all.
                    for ($i = 1; $i <= $rows; ++$i) {
                        $this->seedSalesOrder(self::TENANT_A, \sprintf('SO-9%05d', $i), $customer, $warehouse,
                            status: SalesOrder::STATUS_CONFIRMED, orderedAt: \sprintf('2026-01-%02dT00:00:00+00:00', $i));
                    }

                    return ['filter' => ['customer_id' => $customer], 'search' => 'SO-9',
                        'views' => ['', 'status=open&sort=ordered_at', 'sort=order_number']];
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
                        $this->seedOrder(self::TENANT_A, \sprintf('PO-9%05d', $i), $supplier, $warehouse,
                            status: PurchaseOrder::STATUS_ORDERED, orderedAt: \sprintf('2026-01-%02dT00:00:00+00:00', $i));
                    }

                    return ['filter' => ['supplier_id' => $supplier], 'search' => 'PO-9', 'views' => ['', 'status=open&sort=ordered_at', 'sort=order_number']];
                }];
            },
        ];
    }
}

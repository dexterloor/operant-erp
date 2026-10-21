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

    /**
     * **A fixture path plus more query parameters, merged by key — the later value wins** (stock reads C1, 2026-10-14).
     * Until `ListStockLevels` every fixture path was bare, so the consumers wrote `$path.'?'…`; `/stock` carries its required
     * scope in the path (`/stock?storage_location_id=…`), and a second `?` would make every such request malformed. Empty
     * segments are dropped, so `withQuery($p, '')` is `$p`. The raw text of each segment is kept (a cursor is not re-encoded).
     */
    protected static function withQuery(string $path, string $query): string
    {
        [$route, $existing] = array_pad(explode('?', $path, 2), 2, '');

        $merged = [];
        foreach ([$existing, $query] as $part) {
            foreach (explode('&', $part) as $segment) {
                if ('' !== $segment) {
                    $merged[explode('=', $segment, 2)[0]] = $segment;
                }
            }
        }

        return [] === $merged ? $route : $route.'?'.implode('&', $merged);
    }

    /**
     * A fixture path without its query. A fixture path that carries one carries a **required, mutually exclusive scope**
     * (API-19b; `ListStockLevels` only), so a consumer that sends a scope parameter of its own sends it here, alone.
     */
    protected static function routeOf(string $path): string
    {
        return explode('?', $path, 2)[0];
    }

    /** Whether a fixture path carries a required scope in its query (see {@see self::routeOf()}). */
    protected static function isScoped(string $path): bool
    {
        return str_contains($path, '?');
    }

    /**
     * **Whether a fixture path's scope is EXCLUSIVE** — `ListStockLevels`' one-of-two (API-19b), where a consumer sends each
     * scope alone against {@see self::routeOf()}. Every scoped path but those in {@see self::PAIRED_SCOPE_ROUTES} (audit reads
     * C2): there the scope is a PAIR required together, so a consumer merges onto the fixture's own path with
     * {@see self::withQuery()} — sent alone against the bare route, either half is a 422 `required` by contract.
     */
    protected static function hasExclusiveScope(string $path): bool
    {
        return self::isScoped($path) && !\array_key_exists(self::routeOf($path), self::PAIRED_SCOPE_ROUTES);
    }

    /** @var array<string, string> route => why its scope is a pair, not a choice */
    private const PAIRED_SCOPE_ROUTES = [
        '/audit-log/records' => 'ListAuditRecords: subject_type and subject_id together name ONE record (E-5); neither alone is a History.',
    ];

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
                            VALUES (?::uuid, ?::uuid, ?::uuid, ?, 'other', now(), ?::uuid, false,
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
            'ListCreditNotes' => function (): array {
                $customer = $this->seedCustomer(self::TENANT_A, 'Acme Credits');
                $order = $this->seedSalesOrder(self::TENANT_A, 'SO-157500', $customer, null,
                    SalesOrder::STATUS_CONFIRMED, '2026-01-01T00:00:00+00:00');

                return ['/credit-notes', function (int $rows) use ($order, $customer): array {
                    // Sales step 7 C2. One shipment and its invoice, and line-less adjustment notes against it, as the
                    // owner, each with a distinct number, so the register, the invoice's and the customer's views hold
                    // every row. Numbered from `CN-800001` so they cannot collide with a note a success recipe issued
                    // through the API (`CN-000001` upwards).
                    $fulfilment = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
                    $invoice = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
                    $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                        INSERT INTO fulfilment (id, tenant_id, sales_order_id, warehouse_id, fulfilment_number,
                                                shipped_by_user_id, idempotency_key, request_digest)
                        SELECT ?::uuid, so.tenant_id, so.id, so.warehouse_id, 'FUL-710001', ?::uuid,
                               encode(sha256(gen_random_uuid()::text::bytea), 'hex'), encode(sha256(gen_random_uuid()::text::bytea), 'hex')
                          FROM sales_order so WHERE so.id = ?::uuid
                        SQL, [$fulfilment, (string) $this->actorId, $order]);
                    $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                        INSERT INTO invoice (id, tenant_id, fulfilment_id, sales_order_id, customer_id, invoice_number,
                                             currency_code, total_amount, due_at, issued_by_user_id)
                        SELECT ?::uuid, f.tenant_id, f.id, so.id, so.customer_id, 'INV-920001', so.currency_code, 1000,
                               now() + interval '30 days', ?::uuid
                          FROM fulfilment f JOIN sales_order so ON so.tenant_id = f.tenant_id AND so.id = f.sales_order_id
                         WHERE f.id = ?::uuid
                        SQL, [$invoice, (string) $this->actorId, $fulfilment]);
                    for ($i = 1; $i <= $rows; ++$i) {
                        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                            INSERT INTO credit_note (id, tenant_id, invoice_id, credit_note_number, reason, currency_code,
                                                     total_amount, issued_by_user_id, idempotency_key, request_digest)
                            SELECT ?::uuid, inv.tenant_id, inv.id, ?, 'damaged_goods_kept', inv.currency_code, 1, ?::uuid,
                                   encode(sha256(gen_random_uuid()::text::bytea), 'hex'), encode(sha256(gen_random_uuid()::text::bytea), 'hex')
                              FROM invoice inv WHERE inv.id = ?::uuid
                            SQL, [\Symfony\Component\Uid\Uuid::v4()->toRfc4122(), \sprintf('CN-8%05d', $i), (string) $this->actorId, $invoice]);
                    }

                    // `invoice_id` and `customer_id` hold every row (`sales_return_id` names a return none of them has:
                    // they are adjustments); TB-5 names `credit_note_number`; one order, so one view.
                    return ['filter' => ['invoice_id' => $invoice, 'customer_id' => $customer,
                        'sales_return_id' => \Symfony\Component\Uid\Uuid::v4()->toRfc4122()], 'search' => 'CN-8', 'views' => ['']];
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
            'ListUsers' => function (): array {
                // Users slice 1 C2. Bounded (TB-1c): no filter; TB-5 names `app_user.email`, so `q` is probed with the seeded
                // prefix; one view (§10.2 exclusion 2). The actor is already on the page, as `ListRoles`' fixture role is.
                return ['/users', function (int $rows): array {
                    for ($i = 1; $i <= $rows; ++$i) {
                        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                            INSERT INTO app_user (id, tenant_id, email, password_hash, display_name)
                            VALUES (gen_random_uuid(), ?::uuid, ?, 'not-a-hash', 'Listed user')
                            SQL, [self::TENANT_A, 'user-env-'.$i.'@example.test']);
                    }

                    return ['filter' => [], 'search' => 'user-env', 'views' => ['']];
                }];
            },
            'ListCustomers' => function (): array {
                // Customers slice 1 C1. Keyset; no filter (§4.2 declares none); TB-5 names `customer.name` and `.code`, so `q`
                // is probed once with the seeded name prefix (the name-vs-code, case and prefix-vs-infix probes are
                // `CustomerListTest`'s — this driver takes one); the default view and both manifest orders by name (`PaginationContractTest` decodes exactly the declared enum from
                // the views). `seedCustomer()`'s codes are
                // letter-prefixed (CD-3), so no all-digit code reaches `WireScaleTest`.
                // C5 C4a: as many ARCHIVED customers again, each archived at a distinct microsecond instant (owner SQL: a
                // fixture needs distinct instants; `DeleteCustomer`, C5 C4b, archives at `now()`), so the Archived view
                // (`archived=true&sort=archived_at`) pages too; they never appear in the live views.
                return ['/customers', function (int $rows): array {
                    for ($i = 1; $i <= $rows; ++$i) {
                        $this->seedCustomer(self::TENANT_A, 'CUS-ENV-'.$i);
                        $archived = $this->seedCustomer(self::TENANT_A, 'CUS-ENV-ARCHIVED-'.$i);
                        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE customer SET deleted_at = ?::timestamptz WHERE id = ?::uuid',
                            [\sprintf('2026-10-%02d 12:00:00.123456+00', $i), $archived]);
                    }

                    return ['filter' => [], 'search' => 'CUS-ENV', 'views' => ['', 'sort=name', 'sort=code', 'archived=true&sort=archived_at']];
                }];
            },
            'ListSuppliers' => function (): array {
                // Suppliers slice 1 C1, `ListCustomers`' entry. Keyset; no filter (§4.2 declares none); TB-5 names
                // `supplier.name` and `.code`, so `q` is probed once with the seeded name prefix (the case, code-leaf and
                // prefix-vs-infix probes are `SupplierListTest`'s); views decode exactly the declared `sort` enum
                // (`PaginationContractTest`). `seedSupplier()`'s codes are letter-prefixed (CD-3) and name-derived, and the
                // names here are distinct, so the live name and code uniques hold.
                // C5 C2a: as many ARCHIVED suppliers again, each archived at a distinct instant (owner SQL: a fixture needs
                // distinct instants; `DeleteSupplier`, C5 C2b, archives at `now()`), so the Archived view (`archived=true&sort=archived_at`) pages too; they never
                // appear in the live views.
                return ['/suppliers', function (int $rows): array {
                    for ($i = 1; $i <= $rows; ++$i) {
                        $this->seedSupplier(self::TENANT_A, 'SUP-ENV-'.$i);
                        $archived = $this->seedSupplier(self::TENANT_A, 'SUP-ENV-ARCHIVED-'.$i);
                        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE supplier SET deleted_at = ?::timestamptz WHERE id = ?::uuid',
                            [\sprintf('2026-10-%02d 12:00:00.123456+00', $i), $archived]);
                    }

                    return ['filter' => [], 'search' => 'SUP-ENV', 'views' => ['', 'sort=name', 'sort=code', 'archived=true&sort=archived_at']];
                }];
            },
            'ListOfferedRoles' => function (): array {
                // Users slice 1 C3. Bounded; no filter; TB-5 names `role.name` (the list is derived from `role`), so `q` is
                // probed with the seeded prefix; one view. The subject holds nothing; the actor's fixture role is on the page.
                $subject = $this->seedPrincipal(self::TENANT_A, []);

                return ['/users/'.$subject.'/offered-roles', function (int $rows): array {
                    for ($i = 1; $i <= $rows; ++$i) {
                        $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, ?)',
                            [\Symfony\Component\Uid\Uuid::v4()->toRfc4122(), self::TENANT_A, 'ROLE-OFF-'.$i]);
                    }

                    return ['filter' => [], 'search' => 'ROLE-OFF', 'views' => ['']];
                }];
            },
            'ListRoleGrantProposals' => function (): array {
                // Roles slice 3 C1. One subject, one role, one proposer: the newest row is PENDING and every earlier one
                // LAPSED (one pending per (user, role); decided rows are INSERTed decided, never updated — the
                // decided-is-final trigger), all seeded by owner INSERT. `user` and `role` hold every row; `status` is
                // sent from its enum; no searchable column (TB-5 names none for proposals); one order, so one view.
                $subject = $this->seedPrincipal(self::TENANT_A, []);
                $proposer = $this->seedPrincipal(self::TENANT_A, ['role.assign']);
                $role = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
                $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, ?)',
                    [$role, self::TENANT_A, 'PROPOSAL-ENV']);

                return ['/role-grant-proposals', function (int $rows) use ($subject, $proposer, $role): array {
                    for ($i = 1; $i <= $rows; ++$i) {
                        $pending = $i === $rows;
                        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                            INSERT INTO role_grant_proposal (id, tenant_id, user_id, role_id, permission_codes, proposed_by_user_id,
                                                             proposed_at, expires_at, status, decided_at, lapse_reason)
                            SELECT gen_random_uuid(), ?::uuid, ?::uuid, ?::uuid, '["invoice.pay"]'::jsonb, ?::uuid, c.t, c.t + interval '336 hours',
                                   ?::text,
                                   CASE WHEN ?::text = 'pending' THEN NULL ELSE c.t + interval '337 hours' END,
                                   CASE WHEN ?::text = 'pending' THEN NULL ELSE 'expired' END
                              FROM (SELECT clock_timestamp() - make_interval(hours => ?) AS t) c
                            SQL, [self::TENANT_A, $subject, $role, $proposer, $status = $pending ? 'pending' : 'lapsed', $status, $status, $pending ? 0 : 400 + $rows - $i]);
                    }

                    return ['filter' => ['user' => $subject, 'role' => $role], 'search' => null, 'views' => ['']];
                }];
            },
            'ListInvoiceLines' => function (): array {
                // Sales step 7 C0. An invoice (as the owner) on a LINE-LESS shipment, so the empty page is readable
                // first; the seed step gives the shipment its lines, one order line each. Numbers from `-91xxxx` so
                // they cannot collide with a recipe's invoice or fulfilment.
                $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH Invoice Lines');
                $order = $this->seedSalesOrder(self::TENANT_A, 'SO-157000', null, $warehouse,
                    SalesOrder::STATUS_CONFIRMED, '2026-01-01T00:00:00+00:00');
                $fulfilment = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
                $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                    INSERT INTO fulfilment (id, tenant_id, sales_order_id, warehouse_id, fulfilment_number, shipped_by_user_id,
                                            idempotency_key, request_digest)
                    VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 'FUL-910001', ?::uuid,
                            encode(sha256(gen_random_uuid()::text::bytea), 'hex'), encode(sha256(gen_random_uuid()::text::bytea), 'hex'))
                    SQL, [$fulfilment, self::TENANT_A, $order, $warehouse, (string) $this->actorId]);
                $invoice = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
                $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                    INSERT INTO invoice (id, tenant_id, fulfilment_id, sales_order_id, customer_id, invoice_number, currency_code,
                                         total_amount, due_at, issued_by_user_id)
                    SELECT ?::uuid, so.tenant_id, ?::uuid, so.id, so.customer_id, 'INV-910001', so.currency_code, 0,
                           now() + interval '30 days', ?::uuid
                      FROM sales_order so WHERE so.id = ?::uuid
                    SQL, [$invoice, $fulfilment, (string) $this->actorId, $order]);
                $bin = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
                $this->insertAsOwnerForTenant(self::TENANT_A,
                    "INSERT INTO storage_location (id, tenant_id, warehouse_id, code, kind) VALUES (?::uuid, ?::uuid, ?::uuid, 'IL-01', 'bin')",
                    [$bin, self::TENANT_A, $warehouse]);

                return ['/invoices/'.$invoice.'/lines', function (int $rows) use ($order, $fulfilment, $bin): array {
                    for ($i = 1; $i <= $rows; ++$i) {
                        $line = $this->seedSalesOrderLine(self::TENANT_A, $order, $this->seedItem(self::TENANT_A, 'SKU-ENV-IL'.$i), $i);
                        $this->insertAsOwnerForTenant(self::TENANT_A,
                            'INSERT INTO fulfilment_line (id, tenant_id, fulfilment_id, sales_order_line_id, storage_location_id, quantity) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::uuid, 1)',
                            [\Symfony\Component\Uid\Uuid::v4()->toRfc4122(), self::TENANT_A, $fulfilment, $line, $bin]);
                    }

                    // Bounded (TB-1c), `ListSalesOrderLines`' three nulls for the same rule.
                    return ['filter' => [], 'search' => null, 'views' => ['']];
                }];
            },
            'ListSupplierProducts' => function (): array {
                // Suppliers slice 1 C3. One supplier; the seed step gives it N catalogue entries, one per fresh item, each with a
                // LETTER-bearing `supplier_sku` (`seedSupplierProduct()`'s `SP-` prefix, CD-3).
                $supplier = $this->seedSupplier(self::TENANT_A, 'Acme Catalogue');

                return ['/suppliers/'.$supplier.'/products', function (int $rows) use ($supplier): array {
                    for ($i = 1; $i <= $rows; ++$i) {
                        $this->seedSupplierProduct(self::TENANT_A, $supplier, $this->seedItem(self::TENANT_A, 'SKU-ENV-CAT-'.$i));
                    }

                    // Bounded (owner Q-S3 (b), TB-1c breach G-42), no query parameter at all (the table is `hard`).
                    return ['filter' => [], 'search' => null, 'views' => ['']];
                }];
            },
            'ListWarehouses' => function (): array {
                // Warehouses slice 1 C1, `ListRoles`' entry. Bounded (TB-1c): no filter; TB-5 names `warehouse.code`, so `q` is
                // probed with the seeded code prefix (letter-bearing, CD-3); one view (§10.2 exclusion 2).
                return ['/warehouses', function (int $rows): array {
                    for ($i = 1; $i <= $rows; ++$i) {
                        $this->insertAsOwnerForTenant(self::TENANT_A,
                            "INSERT INTO warehouse (id, tenant_id, code, name, country_code) VALUES (?::uuid, ?::uuid, ?, ?, 'DE')",
                            [\Symfony\Component\Uid\Uuid::v4()->toRfc4122(), self::TENANT_A, 'WH-ENV-'.$i, 'Envelope Warehouse '.$i]);
                    }

                    return ['filter' => [], 'search' => 'WH-ENV', 'views' => ['']];
                }];
            },
            'ListStorageLocations' => function (): array {
                // Warehouses slice 1 C1. One warehouse; the seed step gives it N letter-bearing bins (CD-3). Bounded, no `q`
                // (TB-5 names no `storage_location` column); `archived` only (API-19c limb 2), one view.
                $warehouse = $this->seedWarehouse(self::TENANT_A, 'Envelope Locations');

                return ['/warehouses/'.$warehouse.'/locations', function (int $rows) use ($warehouse): array {
                    for ($i = 1; $i <= $rows; ++$i) {
                        $this->insertAsOwnerForTenant(self::TENANT_A,
                            'INSERT INTO storage_location (id, tenant_id, warehouse_id, code) VALUES (?::uuid, ?::uuid, ?::uuid, ?)',
                            [\Symfony\Component\Uid\Uuid::v4()->toRfc4122(), self::TENANT_A, $warehouse, 'LOC-ENV-'.$i]);
                    }

                    return ['filter' => [], 'search' => null, 'views' => ['']];
                }];
            },
            'ListStockLevels' => function (): array {
                // Stock reads C1 (2026-10-14). **The first list whose bare path is a 422** (API-19b: exactly one scope), so
                // the fixture path CARRIES its scope — `/stock?storage_location_id=<bin>` — and the consumers merge their own
                // parameters into it with {@see self::withQuery()}. The seed step gives the bin N items (`SKU-` codes; a
                // letter-bearing bin code, CD-3). `filter` supplies both scopes, each seeded to return rows alone; a consumer
                // sends a scope against {@see self::routeOf()}, never beside the fixture's own (that would be "both", 422).
                $warehouse = $this->seedWarehouse(self::TENANT_A, 'Envelope Stock');
                $bin = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'STK-ENV');

                return ['/stock?storage_location_id='.$bin, function (int $rows) use ($bin): array {
                    $first = null;
                    for ($i = 1; $i <= $rows; ++$i) {
                        $item = $this->seedItem(self::TENANT_A, 'SKU-STK-ENV-'.$i);
                        $first ??= $item;
                        $this->insertAsOwnerForTenant(self::TENANT_A,
                            'INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric)',
                            [\Symfony\Component\Uid\Uuid::v4()->toRfc4122(), self::TENANT_A, $item, $bin, (string) $i]);
                    }

                    // Bounded (TB-1c): no `limit`, `after`, `before`, `sort` or `q` (TB-5 names no `stock_level` column).
                    return ['filter' => ['storage_location_id' => $bin, 'inventory_item_id' => (string) $first], 'search' => null, 'views' => ['']];
                }];
            },
            'ListStockMovements' => function (): array {
                // Stock reads C2. N movements of ONE item, each at its own whole second, by owner INSERT; both views (the
                // ledger and the item's) hold every row. One order per view, newest first (no `sort`, §14.2 item 29).
                $warehouse = $this->seedWarehouse(self::TENANT_A, 'Envelope Movements');
                $bin = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'MOV-ENV');
                $item = $this->seedItem(self::TENANT_A, 'SKU-MOV-ENV');

                return ['/stock-movements', function (int $rows) use ($bin, $item): array {
                    for ($i = 1; $i <= $rows; ++$i) {
                        $this->insertAsOwnerForTenant(self::TENANT_A,
                            "INSERT INTO stock_movement (id, tenant_id, inventory_item_id, storage_location_id, quantity, reason, occurred_at) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 1, 'receipt', ?::timestamptz)",
                            [\Symfony\Component\Uid\Uuid::v4()->toRfc4122(), self::TENANT_A, $item, $bin, \sprintf('2026-01-%02dT00:00:00+00:00', $i)]);
                    }

                    return ['filter' => ['inventory_item_id' => $item], 'search' => null, 'views' => ['', 'inventory_item_id='.$item]];
                }];
            },
            'ListAuditLogEntries' => function (): array {
                // Audit reads C1. N rows by ONE actor of ONE event type, each at its own whole second, by owner INSERT; all
                // three views (the whole trail, the actor's, the event type's) hold every row. One order per view, newest
                // first (no `sort`, §14.2 item 30). The actor is a user of its own, so the trail the setup itself writes
                // (a denial, a sign-in) never lands in the filtered views.
                $actor = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
                $this->insertAsOwnerForTenant(self::TENANT_A,
                    "INSERT INTO app_user (id, tenant_id, email, password_hash, display_name) VALUES (?::uuid, ?::uuid, ?, 'not-a-hash', 'Envelope Actor')",
                    [$actor, self::TENANT_A, 'envelope-'.$actor.'@example.test']);

                return ['/audit-log', function (int $rows) use ($actor): array {
                    for ($i = 1; $i <= $rows; ++$i) {
                        $this->insertAsOwnerForTenant(self::TENANT_A,
                            "INSERT INTO audit_log (id, tenant_id, event_type, actor_user_id, actor_label, occurred_at) VALUES (?::uuid, ?::uuid, 'purchase_order.cancelled', ?::uuid, 'Envelope Actor', ?::timestamptz)",
                            [\Symfony\Component\Uid\Uuid::v4()->toRfc4122(), self::TENANT_A, $actor, \sprintf('2026-01-%02dT00:00:00+00:00', $i)]);
                    }

                    return ['filter' => ['actor_user_id' => $actor], 'search' => null,
                        'views' => ['', 'actor_user_id='.$actor, 'event_type=purchase_order.cancelled']];
                }];
            },
            'ListAuditRecords' => function (): array {
                // Audit reads C2. The path CARRIES its required PAIR (`subject_type` + `subject_id`; {@see self::PAIRED_SCOPE_ROUTES}),
                // so consumers merge onto it. N `purchase_order.cancelled` rows on ONE purchase order id (no FK on
                // `subject_id`), each at its own whole second, by owner INSERT. One view, newest first (no `sort`).
                $order = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();

                return ['/audit-log/records?subject_type=purchase_order&subject_id='.$order, function (int $rows) use ($order): array {
                    for ($i = 1; $i <= $rows; ++$i) {
                        $this->insertAsOwnerForTenant(self::TENANT_A,
                            "INSERT INTO audit_log (id, tenant_id, event_type, actor_label, subject_type, subject_id, occurred_at) VALUES (?::uuid, ?::uuid, 'purchase_order.cancelled', 'Envelope History', 'purchase_order', ?::uuid, '2026-02-01 00:00:00+00'::timestamptz + ?::int * interval '1 second')",
                            [\Symfony\Component\Uid\Uuid::v4()->toRfc4122(), self::TENANT_A, $order, $i]);
                    }

                    return ['filter' => ['subject_type' => 'purchase_order', 'subject_id' => $order], 'search' => null, 'views' => ['']];
                }];
            },
            'ListCustomerAddresses' => function (): array {
                // Customers slice 1 C3. One customer; the seed step gives it N addresses with LETTER-bearing postal codes
                // (CD-3), each its own row (distinct random keys, `seedCustomerAddress()`).
                $customer = $this->seedCustomer(self::TENANT_A, 'Acme Addresses');

                return ['/customers/'.$customer.'/addresses', function (int $rows) use ($customer): array {
                    for ($i = 1; $i <= $rows; ++$i) {
                        $this->seedCustomerAddress(self::TENANT_A, $customer, 'shipping', $i.' Envelope Street', 'London', 'EC2R 8AH');
                    }

                    // Bounded (TB-1c), no query parameter at all (the table is `hard`, so not even `archived`).
                    return ['filter' => [], 'search' => null, 'views' => ['']];
                }];
            },
            'ListCustomerContacts' => function (): array {
                // Customers slice 2 C1. One customer; the seed step gives it N contacts, each its own row (distinct random
                // keys, `seedCustomerContact()`), none primary (the one-primary index), phones letter- or punctuation-bearing (CD-3).
                $customer = $this->seedCustomer(self::TENANT_A, 'Acme Contacts');

                return ['/customers/'.$customer.'/contacts', function (int $rows) use ($customer): array {
                    for ($i = 1; $i <= $rows; ++$i) {
                        $this->seedCustomerContact(self::TENANT_A, $customer, 'Contact '.$i, 'contact'.$i.'@example.test', '+44 20 7946 '.$i);
                    }

                    // Bounded (TB-1c), no query parameter at all (the table is `hard`, so not even `archived`).
                    return ['filter' => [], 'search' => null, 'views' => ['']];
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
                        // Order restores C2: as many ARCHIVED drafts again (the only archivable status, by CHECK), each at a
                        // distinct microsecond instant (owner SQL: a fixture needs distinct instants), so the Archived view
                        // (`archived=true&sort=archived_at`) pages too; they never appear in the live views.
                        $archived = $this->seedSalesOrder(self::TENANT_A, \sprintf('SO-95%04d', $i), $customer, $warehouse);
                        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE sales_order SET deleted_at = ?::timestamptz WHERE id = ?::uuid',
                            [\sprintf('2026-10-%02d 12:00:00.123456+00', $i), $archived]);
                    }

                    return ['filter' => ['customer_id' => $customer], 'search' => 'SO-9',
                        'views' => ['', 'status=open&sort=ordered_at', 'sort=order_number', 'archived=true&sort=archived_at']];
                }];
            },
            'ListGoodsReceipts' => function (): array {
                $supplier = $this->seedSupplier(self::TENANT_A, 'Acme Receipts');
                $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH Receipts');
                $order = $this->seedOrder(self::TENANT_A, 'PO-335000', $supplier, $warehouse, status: PurchaseOrder::STATUS_ORDERED);

                return ['/goods-receipts', function (int $rows) use ($order, $warehouse): array {
                    // Line-less receipts of ONE order, as the owner (`ListFulfilments`' precedent: a line-less
                    // header the API cannot write), each with a distinct number and receipt time, so both views (the
                    // register and the order's) hold every row. Numbered from `GR-900001` so they cannot collide
                    // with a receipt a success recipe booked through the API earlier (the writer mints from
                    // `GR-000001`). No receiver: the column is nullable, and the outer join must keep the row.
                    for ($i = 1; $i <= $rows; ++$i) {
                        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                            INSERT INTO goods_receipt (id, tenant_id, purchase_order_id, warehouse_id, receipt_number, received_at,
                                                       idempotency_key, request_digest)
                            VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, ?::timestamptz,
                                    encode(sha256(gen_random_uuid()::text::bytea), 'hex'),
                                    encode(sha256(gen_random_uuid()::text::bytea), 'hex'))
                            SQL, [\Symfony\Component\Uid\Uuid::v4()->toRfc4122(), self::TENANT_A, $order, $warehouse,
                                \sprintf('GR-9%05d', $i), \sprintf('2026-01-%02dT00:00:00+00:00', $i)]);
                    }

                    // `purchase_order_id` is the only filter; TB-5 names `receipt_number`; no `sort` (IA §5.6 gives
                    // each view one order), so the two views are the register and the order's.
                    return ['filter' => ['purchase_order_id' => $order], 'search' => 'GR-9',
                        'views' => ['', 'purchase_order_id='.$order]];
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
                        // Order restores C3: as many ARCHIVED drafts again (the only archivable status, by CHECK), each at a
                        // distinct microsecond instant, so the Archived view (`archived=true&sort=archived_at`) pages too;
                        // they never appear in the live views.
                        $archived = $this->seedOrder(self::TENANT_A, \sprintf('PO-95%04d', $i), $supplier, $warehouse);
                        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE purchase_order SET deleted_at = ?::timestamptz WHERE id = ?::uuid',
                            [\sprintf('2026-10-%02d 12:00:00.123456+00', $i), $archived]);
                    }

                    return ['filter' => ['supplier_id' => $supplier], 'search' => 'PO-9',
                        'views' => ['', 'status=open&sort=ordered_at', 'sort=order_number', 'archived=true&sort=archived_at']];
                }];
            },
        ];
    }
}

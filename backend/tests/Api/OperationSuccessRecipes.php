<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Tests\Support\CountingKdf;
use Symfony\Component\Uid\Uuid;

/**
 * One success path per built operation, shared by every registry-wide check
 * that has to drive each operation for real rather than read its attributes.
 *
 * Moved here from `AuditWriterTest` (C5 batch A, `02d99a6`) by C5 batch B, so
 * `ResponseStatusTest` and `ResponseShapeTest` drive the same paths instead of
 * keeping second copies. **Every consumer holds the recipe keys equal to
 * `TransactionBoundaryTest::contractOperations()` inside its own test**, so an
 * operation built tomorrow is red in each of them until it is given a recipe.
 *
 * The status a recipe returns is the status its author expected. A check whose
 * subject is the status itself (`ResponseStatusTest`) must not read it — it
 * derives the expectation from the registry instead.
 *
 * @phpstan-require-extends PurchaseOrderTestCase
 */
trait OperationSuccessRecipes
{
    /**
     * One success path per built operation: a fixture step, run before any
     * request, and a request step answering the status it expects.
     *
     * @return array<string, array{0: callable(): array<string, mixed>, 1: callable(array<string, mixed>): array{0: int, 1: string}}>
     */
    protected function successRecipes(): array
    {
        $key = static fn (): array => ['Idempotency-Key' => Uuid::v4()->toRfc4122()];
        $none = static fn (): array => [];
        $draft = function (string $number): array {
            $d = $this->seedDraft(self::TENANT_A, $number);
            $d['line'] = $this->seedLine(self::TENANT_A, $d['order'], $d['item']);

            return $d;
        };
        $ordered = function (string $number): array {
            $supplier = $this->seedSupplier(self::TENANT_A, 'Acme '.$number);
            $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH '.$number);
            $order = $this->seedOrder(self::TENANT_A, $number, $supplier, $warehouse, status: PurchaseOrder::STATUS_ORDERED);
            $line = $this->seedLine(self::TENANT_A, $order, $this->seedItem(self::TENANT_A, 'SKU-'.$number));
            $bin = Uuid::v4()->toRfc4122();
            $this->insertAsOwnerForTenant(self::TENANT_A,
                'INSERT INTO storage_location (id, tenant_id, warehouse_id, code) VALUES (?::uuid, ?::uuid, ?::uuid, ?)',
                [$bin, self::TENANT_A, $warehouse, 'A-01']);

            return ['order' => $order, 'line' => $line, 'bin' => $bin];
        };
        // One line (V1-R-2, V4 B4a): a recipe that sends no `lines` leaves the nested create unprobed by
        // every check that plants into the body. Both closures live **inside this trait** rather than in
        // a sales-side twin, because the trait is `@phpstan-require-extends PurchaseOrderTestCase` and
        // every consumer holds its keys equal to `TransactionBoundaryTest::contractOperations()`: a
        // separate rig would not satisfy that pin and the operations would be red in four tests at once
        // (RED F-12).
        $salesWorkspace = function (string $tag): array {
            $customer = $this->seedCustomer(self::TENANT_A, 'Acme Retail '.$tag);

            return [
                'customer' => $customer,
                'address' => $this->seedCustomerAddress(self::TENANT_A, $customer),
                'warehouse' => $this->seedWarehouse(self::TENANT_A, 'WH '.$tag),
                'item' => $this->seedItem(self::TENANT_A, 'SKU-NEW-'.$tag),
            ];
        };
        $raiseSalesOrder = function (array $c) use ($key): void {
            $this->send('POST', '/sales-orders', [
                'customer_id' => $c['customer'], 'warehouse_id' => $c['warehouse'],
                'currency_code' => 'EUR', 'ship_to_address_id' => $c['address'],
                'lines' => [['inventory_item_id' => $c['item'], 'quantity' => '1', 'unit_price' => '1']],
            ], $key());
        };
        // Sales step 5: a sales workspace with a bin of its warehouse holding the item, seeded as the owner in the
        // PREPARE half, and one drive that raises, confirms and part-fulfils an order through the operations.
        $stockedSalesWorkspace = function (string $tag) use ($salesWorkspace): array {
            $c = $salesWorkspace($tag);
            $c['bin'] = Uuid::v4()->toRfc4122();
            $this->insertAsOwnerForTenant(self::TENANT_A,
                'INSERT INTO storage_location (id, tenant_id, warehouse_id, code) VALUES (?::uuid, ?::uuid, ?::uuid, ?)',
                [$c['bin'], self::TENANT_A, $c['warehouse'], 'A-01']);
            $this->insertAsOwnerForTenant(self::TENANT_A,
                'INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 100.25)',
                [Uuid::v4()->toRfc4122(), self::TENANT_A, $c['item'], $c['bin']]);

            return $c;
        };
        $raiseConfirmAndFulfil = function (array $c) use ($key): string {
            $this->send('POST', '/sales-orders', [
                'customer_id' => $c['customer'], 'warehouse_id' => $c['warehouse'],
                'currency_code' => 'EUR', 'ship_to_address_id' => $c['address'],
                'lines' => [['inventory_item_id' => $c['item'], 'quantity' => '3', 'unit_price' => '1']],
            ], $key());
            $order = (string) $this->client()->getResponse()->headers->get('Location');
            $this->send('POST', $order.'/confirmation');
            $this->send('GET', $order.'/lines');
            $line = $this->json()['items'][0]['id'];
            $this->send('POST', $order.'/fulfilments', ['lines' => [
                ['sales_order_line_id' => $line, 'quantity' => '1.5', 'storage_location_id' => $c['bin']],
            ]], $key());

            return $order;
        };
        $receive = function (array $o) use ($key): void {
            $this->send('POST', '/purchase-orders/'.$o['order'].'/goods-receipts',
                ['lines' => [['purchase_order_line_id' => $o['line'], 'quantity' => '1', 'storage_location_id' => $o['bin']]]], $key());
        };
        $password = 'a-password-long-enough-for-e23';

        return [
            'ListItems' => [$none, function (): array {
                $this->send('GET', '/items');

                return [200, 'list'];
            }],
            'GetItem' => [fn (): array => ['item' => $this->seedItem(self::TENANT_A, 'SKU-GET')], function (array $c): array {
                $this->send('GET', '/items/'.$c['item']);

                return [200, 'read'];
            }],
            'CreateItem' => [$none, function () use ($key): array {
                $this->send('POST', '/items', ['sku' => 'SKU-NEW', 'name' => 'Widget', 'unit_of_measure_code' => 'EA'], $key());

                return [201, 'create'];
            }],
            'UpdateItem' => [fn (): array => ['item' => $this->seedItem(self::TENANT_A, 'SKU-UPD')], function (array $c): array {
                $this->send('PATCH', '/items/'.$c['item'], ['name' => 'Renamed']);

                return [200, 'update'];
            }],
            'DeleteItem' => [fn (): array => ['item' => $this->seedItem(self::TENANT_A, 'SKU-DEL')], function (array $c): array {
                $this->send('DELETE', '/items/'.$c['item']);

                return [204, 'delete'];
            }],
            'RestoreItem' => [function (): array {
                $item = $this->seedItem(self::TENANT_A, 'SKU-RES');
                $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE inventory_item SET deleted_at = now() WHERE id = ?::uuid', [$item]);

                return ['item' => $item];
            }, function (array $c): array {
                $this->send('POST', '/items/'.$c['item'].'/restoration');

                return [200, 'restore'];
            }],
            'GetGoodsReceipt' => [fn (): array => $ordered('PO-114000'), function (array $c) use ($receive): array {
                $receive($c);
                $this->send('GET', (string) $this->client()->getResponse()->headers->get('Location'));

                return [200, 'receive, then read'];
            }],
            'CreateGoodsReceipt' => [fn (): array => $ordered('PO-115000'), function (array $c) use ($receive): array {
                $receive($c);

                return [201, 'receive'];
            }],
            'ListPurchaseOrders' => [$none, function (): array {
                $this->send('GET', '/purchase-orders');

                return [200, 'list'];
            }],
            'ListPurchaseOrderLines' => [fn (): array => $draft('PO-117000'), function (array $c): array {
                $this->send('GET', '/purchase-orders/'.$c['order'].'/lines');

                return [200, 'list'];
            }],
            'CreatePurchaseOrderLine' => [function () use ($draft): array {
                $d = $draft('PO-108000');
                $this->seedSupplierProduct(self::TENANT_A, $d['supplier'], $d['item']);

                return $d;
            }, function (array $c): array {
                $this->send('POST', '/purchase-orders/'.$c['order'].'/lines', ['inventory_item_id' => $c['item'], 'quantity' => '1', 'unit_cost' => '1'], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);

                return [201, 'create'];
            }],
            'UpdatePurchaseOrderLine' => [fn (): array => $draft('PO-123000'), function (array $c): array {
                $this->send('PATCH', '/purchase-orders/'.$c['order'].'/lines/'.$c['line'], ['quantity' => '2']);

                return [200, 'update'];
            }],
            'DeletePurchaseOrderLine' => [fn (): array => $draft('PO-111000'), function (array $c): array {
                $this->send('DELETE', '/purchase-orders/'.$c['order'].'/lines/'.$c['line']);

                return [204, 'delete'];
            }],
            'GetPurchaseOrder' => [fn (): array => $draft('PO-113000'), function (array $c): array {
                $this->send('GET', '/purchase-orders/'.$c['order']);

                return [200, 'read'];
            }],
            // Driven through the writer, like every other sales recipe here: `seedSalesOrder` writes no
            // creator and `seedSalesOrderLine` no `quantity_fulfilled` history, and a list whose rows the
            // operation itself produced is the one every reference and scale walker sees in the shape a
            // client gets. The order is read back from the create's `Location`, so the path is §4.2's own
            // rather than a second copy of the template.
            // Driven through the writer for the same reason: the list row a client sees is then the one
            // the operation wrote, with a real `created_by` for the reference walkers and a real
            // `total_amount` for the scale walker. A seeded row leaves both unexamined.
            'ListSalesOrders' => [fn (): array => $salesWorkspace('SO-169000'), function (array $c) use ($raiseSalesOrder): array {
                $raiseSalesOrder($c);
                $this->send('GET', '/sales-orders');

                return [200, 'create, then list'];
            }],
            'ListSalesOrderLines' => [fn (): array => $salesWorkspace('SO-168000'), function (array $c) use ($raiseSalesOrder): array {
                $raiseSalesOrder($c);
                $this->send('GET', (string) $this->client()->getResponse()->headers->get('Location').'/lines');

                return [200, 'create, then list its lines'];
            }],
            'CreateSalesOrder' => [fn (): array => $salesWorkspace('SO'), function (array $c) use ($raiseSalesOrder): array {
                $raiseSalesOrder($c);

                return [201, 'create'];
            }],
            // Driven through the writer, for `GetSalesOrder`'s reason: the line is added to an order the
            // operation itself raised, so the `Location` the create returned is §4.2's row URI and the
            // path is built from it rather than from a second copy of the template. A SECOND item is
            // seeded rather than reusing the order's, because the order already carries a line for that
            // one and a create that happens to duplicate an existing line would still pass while proving
            // nothing about a line this operation is the only writer of.
            // ⚠ **The second item is seeded in the PREPARE half, not the drive half**, and that was read
            // off a failure rather than reasoned: `ClientChosenIdTest` runs a drive closure **twice** --
            // once for the baseline and once with a planted id -- so a `seedItem()` inside it collided on
            // `inventory_item_tenant_sku_live` the second time. This trait's own rule says every fixture
            // is created before any request; the failure is what made the rule concrete.
            'CreateSalesOrderLine' => [function () use ($salesWorkspace): array {
                $c = $salesWorkspace('SO-142000');
                // A SECOND item, because the order already carries a line for the workspace's own: a line
                // create that happened to duplicate an existing line would still pass while proving
                // nothing about the operation this recipe exists to drive.
                $c['second_item'] = $this->seedItem(self::TENANT_A, 'SKU-NEW-SO-143000');

                return $c;
            }, function (array $c) use ($raiseSalesOrder): array {
                $raiseSalesOrder($c);
                $order = (string) $this->client()->getResponse()->headers->get('Location');
                $this->send('POST', $order.'/lines', [
                    'inventory_item_id' => $c['second_item'], 'quantity' => '2', 'unit_price' => '3',
                ], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);

                return [201, 'create the order, then add a line'];
            }],
            // Driven through the writer for the same reason, and the PATCH sends **both** fields: D-A
            // freezes `unit_price`, and a recipe that only ever sent `quantity` would leave every
            // registry-wide walker (shapes, scales, statuses) blind to the frozen column.
            'UpdateSalesOrderLine' => [fn (): array => $salesWorkspace('SO-205000'), function (array $c) use ($raiseSalesOrder): array {
                $raiseSalesOrder($c);
                $order = (string) $this->client()->getResponse()->headers->get('Location');
                $this->send('GET', $order.'/lines');
                $line = $this->json()['items'][0]['id'];
                $this->send('PATCH', $order.'/lines/'.$line, ['quantity' => '4', 'unit_price' => '5']);

                return [200, 'create the order, then edit its line'];
            }],
            'DeleteSalesOrderLine' => [fn (): array => $salesWorkspace('SO-150000'), function (array $c) use ($raiseSalesOrder): array {
                $raiseSalesOrder($c);
                $order = (string) $this->client()->getResponse()->headers->get('Location');
                $this->send('GET', $order.'/lines');
                $this->send('DELETE', $order.'/lines/'.$this->json()['items'][0]['id']);

                return [204, 'create the order, then delete its line'];
            }],
            // Seeded in the PREPARE half and deleted in the drive: `ClientChosenIdTest` runs a drive twice, and
            // a draft raised inside the drive would be a second order rather than a repeat (sales slice 4).
            // Seeded as the owner rather than raised through `CreateSalesOrder`: a delete reads nothing back,
            // so there is no body for `created_by` to be examined in, and the prepare half stays free of HTTP.
            'DeleteSalesOrder' => [function (): array {
                $order = $this->seedSalesOrder(self::TENANT_A, 'SO-151000');
                $this->seedSalesOrderLine(self::TENANT_A, $order, $this->seedItem(self::TENANT_A, 'SKU-DO'));

                return ['order' => $order];
            }, function (array $c): array {
                $this->send('DELETE', '/sales-orders/'.$c['order']);

                return [204, 'seed a draft with a line, then delete it'];
            }],
            // The line is created, deleted and then restored through the operations themselves, so the
            // row this drives at is archived by a writer rather than by a fixture -- which is what makes
            // the 200 mean the restore worked rather than that the guard happened to be satisfied.
            'RestoreSalesOrderLine' => [fn (): array => $salesWorkspace('SO-195000'), function (array $c) use ($raiseSalesOrder): array {
                $raiseSalesOrder($c);
                $order = (string) $this->client()->getResponse()->headers->get('Location');
                $this->send('GET', $order.'/lines');
                $line = $this->json()['items'][0]['id'];
                $this->send('DELETE', $order.'/lines/'.$line);
                $this->send('POST', $order.'/lines/'.$line.'/restoration');

                return [200, 'create the order, delete its line, then restore it'];
            }],
            // The read is driven through the writer rather than off a fixture, so the row it reads is a
            // row the operation wrote — which is what makes `created_by` non-null in the body every
            // reference check walks. A seeded `sales_order` would leave the column NULL and the
            // expansion unexamined.
            // Sales step 5. The order is raised, confirmed and PART-FULFILLED through the operations, so every row
            // these three recipes read was written by the API -- including a non-zero `quantity_fulfilled`, which
            // `WireScaleTest` must observe or its guard never arms (the sales step 1 finding). The candidate bin and
            // its stock are seeded in the PREPARE half (no HTTP there). C3's version of the pick-list recipe set
            // `quantity_fulfilled` as the OWNER because `CreateFulfilment` did not exist yet; this replaced it.
            'GetPickList' => [fn (): array => $stockedSalesWorkspace('SO-187000'), function (array $c) use ($raiseConfirmAndFulfil): array {
                $order = $raiseConfirmAndFulfil($c);
                $this->send('GET', $order.'/pick-list');

                return [200, 'create, confirm, part-fulfil, then read the pick list'];
            }],
            'CreateFulfilment' => [fn (): array => $stockedSalesWorkspace('SO-157000'), function (array $c) use ($raiseConfirmAndFulfil): array {
                $raiseConfirmAndFulfil($c);

                return [201, 'create, confirm, then fulfil part of it'];
            }],
            'GetFulfilment' => [fn (): array => $stockedSalesWorkspace('SO-160000'), function (array $c) use ($raiseConfirmAndFulfil): array {
                $raiseConfirmAndFulfil($c);
                $this->send('GET', (string) $this->client()->getResponse()->headers->get('Location'));

                return [200, 'create, confirm, fulfil, then read the fulfilment'];
            }],
            // Sales step 6 C1: the register, read after a fulfilment the API wrote, so the row every reference and
            // scale walker sees is a real one -- lines included (`quantity` is a decimal WireScaleTest must observe).
            'ListFulfilments' => [fn (): array => $stockedSalesWorkspace('SO-167000'), function (array $c) use ($raiseConfirmAndFulfil): array {
                $raiseConfirmAndFulfil($c);
                $this->send('GET', '/fulfilments');

                return [200, 'create, confirm, fulfil, then list the fulfilments'];
            }],
            // Sales step 6, C3: an invoice issued THROUGH THE API on a shipment the API wrote, so the row every
            // reference, scale and audit walker sees is a real one (`total_amount` is numeric(19,4) — WireScaleTest
            // must observe it, and `IssueInvoice` writes `invoice.issued`, which AuditWriterTest classifies).
            'IssueInvoice' => [fn (): array => $stockedSalesWorkspace('SO-161000'), function (array $c) use ($raiseConfirmAndFulfil, $key): array {
                $raiseConfirmAndFulfil($c);
                $this->send('POST', (string) $this->client()->getResponse()->headers->get('Location').'/invoices', null, $key());

                return [201, 'create, confirm, fulfil, then invoice the shipment'];
            }],
            'GetInvoice' => [fn (): array => $stockedSalesWorkspace('SO-162000'), function (array $c) use ($raiseConfirmAndFulfil, $key): array {
                $raiseConfirmAndFulfil($c);
                $this->send('POST', (string) $this->client()->getResponse()->headers->get('Location').'/invoices', null, $key());
                $this->send('GET', (string) $this->client()->getResponse()->headers->get('Location'));

                return [200, 'create, confirm, fulfil, invoice, then read the invoice'];
            }],
            // Sales step 6, C4: the register, read after an invoice the API issued.
            'ListInvoices' => [fn (): array => $stockedSalesWorkspace('SO-164000'), function (array $c) use ($raiseConfirmAndFulfil, $key): array {
                $raiseConfirmAndFulfil($c);
                $this->send('POST', (string) $this->client()->getResponse()->headers->get('Location').'/invoices', null, $key());
                $this->send('GET', '/invoices');

                return [200, 'create, confirm, fulfil, invoice, then list the invoices'];
            }],
            'GetSalesOrder' => [fn (): array => $salesWorkspace('SO-158000'), function (array $c) use ($raiseSalesOrder): array {
                $raiseSalesOrder($c);
                $this->send('GET', (string) $this->client()->getResponse()->headers->get('Location'));

                return [200, 'create, then read'];
            }],
            // Driven through the writer for `GetSalesOrder`'s reason and one of its own: the row it
            // confirms must have a live line, and `seedSalesOrder` deliberately seeds none. The `Location`
            // the create returns is §4.2's row URI, so the confirmation path is built from it rather than
            // from a second copy of the template.
            'ConfirmSalesOrder' => [fn (): array => $salesWorkspace('SO-144000'), function (array $c) use ($raiseSalesOrder): array {
                $raiseSalesOrder($c);
                $this->send('POST', (string) $this->client()->getResponse()->headers->get('Location').'/confirmation');

                return [200, 'create, then confirm'];
            }],
            // Driven from a DRAFT rather than through the confirmation, and that is deliberate: §8.3
            // makes `draft` a legal source for `cancel()`, so the shortest success path needs no second
            // transition, and a recipe that confirmed first would make every probe built on it depend on
            // two operations instead of one. `reason` is sent because §4.2 accepts it and the audit row
            // this operation writes is where it lands — a recipe omitting it would drive the
            // `metadata = '{}'` branch only, and `SalesOrderCancellationTest` owns both branches.
            'CancelSalesOrder' => [fn (): array => $salesWorkspace('SO-138000'), function (array $c) use ($raiseSalesOrder): array {
                $raiseSalesOrder($c);
                $this->send('POST', (string) $this->client()->getResponse()->headers->get('Location').'/cancellation', ['reason' => 'The customer withdrew the order.']);

                return [200, 'create, then cancel'];
            }],
            // Driven through the writer for `GetSalesOrder`'s reason, and the PATCH re-snapshots the
            // **ship-to address** rather than moving the warehouse: that is O-2's path, the one this
            // operation exists for, and it is the only field whose effect is four columns rather than
            // one. The second address belongs to the same customer, so `shipToInside()`'s ownership
            // predicate is satisfied and the success path is real. The 200 body is a full
            // `SalesOrderResource`, which is what `WireScaleTest`, `ResponseShapeTest` and
            // `ResponseStatusTest` walk.
            'UpdateSalesOrder' => [function (): array {
                $customer = $this->seedCustomer(self::TENANT_A, 'Acme Retail SO-207000');

                return [
                    'customer' => $customer,
                    'address' => $this->seedCustomerAddress(self::TENANT_A, $customer),
                    'moved' => $this->seedCustomerAddress(self::TENANT_A, $customer, 'shipping', '1 Change Alley', 'Leeds', 'LS1 1AA'),
                    'warehouse' => $this->seedWarehouse(self::TENANT_A, 'WH SO-207000'),
                    'item' => $this->seedItem(self::TENANT_A, 'SKU-NEW-SO-207000'),
                ];
            }, function (array $c) use ($raiseSalesOrder): array {
                $raiseSalesOrder($c);
                $this->send('PATCH', (string) $this->client()->getResponse()->headers->get('Location'), ['ship_to_address_id' => $c['moved']]);

                return [200, 'create, then move its shipping address'];
            }],
            'CreatePurchaseOrder' => [function (): array {
                $supplier = $this->seedSupplier(self::TENANT_A, 'Acme New');
                $item = $this->seedItem(self::TENANT_A, 'SKU-NEW-PO', $supplier);
                $this->seedSupplierProduct(self::TENANT_A, $supplier, $item);

                return ['supplier' => $supplier, 'warehouse' => $this->seedWarehouse(self::TENANT_A, 'WH New'), 'item' => $item];
            }, function (array $c) use ($key): array {
                $this->send('POST', '/purchase-orders', [
                    'supplier_id' => $c['supplier'], 'warehouse_id' => $c['warehouse'], 'currency_code' => 'EUR',
                    'lines' => [['inventory_item_id' => $c['item'], 'quantity' => '1', 'unit_cost' => '1']],
                ], $key());

                return [201, 'create'];
            }],
            'UpdatePurchaseOrder' => [fn (): array => $draft('PO-124000'), function (array $c): array {
                $this->send('PATCH', '/purchase-orders/'.$c['order'], ['expected_at' => '2030-01-01T00:00:00Z']);

                return [200, 'update'];
            }],
            'DeletePurchaseOrder' => [fn (): array => $draft('PO-110000'), function (array $c): array {
                $this->send('DELETE', '/purchase-orders/'.$c['order']);

                return [204, 'delete'];
            }],
            'ApprovePurchaseOrder' => [fn (): array => $draft('PO-102000'), function (array $c): array {
                $this->send('POST', '/purchase-orders/'.$c['order'].'/approval', []);

                return [200, 'approve'];
            }],
            'CancelPurchaseOrder' => [fn (): array => $draft('PO-106000'), function (array $c): array {
                $this->send('POST', '/purchase-orders/'.$c['order'].'/cancellation', ['reason' => 'Supplier withdrew']);

                return [200, 'cancel'];
            }],
            'ListPermissions' => [$none, function (): array {
                $this->send('GET', '/permissions');

                return [200, 'read the global vocabulary'];
            }],
            // The principal's own fixture role, read back through `/me`, so the role read is of a real row with a
            // real holder and real codes rather than a bare seeded name.
            'GetRole' => [$none, function (): array {
                $this->send('GET', '/me');
                $this->send('GET', '/roles/'.$this->json()['roles'][0]['id']);

                return [200, 'read the caller\'s own role'];
            }],
            'ListRoles' => [$none, function (): array {
                $this->send('GET', '/roles');

                return [200, 'list the workspace\'s roles'];
            }],
            'GetCurrentUser' => [$none, function (): array {
                $this->send('GET', '/me');

                return [200, 'read'];
            }],
            // Last two, in this order: the sign-in presents the password the
            // change just set, so both writers on the credential path run.
            'ChangePassword' => [function () use ($password): array {
                $hasher = self::getContainer()->get(CountingKdf::class);
                \assert($hasher instanceof CountingKdf);
                $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET password_hash = ? WHERE id = ?::uuid', [$hasher->hash($password), $this->actorId]);

                return [];
            }, function () use ($password): array {
                $this->send('POST', '/me/password', ['current_password' => $password, 'new_password' => $password.'-changed']);

                return [200, 'change'];
            }],
            'IssueToken' => [$none, function () use ($password): array {
                $email = (string) ($this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT email FROM app_user WHERE id = ?::uuid', [$this->actorId])[0]['email'] ?? '');
                $this->send('POST', '/auth/token', ['workspace' => 'tenant-a', 'email' => $email, 'password' => $password.'-changed']);

                return [200, 'sign in with the changed password'];
            }],
        ];
    }
}

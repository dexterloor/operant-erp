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
            // Sales step 7 R2: a return recorded THROUGH THE API on a shipment the API wrote, by a principal holding
            // everything -- so `invoice.view` too, and the walkers see `credit_blocked_by_payment` (the conditional field)
            // as well as every decimal (`quantity`, `quantity_credited`, which WireScaleTest must observe at scale 6).
            'CreateSalesReturn' => [fn (): array => $stockedSalesWorkspace('SO-238000'), function (array $c) use ($raiseConfirmAndFulfil, $key): array {
                $raiseConfirmAndFulfil($c);
                $fulfilment = (string) $this->client()->getResponse()->headers->get('Location');
                $this->send('GET', $fulfilment);
                $this->send('POST', $fulfilment.'/sales-returns', ['reason' => 'other', 'lines' => [
                    ['fulfilment_line_id' => $this->json()['lines'][0]['id'], 'quantity' => '0.5', 'storage_location_id' => $c['bin']],
                ]], $key());

                return [201, 'create, confirm, fulfil, then record a return of part of it'];
            }],
            // Sales step 7 C1: an invoice issued through the API, a return recorded after it, and a credit for it -- by a
            // principal holding everything, so every member of the CreditNote shape is observed.
            'IssueCreditNote' => [fn (): array => $stockedSalesWorkspace('SO-243000'), function (array $c) use ($raiseConfirmAndFulfil, $key): array {
                $raiseConfirmAndFulfil($c);
                $fulfilment = (string) $this->client()->getResponse()->headers->get('Location');
                $this->send('GET', $fulfilment);
                $line = $this->json()['lines'][0]['id'];
                $this->send('POST', $fulfilment.'/invoices', null, $key());
                $invoice = (string) $this->client()->getResponse()->headers->get('Location');
                $this->send('POST', $fulfilment.'/sales-returns', ['reason' => 'other', 'lines' => [
                    ['fulfilment_line_id' => $line, 'quantity' => '0.5', 'storage_location_id' => $c['bin']],
                ]], $key());
                $this->send('POST', $invoice.'/credit-notes', ['reason' => 'goods_returned', 'sales_return_id' => $this->json()['id'],
                    'lines' => [['fulfilment_line_id' => $line, 'quantity' => '0.5']]], $key());

                return [201, 'create, confirm, fulfil, invoice, return part, then credit it'];
            }],
            // Sales step 7 C1b (2026-10-01): an adjustment credits goods the customer KEEPS, so no return is recorded.
            'IssueAdjustmentCreditNote' => [fn (): array => $stockedSalesWorkspace('SO-245000'), function (array $c) use ($raiseConfirmAndFulfil, $key): array {
                $raiseConfirmAndFulfil($c);
                $fulfilment = (string) $this->client()->getResponse()->headers->get('Location');
                $this->send('GET', $fulfilment);
                $line = $this->json()['lines'][0]['id'];
                $this->send('POST', $fulfilment.'/invoices', null, $key());
                $invoice = (string) $this->client()->getResponse()->headers->get('Location');
                $this->send('POST', $invoice.'/credit-note-adjustments', ['reason' => 'damaged_goods_kept',
                    'lines' => [['fulfilment_line_id' => $line, 'quantity' => '0.5']]], $key());

                return [201, 'create, confirm, fulfil, invoice, then credit part of the shipment the customer keeps'];
            }],
            'GetCreditNote' => [fn (): array => $stockedSalesWorkspace('SO-244000'), function (array $c) use ($raiseConfirmAndFulfil, $key): array {
                $raiseConfirmAndFulfil($c);
                $fulfilment = (string) $this->client()->getResponse()->headers->get('Location');
                $this->send('GET', $fulfilment);
                $line = $this->json()['lines'][0]['id'];
                $this->send('POST', $fulfilment.'/invoices', null, $key());
                $invoice = (string) $this->client()->getResponse()->headers->get('Location');
                $this->send('POST', $fulfilment.'/sales-returns', ['reason' => 'other', 'lines' => [
                    ['fulfilment_line_id' => $line, 'quantity' => '0.5', 'storage_location_id' => $c['bin']],
                ]], $key());
                $this->send('POST', $invoice.'/credit-notes', ['reason' => 'goods_returned', 'sales_return_id' => $this->json()['id'],
                    'lines' => [['fulfilment_line_id' => $line, 'quantity' => '0.5']]], $key());
                $this->send('GET', (string) $this->client()->getResponse()->headers->get('Location'));

                return [200, 'create, confirm, fulfil, invoice, return part, credit it, then read the credit note'];
            }],
            // Sales step 7 C2: the credit-note register, read after a note the API issued, by a holder of everything.
            'ListCreditNotes' => [fn (): array => $stockedSalesWorkspace('SO-246000'), function (array $c) use ($raiseConfirmAndFulfil, $key): array {
                $raiseConfirmAndFulfil($c);
                $fulfilment = (string) $this->client()->getResponse()->headers->get('Location');
                $this->send('GET', $fulfilment);
                $line = $this->json()['lines'][0]['id'];
                $this->send('POST', $fulfilment.'/invoices', null, $key());
                $invoice = (string) $this->client()->getResponse()->headers->get('Location');
                $this->send('POST', $fulfilment.'/sales-returns', ['reason' => 'other', 'lines' => [
                    ['fulfilment_line_id' => $line, 'quantity' => '0.5', 'storage_location_id' => $c['bin']],
                ]], $key());
                $this->send('POST', $invoice.'/credit-notes', ['reason' => 'goods_returned', 'sales_return_id' => $this->json()['id'],
                    'lines' => [['fulfilment_line_id' => $line, 'quantity' => '0.5']]], $key());
                $this->send('GET', '/credit-notes');

                return [200, 'create, confirm, fulfil, invoice, return part, credit it, then list the credit notes'];
            }],
            'GetSalesReturn' => [fn (): array => $stockedSalesWorkspace('SO-239000'), function (array $c) use ($raiseConfirmAndFulfil, $key): array {
                $raiseConfirmAndFulfil($c);
                $fulfilment = (string) $this->client()->getResponse()->headers->get('Location');
                $this->send('GET', $fulfilment);
                $this->send('POST', $fulfilment.'/sales-returns', ['reason' => 'other', 'lines' => [
                    ['fulfilment_line_id' => $this->json()['lines'][0]['id'], 'quantity' => '0.5', 'storage_location_id' => $c['bin']],
                ]], $key());
                $this->send('GET', (string) $this->client()->getResponse()->headers->get('Location'));

                return [200, 'create, confirm, fulfil, return part, then read the return'];
            }],
            // G-29 / G-30 part 3 C1 (design T-7): an invoice, an adjustment and a return stamped for credit, all through the API,
            // then a `same` decision -- by a principal holding everything, so `invoice.view` too: the decision members
            // (`quantity_covered_by_adjustment`, `adjustment_decisions[].quantity`) are in the 200 and the walkers see them.
            'CreateAdjustmentDecision' => [fn (): array => $stockedSalesWorkspace('SO-247000'), function (array $c) use ($raiseConfirmAndFulfil, $key): array {
                $raiseConfirmAndFulfil($c);
                $fulfilment = (string) $this->client()->getResponse()->headers->get('Location');
                $this->send('GET', $fulfilment);
                $line = $this->json()['lines'][0]['id'];
                $this->send('POST', $fulfilment.'/invoices', null, $key());
                $invoice = (string) $this->client()->getResponse()->headers->get('Location');
                $this->send('POST', $invoice.'/credit-note-adjustments', ['reason' => 'damaged_goods_kept',
                    'lines' => [['fulfilment_line_id' => $line, 'quantity' => '0.5']]], $key());
                $adjustmentLine = $this->json()['lines'][0]['id'];
                $this->send('POST', $fulfilment.'/sales-returns', ['reason' => 'other', 'lines' => [
                    ['fulfilment_line_id' => $line, 'quantity' => '0.5', 'storage_location_id' => $c['bin']],
                ]], $key());
                $return = (string) $this->json()['id'];
                $this->send('POST', '/sales-returns/'.$return.'/adjustment-decisions', ['kind' => 'same',
                    'sales_return_line_id' => $this->json()['lines'][0]['id'], 'credit_note_line_id' => $adjustmentLine, 'quantity' => '0.5'], $key());

                return [200, 'create, confirm, fulfil, invoice, adjust part, return it, then record it as already credited'];
            }],
            // Sales step 7 R3: the register, read after a return the API recorded, by a holder of everything.
            'ListSalesReturns' => [fn (): array => $stockedSalesWorkspace('SO-240000'), function (array $c) use ($raiseConfirmAndFulfil, $key): array {
                $raiseConfirmAndFulfil($c);
                $fulfilment = (string) $this->client()->getResponse()->headers->get('Location');
                $this->send('GET', $fulfilment);
                $this->send('POST', $fulfilment.'/sales-returns', ['reason' => 'other', 'lines' => [
                    ['fulfilment_line_id' => $this->json()['lines'][0]['id'], 'quantity' => '0.5', 'storage_location_id' => $c['bin']],
                ]], $key());
                $this->send('GET', '/sales-returns');

                return [200, 'create, confirm, fulfil, return part, then list the returns'];
            }],
            // Sales step 6, C4: the register, read after an invoice the API issued.
            // Sales step 7 C0: the derived lines of an invoice the API issued on a shipment the API wrote.
            'ListInvoiceLines' => [fn (): array => $stockedSalesWorkspace('SO-242000'), function (array $c) use ($raiseConfirmAndFulfil, $key): array {
                $raiseConfirmAndFulfil($c);
                $this->send('POST', (string) $this->client()->getResponse()->headers->get('Location').'/invoices', null, $key());
                $this->send('GET', (string) $this->client()->getResponse()->headers->get('Location').'/lines');

                return [200, 'create, confirm, fulfil, invoice, then list the invoice\'s lines'];
            }],
            'ListInvoices' => [fn (): array => $stockedSalesWorkspace('SO-164000'), function (array $c) use ($raiseConfirmAndFulfil, $key): array {
                $raiseConfirmAndFulfil($c);
                $this->send('POST', (string) $this->client()->getResponse()->headers->get('Location').'/invoices', null, $key());
                $this->send('GET', '/invoices');

                return [200, 'create, confirm, fulfil, invoice, then list the invoices'];
            }],
            // Sales step 6, C5: an invoice issued through the API, then paid through the API, so the 200 body every
            // walker sees is the PAID shape -- `paid_at` and `paid_by = {id, display_name}` non-null (`SEC` §2: the
            // recipes must reach the paid branch, or the shape is never observed -- the `3e55a99` lesson).
            'PayInvoice' => [fn (): array => $stockedSalesWorkspace('SO-165000'), function (array $c) use ($raiseConfirmAndFulfil, $key): array {
                $raiseConfirmAndFulfil($c);
                $this->send('POST', (string) $this->client()->getResponse()->headers->get('Location').'/invoices', null, $key());
                $this->send('POST', (string) $this->client()->getResponse()->headers->get('Location').'/payment', []);

                return [200, 'create, confirm, fulfil, invoice, then record the payment'];
            }],
            // Sales step 6, C6: issued through the API, PAID by a second principal (its own token, sent explicitly), then
            // reversed by the rig's principal -- the reverser may not be the recorder (A2.3) -- so the 200 body every
            // walker sees is the POST-REVERSAL shape: `issued`, `paid_at` and `paid_by` null (RED-3 N-6: without this
            // recipe that shape is never observed). The rig's actor is NOT switched: later recipes (`ChangePassword`,
            // `IssueToken`) were prepared against it, and switching it reddened both (measured on the first run).
            'ReverseInvoicePayment' => [fn (): array => $stockedSalesWorkspace('SO-166000') + ['payer' => $this->seedPrincipal(self::TENANT_A, self::everyDeclaredPermission())], function (array $c) use ($raiseConfirmAndFulfil, $key): array {
                $raiseConfirmAndFulfil($c);
                $this->send('POST', (string) $this->client()->getResponse()->headers->get('Location').'/invoices', null, $key());
                $invoice = (string) $this->client()->getResponse()->headers->get('Location');
                $this->send('POST', $invoice.'/payment', [], ['Authorization' => 'Bearer '.$this->tokenFor(self::TENANT_A, $c['payer'])]);
                $this->send('POST', $invoice.'/payment-reversal', ['reason' => 'Recorded against the wrong invoice.']);

                return [200, 'create, confirm, fulfil, invoice, pay, then reverse the payment as someone else'];
            }],
            // Sales step 6, C7: issued through the API and voided by the rig's principal, which holds `invoice.void` (every
            // declared permission) and NOT the owner marker -- so this is the delegated void of a NEVER-PAID invoice, which
            // B′ does not touch. The 200 body every walker sees is the VOID shape: `status` `void`, `paid_at`/`paid_by` null.
            'VoidInvoice' => [fn (): array => $stockedSalesWorkspace('SO-172000'), function (array $c) use ($raiseConfirmAndFulfil, $key): array {
                $raiseConfirmAndFulfil($c);
                $this->send('POST', (string) $this->client()->getResponse()->headers->get('Location').'/invoices', null, $key());
                $this->send('POST', (string) $this->client()->getResponse()->headers->get('Location').'/void', ['reason' => 'Issued against the wrong shipment.']);

                return [200, 'create, confirm, fulfil, invoice, then void it'];
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
            // Roles slice 2, C2: a second user holding one non-marker role (seeded as the owner), revoked by the actor, who
            // holds every declared code (so `role.assign`) and never the marker — so E-15b and API-20 have nothing to say.
            'RevokeRole' => [function (): array {
                $subject = $this->seedPrincipal(self::TENANT_A, ['role.view']);

                return ['subject' => $subject, 'role' => (string) $this->fetchAsOwnerForTenant(self::TENANT_A,
                    'SELECT role_id::text AS r FROM user_role WHERE user_id = ?::uuid', [$subject])[0]['r']];
            }, function (array $c): array {
                $this->send('DELETE', '/users/'.$c['subject'].'/roles/'.$c['role']);

                return [204, 'revoke another user\'s role'];
            }],
            // Roles slice 2, C3: a user holding nothing, given a composed role whose one code (`role.view`) the actor holds
            // — the actor holds every declared code and never the marker, so E-15b, E-15e routing, E-15c and PC-13 have
            // nothing to say. 201 with the role as the body and `Location` naming the grant.
            'AssignRole' => [function (): array {
                $role = Uuid::v4()->toRfc4122();
                $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, ?)',
                    [$role, self::TENANT_A, 'Recipe Role '.substr($role, 0, 8)]);
                $this->insertAsOwnerForTenant(self::TENANT_A,
                    "INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, 'role.view')",
                    [self::TENANT_A, $role]);

                return ['subject' => $this->seedPrincipal(self::TENANT_A, []), 'role' => $role];
            }, function (array $c) use ($key): array {
                $this->send('POST', '/users/'.$c['subject'].'/roles', ['role_id' => $c['role']], $key());

                return [201, 'give another user a role'];
            }],
            // Roles slice 3, C1: a user holding nothing; a composed role carrying an X(admin) code the actor holds
            // (`invoice.pay`) and `role.view`. The actor holds every declared code and never the marker, so the grant needs
            // an owner's confirmation and is proposed: E-15b, E-15c, C12, the pending pair and PC-13 have nothing to say.
            // Roles slice 4, C1: a one-code role (`role.view`) composed by the actor, who holds every declared code — inside
            // its set (the ceiling), no SoD row (PC-4), no companion owed (`role.view` needs none). A fresh name per run.
            'CreateRole' => [$none, function () use ($key): array {
                $this->send('POST', '/roles', ['name' => 'Recipe composed '.substr(Uuid::v4()->toRfc4122(), 0, 8), 'permission_codes' => ['role.view']], $key());

                return [201, 'compose a role'];
            }],
            // Roles slice 4, C2: a one-code composed role (`role.view`, seeded as the owner, unheld) renamed and given a second
            // code the actor holds (`inventory_item.view`) — no marker (Q-A), inside the ceiling, no SoD row, no companion owed.
            'UpdateRole' => [function (): array {
                $role = Uuid::v4()->toRfc4122();
                $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, ?)',
                    [$role, self::TENANT_A, 'Recipe Edit '.substr($role, 0, 8)]);
                $this->insertAsOwnerForTenant(self::TENANT_A,
                    "INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, 'role.view')",
                    [self::TENANT_A, $role]);

                return ['role' => $role];
            }, function (array $c): array {
                $this->send('PATCH', '/roles/'.$c['role'], ['name' => 'Recipe edited '.substr($c['role'], 0, 8), 'permission_codes' => ['role.view', 'inventory_item.view']]);

                return [200, 'edit a role'];
            }],
            // Roles slice 4, C3: an unheld one-code composed role (`role.view`, seeded as the owner) with no proposal, deleted
            // by the actor, who holds `role.delete` and `role.assign` (every declared code — `roles-s4-sec-data-red.md` F1-D).
            'DeleteRole' => [function (): array {
                $role = Uuid::v4()->toRfc4122();
                $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, ?)',
                    [$role, self::TENANT_A, 'Recipe Delete '.substr($role, 0, 8)]);
                $this->insertAsOwnerForTenant(self::TENANT_A,
                    "INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, 'role.view')",
                    [self::TENANT_A, $role]);

                return ['role' => $role];
            }, function (array $c): array {
                $this->send('DELETE', '/roles/'.$c['role']);

                return [204, 'delete a role'];
            }],
            'CreateRoleGrantProposal' => [function (): array {
                $role = Uuid::v4()->toRfc4122();
                $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, ?)',
                    [$role, self::TENANT_A, 'Recipe Proposal '.substr($role, 0, 8)]);
                foreach (['invoice.pay', 'role.view'] as $code) {
                    $this->insertAsOwnerForTenant(self::TENANT_A,
                        'INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?)',
                        [self::TENANT_A, $role, $code]);
                }

                return ['subject' => $this->seedPrincipal(self::TENANT_A, []), 'role' => $role];
            }, function (array $c) use ($key): array {
                $this->send('POST', '/users/'.$c['subject'].'/role-grant-proposals', ['role_id' => $c['role']], $key());

                return [201, 'propose a role for another user'];
            }],
            // A pending proposal seeded by owner INSERT (the proposer a second user), read back.
            'GetRoleGrantProposal' => [function (): array {
                $proposal = Uuid::v4()->toRfc4122();
                $role = Uuid::v4()->toRfc4122();
                $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, ?)',
                    [$role, self::TENANT_A, 'Recipe Proposal '.substr($role, 0, 8)]);
                $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                    INSERT INTO role_grant_proposal (id, tenant_id, user_id, role_id, permission_codes, proposed_by_user_id, proposed_at, expires_at)
                    SELECT ?::uuid, ?::uuid, ?::uuid, ?::uuid, '["invoice.pay"]'::jsonb, ?::uuid, c.t, c.t + interval '336 hours'
                      FROM (SELECT clock_timestamp() AS t) c
                    SQL, [$proposal, self::TENANT_A, $this->seedPrincipal(self::TENANT_A, []), $role, $this->seedPrincipal(self::TENANT_A, ['role.assign'])]);

                return ['proposal' => $proposal];
            }, function (array $c): array {
                $this->send('GET', '/role-grant-proposals/'.$c['proposal']);

                return [200, 'read a grant proposal'];
            }],
            'ListRoleGrantProposals' => [$none, function (): array {
                $this->send('GET', '/role-grant-proposals');

                return [200, 'list the grant proposals'];
            }],
            // Users slice 1, C1: a second user holding one composed role (seeded as the owner), read back — a real row with
            // a real grant, so `roles[]` carries a reference.
            'GetUser' => [function (): array {
                return ['user' => $this->seedPrincipal(self::TENANT_A, ['role.view'])];
            }, function (array $c): array {
                $this->send('GET', '/users/'.$c['user']);

                return [200, 'read a user'];
            }],
            'ListUsers' => [$none, function (): array {
                $this->send('GET', '/users');

                return [200, 'list the workspace\'s users'];
            }],
            // Customers slice 1, C1: a seeded customer (its code letter-prefixed by `seedCustomer()`, so `WireScaleTest` never
            // reads an all-digit code as a decimal — CD-3), read back; the list is driven over a seeded one too, so
            // `ResponseShapeTest` walks a real item.
            'GetCustomer' => [fn (): array => ['customer' => $this->seedCustomer(self::TENANT_A, 'Recipe Customer')], function (array $c): array {
                $this->send('GET', '/customers/'.$c['customer']);

                return [200, 'read a customer'];
            }],
            'ListCustomers' => [fn (): array => ['customer' => $this->seedCustomer(self::TENANT_A, 'Recipe Listed Customer')], function (): array {
                $this->send('GET', '/customers');

                return [200, 'list the workspace\'s customers'];
            }],
            // Customers slice 1, C2: a create with a letter-bearing code (CD-3: `WireScaleTest` must never read an all-digit
            // code as a decimal) and every field sent; an update that changes the name and the terms of a seeded customer,
            // so the success path writes its `customer.updated` row.
            'CreateCustomer' => [$none, function () use ($key): array {
                $this->send('POST', '/customers', [
                    'code' => 'C-RECIPE', 'name' => 'Recipe New Customer', 'country_code' => 'GB', 'currency_code' => 'GBP',
                    'payment_terms_days' => 45,
                ], $key());

                return [201, 'create a customer'];
            }],
            'UpdateCustomer' => [fn (): array => ['customer' => $this->seedCustomer(self::TENANT_A, 'Recipe Updated Customer')], function (array $c): array {
                $this->send('PATCH', '/customers/'.$c['customer'], ['name' => 'Recipe Renamed Customer', 'payment_terms_days' => 60]);

                return [200, 'update a customer'];
            }],
            // Customers slice 1, C3: every recipe carries LETTER-bearing postal codes (CD-3: `WireScaleTest` reads an all-digit
            // text value as a decimal; design §5.4). The list seeds one address so `ResponseShapeTest` has an item to walk.
            'CreateCustomerAddress' => [fn (): array => ['customer' => $this->seedCustomer(self::TENANT_A, 'Recipe Address Customer')], function (array $c) use ($key): array {
                $this->send('POST', '/customers/'.$c['customer'].'/addresses', [
                    'kind' => 'shipping', 'line' => '10 Downing Street', 'city' => 'London', 'postal_code' => 'SW1A 2AA', 'country_code' => 'GB',
                ], $key());

                return [201, 'add an address to a customer'];
            }],
            'UpdateCustomerAddress' => [function (): array {
                $customer = $this->seedCustomer(self::TENANT_A, 'Recipe Moved Customer');

                return ['customer' => $customer, 'address' => $this->seedCustomerAddress(self::TENANT_A, $customer)];
            }, function (array $c): array {
                $this->send('PATCH', '/customers/'.$c['customer'].'/addresses/'.$c['address'], ['city' => 'Manchester', 'postal_code' => 'M3 2BW']);

                return [200, 'correct a customer address'];
            }],
            'ListCustomerAddresses' => [function (): array {
                $customer = $this->seedCustomer(self::TENANT_A, 'Recipe Listed Customer');
                $this->seedCustomerAddress(self::TENANT_A, $customer);

                return ['customer' => $customer];
            }, function (array $c): array {
                $this->send('GET', '/customers/'.$c['customer'].'/addresses');

                return [200, 'list a customer\'s addresses'];
            }],
            // Users slice 1, C3 (RED M-1): a subject holding nothing, and a workspace seeded with two composed roles that
            // conflict pairwise (`purchase_order.create` / `goods_receipt.create`, SoD-2) and a margin pair
            // (`purchase_order.view` / `invoice.view`), so `ResponseShapeTest`'s walker examines an expansion at
            // `items[*].conflicts_with[*].role` and at `items[*].margin_exposure_with[*]`.
            'ListOfferedRoles' => [function (): array {
                foreach (['Recipe PO create' => 'purchase_order.create', 'Recipe GR create' => 'goods_receipt.create',
                    'Recipe PO view' => 'purchase_order.view', 'Recipe invoice view' => 'invoice.view'] as $name => $code) {
                    $role = Uuid::v4()->toRfc4122();
                    $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, ?)',
                        [$role, self::TENANT_A, $name.' '.substr($role, 0, 8)]);
                    $this->insertAsOwnerForTenant(self::TENANT_A,
                        'INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?)',
                        [self::TENANT_A, $role, $code]);
                }

                return ['subject' => $this->seedPrincipal(self::TENANT_A, [])];
            }, function (array $c): array {
                $this->send('GET', '/users/'.$c['subject'].'/offered-roles');

                return [200, 'read the roles that could be given to a user'];
            }],
            // Roles slice 3, C2: a pending proposal of a composed X(admin) role (`invoice.pay`, `role.view`, both held by the
            // actor, so E-15c passes) for a user holding nothing, by a proposer who still holds `role.assign` (C9). The
            // actor holds every declared code and never the marker (no built operation declares it), so — Q-6 (c′) — the
            // request step makes it an owner for this one request and takes the marker back afterwards: every other
            // recipe, before and after, still runs as a non-owner (`CreateRoleGrantProposal`'s needs exactly that).
            'ConfirmRoleGrantProposal' => [function (): array {
                $proposal = Uuid::v4()->toRfc4122();
                $role = Uuid::v4()->toRfc4122();
                $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, ?)',
                    [$role, self::TENANT_A, 'Recipe Proposal '.substr($role, 0, 8)]);
                foreach (['invoice.pay', 'role.view'] as $code) {
                    $this->insertAsOwnerForTenant(self::TENANT_A,
                        'INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?)',
                        [self::TENANT_A, $role, $code]);
                }
                $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                    INSERT INTO role_grant_proposal (id, tenant_id, user_id, role_id, permission_codes, proposed_by_user_id, proposed_at, expires_at)
                    SELECT ?::uuid, ?::uuid, ?::uuid, ?::uuid, '["invoice.pay", "role.view"]'::jsonb, ?::uuid, c.t, c.t + interval '336 hours'
                      FROM (SELECT clock_timestamp() AS t) c
                    SQL, [$proposal, self::TENANT_A, $this->seedPrincipal(self::TENANT_A, []), $role, $this->seedPrincipal(self::TENANT_A, ['role.assign'])]);

                return ['proposal' => $proposal];
            }, function (array $c): array {
                $marker = Uuid::v4()->toRfc4122();
                $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                    INSERT INTO role_permission (id, tenant_id, role_id, permission_code)
                    SELECT ?::uuid, ur.tenant_id, ur.role_id, 'tenant_settings.edit' FROM user_role ur WHERE ur.user_id = ?::uuid LIMIT 1
                    SQL, [$marker, $this->actorId]);
                try {
                    $this->send('POST', '/role-grant-proposals/'.$c['proposal'].'/confirmation');
                } finally {
                    $this->insertAsOwnerForTenant(self::TENANT_A, 'DELETE FROM role_permission WHERE id = ?::uuid', [$marker]);
                }

                return [200, 'confirm a grant proposal'];
            }],
            // Roles slice 3, C3: a pending proposal by a second user who manages roles (so the rig actor is NOT the
            // proposer: R-15), rejected by the actor made an owner for this one request, as Confirm's recipe does.
            'RejectRoleGrantProposal' => [function (): array {
                $proposer = $this->seedPrincipal(self::TENANT_A, ['role.assign']);
                $proposal = Uuid::v4()->toRfc4122();
                $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                    INSERT INTO role_grant_proposal (id, tenant_id, user_id, role_id, permission_codes, proposed_by_user_id, proposed_at, expires_at)
                    SELECT ?::uuid, ?::uuid, ?::uuid, (SELECT ur.role_id FROM user_role ur WHERE ur.user_id = ?::uuid LIMIT 1), '["role.view"]'::jsonb, ?::uuid, c.t, c.t + interval '336 hours'
                      FROM (SELECT clock_timestamp() AS t) c
                    SQL, [$proposal, self::TENANT_A, $this->seedPrincipal(self::TENANT_A, []), $proposer, $proposer]);

                return ['proposal' => $proposal];
            }, function (array $c): array {
                $marker = Uuid::v4()->toRfc4122();
                $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                    INSERT INTO role_permission (id, tenant_id, role_id, permission_code)
                    SELECT ?::uuid, ur.tenant_id, ur.role_id, 'tenant_settings.edit' FROM user_role ur WHERE ur.user_id = ?::uuid LIMIT 1
                    SQL, [$marker, $this->actorId]);
                try {
                    $this->send('POST', '/role-grant-proposals/'.$c['proposal'].'/rejection', ['reason' => 'Recipe']);
                } finally {
                    $this->insertAsOwnerForTenant(self::TENANT_A, 'DELETE FROM role_permission WHERE id = ?::uuid', [$marker]);
                }

                return [200, 'reject a grant proposal'];
            }],
            // Roles slice 3, C3: a pending proposal BY the rig actor (withdrawing needs only `role.assign` and being its
            // proposer), of a role the actor holds, for a user holding nothing.
            'CancelRoleGrantProposal' => [function (): array {
                $proposal = Uuid::v4()->toRfc4122();
                $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                    INSERT INTO role_grant_proposal (id, tenant_id, user_id, role_id, permission_codes, proposed_by_user_id, proposed_at, expires_at)
                    SELECT ?::uuid, ?::uuid, ?::uuid, (SELECT ur.role_id FROM user_role ur WHERE ur.user_id = ?::uuid LIMIT 1), '["role.view"]'::jsonb, ?::uuid, c.t, c.t + interval '336 hours'
                      FROM (SELECT clock_timestamp() AS t) c
                    SQL, [$proposal, self::TENANT_A, $this->seedPrincipal(self::TENANT_A, []), (string) $this->actorId, (string) $this->actorId]);

                return ['proposal' => $proposal];
            }, function (array $c): array {
                $this->send('POST', '/role-grant-proposals/'.$c['proposal'].'/cancellation');

                return [200, 'withdraw a grant proposal'];
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

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
            'GetGoodsReceipt' => [fn (): array => $ordered('PO-GR-GET'), function (array $c) use ($receive): array {
                $receive($c);
                $this->send('GET', (string) $this->client()->getResponse()->headers->get('Location'));

                return [200, 'receive, then read'];
            }],
            'CreateGoodsReceipt' => [fn (): array => $ordered('PO-GR-NEW'), function (array $c) use ($receive): array {
                $receive($c);

                return [201, 'receive'];
            }],
            'ListPurchaseOrders' => [$none, function (): array {
                $this->send('GET', '/purchase-orders');

                return [200, 'list'];
            }],
            'ListPurchaseOrderLines' => [fn (): array => $draft('PO-LL'), function (array $c): array {
                $this->send('GET', '/purchase-orders/'.$c['order'].'/lines');

                return [200, 'list'];
            }],
            'CreatePurchaseOrderLine' => [function () use ($draft): array {
                $d = $draft('PO-CL');
                $this->seedSupplierProduct(self::TENANT_A, $d['supplier'], $d['item']);

                return $d;
            }, function (array $c): array {
                $this->send('POST', '/purchase-orders/'.$c['order'].'/lines', ['inventory_item_id' => $c['item'], 'quantity' => '1', 'unit_cost' => '1'], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);

                return [201, 'create'];
            }],
            'UpdatePurchaseOrderLine' => [fn (): array => $draft('PO-UL'), function (array $c): array {
                $this->send('PATCH', '/purchase-orders/'.$c['order'].'/lines/'.$c['line'], ['quantity' => '2']);

                return [200, 'update'];
            }],
            'DeletePurchaseOrderLine' => [fn (): array => $draft('PO-DL'), function (array $c): array {
                $this->send('DELETE', '/purchase-orders/'.$c['order'].'/lines/'.$c['line']);

                return [204, 'delete'];
            }],
            'GetPurchaseOrder' => [fn (): array => $draft('PO-GET'), function (array $c): array {
                $this->send('GET', '/purchase-orders/'.$c['order']);

                return [200, 'read'];
            }],
            'CreateSalesOrder' => [fn (): array => $salesWorkspace('SO'), function (array $c) use ($raiseSalesOrder): array {
                $raiseSalesOrder($c);

                return [201, 'create'];
            }],
            // The read is driven through the writer rather than off a fixture, so the row it reads is a
            // row the operation wrote — which is what makes `created_by` non-null in the body every
            // reference check walks. A seeded `sales_order` would leave the column NULL and the
            // expansion unexamined.
            'GetSalesOrder' => [fn (): array => $salesWorkspace('SO-GET'), function (array $c) use ($raiseSalesOrder): array {
                $raiseSalesOrder($c);
                $this->send('GET', (string) $this->client()->getResponse()->headers->get('Location'));

                return [200, 'create, then read'];
            }],
            // Driven through the writer for `GetSalesOrder`'s reason and one of its own: the row it
            // confirms must have a live line, and `seedSalesOrder` deliberately seeds none. The `Location`
            // the create returns is §4.2's row URI, so the confirmation path is built from it rather than
            // from a second copy of the template.
            'ConfirmSalesOrder' => [fn (): array => $salesWorkspace('SO-CONF'), function (array $c) use ($raiseSalesOrder): array {
                $raiseSalesOrder($c);
                $this->send('POST', (string) $this->client()->getResponse()->headers->get('Location').'/confirmation');

                return [200, 'create, then confirm'];
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
            'UpdatePurchaseOrder' => [fn (): array => $draft('PO-UPD'), function (array $c): array {
                $this->send('PATCH', '/purchase-orders/'.$c['order'], ['expected_at' => '2030-01-01T00:00:00Z']);

                return [200, 'update'];
            }],
            'DeletePurchaseOrder' => [fn (): array => $draft('PO-DEL'), function (array $c): array {
                $this->send('DELETE', '/purchase-orders/'.$c['order']);

                return [204, 'delete'];
            }],
            'ApprovePurchaseOrder' => [fn (): array => $draft('PO-APP'), function (array $c): array {
                $this->send('POST', '/purchase-orders/'.$c['order'].'/approval', []);

                return [200, 'approve'];
            }],
            'CancelPurchaseOrder' => [fn (): array => $draft('PO-CAN'), function (array $c): array {
                $this->send('POST', '/purchase-orders/'.$c['order'].'/cancellation', ['reason' => 'Supplier withdrew']);

                return [200, 'cancel'];
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

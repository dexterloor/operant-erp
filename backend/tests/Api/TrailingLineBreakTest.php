<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Customers\Application\CustomerWriter;
use App\Modules\Suppliers\Application\SupplierProductWriter;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **G-103: a value followed by one line break is refused at its field, with the field's own code and sentence** — the
 * paths no success recipe reaches (`RequestBodySchemaProbeTest` probe 7 covers the ones that do). PCRE's `$` without `D`
 * also matches before one final `\n`; the 40 patterns that ended that way now end in `\z`.
 *
 * Before (measured over HTTP on 2026-11-17, `docs/session13/g103-build-progress.md`): a quantity, or a unit cost or price
 * sent with a quantity, reached bcmath and answered 500; a unit cost or price sent alone on an order-line edit, and a
 * catalogue unit cost on create, was stored as the number written (Postgres numeric input skips trailing whitespace); a
 * country code was refused as `unknown_reference`. Each case asserts the exact problem body and that nothing was written.
 *
 * E-15…E-17 pin the fields that are TRIMMED, not refused: a trailing line break there is dropped, as before.
 */
final class TrailingLineBreakTest extends PurchaseOrderTestCase
{
    use OperationSuccessRecipes;

    private const PO_QUANTITY = 'This field must be a positive decimal string with at most 13 integer digits and 6 fractional digits.';

    private const PO_MONEY = 'This field must be a non-negative decimal string with at most 15 integer digits and 4 fractional digits.';

    /** E-1: a unit cost alone on a purchase-order line edit was stored; it is refused, and the line and total are unchanged. */
    #[Test]
    public function aPurchaseOrderLineUnitCostAloneIsRefused(): void
    {
        $d = $this->prepare('UpdatePurchaseOrderLine');
        $before = $this->purchaseLine($d['line']);
        $this->send('PATCH', '/purchase-orders/'.$d['order'].'/lines/'.$d['line'], ['unit_cost' => "12.5\n"]);
        $this->assertRefused('/unit_cost', 'not_a_decimal', self::PO_MONEY);
        self::assertSame($before, $this->purchaseLine($d['line']), 'The refused edit changed the line or the order total.');
    }

    /** E-2 and E-3: with a quantity, or as the quantity, the value reached bcmath (500). One line; a refusal writes nothing. */
    #[Test]
    public function aPurchaseOrderLineQuantityOrCostWithAQuantityIsRefused(): void
    {
        $d = $this->prepare('UpdatePurchaseOrderLine');
        $before = $this->purchaseLine($d['line']);
        foreach ([[['quantity' => '2', 'unit_cost' => "12.5\n"], '/unit_cost', 'not_a_decimal', self::PO_MONEY],
            [['quantity' => "2\n"], '/quantity', 'not_a_positive_decimal', self::PO_QUANTITY]] as [$body, $pointer, $code, $message]) {
            $this->send('PATCH', '/purchase-orders/'.$d['order'].'/lines/'.$d['line'], $body);
            $this->assertRefused($pointer, $code, $message);
            self::assertSame($before, $this->purchaseLine($d['line']));
        }
    }

    /** E-4: on a nested create, at `/lines/0/…`, and no order is written. */
    #[Test]
    public function aNestedPurchaseOrderLineValueIsRefusedAndNoOrderIsWritten(): void
    {
        $c = $this->prepare('CreatePurchaseOrder');
        foreach ([['quantity', "2\n", 'not_a_positive_decimal', self::PO_QUANTITY], ['unit_cost', "12.5\n", 'not_a_decimal', self::PO_MONEY]] as [$field, $value, $code, $message]) {
            $line = ['inventory_item_id' => $c['item'], 'quantity' => '1', 'unit_cost' => '1', $field => $value];
            $orders = $this->rowsIn('purchase_order');
            $this->send('POST', '/purchase-orders', ['supplier_id' => $c['supplier'], 'warehouse_id' => $c['warehouse'], 'currency_code' => 'EUR', 'lines' => [$line]], $this->key());
            $this->assertRefused('/lines/0/'.$field, $code, $message);
            self::assertSame($orders, $this->rowsIn('purchase_order'), 'A refused create wrote an order.');
        }
    }

    /** E-5: a unit price alone on a sales-order line edit was stored; it is refused, and the line and total are unchanged. */
    #[Test]
    public function aSalesOrderLineUnitPriceAloneIsRefused(): void
    {
        [$order, $line] = $this->raiseSalesOrder($this->prepare('UpdateSalesOrderLine'));
        $before = $this->salesLine($line);
        $this->send('PATCH', $order.'/lines/'.$line, ['unit_price' => "12.5\n"]);
        $this->assertRefused('/unit_price', 'not_a_decimal', self::PO_MONEY);
        self::assertSame($before, $this->salesLine($line));
    }

    /** E-6: a quantity on a sales-order line create reached bcmath (500); it is refused and no line is written. */
    #[Test]
    public function aSalesOrderLineQuantityIsRefusedOnCreate(): void
    {
        $c = $this->prepare('CreateSalesOrderLine');
        [$order] = $this->raiseSalesOrder($c);
        $lines = $this->rowsIn('sales_order_line');
        $this->send('POST', $order.'/lines', ['inventory_item_id' => $c['second_item'], 'quantity' => "2\n", 'unit_price' => '3'], $this->key());
        $this->assertRefused('/quantity', 'not_a_positive_decimal', self::PO_QUANTITY);
        self::assertSame($lines, $this->rowsIn('sales_order_line'));
    }

    /** E-7: a catalogue unit cost on create was stored; it is refused and no catalogue entry is written. */
    #[Test]
    public function aCatalogueUnitCostIsRefusedOnCreate(): void
    {
        $c = $this->prepare('CreateSupplierProduct');
        $this->send('POST', '/suppliers/'.$c['supplier'].'/products', [
            'inventory_item_id' => $c['item'], 'supplier_sku' => 'ACME-SKU-A', 'unit_cost' => "12.5\n", 'lead_time_days' => 9, 'minimum_order_quantity' => '10',
        ], $this->key());
        $this->assertRefused('/unit_cost', 'not_a_decimal', SupplierProductWriter::UNIT_COST_SHAPE);
        self::assertSame(0, $this->countWhere('supplier_product', 'supplier_id', $c['supplier']));
    }

    /** E-8: on a catalogue edit, the minimum order and the unit cost reached bcmath (500); each is refused, the entry unchanged. */
    #[Test]
    public function aCatalogueEditIsRefused(): void
    {
        $c = $this->prepare('UpdateSupplierProduct');
        foreach ([['minimum_order_quantity', "10\n", 'not_a_positive_decimal', SupplierProductWriter::MOQ_SHAPE], ['unit_cost', "7.25\n", 'not_a_decimal', SupplierProductWriter::UNIT_COST_SHAPE]] as [$field, $value, $code, $message]) {
            $before = $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT unit_cost, min_order_quantity, updated_at FROM supplier_product WHERE id = ?::uuid', [$c['product']]);
            $this->send('PATCH', '/suppliers/'.$c['supplier'].'/products/'.$c['product'], [$field => $value]);
            $this->assertRefused('/'.$field, $code, $message);
            self::assertSame($before, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT unit_cost, min_order_quantity, updated_at FROM supplier_product WHERE id = ?::uuid', [$c['product']]));
        }
    }

    /** E-9: a country code was refused as `unknown_reference`; it is refused as its shape, with the shape sentence. */
    #[Test]
    public function aCountryCodeIsRefusedAsItsShape(): void
    {
        $customers = $this->rowsIn('customer');
        $this->send('POST', '/customers', ['code' => 'C-G103', 'name' => 'G103 Customer', 'country_code' => "GB\n", 'currency_code' => 'GBP', 'payment_terms_days' => 45], $this->key());
        $this->assertRefused('/country_code', 'not_a_country_code', CustomerWriter::COUNTRY_SHAPE);
        self::assertSame($customers, $this->rowsIn('customer'));
    }

    /** E-10: a permission code was already refused (the PHP comparison); it no longer reaches the database, and grants nothing. */
    #[Test]
    public function aPermissionCodeIsRefusedAndGrantsNothing(): void
    {
        $roles = $this->rowsIn('role');
        $grants = $this->rowsIn('role_permission');
        $this->send('POST', '/roles', ['name' => 'G103 role', 'permission_codes' => ["inventory_item.view\n"]], $this->key());
        $this->assertRefused('/permission_codes/0', 'unknown_reference', 'There is no permission with this code.');
        self::assertSame($roles, $this->rowsIn('role'));
        self::assertSame($grants, $this->rowsIn('role_permission'));
    }

    /**
     * E-11: a `Bearer` header followed by a line break was accepted (the capture stopped before the `\n`); it is refused.
     * The test client delivers the header with its line break (measured 2026-11-17), so this is an HTTP test; the control
     * shows the same token is valid.
     */
    #[Test]
    public function aBearerHeaderFollowedByALineBreakIsRefused(): void
    {
        $token = $this->tokenFor(self::TENANT_A, (string) $this->actorId);
        $this->send('GET', '/me', null, ['Authorization' => 'Bearer '.$token."\n"]);
        self::assertStringEndsWith("\n", (string) $this->client()->getRequest()->headers->get('Authorization'), 'The test client stripped the line break, so this case tests nothing.');
        self::assertSame(401, $this->httpStatus(), $this->responseContent());
        $this->send('GET', '/me', null, ['Authorization' => 'Bearer '.$token]);
        self::assertSame(200, $this->httpStatus(), $this->responseContent());
    }

    /** E-12: the controls — the value itself is accepted; `\r\n` and two line breaks were refused before and are now. */
    #[Test]
    public function theControlsHold(): void
    {
        $d = $this->prepare('UpdatePurchaseOrderLine');
        $this->send('PATCH', '/purchase-orders/'.$d['order'].'/lines/'.$d['line'], ['unit_cost' => '12.5']);
        self::assertSame(200, $this->httpStatus());
        foreach (["12.5\r\n", "12.5\n\n"] as $value) {
            $this->send('PATCH', '/purchase-orders/'.$d['order'].'/lines/'.$d['line'], ['unit_cost' => $value]);
            $this->assertRefused('/unit_cost', 'not_a_decimal', self::PO_MONEY);
        }
    }

    /** E-15: a role name is trimmed, not refused. */
    #[Test]
    public function aRoleNameIsTrimmed(): void
    {
        $this->send('POST', '/roles', ['name' => "Buyers G103\n", 'permission_codes' => ['role.view']], $this->key());
        self::assertSame(201, $this->httpStatus());
        $this->send('GET', (string) $this->client()->getResponse()->headers->get('Location'));
        self::assertSame('Buyers G103', $this->json()['name']);
    }

    /** E-16: a person's display name is trimmed, not refused. */
    #[Test]
    public function aDisplayNameIsTrimmed(): void
    {
        $user = $this->seedPrincipal(self::TENANT_A, []);
        $this->send('PATCH', '/users/'.$user, ['display_name' => "Ada\n"]);
        self::assertSame(200, $this->httpStatus());
        $this->send('GET', '/users/'.$user);
        self::assertSame('Ada', $this->json()['display_name']);
    }

    /** E-17: a sign-in email is trimmed: the same account signs in. */
    #[Test]
    public function aSignInEmailIsTrimmed(): void
    {
        try {
            $password = 'a-g103-password-long-enough';
            $this->setPassword(self::TENANT_A, (string) $this->actorId, $password);
            $email = (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT email FROM app_user WHERE id = ?::uuid', [$this->actorId])[0]['email'];
            $this->send('POST', '/auth/token', ['workspace' => 'tenant-a', 'email' => $email."\n", 'password' => $password]);
            self::assertSame(200, $this->httpStatus(), $this->responseContent());
            $token = (string) ($this->json()['access_token'] ?? '');
            $this->send('GET', '/me', null, ['Authorization' => 'Bearer '.$token]);
            self::assertSame($this->actorId, $this->json()['id'] ?? null);
        } finally {
            $this->owner()->executeStatement('TRUNCATE auth_attempt, auth_ip_budget, auth_lockout');
        }
    }

    // ---------------------------------------------------------------------------------------------------------------------

    private function assertRefused(string $pointer, string $code, string $message): void
    {
        self::assertSame(422, $this->httpStatus(), $this->responseContent());
        self::assertSame([['pointer' => $pointer, 'code' => $code, 'message' => $message]], $this->json()['errors'] ?? null);
    }

    /** @return array<string, mixed> */
    private function prepare(string $operation): array
    {
        return $this->successRecipes()[$operation][0]();
    }

    /** @return array{0: string, 1: string} the order's path and its one line's id */
    private function raiseSalesOrder(array $c): array
    {
        $this->send('POST', '/sales-orders', ['customer_id' => $c['customer'], 'warehouse_id' => $c['warehouse'], 'currency_code' => 'EUR',
            'ship_to_address_id' => $c['address'], 'lines' => [['inventory_item_id' => $c['item'], 'quantity' => '1', 'unit_price' => '1']]], $this->key());
        self::assertSame(201, $this->httpStatus());
        $order = (string) $this->client()->getResponse()->headers->get('Location');
        $this->send('GET', $order.'/lines');

        return [$order, (string) $this->json()['items'][0]['id']];
    }

    /** @return array<string, string> */
    private function key(): array
    {
        return [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()];
    }

    /** @return list<array<string, mixed>> */
    private function purchaseLine(string $line): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT l.quantity_ordered, l.unit_cost, l.updated_at, o.total_amount FROM purchase_order_line l JOIN purchase_order o ON o.id = l.purchase_order_id WHERE l.id = ?::uuid', [$line]);
    }

    /** @return list<array<string, mixed>> */
    private function salesLine(string $line): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT l.quantity, l.unit_price, l.updated_at, o.total_amount FROM sales_order_line l JOIN sales_order o ON o.id = l.sales_order_id WHERE l.id = ?::uuid', [$line]);
    }

    private function rowsIn(string $table): int
    {
        return (int) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) AS n FROM '.$table)[0]['n'];
    }

    private function countWhere(string $table, string $column, string $id): int
    {
        return (int) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) AS n FROM '.$table.' WHERE '.$column.' = ?::uuid', [$id])[0]['n'];
    }
}

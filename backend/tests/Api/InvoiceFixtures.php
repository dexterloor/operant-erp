<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use App\Modules\UserManagement\Domain\DefaultRoles;
use Symfony\Component\Uid\Uuid;

/**
 * The invoice chain's fixtures — a shipment written THROUGH THE API, an invoice issued on it, and the default roles to
 * act as. Moved out of `InvoiceIssueTest` (sales step 6 C3) by C5, unchanged, so `InvoicePaymentTest` (C5) and the
 * reversal tests (C6) drive the same shipment rather than a second copy of it.
 *
 * @phpstan-require-extends PurchaseOrderTestCase
 */
trait InvoiceFixtures
{
    protected function issue(string $fulfilmentId): void
    {
        $this->send('POST', '/fulfilments/'.$fulfilmentId.'/invoices', null, ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
    }

    /** @return string the new actor's id */
    protected function actAsRole(string $role): string
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor($role));

        return (string) $this->actorId;
    }

    /**
     * A confirmed order of `$tenant`, seeded as the owner — one line per `[ordered, unit price, shipped]` — and one
     * fulfilment of it shipped THROUGH THE API by a principal holding everything. The actor is left as that principal.
     *
     * @param list<array{0: string, 1: string, 2: string}> $lines
     *
     * @return array{order: string, customer: string, lines: list<string>, fulfilment: string, fulfilment_number: string}
     */
    protected function shipped(string $tag, array $lines, int $terms = 30, string $currency = 'EUR', string $tenant = self::TENANT_A): array
    {
        $this->actAs($tenant);
        $customer = $this->seedCustomer($tenant, 'Customer '.$tag);
        $this->executeAsOwnerForTenant($tenant, 'UPDATE customer SET payment_terms_days = ? WHERE id = ?::uuid', [$terms, $customer]);
        $warehouse = $this->seedWarehouse($tenant, 'WH '.$tag);
        $order = $this->seedSalesOrder($tenant, self::freshNumber('SO'), $customer, $warehouse, SalesOrder::STATUS_CONFIRMED, '2026-09-20T10:00:00Z');
        $this->executeAsOwnerForTenant($tenant, 'UPDATE sales_order SET currency_code = ? WHERE id = ?::uuid', [$currency, $order]);

        $bin = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant($tenant,
            "INSERT INTO storage_location (id, tenant_id, warehouse_id, code, kind) VALUES (?::uuid, ?::uuid, ?::uuid, 'A-01', 'bin')",
            [$bin, $tenant, $warehouse]);

        $lineIds = [];
        $shipping = [];
        foreach ($lines as $i => [$ordered, $price, $ship]) {
            $item = $this->seedItem($tenant, 'SKU-'.$tag.'-'.$i);
            $lineIds[] = $line = $this->seedSalesOrderLine($tenant, $order, $item, lineNumber: $i + 1, quantity: $ordered, unitPrice: $price);
            $this->insertAsOwnerForTenant($tenant,
                'INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric)',
                [Uuid::v4()->toRfc4122(), $tenant, $item, $bin, $ordered]);
            $shipping[] = ['sales_order_line_id' => $line, 'quantity' => $ship, 'storage_location_id' => $bin];
        }

        $this->send('POST', '/sales-orders/'.$order.'/fulfilments', ['lines' => $shipping], ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), 'Fixture: the shipment was refused. '.(string) $this->client()->getResponse()->getContent());

        return [
            'order' => $order,
            'customer' => $customer,
            'lines' => $lineIds,
            'fulfilment' => $this->json()['id'],
            'fulfilment_number' => $this->json()['fulfilment_number'],
        ];
    }


    /**
     * An invoice ISSUED THROUGH THE API on a one-line shipment of `$tag` (priced 10.0000, 30-day terms). The actor is
     * left as a `sales_rep`, the issuer.
     *
     * @return array{invoice: string, number: string, issuer: string, shipment: array{order: string, customer: string, lines: list<string>, fulfilment: string, fulfilment_number: string}}
     */
    protected function issuedInvoice(string $tag, string $tenant = self::TENANT_A): array
    {
        $shipment = $this->shipped($tag, [['1', '10.0000', '1']], tenant: $tenant);
        $this->actAs($tenant, DefaultRoles::codesFor(DefaultRoles::SALES_REP));
        $issuer = (string) $this->actorId;
        $this->issue($shipment['fulfilment']);
        self::assertSame(201, $this->httpStatus(), 'Fixture: the invoice was not issued. '.(string) $this->client()->getResponse()->getContent());

        return ['invoice' => $this->json()['id'], 'number' => $this->json()['invoice_number'], 'issuer' => $issuer, 'shipment' => $shipment];
    }
}

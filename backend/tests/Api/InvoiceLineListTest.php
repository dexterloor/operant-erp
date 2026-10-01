<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Sales\ApiResource\InvoiceLineResource;
use App\Modules\UserManagement\Domain\DefaultRoles;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;

/**
 * **`ListInvoiceLines` — sales step 7 C0** (design §4.5, §14 items 3 and 25; owner ruling Q-B: `invoice.view`).
 *
 * Shipments and invoices go THROUGH THE API; credit notes are seeded as the owner (no credit writer exists at C0 —
 * the G-7 caveat, `CreditFixtures`).
 */
final class InvoiceLineListTest extends PurchaseOrderTestCase
{
    use InvoiceFixtures;
    use CreditFixtures;

    /**
     * Two lines billed, the per-line rounding the issuer used (D-A), and this invoice's credits per shipped line. The
     * zero members are literal `"0.000000"` / `"0.0000"` (a bare `COALESCE(SUM(…), 0)` would serialize at scale 0, which
     * `WireScaleTest` cannot see once the artefact records it — the `3e55a99` lesson).
     */
    #[Test]
    public function anInvoicesLinesShowWhatWasBilledAndWhatWasCredited(): void
    {
        $s = $this->shipped('IL-A', [['4', '0.0001', '0.5'], ['4', '12.5000', '2']]);
        $this->send('GET', '/fulfilments/'.$s['fulfilment']);
        $lines = array_column($this->json()['lines'], 'id');
        $this->actAsRole(DefaultRoles::SALES_REP);
        $this->issue($s['fulfilment']);
        $invoice = $this->json()['id'];
        $this->seedCreditNote($invoice, '12.5000', [[$lines[1], '1', '12.5000']]);

        $this->actAsRole(DefaultRoles::FINANCE);
        $this->send('GET', '/invoices/'.$invoice.'/lines');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertNull($body['next_cursor']);
        self::assertNull($body['previous_cursor']);
        self::assertTrue($body['tenant_has_rows']);
        self::assertCount(2, $body['items']);

        [$first, $second] = $body['items'];
        self::assertSame($lines[0], $first['id'], 'An invoice line is named by its shipped line\'s id.');
        self::assertSame(['id', 'fulfilment_line', 'inventory_item', 'quantity_billed', 'unit_price', 'amount_billed', 'quantity_credited', 'amount_credited'], array_keys($first));
        self::assertSame(['id', 'sales_order_line', 'inventory_item', 'quantity'], array_keys($first['fulfilment_line']), 'Q-H money side: binless, with the shipped quantity.');
        self::assertSame(['0.500000', '0.500000', '0.0001', '0.0001', '0.000000', '0.0000'],
            [$first['fulfilment_line']['quantity'], $first['quantity_billed'], $first['unit_price'], $first['amount_billed'], $first['quantity_credited'], $first['amount_credited']]);
        self::assertSame(['2.000000', '12.5000', '25.0000', '1.000000', '12.5000'],
            [$second['quantity_billed'], $second['unit_price'], $second['amount_billed'], $second['quantity_credited'], $second['amount_credited']]);
        self::assertSame(2, $second['fulfilment_line']['sales_order_line']['line_number']);

        $this->send('GET', '/invoices/'.$invoice);
        self::assertSame($this->json()['total_amount'], bcadd($first['amount_billed'], $second['amount_billed'], 4),
            'The lines do not add up to the invoice: the billed derivation is not the issuer\'s.');
        self::assertSame('12.5000', $this->json()['amount_credited']);
    }

    /** A deducting return (Q-L (a′): recorded before issue by an owner) is billed less, per line. */
    #[Test]
    public function aReturnDeductedBeforeIssueIsBilledLess(): void
    {
        $s = $this->shipped('IL-B', [['5', '10.0000', '4']]);
        $this->send('GET', '/fulfilments/'.$s['fulfilment']);
        $line = $this->json()['lines'][0];
        $this->actAsRole(DefaultRoles::OWNER);
        $this->send('POST', '/fulfilments/'.$s['fulfilment'].'/sales-returns', ['reason' => 'other', 'lines' => [
            ['fulfilment_line_id' => $line['id'], 'quantity' => '1', 'storage_location_id' => $line['storage_location']['id']],
        ]], ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), 'Fixture: the return. '.(string) $this->client()->getResponse()->getContent());
        self::assertTrue($this->json()['deducts'], 'Fixture: an owner\'s pre-invoice return deducts.');
        $this->actAsRole(DefaultRoles::SALES_REP);
        $this->issue($s['fulfilment']);
        $invoice = $this->json()['id'];

        $this->actAsRole(DefaultRoles::FINANCE);
        $this->send('GET', '/invoices/'.$invoice.'/lines');
        $item = $this->json()['items'][0];
        self::assertSame(['4.000000', '3.000000', '30.0000'], [$item['fulfilment_line']['quantity'], $item['quantity_billed'], $item['amount_billed']]);
    }

    /** Money: `invoice.view` alone (Q-B; V2-S-8 forbids `fulfilment.view`); a foreign or unknown invoice is the same 404. */
    #[Test]
    public function theLinesAreReadUnderInvoiceViewAndOnlyInThisWorkspace(): void
    {
        $mine = $this->issuedInvoice('IL-C');
        $theirs = $this->issuedInvoice('IL-D', self::TENANT_B);

        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);
        $this->send('GET', '/invoices/'.$mine['invoice'].'/lines');
        self::assertSame(403, $this->httpStatus());

        $this->actAsRole(DefaultRoles::FINANCE);
        $this->send('GET', '/invoices/'.$theirs['invoice'].'/lines');
        self::assertSame(404, $this->httpStatus());
        $foreign = (string) $this->client()->getResponse()->getContent();
        $this->send('GET', '/invoices/'.Uuid::v4()->toRfc4122().'/lines');
        self::assertSame(404, $this->httpStatus());
        self::assertSame($foreign, (string) $this->client()->getResponse()->getContent());

        $this->send('GET', '/invoices/'.$mine['invoice'].'/lines?limit=10');
        self::assertSame(422, $this->httpStatus(), 'A bounded list accepted a paging parameter.');
    }

    /** The inline item schema is the resource's own members (the `SalesOrderLine` precedent of 2026-09-21). */
    #[Test]
    public function theWireSchemaIsTheResourcesOwnMembers(): void
    {
        $members = [];
        foreach ((new \ReflectionClass(InvoiceLineResource::class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $renamed = $property->getAttributes(SerializedName::class)[0] ?? null;
            $members[] = null === $renamed ? $property->getName() : (string) $renamed->newInstance()->serializedName;
        }

        self::assertSame($members, array_keys(InvoiceLineResource::WIRE_SCHEMA['properties']));
        self::assertSame($members, InvoiceLineResource::WIRE_SCHEMA['required']);
        self::assertSame(['id', 'sales_order_line', 'inventory_item', 'quantity'], array_keys(InvoiceLineResource::WIRE_SCHEMA['properties']['fulfilment_line']['properties']));
    }
}

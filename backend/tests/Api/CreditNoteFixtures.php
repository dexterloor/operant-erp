<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Domain\DefaultRoles;
use Symfony\Component\Uid\Uuid;

/**
 * **Shipments, invoices, returns and credit notes driven THROUGH THE API** (sales step 7 C1), for the credit-note
 * tests and the races beside the holder harness. Every helper leaves the actor as the role it acted as.
 *
 * @phpstan-require-extends PurchaseOrderTestCase
 */
trait CreditNoteFixtures
{
    /**
     * A shipment of `$lines` (`[ordered, unit price, shipped]`), shipped by a `warehouse_operator`, and its invoice,
     * issued by a `sales_rep`.
     *
     * @param list<array{0: string, 1: string, 2: string}> $lines
     *
     * @return array{invoice: string, number: string, fulfilment: string, order: string, lines: list<string>, bin: string}
     */
    protected function invoicedShipment(string $tag, array $lines, bool $issue = true): array
    {
        $s = $this->shipped($tag, $lines);
        $shipper = $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE fulfilment SET shipped_by_user_id = ?::uuid WHERE id = ?::uuid', [$shipper, $s['fulfilment']]);
        $this->send('GET', '/fulfilments/'.$s['fulfilment']);
        self::assertSame(200, $this->httpStatus(), 'Fixture: the shipment is not readable.');
        $shipment = [
            'invoice' => '', 'number' => '', 'fulfilment' => $s['fulfilment'], 'order' => $s['order'],
            'lines' => array_column($this->json()['lines'], 'id'),
            'bin' => $this->json()['lines'][0]['storage_location']['id'],
        ];
        if (!$issue) {
            return $shipment;
        }

        return $this->issueOn($shipment);
    }

    /**
     * @param array{invoice: string, number: string, fulfilment: string, order: string, lines: list<string>, bin: string} $shipment
     *
     * @return array{invoice: string, number: string, fulfilment: string, order: string, lines: list<string>, bin: string}
     */
    protected function issueOn(array $shipment): array
    {
        $this->actAsRole(DefaultRoles::SALES_REP);
        $this->issue($shipment['fulfilment']);
        self::assertSame(201, $this->httpStatus(), 'Fixture: the invoice. '.(string) $this->client()->getResponse()->getContent());

        return ['invoice' => $this->json()['id'], 'number' => $this->json()['invoice_number']] + $shipment;
    }

    /**
     * A return through the API. By default an `owner` records it: before an invoice exists that DEDUCTS (Q-L (a′)); after
     * one exists it is stamped with it and is a credit's to settle. `$recorder` = `warehouse_operator` before issue with
     * the shipper's own principal is the non-deducting NULL-stamped kind.
     *
     * @param array{fulfilment: string, lines: list<string>, bin: string} $shipment
     * @param list<array{0: int, 1: string}>                              $lines    `[line index, quantity]`
     */
    protected function returnOf(array $shipment, array $lines, string $reason = 'other'): string
    {
        $this->send('POST', '/fulfilments/'.$shipment['fulfilment'].'/sales-returns', ['reason' => $reason, 'lines' => array_map(
            static fn (array $l): array => ['fulfilment_line_id' => $shipment['lines'][$l[0]], 'quantity' => $l[1], 'storage_location_id' => $shipment['bin']],
            $lines,
        )], ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), 'Fixture: the return. '.(string) $this->client()->getResponse()->getContent());

        return (string) $this->json()['id'];
    }

    /**
     * `IssueCreditNote`, as the current actor.
     *
     * @param array{invoice: string, lines: list<string>} $shipment
     * @param list<array{0: int, 1: string}>             $lines    `[line index, quantity]`
     */
    protected function credit(array $shipment, string $returnId, array $lines, ?string $key = null): void
    {
        $this->send('POST', '/invoices/'.$shipment['invoice'].'/credit-notes', [
            'reason' => 'goods_returned',
            'sales_return_id' => $returnId,
            'lines' => array_map(static fn (array $l): array => ['fulfilment_line_id' => $shipment['lines'][$l[0]], 'quantity' => $l[1]], $lines),
        ], ['Idempotency-Key' => $key ?? Uuid::v4()->toRfc4122()]);
    }
}

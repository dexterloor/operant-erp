<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Sales\ApiResource\State\InvoiceLineCollectionProvider;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **`ListInvoiceLines` — what an invoice billed and what has been credited, per shipped line** (sales step 7 C0;
 * design §4.5; owner ruling Q-B: under `invoice.view`). A credit issuer cannot size a credit without per-line billed
 * and credited quantities (it would learn the bound only from a 409), and under Q-A it is how a line id is found
 * without `fulfilment.view` (design §4.5, every option of Q-A).
 *
 * **`(bounded)`** (§4.2), so `ListSalesOrderLines`' shape: the whole set in `items`, both cursors null always, and no
 * `limit`/`after`/`before`/`sort` (undeclared, therefore refused; §10.3 step 4, §10.2 exclusion 2). Rows come back in
 * the order the invoice's shipment lists its lines: `line_number`, then the bin, then the id.
 *
 * **Derived, no table** — see `InvoiceLineView`. Another workspace's invoice and a random uuid are the same 404.
 */
#[ApiResource(
    shortName: 'InvoiceLineCollection',
    operations: [
        new Get(
            uriTemplate: '/invoices/{id}/lines',
            uriVariables: [
                'id' => new Link(fromClass: InvoiceResource::class, identifiers: ['id'], parameterName: 'id'),
            ],
            name: 'ListInvoiceLines',
            openapi: new OpenApiOperation(operationId: 'ListInvoiceLines'),
            provider: InvoiceLineCollectionProvider::class,
            // §4.2's `Perm` cell, enforced before the provider runs (E-1). Money: never `fulfilment.view` (V2-S-8).
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'invoice.view'],
        ),
    ],
)]
final class InvoiceLineCollection
{
    /**
     * @param list<InvoiceLineResource> $items
     */
    public function __construct(
        // INLINE, not a `$ref`: no operation outputs a single invoice line, so no component exists to point at.
        #[ApiProperty(schema: ['type' => 'array', 'items' => InvoiceLineResource::WIRE_SCHEMA])]
        public array $items,
        /** Always null on a bounded list (§4.4.2). */
        #[SerializedName('next_cursor')]
        public ?string $nextCursor,
        #[SerializedName('previous_cursor')]
        public ?string $previousCursor,
        /** ST-2's discriminator (API-22): does this workspace have any invoice at all. */
        #[SerializedName('tenant_has_rows')]
        public bool $tenantHasRows,
    ) {
    }
}

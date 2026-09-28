<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Sales\ApiResource\State\InvoiceCollectionProvider;
use App\Modules\Sales\Application\InvoiceViews;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **`ListInvoices` — the invoice register and the collections screen (FLOWS F-5 step 8; IA §5.6).** Sales step 6, C4
 * (2026-09-28). API-22's named envelope, so a separate `*Collection` resource with a `Get` on the collection path
 * (`FulfilmentCollection`'s shape). Each item is `GetInvoice`'s document, `status` derived the same way.
 */
#[ApiResource(
    shortName: 'InvoiceCollection',
    operations: [
        new Get(
            uriTemplate: '/invoices',
            name: 'ListInvoices',
            openapi: new OpenApiOperation(operationId: 'ListInvoices'),
            provider: InvoiceCollectionProvider::class,
            parameters: [
                'status' => new QueryParameter(
                    description: 'The list segment, over the STORED status (TB-4): `unpaid` (issued; an unpaid invoice past its due date is labelled overdue in each row, never filtered), `paid`, `void`.',
                    schema: ['type' => 'string', 'enum' => InvoiceViews::SEGMENT_NAMES],
                ),
                'customer_id' => new QueryParameter(
                    description: 'Restrict to one customer. The sort manifest\'s "/invoices filtered to a customer" predicate, served by invoice_tenant_customer_idx. A customer this workspace cannot see matches nothing: an empty page, never a 404.',
                    schema: ['type' => 'string', 'format' => 'uuid'],
                ),
                'fulfilment_id' => new QueryParameter(
                    description: 'Restrict to the invoices of one shipment, newest first (V2-C-11). A shipment this workspace cannot see matches nothing: an empty page, never a 404.',
                    schema: ['type' => 'string', 'format' => 'uuid'],
                ),
                'q' => new QueryParameter(
                    description: 'Free-text search. API-19d/TB-5 name one searchable column on this table, invoice.invoice_number, matched by prefix.',
                    schema: ['type' => 'string'],
                ),
                'sort' => new QueryParameter(
                    description: 'The order. Legal values depend on the view (API-19): invoice_number always; due_at on Unpaid; issued_at filtered to a customer; only invoice_number filtered to a fulfilment. Never paid_at.',
                    schema: ['type' => 'string', 'enum' => [
                        InvoiceViews::SORT_INVOICE_NUMBER,
                        InvoiceViews::SORT_DUE_AT,
                        InvoiceViews::SORT_ISSUED_AT,
                    ]],
                ),
                'limit' => new QueryParameter(
                    description: 'Page size. API-17: default 50, maximum 200.',
                    schema: [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => InvoiceCollectionProvider::MAXIMUM_LIMIT,
                        'default' => InvoiceCollectionProvider::DEFAULT_LIMIT,
                    ],
                ),
                'after' => new QueryParameter(
                    description: 'An opaque cursor from a previous response\'s next_cursor. Mutually exclusive with before.',
                    schema: ['type' => 'string'],
                ),
                'before' => new QueryParameter(
                    description: 'An opaque cursor from a previous response\'s previous_cursor. Mutually exclusive with after.',
                    schema: ['type' => 'string'],
                ),
            ],
            // §4.2's `Perm` cell, enforced before the provider runs (E-1).
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'invoice.view'],
        ),
    ],
)]
final class InvoiceCollection
{
    /**
     * @param list<InvoiceResource> $items
     */
    public function __construct(
        // Written out for `SalesOrderCollection`'s reason (no phpdoc parser installed). `Invoice` exists as a
        // component because `GetInvoice` is built and names it.
        #[ApiProperty(schema: ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Invoice']])]
        public array $items,
        #[SerializedName('next_cursor')]
        public ?string $nextCursor,
        #[SerializedName('previous_cursor')]
        public ?string $previousCursor,
        #[SerializedName('tenant_has_rows')]
        public bool $tenantHasRows,
    ) {
    }
}

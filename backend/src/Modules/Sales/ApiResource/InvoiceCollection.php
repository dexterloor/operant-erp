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
                    description: 'Which invoices to list: `unpaid` (issued and not credited in full; one past its due date shows as overdue in its row but is not filtered out), `paid`, `credited` (issued and credited in full, so it owes nothing), `void`.',
                    schema: ['type' => 'string', 'enum' => InvoiceViews::SEGMENT_NAMES],
                ),
                'customer_id' => new QueryParameter(
                    description: 'Only one customer\'s invoices. A customer this workspace cannot see matches nothing: the answer is an empty page, never a 404.',
                    schema: ['type' => 'string', 'format' => 'uuid'],
                ),
                'fulfilment_id' => new QueryParameter(
                    description: 'Only the invoices of one shipment, newest first. A shipment this workspace cannot see matches nothing: the answer is an empty page, never a 404.',
                    schema: ['type' => 'string', 'format' => 'uuid'],
                ),
                'q' => new QueryParameter(
                    description: 'Search: a prefix of the `invoice_number`. Nothing else is searched.',
                    schema: ['type' => 'string'],
                ),
                'sort' => new QueryParameter(
                    description: 'The order. `invoice_number` is always allowed. With `customer_id`, `issued_at` is also allowed and is the default; otherwise, with `status=unpaid`, `due_at` is also allowed and is the default. With `fulfilment_id`, only `invoice_number`, newest first.',
                    schema: ['type' => 'string', 'enum' => [
                        InvoiceViews::SORT_INVOICE_NUMBER,
                        InvoiceViews::SORT_DUE_AT,
                        InvoiceViews::SORT_ISSUED_AT,
                    ]],
                ),
                'limit' => new QueryParameter(
                    description: 'How many rows to return: 50 if not sent, at most 200.',
                    schema: [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => InvoiceCollectionProvider::MAXIMUM_LIMIT,
                        'default' => InvoiceCollectionProvider::DEFAULT_LIMIT,
                    ],
                ),
                'after' => new QueryParameter(
                    description: 'The `next_cursor` of the previous page, to fetch the next one. Not with `before`.',
                    schema: ['type' => 'string'],
                ),
                'before' => new QueryParameter(
                    description: 'The `previous_cursor` of a later page, to fetch the one before it. Not with `after`.',
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

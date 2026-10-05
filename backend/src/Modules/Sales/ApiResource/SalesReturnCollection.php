<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Sales\ApiResource\State\SalesReturnCollectionProvider;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **`ListSalesReturns` — the returns register and the credit issuer's work queue (sales step 7, commit R3).**
 * `FulfilmentCollection`'s envelope. Each item is `GetSalesReturn`'s document, `credit_blocked_by_payment` included only
 * for an `invoice.view` holder (`RED-4` M4-1).
 */
#[ApiResource(
    shortName: 'SalesReturnCollection',
    operations: [
        new Get(
            uriTemplate: '/sales-returns',
            name: 'ListSalesReturns',
            openapi: new OpenApiOperation(operationId: 'ListSalesReturns'),
            provider: SalesReturnCollectionProvider::class,
            parameters: [
                'fulfilment_id' => new QueryParameter(
                    description: 'Restrict to one shipment\'s returns. A shipment this workspace cannot see matches nothing: an empty page, never a 404.',
                    schema: ['type' => 'string', 'format' => 'uuid'],
                ),
                'invoice_id' => new QueryParameter(
                    description: 'Restrict to the returns recorded against one invoice (the invoice that was live when each was recorded).',
                    schema: ['type' => 'string', 'format' => 'uuid'],
                ),
                'creditable' => new QueryParameter(
                    description: 'true: the returns a credit note is owed for now (the credit issuer\'s queue); false: the others. PC-9\'s third exception: membership is served to every sales_return.view holder.',
                    schema: ['type' => 'string', 'enum' => ['true', 'false']],
                ),
                'deducts' => new QueryParameter(
                    description: 'true: the returns that lowered an invoice with no credit note (owner ruling Q-L (a′)) — a list to reconcile against goods actually received.',
                    schema: ['type' => 'string', 'enum' => ['true', 'false']],
                ),
                'credit_blocked_by_payment' => new QueryParameter(
                    description: 'true: returns that would be creditable but whose invoice is paid. Served only to an invoice.view holder; refused as a parameter error for anyone else.',
                    schema: ['type' => 'string', 'enum' => ['true', 'false']],
                ),
                'adjustment_decision_owed' => new QueryParameter(
                    description: 'true: returns on an order line that already carries a credit without a return, waiting for a decision (already credited, or, by an owner, different goods) before they can be credited. Served only to an invoice.view holder; refused as a parameter error for anyone else.',
                    schema: ['type' => 'string', 'enum' => ['true', 'false']],
                ),
                'adjustment_decided' => new QueryParameter(
                    description: 'same: returns carrying a live "already credited" decision — the owner\'s review list. Served only to an invoice.view holder.',
                    schema: ['type' => 'string', 'enum' => ['same']],
                ),
                'decided_since' => new QueryParameter(
                    description: 'With adjustment_decided=same: only decisions recorded at or after this RFC 3339 instant. A decision\'s time is its transaction\'s start, so start each review a little before the last one. Served only to an invoice.view holder.',
                    schema: ['type' => 'string'],
                ),
                'q' => new QueryParameter(
                    description: 'Free-text search. API-19d/TB-5: one searchable column, sales_return.sales_return_number, matched by prefix.',
                    schema: ['type' => 'string'],
                ),
                'limit' => new QueryParameter(
                    description: 'Page size. API-17: default 50, maximum 200.',
                    schema: [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => SalesReturnCollectionProvider::MAXIMUM_LIMIT,
                        'default' => SalesReturnCollectionProvider::DEFAULT_LIMIT,
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
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'sales_return.view'],
        ),
    ],
)]
final class SalesReturnCollection
{
    public function __construct(
        #[ApiProperty(schema: ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/SalesReturn']])]
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

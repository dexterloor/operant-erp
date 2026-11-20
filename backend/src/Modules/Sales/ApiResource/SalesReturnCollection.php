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
                    description: 'Only one shipment\'s returns. A shipment this workspace cannot see matches nothing: the answer is an empty page, never a 404.',
                    schema: ['type' => 'string', 'format' => 'uuid'],
                ),
                'invoice_id' => new QueryParameter(
                    description: 'Only the returns recorded against one invoice (the invoice that was live when each was recorded).',
                    schema: ['type' => 'string', 'format' => 'uuid'],
                ),
                'creditable' => new QueryParameter(
                    description: '`true`: the returns a credit note is owed for now; `false`: the others. Unlike the filters that need `invoice.view`, anyone who may list returns may use it.',
                    schema: ['type' => 'string', 'enum' => ['true', 'false']],
                ),
                'deducts' => new QueryParameter(
                    description: '`true`: the returns that lowered an invoice without a credit note, to reconcile against the goods actually received.',
                    schema: ['type' => 'string', 'enum' => ['true', 'false']],
                ),
                'credit_blocked_by_payment' => new QueryParameter(
                    description: '`true`: the returns that would be creditable but whose invoice is paid. Only for a caller holding `invoice.view`; anyone else is refused 422 `not_permitted`.',
                    schema: ['type' => 'string', 'enum' => ['true', 'false']],
                ),
                'adjustment_decision_owed' => new QueryParameter(
                    description: '`true`: the returns on an order line that already carries a credit without a return, which wait for a decision (already credited, or, by an owner, different goods) before they can be credited. Only for a caller holding `invoice.view`; anyone else is refused 422 `not_permitted`.',
                    schema: ['type' => 'string', 'enum' => ['true', 'false']],
                ),
                'adjustment_decided' => new QueryParameter(
                    description: '`same`: the returns carrying a live "already credited" decision, for the owner to review. Only for a caller holding `invoice.view`; anyone else is refused 422 `not_permitted`.',
                    schema: ['type' => 'string', 'enum' => ['same']],
                ),
                'decided_since' => new QueryParameter(
                    description: 'With `adjustment_decided=same`: only the decisions recorded at or after this RFC 3339 instant; without `adjustment_decided` it is refused 422 `needs_adjustment_decided`. A decision\'s time is when its transaction started, so start each review a little before the last one. Only for a caller holding `invoice.view`; anyone else is refused 422 `not_permitted`.',
                    schema: ['type' => 'string'],
                ),
                'q' => new QueryParameter(
                    description: 'Search: a prefix of the `sales_return_number`. Nothing else is searched.',
                    schema: ['type' => 'string'],
                ),
                'limit' => new QueryParameter(
                    description: 'How many rows to return: 50 if not sent, at most 200.',
                    schema: [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => SalesReturnCollectionProvider::MAXIMUM_LIMIT,
                        'default' => SalesReturnCollectionProvider::DEFAULT_LIMIT,
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
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'sales_return.view'],
        ),
    ],
)]
final class SalesReturnCollection
{
    /**
     * @param list<SalesReturnResource> $items
     */
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

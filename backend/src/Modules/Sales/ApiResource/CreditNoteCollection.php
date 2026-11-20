<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Sales\ApiResource\State\CreditNoteCollectionProvider;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **`ListCreditNotes` — the credit-note register (sales step 7, commit C2; design §4.5; closes API-O-35).**
 * `SalesReturnCollection`'s envelope. Each item is `GetCreditNote`'s document — return-linked notes and adjustments
 * (C1b) alike — under `invoice.view` (Q-B: same money, same audience).
 */
#[ApiResource(
    shortName: 'CreditNoteCollection',
    operations: [
        new Get(
            uriTemplate: '/credit-notes',
            name: 'ListCreditNotes',
            openapi: new OpenApiOperation(operationId: 'ListCreditNotes'),
            provider: CreditNoteCollectionProvider::class,
            parameters: [
                'invoice_id' => new QueryParameter(
                    description: 'Only the credit notes that reduce one invoice. An invoice this workspace cannot see matches nothing: the answer is an empty page, never a 404.',
                    schema: ['type' => 'string', 'format' => 'uuid'],
                ),
                'customer_id' => new QueryParameter(
                    description: 'Only one customer\'s credit notes: those that reduce an invoice of that customer.',
                    schema: ['type' => 'string', 'format' => 'uuid'],
                ),
                'sales_return_id' => new QueryParameter(
                    description: 'Only the credit notes issued for one return. A credit note that credits no return (an adjustment) never matches.',
                    schema: ['type' => 'string', 'format' => 'uuid'],
                ),
                'q' => new QueryParameter(
                    description: 'Search: a prefix of the `credit_note_number`. Nothing else is searched.',
                    schema: ['type' => 'string'],
                ),
                'limit' => new QueryParameter(
                    description: 'How many rows to return: 50 if not sent, at most 200.',
                    schema: [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => CreditNoteCollectionProvider::MAXIMUM_LIMIT,
                        'default' => CreditNoteCollectionProvider::DEFAULT_LIMIT,
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
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'invoice.view'],
        ),
    ],
)]
final class CreditNoteCollection
{
    /**
     * @param list<CreditNoteResource> $items
     */
    public function __construct(
        #[ApiProperty(schema: ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/CreditNote']])]
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

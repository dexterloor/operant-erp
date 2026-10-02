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
                    description: 'Restrict to the credit notes that reduce one invoice. An invoice this workspace cannot see matches nothing: an empty page, never a 404.',
                    schema: ['type' => 'string', 'format' => 'uuid'],
                ),
                'customer_id' => new QueryParameter(
                    description: 'Restrict to one customer\'s credit notes, through the invoice each reduces (the invoice\'s own customer).',
                    schema: ['type' => 'string', 'format' => 'uuid'],
                ),
                'sales_return_id' => new QueryParameter(
                    description: 'Restrict to the credit notes issued for one return. An adjustment credits no return and never matches.',
                    schema: ['type' => 'string', 'format' => 'uuid'],
                ),
                'q' => new QueryParameter(
                    description: 'Free-text search. API-19d/TB-5: one searchable column, credit_note.credit_note_number, matched by prefix.',
                    schema: ['type' => 'string'],
                ),
                'limit' => new QueryParameter(
                    description: 'Page size. API-17: default 50, maximum 200.',
                    schema: [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => CreditNoteCollectionProvider::MAXIMUM_LIMIT,
                        'default' => CreditNoteCollectionProvider::DEFAULT_LIMIT,
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
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'invoice.view'],
        ),
    ],
)]
final class CreditNoteCollection
{
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

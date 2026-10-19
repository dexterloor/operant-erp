<?php

declare(strict_types=1);

namespace App\Modules\Customers\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Customers\ApiResource\State\CustomerContactCollectionProvider;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **`ListCustomerContacts` — `GET /api/v1/customers/{id}/contacts`, `customer.view`, *(bounded)*** (customers slice 2 C1).
 * {@see CustomerAddressCollection}'s shape.
 *
 * TB-1c: a customer's contacts are a bounded sub-list loaded whole, so **no `limit`, `after`, `before`, `sort` or `q`**
 * (§10.3 step 4) and both cursors are always null (§4.4.2). **No `archived`** either: `customer_contact` is class `hard`.
 * Rows come back ordered `is_primary DESC, created_at, id` (the primary first; `UX` TO CONFIRM). An archived customer's
 * contacts are listed (§5.2a); an unknown or foreign customer is a 404. Every `customer.view` holder reads them, `viewer`
 * included (owner Q-3 "yes"; `SECURITY_MODEL.md` "Customer contacts", SEC S-1).
 */
#[ApiResource(
    shortName: 'CustomerContactCollection',
    operations: [
        new Get(
            uriTemplate: '/customers/{id}/contacts',
            uriVariables: [
                'id' => new Link(fromClass: CustomerResource::class, identifiers: ['id'], parameterName: 'id'),
            ],
            name: 'ListCustomerContacts',
            openapi: new OpenApiOperation(operationId: 'ListCustomerContacts'),
            provider: CustomerContactCollectionProvider::class,
            // §4.2's `Perm` cell, enforced before the provider runs (E-1).
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'customer.view'],
        ),
    ],
)]
final class CustomerContactCollection
{
    /**
     * @param list<CustomerContactResource> $items
     */
    public function __construct(
        // A `$ref`: `CreateCustomerContact` outputs `CustomerContactResource`, so the component exists.
        #[ApiProperty(schema: ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/CustomerContact']])]
        public array $items,
        /** Always null on a bounded list (§4.4.2). */
        #[SerializedName('next_cursor')]
        public ?string $nextCursor,
        #[SerializedName('previous_cursor')]
        public ?string $previousCursor,
        /** ST-2's discriminator (API-22): does this workspace hold any customer contact at all — probed on an empty set. */
        #[SerializedName('tenant_has_rows')]
        public bool $tenantHasRows,
    ) {
    }
}

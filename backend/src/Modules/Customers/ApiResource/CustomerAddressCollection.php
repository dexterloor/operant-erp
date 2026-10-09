<?php

declare(strict_types=1);

namespace App\Modules\Customers\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Customers\ApiResource\State\CustomerAddressCollectionProvider;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **`ListCustomerAddresses` — `GET /api/v1/customers/{id}/addresses`, `customer.view`, *(bounded)*** (customers slice 1 C3).
 *
 * TB-1c: a customer's addresses are a bounded sub-list loaded whole, so **no `limit`, `after`, `before` or `sort`** (§10.3
 * step 4) and both cursors are always null (§4.4.2). **No `archived`** either: `customer_address` is class `hard`, so
 * API-19c's limb 2 (a bounded sub-list of a `SOFT` table) does not apply and there is no archived set to offer. `IA.md`
 * §5.6 has no row for it (addresses live inside `/customers/[id]`, `customers-s1-ux.md` §1.3).
 *
 * Rows come back ordered `kind, created_at, id` (billing before shipping, oldest first); the screen re-groups them (UX
 * §1.3). An archived customer's addresses are listed (§5.2a); an unknown or foreign customer is a 404.
 */
#[ApiResource(
    shortName: 'CustomerAddressCollection',
    operations: [
        new Get(
            uriTemplate: '/customers/{id}/addresses',
            uriVariables: [
                'id' => new Link(fromClass: CustomerResource::class, identifiers: ['id'], parameterName: 'id'),
            ],
            name: 'ListCustomerAddresses',
            openapi: new OpenApiOperation(operationId: 'ListCustomerAddresses'),
            provider: CustomerAddressCollectionProvider::class,
            // §4.2's `Perm` cell, enforced before the provider runs (E-1).
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'customer.view'],
        ),
    ],
)]
final class CustomerAddressCollection
{
    /**
     * @param list<CustomerAddressResource> $items
     */
    public function __construct(
        // A `$ref`: `CreateCustomerAddress` outputs `CustomerAddressResource`, so the component exists.
        #[ApiProperty(schema: ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/CustomerAddress']])]
        public array $items,
        /** Always null on a bounded list (§4.4.2). */
        #[SerializedName('next_cursor')]
        public ?string $nextCursor,
        #[SerializedName('previous_cursor')]
        public ?string $previousCursor,
        /** ST-2's discriminator (API-22): does this workspace hold any customer address at all — probed on an empty set. */
        #[SerializedName('tenant_has_rows')]
        public bool $tenantHasRows,
    ) {
    }
}

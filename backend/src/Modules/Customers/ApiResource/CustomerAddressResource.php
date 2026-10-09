<?php

declare(strict_types=1);

namespace App\Modules\Customers\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Customers\ApiResource\State\CreateCustomerAddressProcessor;
use App\Modules\Customers\ApiResource\State\CustomerAddressProvider;
use App\Modules\Customers\ApiResource\State\UpdateCustomerAddressProcessor;
use App\Modules\Customers\Application\CustomerAddressView;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Http\Location\CreatedRowLocation;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **`CreateCustomerAddress` and `UpdateCustomerAddress`** (customers slice 1 C3; `docs/session13/customers-s1-design.md`
 * §5). A DTO, never the entity.
 *
 * The shape is §4.4.3's derivation (API-23): the accepted fields `kind`, `line`, `city`, `postal_code`, `country_code`
 * (minus `customer_id`, implied by the path), then `id`. **No `archived_at`**: `customer_address` is class `hard`
 * (`SchemaManifest`), which is why `sales_order` holds a snapshot of the address rather than a reference to it. **`line` is
 * the wire name of the column `address_line`** (`sales_order.ship_to_line`'s spelling); `region` is a column, not a field.
 *
 * **No `GetCustomerAddress`**: §4.2 declares none (TB-1c: the screen loads the bounded set whole), so API Platform mints
 * its not-exposed fallback route for this resource and `CreatedRowLocation` names the URI §4.2 does declare
 * (`PurchaseOrderLine`'s and `SalesOrderLine`'s shape, final RED M-1).
 *
 * **No `OPERATION_DENIAL_SUBJECT`**: the first uuid route parameter is the customer `{id}`, typed by the permission's
 * resource half `customer` — parent and permission agree (design §5.5; the shipped `UpdatePurchaseOrderLine` shape).
 */
#[ApiResource(
    shortName: 'CustomerAddress',
    operations: [
        // API-21 branch 4: §4.2 declares `UpdateCustomerAddress` at `/customers/{id}/addresses/{addressId}`, the row-level
        // URI of the row this POST creates, so the answer is 201 with a `Location` naming it and the address as the body.
        new Post(
            uriTemplate: '/customers/{id}/addresses',
            uriVariables: [
                'id' => new Link(fromClass: CustomerResource::class, identifiers: ['id'], parameterName: 'id'),
            ],
            name: 'CreateCustomerAddress',
            status: 201,
            openapi: new OpenApiOperation(operationId: 'CreateCustomerAddress'),
            input: CreateCustomerAddressInput::class,
            denormalizationContext: [
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
            ],
            processor: CreateCustomerAddressProcessor::class,
            // §4.2: `unique:customer_address_idem_unique` (customers slice 1 C0). The header's hash IS the indexed value,
            // so API-14 demands it (428 without one); a same-body retry replays the stored address.
            extraProperties: [
                IdempotencyKeyGuard::OPERATION_FLAG => true,
                PermissionGate::OPERATION_PERMISSION => 'customer.edit',
                // Without it API Platform derives `Location` from its `NotExposed` fallback and names
                // `/customer_addresses/{id}`, a path that answers 404 (the reason `CreatedRowLocation` exists).
                CreatedRowLocation::OPERATION_URI_TEMPLATE => '/customers/{id}/addresses/{addressId}',
            ],
        ),
        new Patch(
            uriTemplate: '/customers/{id}/addresses/{addressId}',
            uriVariables: [
                'id' => new Link(fromClass: CustomerResource::class, identifiers: ['id'], parameterName: 'id'),
                'addressId' => new Link(fromClass: CustomerAddressResource::class, identifiers: ['id'], parameterName: 'addressId'),
            ],
            name: 'UpdateCustomerAddress',
            status: 200,
            openapi: new OpenApiOperation(operationId: 'UpdateCustomerAddress'),
            input: UpdateCustomerAddressInput::class,
            denormalizationContext: [
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
                'api_allow_update' => false,
            ],
            // The pair is read first, so a PATCH at an address no row of THIS customer answers is a 404 (§5.3 clause 2).
            provider: CustomerAddressProvider::class,
            processor: UpdateCustomerAddressProcessor::class,
            // No `OPERATION_FLAG`: §4.2's `Idem` cell is `no` — a PATCH sets absolute values on a row that exists.
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'customer.edit'],
        ),
    ],
)]
final class CustomerAddressResource
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $line,
        public string $city,
        #[SerializedName('postal_code')]
        public string $postalCode,
        #[SerializedName('country_code')]
        public string $countryCode,
    ) {
    }

    public static function fromView(CustomerAddressView $view): self
    {
        return new self($view->id, $view->kind, $view->line, $view->city, $view->postalCode, $view->countryCode);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Customers\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Customers\ApiResource\State\CreateCustomerContactProcessor;
use App\Modules\Customers\ApiResource\State\CustomerContactProvider;
use App\Modules\Customers\ApiResource\State\UpdateCustomerContactProcessor;
use App\Modules\Customers\Application\CustomerContactView;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Http\Location\CreatedRowLocation;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **`CreateCustomerContact` and `UpdateCustomerContact`** (customers slice 2 C1; `docs/session13/customers-s2-design.md`
 * §2.2 and Revision 2). A DTO, never the entity. {@see CustomerAddressResource}'s shape.
 *
 * The shape is §4.4.3's derivation (API-23): the accepted fields `name`, `email`, `phone`, `is_primary` (minus
 * `customer_id`, implied by the path), then `id`. `email` and `phone` are nullable. **No `archived_at`**: `customer_contact`
 * is class `hard`. Never the retry key or digest.
 *
 * **No `GetCustomerContact`**: §4.2 declares none (TB-1c: the screen loads the bounded set whole), so API Platform mints its
 * not-exposed fallback route for this resource and `CreatedRowLocation` names the URI §4.2 does declare.
 *
 * **No `OPERATION_DENIAL_SUBJECT`**: the first uuid route parameter is the customer `{id}`, typed by the permission's
 * resource half `customer` — parent and permission agree (the address precedent).
 */
#[ApiResource(
    shortName: 'CustomerContact',
    operations: [
        // API-21 branch 4: §4.2 declares `UpdateCustomerContact` at `/customers/{id}/contacts/{contactId}`, the row-level
        // URI of the row this POST creates, so the answer is 201 with a `Location` naming it and the contact as the body.
        new Post(
            uriTemplate: '/customers/{id}/contacts',
            uriVariables: [
                'id' => new Link(fromClass: CustomerResource::class, identifiers: ['id'], parameterName: 'id'),
            ],
            name: 'CreateCustomerContact',
            status: 201,
            openapi: new OpenApiOperation(operationId: 'CreateCustomerContact'),
            input: CreateCustomerContactInput::class,
            denormalizationContext: [
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
            ],
            processor: CreateCustomerContactProcessor::class,
            // §4.2: `unique:customer_contact_idem_unique` (customers slice 2 C0). The header's hash IS the indexed value,
            // so API-14 demands it (428 without one); a same-body retry replays the stored contact.
            extraProperties: [
                IdempotencyKeyGuard::OPERATION_FLAG => true,
                PermissionGate::OPERATION_PERMISSION => 'customer.edit',
                CreatedRowLocation::OPERATION_URI_TEMPLATE => '/customers/{id}/contacts/{contactId}',
            ],
        ),
        new Patch(
            uriTemplate: '/customers/{id}/contacts/{contactId}',
            uriVariables: [
                'id' => new Link(fromClass: CustomerResource::class, identifiers: ['id'], parameterName: 'id'),
                'contactId' => new Link(fromClass: CustomerContactResource::class, identifiers: ['id'], parameterName: 'contactId'),
            ],
            name: 'UpdateCustomerContact',
            status: 200,
            openapi: new OpenApiOperation(operationId: 'UpdateCustomerContact'),
            input: UpdateCustomerContactInput::class,
            denormalizationContext: [
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
                'api_allow_update' => false,
            ],
            // The pair is read first, so a PATCH at a contact no row of THIS customer answers is a 404 (ST-5).
            provider: CustomerContactProvider::class,
            processor: UpdateCustomerContactProcessor::class,
            // No `OPERATION_FLAG`: §4.2's `Idem` cell is `no` — a PATCH sets absolute values on a row that exists.
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'customer.edit'],
        ),
    ],
)]
final class CustomerContactResource
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $email,
        public ?string $phone,
        #[SerializedName('is_primary')]
        public bool $isPrimary,
    ) {
    }

    public static function fromView(CustomerContactView $view): self
    {
        return new self($view->id, $view->name, $view->email, $view->phone, $view->isPrimary);
    }
}

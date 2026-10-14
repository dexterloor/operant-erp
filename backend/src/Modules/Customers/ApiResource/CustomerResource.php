<?php

declare(strict_types=1);

namespace App\Modules\Customers\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Customers\ApiResource\State\CreateCustomerProcessor;
use App\Modules\Customers\ApiResource\State\CustomerProvider;
use App\Modules\Customers\ApiResource\State\RestoreCustomerProcessor;
use App\Modules\Customers\ApiResource\State\UpdateCustomerProcessor;
use App\Modules\Customers\Application\CustomerView;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **`GetCustomer` — `GET /api/v1/customers/{id}`, `customer.view`** (customers slice 1 C1;
 * `docs/session13/customers-s1-design.md` §3). A DTO, never the entity (`ItemResource`'s reason).
 *
 * The shape is §4.4.3's derivation (API-23): the accepted fields `code`, `name`, `country_code`, `currency_code`,
 * `payment_terms_days`, then `id`, then `archived_at` (the table is `soft`). `currency_code` and `country_code` are
 * reference-data codes, served as scalars. `email`, `phone`, `credit_limit` and `is_active` are **columns, not fields**:
 * nothing writes them (owner Q-C2), and `credit_limit` enforces nothing.
 *
 * The gate refuses before the provider loads anything (E-1). A non-uuid, absent or another workspace's id is the same
 * 404; an archived customer in the caller's workspace is returned with `archived_at` set (§5.2a, ST-9).
 *
 * **`CreateCustomer` / `UpdateCustomer`** (customers slice 1 C2): `CustomerWriter` behind both, `ItemResource`'s operation
 * shape. Each writes one audit row in its transaction (`customer.created` / `customer.updated` with field names only;
 * `RESIDUAL_GAPS.md` G-35).
 *
 * **`RestoreCustomer`** (C5 C4a): `CustomerWriter::restore()`, `customer.restored` in its transaction.
 */
#[ApiResource(
    shortName: 'Customer',
    operations: [
        new Get(
            uriTemplate: '/customers/{id}',
            name: 'GetCustomer',
            openapi: new OpenApiOperation(operationId: 'GetCustomer'),
            provider: CustomerProvider::class,
            // §4.2's `Perm` cell. Enforced by `PermissionGate` on `kernel.controller`, before the provider loads anything.
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'customer.view'],
        ),
        new Post(
            uriTemplate: '/customers',
            name: 'CreateCustomer',
            status: 201,
            openapi: new OpenApiOperation(operationId: 'CreateCustomer'),
            input: CreateCustomerInput::class,
            denormalizationContext: [
                // API-9: unknown fields are refused and named back. A client `id` is refused by the layers `ItemResource`
                // records (B-3); `CustomerWriter::create()` mints the id regardless.
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
            ],
            processor: CreateCustomerProcessor::class,
            // §4.2: `unique:customer_tenant_code_live`. API-14 demands the header; a repeat is refused by the code's live
            // unique, never replayed (`ItemResource`'s `CreateItem` precedent).
            extraProperties: [
                IdempotencyKeyGuard::OPERATION_FLAG => true,
                PermissionGate::OPERATION_PERMISSION => 'customer.create',
            ],
        ),
        new Patch(
            uriTemplate: '/customers/{id}',
            name: 'UpdateCustomer',
            status: 200,
            openapi: new OpenApiOperation(operationId: 'UpdateCustomer'),
            input: UpdateCustomerInput::class,
            denormalizationContext: [
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
                // A body `id` is an undeclared field, never an IRI to resolve (`UpdateItem`'s reason).
                'api_allow_update' => false,
            ],
            // The row is read first, so a PATCH at an id no row answers is `GetCustomer`'s 404 (§5.3 clause 2).
            provider: CustomerProvider::class,
            processor: UpdateCustomerProcessor::class,
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'customer.edit'],
        ),
        // C5 C4a — `RestoreCustomer`, API-21 branch 5: a POST that creates no row this contract addresses, so 200 with the
        // customer in its new state and no `Location` (`RestoreSupplier`'s shape). It ships with `ListCustomers?archived`
        // (E9-3: an archived customer's id is obtainable) — `CustomerOperationContractTest` ties the two.
        new Post(
            uriTemplate: '/customers/{id}/restoration',
            name: 'RestoreCustomer',
            status: 200,
            openapi: new OpenApiOperation(operationId: 'RestoreCustomer'),
            input: false,
            deserialize: false,
            read: false,
            processor: RestoreCustomerProcessor::class,
            // §4.2: `customer.delete` (undoing an archive is the same authority as making one; `RestoreItem`'s reason).
            // **No `IdempotencyKeyGuard::OPERATION_FLAG`:** §4.2's cell is `guard:customer.deleted_at` — a replayed restore
            // hits a live row and is refused `customer.is_not_archived` (`RestoreItem`'s reason; `IdempotencyContractTest`).
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'customer.delete'],
        ),
    ],
)]
final class CustomerResource
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        #[SerializedName('country_code')]
        public string $countryCode,
        #[SerializedName('currency_code')]
        public string $currencyCode,
        #[SerializedName('payment_terms_days')]
        public int $paymentTermsDays,
        #[SerializedName('archived_at')]
        public ?string $archivedAt,
    ) {
    }

    public static function fromView(CustomerView $view): self
    {
        return new self(
            $view->id,
            $view->code,
            $view->name,
            $view->countryCode,
            $view->currencyCode,
            $view->paymentTermsDays,
            $view->archivedAt,
        );
    }
}

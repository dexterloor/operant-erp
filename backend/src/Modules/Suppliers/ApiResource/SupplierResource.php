<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Suppliers\ApiResource\State\CreateSupplierProcessor;
use App\Modules\Suppliers\ApiResource\State\SupplierProvider;
use App\Modules\Suppliers\ApiResource\State\UpdateSupplierProcessor;
use App\Modules\Suppliers\Application\SupplierView;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **`GetSupplier` — `GET /api/v1/suppliers/{id}`, `supplier.view`** (suppliers slice 1 C1;
 * `docs/session13/suppliers-s1-design.md` §3 and FINAL F-2). A DTO, never the entity (`ItemResource`'s reason).
 *
 * The shape is §4.4.3's derivation (API-23): the accepted fields `code`, `name`, `country_code`, `currency_code`,
 * `payment_terms_days`, then `id`, then `archived_at` (the table is `soft`). `currency_code` and `country_code` are
 * reference-data codes, served as scalars (`country_code` laid at C0, `Version20261012000100`). `email`, `phone` and
 * `is_active` are **columns, not fields**: nothing writes them (owner Q-S6, 2026-10-12), and `is_active` duplicates
 * `deleted_at`. The resource carries no money column, so PC-12's cost separation does not reach it.
 *
 * The gate refuses before the provider loads anything (E-1). A non-uuid, absent or another workspace's id is the same
 * 404; an archived supplier in the caller's workspace is returned with `archived_at` set (§5.2a, ST-9). Other documents'
 * supplier reference stays `{id, name}` (`SupplierReference`, `NamedReference`).
 *
 * **`CreateSupplier` / `UpdateSupplier`** (suppliers slice 1 C2): `SupplierWriter` behind both, `CustomerResource`'s
 * operation shape. Each writes one audit row in its transaction (`supplier.created` / `supplier.updated` with field names
 * only; owner Q-S5). Two live uniques (code, name), each refused, never replayed.
 */
#[ApiResource(
    shortName: 'Supplier',
    operations: [
        new Get(
            uriTemplate: '/suppliers/{id}',
            name: 'GetSupplier',
            openapi: new OpenApiOperation(operationId: 'GetSupplier'),
            provider: SupplierProvider::class,
            // §4.2's `Perm` cell. Enforced by `PermissionGate` on `kernel.controller`, before the provider loads anything.
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'supplier.view'],
        ),
        new Post(
            uriTemplate: '/suppliers',
            name: 'CreateSupplier',
            status: 201,
            openapi: new OpenApiOperation(operationId: 'CreateSupplier'),
            input: CreateSupplierInput::class,
            denormalizationContext: [
                // API-9: unknown fields are refused and named back (`email`, `phone`, `is_active` included, G-36). A client
                // `id` is refused by the layers `ItemResource` records (B-3); `SupplierWriter::create()` mints the id regardless.
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
            ],
            processor: CreateSupplierProcessor::class,
            // §4.2: `unique:supplier_tenant_code_live`. API-14 demands the header; a repeat is refused by the code's live
            // unique, never replayed (`CreateCustomer`'s precedent).
            extraProperties: [
                IdempotencyKeyGuard::OPERATION_FLAG => true,
                PermissionGate::OPERATION_PERMISSION => 'supplier.create',
            ],
        ),
        new Patch(
            uriTemplate: '/suppliers/{id}',
            name: 'UpdateSupplier',
            status: 200,
            openapi: new OpenApiOperation(operationId: 'UpdateSupplier'),
            input: UpdateSupplierInput::class,
            denormalizationContext: [
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
                // A body `id` is an undeclared field, never an IRI to resolve (`UpdateItem`'s reason).
                'api_allow_update' => false,
            ],
            // The row is read first, so a PATCH at an id no row answers is `GetSupplier`'s 404 (§5.3 clause 2).
            provider: SupplierProvider::class,
            processor: UpdateSupplierProcessor::class,
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'supplier.edit'],
        ),
    ],
)]
final class SupplierResource
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

    public static function fromView(SupplierView $view): self
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

<?php

declare(strict_types=1);

namespace App\Modules\Customers\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Customers\ApiResource\State\CustomerProvider;
use App\Modules\Customers\Application\CustomerView;
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

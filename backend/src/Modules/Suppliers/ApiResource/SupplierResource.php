<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Suppliers\ApiResource\State\SupplierProvider;
use App\Modules\Suppliers\Application\SupplierView;
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

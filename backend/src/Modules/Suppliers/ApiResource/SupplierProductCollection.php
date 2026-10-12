<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Suppliers\ApiResource\State\SupplierProductCollectionProvider;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **`ListSupplierProducts` — `GET /api/v1/suppliers/{id}/products`, `supplier_product.view`, *(bounded)*** (suppliers slice
 * 1 C3).
 *
 * **Loaded whole for now** (owner Q-S3 (b), 2026-10-12): no `limit`, `after`, `before` or `sort` (§10.3 step 4), both
 * cursors always null (§4.4.2). This overrides `IA.md` §3.1 and breaches TB-1c for a large catalogue — `RESIDUAL_GAPS.md`
 * G-42. **No `archived`**: `supplier_product` is class `hard`, so API-19c's limb 2 does not apply. Rows come back ordered
 * `supplier_sku, id`. An archived supplier's catalogue is listed (§5.2a); an unknown or foreign supplier is a 404.
 *
 * A **cost read** (PC-12): `supplier_product.view` is a cost source from this commit (`MarginSources`). The denial subject
 * is declared `['supplier', 'id']` (`SupplierProductResource`'s reason).
 */
#[ApiResource(
    shortName: 'SupplierProductCollection',
    operations: [
        new Get(
            uriTemplate: '/suppliers/{id}/products',
            uriVariables: [
                'id' => new Link(fromClass: SupplierResource::class, identifiers: ['id'], parameterName: 'id'),
            ],
            name: 'ListSupplierProducts',
            openapi: new OpenApiOperation(operationId: 'ListSupplierProducts'),
            provider: SupplierProductCollectionProvider::class,
            // §4.2's `Perm` cell, enforced before the provider runs (E-1).
            extraProperties: [
                PermissionGate::OPERATION_PERMISSION => 'supplier_product.view',
                PermissionGate::OPERATION_DENIAL_SUBJECT => ['supplier', 'id'],
            ],
        ),
    ],
)]
final class SupplierProductCollection
{
    /**
     * @param list<SupplierProductResource> $items
     */
    public function __construct(
        // A `$ref`: `CreateSupplierProduct` outputs `SupplierProductResource`, so the component exists.
        #[ApiProperty(schema: ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/SupplierProduct']])]
        public array $items,
        /** Always null on a bounded list (§4.4.2). */
        #[SerializedName('next_cursor')]
        public ?string $nextCursor,
        #[SerializedName('previous_cursor')]
        public ?string $previousCursor,
        /** ST-2's discriminator (API-22): does this workspace hold any catalogue entry at all — probed on an empty set. */
        #[SerializedName('tenant_has_rows')]
        public bool $tenantHasRows,
    ) {
    }
}

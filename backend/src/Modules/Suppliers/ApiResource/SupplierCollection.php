<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Suppliers\ApiResource\State\SupplierCollectionProvider;
use App\Modules\Suppliers\Application\SupplierListCriteria;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **`ListSuppliers` — `GET /api/v1/suppliers`, `supplier.view`; `q`; `sort`; `limit`; `after│before`** (suppliers slice
 * 1 C1, `ListCustomers`' shape). The API-22 envelope as its own resource with one `Get`, `ItemCollection`'s reason.
 *
 * The parameters are §4.2's cell, exhaustively. **`archived`** (C5 C2a): API-19c limb 1 offers it because the sort
 * manifest now gives `/suppliers` an Archived row that is not `⛔` (`IA.md` §5.6; D-a's `supplier_tenant_archived_idx`),
 * and `RestoreSupplier` ships with it (E9-3). **`sort`** is the manifest's two `/suppliers` rows, per view: `name`
 * (default) and `code` live, `archived_at` on the Archived view; the declared enum is their union with a live value first
 * (`SupplierListCriteria::SORTS`).
 * **`q`** is TB-5's two columns: a case-insensitive prefix of `supplier.name` or an as-stored prefix of `supplier.code`
 * (TB-5a, `supplier.name` decided 2026-10-12, signed by `UX`). ⚠ `supplier_tenant_name_live` is unique **as stored**, so
 * `ACME` beside `Acme` is accepted; folding case in the search is what lets a user find the existing row first.
 */
#[ApiResource(
    shortName: 'SupplierCollection',
    operations: [
        new Get(
            uriTemplate: '/suppliers',
            name: 'ListSuppliers',
            openapi: new OpenApiOperation(operationId: 'ListSuppliers'),
            provider: SupplierCollectionProvider::class,
            parameters: [
                'archived' => new QueryParameter(
                    description: 'Return the archived suppliers instead of the live ones, most recently archived first.',
                    schema: ['type' => 'boolean', 'default' => false],
                ),
                'q' => new QueryParameter(
                    description: 'Search: a prefix of the `name`, ignoring case, or a prefix of the `code`, matched exactly as stored.',
                    schema: ['type' => 'string'],
                ),
                'sort' => new QueryParameter(
                    description: 'The order. Live suppliers: `name` (the default) or `code`. Archived suppliers: only `archived_at`, newest first.',
                    schema: ['type' => 'string', 'enum' => SupplierListCriteria::SORTS],
                ),
                'limit' => new QueryParameter(
                    description: 'How many rows to return: 50 if not sent, at most 200.',
                    schema: [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => SupplierListCriteria::MAXIMUM_LIMIT,
                        'default' => SupplierListCriteria::DEFAULT_LIMIT,
                    ],
                ),
                'after' => new QueryParameter(
                    description: 'The `next_cursor` of the previous page, to fetch the next one. Not with `before`.',
                    schema: ['type' => 'string'],
                ),
                'before' => new QueryParameter(
                    description: 'The `previous_cursor` of a later page, to fetch the one before it. Not with `after`.',
                    schema: ['type' => 'string'],
                ),
            ],
            // §4.2's `Perm` cell. Enforced by `PermissionGate` before the provider runs (E-1, ST-4b).
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'supplier.view'],
        ),
    ],
)]
final class SupplierCollection
{
    /**
     * @param list<SupplierResource> $items
     */
    public function __construct(
        #[ApiProperty(schema: ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Supplier']])]
        public array $items,
        /** Null on the last page and only there (API-22). Passed back as `after`. */
        #[SerializedName('next_cursor')]
        public ?string $nextCursor,
        /** Passed back as `before`. */
        #[SerializedName('previous_cursor')]
        public ?string $previousCursor,
        /** ST-2's discriminator: probed only on an empty page, against `supplier` with no filter predicate (API-22). */
        #[SerializedName('tenant_has_rows')]
        public bool $tenantHasRows,
    ) {
    }
}

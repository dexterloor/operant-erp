<?php

declare(strict_types=1);

namespace App\Modules\Customers\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Customers\ApiResource\State\CustomerCollectionProvider;
use App\Modules\Customers\Application\CustomerListCriteria;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **`ListCustomers` — `GET /api/v1/customers`, `customer.view`; `q`; `sort`; `limit`; `after│before`** (customers slice
 * 1 C1). The API-22 envelope as its own resource with one `Get`, `ItemCollection`'s reason.
 *
 * The parameters are §4.2's cell, exhaustively. **`archived`** (C5 C4a): API-19c limb 1 offers it because the sort
 * manifest now gives `/customers` an Archived row that is not `⛔` (`IA.md` §5.6; D-a's `customer_tenant_archived_idx`),
 * and `RestoreCustomer` ships with it (E9-3). **`sort`** is the manifest's two `/customers` rows, per view: `name`
 * (default) and `code` live, `archived_at` on the Archived view; the declared enum is their union with a live value first
 * (`CustomerListCriteria::SORTS`).
 * **`q`** is TB-5's two columns: a case-insensitive prefix of `customer.name` or an as-stored prefix of `customer.code`
 * (TB-5a, 2026-10-09).
 */
#[ApiResource(
    shortName: 'CustomerCollection',
    operations: [
        new Get(
            uriTemplate: '/customers',
            name: 'ListCustomers',
            openapi: new OpenApiOperation(operationId: 'ListCustomers'),
            provider: CustomerCollectionProvider::class,
            parameters: [
                'archived' => new QueryParameter(
                    description: 'Return the archived customers instead of the live ones (TB-3), most recently archived first. API-19c limb 1: IA §5.6 has a /customers Archived view (C5).',
                    schema: ['type' => 'boolean', 'default' => false],
                ),
                'q' => new QueryParameter(
                    description: 'Free-text search. TB-5 names customer.name and customer.code: a prefix of the name, ignoring case, or of the code, as stored (IA TB-5a).',
                    schema: ['type' => 'string'],
                ),
                'sort' => new QueryParameter(
                    description: 'The order, per view (API-19): name (the default) or code on the live view; archived_at, newest first (the default and only order) on the Archived view. The sort manifest\'s /customers rows.',
                    schema: ['type' => 'string', 'enum' => CustomerListCriteria::SORTS],
                ),
                'limit' => new QueryParameter(
                    description: 'Page size. API-17: default 50, maximum 200.',
                    schema: [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => CustomerListCriteria::MAXIMUM_LIMIT,
                        'default' => CustomerListCriteria::DEFAULT_LIMIT,
                    ],
                ),
                'after' => new QueryParameter(
                    description: 'An opaque cursor from a previous response\'s next_cursor. Mutually exclusive with before.',
                    schema: ['type' => 'string'],
                ),
                'before' => new QueryParameter(
                    description: 'An opaque cursor from a previous response\'s previous_cursor. Mutually exclusive with after.',
                    schema: ['type' => 'string'],
                ),
            ],
            // §4.2's `Perm` cell. Enforced by `PermissionGate` before the provider runs (E-1, ST-4b).
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'customer.view'],
        ),
    ],
)]
final class CustomerCollection
{
    /**
     * @param list<CustomerResource> $items
     */
    public function __construct(
        #[ApiProperty(schema: ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Customer']])]
        public array $items,
        /** Null on the last page and only there (API-22). Passed back as `after`. */
        #[SerializedName('next_cursor')]
        public ?string $nextCursor,
        /** Passed back as `before`. */
        #[SerializedName('previous_cursor')]
        public ?string $previousCursor,
        /** ST-2's discriminator: probed only on an empty page, against `customer` with no filter predicate (API-22). */
        #[SerializedName('tenant_has_rows')]
        public bool $tenantHasRows,
    ) {
    }
}

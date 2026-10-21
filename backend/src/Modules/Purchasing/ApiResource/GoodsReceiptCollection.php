<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Purchasing\ApiResource\State\GoodsReceiptCollectionProvider;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * API-22 — the list envelope, and **`ListGoodsReceipts`** (remaining-ops G1). `FulfilmentCollection`'s shape one module
 * over: a `Get` on `/goods-receipts`, not a `GetCollection` on `GoodsReceiptResource`, so API-22's named envelope is
 * emitted (`next_cursor`, `previous_cursor`, `tenant_has_rows`, no total — TB-2b).
 *
 * **The parameter set is §4.2's cell** — `purchase_order_id`; `q`; `limit`; `after`│`before` — and the column is
 * exhaustive (API-19b). **No `sort`:** `IA.md` §5.6 gives each of this route's two views exactly one order, and
 * `/goods-receipts ordered or filtered by received_at` is `⛔ D-d`. **No `archived`:** `goods_receipt` is deletion
 * class `none`.
 *
 * **Each row is `GetGoodsReceipt`'s document**, lines embedded (§4.2 has no `ListGoodsReceiptLines`).
 */
#[ApiResource(
    shortName: 'GoodsReceiptCollection',
    operations: [
        new Get(
            uriTemplate: '/goods-receipts',
            name: 'ListGoodsReceipts',
            openapi: new OpenApiOperation(operationId: 'ListGoodsReceipts'),
            provider: GoodsReceiptCollectionProvider::class,
            parameters: [
                'purchase_order_id' => new QueryParameter(
                    description: 'Restrict to one purchase order\'s receipts, newest receipt first. The sort manifest\'s "/goods-receipts inside /purchase-orders/[id]" predicate, served by goods_receipt_tenant_order_idx. An order this workspace cannot see matches nothing: the answer is an empty page, never a 404.',
                    schema: ['type' => 'string', 'format' => 'uuid'],
                ),
                'q' => new QueryParameter(
                    description: 'Free-text search. API-19d/TB-5 name one searchable column on this table, goods_receipt.receipt_number, matched by prefix.',
                    schema: ['type' => 'string'],
                ),
                'limit' => new QueryParameter(
                    description: 'Page size. API-17: default 50, maximum 200.',
                    schema: [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => GoodsReceiptCollectionProvider::MAXIMUM_LIMIT,
                        'default' => GoodsReceiptCollectionProvider::DEFAULT_LIMIT,
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
            // §4.2's `Perm` cell, enforced before the provider runs (E-1): a caller without it learns nothing about
            // whichever order the filter names.
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'goods_receipt.view'],
        ),
    ],
)]
final class GoodsReceiptCollection
{
    /**
     * @param list<GoodsReceiptResource> $items
     */
    public function __construct(
        // Written out for `SalesOrderCollection`'s reason (no phpdoc parser installed). `GoodsReceipt` exists as a
        // component because `GetGoodsReceipt` is built and names it.
        #[ApiProperty(schema: ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/GoodsReceipt']])]
        public array $items,
        /** Null on the last page and only there (API-22). Passed back as `after`. */
        #[SerializedName('next_cursor')]
        public ?string $nextCursor,
        /** Passed back as `before`. */
        #[SerializedName('previous_cursor')]
        public ?string $previousCursor,
        /** ST-2's discriminator, computed only when `items` is empty. */
        #[SerializedName('tenant_has_rows')]
        public bool $tenantHasRows,
    ) {
    }
}

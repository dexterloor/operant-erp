<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Sales\ApiResource\State\PickQueueEntryCollectionProvider;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * API-22 — the list envelope, and **`ListPickQueueEntries`** (BK-21; owner D-5 of 2026-11-03, approved in principle under
 * delegation; `docs/design/bk21-design.md` Revision 1, final review `bk21-final-red.md`).
 *
 * **The warehouse's way in to the pick screen:** the open, released sales orders (confirmed or partially fulfilled,
 * `ordered_at` set, live), oldest first, each with only its number, status, date, customer and warehouse. The operator opens
 * the order's pick list (`GetPickList`) from a row. **Gated `fulfilment.create` alone** — the holders who can ship
 * (`owner`, `warehouse_operator`; SoD-4 keeps `admin` out). No `RoleCompanions` row (N-1).
 *
 * **`Get` on `/pick-queue`, not `GetCollection`**, for `FulfilmentCollection`'s reason. **The parameter set is §4.2's cell**
 * — `warehouse_id`; `q`; `limit`; `after`│`before` — exhaustive (API-19b). **No `sort`:** one order. **No `status`:** one
 * view. **No `archived`:** an archived order is always a draft, so none is ever open.
 *
 * The item schema is written inline ({@see PickQueueEntryResource::WIRE_SCHEMA}), because no item operation exists and a
 * `$ref` would dangle (the `SalesOrderLineCollection` lesson).
 */
#[ApiResource(
    shortName: 'PickQueueEntryCollection',
    operations: [
        new Get(
            uriTemplate: '/pick-queue',
            name: 'ListPickQueueEntries',
            openapi: new OpenApiOperation(operationId: 'ListPickQueueEntries'),
            provider: PickQueueEntryCollectionProvider::class,
            parameters: [
                'warehouse_id' => new QueryParameter(
                    description: 'Restrict to one warehouse\'s open orders, served by sales_order_tenant_warehouse_awaiting_pick_idx. A warehouse this workspace cannot see matches nothing: the answer is an empty page, never a 404.',
                    schema: ['type' => 'string', 'format' => 'uuid'],
                ),
                'q' => new QueryParameter(
                    description: 'Free-text search. API-19d/TB-5 name one searchable column on this table, sales_order.order_number, matched by prefix.',
                    schema: ['type' => 'string'],
                ),
                'limit' => new QueryParameter(
                    description: 'Page size. API-17: default 50, maximum 200.',
                    schema: [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => PickQueueEntryCollectionProvider::MAXIMUM_LIMIT,
                        'default' => PickQueueEntryCollectionProvider::DEFAULT_LIMIT,
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
            // §4.2's `Perm` cell, enforced before the provider runs (E-1).
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'fulfilment.create'],
        ),
    ],
)]
final class PickQueueEntryCollection
{
    /**
     * @param list<PickQueueEntryResource> $items
     */
    public function __construct(
        #[ApiProperty(schema: ['type' => 'array', 'items' => PickQueueEntryResource::WIRE_SCHEMA])]
        public array $items,
        /** Null on the last page and only there (API-22). Passed back as `after`. */
        #[SerializedName('next_cursor')]
        public ?string $nextCursor,
        /** Passed back as `before`. */
        #[SerializedName('previous_cursor')]
        public ?string $previousCursor,
        /** ST-2's discriminator, computed only when `items` is empty, over the queue's own base view. */
        #[SerializedName('tenant_has_rows')]
        public bool $tenantHasRows,
    ) {
    }
}

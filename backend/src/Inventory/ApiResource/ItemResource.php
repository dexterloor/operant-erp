<?php

declare(strict_types=1);

namespace App\Inventory\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Api\Idempotency\IdempotencyKeyGuard;
use App\Inventory\ApiResource\State\CreateItemProcessor;
use App\Inventory\ApiResource\State\DeleteItemProcessor;
use App\Inventory\ApiResource\State\ItemProvider;
use App\Inventory\ApiResource\State\RestoreItemProcessor;
use App\Inventory\ApiResource\State\UpdateItemProcessor;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `inventory_item`'s read shape and its five row-level operations.
 *
 * ---------------------------------------------------------------------------
 * The field list is derived, not chosen (API-23)
 * ---------------------------------------------------------------------------
 *   1. every field in `CreateItem`'s `Accepted request fields` cell, minus any
 *      `_id` naming a parent implied by the path -- `/items` implies no parent,
 *      so `supplier_id` survives step 1 and is expanded by API-23b;
 *   2. plus `id`;
 *   3. plus `archived_at`, because `SchemaManifest::TABLES['inventory_item']`
 *      declares deletion class `soft`;
 *   4. plus §4.4.3's additions table, which has **no row for Item** -- so steps
 *      1-3 are the whole shape.
 *
 * `unit_cost`, `currency_code` and `is_active` are columns on the table and are
 * **not** fields of this resource: no `Create*` cell names them and no additions
 * row adds them. A column is not a field.
 *
 * ---------------------------------------------------------------------------
 * Why this is a DTO and not the `InventoryItem` entity (API-8, P-7)
 * ---------------------------------------------------------------------------
 * The tenancy transaction closes before serialization. After it closes, a lazy
 * load raises a real `EntityNotFoundException` -- RLS returns zero rows to the
 * proxy initializer -- so an entity-graph serializer that touches one unhydrated
 * association turns a correct request into a 500 at serialization time, on
 * exactly the associations no fixture covers. Every property below is a scalar
 * or a fully-built value object, so serializing this object cannot reach a
 * database at all.
 */
#[ApiResource(
    shortName: 'Item',
    operations: [
        new Get(
            uriTemplate: '/items/{id}',
            name: 'GetItem',
            openapi: new OpenApiOperation(operationId: 'GetItem'),
            provider: ItemProvider::class,
        ),
        new Post(
            uriTemplate: '/items',
            name: 'CreateItem',
            status: 201,
            openapi: new OpenApiOperation(operationId: 'CreateItem'),
            input: CreateItemInput::class,
            denormalizationContext: [
                // API-9: unknown fields are refused, never ignored. This is the
                // runtime half of B-3 -- the spec-level clause stops `id` being
                // *declared*, and only a deny-unknown deserializer stops it
                // being *accepted*.
                'allow_extra_attributes' => false,
                // The provider returns nothing for a create, and leaving this
                // unset makes API Platform emit a 5.0 deprecation on every POST.
                'api_assign_object_to_populate' => false,
            ],
            processor: CreateItemProcessor::class,
            // §4.2 marks `CreateItem` Idem. API-14 requires the header; §6.1's
            // interim rule is what makes the operation safe without the key
            // store that does not exist yet -- a repeat collides with
            // `inventory_item_tenant_sku_live`.
            extraProperties: [IdempotencyKeyGuard::OPERATION_FLAG => true],
        ),
        new Patch(
            uriTemplate: '/items/{id}',
            name: 'UpdateItem',
            status: 200,
            openapi: new OpenApiOperation(operationId: 'UpdateItem'),
            input: UpdateItemInput::class,
            denormalizationContext: [
                'allow_extra_attributes' => false,
                // The input DTO is a different class from the resource, so there
                // is nothing to populate; the existing row is read by the
                // processor inside the tenancy transaction that writes it.
                'api_assign_object_to_populate' => false,
                // Without this, an `id` in a PATCH body sends API Platform off
                // to resolve it as an IRI and the answer is a bare 400 from the
                // IRI converter. The row this operation writes is named by the
                // path and by nothing else (§3.1 case 2), so the body's `id` is
                // an undeclared field and must be refused as one.
                'api_allow_update' => false,
            ],
            // The row is read by this provider first, so a `PATCH` at an id no
            // row answers is `not-found` before the body is even parsed -- and
            // it is the same `not-found` `GetItem` returns, which is what §5.3
            // clause 2 requires. Without it API Platform's default provider
            // answers with a bare `NotFoundHttpException`.
            provider: ItemProvider::class,
            processor: UpdateItemProcessor::class,
        ),
        new Delete(
            uriTemplate: '/items/{id}',
            name: 'DeleteItem',
            status: 204,
            openapi: new OpenApiOperation(operationId: 'DeleteItem'),
            provider: ItemProvider::class,
            processor: DeleteItemProcessor::class,
        ),
        // API-21 branch 5: a POST that creates no row this contract addresses.
        // `RestoreItem` mints nothing -- it clears `deleted_at` on a row the
        // caller already holds the id of -- so 200 with the resource in its new
        // state, and no `Location`.
        new Post(
            uriTemplate: '/items/{id}/restoration',
            name: 'RestoreItem',
            status: 200,
            openapi: new OpenApiOperation(operationId: 'RestoreItem'),
            input: false,
            deserialize: false,
            read: false,
            processor: RestoreItemProcessor::class,
            extraProperties: [IdempotencyKeyGuard::OPERATION_FLAG => true],
        ),
    ],
)]
final class ItemResource
{
    /**
     * @param string $reorderPoint    numeric(19,6) — carried as a decimal string
     * @param string $reorderQuantity numeric(19,6)
     * @param string $safetyStock     numeric(19,6)
     */
    public function __construct(
        public string $id,
        public string $sku,
        public string $name,
        #[SerializedName('unit_of_measure_code')]
        public string $unitOfMeasureCode,
        public ?SupplierReference $supplier,
        #[SerializedName('reorder_point')]
        public string $reorderPoint,
        #[SerializedName('reorder_quantity')]
        public string $reorderQuantity,
        #[SerializedName('safety_stock')]
        public string $safetyStock,
        /** Non-null iff the row is archived (ST-9). */
        #[SerializedName('archived_at')]
        public ?string $archivedAt,
    ) {
    }
}

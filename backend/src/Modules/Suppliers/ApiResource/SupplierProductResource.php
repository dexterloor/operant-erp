<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Suppliers\ApiResource\State\CreateSupplierProductProcessor;
use App\Modules\Suppliers\ApiResource\State\SupplierProductProvider;
use App\Modules\Suppliers\ApiResource\State\UpdateSupplierProductProcessor;
use App\Modules\Suppliers\Application\SupplierProductView;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Http\Location\CreatedRowLocation;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **`CreateSupplierProduct` and `UpdateSupplierProduct`** (suppliers slice 1 C3; `docs/session13/suppliers-s1-design.md`
 * FINAL F-4 and §5). A DTO, never the entity. `CustomerAddressResource`'s operation shape.
 *
 * The shape is §4.4.3's derivation (API-23): the accepted fields `inventory_item_id` (expanded under API-23b to
 * `inventory_item = {id, sku, name}`), `supplier_sku`, `unit_cost`, `lead_time_days`, `minimum_order_quantity` (minus
 * `supplier_id`, implied by the path), then `id`; **no `archived_at`** (`supplier_product` is class `hard`); plus the
 * additions row's **`currency_code`**, copied from the supplier when the entry is added (owner Q-S4 (a)). Decimals are
 * strings (S-12): `unit_cost` at scale 4, `minimum_order_quantity` at scale 6 (the column is `min_order_quantity`).
 *
 * **This is a cost read** (PC-12): `unit_cost` is classified `cost` (`MarginSources::columns()`), so from this commit
 * `supplier_product.view` (the list) and `supplier_product.edit` (these writes' 200/201 bodies, PC-17) are cost sources and
 * the margin warning counts them (`MarginSourcesDerivationTest` derives it from this schema).
 *
 * **No `GetSupplierProduct`**: §4.2 declares none (a bounded sub-list, `API.md` §4.2's *"No `Get` is offered for a single
 * line, contact or address"*), so API Platform mints its not-exposed fallback route and `CreatedRowLocation` names the URI
 * §4.2 does declare.
 *
 * **`OPERATION_DENIAL_SUBJECT` = `['supplier', 'id']`** on both (design §5.5; the `AssignRole` precedent): the permission's
 * resource half is `supplier_product`, but the recorded `{id}` addresses a `supplier` row, so without the declaration a
 * denial would record `(supplier_product, <supplier id>)` — F-1's mislabel. (Customers did not need it: parent and
 * permission agree there.)
 */
#[ApiResource(
    shortName: 'SupplierProduct',
    operations: [
        // API-21 branch 4: §4.2 declares `UpdateSupplierProduct` at `/suppliers/{id}/products/{productId}`, the row-level URI
        // of the row this POST creates, so the answer is 201 with a `Location` naming it and the entry as the body.
        new Post(
            uriTemplate: '/suppliers/{id}/products',
            uriVariables: [
                'id' => new Link(fromClass: SupplierResource::class, identifiers: ['id'], parameterName: 'id'),
            ],
            name: 'CreateSupplierProduct',
            status: 201,
            openapi: new OpenApiOperation(operationId: 'CreateSupplierProduct'),
            input: CreateSupplierProductInput::class,
            denormalizationContext: [
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
            ],
            processor: CreateSupplierProductProcessor::class,
            // §4.2: `unique:supplier_product_unique` (supplier, item). API-14 demands the header; a repeat is refused by the
            // unique, never replayed (`CreateSupplier`'s precedent).
            extraProperties: [
                IdempotencyKeyGuard::OPERATION_FLAG => true,
                PermissionGate::OPERATION_PERMISSION => 'supplier_product.edit',
                PermissionGate::OPERATION_DENIAL_SUBJECT => ['supplier', 'id'],
                // Without it API Platform derives `Location` from its `NotExposed` fallback and names
                // `/supplier_products/{id}`, a path that answers 404 (the reason `CreatedRowLocation` exists).
                CreatedRowLocation::OPERATION_URI_TEMPLATE => '/suppliers/{id}/products/{productId}',
            ],
        ),
        new Patch(
            uriTemplate: '/suppliers/{id}/products/{productId}',
            uriVariables: [
                'id' => new Link(fromClass: SupplierResource::class, identifiers: ['id'], parameterName: 'id'),
                'productId' => new Link(fromClass: SupplierProductResource::class, identifiers: ['id'], parameterName: 'productId'),
            ],
            name: 'UpdateSupplierProduct',
            status: 200,
            openapi: new OpenApiOperation(operationId: 'UpdateSupplierProduct'),
            input: UpdateSupplierProductInput::class,
            denormalizationContext: [
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
                'api_allow_update' => false,
            ],
            // The pair is read first, so a PATCH at an entry no row of THIS supplier answers is a 404 (§5.3 clause 2).
            provider: SupplierProductProvider::class,
            processor: UpdateSupplierProductProcessor::class,
            // No `OPERATION_FLAG`: §4.2's `Idem` cell is `no` — a PATCH sets absolute values on a row that exists.
            extraProperties: [
                PermissionGate::OPERATION_PERMISSION => 'supplier_product.edit',
                PermissionGate::OPERATION_DENIAL_SUBJECT => ['supplier', 'id'],
            ],
        ),
    ],
)]
final class SupplierProductResource
{
    public function __construct(
        public string $id,
        #[SerializedName('inventory_item')]
        public SupplierItemReference $inventoryItem,
        #[SerializedName('supplier_sku')]
        public string $supplierSku,
        #[SerializedName('unit_cost')]
        public string $unitCost,
        #[SerializedName('lead_time_days')]
        public int $leadTimeDays,
        #[SerializedName('minimum_order_quantity')]
        public string $minimumOrderQuantity,
        #[SerializedName('currency_code')]
        public string $currencyCode,
    ) {
    }

    public static function fromView(SupplierProductView $view): self
    {
        return new self(
            $view->id,
            new SupplierItemReference($view->itemId, $view->itemSku, $view->itemName),
            $view->supplierSku,
            $view->unitCost,
            $view->leadTimeDays,
            $view->minimumOrderQuantity,
            $view->currencyCode,
        );
    }
}

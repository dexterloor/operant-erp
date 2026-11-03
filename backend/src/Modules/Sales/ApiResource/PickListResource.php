<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Sales\ApiResource\State\PickListProvider;
use App\Modules\Sales\Application\PickListLineView;
use App\Modules\Sales\Application\PickListView;
use App\Modules\Sales\Application\SalesNamedRef;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **The pick read — `GetPickList`, `GET /sales-orders/{id}/pick-list`, gated on
 * `fulfilment.view` alone.** The 116th §4.2 row, ratified by the owner on
 * 2026-09-22 (ruling R7 of sales step 5): V2-S-1b's money-free read, its own
 * resource rather than a projection of `SalesOrder` toggled by permission
 * (clause 1), with its own §4.2 and §4.4.3 additions rows (clause 2), gated on
 * `fulfilment.view` and not `sales_order.view` (clause 3). **V2-S-1b ships IN
 * PART here** (ruling R8): clause 4 (no `sales_order` table under PC-8) is
 * re-carried to `SEC` on `AiToolDtoTest`'s pin, and clause 5's frontend half to
 * `FE` + `UX` on `OwedAmendmentTripwireTest::noFrontendShipsWithoutThePickRouteCarry`
 * (trigger re-anchored by the owner on 2026-11-03: a frontend page at
 * `/sales-orders` or below, or frontend use of `CreateFulfilment`/`GetPickList`,
 * owes the pick page at `/sales-orders/[id]/fulfil`).
 *
 * The id in the path is the sales order's, and it is this resource's `id`: a
 * pick list is a view of one order, not a row of its own. Its shape is
 * `SECURITY_MODEL.md` PC-9's second deliberate exception exactly — see
 * `PickListReader` for the columns, the visibility rule (a draft is not-found)
 * and what is never projected.
 */
#[ApiResource(
    shortName: 'PickList',
    operations: [
        new Get(
            uriTemplate: '/sales-orders/{id}/pick-list',
            name: 'GetPickList',
            openapi: new OpenApiOperation(operationId: 'GetPickList'),
            provider: PickListProvider::class,
            // §4.2's `Perm` cell: `fulfilment.view` ALONE (V2-S-1b clause 3, ruling R7). Enforced by
            // `PermissionGate` on `kernel.controller`, before the provider loads anything — E-1.
            // F-1: a denial here names the sales order its path's {id} addresses, not a fulfilment
            // (`PermissionGate::OPERATION_DENIAL_SUBJECT`).
            extraProperties: [
                PermissionGate::OPERATION_PERMISSION => 'fulfilment.view',
                PermissionGate::OPERATION_DENIAL_SUBJECT => ['sales_order', 'id'],
            ],
        ),
    ],
)]
final class PickListResource
{
    /**
     * The served schema of one `PickListLineView` (BK-13 C2). Without it the list's items fall back to `string|null`, because the
     * element type lives only in a docblock. A field is optional only when it is nullable and skipped when null.
     */
    private const LINE_SCHEMA = ['type' => 'object', 'required' => ['sales_order_line', 'inventory_item', 'quantity', 'quantity_fulfilled', 'quantity_outstanding', 'candidate_bins'], 'properties' => [
        'sales_order_line' => ['type' => 'object', 'required' => ['id', 'line_number'], 'properties' => [
            'id' => ['type' => 'string', 'format' => 'uuid'],
            'line_number' => ['type' => 'integer'],
        ]],
        'inventory_item' => ['type' => 'object', 'required' => ['id', 'sku', 'name'], 'properties' => [
            'id' => ['type' => 'string', 'format' => 'uuid'],
            'sku' => ['type' => 'string'],
            'name' => ['type' => 'string'],
        ]],
        'quantity' => ['type' => 'string'],
        'quantity_fulfilled' => ['type' => 'string'],
        'quantity_outstanding' => ['type' => 'string'],
        'candidate_bins' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['storage_location', 'qty_on_hand'], 'properties' => [
            'storage_location' => ['type' => 'object', 'required' => ['id', 'code'], 'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'code' => ['type' => 'string'],
            ]],
            'qty_on_hand' => ['type' => 'string'],
        ]]],
    ]];

    /**
     * @param list<PickListLineView> $lines live lines only, by `line_number`
     */
    public function __construct(
        public string $id,
        #[SerializedName('order_number')]
        public string $orderNumber,
        /** One of `SalesOrder::STATUS_*` — B1N-12: the actor who shipped sees the order's resulting status. */
        public string $status,
        #[ApiProperty(schema: SalesReferenceSchema::NAMED)]
        public SalesNamedRef $customer,
        #[ApiProperty(schema: SalesReferenceSchema::NAMED)]
        public SalesNamedRef $warehouse,
        #[SerializedName('ship_to_line')]
        public string $shipToLine,
        #[SerializedName('ship_to_city')]
        public string $shipToCity,
        #[SerializedName('ship_to_postal_code')]
        public string $shipToPostalCode,
        #[SerializedName('ship_to_country_code')]
        public string $shipToCountryCode,
        #[ApiProperty(schema: ['type' => 'array', 'items' => self::LINE_SCHEMA])]
        public array $lines,
    ) {
    }

    public static function fromView(PickListView $view): self
    {
        return new self(
            $view->id,
            $view->orderNumber,
            $view->status,
            $view->customer,
            $view->warehouse,
            $view->shipToLine,
            $view->shipToCity,
            $view->shipToPostalCode,
            $view->shipToCountryCode,
            $view->lines,
        );
    }
}

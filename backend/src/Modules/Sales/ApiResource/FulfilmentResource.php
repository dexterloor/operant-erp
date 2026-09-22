<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Sales\ApiResource\State\CreateFulfilmentProcessor;
use App\Modules\Sales\ApiResource\State\FulfilmentProvider;
use App\Modules\Sales\Application\FulfilmentLineView;
use App\Modules\Sales\Application\FulfilmentView;
use App\Modules\Sales\Application\SalesActorRef;
use App\Modules\Sales\Application\SalesOrderRef;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **One shipment against one sales order (FLOWS F-5 step 5) — the event that depletes stock.** Sales step 5,
 * commit 4 (2026-09-22). `GoodsReceiptResource`'s shape, one module over.
 *
 * The read shape is API-23a's derivation: `shipped_at` and the three line fields from `CreateFulfilment`'s
 * accepted cell, `id`, and §4.4.3's additions — `fulfilment_number`, the parent order's `{id, order_number}`,
 * `shipped_by = {id, display_name}` (owner ruling R6, PC-11 (4a): evidence for SoD-4, not enforcement; never
 * null — the column is NOT NULL) and `lines[].inventory_item = {id, sku, name}`. No `archived_at`: class `none`.
 * **No money field.**
 *
 * `GetFulfilment` is declared FIRST on purpose: API Platform's own `Location` names a resource's first item
 * operation, and that is the URI the 201 must carry (API-21 branch 4). Owner ruling R9 put it in step 5 for
 * exactly that reason; `ListFulfilments` stays in step 6.
 */
#[ApiResource(
    shortName: 'Fulfilment',
    operations: [
        new Get(
            uriTemplate: '/fulfilments/{id}',
            name: 'GetFulfilment',
            openapi: new OpenApiOperation(operationId: 'GetFulfilment'),
            provider: FulfilmentProvider::class,
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'fulfilment.view'],
        ),
        // N-8: no standalone create route. The fulfilment hangs off its order.
        new Post(
            uriTemplate: '/sales-orders/{id}/fulfilments',
            uriVariables: [
                'id' => new Link(fromClass: SalesOrderResource::class, identifiers: ['id'], parameterName: 'id'),
            ],
            name: 'CreateFulfilment',
            status: 201,
            openapi: new OpenApiOperation(operationId: 'CreateFulfilment'),
            input: CreateFulfilmentInput::class,
            denormalizationContext: [
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
            ],
            processor: CreateFulfilmentProcessor::class,
            // §4.2's cell is `unique:fulfilment_idem_unique` (sales step 5 C2): the header's hash IS the indexed
            // value, so the header is demanded. SoD-4: `fulfilment.create` is held by `owner` and
            // `warehouse_operator` only — `sales_rep` and `admin` are refused.
            extraProperties: [
                IdempotencyKeyGuard::OPERATION_FLAG => true,
                PermissionGate::OPERATION_PERMISSION => 'fulfilment.create',
            ],
        ),
    ],
)]
final class FulfilmentResource
{
    /**
     * @param list<FulfilmentLineView> $lines
     */
    public function __construct(
        public string $id,
        #[SerializedName('fulfilment_number')]
        public string $fulfilmentNumber,
        #[SerializedName('shipped_at')]
        public string $shippedAt,
        #[SerializedName('sales_order')]
        public SalesOrderRef $salesOrder,
        public array $lines,
        /** Owner ruling R6 / PC-11 (4a): who declared the goods shipped. Evidence for SoD-4, not enforcement. */
        #[SerializedName('shipped_by')]
        public SalesActorRef $shippedBy,
    ) {
    }

    public static function fromView(FulfilmentView $view): self
    {
        return new self($view->id, $view->fulfilmentNumber, $view->shippedAt, $view->salesOrder, $view->lines, $view->shippedBy);
    }
}

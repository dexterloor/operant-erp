<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Purchasing\ApiResource\State\CreateGoodsReceiptProcessor;
use App\Modules\Purchasing\ApiResource\State\GoodsReceiptProvider;
use App\Modules\Purchasing\Application\GoodsReceiptLineView;
use App\Modules\Purchasing\Application\GoodsReceiptView;
use App\Modules\Purchasing\Application\OrderReference;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * One delivery recorded against one purchase order (FLOWS F-3).
 *
 * The read shape is API-23a's derivation: `received_at` and the three line
 * fields from `CreateGoodsReceipt`'s accepted cell, `id`, and §4.4.3's
 * additions for this resource, `receipt_number` and the parent order's
 * `{id, order_number}`, and `received_by = {id, display_name}` or `null`
 * (API-O-33 (a), 2026-09-14). No `archived_at`: `goods_receipt` is class `none`.
 *
 * `GetGoodsReceipt` is declared FIRST on purpose. API Platform's own
 * `Location` names a resource's first item operation, and that is the URI the
 * 201 must carry (API-21 branch 4), so no `CreatedRowLocation` template is
 * needed — the reason `CreatePurchaseOrder` gives for itself.
 */
#[ApiResource(
    shortName: 'GoodsReceipt',
    operations: [
        new Get(
            uriTemplate: '/goods-receipts/{id}',
            name: 'GetGoodsReceipt',
            openapi: new OpenApiOperation(operationId: 'GetGoodsReceipt'),
            provider: GoodsReceiptProvider::class,
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'goods_receipt.view'],
        ),
        // N-8: no standalone create route. The receipt hangs off its order.
        new Post(
            uriTemplate: '/purchase-orders/{id}/goods-receipts',
            uriVariables: [
                'id' => new Link(fromClass: PurchaseOrderResource::class, identifiers: ['id'], parameterName: 'id'),
            ],
            name: 'CreateGoodsReceipt',
            status: 201,
            openapi: new OpenApiOperation(operationId: 'CreateGoodsReceipt'),
            input: CreateGoodsReceiptInput::class,
            denormalizationContext: [
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
            ],
            processor: CreateGoodsReceiptProcessor::class,
            // §4.2's cell is `unique:goods_receipt_idem_unique` (8-i): the
            // header's hash IS the indexed value, so the header is demanded.
            extraProperties: [
                IdempotencyKeyGuard::OPERATION_FLAG => true,
                PermissionGate::OPERATION_PERMISSION => 'goods_receipt.create',
            ],
        ),
    ],
)]
final class GoodsReceiptResource
{
    /**
     * @param list<GoodsReceiptLineView> $lines
     */
    public function __construct(
        public string $id,
        #[SerializedName('receipt_number')]
        public string $receiptNumber,
        #[SerializedName('received_at')]
        public string $receivedAt,
        #[SerializedName('purchase_order')]
        public OrderReference $purchaseOrder,
        public array $lines,
        /**
         * API-O-33 option (a), ruled 2026-09-14: who booked the delivery, gated
         * on `goods_receipt.view` like the rest of the shape (PC-11 (4a) — a
         * business-document actor, not privilege history). **Null is a real
         * value:** the column is nullable, and a receipt with no recorded
         * receiver renders as unknown. Evidence for SoD-2, not enforcement.
         */
        #[SerializedName('received_by')]
        public ?ActorReference $receivedBy,
    ) {
    }

    public static function fromView(GoodsReceiptView $view): self
    {
        return new self(
            $view->id,
            $view->receiptNumber,
            $view->receivedAt,
            $view->purchaseOrder,
            $view->lines,
            null === $view->receivedById
                ? null
                : new ActorReference($view->receivedById, (string) $view->receivedByDisplayName),
        );
    }
}

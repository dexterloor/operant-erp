<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Sales\ApiResource\State\CreateSalesReturnProcessor;
use App\Modules\Sales\ApiResource\State\SalesReturnProvider;
use App\Modules\Sales\Application\FulfilmentRef;
use App\Modules\Sales\Application\InvoiceNumberRef;
use App\Modules\Sales\Application\SalesActorRef;
use App\Modules\Sales\Application\SalesReturnLineView;
use App\Modules\Sales\Application\SalesReturnView;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

/**
 * **Goods a customer sent back against one shipment — sales step 7, commit R2 (2026-10-01).** `FulfilmentResource`'s
 * shape, one document over. Owner rulings 2026-09-30 (`docs/session13/output9-sales-8-ruling.md`) and `SEC`'s.
 *
 * The read shape is API-23a's derivation: `returned_at`, `reason` and the line fields from `CreateSalesReturn`'s
 * accepted cell, and §4.4.3's additions — `sales_return_number`, `fulfilment {id, fulfilment_number}`, `invoice` (the
 * live invoice when recorded, PC-15) and `credit_target` (the invoice a credit must be issued against, null unless
 * the return is creditable — L5-1 decided (b)), `deducts` (Q-L (a′)), `recorded_by {id, display_name}` (PC-11 (4a),
 * SoD-5's evidence), `created_at`; lines carry the shipped line (Q-H, with the bin it left from), the item,
 * `quantity`, the bin it came back into and `quantity_credited` (PC-9's third exception: quantities only). **No money.**
 * `credit_blocked_by_payment` is served ONLY to an `invoice.view` holder and is ABSENT otherwise (`RED-4` M4-1).
 *
 * `GetSalesReturn` is declared FIRST: API Platform's `Location` names a resource's first item operation (API-21
 * branch 4), and the 201 must name a route that answers (V2-R-4).
 */
#[ApiResource(
    shortName: 'SalesReturn',
    operations: [
        new Get(
            uriTemplate: '/sales-returns/{id}',
            name: 'GetSalesReturn',
            openapi: new OpenApiOperation(operationId: 'GetSalesReturn'),
            provider: SalesReturnProvider::class,
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'sales_return.view'],
        ),
        // N-8: the return hangs off the shipment it returns (design §4: the last segment is the collection it creates).
        new Post(
            uriTemplate: '/fulfilments/{id}/sales-returns',
            uriVariables: [
                'id' => new Link(fromClass: FulfilmentResource::class, identifiers: ['id'], parameterName: 'id'),
            ],
            name: 'CreateSalesReturn',
            status: 201,
            openapi: new OpenApiOperation(operationId: 'CreateSalesReturn'),
            input: CreateSalesReturnInput::class,
            denormalizationContext: [
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
            ],
            processor: CreateSalesReturnProcessor::class,
            // §4.2's cell is `unique:sales_return_idem_unique` (R1): the header's hash IS the indexed value. Owner ruling
            // Q-F: `sales_return.create` is held by `warehouse_operator` (and `admin` by PC-14's inverse clause, and `owner`).
            extraProperties: [
                IdempotencyKeyGuard::OPERATION_FLAG => true,
                PermissionGate::OPERATION_PERMISSION => 'sales_return.create',
                // F-1: a denial here names the fulfilment its path's {id} addresses, not a sales return.
                PermissionGate::OPERATION_DENIAL_SUBJECT => ['fulfilment', 'id'],
            ],
        ),
    ],
)]
final class SalesReturnResource
{
    /**
     * @param list<SalesReturnLineView> $lines
     */
    public function __construct(
        public string $id,
        #[SerializedName('sales_return_number')]
        public string $salesReturnNumber,
        public string $reason,
        #[SerializedName('returned_at')]
        public string $returnedAt,
        public FulfilmentRef $fulfilment,
        /** The fulfilment's live invoice when the return was recorded (design F-2), or null. A PC-15 reference. */
        public ?InvoiceNumberRef $invoice,
        /** The invoice a credit for this return must be issued against; null unless creditable (L5-1 (b)). */
        #[SerializedName('credit_target')]
        public ?InvoiceNumberRef $creditTarget,
        /** Owner ruling Q-L (a′): whether this return lowers the next invoice, decided when it was recorded. */
        public bool $deducts,
        /** PC-11 (4a): who recorded the return — SoD-5's evidence, not enforcement. */
        #[SerializedName('recorded_by')]
        public SalesActorRef $recordedBy,
        #[SerializedName('created_at')]
        public string $createdAt,
        public array $lines,
        /**
         * `true` when the return would be creditable but its invoice is paid (Q-E, G-23's queue). **Absent** — not null —
         * for a caller without `invoice.view`: it names payment as the cause, which is invoice status (`RED-4` M4-1,
         * PC-9's third exception's violation clause). The per-property skip is what makes "absent" true.
         */
        #[SerializedName('credit_blocked_by_payment')]
        #[Context([AbstractObjectNormalizer::SKIP_NULL_VALUES => true])]
        #[ApiProperty(required: false)]
        public ?bool $creditBlockedByPayment = null,
    ) {
    }

    public static function fromView(SalesReturnView $view): self
    {
        return new self($view->id, $view->salesReturnNumber, $view->reason, $view->returnedAt, $view->fulfilment,
            $view->invoice, $view->creditTarget, $view->deducts, $view->recordedBy, $view->createdAt, $view->lines,
            $view->creditBlockedByPayment);
    }
}

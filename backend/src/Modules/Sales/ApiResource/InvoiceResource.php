<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Sales\ApiResource\State\InvoiceProvider;
use App\Modules\Sales\ApiResource\State\IssueInvoiceProcessor;
use App\Modules\Sales\ApiResource\State\PayInvoiceProcessor;
use App\Modules\Sales\ApiResource\State\ReverseInvoicePaymentProcessor;
use App\Modules\Sales\ApiResource\State\VoidInvoiceProcessor;
use App\Modules\Sales\Application\FulfilmentRef;
use App\Modules\Sales\Application\InvoiceView;
use App\Modules\Sales\Application\SalesActorRef;
use App\Modules\Sales\Application\SalesNamedRef;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **The demand for payment — one invoice per shipment (D-A as amended).** Sales step 6, C3 (2026-09-28).
 *
 * The shape is §4.4.3's Invoice row (`invoice` has no `Create*` row, N-8, so the row is the whole shape beyond `id`):
 * `invoice_number`, `status` (**derived** — `overdue` once `due_at` has passed on an issued invoice, owner ruling Q2),
 * `total_amount` (numeric(19,4), a string), `currency_code`, `issued_at`, `due_at`, `paid_at`, **`paid_by = {id, display_name}`** (null unless paid; sales step 6 C5, `SEC` §2 —
 * Q7, PC-11 (4a): who recorded the payment, A2.3's input), and the references
 * `fulfilment = {id, fulfilment_number}` (the billed shipment, D-A) and `customer = {id, name}` (V2-C-10). **Never
 * `issued_by`** (`SEC` §2) and **no amount credited or due** (V2-C-10's second half, owed to `IssueCreditNote`).
 *
 * `GetInvoice` is declared FIRST on purpose: API Platform's own `Location` names a resource's first item operation,
 * and that is the URI `IssueInvoice`'s 201 must carry (API-21 branch 4; M-19). `CreatedRowLocation`'s template is
 * deliberately NOT declared: it binds the request's own `{id}` first, which on `/fulfilments/{id}/invoices` is the
 * FULFILMENT's id.
 */
#[ApiResource(
    shortName: 'Invoice',
    operations: [
        new Get(
            uriTemplate: '/invoices/{id}',
            name: 'GetInvoice',
            openapi: new OpenApiOperation(operationId: 'GetInvoice'),
            provider: InvoiceProvider::class,
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'invoice.view'],
        ),
        // N-8: no standalone create route. The invoice hangs off the shipment it bills (§4.2, owner ruling C).
        new Post(
            uriTemplate: '/fulfilments/{id}/invoices',
            uriVariables: [
                'id' => new Link(fromClass: FulfilmentResource::class, identifiers: ['id'], parameterName: 'id'),
            ],
            name: 'IssueInvoice',
            status: 201,
            openapi: new OpenApiOperation(operationId: 'IssueInvoice'),
            // §4.2's `Accepted request fields` cell is `—`: the request carries nothing. `input: false` and
            // `deserialize: false` make that true of the code (`BodylessWriteRefusal`), as `ConfirmSalesOrder` does.
            input: false,
            deserialize: false,
            // The fulfilment is read inside the issuing transaction, never by a provider before it opens.
            read: false,
            processor: IssueInvoiceProcessor::class,
            // §4.2's cell is `unique:invoice_tenant_fulfilment_not_void`, so API-14 demands the header — and the
            // header is unused: the key is the path's fulfilment, and a repeat is refused, never replayed (§4.2
            // item 4). SoD-4: `invoice.issue` is held by `owner` and `sales_rep`, never by `warehouse_operator`,
            // who declares the shipment.
            extraProperties: [
                IdempotencyKeyGuard::OPERATION_FLAG => true,
                PermissionGate::OPERATION_PERMISSION => 'invoice.issue',
            ],
        ),
        // Sales step 6, C5 (2026-09-28). API-21 branch 5: a transition creates no row, so 200 with the invoice in its
        // NEW state. `guard:invoice.status` — a repeat meets the status guard, so no `Idempotency-Key` is demanded
        // (API-14 as amended). `read: false`: the invoice is locked and re-read inside the paying transaction
        // (`InvoicePayment` step 2), never by a provider before it opens.
        new Post(
            uriTemplate: '/invoices/{id}/payment',
            name: 'PayInvoice',
            status: 200,
            openapi: new OpenApiOperation(operationId: 'PayInvoice'),
            input: PayInvoiceInput::class,
            denormalizationContext: [
                // API-9: unknown fields are refused, never ignored — there is no field for the payer.
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
                'api_allow_update' => false,
            ],
            read: false,
            processor: PayInvoiceProcessor::class,
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'invoice.pay'],
        ),
        // Sales step 6, C6 (2026-09-28). `paid → issued` under `invoice.pay` itself (ADR-008 R-15), reason required,
        // reverser ≠ recorder unless an owner (A2.3, the one owner predicate). 200 with the invoice in its new state
        // (API-21 branch 5); `guard:invoice.status`, so no `Idempotency-Key` is demanded.
        new Post(
            uriTemplate: '/invoices/{id}/payment-reversal',
            name: 'ReverseInvoicePayment',
            status: 200,
            openapi: new OpenApiOperation(operationId: 'ReverseInvoicePayment'),
            input: ReverseInvoicePaymentInput::class,
            denormalizationContext: [
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
                'api_allow_update' => false,
            ],
            read: false,
            processor: ReverseInvoicePaymentProcessor::class,
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'invoice.pay'],
        ),
        // Sales step 6, C7 (2026-09-29). `issued`/`overdue` → `void` under `invoice.void` — held by `owner` alone among
        // the default roles since A2.1a (ADR-008 A2.7), and that holding is the whole control: no owner check in the
        // code (`SEC` §9.1), except B′ (only an owner voids an invoice whose payment was ever reversed). Reason required.
        // 200 with the invoice in its new state (API-21 branch 5); `guard:invoice.status`, so no `Idempotency-Key`.
        new Post(
            uriTemplate: '/invoices/{id}/void',
            name: 'VoidInvoice',
            status: 200,
            openapi: new OpenApiOperation(operationId: 'VoidInvoice'),
            input: VoidInvoiceInput::class,
            denormalizationContext: [
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
                'api_allow_update' => false,
            ],
            read: false,
            processor: VoidInvoiceProcessor::class,
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'invoice.void'],
        ),
    ],
)]
final class InvoiceResource
{
    public function __construct(
        public string $id,
        #[SerializedName('invoice_number')]
        public string $invoiceNumber,
        public string $status,
        #[SerializedName('total_amount')]
        public string $totalAmount,
        #[SerializedName('currency_code')]
        public string $currencyCode,
        #[SerializedName('issued_at')]
        public string $issuedAt,
        #[SerializedName('due_at')]
        public string $dueAt,
        #[SerializedName('paid_at')]
        public ?string $paidAt,
        #[SerializedName('paid_by')]
        public ?SalesActorRef $paidBy,
        public FulfilmentRef $fulfilment,
        public SalesNamedRef $customer,
    ) {
    }

    public static function fromView(InvoiceView $view): self
    {
        return new self($view->id, $view->invoiceNumber, $view->status, $view->totalAmount, $view->currencyCode,
            $view->issuedAt, $view->dueAt, $view->paidAt, $view->paidBy, $view->fulfilment, $view->customer);
    }
}

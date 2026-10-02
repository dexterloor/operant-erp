<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Sales\ApiResource\State\CreditNoteProvider;
use App\Modules\Sales\ApiResource\State\IssueAdjustmentCreditNoteProcessor;
use App\Modules\Sales\ApiResource\State\IssueCreditNoteProcessor;
use App\Modules\Sales\Application\CreditNoteLineView;
use App\Modules\Sales\Application\CreditNoteView;
use App\Modules\Sales\Application\InvoiceNumberRef;
use App\Modules\Sales\Application\SalesActorRef;
use App\Modules\Sales\Application\SalesReturnNumberRef;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **A credit note — the only reduction of an issued invoice (OI-2).** Sales step 7 C1 (2026-10-01).
 *
 * The shape is §4.4.3's CreditNote row (`Issue / CreditNote`, no `Create*` row, so the row is the whole shape beyond
 * `id`): `credit_note_number`, `reason`, `invoice = {id, invoice_number}`, `sales_return = {id, sales_return_number}|null`
 * (PC-15, `SEC` S-4), `currency_code`, `total_amount` (numeric(19,4), a string), `issued_at`, `issued_by = {id,
 * display_name}` (PC-11 (4a), `SEC` S-8) and `lines`. Read under `invoice.view` (Q-B: same money, same audience);
 * `IssueCreditNote`'s 201 carries the same shape under `credit_note.issue` (PC-17).
 *
 * `GetCreditNote` is declared FIRST: API Platform's `Location` names a resource's first item operation, and that is the
 * URI `IssueCreditNote`'s 201 must carry (API-21 branch 4; V2-R-4). `CreatedRowLocation`'s template is not declared: on
 * `/invoices/{id}/credit-notes` the request's own `{id}` is the INVOICE's.
 */
#[ApiResource(
    shortName: 'CreditNote',
    operations: [
        new Get(
            uriTemplate: '/credit-notes/{id}',
            name: 'GetCreditNote',
            openapi: new OpenApiOperation(operationId: 'GetCreditNote'),
            provider: CreditNoteProvider::class,
            extraProperties: [
                PermissionGate::OPERATION_PERMISSION => 'invoice.view',
                // F-1 / `roles-s3-red-3.md` H-1: the code's resource half is `invoice`, but `{id}` addresses a credit note.
                PermissionGate::OPERATION_DENIAL_SUBJECT => ['credit_note', 'id'],
            ],
        ),
        // N-8: the credit note hangs off the invoice it reduces (§4.2; the last segment is the collection it creates).
        new Post(
            uriTemplate: '/invoices/{id}/credit-notes',
            uriVariables: [
                'id' => new Link(fromClass: InvoiceResource::class, identifiers: ['id'], parameterName: 'id'),
            ],
            name: 'IssueCreditNote',
            status: 201,
            openapi: new OpenApiOperation(operationId: 'IssueCreditNote'),
            input: IssueCreditNoteInput::class,
            denormalizationContext: [
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
            ],
            // The invoice is locked and re-read inside the issuing transaction, never by a provider before it opens.
            read: false,
            processor: IssueCreditNoteProcessor::class,
            // §4.2's cell is `unique:credit_note_idem_unique` (C0): the header's hash IS the indexed value. Owner ruling
            // Q-F: `credit_note.issue` is held by `finance` and `owner` — never `admin` (A2.1b, PC-14) or `sales_rep`.
            extraProperties: [
                IdempotencyKeyGuard::OPERATION_FLAG => true,
                PermissionGate::OPERATION_PERMISSION => 'credit_note.issue',
                // F-1: a denial here names the invoice its path's {id} addresses, not a credit note.
                PermissionGate::OPERATION_DENIAL_SUBJECT => ['invoice', 'id'],
            ],
        ),
        // Sales step 7 C1b (owner ruling Q-A = B′; ADR-008 A2.1c): a credit for goods kept damaged or not received, no return, on
        // its OWN code so E-1 covers it mechanically. §4.2 names its resource `AdjustmentCreditNote` (`Issue / CreditNote`
        // is taken), and its row IS a credit note: the `CreditNote` shape, `Location` → `GetCreditNote` (no `Get` of its own).
        new Post(
            uriTemplate: '/invoices/{id}/credit-note-adjustments',
            uriVariables: [
                'id' => new Link(fromClass: InvoiceResource::class, identifiers: ['id'], parameterName: 'id'),
            ],
            name: 'IssueAdjustmentCreditNote',
            status: 201,
            openapi: new OpenApiOperation(operationId: 'IssueAdjustmentCreditNote'),
            input: IssueAdjustmentCreditNoteInput::class,
            denormalizationContext: [
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
            ],
            read: false,
            processor: IssueAdjustmentCreditNoteProcessor::class,
            // Shares `unique:credit_note_idem_unique` with `IssueCreditNote`; each digest binds its own operation name.
            // `credit_note.adjust`: owner-held among the default roles, on SoD-3's controlled half (A2.1c).
            extraProperties: [
                IdempotencyKeyGuard::OPERATION_FLAG => true,
                PermissionGate::OPERATION_PERMISSION => 'credit_note.adjust',
                PermissionGate::OPERATION_DENIAL_SUBJECT => ['invoice', 'id'],
            ],
        ),
    ],
)]
final class CreditNoteResource
{
    /**
     * @param list<CreditNoteLineView> $lines
     */
    public function __construct(
        public string $id,
        #[SerializedName('credit_note_number')]
        public string $creditNoteNumber,
        public string $reason,
        public InvoiceNumberRef $invoice,
        /** The return credited; null for an adjustment (C1b). A PC-15 reference. */
        #[SerializedName('sales_return')]
        public ?SalesReturnNumberRef $salesReturn,
        #[SerializedName('currency_code')]
        public string $currencyCode,
        #[SerializedName('total_amount')]
        public string $totalAmount,
        #[SerializedName('issued_at')]
        public string $issuedAt,
        /** PC-11 (4a): who issued it — SoD-5's evidence, not enforcement. */
        #[SerializedName('issued_by')]
        public SalesActorRef $issuedBy,
        public array $lines,
    ) {
    }

    public static function fromView(CreditNoteView $view): self
    {
        return new self($view->id, $view->creditNoteNumber, $view->reason, $view->invoice, $view->salesReturn,
            $view->currencyCode, $view->totalAmount, $view->issuedAt, $view->issuedBy, $view->lines);
    }
}

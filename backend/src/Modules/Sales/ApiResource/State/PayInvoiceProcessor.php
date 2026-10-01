<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Sales\ApiResource\InvoiceResource;
use App\Modules\Sales\ApiResource\PayInvoiceInput;
use App\Modules\Sales\Application\InvoicePayment;

/**
 * `PayInvoice` (sales step 6, C5). The body's one field is handed to the writer as the string it arrived as, so the
 * Application layer needs no `ApiResource` input type (the `IssueInvoice` arrangement: the writer returns an
 * Application view and this class maps it).
 *
 * @implements ProcessorInterface<PayInvoiceInput, InvoiceResource>
 */
final readonly class PayInvoiceProcessor implements ProcessorInterface
{
    public function __construct(private InvoicePayment $payment)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): InvoiceResource
    {
        \assert($data instanceof PayInvoiceInput);

        return InvoiceResource::fromView($this->payment->pay((string) ($uriVariables['id'] ?? ''), $data->paidAt, $data->acknowledgedReturnIds));
    }
}

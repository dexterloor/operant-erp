<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Sales\ApiResource\InvoiceResource;
use App\Modules\Sales\ApiResource\ReverseInvoicePaymentInput;
use App\Modules\Sales\Application\InvoicePaymentReversal;

/**
 * `ReverseInvoicePayment` (sales step 6, C6). `PayInvoiceProcessor`'s arrangement: the one field is handed over as the
 * string it arrived as, and the writer returns an Application view this class maps.
 *
 * @implements ProcessorInterface<ReverseInvoicePaymentInput, InvoiceResource>
 */
final readonly class ReverseInvoicePaymentProcessor implements ProcessorInterface
{
    public function __construct(private InvoicePaymentReversal $reversal)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): InvoiceResource
    {
        \assert($data instanceof ReverseInvoicePaymentInput);

        return InvoiceResource::fromView($this->reversal->reverse((string) ($uriVariables['id'] ?? ''), $data->reason));
    }
}

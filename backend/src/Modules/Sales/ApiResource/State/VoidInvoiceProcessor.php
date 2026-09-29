<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Sales\ApiResource\InvoiceResource;
use App\Modules\Sales\ApiResource\VoidInvoiceInput;
use App\Modules\Sales\Application\InvoiceVoiding;

/**
 * `VoidInvoice` (sales step 6, C7). `ReverseInvoicePaymentProcessor`'s arrangement: the one field is handed over as the
 * string it arrived as, and the writer returns an Application view this class maps.
 *
 * @implements ProcessorInterface<VoidInvoiceInput, InvoiceResource>
 */
final readonly class VoidInvoiceProcessor implements ProcessorInterface
{
    public function __construct(private InvoiceVoiding $voiding)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): InvoiceResource
    {
        \assert($data instanceof VoidInvoiceInput);

        return InvoiceResource::fromView($this->voiding->void((string) ($uriVariables['id'] ?? ''), $data->reason));
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Sales\ApiResource\InvoiceResource;
use App\Modules\Sales\Application\InvoiceIssuer;

/**
 * `IssueInvoice`. The path's fulfilment is the whole request: the body is refused before this runs
 * (`BodylessWriteRefusal`), and the `Idempotency-Key` header is demanded by API-14 but not read — a repeat is
 * refused by `invoice_tenant_fulfilment_not_void`, never replayed.
 *
 * @implements ProcessorInterface<null, InvoiceResource>
 */
final readonly class IssueInvoiceProcessor implements ProcessorInterface
{
    public function __construct(private InvoiceIssuer $issuer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): InvoiceResource
    {
        return InvoiceResource::fromView($this->issuer->issue((string) ($uriVariables['id'] ?? '')));
    }
}

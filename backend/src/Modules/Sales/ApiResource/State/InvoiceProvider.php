<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Sales\ApiResource\InvoiceResource;
use App\Modules\Sales\Application\InvoiceReader;
use App\Platform\Http\Problem\ApiProblem;

/**
 * `GetInvoice`. An invoice this caller cannot see — another workspace's, or none — is not-found.
 *
 * @implements ProviderInterface<InvoiceResource>
 */
final readonly class InvoiceProvider implements ProviderInterface
{
    public function __construct(private InvoiceReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): InvoiceResource
    {
        $view = $this->reader->get((string) ($uriVariables['id'] ?? '')) ?? throw ApiProblem::notFound();

        return InvoiceResource::fromView($view);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Sales\ApiResource\SalesOrderLineResource;
use App\Modules\Sales\ApiResource\UpdateSalesOrderLineInput;
use App\Modules\Sales\Application\SalesOrderWriter;

/**
 * `UpdateSalesOrderLine` — API-21 branch 2: 200 with the updated resource.
 *
 * @implements ProcessorInterface<UpdateSalesOrderLineInput, SalesOrderLineResource>
 */
final readonly class UpdateSalesOrderLineProcessor implements ProcessorInterface
{
    public function __construct(private SalesOrderWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SalesOrderLineResource
    {
        \assert($data instanceof UpdateSalesOrderLineInput);

        return $this->writer->updateLine(
            (string) ($uriVariables['id'] ?? ''),
            (string) ($uriVariables['lineId'] ?? ''),
            $data,
        );
    }
}

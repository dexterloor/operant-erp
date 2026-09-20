<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Sales\ApiResource\SalesOrderResource;
use App\Modules\Sales\ApiResource\UpdateSalesOrderInput;
use App\Modules\Sales\Application\SalesOrderWriter;

/**
 * `UpdateSalesOrder` — API-21 branch 2: 200 with the updated resource.
 *
 * @implements ProcessorInterface<UpdateSalesOrderInput, SalesOrderResource>
 */
final readonly class UpdateSalesOrderProcessor implements ProcessorInterface
{
    public function __construct(private SalesOrderWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SalesOrderResource
    {
        \assert($data instanceof UpdateSalesOrderInput);

        return $this->writer->update((string) ($uriVariables['id'] ?? ''), $data);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Sales\ApiResource\SalesOrderResource;
use App\Modules\Sales\Application\SalesOrderWriter;

/**
 * `RestoreSalesOrder` (order restores C2, 2026-10-21) — API-12a / ST-9. The permission is the one that archives
 * (`sales_order.delete`, §4.2), and the response is API-21 branch 5: 200 with the order in its **new** state, exactly
 * `GetSalesOrder`'s body (`RestoreCustomerProcessor`'s shape). Everything else is `SalesOrderWriter::restore()`.
 *
 * @implements ProcessorInterface<mixed, SalesOrderResource>
 */
final readonly class RestoreSalesOrderProcessor implements ProcessorInterface
{
    public function __construct(private SalesOrderWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SalesOrderResource
    {
        return $this->writer->restore((string) ($uriVariables['id'] ?? ''));
    }
}

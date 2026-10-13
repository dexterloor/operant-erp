<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Suppliers\ApiResource\SupplierResource;
use App\Modules\Suppliers\Application\SupplierWriter;

/**
 * `RestoreSupplier` (C5 C2a) — API-12a / ST-9. The permission is the one that archives (`supplier.delete`, §4.2), and the
 * response is API-21 branch 5: 200 with the supplier in its **new** state (`RestoreItemProcessor`'s shape).
 *
 * @implements ProcessorInterface<mixed, SupplierResource>
 */
final readonly class RestoreSupplierProcessor implements ProcessorInterface
{
    public function __construct(private SupplierWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SupplierResource
    {
        return SupplierResource::fromView($this->writer->restore((string) ($uriVariables['id'] ?? '')));
    }
}

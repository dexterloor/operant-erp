<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Inventory\ApiResource\ItemResource;
use App\Modules\Inventory\ApiResource\UpdateItemInput;
use App\Modules\Inventory\Application\ItemWriter;

/**
 * `UpdateItem` — API-21 branch 2: 200 with the updated resource.
 *
 * @implements ProcessorInterface<UpdateItemInput, ItemResource>
 */
final readonly class UpdateItemProcessor implements ProcessorInterface
{
    public function __construct(private ItemWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ItemResource
    {
        \assert($data instanceof UpdateItemInput);

        return $this->writer->update((string) ($uriVariables['id'] ?? ''), $data);
    }
}

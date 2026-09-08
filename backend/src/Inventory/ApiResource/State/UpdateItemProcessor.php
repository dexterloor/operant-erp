<?php

declare(strict_types=1);

namespace App\Inventory\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Inventory\ApiResource\ItemResource;
use App\Inventory\ApiResource\UpdateItemInput;
use App\Inventory\Application\ItemWriter;

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

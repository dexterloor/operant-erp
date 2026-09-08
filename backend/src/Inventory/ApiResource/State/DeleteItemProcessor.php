<?php

declare(strict_types=1);

namespace App\Inventory\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Inventory\Application\ItemWriter;

/**
 * `DeleteItem` — API-12: `inventory_item` is `SchemaManifest::SOFT`, so this
 * sets `deleted_at`. API-21 branch 3: **204, and no body** -- a soft delete's
 * confirmation is the absence of an error, and §5.2a gives the archived row its
 * own read path.
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class DeleteItemProcessor implements ProcessorInterface
{
    public function __construct(private ItemWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->writer->archive((string) ($uriVariables['id'] ?? ''));

        return null;
    }
}

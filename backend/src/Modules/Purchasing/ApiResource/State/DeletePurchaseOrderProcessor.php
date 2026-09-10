<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Purchasing\Application\PurchaseOrderWriter;

/**
 * `DeletePurchaseOrder` — API-12: `purchase_order` is `SchemaManifest::SOFT`, so
 * this sets `deleted_at` and the database's `purchase_order_cascade_deletion`
 * trigger carries it to the lines. API-21 branch 3: **204, and no body**.
 *
 * **L-8 is what makes this operation different from `CancelPurchaseOrder`**, and
 * the writer is where that rule lives: a document is deletable only while
 * `status = 'draft'`, so disposing of a draft and repudiating a placed
 * commitment stay two acts with two permissions.
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class DeletePurchaseOrderProcessor implements ProcessorInterface
{
    public function __construct(private PurchaseOrderWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->writer->archive((string) ($uriVariables['id'] ?? ''));

        return null;
    }
}

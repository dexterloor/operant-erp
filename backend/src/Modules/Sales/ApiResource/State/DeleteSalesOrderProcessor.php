<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Sales\Application\SalesOrderWriter;

/**
 * `DeleteSalesOrder` — API-12: `sales_order` is `SchemaManifest::SOFT`, so this sets `deleted_at` and the database's
 * `sales_order_cascade_deletion` trigger carries it to the live lines. API-21 branch 3: **204, and no body**.
 *
 * **L-8 is what makes this operation different from `CancelSalesOrder`**, and the writer is where that rule lives: a
 * document is deletable only while `status = 'draft'`. `DeletePurchaseOrderProcessor` is the precedent.
 *
 * **Shipped without `RestoreSalesOrder`** (owner ruling Q1, 2026-09-21): E9-3 blocks that restore while
 * `ListSalesOrders` declares no `archived` parameter — `RESIDUAL_GAPS.md` G-8.
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class DeleteSalesOrderProcessor implements ProcessorInterface
{
    public function __construct(private SalesOrderWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->writer->archive((string) ($uriVariables['id'] ?? ''));

        return null;
    }
}

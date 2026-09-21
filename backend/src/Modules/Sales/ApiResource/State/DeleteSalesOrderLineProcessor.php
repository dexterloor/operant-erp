<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Sales\Application\SalesOrderWriter;

/**
 * `DeleteSalesOrderLine` — `sales_order_line` is `SchemaManifest::SOFT`, so this sets `deleted_at`.
 * API-21 branch 3: **204, and no body**.
 *
 * **The soft delete is settled by the table, not by preference.** `deleted_at` is mapped,
 * `sales_order_line_unique` is partial on it, `fulfilment_line_order_line_fk` references
 * `sales_order_line (tenant_id, id)` so a hard `DELETE` would fail or orphan a shipment record, and
 * `has_table_privilege('erp_app','sales_order_line','DELETE')` is **false** — measured in this commit,
 * not inherited. A real `DELETE` would need a migration granting it with its caller (ADR-002 A4.1/A4.4),
 * a `SchemaManifest` `grants` edit and a `GrantFootprintTest` move. **None of that is owed**: the writer
 * issues an `UPDATE`, which is inside the footprint `erp_app` already holds.
 *
 * ⚠ **Unlike the purchase twin, a `RestoreSalesOrderLine` DOES ship**, in the next commit of this same
 * slice — so the sentence this class would otherwise have copied (*"shipping the child restore alone
 * would leave a line restorable inside an order that is not"*) is answered rather than repeated. It is
 * answered by the guards, not by the absence of the operation: `refuseIfArchived($order)` and
 * `refuseUnlessDraft($order)` run in front of the restore, so a cascade-archived line cannot be restored
 * at all — its order is archived and the first guard refuses. The only restorable line is one deleted
 * individually while its order was a live draft, which is coherent by construction.
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class DeleteSalesOrderLineProcessor implements ProcessorInterface
{
    public function __construct(private SalesOrderWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->writer->archiveLine(
            (string) ($uriVariables['id'] ?? ''),
            (string) ($uriVariables['lineId'] ?? ''),
        );

        return null;
    }
}

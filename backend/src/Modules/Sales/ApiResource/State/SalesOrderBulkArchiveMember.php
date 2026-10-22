<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use App\Modules\Sales\ApiResource\SalesOrderResource;
use App\Modules\Sales\Application\SalesOrderWriter;
use App\Platform\Http\Bulk\BulkArchiveMember;

/**
 * **`sales_order` in `CreateBulkArchival`** (G5-C2, 2026-10-22; design §5.2, D-12): each member runs exactly what `DeleteSalesOrder`'s
 * processor runs, {@see SalesOrderWriter::archive()} — one copy of every refusal rule. Its refusals: `sales_order.is_archived` and draft-only (the database's `sales_order_archived_only_while_draft` behind it), under `SalesOrderRowLock`. Lock order: ascending id (§5.3).
 */
final readonly class SalesOrderBulkArchiveMember implements BulkArchiveMember
{
    public function __construct(private SalesOrderWriter $writer)
    {
    }

    public function resource(): string
    {
        return 'sales_order';
    }

    public function deleteResourceClass(): string
    {
        return SalesOrderResource::class;
    }

    public function deleteOperationName(): string
    {
        return 'DeleteSalesOrder';
    }

    public function lockOrder(array $ids): array
    {
        return $ids;
    }

    public function archive(string $id): void
    {
        $this->writer->archive($id);
    }
}

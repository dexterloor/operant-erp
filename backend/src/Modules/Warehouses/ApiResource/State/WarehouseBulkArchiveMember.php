<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\ApiResource\State;

use App\Modules\Warehouses\ApiResource\WarehouseResource;
use App\Modules\Warehouses\Application\WarehouseWriter;
use App\Platform\Http\Bulk\BulkArchiveMember;

/**
 * **`warehouse` in `CreateBulkArchival`** (G5-C2, 2026-10-22; design §5.2, D-12): each member runs exactly what `DeleteWarehouse`'s
 * processor runs, {@see WarehouseWriter::delete()} — one copy of every refusal rule. Its refusals: `warehouse.is_archived`, `.has_open_purchase_orders`, `.has_open_sales_orders`, `.holds_stock` (any location, live or archived); its live locations are LEFT LIVE (owner Q-2 (a)), because the single delete does not touch them. Lock order: ascending id (§5.3).
 */
final readonly class WarehouseBulkArchiveMember implements BulkArchiveMember
{
    public function __construct(private WarehouseWriter $writer)
    {
    }

    public function resource(): string
    {
        return 'warehouse';
    }

    public function deleteResourceClass(): string
    {
        return WarehouseResource::class;
    }

    public function deleteOperationName(): string
    {
        return 'DeleteWarehouse';
    }

    public function lockOrder(array $ids): array
    {
        return $ids;
    }

    public function archive(string $id): void
    {
        $this->writer->delete($id);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\ApiResource\State;

use App\Modules\Warehouses\ApiResource\StorageLocationResource;
use App\Modules\Warehouses\Application\StorageLocationWriter;
use App\Platform\Http\Bulk\BulkArchiveMember;
use App\Platform\Http\Problem\ApiProblem;

/**
 * **`storage_location` in `CreateBulkArchival`** (G5-C2, 2026-10-22; design §5.2, D-12): each member runs exactly what
 * `DeleteStorageLocation`'s processor runs, {@see StorageLocationWriter::delete()} with the location's own warehouse as
 * the path's first value — one copy of every refusal rule: `warehouse.is_archived` (a location of an archived warehouse),
 * `storage_location.is_archived` and `storage_location.holds_stock` (any stock, any kind, quarantine included: archiving
 * stocked quarantine would hide held stock from `StockAdjuster`'s held read — the G-44 bypass G-50 names).
 *
 * Lock order `(warehouse_id, id)` ({@see StorageLocationWriter::bulkLockOrder()}, §5.3), so each member's warehouse
 * share-lock is taken in ascending parent order.
 */
final readonly class StorageLocationBulkArchiveMember implements BulkArchiveMember
{
    public function __construct(private StorageLocationWriter $writer)
    {
    }

    public function resource(): string
    {
        return 'storage_location';
    }

    public function deleteResourceClass(): string
    {
        return StorageLocationResource::class;
    }

    public function deleteOperationName(): string
    {
        return 'DeleteStorageLocation';
    }

    public function lockOrder(array $ids): array
    {
        return $this->writer->bulkLockOrder($ids);
    }

    public function archive(string $id): void
    {
        $warehouse = $this->writer->warehouseOf($id) ?? throw ApiProblem::notFound();

        $this->writer->delete($warehouse, $id);
    }
}

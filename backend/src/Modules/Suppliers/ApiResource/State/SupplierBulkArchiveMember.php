<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\ApiResource\State;

use App\Modules\Suppliers\ApiResource\SupplierResource;
use App\Modules\Suppliers\Application\SupplierWriter;
use App\Platform\Http\Bulk\BulkArchiveMember;

/**
 * **`supplier` in `CreateBulkArchival`** (G5-C2, 2026-10-22; design §5.2, D-12): each member runs exactly what `DeleteSupplier`'s
 * processor runs, {@see SupplierWriter::delete()} — one copy of every refusal rule. Its refusals: `supplier.is_archived`, `supplier.has_open_purchase_orders`. Lock order: ascending id (§5.3).
 */
final readonly class SupplierBulkArchiveMember implements BulkArchiveMember
{
    public function __construct(private SupplierWriter $writer)
    {
    }

    public function resource(): string
    {
        return 'supplier';
    }

    public function deleteResourceClass(): string
    {
        return SupplierResource::class;
    }

    public function deleteOperationName(): string
    {
        return 'DeleteSupplier';
    }

    public function lockOrder(array $ids): array
    {
        return $ids;
    }

    public function archive(string $id): void
    {
        $this->writer->delete($id);
    }

    public function restoreOperationName(): string
    {
        return 'RestoreSupplier';
    }

    /** G5-C3 (2026-10-23): exactly what `RestoreSupplier`'s processor runs — one copy of every restore rule and collision translation. */
    public function restore(string $id): void
    {
        $this->writer->restore($id);
    }
}

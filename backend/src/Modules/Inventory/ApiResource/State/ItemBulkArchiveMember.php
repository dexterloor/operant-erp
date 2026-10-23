<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ApiResource\State;

use App\Modules\Inventory\ApiResource\ItemResource;
use App\Modules\Inventory\Application\ItemWriter;
use App\Platform\Http\Bulk\BulkArchiveMember;

/**
 * **`inventory_item` in `CreateBulkArchival`** (G5-C2, 2026-10-22; design §5.2, D-12/D-13). The ONE member that is not the
 * single delete's method verbatim: {@see ItemWriter::archiveUnlessStocked()} is `DeleteItem`'s {@see ItemWriter::archive()}
 * behind the bulk-only stock refusal (G-51: the single delete still archives a stocked item; bulk must not, or a stocked
 * item's stock blocks its locations and warehouse). Lock order: ascending id (§5.3).
 */
final readonly class ItemBulkArchiveMember implements BulkArchiveMember
{
    public function __construct(private ItemWriter $writer)
    {
    }

    public function resource(): string
    {
        return 'inventory_item';
    }

    public function deleteResourceClass(): string
    {
        return ItemResource::class;
    }

    public function deleteOperationName(): string
    {
        return 'DeleteItem';
    }

    public function lockOrder(array $ids): array
    {
        return $ids;
    }

    public function archive(string $id): void
    {
        $this->writer->archiveUnlessStocked($id);
    }

    public function restoreOperationName(): string
    {
        return 'RestoreItem';
    }

    /** G5-C3 (2026-10-23): exactly what `RestoreItem`'s processor runs — one copy of every restore rule and collision translation. */
    public function restore(string $id): void
    {
        $this->writer->restore($id);
    }
}

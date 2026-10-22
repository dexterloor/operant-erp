<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource\State;

use App\Modules\Purchasing\ApiResource\PurchaseOrderResource;
use App\Modules\Purchasing\Application\PurchaseOrderWriter;
use App\Platform\Http\Bulk\BulkArchiveMember;

/**
 * **`purchase_order` in `CreateBulkArchival`** (G5-C2, 2026-10-22; design §5.2, D-12): each member runs exactly what `DeletePurchaseOrder`'s
 * processor runs, {@see PurchaseOrderWriter::archive()} — one copy of every refusal rule. Its refusals: `purchase_order.is_archived` and draft-only (the database's `purchase_order_archived_only_while_draft` behind it), under `PurchaseOrderRowLock`. Lock order: ascending id (§5.3).
 */
final readonly class PurchaseOrderBulkArchiveMember implements BulkArchiveMember
{
    public function __construct(private PurchaseOrderWriter $writer)
    {
    }

    public function resource(): string
    {
        return 'purchase_order';
    }

    public function deleteResourceClass(): string
    {
        return PurchaseOrderResource::class;
    }

    public function deleteOperationName(): string
    {
        return 'DeletePurchaseOrder';
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

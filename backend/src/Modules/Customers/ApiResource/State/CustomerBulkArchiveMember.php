<?php

declare(strict_types=1);

namespace App\Modules\Customers\ApiResource\State;

use App\Modules\Customers\ApiResource\CustomerResource;
use App\Modules\Customers\Application\CustomerWriter;
use App\Modules\Customers\Application\SharedNameSwitch;
use App\Platform\Http\Bulk\BulkArchiveMember;

/**
 * **`customer` in `CreateBulkArchival`** (G5-C2, 2026-10-22; design §5.2, D-12): each member runs exactly what `DeleteCustomer`'s
 * processor runs, {@see CustomerWriter::delete()} — one copy of every refusal rule. Its refusals: `customer.is_archived` and the four open-work legs (`has_open_sales_orders`, `has_unbilled_shipments`, `has_unpaid_invoices`, `is_owed_a_credit`). Lock order: ascending id (§5.3).
 */
final readonly class CustomerBulkArchiveMember implements BulkArchiveMember
{
    public function __construct(private CustomerWriter $writer)
    {
    }

    public function resource(): string
    {
        return 'customer';
    }

    public function deleteResourceClass(): string
    {
        return CustomerResource::class;
    }

    public function deleteOperationName(): string
    {
        return 'DeleteCustomer';
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
        return 'RestoreCustomer';
    }

    /**
     * G5-C3 (2026-10-23): exactly what `RestoreCustomer`'s processor runs — one copy of every restore rule and collision
     * translation. **BK-47: with the shared-name switch OFF, always** (`docs/session13/bk47-design.md` §2.4, owner notice
     * N-C): the bulk body is `{resource, ids}` for every resource, and a person restoring in bulk gets counts only, never a
     * dialog. So a customer whose name another customer (live or archived) has is not applied, and two archived namesakes
     * can never both be restored in bulk; each is restored on its own with `?allow_shared_name=true`. The first screen
     * that bulk-restores customers must say so (`RESIDUAL_GAPS.md` G-48's bulk-screen trigger).
     */
    public function restore(string $id): void
    {
        $this->writer->restore($id, SharedNameSwitch::off());
    }
}

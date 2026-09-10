<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `UpdatePurchaseOrder`'s accepted request fields, exhaustively (§4.2):
 * `supplier_id`, `warehouse_id`, `expected_at`.
 *
 * **There is no `id`** — API-5 removes `PUT`, so `{id}` in the path names a row
 * that already exists (§3.1 case 2) and no operation creates a row at a
 * client-chosen path.
 *
 * **There is no `currency_code` and no `status`, and neither is an oversight.**
 * `currency_code` is in `CreatePurchaseOrder`'s cell and not in this one: a
 * placed commitment's currency is not an edit. `status` is in no cell at all —
 * §8.3's matrix says a status moves by a **method**, and the two methods this
 * document declares are behind `/approval` and `/cancellation`, which is what
 * stops a `PATCH` re-opening a cancelled order.
 *
 * Properties are declared **uninitialised**; see `SubmittedFields`.
 */
final class UpdatePurchaseOrderInput
{
    use SubmittedFields;

    #[SerializedName('supplier_id')]
    public ?string $supplierId;

    #[SerializedName('warehouse_id')]
    public ?string $warehouseId;

    #[SerializedName('expected_at')]
    public ?string $expectedAt;
}

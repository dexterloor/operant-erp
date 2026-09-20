<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `UpdateSalesOrder`'s accepted request fields, exhaustively (§4.2):
 * `customer_id`, `warehouse_id`, `ship_to_address_id`.
 *
 * **There is no `id`** — API-5 removes `PUT`, so `{id}` in the path names a row
 * that already exists (§3.1 case 2) and no operation creates a row at a
 * client-chosen path.
 *
 * **There is no `currency_code` and no `status`.** `currency_code` is in
 * `CreateSalesOrder`'s cell and not in this one: a document's currency is not an
 * edit. `status` is in no cell at all — §8.3's matrix says a status moves by a
 * **method**, and the two this contract declares sit behind `/confirmation` and
 * `/cancellation`, which is exactly what stops a `PATCH` re-opening a cancelled
 * order.
 *
 * **`ship_to_address_id` is accepted but is not a column.** `sales_order` stores
 * no address id at all (O-1/O-2, KEEP-AND-EXCEPT): the four `ship_to_*` columns
 * are a **snapshot** taken from `customer_address` at create, and this operation
 * re-takes it while the order is draft. That is O-2's ruling in full, and the
 * freeze the same ruling attached is §8.3a's `sales_order.is_editable_only_while_draft`
 * row, not a sentence.
 *
 * ===========================================================================
 * Why `submitted()` is written here and not taken from a trait — MEASURED
 * ===========================================================================
 * A `PATCH` has to tell three cases apart — the field was absent, the field was sent as `null`, the
 * field was sent with a value — and a property with a `= null` default collapses the first two. The
 * properties below are therefore declared **uninitialised**, and `\ReflectionProperty::isInitialized()`
 * separates them.
 *
 * `Modules\Purchasing\ApiResource\SubmittedFields` is the same four lines as a trait, and this class
 * shipped for about an hour as a per-module copy of it. **That copy broke a shipped check, and the
 * failure is worth carrying:**
 *
 *     LogicException: Two classes in src/ share the short name in SubmittedFields::submitted;
 *                     key the scan by FQCN.
 *
 * `PurchaseOrderRowLockTest`'s writer scan (and the write-ownership scan it shares code with) keys
 * every class in `src/` by its **short name** and refuses outright when two collide. So *"copy the
 * trait per module"* — which the design chose, and which the tree's own `Modules\Purchasing` /
 * `Modules\Inventory` split looks like a precedent for — is **not** available for a trait of this name.
 *
 * What is available, and what this class does, is `Modules\Inventory\ApiResource\UpdateItemInput`'s
 * shipped precedent: the method inline, on the DTO. It adds no cross-module deptrac edge and collides
 * with nothing.
 *
 * ⚠ **This is now the third hand-written copy of four lines of reflection, and the argument for
 * promoting it to `SharedKernel` is stronger than the design thought** — the design declined the
 * promotion on the grounds that a per-module copy is free, and it is not. The promotion still edits
 * four shipped DTOs in a commit whose subject is something else, so it is **named here with an owner
 * rather than done here**.
 */
final class UpdateSalesOrderInput
{
    #[SerializedName('customer_id')]
    public ?string $customerId;

    #[SerializedName('warehouse_id')]
    public ?string $warehouseId;

    #[SerializedName('ship_to_address_id')]
    public ?string $shipToAddressId;

    /**
     * The fields this request actually carried, keyed by property name.
     *
     * @return array<string, string|null>
     */
    public function submitted(): array
    {
        $submitted = [];

        foreach ((new \ReflectionClass($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isInitialized($this)) {
                /** @var string|null $value */
                $value = $property->getValue($this);
                $submitted[$property->getName()] = $value;
            }
        }

        return $submitted;
    }
}

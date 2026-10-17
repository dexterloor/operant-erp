<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Contract;

/**
 * **The stock count — `CreateStockAdjustment`'s one writer** (stock count C1, 2026-10-16;
 * `docs/session13/stock-adjustment-design.md` §8.5 mechanism (a), `SEC` CONFIRMED in `stock-adjustment-sec.md` §7).
 *
 * `SECURITY_MODEL.md` E-26 binds each `stock_movement.reason` to one writer reached through one `Application\Contract`
 * whose one `src/` consumer is the operation that authorises it: here `adjustment` ← `StockAdjuster` ← this interface ←
 * `CreateStockAdjustmentProcessor` (`CreateStockAdjustment`, `stock_movement.adjust`). `WriteOwnershipTest` holds both
 * halves (`STOCK_WRITE_CONTRACTS`, `STOCK_MOVEMENT_REASONS`).
 *
 * **Count-to** (owner Q2): the caller says what the bin holds, quoting the `version` it read on `ListStockLevels`
 * (`null` for a bin that showed no row for the item); the writer sets the level and records the signed difference as one
 * `adjustment` movement, with an `audit_log` row naming the actor (E-25 (a)).
 *
 * **Unlike its three siblings it OWNS its transaction**, as an operation's writer does (`CustomerAddressWriter`'s shape):
 * a 23505 on `stock_movement_idem_unique` aborts the transaction and can only be translated outside it. `TenantContext`
 * refuses to run inside a transaction it did not open, so it cannot be folded into a caller's. That is why it carries
 * no `NoCallerTransaction` refusal (design §8.5's "like its siblings" does not fit a transaction owner — recorded as a
 * deviation in the build progress note).
 */
interface AdjustStock
{
    /**
     * @param array<string, mixed> $submitted wire name => value, exactly the fields the body carried
     *
     * @return string the id of the movement recorded — or, on a replay, of the one recorded by the first request
     */
    public function adjust(array $submitted, string $idempotencyKey): string;
}

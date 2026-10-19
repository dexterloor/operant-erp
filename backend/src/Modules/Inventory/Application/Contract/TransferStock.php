<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Contract;

/**
 * **The stock transfer — `CreateStockTransfer`'s one writer** (stock transfer C1, 2026-10-19;
 * `docs/session13/stock-transfer-design.md` Revision 2 §C1, `SEC` `stock-transfer-sec.md` §1–§7).
 *
 * `SECURITY_MODEL.md` E-26 binds `transfer_out` and `transfer_in` to one writer reached through this interface, whose one
 * `src/` consumer is `CreateStockTransferProcessor` (`CreateStockTransfer`, `stock_movement.adjust`): `transfer_out`,
 * `transfer_in` ← `StockTransferrer` ← this interface ← `CreateStockTransfer`. `WriteOwnershipTest` holds both halves
 * (`STOCK_WRITE_CONTRACTS`, `STOCK_MOVEMENT_REASONS`).
 *
 * One request moves a quantity of one item from one live bin to another (same or different warehouse — owner Q-T1), as one
 * instant move: an equal-and-opposite pair of movements, each naming the other, and both levels changed. Like
 * {@see AdjustStock} it OWNS its transaction (a 23505 on the retry key is translated inside it, under a savepoint), so it
 * carries no `NoCallerTransaction` refusal.
 */
interface TransferStock
{
    /**
     * @param array<string, mixed> $submitted wire name => value, exactly the fields the body carried
     *
     * @return string the id of the out leg recorded — or, on a replay, of the one recorded by the first request
     */
    public function transfer(array $submitted, string $idempotencyKey): string;
}

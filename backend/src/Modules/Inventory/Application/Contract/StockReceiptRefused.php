<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Contract;

/**
 * Inventory refused to take a receipt in — a fact about the receipt, not a bug
 * in the calling code.
 *
 * ---------------------------------------------------------------------------
 * The caller translates it; nothing translates it for them
 * ---------------------------------------------------------------------------
 * A `\DomainException` and not an `ApiProblem`, for the reason
 * `Platform\Domain\IllegalTransition` gives: the refusal is Inventory's, the
 * mapping onto §5.2's `business-rule-violated` / `validation-failed` is the
 * caller's API. And unlike `IllegalTransition`, `FrameworkProblemTranslator`
 * has no arm for this class **on purpose**: only the caller knows where the
 * fault sits in ITS request — `/lines/2/storage_location_id` is a pointer into
 * a goods-receipt body Inventory has never seen. `lineIndex()` is the index
 * into `StockReceipt::$lines` the caller built, which it maps back to its own
 * input. Left untranslated it surfaces as a 500, which is loud.
 *
 * `rule()` is `<table>.<rule>`, the project's convention for §5.2's `rule`
 * member (`inventory_item.is_archived`, `purchase_order.has_a_live_line`).
 */
final class StockReceiptRefused extends \DomainException
{
    private function __construct(
        private readonly string $rule,
        private readonly ?int $lineIndex,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function noLines(): self
    {
        return new self('stock_movement.receipt_has_a_line', null, 'A goods receipt must take in at least one line.');
    }

    /**
     * **The quantity is echoed verbatim, quoted, and is deliberately NOT rendered
     * under QR-2** (`docs/ux/STATES.md` §QR; reviewed 2026-09-18 with CD-1 step 2).
     *
     * QR-5 decides it by provenance: *could this string have come from the request
     * body unaltered?* Here it always could. `StockReceipt` calls this only when
     * the value **failed** its `numeric(19,6)` check, and the one production
     * construction of a `StockReceipt` — `GoodsReceiptWriter::create()` — applies
     * that same regex and the same positivity test to the request body first
     * (`GoodsReceiptWriter:539`) and only then normalises with `bcadd(…, 6)`
     * (`:558`). **So no value that reached `:558` can arrive here**, and everything
     * that does arrive is a raw string from a caller off the HTTP path. Rendering
     * it would be unsound as well as wrong: `"five"`, `"5\n"` and `"1.0000001"`
     * are not decimals for QR-2 to trim, and `"0.000000"` — which *is* one — would
     * be reported back as `"0"`, hiding what the caller actually sent.
     *
     * Pinned by `ReceiveStockTest::aLineWhoseQuantityIsNotAPositiveReceiptIsRefusedAndNamed`.
     */
    public static function quantityIsNotPositive(int $lineIndex, string $quantity): self
    {
        return new self(
            'stock_movement.quantity_is_positive',
            $lineIndex,
            \sprintf('Line %d: "%s" is not a positive quantity of at most 13 digits and 6 decimal places.', $lineIndex, $quantity),
        );
    }

    public static function binIsNotALiveBinOfTheReceivingWarehouse(int $lineIndex, string $locationId, string $warehouseId): self
    {
        return new self(
            'storage_location.belongs_to_receiving_warehouse',
            $lineIndex,
            \sprintf('Line %d: storage location %s is not a live location of warehouse %s.', $lineIndex, $locationId, $warehouseId),
        );
    }

    public static function goodsReceiptAlreadyPosted(string $goodsReceiptId): self
    {
        return new self(
            'stock_movement.goods_receipt_posted_once',
            null,
            \sprintf('Goods receipt %s has already posted its stock; posting it again would double it.', $goodsReceiptId),
        );
    }

    public static function receivedQuantityDoesNotFit(int $lineIndex, string $itemId, string $locationId): self
    {
        return new self(
            'stock_level.received_quantity_fits',
            $lineIndex,
            \sprintf('Line %d: the quantity received of item %s into location %s, summed over this receipt, '
                .'exceeds 13 digits before the decimal point.', $lineIndex, $itemId, $locationId),
        );
    }

    public static function warehouseIsArchived(string $warehouseId): self
    {
        return new self(
            'warehouse.is_live',
            null,
            \sprintf('Warehouse %s is archived: a closed site cannot take stock in.', $warehouseId),
        );
    }

    public function rule(): string
    {
        return $this->rule;
    }

    /** The index into `StockReceipt::$lines` at fault, or null when the refusal is about the receipt as a whole. */
    public function lineIndex(): ?int
    {
        return $this->lineIndex;
    }
}

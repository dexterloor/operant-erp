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

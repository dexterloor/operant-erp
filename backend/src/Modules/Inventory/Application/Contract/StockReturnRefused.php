<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Contract;

/**
 * `ReturnStock`'s refusals, by rule code. `StockReceiptRefused`'s shape: the caller translates each with its own
 * pointers; nothing here names a request field.
 */
final class StockReturnRefused extends \DomainException
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
        return new self('stock_movement.return_has_a_line', null, 'A sales return must take back at least one line.');
    }

    public static function quantityIsNotPositive(int $lineIndex, string $quantity): self
    {
        return new self(
            'stock_movement.quantity_is_positive',
            $lineIndex,
            \sprintf('Line %d: "%s" is not a positive quantity of at most 13 digits and 6 decimal places.', $lineIndex, $quantity),
        );
    }

    public static function binIsNotALiveBinOfTheReturningWarehouse(int $lineIndex, string $locationId, string $warehouseId): self
    {
        return new self(
            'storage_location.belongs_to_returning_warehouse',
            $lineIndex,
            \sprintf('Line %d: storage location %s is not a live location of warehouse %s.', $lineIndex, $locationId, $warehouseId),
        );
    }

    public static function salesReturnAlreadyPosted(string $salesReturnId): self
    {
        return new self(
            'stock_movement.sales_return_posted_once',
            null,
            \sprintf('Sales return %s has already posted its stock; posting it again would double it.', $salesReturnId),
        );
    }

    public static function returnedQuantityDoesNotFit(int $lineIndex, string $itemId, string $locationId): self
    {
        return new self(
            'stock_level.returned_quantity_fits',
            $lineIndex,
            \sprintf('Line %d: the quantity returned of item %s into location %s, summed over this return, '
                .'exceeds 13 digits before the decimal point.', $lineIndex, $itemId, $locationId),
        );
    }

    /**
     * The line fits, the return's sums fit, but the bin ALREADY holds so much of the item
     * that adding them would take `qty_on_hand` past numeric(19,6). A fact about the bin's state, so
     * the caller answers it as a business rule (409), like a depletion the bin cannot cover. Until
     * 2026-10-16 this was a 22003 and an HTTP 500 (shipped defect, flagged the same day).
     */
    public static function returnedQuantityWouldOverfillTheLevel(int $lineIndex, string $itemId, string $locationId): self
    {
        return new self(
            'stock_level.on_hand_fits',
            $lineIndex,
            \sprintf('Line %d: location %s already holds so much of item %s that the quantity returned '
                .'would exceed 13 digits before the decimal point.', $lineIndex, $locationId, $itemId),
        );
    }

    public static function warehouseIsArchived(string $warehouseId): self
    {
        return new self(
            'warehouse.is_live',
            null,
            \sprintf('Warehouse %s is archived: a closed site cannot take returned goods back.', $warehouseId),
        );
    }

    public function rule(): string
    {
        return $this->rule;
    }

    /** The index into `StockReturn::$lines` at fault, or null when the refusal is about the return as a whole. */
    public function lineIndex(): ?int
    {
        return $this->lineIndex;
    }
}

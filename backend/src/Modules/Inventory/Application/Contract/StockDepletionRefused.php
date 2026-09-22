<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Contract;

/**
 * Inventory refused to take stock out — a fact about the depletion, not a bug
 * in the calling code. `StockReceiptRefused`'s mirror, and untranslated for
 * the same reason: only the caller knows where the fault sits in ITS request.
 * `lineIndex()` / `lineIndexes()` index `StockDepletion::$lines`.
 */
final class StockDepletionRefused extends \DomainException
{
    /**
     * @param list<int> $lineIndexes
     */
    private function __construct(
        private readonly string $rule,
        private readonly array $lineIndexes,
        string $message,
        private readonly ?string $inventoryItemId = null,
        private readonly ?string $storageLocationId = null,
        private readonly ?string $onHand = null,
        private readonly ?string $requested = null,
    ) {
        parent::__construct($message);
    }

    public static function noLines(): self
    {
        return new self('stock_movement.depletion_has_a_line', [], 'A fulfilment must take out at least one line.');
    }

    /** Echoed verbatim, quoted, for `StockReceiptRefused::quantityIsNotPositive()`'s QR-5 reason. */
    public static function quantityIsNotPositive(int $lineIndex, string $quantity): self
    {
        return new self(
            'stock_movement.quantity_is_positive',
            [$lineIndex],
            \sprintf('Line %d: "%s" is not a positive quantity of at most 13 digits and 6 decimal places.', $lineIndex, $quantity),
        );
    }

    public static function fulfilmentAlreadyPosted(string $fulfilmentId): self
    {
        return new self(
            'stock_movement.fulfilment_posted_once',
            [],
            \sprintf('Fulfilment %s has already posted its stock; posting it again would take it out twice.', $fulfilmentId),
        );
    }

    public static function warehouseIsArchived(string $warehouseId): self
    {
        return new self(
            'warehouse.is_live',
            [],
            \sprintf('Warehouse %s is archived: a closed site cannot ship stock.', $warehouseId),
        );
    }

    public static function binIsNotALocationOfTheFulfillingWarehouse(int $lineIndex, string $locationId, string $warehouseId): self
    {
        return new self(
            'storage_location.belongs_to_fulfilling_warehouse',
            [$lineIndex],
            \sprintf('Line %d: storage location %s is not a location of warehouse %s.', $lineIndex, $locationId, $warehouseId),
            storageLocationId: $locationId,
        );
    }

    /** Owner ruling R2 (2026-09-22): only a live, non-quarantine location is pickable. */
    public static function binIsNotPickable(int $lineIndex, string $locationId, bool $archived): self
    {
        return new self(
            'storage_location.is_pickable',
            [$lineIndex],
            \sprintf('Line %d: storage location %s is %s, and stock there cannot be picked.',
                $lineIndex, $locationId, $archived ? 'archived' : 'a quarantine location'),
            storageLocationId: $locationId,
        );
    }

    /**
     * @param list<int> $lineIndexes every line that contributed to this (item, bin)
     * @param string    $onHand      scale-6, what the bin held when this was checked (0 when it holds no level)
     * @param string    $requested   scale-6, the summed quantity asked of it
     */
    public static function onHandDoesNotCoverTheDepletion(array $lineIndexes, string $itemId, string $locationId, string $onHand, string $requested): self
    {
        return new self(
            'stock_level.on_hand_covers_the_depletion',
            $lineIndexes,
            \sprintf('Lines %s: %s of item %s were asked of location %s, which held %s when this was checked.',
                implode(', ', $lineIndexes), $requested, $itemId, $locationId, $onHand),
            $itemId,
            $locationId,
            $onHand,
            $requested,
        );
    }

    public function rule(): string
    {
        return $this->rule;
    }

    /** The first line index at fault, or null when the refusal is about the depletion as a whole. */
    public function lineIndex(): ?int
    {
        return $this->lineIndexes[0] ?? null;
    }

    /** @return list<int> every line index at fault, ascending; empty when the refusal is about the depletion as a whole */
    public function lineIndexes(): array
    {
        return $this->lineIndexes;
    }

    public function inventoryItemId(): ?string
    {
        return $this->inventoryItemId;
    }

    public function storageLocationId(): ?string
    {
        return $this->storageLocationId;
    }

    /** Scale-6; set only for `stock_level.on_hand_covers_the_depletion`. */
    public function onHand(): ?string
    {
        return $this->onHand;
    }

    /** Scale-6; set only for `stock_level.on_hand_covers_the_depletion`. */
    public function requested(): ?string
    {
        return $this->requested;
    }
}

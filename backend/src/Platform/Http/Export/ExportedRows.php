<?php

declare(strict_types=1);

namespace App\Platform\Http\Export;

/**
 * What an {@see ExportSource}'s read returns: at most `cap` hydrated row objects, and whether the view held more.
 *
 * `$overCap` comes from the same statement as the rows (`LIMIT cap + 1`), so "more than the cap" and "these rows" are one
 * snapshot's answer (`g5-g6-bulk-export-design.md` §6.4). An over-cap view exports nothing (409 `export.too_many_rows`).
 */
final readonly class ExportedRows
{
    /**
     * @param list<object> $rows
     */
    public function __construct(
        public array $rows,
        public bool $overCap,
    ) {
        if (!array_is_list($rows)) {
            throw new \LogicException('An export reads rows in the List\'s order, so they are a list.');
        }
    }

    /**
     * The common shape: the reader fetched `cap + 1` rows (or a bounded list read them all), so anything past `cap` means
     * over. The extra row is dropped here, never rendered.
     *
     * @param list<object> $fetched
     */
    public static function capped(array $fetched, int $cap): self
    {
        $over = \count($fetched) > $cap;

        return new self($over ? array_slice($fetched, 0, $cap) : $fetched, $over);
    }
}

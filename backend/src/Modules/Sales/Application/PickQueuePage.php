<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

/**
 * One page of `ListPickQueueEntries`, as `PickQueueReader::list()` reads it. `FulfilmentPage`'s arrangement.
 */
final readonly class PickQueuePage
{
    /**
     * @param list<PickQueueEntryView> $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
        public ?string $previousCursor,
        public bool $tenantHasRows,
    ) {
    }
}

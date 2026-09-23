<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

/**
 * One page of `ListFulfilments`, as `FulfilmentReader::list()` reads it. The provider serializes it as
 * `FulfilmentCollection` (API-22), so the reader returns an Application read model and never an `ApiResource`
 * class — `FulfilmentView`'s arrangement, and no new `Application -> ApiResource` edge.
 */
final readonly class FulfilmentPage
{
    /**
     * @param list<FulfilmentView> $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
        public ?string $previousCursor,
        public bool $tenantHasRows,
    ) {
    }
}

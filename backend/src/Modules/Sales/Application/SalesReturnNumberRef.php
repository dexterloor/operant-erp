<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use Symfony\Component\Serializer\Attribute\SerializedName;

/** A reference to a sales return: `{id, sales_return_number}` (PC-15; design §14 item 25; `SEC` S-4). */
final readonly class SalesReturnNumberRef
{
    public function __construct(
        public string $id,
        #[SerializedName('sales_return_number')]
        public string $salesReturnNumber,
    ) {
    }
}

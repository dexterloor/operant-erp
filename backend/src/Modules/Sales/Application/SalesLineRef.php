<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use Symfony\Component\Serializer\Attribute\SerializedName;

/** API-23b clause (i)'s `sales_order_line = {id, line_number}` (owner ruling Q1(b)), as an `Application` value. */
final readonly class SalesLineRef
{
    public function __construct(
        public string $id,
        #[SerializedName('line_number')]
        public int $lineNumber,
    ) {
    }
}

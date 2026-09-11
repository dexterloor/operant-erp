<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

use Symfony\Component\Serializer\Attribute\SerializedName;

/** A parent order as §4.4.3 names it on an N-8 child: `{id, order_number}`. */
final readonly class OrderReference
{
    public function __construct(
        public string $id,
        #[SerializedName('order_number')]
        public string $orderNumber,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use Symfony\Component\Serializer\Attribute\SerializedName;

/** The parent order's `{id, order_number}` (§4.4.3's GoodsReceipt / Fulfilment row), as an `Application` value. */
final readonly class SalesOrderRef
{
    public function __construct(
        public string $id,
        #[SerializedName('order_number')]
        public string $orderNumber,
    ) {
    }
}

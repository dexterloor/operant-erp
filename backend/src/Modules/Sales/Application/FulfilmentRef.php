<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use Symfony\Component\Serializer\Attribute\SerializedName;

/** The billed shipment's `{id, fulfilment_number}` (§4.4.3's Invoice row, D-A as amended), as an `Application` value. */
final readonly class FulfilmentRef
{
    public function __construct(
        public string $id,
        #[SerializedName('fulfilment_number')]
        public string $fulfilmentNumber,
    ) {
    }
}

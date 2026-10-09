<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

/**
 * One customer address as the address reader and writer return it — scalars only (A-7; final RED M-4). §4.4.3's derived
 * `CustomerAddress` shape: `id`, `kind`, `line`, `city`, `postal_code`, `country_code`. **`line` is the wire name of the
 * column `address_line`** (as `SalesOrder.ship_to_line`); `region` is neither accepted nor served (`sales_order` has no
 * column for it, O-5), so the column's default `''` applies; `customer_id` is implied by the path (API-23).
 */
final readonly class CustomerAddressView
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $line,
        public string $city,
        public string $postalCode,
        public string $countryCode,
    ) {
    }
}

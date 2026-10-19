<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

/**
 * One customer contact as the contact reader and writer return it — scalars only (A-7). §4.4.3's derived
 * `CustomerContact` shape: `id`, `name`, `email`, `phone`, `is_primary` (customers slice 2 C1). `email` and `phone` are
 * nullable (a name-only contact is valid); `customer_id` is implied by the path (API-23). The retry key and digest are
 * never read into a view.
 */
final readonly class CustomerContactView
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $email,
        public ?string $phone,
        public bool $isPrimary,
    ) {
    }
}

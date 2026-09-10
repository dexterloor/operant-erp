<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * API-23b's expansion for `purchase_order.created_by_user_id`, and §4.4.3 names
 * its display field explicitly: `created_by = {id, display_name}`.
 *
 * It is not `NamedReference` because `app_user` has no `name` column — the
 * derivation's first limb finds nothing there and §4.4.3's additions row settles
 * it — and it is not the whole principal because an expansion carries the id and
 * the display field and nothing else. In particular it never carries `email`,
 * which is `app_user.view`'s to disclose and not `purchase_order.view`'s.
 *
 * **This is DSoD-1's input rendered for a person.** F-2 step 8 requires the
 * approval to be *"recorded against this manager by name"*, and E-19's refusal is
 * a comparison against the very id this object carries — so a client that renders
 * *"you raised this order"* is reading the same field the server refused on.
 */
final readonly class ActorReference
{
    public function __construct(
        public string $id,
        #[SerializedName('display_name')]
        public string $displayName,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * API-23b's expansion for `sales_order.created_by_user_id`, and §4.4.3 names its display field
 * explicitly: `created_by = {id, display_name}`.
 *
 * It is not `SalesNamedReference` because `app_user` has no `name` column — the derivation's first limb
 * finds nothing there and §4.4.3's additions row settles it — and it is not the whole principal because
 * an expansion carries the id and the display field and nothing else. In particular it never carries
 * `email`, which is `app_user.view`'s to disclose and not `sales_order.view`'s (PC-15 condition 2).
 *
 * ---------------------------------------------------------------------------
 * ⚠ Why this is a third copy of a two-property DTO and not Purchasing's `ActorReference`
 * ---------------------------------------------------------------------------
 * `ActorReference` lives only in `Modules\Purchasing\ApiResource`, and `deptrac.yaml` does not admit
 * `PurchasingApiResource` into `SalesApiResource`'s allowed list — every Sales→ApiResource edge is
 * enumerated individually. Reaching for it is exactly the edge `make deptrac` exists to refuse.
 * `SalesNamedReference` is the established answer to the same question one field earlier, and this
 * follows it rather than inventing a second answer. The name carries the module prefix for the two
 * reasons that class records and neither is taste: `PurchaseOrderRowLockTest`'s writer scan keys methods
 * by **short class name** and refuses a collision outright, and API Platform names a spec component after
 * the class's short name, so two `ActorReference` classes would be two components competing for
 * `#/components/schemas/ActorReference`.
 *
 * ---------------------------------------------------------------------------
 * ⚠ What this expansion is NOT, and the sentence must not be copied from the purchasing side
 * ---------------------------------------------------------------------------
 * `ActorReference`'s docblock calls itself *"DSoD-1's input rendered for a person"*, and
 * `GoodsReceipt.received_by` is labelled *"evidence for SoD-2"*. **Neither transfers.** No separation of
 * duty runs over `sales_order` at all: `SEC-O-9` verified that this column records *"the creator, not the
 * confirmer"*, ADR-008 names no DSoD rule on any sales document, and `IA.md` IA-O-8 states as a tested
 * claim that *"F-5 has no mirror of this gap — `sales_rep` holds create, edit and confirm … there is no
 * seam"*. **This field serves no SoD control here.** It is disclosed because who raised an order is the
 * order's own business state (PC-11 (4a)), and because `AuditEvent::ALL` has no `sales_order.*` member —
 * so with the field withheld, *"who raised this order"* is answerable only by opening the database.
 */
final readonly class SalesActorReference
{
    public function __construct(
        public string $id,
        #[SerializedName('display_name')]
        public string $displayName,
    ) {
    }
}

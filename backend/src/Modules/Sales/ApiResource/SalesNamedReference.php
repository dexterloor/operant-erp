<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

/**
 * API-23b — a foreign-key reference is returned expanded as `{id, <display field>}` and never as a bare
 * uuid.
 *
 * The display field is **derived, not chosen per resource**: the referenced table's `name` where it has
 * one, else `code`, else `sku`, else its document number. Both references this module returns through this
 * class resolve to `name` — `customer.name` and `warehouse.name` are both present in
 * `Version20260906000100` — so one class serves both.
 *
 * **It is a third copy of a two-property DTO, and that is a boundary rather than taste.** `deptrac.yaml`
 * does not admit `PurchasingApiResource` or `InventoryApiResource` into `SalesApiResource`'s allowed list,
 * so reaching for Purchasing's identical `NamedReference` is exactly the edge `make deptrac` exists to
 * refuse. Purchasing's own docblock states that cost for the first pair — *"the generated spec therefore
 * carries two components with different names and the same two members"* — and this states it for the
 * second.
 *
 * ---------------------------------------------------------------------------
 * ⚠ Why the name is `SalesNamedReference` and not `NamedReference`
 * ---------------------------------------------------------------------------
 * **It was written as `NamedReference` first and two independent things refused it, neither of them
 * predicted by any plan.**
 *
 *   1. `PurchaseOrderRowLockTest`'s writer scan keys every method by **short class name** and raises
 *      *"Two classes in src/ share the short name in NamedReference::__construct; key the scan by FQCN"* —
 *      a deliberate guard, because two classes merged under one key would make the sales tripwire's empty
 *      result meaningless.
 *   2. API Platform names a schema component after the class's short name (`#/components/schemas/…`,
 *      pinned for `ActorReference` in `OpenApiContractTest:327`), so two `NamedReference` classes would be
 *      two different components competing for one name in the generated spec.
 *
 * **The real fix is one of two things and neither belongs in this commit:** one shared `{id, name}` DTO
 * that every module may import (a `SharedKernel` move, which changes three shipped modules' deptrac
 * entries and the spec's component names), or keying that scan by FQCN (which moves every pin in a
 * tripwire). The name here is the small honest answer, and this paragraph is what stops the next module
 * rediscovering the collision.
 *
 * **PC-12 stays out of this for the reason it stays out of the purchasing one:** expanding a customer on a
 * sales order discloses the customer's **name**, which `sales_order.view` already implies, and never
 * `credit_limit`, `payment_terms_days` or anything else `customer.view` gates.
 *
 * **`created_by` does not come through here**, and on this resource it does not come through at all yet —
 * `SalesOrderResource` holds the member back pending `SEC`'s B1N-2 ruling. See that class.
 */
final readonly class SalesNamedReference
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}

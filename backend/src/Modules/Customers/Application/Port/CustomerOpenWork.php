<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application\Port;

use Doctrine\ORM\EntityManagerInterface;

/**
 * **What stops a customer being archived** — Customers' inbound port for ONE named consumer (C5 C4b,
 * `docs/session13/c5-design.md` Revision 1 R1.2 / R1.6; `session29-c5-ruling.md` Q-1 = (b′), adopted under the owner's
 * delegation). `DeleteCustomer` (`CustomerWriter::delete()`) asks it; **Sales answers it**
 * (`Sales\Application\CustomerOpenSalesWork`, bound in `services.yaml`), because only Sales may spell its documents'
 * statuses (API-15), compose its own shared predicates and read its tables (A-2).
 *
 * **Not a Contract** (R1.6, RED round 2 F-3): `Application\Contract` is the AI's door into a module (A-4), and this port
 * returns counts of documents with no `.view` gate. It lives in its own deptrac layer, `CustomersPort`, which only
 * `CustomersApplication` and `SalesApplication` may use — never `Ai*` or `Analytics*` (`DeptracRulesetTest` probes it).
 * `ARCH` co-signs the layer kind (design §9 A-1; owed).
 *
 * **(b′), four legs, counted in ONE statement** (one READ COMMITTED snapshot: separate counts could each miss a row a
 * concurrent `VoidInvoice` moves between "billed" and "unbilled"): open sales orders, billable shipments with no live
 * invoice, invoices owed by the customer, returns owed a credit — see {@see CustomerOpenWorkCount}. **A check at the
 * moment of archiving, not an invariant afterwards** (`RESIDUAL_GAPS.md` G-45).
 *
 * Call it inside your own tenancy transaction, AFTER taking the customer `FOR UPDATE` (W-2's shape for a read; it issues
 * one statement on `$em`'s connection and writes nothing).
 */
interface CustomerOpenWork
{
    /** @param string $customerId canonical lower-case RFC 4122 */
    public function openWorkInside(EntityManagerInterface $em, string $customerId): CustomerOpenWorkCount;
}

<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * **TB-4's Credited exception and what ends it** (sales step 7 C0; `IA.md` §5.4 ³ and its condition (1);
 * `docs/session13/output9-sales-8-c0-amendments.md` A-6). One text, carried as the failure message of every pin that
 * guards the condition: the live-grant pin (`CreditedSegmentLapseTest`), the `operationId` allow-list
 * (`OwedAmendmentTripwireTest`) and the behaviour pins on `PayInvoice`/`VoidInvoice` and on crediting a paid invoice.
 */
final class CreditedSegment
{
    public const LAPSE = "TB-4's Credited exception (IA.md §5.4 ³) lapses: Credited → Unpaid becomes reachable, so re-rule the segment (UX) and the keyset argument before shipping.";
}

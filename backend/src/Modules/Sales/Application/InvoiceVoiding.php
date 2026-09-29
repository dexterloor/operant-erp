<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\AuditLog\Infrastructure\InvoiceAuditWriter;
use App\Modules\Sales\Infrastructure\Entity\Invoice;
use App\Modules\UserManagement\Application\Contract\OwnerPredicate;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\Identity;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`VoidInvoice` — withdraw an issued invoice (IA L-9's compensation for "Issue an invoice"), one transaction.** Sales
 * step 6, C7 (2026-09-29), from `docs/session13/output9-sales-7-design.md` §19 C7 under OI-2 (owner ruling #2,
 * 2026-09-14), the owner's Q4 (*"Owner only"* — a HOLDING, ADR-008 A2.1a / A2.7) and ruling (d), B′ (`SEC` §15, §17.1).
 *
 * The statement order:
 *  1. The E-1 gate (`invoice.void`) has already run on `kernel.controller`. **That gate is the whole of "only the owner
 *     voids"** (`SEC` §9.1): since A2.1a `invoice.void` is on SoD-3's controlled half, so `admin` no longer holds it and
 *     `owner` alone holds it among the default roles; it is delegable only through a composed role an owner makes or
 *     confirms (E-15e). **There is no general owner check here**, and adding one would break the delegation Q4 chose.
 *  2. **`InvoiceRowLock`**, then the invoice re-read under it (another workspace's invoice and a random id: 404).
 *  3. **The status guard, first** — `Invoice::void()` refuses anything but `issued` or `overdue` (§8.3): a paid invoice
 *     is un-paid first (`ReverseInvoicePayment`), a void one is already void. Nothing is flushed until step 7, so a
 *     refusal below leaves the row as it was (the transaction rolls back).
 *  4. **`reason` is required** (§8.3, OI-2 R2): absent or blank → 422 `/reason` `required`.
 *  5. *(V2-R-17's slot — `invoice.is_not_credited`, "a credited invoice cannot be voided". Ruled (text) 2026-09-23; its
 *     enforcement is owed by `IssueCreditNote`, which is carried by `OwedAmendmentTripwireTest`'s
 *     `OPERATIONS_OWING_THE_INVOICE_CREDIT_FIELDS`. No credit note can exist until then, so nothing is checked here
 *     today. When it lands it goes HERE: after the status guard, BEFORE B′ — nobody, the owner included, is exempt
 *     from it, so it speaks first.)*
 *  6. **B′** (owner ruling (d), 2026-09-23): an invoice whose payment was EVER reversed (`payment_reversed_at`, set once
 *     by `ReverseInvoicePayment` and never cleared) may be voided only by an owner — `OwnerPredicate::holdsMarker()`
 *     over the request's own permission set, the ONE owner predicate (C6a), never a role name. Refused
 *     `invoice.is_not_payment_reversed`, *"A payment on this invoice was reversed, so only an owner can void it."*
 *     (`SEC` §17.1: "an owner", because a co-owner is an owner). This is §9.1's one named exception: it narrows the
 *     delegation only where Q4's own reason — erasing a settled debt — applies.
 *  7. Flush — the ORM `UPDATE` sets `status` and `updated_at`, both granted since C5; no grant is added.
 *  8. `invoice.voided`, metadata `{"reason"}`, in the same transaction. Then the 200 body, read back — `void`.
 *
 * Guards here are named `assert…`, never `refuseUnless…`: they are not edit windows (`EditWindowGuardTest` clause (b)).
 * A business-rule refusal writes no audit row (E-2).
 *
 * **What it deliberately does not do:** re-issue (the void releases `invoice_tenant_fulfilment_not_void`, and
 * `IssueInvoice` issues the corrected invoice under a new number); take any lock but the invoice's; expose
 * `payment_reversed_at` (not on the wire, `SEC` §17.2).
 */
final readonly class InvoiceVoiding
{
    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private InvoiceReader $reader,
        private InvoiceAuditWriter $audit,
    ) {
    }

    public function void(string $id, ?string $reason): InvoiceView
    {
        if (!Uuid::isValid($id)) {
            throw ApiProblem::notFound();
        }
        $id = Uuid::fromString($id)->toRfc4122();
        $identity = $this->identities->authenticated();

        return $this->tenants->run(
            $this->tenant->tenantId(),
            fn (EntityManagerInterface $em): InvoiceView => $this->voidInside($em, $id, $reason, $identity),
        );
    }

    private function voidInside(EntityManagerInterface $em, string $id, ?string $reason, Identity $identity): InvoiceView
    {
        // 2.
        InvoiceRowLock::holdInside($em, $id);

        $invoice = $em->find(Invoice::class, Uuid::fromString($id));
        if (!$invoice instanceof Invoice) {
            throw ApiProblem::notFound();
        }
        $em->refresh($invoice);

        // 3. The status guard is `void()`'s first statement; nothing is flushed before step 7.
        $invoice->void();

        // 4.
        $reason = self::assertReasonGiven($reason);

        // 5. V2-R-17's slot — see the class docblock. Nothing to check until `IssueCreditNote` exists.

        // 6.
        self::assertNotPaymentReversedUnlessAnOwner($invoice, $identity);

        // 7.
        $em->flush();

        // 8.
        $this->audit->recordVoided(
            tenantId: $identity->tenantId,
            actorUserId: $identity->userId,
            actorLabel: $identity->actorLabel,
            invoiceId: $id,
            reason: $reason,
        );

        return $this->reader->readInside($em, $id)
            ?? throw new \LogicException('The invoice just voided is not visible to its own transaction.');
    }

    /** Step 4. A blank reason is not a reason: `metadata` is written once on an append-only table. */
    private static function assertReasonGiven(?string $reason): string
    {
        $reason = trim((string) $reason);
        if ('' === $reason) {
            throw ApiProblem::validationFailed([
                ['pointer' => '/reason', 'code' => 'required', 'message' => 'Say why the invoice is being voided.'],
            ]);
        }

        return $reason;
    }

    /**
     * Step 6 — B′. `payment_reversed_at` is read as re-read under the lock (`refresh()` above), so a reversal that
     * committed while this request waited on the lock is seen. It is the invoice's HISTORY, not a person: a later
     * reversal by anyone, or a later payment, never clears it (`COALESCE`, set once), which is what defeats the laundering
     * sequence B could not (`SEC` §15).
     */
    private static function assertNotPaymentReversedUnlessAnOwner(Invoice $invoice, Identity $caller): void
    {
        if (null === $invoice->getPaymentReversedAt()) {
            return;
        }

        if (OwnerPredicate::holdsMarker($caller->permissionCodes)) {
            return;
        }

        throw ApiProblem::businessRuleViolated(
            'invoice.is_not_payment_reversed',
            'A payment on this invoice was reversed, so only an owner can void it.',
        );
    }
}

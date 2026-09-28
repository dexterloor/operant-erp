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
 * **`ReverseInvoicePayment` — undo a payment recorded in error (IA L-9's compensation for "Mark an invoice paid"), one
 * transaction.** Sales step 6, C6 (2026-09-28), from `docs/session13/output9-sales-7-design.md` §19 C6 under OI-2
 * (owner ruling #2, 2026-09-14), ADR-008 A2.3/A2.3a, `SEC`'s Q8 (the one owner predicate) and the owner's ruling (d),
 * B′ (`SEC` §15).
 *
 * The statement order:
 *  1. The E-1 gate (`invoice.pay` — the reversal is the same control as the payment it undoes, ADR-008 R-15) has
 *     already run on `kernel.controller`.
 *  2. **`InvoiceRowLock`**, then the invoice re-read under it (another workspace's invoice and a random id: 404).
 *  3. **The status guard, first** — `Invoice::reversePayment()` refuses anything but `paid` (§8.3), so an unpaid
 *     invoice gets the transition 409 before anything else is said about the request. Nothing is flushed until step 6,
 *     so a refusal below leaves the row as it was (the transaction rolls back).
 *  4. **`reason` is required** (§8.3, OI-2): absent or blank → 422 `/reason` `required`.
 *  5. **Reverser ≠ recorder, unless the caller is an owner** (ADR-008 A2.3, DSoD-1's shape): the recorder is
 *     `paid_by_user_id` as read under the lock; the exemption is `OwnerPredicate::holdsMarker()` over the request's own
 *     permission set — the ONE owner predicate (V2-R-19 + V2-S-5, C6a), never a role name. Refused
 *     `invoice.reverser_is_not_the_recorder`, *"You recorded this payment. It needs a different person to reverse it."*
 *  6. Flush — the ORM `UPDATE` sets `status`, `paid_at` (NULL), `paid_by_user_id` (NULL) and `updated_at`, all granted
 *     since C5 — then **`payment_reversed_at = COALESCE(payment_reversed_at, now())`** in SQL: B′'s "ever reversed",
 *     set once on the database clock (RED-3 F-1), never moved by a later reversal (`SEC` §15 modification 1).
 *  7. `invoice.payment_reversed`, metadata `{"reason"}`, in the same transaction. Then the 200 body, read back —
 *     `issued` again, `paid_at` and `paid_by` null.
 *
 * Guards here are named `assert…`, never `refuseUnless…`: they are not edit windows (`EditWindowGuardTest` clause (b)).
 *
 * **What it deliberately does not do:** accept a replacement payment (a mistaken reversal is answered by recording the
 * payment again, IA L-9); take any lock but the invoice's; expose `payment_reversed_at` (not on the wire, `SEC` §17.2).
 */
final readonly class InvoicePaymentReversal
{
    /** B′'s one statement: set once, on the database clock. `WriteOwnershipTest` counts it for `Modules\Sales`. */
    private const MARK_REVERSED = 'UPDATE invoice SET payment_reversed_at = COALESCE(payment_reversed_at, now()) WHERE id = ?::uuid';

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private InvoiceReader $reader,
        private InvoiceAuditWriter $audit,
    ) {
    }

    public function reverse(string $id, ?string $reason): InvoiceView
    {
        if (!Uuid::isValid($id)) {
            throw ApiProblem::notFound();
        }
        $id = Uuid::fromString($id)->toRfc4122();
        $identity = $this->identities->authenticated();

        return $this->tenants->run(
            $this->tenant->tenantId(),
            fn (EntityManagerInterface $em): InvoiceView => $this->reverseInside($em, $id, $reason, $identity),
        );
    }

    private function reverseInside(EntityManagerInterface $em, string $id, ?string $reason, Identity $identity): InvoiceView
    {
        // 2.
        InvoiceRowLock::holdInside($em, $id);

        $invoice = $em->find(Invoice::class, Uuid::fromString($id));
        if (!$invoice instanceof Invoice) {
            throw ApiProblem::notFound();
        }
        $em->refresh($invoice);
        $recorded = $em->getConnection()->fetchOne('SELECT paid_by_user_id::text FROM invoice WHERE id = ?::uuid', [$id]);
        $recorder = false === $recorded || null === $recorded ? null : (string) $recorded;

        // 3. The status guard is `reversePayment()`'s first statement; nothing is flushed before step 6.
        $invoice->reversePayment();

        // 4 and 5.
        $reason = self::assertReasonGiven($reason);
        self::assertReverserIsNotTheRecorder($recorder, $identity);

        // 6.
        $em->flush();
        $em->getConnection()->executeStatement(self::MARK_REVERSED, [$id]);

        // 7.
        $this->audit->recordPaymentReversed(
            tenantId: $identity->tenantId,
            actorUserId: $identity->userId,
            actorLabel: $identity->actorLabel,
            invoiceId: $id,
            reason: $reason,
        );

        return $this->reader->readInside($em, $id)
            ?? throw new \LogicException('The invoice whose payment was just reversed is not visible to its own transaction.');
    }

    /** Step 4. A blank reason is not a reason: `metadata` is written once on an append-only table. */
    private static function assertReasonGiven(?string $reason): string
    {
        $reason = trim((string) $reason);
        if ('' === $reason) {
            throw ApiProblem::validationFailed([
                ['pointer' => '/reason', 'code' => 'required', 'message' => 'Say why the payment is being reversed.'],
            ]);
        }

        return $reason;
    }

    /**
     * Step 5 — ADR-008 A2.3: the reverser is not the person who recorded the payment, unless an owner. A paid invoice
     * always has a recorder (`invoice_paid_by_matches_status`), so a NULL here means no payment to compare against — it
     * cannot arise past step 3, and it refuses nothing.
     */
    private static function assertReverserIsNotTheRecorder(?string $recorder, Identity $caller): void
    {
        if (null === $recorder || $recorder !== $caller->userId) {
            return;
        }

        if (OwnerPredicate::holdsMarker($caller->permissionCodes)) {
            return;
        }

        throw ApiProblem::businessRuleViolated(
            'invoice.reverser_is_not_the_recorder',
            'You recorded this payment. It needs a different person to reverse it.',
        );
    }
}

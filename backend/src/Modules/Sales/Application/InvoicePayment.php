<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\AuditLog\Infrastructure\InvoiceAuditWriter;
use App\Modules\Sales\Infrastructure\Entity\Invoice;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Http\Rfc3339Timestamp;
use App\Platform\Security\Identity;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`PayInvoice` — record that an invoice has been paid (FLOWS F-5 step 8), one transaction.** Sales step 6, C5
 * (2026-09-28), built from `docs/session13/output9-sales-7-design.md` §19 C5 under OI-2 (owner ruling #2,
 * 2026-09-14) and `SEC`'s Q7 (`paid_by` exposed, PC-11 (4a)).
 *
 * The statement order:
 *  1. The E-1 gate (`invoice.pay`) has already run — `PermissionGate`, on `kernel.controller`.
 *  2. **`InvoiceRowLock`**, the transaction's first statement, then the invoice re-read under it (RLS: another
 *     workspace's invoice and a random uuid are the same 404, E-2 / ST-5).
 *  3. The status guard, inside `Invoice::markPaid()` (§8.3's matrix row: `issued` or `overdue`). A repeat finds the
 *     invoice `paid` and is refused `invoice.status_transition_is_legal` — `guard:invoice.status`, so no
 *     `Idempotency-Key` is demanded (API-14 as amended).
 *  4. **`paid_at`, on the DATABASE clock** (RED-3 F-1): one `SELECT now()` inside the locked transaction is the
 *     default, the "future" bound and the clamp target — never PHP's clock. More than 60 s ahead of it → 422
 *     `in_the_future` (`FulfilmentWriter::shippedAt()`'s rule); inside the window → clamped to it. **Earlier than the
 *     invoice's `issued_at`, read from the locked row → `invoice.paid_after_issue`** (RED R-10), before
 *     `invoice_paid_not_before_issue` could answer 23514 as a 500. A PHP clock running behind the database's would
 *     otherwise clamp to an instant before `issued_at` (the Colima `+2.03 s` trap).
 *  5. `markPaid($paidAt, <the caller>)`, then flush. The ORM's UPDATE sets exactly `status`, `paid_at`,
 *     `paid_by_user_id` and `updated_at` — the four columns `erp_app` is granted (`Version20260928000400`; M-11).
 *  6. `invoice.paid`, metadata `{"paid_at": <the value written>}` (`SEC` §5.1), in the same transaction. Then the 200
 *     body, read back, with `paid_by = {id, display_name}`.
 *
 * **The payer is the authenticated caller, never a request field** (`SEC` §2; A2.3a). Its reference is obtained
 * through `Invoice`'s own mapping — `getAssociationTargetClass('paidBy')` — rather than by naming the
 * `UserManagement` entity here: the `Invoice → AppUser` association is the registered A-12 edge, and this
 * Application class adds no second cross-module edge to hand the entity the id it already maps. `getReference()`
 * issues no query.
 *
 * **Stated decisions:** full payment only (partial payment is not ruled); no payment amount, method or reference is
 * accepted (§4.2's cell is `paid_at` alone).
 */
final readonly class InvoicePayment
{
    /** `FulfilmentWriter::CLOCK_SKEW_SECONDS`' tolerance, for the same reason: a client clock a little ahead. */
    public const CLOCK_SKEW_SECONDS = 60;

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private InvoiceReader $reader,
        private InvoiceAuditWriter $audit,
    ) {
    }

    public function pay(string $id, ?string $paidAtValue): InvoiceView
    {
        // The shape of `paid_at` is decidable without the database; its bounds are not (step 4).
        $requested = null === $paidAtValue ? null : (Rfc3339Timestamp::parse($paidAtValue) ?? throw ApiProblem::validationFailed([
            ['pointer' => '/paid_at', 'code' => 'not_a_timestamp', 'message' => 'This field must be an RFC 3339 timestamp.'],
        ]));

        if (!Uuid::isValid($id)) {
            throw ApiProblem::notFound();
        }
        $id = Uuid::fromString($id)->toRfc4122();
        $identity = $this->identities->authenticated();

        return $this->tenants->run(
            $this->tenant->tenantId(),
            fn (EntityManagerInterface $em): InvoiceView => $this->payInside($em, $id, $requested, $identity),
        );
    }

    private function payInside(EntityManagerInterface $em, string $id, ?\DateTimeImmutable $requested, Identity $identity): InvoiceView
    {
        // 2.
        InvoiceRowLock::holdInside($em, $id);

        $invoice = $em->find(Invoice::class, Uuid::fromString($id));
        if (!$invoice instanceof Invoice) {
            throw ApiProblem::notFound();
        }
        // `find()` answers from the identity map when the entity is already managed; refreshing re-reads the row
        // under the lock whoever loaded it and when (`SalesOrderConfirmation`'s reason).
        $em->refresh($invoice);

        // 4.
        $paidAt = $this->paidAt($em, $requested, $invoice);

        // 3 and 5. The status guard is the first statement of `markPaid()`.
        $payer = $em->getReference($em->getClassMetadata(Invoice::class)->getAssociationTargetClass('paidBy'), Uuid::fromString($identity->userId));
        $invoice->markPaid($paidAt, $payer);
        $em->flush();

        $view = $this->reader->readInside($em, $id)
            ?? throw new \LogicException('The invoice just paid is not visible to its own transaction.');

        // 6.
        $this->audit->recordPaid(
            tenantId: $identity->tenantId,
            actorUserId: $identity->userId,
            actorLabel: $identity->actorLabel,
            invoiceId: $id,
            paidAt: (string) $view->paidAt,
        );

        return $view;
    }

    /**
     * Step 4, entirely on the database's clock. `now()` is the transaction timestamp — the clock `issued_at`'s
     * default was read from — so "not before the invoice" and "not in the future" are decided on one clock.
     */
    private function paidAt(EntityManagerInterface $em, ?\DateTimeImmutable $requested, Invoice $invoice): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable((string) $em->getConnection()->fetchOne('SELECT now()'));

        if (null === $requested) {
            return $now;
        }

        if ($requested > $now->modify('+'.self::CLOCK_SKEW_SECONDS.' seconds')) {
            throw ApiProblem::validationFailed([
                ['pointer' => '/paid_at', 'code' => 'in_the_future', 'message' => 'A payment cannot be recorded in the future. Omit the field to mean now.'],
            ]);
        }

        $paidAt = min($requested, $now);

        if ($paidAt < $invoice->getIssuedAt()) {
            throw ApiProblem::businessRuleViolated(
                'invoice.paid_after_issue',
                'A payment cannot be dated before the invoice was issued.',
            );
        }

        return $paidAt;
    }
}

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
 * The statement order (as changed by sales step 7 C0 and C1 — `docs/session13/output9-sales-8-c0-amendments.md` A-1
 * row 7; design §4.6; three changes to a shipped operation, approved with step 7):
 *  1. The E-1 gate (`invoice.pay`) has already run — `PermissionGate`, on `kernel.controller`.
 *  1a. *(C1)* **The lock key, read BEFORE the locks, in its own short transaction:** the order of the invoice's
 *     FULFILMENT (`SELECT f.sales_order_id FROM invoice i JOIN fulfilment f …`) — the row `CreateSalesReturn` locks. It
 *     cannot go stale: `erp_app`'s UPDATE on `invoice` is column-level and excludes `sales_order_id` and
 *     `fulfilment_id`, and it holds none on `fulfilment` (design §4.6 (0)).
 *  2. *(C1)* **`SalesOrderRowLock` on that order, then `InvoiceRowLock`** — the ruled W-5 order → invoice — then the
 *     invoice re-read under it (RLS: another workspace's invoice and a random uuid are the same 404, E-2 / ST-5).
 *     Without the order lock a payment could read "no creditable return" while a return was in flight and commit
 *     `paid`, leaving the very end state (α) exists to prevent.
 *  2a. *(C1)* **After both locks, `invoice.sales_order_id = fulfilment.sales_order_id` = the order locked** — or the
 *     request is refused 500-class, logged, and NOTHING is paid: a forged invoice (G-15) would have locked a different
 *     order (`SEC` final position item 4).
 *  3. The status guard (§8.3's matrix row: `issued` or `overdue`) — **evaluated explicitly after step 4's date checks**
 *     since sales step 7 C0 (`Invoice::assertPayable()`; `markPaid()` keeps it as its first statement too). A
 *     repeat finds the invoice `paid` and is refused `invoice.status_transition_is_legal` — `guard:invoice.status`, so
 *     no `Idempotency-Key` is demanded (API-14 as amended).
 *  3a. **`invoice.has_an_amount_due` (409, sales step 7 C0 — a change to shipped `PayInvoice`, vacuous until C1
 *     writes a credit):** an invoice **credited in full** ({@see InvoiceCredit::creditedInFull()}, the one predicate)
 *     owes nothing and cannot be marked paid. AFTER the status guard, so no shipped answer moves
 *     (`output9-sales-8-c0-amendments.md` A-1 row 7: locks → re-read → dates → status → the new refusals → flip).
 *     A zero-total invoice with no credit note is not credited in full and is paid as before.
 *  4. **`paid_at`, on the DATABASE clock** (RED-3 F-1): one `SELECT now()` inside the locked transaction is the
 *     default, the "future" bound and the clamp target — never PHP's clock. More than 60 s ahead of it → 422
 *     `in_the_future` (`FulfilmentWriter::shippedAt()`'s rule); inside the window → clamped to it. **Earlier than the
 *     invoice's `issued_at`, read from the locked row → `invoice.paid_after_issue`** (RED R-10), before
 *     `invoice_paid_not_before_issue` could answer 23514 as a 500. A PHP clock running behind the database's would
 *     otherwise clamp to an instant before `issued_at` (the Colima `+2.03 s` trap).
 *  3b. *(C1, owner ruling Q-P restricted (α); after the status guard, so N-4 holds — a retry of a completed payment is
 *     answered by the status refusal, whatever its body)*: every `acknowledged_return_ids[]` entry must be a return of
 *     this invoice's shipment → else 422 on `/acknowledged_return_ids/{i}`; an entry that is a still-creditable
 *     `recorded_in_error` return → 409 `invoice.error_return_cannot_be_acknowledged` (paying past it bills a shipment
 *     that did not happen); then every **creditable** return of the shipment (`SalesReturnReader::creditable()`, the one
 *     shared predicate) must be named → else 409 `invoice.has_no_uncredited_return`, whose detail lists the ids to
 *     acknowledge ONLY to a caller holding `sales_return.view` (`SEC` final position item 1). An entry that is no
 *     longer creditable (credited meanwhile) is ignored: the payer's intent is met.
 *  3c. Then 3a's `invoice.has_an_amount_due`.
 *  5. `markPaid($paidAt, <the caller>)`, then flush. The ORM's UPDATE sets exactly `status`, `paid_at`,
 *     `paid_by_user_id` and `updated_at` — the four columns `erp_app` is granted (`Version20260928000400`; M-11).
 *  6. `invoice.paid`, metadata `{"paid_at": <the value written>}` (`SEC` §5.1) — plus, since C1,
 *     `"acknowledged_return_ids"`: the returns actually paid past (named AND creditable), sorted, omitted when none
 *     (`RED-5` L5-2) — in the same transaction. Then the 200
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

    public function pay(string $id, ?string $paidAtValue, mixed $acknowledgedReturnIds = null): InvoiceView
    {
        // The shape of `paid_at` is decidable without the database; its bounds are not (step 4).
        $requested = null === $paidAtValue ? null : (Rfc3339Timestamp::parse($paidAtValue) ?? throw ApiProblem::validationFailed([
            ['pointer' => '/paid_at', 'code' => 'not_a_timestamp', 'message' => 'This field must be an RFC 3339 timestamp.'],
        ]));
        // So is the shape of `acknowledged_return_ids` — WHICH returns they are is step 3b's, after the status guard.
        $acknowledged = self::acknowledgedShape($acknowledgedReturnIds);

        if (!Uuid::isValid($id)) {
            throw ApiProblem::notFound();
        }
        $id = Uuid::fromString($id)->toRfc4122();
        $identity = $this->identities->authenticated();

        // 1a.
        $orderId = $this->tenants->run($this->tenant->tenantId(), static fn (EntityManagerInterface $em): mixed => $em->getConnection()->fetchOne(
            'SELECT f.sales_order_id FROM invoice i JOIN fulfilment f ON f.tenant_id = i.tenant_id AND f.id = i.fulfilment_id WHERE i.id = ?::uuid',
            [$id],
        ));
        if (false === $orderId || null === $orderId) {
            throw ApiProblem::notFound();
        }

        return $this->tenants->run(
            $this->tenant->tenantId(),
            fn (EntityManagerInterface $em): InvoiceView => $this->payInside($em, $id, (string) $orderId, $requested, $acknowledged, $identity),
        );
    }

    /**
     * @param list<string> $acknowledged
     */
    private function payInside(EntityManagerInterface $em, string $id, string $orderId, ?\DateTimeImmutable $requested, array $acknowledged, Identity $identity): InvoiceView
    {
        // 2.
        SalesOrderRowLock::holdInside($em, $orderId);
        InvoiceRowLock::holdInside($em, $id);

        $invoice = $em->find(Invoice::class, Uuid::fromString($id));
        if (!$invoice instanceof Invoice) {
            throw ApiProblem::notFound();
        }
        // `find()` answers from the identity map when the entity is already managed; refreshing re-reads the row
        // under the lock whoever loaded it and when (`SalesOrderConfirmation`'s reason).
        $em->refresh($invoice);

        // 2a.
        $orders = $em->getConnection()->fetchAssociative(
            'SELECT i.sales_order_id::text AS invoice_order, f.sales_order_id::text AS fulfilment_order, i.fulfilment_id::text AS fulfilment
               FROM invoice i JOIN fulfilment f ON f.tenant_id = i.tenant_id AND f.id = i.fulfilment_id WHERE i.id = ?::uuid',
            [$id],
        );
        if (false === $orders || $orders['invoice_order'] !== $orders['fulfilment_order'] || $orders['fulfilment_order'] !== $orderId) {
            throw new \RuntimeException(\sprintf(
                'Invoice %s names order %s, its fulfilment\'s order is %s, and the order locked was %s. The invoice was written '
                .'outside the application (RESIDUAL_GAPS.md G-15). Nothing was paid.',
                $id, (string) ($orders['invoice_order'] ?? '?'), (string) ($orders['fulfilment_order'] ?? '?'), $orderId,
            ));
        }

        // 4.
        $paidAt = $this->paidAt($em, $requested, $invoice);

        // 3. The status guard, explicitly, before any new refusal (N-4).
        $invoice->assertPayable();

        // 3b.
        $pastReturns = $this->refuseUncreditedReturns($em, (string) $orders['fulfilment'], $acknowledged, $identity);

        // 3a / 3c. The one predicate, on the locked row's stored columns.
        if (true === (bool) $em->getConnection()->fetchOne(
            'SELECT '.InvoiceCredit::creditedInFull().' FROM invoice inv WHERE inv.id = ?::uuid',
            [$id],
        )) {
            throw ApiProblem::businessRuleViolated(
                'invoice.has_an_amount_due',
                'This invoice has been credited in full, so nothing is owed on it and it cannot be marked paid.',
            );
        }

        // 5.
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
            acknowledgedReturnIds: $pastReturns,
        );

        return $view;
    }

    /**
     * Step 3b — Q-P restricted (α). Returns the ids actually paid past: named AND creditable (`RED-5` L5-2).
     *
     * @param list<string> $acknowledged
     *
     * @return list<string>
     */
    private function refuseUncreditedReturns(EntityManagerInterface $em, string $fulfilmentId, array $acknowledged, Identity $identity): array
    {
        $returns = [];
        foreach ($em->getConnection()->fetchAllAssociative(\sprintf(<<<'SQL'
            SELECT r.id::text AS id, r.sales_return_number, r.reason, %s AS creditable, %s AS decision_owed
              FROM sales_return r
              LEFT JOIN invoice live ON live.tenant_id = r.tenant_id AND live.fulfilment_id = r.fulfilment_id AND live.status <> '%s'
             WHERE r.fulfilment_id = ?::uuid
             ORDER BY r.sales_return_number
            SQL, SalesReturnReader::creditable(), SalesReturnReader::adjustmentDecisionOwed(), Invoice::STATUS_VOID), [$fulfilmentId]) as $row) {
            $returns[(string) $row['id']] = $row;
        }

        $errors = [];
        foreach ($acknowledged as $index => $returnId) {
            if (!isset($returns[$returnId])) {
                $errors[] = ['pointer' => '/acknowledged_return_ids/'.$index, 'code' => 'unknown_reference',
                    'message' => 'No return of the shipment this invoice bills has this id.'];
            }
        }
        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }

        $creditable = array_filter($returns, static fn (array $row): bool => (bool) $row['creditable']);
        foreach ($acknowledged as $returnId) {
            if (isset($creditable[$returnId]) && SalesReturnWriter::RECORDED_IN_ERROR === $creditable[$returnId]['reason']) {
                throw ApiProblem::businessRuleViolated(
                    'invoice.error_return_cannot_be_acknowledged',
                    'This return records a shipment that did not happen. Credit it before recording payment.',
                );
            }
        }

        $unnamed = array_diff_key($creditable, array_flip($acknowledged));
        if ([] !== $unnamed) {
            $detail = 'Goods on this shipment came back and have not been credited yet. Issue the credit note first.';
            // `SEC` final position item 1: the ids only to a caller who can read returns (every default `invoice.pay`
            // holder can, under Q-B); anyone else is told only that such returns exist.
            if ($identity->holds('sales_return.view')) {
                $detail .= ' To record the payment anyway, name each of these returns in acknowledged_return_ids: '
                    .implode(', ', array_map(static fn (array $row): string => $row['id'].' ('.$row['sales_return_number'].')', $unnamed)).'.';
            }

            // G-29 / G-30 part 3 (RED d1d4 F-2, final RED R-4): an unnamed return that is also awaiting an adjustment
            // DECISION is told so — paying past it turns a later `distinct` into G-23's queue, and the way out
            // (`ReverseInvoicePayment`) marks the invoice for good. Copy only; an `invoice.view` holder's, and the return
            // number only for a caller who also holds `sales_return.view`.
            $owed = array_filter($unnamed, static fn (array $row): bool => (bool) $row['decision_owed']);
            if ([] !== $owed && $identity->holds('invoice.view')) {
                $detail .= \sprintf(' A decision is owed on %s (already credited, or, by an owner, different goods). If it turns out to be different goods after you record the payment, its credit will need the payment reversed, which marks the invoice for good.',
                    $identity->holds('sales_return.view')
                        ? implode(', ', array_map(static fn (array $row): string => (string) $row['sales_return_number'], $owed))
                        : 'one of these returns');
            }

            throw ApiProblem::businessRuleViolated('invoice.has_no_uncredited_return', $detail);
        }

        return array_values(array_intersect($acknowledged, array_keys($creditable)));
    }

    /**
     * The shape of `acknowledged_return_ids`: absent or a list of uuids (deduplicated). Which returns they are is step 3b's.
     *
     * @return list<string>
     */
    private static function acknowledgedShape(mixed $value): array
    {
        if (null === $value) {
            return [];
        }
        if (!\is_array($value) || !array_is_list($value)) {
            throw ApiProblem::validationFailed([['pointer' => '/acknowledged_return_ids', 'code' => 'not_a_list',
                'message' => 'This field must be a list of return ids.']]);
        }

        $errors = [];
        $ids = [];
        foreach ($value as $index => $id) {
            if (!\is_string($id) || !Uuid::isValid($id)) {
                $errors[] = ['pointer' => '/acknowledged_return_ids/'.$index, 'code' => 'not_a_uuid',
                    'message' => 'This field must be a UUID naming a return in this workspace.'];
                continue;
            }
            $ids[] = Uuid::fromString($id)->toRfc4122();
        }
        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }

        return array_values(array_unique($ids));
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

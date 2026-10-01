<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Infrastructure;

use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\ValueObject\AuditEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **The `invoice.*` audit rows** — one class per subject table, `SalesOrderAuditWriter`'s shape and reasoning
 * (one method per event, no parameter naming an event, so no caller can choose one it was not written for).
 *
 * Sales step 6, C3 (2026-09-28): `invoice.issued`, owner ruling Q5. `invoice.paid` joined with `PayInvoice` (C5, 2026-09-28) and
 * `invoice.payment_reversed` with `ReverseInvoicePayment` (C6, the same day), and `invoice.voided` with `VoidInvoice` (C7, 2026-09-29), each
 * with the metadata `SEC` ruled for it (`output9-sales-7-sec.md` §5.1).
 *
 * **Synchronous and inside the caller's transaction** (`API.md` §9.2, `ARCHITECTURE.md` §6.1):
 * `TenantContext::run()` is re-entrant for the same tenant, so this call joins `InvoiceIssuer`'s transaction and the
 * invoice and the row naming its issuer commit together or not at all. **No grant:** `erp_app` already holds INSERT
 * on `audit_log` (measured; S-9 leaves no role UPDATE or DELETE on it).
 *
 * **The parameter list is the control.** No `$ipAddress` (a business event; `audit_log_ip_address_scope` refuses one),
 * no `permission_code` (`audit_log_permission_code_scope`), and **no metadata parameter at all for `invoice.issued`**:
 * `SEC` ruled it `{}` — the number, customer and fulfilment are PC-15 display data (condition 6 forbids copying them
 * into `metadata`), and a copy of `total_amount` adds no detection. A method with no parameter for them cannot send
 * them.
 */
final readonly class InvoiceAuditWriter
{
    public function __construct(private TenantContext $tenants)
    {
    }

    /**
     * `invoice.issued`. `$actorUserId` is the issuer, which is also `invoice.issued_by_user_id`; `$actorLabel` is
     * their display name, captured by the caller (`audit_log.actor_label` is NOT NULL so the trail outlives the user).
     */
    public function recordIssued(string $tenantId, string $actorUserId, string $actorLabel, string $invoiceId): void
    {
        $this->tenants->run($tenantId, static function (EntityManagerInterface $em) use ($tenantId, $actorUserId, $actorLabel, $invoiceId): void {
            $em->getConnection()->executeStatement(<<<'SQL'
                INSERT INTO audit_log
                    (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label,
                     subject_type, subject_id, ip_address, metadata)
                VALUES
                    (?::uuid, ?::uuid, now(), ?, ?::uuid, ?, ?, ?::uuid, NULL, '{}'::jsonb)
                SQL, [
                    Uuid::v4()->toRfc4122(),
                    $tenantId,
                    AuditEvent::INVOICE_ISSUED,
                    $actorUserId,
                    $actorLabel,
                    // Read off the catalogue: `audit_log_subject_pair` requires the pair to arrive together.
                    AuditEvent::SUBJECT_TYPES[AuditEvent::INVOICE_ISSUED],
                    $invoiceId,
                ]);
        });
    }

    /**
     * `invoice.paid` (sales step 6, C5). `$actorUserId` is the payer, which is also `invoice.paid_by_user_id`.
     *
     * **`metadata` is exactly `{"paid_at": <the value written>}`** (`SEC` §5.1), bound as a parameter into
     * `jsonb_build_object` and never interpolated. It is the invoice's own column, carried because a reversal (C6)
     * nulls `paid_at`: without it a back-dated payment that was later reversed would leave no record of the date it
     * claimed. `$paidAt` is the wire's rendering of the stored value (`InvoiceView::$paidAt`), so the History and the
     * invoice say the same instant in the same form. Nothing else is copied — but see the method body for the one key
     * sales step 7 C1 added.
     *
     * @param list<string> $acknowledgedReturnIds
     */
    public function recordPaid(string $tenantId, string $actorUserId, string $actorLabel, string $invoiceId, string $paidAt, array $acknowledgedReturnIds = []): void
    {
        // Sales step 7 C1 (`SEC` final position item 2; `RED-5` L5-2): `acknowledged_return_ids` — the returns this
        // payment was recorded PAST (Q-P restricted (α)), sorted uuids and nothing else, and ONLY those actually paid
        // past, never every id sent. **Omitted when empty**, so every earlier row keeps exactly `{"paid_at"}` and the
        // key's presence is what an auditor filters on. A change to a shipped event's shape, approved with step 7.
        sort($acknowledgedReturnIds);
        $acknowledged = [] === $acknowledgedReturnIds ? null : json_encode(array_values($acknowledgedReturnIds), \JSON_THROW_ON_ERROR);

        $this->tenants->run($tenantId, static function (EntityManagerInterface $em) use ($tenantId, $actorUserId, $actorLabel, $invoiceId, $paidAt, $acknowledged): void {
            $em->getConnection()->executeStatement(<<<'SQL'
                INSERT INTO audit_log
                    (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label,
                     subject_type, subject_id, ip_address, metadata)
                VALUES
                    (?::uuid, ?::uuid, now(), ?, ?::uuid, ?, ?, ?::uuid, NULL,
                     jsonb_build_object('paid_at', ?::text)
                     || CASE WHEN ?::jsonb IS NULL THEN '{}'::jsonb ELSE jsonb_build_object('acknowledged_return_ids', ?::jsonb) END)
                SQL, [
                    Uuid::v4()->toRfc4122(),
                    $tenantId,
                    AuditEvent::INVOICE_PAID,
                    $actorUserId,
                    $actorLabel,
                    AuditEvent::SUBJECT_TYPES[AuditEvent::INVOICE_PAID],
                    $invoiceId,
                    $paidAt,
                    $acknowledged,
                    $acknowledged,
                ]);
        });
    }

    /**
     * `invoice.payment_reversed` (sales step 6, C6). `$actorUserId` is the reverser. **`metadata` is exactly
     * `{"reason": <string>}`** (`SEC` §5.1): the reason is required by the operation, so the key is always present, bound
     * as a parameter and never interpolated — `sales_order.cancelled`'s shape. It deliberately does not copy the reversed
     * recorder's id or `paid_at`: the `invoice.paid` row it undoes already holds both, and an `app_user` id in `metadata`
     * would be a copied reference.
     */
    public function recordPaymentReversed(string $tenantId, string $actorUserId, string $actorLabel, string $invoiceId, string $reason): void
    {
        $this->tenants->run($tenantId, static function (EntityManagerInterface $em) use ($tenantId, $actorUserId, $actorLabel, $invoiceId, $reason): void {
            $em->getConnection()->executeStatement(<<<'SQL'
                INSERT INTO audit_log
                    (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label,
                     subject_type, subject_id, ip_address, metadata)
                VALUES
                    (?::uuid, ?::uuid, now(), ?, ?::uuid, ?, ?, ?::uuid, NULL, jsonb_build_object('reason', ?::text))
                SQL, [
                    Uuid::v4()->toRfc4122(),
                    $tenantId,
                    AuditEvent::INVOICE_PAYMENT_REVERSED,
                    $actorUserId,
                    $actorLabel,
                    AuditEvent::SUBJECT_TYPES[AuditEvent::INVOICE_PAYMENT_REVERSED],
                    $invoiceId,
                    $reason,
                ]);
        });
    }

    /**
     * `invoice.voided` (sales step 6, C7). `$actorUserId` is the voider. **`metadata` is exactly `{"reason": <string>}`**
     * (`SEC` §5.1): the reason is required by the operation, so the key is always present, bound as a parameter and never
     * interpolated — `sales_order.cancelled`'s and `invoice.payment_reversed`'s shape. Nothing else is copied: the
     * invoice's number, total and customer are PC-15 display data (condition 6).
     */
    public function recordVoided(string $tenantId, string $actorUserId, string $actorLabel, string $invoiceId, string $reason): void
    {
        $this->tenants->run($tenantId, static function (EntityManagerInterface $em) use ($tenantId, $actorUserId, $actorLabel, $invoiceId, $reason): void {
            $em->getConnection()->executeStatement(<<<'SQL'
                INSERT INTO audit_log
                    (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label,
                     subject_type, subject_id, ip_address, metadata)
                VALUES
                    (?::uuid, ?::uuid, now(), ?, ?::uuid, ?, ?, ?::uuid, NULL, jsonb_build_object('reason', ?::text))
                SQL, [
                    Uuid::v4()->toRfc4122(),
                    $tenantId,
                    AuditEvent::INVOICE_VOIDED,
                    $actorUserId,
                    $actorLabel,
                    AuditEvent::SUBJECT_TYPES[AuditEvent::INVOICE_VOIDED],
                    $invoiceId,
                    $reason,
                ]);
        });
    }
}

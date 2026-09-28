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
 * Sales step 6, C3 (2026-09-28): `invoice.issued`, owner ruling Q5. `invoice.paid`, `invoice.payment_reversed` and
 * `invoice.voided` join this class with `PayInvoice` (C5), `ReverseInvoicePayment` (C6) and `VoidInvoice` (C7), each
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
}

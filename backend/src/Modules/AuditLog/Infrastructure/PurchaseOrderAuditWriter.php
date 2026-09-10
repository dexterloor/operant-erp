<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Infrastructure;

use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\ValueObject\AuditEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **F-2 step 8 — `PurchaseOrder::place()` *"writes an `audit_log` row naming the
 * approver"*.**
 *
 * ===========================================================================
 * `erp_app`, and no new grant
 * ===========================================================================
 * `API.md` §9.2's row for `PURCHASE_ORDER_PLACED` names the principal:
 * **`erp_app`**, written by `ApprovePurchaseOrder`. `erp_app` already holds
 * `SELECT, INSERT` on `audit_log` (S-9 leaves no role holding `UPDATE` or
 * `DELETE`), which is exactly what an append is and no more. `erp_authlog` is the
 * pre-authentication path's principal and is not reachable here: an approval
 * happens behind `AuthenticationGate`.
 *
 * The connection is taken from the `EntityManagerInterface` the tenancy
 * transaction hands in, rather than as an injected
 * `Doctrine\DBAL\Connection` — that is `erp_app` by the same binding every other
 * read and write in the request uses, and it keeps a bare DBAL connection out of
 * this class's signature (A-10).
 *
 * ===========================================================================
 * Synchronous, and inside the caller's transaction
 * ===========================================================================
 * `API.md` §9.2 and `ARCHITECTURE.md` §6.1: audit is synchronous and its failure
 * must roll back the change it records. `TenantContext::run()` is re-entrant for
 * the same tenant, so calling it here **joins** the transaction
 * `PurchaseOrderApproval` already opened around `place()` — one `COMMIT` covers
 * the status change and the row that says who made it, and neither can survive
 * the other. Nothing is caught: a failed audit write is a failed request, which
 * is the right way round, because the user can approve again and the missing row
 * cannot be reconstructed.
 *
 * There is no asynchronous form and there cannot be one: `symfony/messenger` is
 * not installed (`API.md` §7.5, §11.1).
 *
 * ===========================================================================
 * The parameter list is the control
 * ===========================================================================
 * There is **no `$eventType`** — this class writes one event, exactly as
 * `DenialAuditWriter` and `PasswordChangeAuditWriter` each write one.
 *
 * There is **no `$ipAddress`**, and it is absent structurally rather than passed
 * as null: §9.2's `ip_address` cell for this event is **null**, API-16 restricts
 * the column to *security* events, `AuditEvent::PURCHASE_ORDER_PLACED` is a
 * business event under PC-11 (4a), and E4-5's database `CHECK` refuses a business
 * event carrying one. A method with no parameter for it cannot send one.
 *
 * There is **no `$metadata`**. §9.2 assigns metadata to three events and this is
 * not one of them: `STOCK_MOVEMENT_ADJUSTED` carries the operator's `note` and
 * `PURCHASE_ORDER_CANCELLED` will carry the cancellation reason, because
 * `stock_movement` and `purchase_order` have no free-text column for either.
 * An approval has no such words — F-2 step 8 has no reason field — so the column
 * is written as a literal empty object and there is no parameter that could fill
 * it. `metadata` is `jsonb` on an append-only table no role can `UPDATE` or
 * `DELETE`, so a value written there is written permanently.
 *
 * **No `permission_code` either.** `audit_log_permission_code_scope` permits a
 * non-null code only on `authz.permission.denied`, so writing one here would
 * raise `23514`; there is nothing to write and no parameter that could.
 *
 * ===========================================================================
 * The subject is the order, and it is declared rather than chosen
 * ===========================================================================
 * `AuditEvent::SUBJECT_TYPES[PURCHASE_ORDER_PLACED]` is `'purchase_order'`, so
 * the pair `(subject_type, subject_id)` is what L-7's History section on
 * `/purchase-orders/[id]` queries on — and it is why `$subjectId` is the one
 * identifier this method takes beyond the actor.
 *
 * @see SECURITY_MODEL.md §5 E-19
 * @see \App\SharedKernel\ValueObject\AuditEvent::PURCHASE_ORDER_PLACED
 */
final readonly class PurchaseOrderAuditWriter
{
    public function __construct(private TenantContext $tenants)
    {
    }

    /**
     * Scalars rather than an `App\Platform\Security\Identity`, for the reason
     * `DenialAuditWriter` gives: `AuditLog` does not acquire a dependency on the
     * HTTP layer, and the HTTP caller is only the first one — the reorder agent
     * (F-4) is the second.
     *
     * `$actorLabel` is `app_user.display_name`, captured by the caller.
     * `audit_log.actor_label` is `NOT NULL` because the trail has to stay
     * readable after the principal is deleted — which is exactly what makes
     * F-2 step 8's *"recorded against this manager by name"* durable.
     */
    public function recordPlaced(string $tenantId, string $actorUserId, string $actorLabel, string $purchaseOrderId): void
    {
        $this->tenants->run($tenantId, static function (EntityManagerInterface $em) use ($tenantId, $actorUserId, $actorLabel, $purchaseOrderId): void {
            $em->getConnection()->executeStatement(<<<'SQL'
                INSERT INTO audit_log
                    (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label,
                     subject_type, subject_id, ip_address, metadata)
                VALUES
                    (?::uuid, ?::uuid, now(), ?, ?::uuid, ?, ?, ?::uuid, NULL, '{}'::jsonb)
                SQL, [
                    Uuid::v4()->toRfc4122(),
                    $tenantId,
                    AuditEvent::PURCHASE_ORDER_PLACED,
                    $actorUserId,
                    $actorLabel,
                    // Read off the catalogue rather than written as a literal:
                    // the subject type and the event name are declared together
                    // in `AuditEvent`, and `audit_log_subject_pair` requires them
                    // to arrive together.
                    AuditEvent::SUBJECT_TYPES[AuditEvent::PURCHASE_ORDER_PLACED],
                    $purchaseOrderId,
                ]);
        });
    }
}

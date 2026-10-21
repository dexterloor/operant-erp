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
 * There is **no `$eventType`** — this class writes the two `purchase_order.*`
 * events and each has a method of its own, so no caller can choose one. That is
 * `DenialAuditWriter`'s and `PasswordChangeAuditWriter`'s shape with two members
 * rather than one: what the rule forbids is a *parameter* naming the event, not
 * a class covering a table.
 *
 * There is **no `$ipAddress`**, and it is absent structurally rather than passed
 * as null: §9.2's `ip_address` cell for this event is **null**, API-16 restricts
 * the column to *security* events, `AuditEvent::PURCHASE_ORDER_PLACED` is a
 * business event under PC-11 (4a), and E4-5's database `CHECK` refuses a business
 * event carrying one. A method with no parameter for it cannot send one.
 *
 * `recordPlaced()` takes **no `$metadata`**, and the asymmetry with
 * `recordCancelled()` below is the whole of §9.2's metadata column rather than an
 * inconsistency. An approval has no operator words — F-2 step 8 has no reason
 * field — so the column is written as a literal empty object and there is no
 * parameter that could fill it. A cancellation **does**: §4.2 accepts a `reason`
 * on it, and `purchase_order` has no free-text column for one, which is exactly
 * the position `stock_movement` is in for `STOCK_MOVEMENT_ADJUSTED`'s `note`.
 * `metadata` is `jsonb` on an append-only table no role can `UPDATE` or
 * `DELETE`, so a value written there is written permanently — which is why the
 * parameter exists on one method and cannot be reached from the other.
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

    /**
     * **`ADR-008` Amendment 1 / E-19 — the other end of the commitment
     * `recordPlaced()` records.**
     *
     * §2's table puts `purchase_order.cancel` on SoD-1's controlled half beside
     * `.approve`, which is what makes this a controlled transition rather than an
     * ordinary edit, and `AuditEvent::PURCHASE_ORDER_CANCELLED` files it
     * **`BUSINESS`** under PC-11 (4a): it is about the subject row's own business
     * state and about no principal's credentials, sessions or privileges. A trail
     * that showed a colleague the placing and hid the cancelling would answer
     * *"what happened to this order"* with half of it.
     *
     * **`$reason` is the one value on this table that is not server-generated**,
     * and it is handled as §9.2 handles `CreateStockAdjustment.note`: stored as a
     * jsonb **string value**, under a single key, never interpolated into
     * anything. It is bound as a parameter and cast — the JSON is built by
     * Postgres from a bound string rather than assembled in PHP, so there is no
     * spelling of a reason that can change the shape of the object it lands in.
     *
     * **Null is written as an empty object, not as `{"reason": null}`.** A key
     * carrying a null would be a permanent record asserting that a reason field
     * exists and is empty; the absence of the key is the honest statement that
     * none was given, and it is the same `{}` every unmetadata'd event writes.
     *
     * **No `ip_address`, structurally rather than as a null argument** —
     * `recordPlaced()`'s reason applies unchanged: API-16 restricts the column to
     * security events, this is a business event, and `audit_log_ip_address_scope`
     * refuses one at the database. A method with no parameter for it cannot send
     * one.
     *
     * @param string|null $reason the operator's words, or null when none were given
     */
    public function recordCancelled(string $tenantId, string $actorUserId, string $actorLabel, string $purchaseOrderId, ?string $reason = null): void
    {
        $this->tenants->run($tenantId, static function (EntityManagerInterface $em) use ($tenantId, $actorUserId, $actorLabel, $purchaseOrderId, $reason): void {
            $em->getConnection()->executeStatement(<<<'SQL'
                INSERT INTO audit_log
                    (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label,
                     subject_type, subject_id, ip_address, metadata)
                VALUES
                    (?::uuid, ?::uuid, now(), ?, ?::uuid, ?, ?, ?::uuid, NULL,
                     CASE WHEN ?::text IS NULL THEN '{}'::jsonb ELSE jsonb_build_object('reason', ?::text) END)
                SQL, [
                    Uuid::v4()->toRfc4122(),
                    $tenantId,
                    AuditEvent::PURCHASE_ORDER_CANCELLED,
                    $actorUserId,
                    $actorLabel,
                    // Read off the catalogue rather than written as a literal:
                    // the subject type and the event name are declared together
                    // in `AuditEvent`, and `audit_log_subject_pair` requires them
                    // to arrive together.
                    AuditEvent::SUBJECT_TYPES[AuditEvent::PURCHASE_ORDER_CANCELLED],
                    $purchaseOrderId,
                    $reason,
                    $reason,
                ]);
        });
    }

    /**
     * `purchase_order.deleted` — B-4a / `RESIDUAL_GAPS.md` G-9 (roles slice 2, C1, 2026-09-29), written by
     * `PurchaseOrderWriter::archive()` (`DeletePurchaseOrder`) in the archiving transaction, with its sales twin.
     * Business, `metadata` `{}`, no IP (`SEC`, `docs/session13/roles-s2-sec.md` §3) — no parameter could send either.
     */
    public function recordDeleted(string $tenantId, string $actorUserId, string $actorLabel, string $purchaseOrderId): void
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
                    AuditEvent::PURCHASE_ORDER_DELETED,
                    $actorUserId,
                    $actorLabel,
                    // Read off the catalogue: `audit_log_subject_pair` requires the pair to arrive together.
                    AuditEvent::SUBJECT_TYPES[AuditEvent::PURCHASE_ORDER_DELETED],
                    $purchaseOrderId,
                ]);
        });
    }

    /**
     * `purchase_order.restored` — `RestorePurchaseOrder` (order restores C3, 2026-10-21). Business, subject the order, `{}`,
     * no IP: `recordDeleted()`'s shape, its inverse. Called by `PurchaseOrderWriter::restore()` after the UPDATE and the
     * recompute, inside the restoring transaction, so a refused restore — including a 23505 — leaves no row.
     */
    public function recordRestored(string $tenantId, string $actorUserId, string $actorLabel, string $purchaseOrderId): void
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
                    AuditEvent::PURCHASE_ORDER_RESTORED,
                    $actorUserId,
                    $actorLabel,
                    AuditEvent::SUBJECT_TYPES[AuditEvent::PURCHASE_ORDER_RESTORED],
                    $purchaseOrderId,
                ]);
        });
    }
}

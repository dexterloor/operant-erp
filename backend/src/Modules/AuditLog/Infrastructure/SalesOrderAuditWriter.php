<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Infrastructure;

use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\ValueObject\AuditEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **The first writer of a `sales_order.*` audit row this project has ever had**
 * — `CancelSalesOrder`, 2026-09-19.
 *
 * **Since 2026-09-29 (roles slice 2, C1 — B-4a) it also writes `sales_order.confirmed`
 * and `sales_order.deleted`**, so the asymmetry the next section records is history:
 * it explains why the cancellation shipped first, not a present difference.
 *
 * ===========================================================================
 * Why this row was written when `ConfirmSalesOrder`'s was not (until B-4a)
 * ===========================================================================
 * `ConfirmSalesOrder` shipped earlier the same day and wrote nothing, deferred
 * to `DELIVERY_LEDGER.md` **B-4a**. The asymmetry was a rule, not a preference,
 * and it is `ADR-008` §2's: declining the sales duty split — `sales_rep` holds
 * both `sales_order.confirm` and `sales_order.cancel`, where the buying side
 * splits the equivalent pair — it names the compensation in the same sentence.
 *
 * > *"This is the weaker of the two controls and it is knowingly weaker — a rep
 * > who cancels confirmed orders can conceal lost sales, and **the compensating
 * > control is `audit_log`, not a second pair of hands.**"*
 *
 * So this row **is** the control. There is no `SalesOrderDutySeparation` and
 * there must not be one (see `SalesOrder::cancel()`'s docblock, which carries the
 * quotation and ADR-008 Amendment 1's purchase-only predicate). ADR-008 says
 * nothing of the kind about confirming — on its own reasoning confirming
 * *"moves nothing out"*.
 *
 * ===========================================================================
 * `erp_app`, and no new grant
 * ===========================================================================
 * Measured with `has_table_privilege()` before this class was written:
 * `erp_app` already holds `INSERT` on `audit_log`. S-9 leaves no role holding
 * `UPDATE` or `DELETE` on it, which is exactly what an append is and no more, so
 * **no migration in this commit touches a privilege** and ADR-002 A4.4 duty 1's
 * *"exactly"* is satisfied by the footprint not moving.
 *
 * The connection is taken from the `EntityManagerInterface` the tenancy
 * transaction hands in rather than as an injected `Doctrine\DBAL\Connection` —
 * that is `erp_app` by the same binding every other read and write in the request
 * uses, and it keeps a bare DBAL connection out of this class's signature (A-10).
 * An unbound autowired `Connection` is the **schema owner**, measured 2026-09-14.
 *
 * ===========================================================================
 * Synchronous, and inside the caller's transaction
 * ===========================================================================
 * `API.md` §9.2 and `ARCHITECTURE.md` §6.1: audit is synchronous and its failure
 * must roll back the change it records. `TenantContext::run()` is re-entrant for
 * the same tenant, so calling it here **joins** the transaction
 * `SalesOrderCancellation` already opened around `cancel()` — one `COMMIT` covers
 * the status change and the row that says who made it, and neither can survive
 * the other. Nothing is caught: a failed audit write is a failed request, which
 * is the right way round here more than anywhere, because the row is the control
 * and a cancellation that committed without it would be the unwitnessed act
 * ADR-008 accepted only on the strength of the trail.
 *
 * There is no asynchronous form and there cannot be one: `symfony/messenger` is
 * not installed (`API.md` §7.5, §11.1).
 *
 * ===========================================================================
 * A separate class from `PurchaseOrderAuditWriter`, deliberately
 * ===========================================================================
 * One class per subject table, which is the shape `PurchaseOrderAuditWriter`,
 * `DenialAuditWriter` and `PasswordChangeAuditWriter` already have. Widening the
 * purchase writer to take a table would reintroduce the **`$eventType`
 * parameter** its own docblock refuses: *"what the rule forbids is a parameter
 * naming the event, not a class covering a table."* One method per event, no
 * parameter that selects one, so no caller can choose an event it was not
 * written for.
 *
 * ===========================================================================
 * The parameter list is the control
 * ===========================================================================
 * There is **no `$ipAddress`**, and it is absent structurally rather than passed
 * as null: API-16 restricts the column to *security* events,
 * `AuditEvent::SALES_ORDER_CANCELLED` is a `BUSINESS` event under PC-11 (4a), and
 * `audit_log_ip_address_scope` refuses a business event carrying one. A method
 * with no parameter for it cannot send one — which is also why this commit's
 * migration is forbidden from touching that constraint (step 6 does not apply).
 *
 * **No `permission_code` either.** `audit_log_permission_code_scope` permits a
 * non-null code only on `authz.permission.denied`, so writing one here would
 * raise `23514`; there is nothing to write and no parameter that could.
 *
 * ===========================================================================
 * The subject is the order, and it is declared rather than chosen
 * ===========================================================================
 * `AuditEvent::SUBJECT_TYPES[SALES_ORDER_CANCELLED]` is `'sales_order'`, read off
 * the catalogue rather than written here as a literal — the subject type and the
 * event name are declared together in `AuditEvent`, and `audit_log_subject_pair`
 * requires them to arrive together. That pair is what L-7's History section on
 * `/sales-orders/[id]` queries on.
 *
 * @see docs/adr/ADR-008-separation-of-duties.md §2 for the sales asymmetry and this row's standing as the compensating control
 * @see docs/API.md §8.3's OI-2 block, §9.2
 * @see \App\SharedKernel\ValueObject\AuditEvent::SALES_ORDER_CANCELLED
 */
final readonly class SalesOrderAuditWriter
{
    public function __construct(private TenantContext $tenants)
    {
    }

    /**
     * **OI-2's `sales_order.cancelled`, and the row ADR-008 §2 leans the whole
     * sales cancellation control on.**
     *
     * Scalars rather than an `App\Platform\Security\Identity`, for the reason
     * `PurchaseOrderAuditWriter` and `DenialAuditWriter` both give: `AuditLog`
     * does not acquire a dependency on the HTTP layer, and the HTTP caller is
     * only the first one.
     *
     * `$actorLabel` is `app_user.display_name`, captured by the caller.
     * `audit_log.actor_label` is `NOT NULL` because the trail has to stay
     * readable after the principal is deleted — which is what makes *"who
     * cancelled this order"* durable, and on this operation that durability is
     * the control rather than a convenience. **`sales_order` itself cannot answer
     * the question**: it has no canceller column, and `created_by_user_id` records
     * the creator, not whoever moved the status (SEC-O-9).
     *
     * **`$reason` is the one value on this table that is not server-generated**,
     * and it is handled as §9.2 handles `CreateStockAdjustment.note` and
     * `PURCHASE_ORDER_CANCELLED`'s own reason: stored as a jsonb **string value**,
     * under a single key, never interpolated into anything. It is bound as a
     * parameter and cast — the JSON is built by Postgres from a bound string
     * rather than assembled in PHP, so there is no spelling of a reason that can
     * change the shape of the object it lands in.
     *
     * **Null is written as an empty object, not as `{"reason": null}`.** A key
     * carrying a null would be a permanent record asserting that a reason field
     * exists and is empty; the absence of the key is the honest statement that
     * none was given, and it is the same `{}` every unmetadata'd event writes.
     *
     * @param string|null $reason the operator's words, or null when none were given
     */
    public function recordCancelled(string $tenantId, string $actorUserId, string $actorLabel, string $salesOrderId, ?string $reason = null): void
    {
        $this->tenants->run($tenantId, static function (EntityManagerInterface $em) use ($tenantId, $actorUserId, $actorLabel, $salesOrderId, $reason): void {
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
                    AuditEvent::SALES_ORDER_CANCELLED,
                    $actorUserId,
                    $actorLabel,
                    // Read off the catalogue rather than written as a literal:
                    // the subject type and the event name are declared together
                    // in `AuditEvent`, and `audit_log_subject_pair` requires them
                    // to arrive together.
                    AuditEvent::SUBJECT_TYPES[AuditEvent::SALES_ORDER_CANCELLED],
                    $salesOrderId,
                    $reason,
                    $reason,
                ]);
        });
    }

    /**
     * `sales_order.confirmed` — B-4a (roles slice 2, C1, 2026-09-29), written by `SalesOrderConfirmation` in the
     * confirming transaction. `$actorUserId` is the confirmer, whom no column records (SEC-O-9).
     * Business, `metadata` `{}`, no IP (`SEC`, `docs/session13/roles-s2-sec.md` §3) — no parameter could send either.
     */
    public function recordConfirmed(string $tenantId, string $actorUserId, string $actorLabel, string $salesOrderId): void
    {
        $this->tenants->run($tenantId, static function (EntityManagerInterface $em) use ($tenantId, $actorUserId, $actorLabel, $salesOrderId): void {
            $em->getConnection()->executeStatement(<<<'SQL'
                INSERT INTO audit_log
                    (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label,
                     subject_type, subject_id, ip_address, metadata)
                VALUES
                    (?::uuid, ?::uuid, now(), ?, ?::uuid, ?, ?, ?::uuid, NULL, '{}'::jsonb)
                SQL, [
                    Uuid::v4()->toRfc4122(),
                    $tenantId,
                    AuditEvent::SALES_ORDER_CONFIRMED,
                    $actorUserId,
                    $actorLabel,
                    // Read off the catalogue: `audit_log_subject_pair` requires the pair to arrive together.
                    AuditEvent::SUBJECT_TYPES[AuditEvent::SALES_ORDER_CONFIRMED],
                    $salesOrderId,
                ]);
        });
    }

    /**
     * `sales_order.deleted` — B-4a / `RESIDUAL_GAPS.md` G-9 (roles slice 2, C1, 2026-09-29), written by
     * `SalesOrderWriter::archive()` (`DeleteSalesOrder`) in the archiving transaction, with its purchase twin.
     * Business, `metadata` `{}`, no IP (`SEC`, `docs/session13/roles-s2-sec.md` §3) — no parameter could send either.
     */
    public function recordDeleted(string $tenantId, string $actorUserId, string $actorLabel, string $salesOrderId): void
    {
        $this->tenants->run($tenantId, static function (EntityManagerInterface $em) use ($tenantId, $actorUserId, $actorLabel, $salesOrderId): void {
            $em->getConnection()->executeStatement(<<<'SQL'
                INSERT INTO audit_log
                    (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label,
                     subject_type, subject_id, ip_address, metadata)
                VALUES
                    (?::uuid, ?::uuid, now(), ?, ?::uuid, ?, ?, ?::uuid, NULL, '{}'::jsonb)
                SQL, [
                    Uuid::v4()->toRfc4122(),
                    $tenantId,
                    AuditEvent::SALES_ORDER_DELETED,
                    $actorUserId,
                    $actorLabel,
                    // Read off the catalogue: `audit_log_subject_pair` requires the pair to arrive together.
                    AuditEvent::SUBJECT_TYPES[AuditEvent::SALES_ORDER_DELETED],
                    $salesOrderId,
                ]);
        });
    }

    /**
     * `sales_order.restored` — `RestoreSalesOrder` (order restores C2, 2026-10-21). Business, subject the order, `{}`,
     * no IP: `recordDeleted()`'s shape, its inverse. Called by `SalesOrderWriter::restore()` after the UPDATE, inside the
     * restoring transaction (`TenantContext::run()` is re-entrant for the same tenant), so a refused restore — including
     * a 23505 from the UPDATE or the cascade trigger — leaves no row.
     */
    public function recordRestored(string $tenantId, string $actorUserId, string $actorLabel, string $salesOrderId): void
    {
        $this->tenants->run($tenantId, static function (EntityManagerInterface $em) use ($tenantId, $actorUserId, $actorLabel, $salesOrderId): void {
            $em->getConnection()->executeStatement(<<<'SQL'
                INSERT INTO audit_log
                    (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label,
                     subject_type, subject_id, ip_address, metadata)
                VALUES
                    (?::uuid, ?::uuid, now(), ?, ?::uuid, ?, ?, ?::uuid, NULL, '{}'::jsonb)
                SQL, [
                    Uuid::v4()->toRfc4122(),
                    $tenantId,
                    AuditEvent::SALES_ORDER_RESTORED,
                    $actorUserId,
                    $actorLabel,
                    AuditEvent::SUBJECT_TYPES[AuditEvent::SALES_ORDER_RESTORED],
                    $salesOrderId,
                ]);
        });
    }
}

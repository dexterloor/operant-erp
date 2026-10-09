<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Infrastructure;

use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\ValueObject\AuditEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **The writer of `customer.created` and `customer.updated`** — customers slice 1 C2, 2026-10-09
 * (`docs/session13/customers-s1-g35-judge.md` FINAL items 1–3; `RESIDUAL_GAPS.md` G-35).
 *
 * The rows are what names the person who set a customer's payment terms: `sales_rep` holds `customer.create`,
 * `customer.edit` **and** `invoice.issue`, and nothing prevents one person from setting terms of up to ten years and
 * issuing that customer's invoice (G-35). **Detection, not prevention** — and evidence against an in-app actor only, since
 * SQL as `erp_app` can change `customer` with no row (A-3's shape).
 *
 * `SalesOrderAuditWriter`'s shape, for its reasons, which are not restated here: one method per event and no parameter
 * selecting one; scalars, not an `Identity` (`AuditLog` takes no dependency on the HTTP layer); the connection is the one
 * the caller's `TenantContext::run()` hands in (`erp_app`, never an unbound autowired `Connection`, which is the schema
 * owner — A-10), and `run()` is re-entrant for the same tenant, so the row **joins** the write's transaction and neither
 * can commit without the other. Nothing is caught.
 *
 * **The parameter lists are the control.** Both events are **business** under PC-11 (4a) — a delegated filing by the G-35
 * judge in the `SEC` role (`customers-s1-g35-judge.md` C-4; `SEC` to confirm) — so there is **no `$ipAddress`**
 * (`audit_log_ip_address_scope` refuses one on a business event) and no `permission_code`. `recordCreated()` takes no
 * metadata at all (`{}`). `recordUpdated()` takes only the **names** of the changed fields: it builds
 * `{"fields": [...]}` itself, sorted and de-duplicated, and refuses an empty list, because "no field changed" writes no
 * row (judge FINAL item 2). **Never values**: a list of column names is sourced from no table, so PC-11's metadata clause
 * and PC-15 condition 6 are met by construction; an old or new name, or a term, cannot reach `metadata` through this
 * class.
 *
 * The subject is the customer, read off `AuditEvent::SUBJECT_TYPES` rather than written as a literal
 * (`audit_log_subject_pair` requires the pair to arrive together).
 *
 * @see \App\SharedKernel\ValueObject\AuditEvent::CUSTOMER_CREATED
 * @see \App\SharedKernel\ValueObject\AuditEvent::CUSTOMER_UPDATED
 */
final readonly class CustomerAuditWriter
{
    /** The only names `customer.updated` may carry: `UpdateCustomer`'s five accepted wire fields (§4.2). */
    private const FIELDS = ['code', 'country_code', 'currency_code', 'name', 'payment_terms_days'];

    public function __construct(private TenantContext $tenants)
    {
    }

    /** `customer.created` — `CreateCustomer`, after its INSERT, in its transaction. `metadata` `{}`. */
    public function recordCreated(string $tenantId, string $actorUserId, string $actorLabel, string $customerId): void
    {
        $this->record($tenantId, $actorUserId, $actorLabel, AuditEvent::CUSTOMER_CREATED, $customerId, '{}');
    }

    /**
     * `customer.updated` — `UpdateCustomer`, after its UPDATE, in its transaction: `{"fields": [...]}`, the wire names of
     * the fields whose submitted value differs from the locked row, sorted ascending, never values.
     *
     * @param list<string> $fields
     */
    public function recordUpdated(string $tenantId, string $actorUserId, string $actorLabel, string $customerId, array $fields): void
    {
        if ([] === $fields) {
            throw new \LogicException('customer.updated names the fields that changed; an update that changed nothing writes no row.');
        }
        foreach ($fields as $field) {
            if (!\in_array($field, self::FIELDS, true)) {
                throw new \LogicException(\sprintf('"%s" is not a field UpdateCustomer accepts, so customer.updated cannot name it.', $field));
            }
        }
        $fields = array_values(array_unique($fields));
        sort($fields);

        $this->record($tenantId, $actorUserId, $actorLabel, AuditEvent::CUSTOMER_UPDATED, $customerId,
            json_encode(['fields' => $fields], \JSON_THROW_ON_ERROR));
    }

    private function record(string $tenantId, string $actorUserId, string $actorLabel, string $event, string $customerId, string $metadata): void
    {
        $this->tenants->run($tenantId, static function (EntityManagerInterface $em) use ($tenantId, $actorUserId, $actorLabel, $event, $customerId, $metadata): void {
            $em->getConnection()->executeStatement(<<<'SQL'
                INSERT INTO audit_log
                    (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label,
                     subject_type, subject_id, ip_address, metadata)
                VALUES
                    (?::uuid, ?::uuid, now(), ?, ?::uuid, ?, ?, ?::uuid, NULL, ?::jsonb)
                SQL, [
                    Uuid::v4()->toRfc4122(),
                    $tenantId,
                    $event,
                    $actorUserId,
                    $actorLabel,
                    // Read off the catalogue: `audit_log_subject_pair` requires the pair to arrive together.
                    AuditEvent::SUBJECT_TYPES[$event],
                    $customerId,
                    $metadata,
                ]);
        });
    }
}

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
 * (`audit_log_ip_address_scope` refuses one on a business event) and no `permission_code`. `recordRestored()` (C5 C4a)
 * and `recordDeleted()` (C5 C4b, `DeleteCustomer`) take no metadata at all (`{}`).
 *
 * **Names only, except the terms value** (G-35 (g), `docs/session13/audit-reads-owner-q-ruling.md` §2 / §5 C0a, judge
 * 2026-10-20 under the owner's delegation; it reverses `customers-s1-g35-judge.md`'s "names only" for this one field).
 * Field names alone cannot show how long a term was set to, and the invoice issuer reads terms live, so "raise to
 * 3650, issue, put back to 30" left two ordinary `["payment_terms_days"]` rows and nothing showing the ten years.
 *  - `recordCreated()` writes `{"payment_terms_days": n}`, where `n` is the value the INSERT stored — the caller passes
 *    `INSERT … RETURNING payment_terms_days`, never a PHP copy of the column default.
 *  - `recordUpdated()` builds `{"fields": [...]}` itself, sorted and de-duplicated, and refuses an empty list, because
 *    "no field changed" writes no row (judge FINAL item 2). When terms changed it adds
 *    `"payment_terms_days": {"from": a, "to": b}`, `from` being the **locked row's** value, and it refuses a pair
 *    without the name in `fields`, or the name without a pair (`LogicException`): the two cannot drift apart.
 *  - **No other value**: an old or new name, code, country or currency cannot reach `metadata` through this class. The
 *    terms value comes from the subject row itself, so PC-11's metadata clause (no field sourced from a table the reader
 *    has no `.view` on) and PC-15 condition 6 still hold; it is an integer, so no scale-4 decimal and no PII.
 *  - **The supplier is not mirrored** (G-19 decision, ruling §2.5): supplier terms are credit the tenant receives, and a
 *    long supplier term favours the tenant, so `SupplierAuditWriter` stays names-only.
 *
 * The subject is the customer, read off `AuditEvent::SUBJECT_TYPES` rather than written as a literal
 * (`audit_log_subject_pair` requires the pair to arrive together).
 *
 * @see \App\SharedKernel\ValueObject\AuditEvent::CUSTOMER_CREATED
 * @see \App\SharedKernel\ValueObject\AuditEvent::CUSTOMER_UPDATED
 * @see \App\SharedKernel\ValueObject\AuditEvent::CUSTOMER_RESTORED
 * @see \App\SharedKernel\ValueObject\AuditEvent::CUSTOMER_DELETED
 */
final readonly class CustomerAuditWriter
{
    /** The only names `customer.updated` may carry: `UpdateCustomer`'s five accepted wire fields (§4.2). */
    private const FIELDS = ['code', 'country_code', 'currency_code', 'name', 'payment_terms_days'];

    public function __construct(private TenantContext $tenants)
    {
    }

    /**
     * `customer.created` — `CreateCustomer`, after its INSERT, in its transaction. `metadata` `{"payment_terms_days": n}`,
     * `n` read back from the INSERT's `RETURNING` (G-35 (g)), so an omitted field records the column default as stored.
     */
    public function recordCreated(string $tenantId, string $actorUserId, string $actorLabel, string $customerId, int $paymentTermsDays): void
    {
        $this->record($tenantId, $actorUserId, $actorLabel, AuditEvent::CUSTOMER_CREATED, $customerId,
            json_encode(['payment_terms_days' => $paymentTermsDays], \JSON_THROW_ON_ERROR));
    }

    /**
     * `customer.updated` — `UpdateCustomer`, after its UPDATE, in its transaction: `{"fields": [...]}`, the wire names of
     * the fields whose submitted value differs from the locked row, sorted ascending; plus, when terms changed,
     * `"payment_terms_days": {"from": a, "to": b}` (G-35 (g)) — the only value this event carries.
     *
     * @param list<string>                 $fields
     * @param array{from: int, to: int}|null $paymentTerms the locked row's terms and the submitted ones; set exactly when
     *                                                     `payment_terms_days` is in `$fields`
     */
    public function recordUpdated(string $tenantId, string $actorUserId, string $actorLabel, string $customerId, array $fields, ?array $paymentTerms = null): void
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

        $termsChanged = \in_array('payment_terms_days', $fields, true);
        if ($termsChanged && null === $paymentTerms) {
            throw new \LogicException('customer.updated names payment_terms_days but was given no {from, to}: the terms value is what G-35 (g) records.');
        }
        if (!$termsChanged && null !== $paymentTerms) {
            throw new \LogicException('customer.updated was given a terms {from, to} but does not name payment_terms_days: only a terms change carries the value.');
        }

        $metadata = ['fields' => $fields];
        if (null !== $paymentTerms) {
            $metadata['payment_terms_days'] = ['from' => $paymentTerms['from'], 'to' => $paymentTerms['to']];
        }

        $this->record($tenantId, $actorUserId, $actorLabel, AuditEvent::CUSTOMER_UPDATED, $customerId,
            json_encode($metadata, \JSON_THROW_ON_ERROR));
    }

    /** `customer.restored` — `RestoreCustomer` (C5 C4a), after its UPDATE, in its transaction. `metadata` `{}`. */
    public function recordRestored(string $tenantId, string $actorUserId, string $actorLabel, string $customerId): void
    {
        $this->record($tenantId, $actorUserId, $actorLabel, AuditEvent::CUSTOMER_RESTORED, $customerId, '{}');
    }

    /** `customer.deleted` — `DeleteCustomer` (C5 C4b), after its UPDATE, in its transaction. `metadata` `{}`. */
    public function recordDeleted(string $tenantId, string $actorUserId, string $actorLabel, string $customerId): void
    {
        $this->record($tenantId, $actorUserId, $actorLabel, AuditEvent::CUSTOMER_DELETED, $customerId, '{}');
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

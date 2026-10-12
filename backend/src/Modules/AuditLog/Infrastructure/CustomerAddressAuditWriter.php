<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Infrastructure;

use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\ValueObject\AuditEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **The writer of `customer_address.created` and `customer_address.updated`** — suppliers slice 1 C2b, 2026-10-12 (owner
 * ruling F-12 / S4 "Record address changes", `docs/session13/suppliers-s1-ruling.md`; `owed-roles-recs.md` S4 (a)).
 *
 * The rows name the person who added or re-pointed a customer's address: `sales_rep` and `admin` hold `customer.edit`,
 * and without them one seat could move a customer's shipping address to a place it controls, an innocent colleague's
 * order would ship there, and the only names on record would be the colleague's (`sales_order.created_by_user_id`,
 * `sales_order.confirmed`). **Detection, not prevention** — and evidence against an in-app actor only, since SQL as
 * `erp_app` can change `customer_address` with no row (A-3's shape).
 *
 * {@see CustomerAuditWriter}'s shape, for its reasons, which are not restated here: one method per event; scalars, not
 * an `Identity`; the connection is the one the caller's `TenantContext::run()` hands in, so the row joins the write's
 * transaction (A-10). Nothing is caught.
 *
 * **The parameter lists are the control.** Both events are **business** under PC-11 (4a), so there is **no
 * `$ipAddress`** and no `permission_code`. `recordCreated()` takes no metadata (`{}`). `recordUpdated()` takes only the
 * **names** of the changed fields, builds `{"fields": [...]}` itself, sorted and de-duplicated, refuses an empty list
 * ("no field changed" writes no row) and refuses any name `UpdateCustomerAddress` does not accept. **Never values**: an
 * address is personal data, and a line, a city or a postal code cannot reach `metadata` through this class.
 *
 * The subject is the address row, read off `AuditEvent::SUBJECT_TYPES` (`customer_address`; `SEC` to confirm).
 *
 * @see \App\SharedKernel\ValueObject\AuditEvent::CUSTOMER_ADDRESS_CREATED
 * @see \App\SharedKernel\ValueObject\AuditEvent::CUSTOMER_ADDRESS_UPDATED
 */
final readonly class CustomerAddressAuditWriter
{
    /** The only names `customer_address.updated` may carry: `UpdateCustomerAddress`'s five accepted wire fields (§4.2). */
    private const FIELDS = ['city', 'country_code', 'kind', 'line', 'postal_code'];

    public function __construct(private TenantContext $tenants)
    {
    }

    /** `customer_address.created` — `CreateCustomerAddress`, after its INSERT, in its transaction. `metadata` `{}`. */
    public function recordCreated(string $tenantId, string $actorUserId, string $actorLabel, string $addressId): void
    {
        $this->record($tenantId, $actorUserId, $actorLabel, AuditEvent::CUSTOMER_ADDRESS_CREATED, $addressId, '{}');
    }

    /**
     * `customer_address.updated` — `UpdateCustomerAddress`, after its UPDATE, in its transaction: `{"fields": [...]}`,
     * the wire names of the fields whose submitted value differs from the locked row, sorted ascending, never values.
     *
     * @param list<string> $fields
     */
    public function recordUpdated(string $tenantId, string $actorUserId, string $actorLabel, string $addressId, array $fields): void
    {
        if ([] === $fields) {
            throw new \LogicException('customer_address.updated names the fields that changed; an update that changed nothing writes no row.');
        }
        foreach ($fields as $field) {
            if (!\in_array($field, self::FIELDS, true)) {
                throw new \LogicException(\sprintf('"%s" is not a field UpdateCustomerAddress accepts, so customer_address.updated cannot name it.', $field));
            }
        }
        $fields = array_values(array_unique($fields));
        sort($fields);

        $this->record($tenantId, $actorUserId, $actorLabel, AuditEvent::CUSTOMER_ADDRESS_UPDATED, $addressId,
            json_encode(['fields' => $fields], \JSON_THROW_ON_ERROR));
    }

    private function record(string $tenantId, string $actorUserId, string $actorLabel, string $event, string $addressId, string $metadata): void
    {
        $this->tenants->run($tenantId, static function (EntityManagerInterface $em) use ($tenantId, $actorUserId, $actorLabel, $event, $addressId, $metadata): void {
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
                    $addressId,
                    $metadata,
                ]);
        });
    }
}

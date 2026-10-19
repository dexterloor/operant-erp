<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Infrastructure;

use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\ValueObject\AuditEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **The writer of `customer_contact.created` and `customer_contact.updated`** — customers slice 2 C1, 2026-10-19 (owner
 * Q-2 "yes, record contact changes", `docs/session13/s35-ruling.md`; filed by `SEC`, `customers-s2-sec-arch.md` S-4).
 *
 * The rows name the person who added or changed a customer's contact: re-pointing a customer's e-mail through a contact is
 * the contact twin of re-pointing a ship-to, which {@see CustomerAddressAuditWriter} exists to attribute. **Detection, not
 * prevention** — and evidence against an in-app actor only, since SQL as `erp_app` can change `customer_contact` with no row
 * (A-3's shape).
 *
 * {@see CustomerAddressAuditWriter}'s shape exactly, for its reasons: one method per event; scalars, not an `Identity`; the
 * connection is the one the caller's `TenantContext::run()` hands in, so the row joins the write's transaction (A-10).
 * Nothing is caught.
 *
 * **The parameter lists are the control.** Both events are **business** under PC-11 (4a), so there is **no `$ipAddress`**
 * and no `permission_code`. `recordCreated()` takes no metadata (`{}`). `recordUpdated()` takes only the **names** of the
 * changed fields, builds `{"fields": [...]}` itself, sorted and de-duplicated, refuses an empty list ("no field changed"
 * writes no row) and refuses any name `UpdateCustomerContact` does not accept. **Never values**: a name, an e-mail or a
 * phone cannot reach `metadata` through this class. A contact demoted because another was marked primary (owner Q-1 (a))
 * gets its own `recordUpdated(…, ['is_primary'])`.
 *
 * The subject is the contact row, read off `AuditEvent::SUBJECT_TYPES` (`customer_contact`; `SEC` S-4 confirmed).
 *
 * @see \App\SharedKernel\ValueObject\AuditEvent::CUSTOMER_CONTACT_CREATED
 * @see \App\SharedKernel\ValueObject\AuditEvent::CUSTOMER_CONTACT_UPDATED
 */
final readonly class CustomerContactAuditWriter
{
    /** The only names `customer_contact.updated` may carry: `UpdateCustomerContact`'s four accepted wire fields (§4.2). */
    private const FIELDS = ['email', 'is_primary', 'name', 'phone'];

    public function __construct(private TenantContext $tenants)
    {
    }

    /** `customer_contact.created` — `CreateCustomerContact`, after its INSERT, in its transaction. `metadata` `{}`. */
    public function recordCreated(string $tenantId, string $actorUserId, string $actorLabel, string $contactId): void
    {
        $this->record($tenantId, $actorUserId, $actorLabel, AuditEvent::CUSTOMER_CONTACT_CREATED, $contactId, '{}');
    }

    /**
     * `customer_contact.updated` — after an UPDATE, in its transaction: `{"fields": [...]}`, the wire names of the fields
     * whose value changed, sorted ascending, never values.
     *
     * @param list<string> $fields
     */
    public function recordUpdated(string $tenantId, string $actorUserId, string $actorLabel, string $contactId, array $fields): void
    {
        if ([] === $fields) {
            throw new \LogicException('customer_contact.updated names the fields that changed; an update that changed nothing writes no row.');
        }
        foreach ($fields as $field) {
            if (!\in_array($field, self::FIELDS, true)) {
                throw new \LogicException(\sprintf('"%s" is not a field UpdateCustomerContact accepts, so customer_contact.updated cannot name it.', $field));
            }
        }
        $fields = array_values(array_unique($fields));
        sort($fields);

        $this->record($tenantId, $actorUserId, $actorLabel, AuditEvent::CUSTOMER_CONTACT_UPDATED, $contactId,
            json_encode(['fields' => $fields], \JSON_THROW_ON_ERROR));
    }

    private function record(string $tenantId, string $actorUserId, string $actorLabel, string $event, string $contactId, string $metadata): void
    {
        $this->tenants->run($tenantId, static function (EntityManagerInterface $em) use ($tenantId, $actorUserId, $actorLabel, $event, $contactId, $metadata): void {
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
                    $contactId,
                    $metadata,
                ]);
        });
    }
}

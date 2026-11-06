<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Infrastructure;

use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\ValueObject\AuditEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **The writer of `inventory_item.deleted`, `inventory_item.restored` and `inventory_item.updated`** — G5-C2a, 2026-10-22
 * (`docs/session13/g5-g6-bulk-export-design.md` §5.5). Until this class `DeleteItem` and `RestoreItem` wrote no audit row:
 * who archived an item was recorded nowhere (`updated_at` records when, not who), and the bulk archival's per-member
 * parity (G-50's closing work: *"one audit row per member"*) had nothing to be parity with.
 *
 * `CustomerAuditWriter::recordDeleted()` / `recordRestored()`'s shape, for their reasons, which are not restated here: one
 * method per event and no parameter selecting one; scalars, not an `Identity`; the connection is the one the caller's
 * `TenantContext::run()` hands in (`erp_app`, never an unbound autowired `Connection` — A-10), and `run()` is re-entrant for
 * the same tenant, so the row **joins** the write's transaction and neither can commit without the other. Nothing is
 * caught.
 *
 * **The parameter lists are the control.** Both events are **business** under PC-11 (4a) — filed by the builder on the
 * customer pair's terms, `SEC` to confirm — so there is **no `$ipAddress`** (`audit_log_ip_address_scope` refuses one on a
 * business event), no permission code, and no metadata (`{}`): an archive or a restore changes only `deleted_at`, which the
 * row itself shows as `archived_at`. The subject is read off `AuditEvent::SUBJECT_TYPES`, never written as a literal.
 *
 * **`inventory_item.updated` carries values** (items batch C1, 2026-11-06; BK-25): `WarehouseAuditWriter`'s departure, for
 * its reason — an item rename or SKU change relabels every issued document that names the item (`RESIDUAL_GAPS.md` G-96),
 * and the trail is where the old label survives. Every recorded field is already readable under `inventory_item.view`; none
 * is cost, money or personal data, and `supplier_id` is an id, never a name. **The value allowlist is the control:**
 * {@see self::VALUE_FIELDS} names every field whose value may be recorded, and a field added to `inventory_item` later does
 * not inherit the rule — passing it here throws. One difference from the warehouse writer: `supplier_id` may be `null`
 * (cleared or never set), so it is the one field whose value may be `null`. `SEC` to confirm the values.
 *
 * @see \App\SharedKernel\ValueObject\AuditEvent::INVENTORY_ITEM_DELETED
 * @see \App\SharedKernel\ValueObject\AuditEvent::INVENTORY_ITEM_RESTORED
 * @see \App\SharedKernel\ValueObject\AuditEvent::INVENTORY_ITEM_UPDATED
 */
final readonly class ItemAuditWriter
{
    /**
     * The only fields whose **values** `inventory_item.updated` may carry: `UpdateItem`'s seven accepted wire fields
     * (§4.2), sorted. An explicit list, never "all changed fields".
     */
    public const VALUE_FIELDS = ['name', 'reorder_point', 'reorder_quantity', 'safety_stock', 'sku', 'supplier_id', 'unit_of_measure_code'];

    /** The one allowlisted field whose value may be `null`: an item need not have a supplier, and a PATCH may clear it. */
    public const NULLABLE_VALUE_FIELDS = ['supplier_id'];

    public function __construct(private TenantContext $tenants)
    {
    }

    /** `inventory_item.deleted` — `DeleteItem`, after its one-row UPDATE, in its transaction. `metadata` `{}`. */
    public function recordDeleted(string $tenantId, string $actorUserId, string $actorLabel, string $itemId): void
    {
        $this->record($tenantId, $actorUserId, $actorLabel, AuditEvent::INVENTORY_ITEM_DELETED, $itemId);
    }

    /** `inventory_item.restored` — `RestoreItem`, after its one-row UPDATE, in its transaction. `metadata` `{}`. */
    public function recordRestored(string $tenantId, string $actorUserId, string $actorLabel, string $itemId): void
    {
        $this->record($tenantId, $actorUserId, $actorLabel, AuditEvent::INVENTORY_ITEM_RESTORED, $itemId);
    }

    /**
     * `inventory_item.updated` — `UpdateItem`, after its UPDATE, in its transaction: `{"fields": [...], "from": {...},
     * "to": {...}}`, the changed fields' wire names (sorted) and their old and new served values. `$from` and `$to` name the
     * same fields; an update that changed nothing writes no row, so an empty change is refused here.
     *
     * @param array<string, string|null> $from
     * @param array<string, string|null> $to
     */
    public function recordUpdated(string $tenantId, string $actorUserId, string $actorLabel, string $itemId, array $from, array $to): void
    {
        $fields = array_keys($from);
        sort($fields);
        $toFields = array_keys($to);
        sort($toFields);
        if ([] === $fields) {
            throw new \LogicException('inventory_item.updated names the fields that changed; an update that changed nothing writes no row.');
        }
        if ($fields !== $toFields) {
            throw new \LogicException('inventory_item.updated records the old and new value of the same fields.');
        }

        $this->record($tenantId, $actorUserId, $actorLabel, AuditEvent::INVENTORY_ITEM_UPDATED, $itemId,
            json_encode(['fields' => $fields, 'from' => self::checked($from), 'to' => self::checked($to)], \JSON_THROW_ON_ERROR));
    }

    /**
     * Refuses any field outside {@see self::VALUE_FIELDS}, a `null` outside {@see self::NULLABLE_VALUE_FIELDS} and any other
     * non-string value, and sorts by field name.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, string|null>
     */
    private static function checked(array $values): array
    {
        foreach ($values as $field => $value) {
            if (!\in_array($field, self::VALUE_FIELDS, true)) {
                throw new \LogicException(\sprintf('"%s" is not on the item value allowlist, so inventory_item.updated cannot record its value.', $field));
            }
            if (null === $value && \in_array($field, self::NULLABLE_VALUE_FIELDS, true)) {
                continue;
            }
            if (!\is_string($value)) {
                throw new \LogicException(\sprintf('The value of "%s" must be the string the item serves.', $field));
            }
        }
        ksort($values);

        return $values;
    }

    private function record(string $tenantId, string $actorUserId, string $actorLabel, string $event, string $itemId, string $metadata = '{}'): void
    {
        $this->tenants->run($tenantId, static function (EntityManagerInterface $em) use ($tenantId, $actorUserId, $actorLabel, $event, $itemId, $metadata): void {
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
                    $itemId,
                    $metadata,
                ]);
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Infrastructure;

use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\ValueObject\AuditEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **The writer of `warehouse.created` and `warehouse.updated`** — warehouses slice 1 C2, 2026-10-13 (owner Q-W2 (a),
 * adopted under delegation; `docs/session13/warehouses-s1-design.md` §3.2; final RED M-4). `SupplierAuditWriter`'s shape,
 * with one deliberate departure: **these events carry values.**
 *
 * **Why values here, when `customer.*`, `supplier.*` and `role.updated` carry names only:** a warehouse's `code`, `name` and
 * `country_code` are operational, not personal data, and PC-11's `audit_log.view_record` shows an entry only to a caller
 * who can already view the row (`SECURITY_MODEL.md` PC-11), so the only new information is the **old** value — which is the
 * point: a rename relabels issued purchase orders, sales orders and pick lists (`RESIDUAL_GAPS.md` G-43 half (i)), and the
 * trail is where the old label survives. `.created` carries the values too, as the anchor that lets the first update's
 * `from` be checked, so an out-of-band SQL edit shows as a break in the chain (detection, not a control). **`SEC`
 * confirmation is not a build gate** (final RED M-4): if `SEC` later refuses values, a follow-up commit drops them for
 * `warehouse.*` (names only, the house shape) and G-43 half (i) says the old name is unrecoverable.
 *
 * **The value allowlist is the control** (design R-9): {@see self::VALUE_FIELDS} names every field whose value may be
 * recorded, explicitly. A field added to `warehouse` later (a site contact, an address line — personal data) does **not**
 * inherit the rule: passing it here throws. `WarehouseWriteTest` pins the set by literal and drives an update that changes
 * every accepted field.
 *
 * Both events are **business** under PC-11 (4a) (the `supplier.*` filing's terms; `SEC` to confirm the warehouse block),
 * so there is **no `$ipAddress`** and no `permission_code`. `warehouse.restored` (C1 of the delete-restore build,
 * 2026-10-20, {@see self::recordRestored()}) carries `{}` (`SEC` `wh-loc-delete-roles.md` S-1: a restore changes only
 * `deleted_at`). `warehouse.deleted` is **not** written here yet: it is minted with its writer when `DeleteWarehouse`
 * ships (C4, API-O-34, G-7).
 *
 * @see \App\SharedKernel\ValueObject\AuditEvent::WAREHOUSE_CREATED
 * @see \App\SharedKernel\ValueObject\AuditEvent::WAREHOUSE_UPDATED
 * @see \App\SharedKernel\ValueObject\AuditEvent::WAREHOUSE_RESTORED
 */
final readonly class WarehouseAuditWriter
{
    /**
     * The only fields whose **values** `warehouse.*` may carry: `CreateWarehouse` / `UpdateWarehouse`'s three accepted wire
     * fields (§4.2), sorted. An explicit list, never "all changed fields" (R-9).
     */
    public const VALUE_FIELDS = ['code', 'country_code', 'name'];

    public function __construct(private TenantContext $tenants)
    {
    }

    /**
     * `warehouse.created` — `CreateWarehouse`, after its INSERT, in its transaction: `{"values": {...}}`, all three
     * allowlisted fields as stored.
     *
     * @param array<string, string> $values
     */
    public function recordCreated(string $tenantId, string $actorUserId, string $actorLabel, string $warehouseId, array $values): void
    {
        $keys = array_keys($values);
        sort($keys);
        if (self::VALUE_FIELDS !== $keys) {
            throw new \LogicException(\sprintf('warehouse.created carries exactly the values of %s; got %s.', implode(', ', self::VALUE_FIELDS), implode(', ', $keys)));
        }

        $this->record($tenantId, $actorUserId, $actorLabel, AuditEvent::WAREHOUSE_CREATED, $warehouseId,
            json_encode(['values' => self::checked($values)], \JSON_THROW_ON_ERROR));
    }

    /**
     * `warehouse.updated` — `UpdateWarehouse`, after its UPDATE, in its transaction: `{"fields": [...], "from": {...},
     * "to": {...}}`, the changed fields' wire names (sorted) and their old and new values. `$from` and `$to` name the same
     * fields; an empty change writes no row.
     *
     * @param array<string, string> $from
     * @param array<string, string> $to
     */
    public function recordUpdated(string $tenantId, string $actorUserId, string $actorLabel, string $warehouseId, array $from, array $to): void
    {
        $fields = array_keys($from);
        sort($fields);
        $toFields = array_keys($to);
        sort($toFields);
        if ([] === $fields) {
            throw new \LogicException('warehouse.updated names the fields that changed; an update that changed nothing writes no row.');
        }
        if ($fields !== $toFields) {
            throw new \LogicException('warehouse.updated records the old and new value of the same fields.');
        }

        $this->record($tenantId, $actorUserId, $actorLabel, AuditEvent::WAREHOUSE_UPDATED, $warehouseId,
            json_encode(['fields' => $fields, 'from' => self::checked($from), 'to' => self::checked($to)], \JSON_THROW_ON_ERROR));
    }

    /**
     * `warehouse.restored` — `RestoreWarehouse`, after its UPDATE, in its transaction. `metadata` `{}`: the row shows the
     * change itself (`archived_at`), and no value moved.
     */
    public function recordRestored(string $tenantId, string $actorUserId, string $actorLabel, string $warehouseId): void
    {
        $this->record($tenantId, $actorUserId, $actorLabel, AuditEvent::WAREHOUSE_RESTORED, $warehouseId, '{}');
    }

    /**
     * Refuses any field outside {@see self::VALUE_FIELDS} and any non-string value, and sorts by field name.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, string>
     */
    private static function checked(array $values): array
    {
        foreach ($values as $field => $value) {
            if (!\in_array($field, self::VALUE_FIELDS, true)) {
                throw new \LogicException(\sprintf('"%s" is not on the warehouse value allowlist, so warehouse.* cannot record its value.', $field));
            }
            if (!\is_string($value)) {
                throw new \LogicException(\sprintf('The value of "%s" must be the string the row stores.', $field));
            }
        }
        ksort($values);

        return $values;
    }

    private function record(string $tenantId, string $actorUserId, string $actorLabel, string $event, string $warehouseId, string $metadata): void
    {
        $this->tenants->run($tenantId, static function (EntityManagerInterface $em) use ($tenantId, $actorUserId, $actorLabel, $event, $warehouseId, $metadata): void {
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
                    $warehouseId,
                    $metadata,
                ]);
        });
    }
}

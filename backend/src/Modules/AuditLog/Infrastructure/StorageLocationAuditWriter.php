<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Infrastructure;

use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\ValueObject\AuditEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **The writer of `storage_location.created` and `storage_location.updated`** — warehouses slice 1 C3, 2026-10-13 (owner
 * Q-W2 (a) and Q-W1 (d), adopted under delegation; `docs/session13/warehouses-s1-design.md` §4.5; final RED M-1, M-4).
 * {@see WarehouseAuditWriter}'s shape and its reasons for carrying **values**: a location's `code` and `kind` are
 * operational, not personal, and a re-label changes the code shown on issued receipts, shipments and returns
 * (`RESIDUAL_GAPS.md` G-43 half (ii)), so the old label survives here.
 *
 * **`held_stock`** — present on `storage_location.updated` **exactly when `kind` changed** (final RED M-1: one rule): whether
 * the location held stock when the change was made, read by `StorageLocationWriter` under the row lock in a statement
 * AFTER it. It is what tells a hold or a release of a stocked location (recall, damage, inspection; a release of a stocked
 * quarantine location is the owner's alone, G-44) from an empty relabel, without a second query against a stock table
 * that has moved on since.
 *
 * **The value allowlist is the control** (design R-9): {@see self::VALUE_FIELDS}. A field added to `storage_location` later
 * does not inherit the rule: passing it here throws. `StorageLocationWriteTest` pins the set by literal and drives an update
 * that changes every accepted field.
 *
 * Both events are **business** under PC-11 (4a) (`SEC` to confirm the storage-location block), so no `$ipAddress` and no
 * `permission_code`. `.restored` (C2 of the delete-restore build, 2026-10-20, {@see self::recordRestored()}) carries `{}`
 * (`SEC` `wh-loc-delete-roles.md` S-1); `.deleted` is minted with `DeleteStorageLocation` (C3, G-27).
 *
 * @see \App\SharedKernel\ValueObject\AuditEvent::STORAGE_LOCATION_CREATED
 * @see \App\SharedKernel\ValueObject\AuditEvent::STORAGE_LOCATION_UPDATED
 * @see \App\SharedKernel\ValueObject\AuditEvent::STORAGE_LOCATION_RESTORED
 */
final readonly class StorageLocationAuditWriter
{
    /**
     * The only fields whose **values** `storage_location.*` may carry: `CreateStorageLocation` / `UpdateStorageLocation`'s
     * two accepted wire fields (§4.2), sorted. An explicit list, never "all changed fields" (R-9).
     */
    public const VALUE_FIELDS = ['code', 'kind'];

    public function __construct(private TenantContext $tenants)
    {
    }

    /**
     * `storage_location.created` — `CreateStorageLocation`, after its INSERT, in its transaction: `{"values": {...}}`, both
     * allowlisted fields as stored (the kind the database defaulted when none was sent).
     *
     * @param array<string, string> $values
     */
    public function recordCreated(string $tenantId, string $actorUserId, string $actorLabel, string $storageLocationId, array $values): void
    {
        $keys = array_keys($values);
        sort($keys);
        if (self::VALUE_FIELDS !== $keys) {
            throw new \LogicException(\sprintf('storage_location.created carries exactly the values of %s; got %s.', implode(', ', self::VALUE_FIELDS), implode(', ', $keys)));
        }

        $this->record($tenantId, $actorUserId, $actorLabel, AuditEvent::STORAGE_LOCATION_CREATED, $storageLocationId,
            json_encode(['values' => self::checked($values)], \JSON_THROW_ON_ERROR));
    }

    /**
     * `storage_location.updated` — `UpdateStorageLocation`, after its UPDATE, in its transaction: `{"fields": [...], "from":
     * {...}, "to": {...}}`, plus `"held_stock"` when — and only when — `kind` is among the changed fields.
     *
     * @param array<string, string> $from
     * @param array<string, string> $to
     */
    public function recordUpdated(string $tenantId, string $actorUserId, string $actorLabel, string $storageLocationId, array $from, array $to, ?bool $heldStock): void
    {
        $fields = array_keys($from);
        sort($fields);
        $toFields = array_keys($to);
        sort($toFields);
        if ([] === $fields) {
            throw new \LogicException('storage_location.updated names the fields that changed; an update that changed nothing writes no row.');
        }
        if ($fields !== $toFields) {
            throw new \LogicException('storage_location.updated records the old and new value of the same fields.');
        }
        $kindChanged = \in_array('kind', $fields, true);
        if ($kindChanged !== (null !== $heldStock)) {
            throw new \LogicException('storage_location.updated carries held_stock exactly when kind changed.');
        }

        $metadata = ['fields' => $fields, 'from' => self::checked($from), 'to' => self::checked($to)];
        if (null !== $heldStock) {
            $metadata['held_stock'] = $heldStock;
        }

        $this->record($tenantId, $actorUserId, $actorLabel, AuditEvent::STORAGE_LOCATION_UPDATED, $storageLocationId,
            json_encode($metadata, \JSON_THROW_ON_ERROR));
    }

    /**
     * `storage_location.restored` — `RestoreStorageLocation`, after its UPDATE, in its transaction. `metadata` `{}`: the row
     * shows the change itself (`archived_at`), and `warehouse_id` is frozen, so the subject always resolves.
     */
    public function recordRestored(string $tenantId, string $actorUserId, string $actorLabel, string $storageLocationId): void
    {
        $this->record($tenantId, $actorUserId, $actorLabel, AuditEvent::STORAGE_LOCATION_RESTORED, $storageLocationId, '{}');
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
                throw new \LogicException(\sprintf('"%s" is not on the storage location value allowlist, so storage_location.* cannot record its value.', $field));
            }
            if (!\is_string($value)) {
                throw new \LogicException(\sprintf('The value of "%s" must be the string the row stores.', $field));
            }
        }
        ksort($values);

        return $values;
    }

    private function record(string $tenantId, string $actorUserId, string $actorLabel, string $event, string $storageLocationId, string $metadata): void
    {
        $this->tenants->run($tenantId, static function (EntityManagerInterface $em) use ($tenantId, $actorUserId, $actorLabel, $event, $storageLocationId, $metadata): void {
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
                    $storageLocationId,
                    $metadata,
                ]);
        });
    }
}

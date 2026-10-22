<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Infrastructure;

use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\ValueObject\AuditEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **The writer of `tenant_settings.updated`** — G7b `UpdateSettings`, 2026-10-22 (owner ruling RO-S1;
 * `docs/session13/g3-g7b-reorder-settings-design.md` Revision 1 FINAL R1.2 M-5; final RED F-M3). `WarehouseAuditWriter`'s
 * shape, with the metadata narrowed to what the design adopted: **the new value only**.
 *
 * `metadata` is exactly `{"fields": ["display_name"], "to": <the stored name>}` — no `from`. The first value of the chain is
 * the one `Version20261022020000` backfilled from `btrim(tenant.name)`, which RO-S1 never rewrites, so each row's `to`
 * reconstructs the chain **from the migrated value** (final RED F-M4: a workspace set up later outside the app starts from
 * whatever that path wrote, unaudited). Writing the previous name a second time as `from` would record each value twice.
 *
 * **Business** under PC-11 (4a) (`SEC` to confirm), so there is **no `$ipAddress`** and no `permission_code`. Subject
 * `('tenant_settings', id)` — the singleton's row id, which no operation serves (its History is unreachable from the product,
 * final RED F-M4); `ListAuditLogEntries` shows the rows to owner and admin.
 *
 * @see \App\SharedKernel\ValueObject\AuditEvent::TENANT_SETTINGS_UPDATED
 */
final readonly class SettingsAuditWriter
{
    /** The only field `UpdateSettings` accepts, and so the only field `tenant_settings.updated` can name. */
    public const FIELDS = ['display_name'];

    public function __construct(private TenantContext $tenants)
    {
    }

    /**
     * `tenant_settings.updated` — `UpdateSettings`, after its UPDATE, in its transaction and under the settings row lock. A
     * rename that changes nothing writes no row (the writer never calls this then).
     */
    public function recordUpdated(string $tenantId, string $actorUserId, string $actorLabel, string $settingsId, string $displayName): void
    {
        $metadata = json_encode(['fields' => self::FIELDS, 'to' => $displayName], \JSON_THROW_ON_ERROR);

        $this->tenants->run($tenantId, static function (EntityManagerInterface $em) use ($tenantId, $actorUserId, $actorLabel, $settingsId, $metadata): void {
            $em->getConnection()->executeStatement(<<<'SQL'
                INSERT INTO audit_log
                    (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label,
                     subject_type, subject_id, ip_address, metadata)
                VALUES
                    (?::uuid, ?::uuid, now(), ?, ?::uuid, ?, ?, ?::uuid, NULL, ?::jsonb)
                SQL, [
                    Uuid::v4()->toRfc4122(),
                    $tenantId,
                    AuditEvent::TENANT_SETTINGS_UPDATED,
                    $actorUserId,
                    $actorLabel,
                    // Read off the catalogue: `audit_log_subject_pair` requires the pair to arrive together.
                    AuditEvent::SUBJECT_TYPES[AuditEvent::TENANT_SETTINGS_UPDATED],
                    $settingsId,
                    $metadata,
                ]);
        });
    }
}

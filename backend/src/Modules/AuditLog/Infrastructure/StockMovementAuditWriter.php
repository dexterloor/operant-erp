<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Infrastructure;

use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\ValueObject\AuditEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **The writer of `stock_movement.adjusted`** — stock count C1, 2026-10-16 (`SECURITY_MODEL.md` E-25 (a); `SEC`
 * `docs/session13/stock-adjustment-sec.md` §3). `stock_movement` has no actor column, so this row is the only record of who
 * counted and why.
 *
 * **Business** under PC-11 (4a): no IP, no `permission_code`. Subject the movement, through the catalogue
 * (`AuditEvent::SUBJECT_TYPES`), never a new `'stock_movement'` literal (`WriteOwnershipTest::STOCK_MOVEMENT_NAME_HOLDERS`).
 * `metadata` is EXACTLY `{"counted_quantity": <scale-6 string>, "note": <string>}` ({@see self::METADATA_KEYS}): the counted
 * figure is operator input and what it discloses is derivable from the movement history under `stock_movement.view`;
 * `note` is stored as a jsonb string value, never interpolated. **Refused (`SEC` §3):** any owner-rule / held-stock flag
 * (derived from other bins and the bin's kind, which the trail's reader need not be able to see), the version, the key,
 * on-hand before, any display field, any other bin's figure.
 *
 * Written inside the count's transaction, after its statements: an audit failure rolls the count back, and a refusal or
 * a replay writes none — exactly one row per movement row.
 *
 * **A transfer writes two, one per leg** (stock transfer C1, 2026-10-19; `SEC` `docs/session13/stock-transfer-sec.md` §1):
 * {@see self::recordTransferred()} gives the out leg and the in leg each one `stock_movement.adjusted` row, so "exactly one
 * row per movement row" holds for transfers too and either leg's History names who moved it. `metadata` is exactly
 * `{"note": <string or null>}` on both ({@see self::TRANSFER_METADATA_KEYS}); never `counted_quantity`, the partner's id,
 * a bin's kind or code, any on-hand figure or a release flag. It executes ONE single-row `INSERT` literal once per leg —
 * never a two-tuple INSERT (final RED H-1: `AuditWriterTest` holds one statement to one row), and never `recordAdjusted`'s
 * literal (the count's metadata is not the transfer's).
 *
 * @see \App\SharedKernel\ValueObject\AuditEvent::STOCK_MOVEMENT_ADJUSTED
 */
final readonly class StockMovementAuditWriter
{
    /** The only `metadata` keys `stock_movement.adjusted` carries, sorted (`SEC` §3). */
    public const METADATA_KEYS = ['counted_quantity', 'note'];

    /** The only `metadata` keys a transfer leg's `stock_movement.adjusted` row carries (`SEC` stock-transfer-sec.md §1.3). */
    public const TRANSFER_METADATA_KEYS = ['note'];

    public function __construct(private TenantContext $tenants)
    {
    }

    public function recordAdjusted(string $tenantId, string $actorUserId, string $actorLabel, string $movementId, string $countedQuantity, string $note): void
    {
        $metadata = json_encode(['counted_quantity' => $countedQuantity, 'note' => $note], \JSON_THROW_ON_ERROR);

        $this->tenants->run($tenantId, static function (EntityManagerInterface $em) use ($tenantId, $actorUserId, $actorLabel, $movementId, $metadata): void {
            $em->getConnection()->executeStatement(<<<'SQL'
                INSERT INTO audit_log
                    (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label,
                     subject_type, subject_id, ip_address, metadata)
                VALUES
                    (?::uuid, ?::uuid, now(), ?, ?::uuid, ?, ?, ?::uuid, NULL, ?::jsonb)
                SQL, [
                    Uuid::v4()->toRfc4122(),
                    $tenantId,
                    AuditEvent::STOCK_MOVEMENT_ADJUSTED,
                    $actorUserId,
                    $actorLabel,
                    // Read off the catalogue: `audit_log_subject_pair` requires the pair to arrive together.
                    AuditEvent::SUBJECT_TYPES[AuditEvent::STOCK_MOVEMENT_ADJUSTED],
                    $movementId,
                    $metadata,
                ]);
        });
    }

    /**
     * One row per leg of a transfer, both inside the transfer's transaction, same actor and the same `{"note": …}`.
     * `$note` is the trimmed note, or null when none was sent (owner Q-T3).
     */
    public function recordTransferred(string $tenantId, string $actorUserId, string $actorLabel, string $outLegId, string $inLegId, ?string $note): void
    {
        $metadata = json_encode(['note' => $note], \JSON_THROW_ON_ERROR);

        $this->tenants->run($tenantId, static function (EntityManagerInterface $em) use ($tenantId, $actorUserId, $actorLabel, $outLegId, $inLegId, $metadata): void {
            foreach ([$outLegId, $inLegId] as $legId) {
                $em->getConnection()->executeStatement(<<<'SQL'
                    INSERT INTO audit_log
                        (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label,
                         subject_type, subject_id, ip_address, metadata)
                    VALUES
                        (?::uuid, ?::uuid, now(), ?, ?::uuid, ?, ?, ?::uuid, NULL, ?::jsonb)
                    SQL, [
                        Uuid::v4()->toRfc4122(),
                        $tenantId,
                        AuditEvent::STOCK_MOVEMENT_ADJUSTED,
                        $actorUserId,
                        $actorLabel,
                        AuditEvent::SUBJECT_TYPES[AuditEvent::STOCK_MOVEMENT_ADJUSTED],
                        $legId,
                        $metadata,
                    ]);
            }
        });
    }
}

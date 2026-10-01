<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Infrastructure;

use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\ValueObject\AuditEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`credit_note.issued`** (sales step 7 C1; `SEC` S-5): business, subject the credit note, actor the issuer, no IP,
 * metadata `{"reason": <enum value>}` and nothing else — no number, no amount, no display data (PC-11's metadata clause).
 * Written synchronously inside the issuing transaction (A-5, `SalesReturnAuditWriter`'s shape), so a credit note and
 * its trail row commit together or not at all.
 */
final readonly class CreditNoteAuditWriter
{
    public function __construct(private TenantContext $tenants)
    {
    }

    /** The caller invokes this on its **insert** branch only — a key replay writes no second row. */
    public function recordIssued(string $tenantId, string $actorUserId, string $actorLabel, string $creditNoteId, string $reason): void
    {
        $this->tenants->run($tenantId, static function (EntityManagerInterface $em) use ($tenantId, $actorUserId, $actorLabel, $creditNoteId, $reason): void {
            $em->getConnection()->executeStatement(<<<'SQL'
                INSERT INTO audit_log
                    (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label,
                     subject_type, subject_id, ip_address, metadata)
                VALUES
                    (?::uuid, ?::uuid, now(), ?, ?::uuid, ?, ?, ?::uuid, NULL,
                     jsonb_build_object('reason', ?::text))
                SQL, [
                    Uuid::v4()->toRfc4122(),
                    $tenantId,
                    AuditEvent::CREDIT_NOTE_ISSUED,
                    $actorUserId,
                    $actorLabel,
                    AuditEvent::SUBJECT_TYPES[AuditEvent::CREDIT_NOTE_ISSUED],
                    $creditNoteId,
                    $reason,
                ]);
        });
    }
}

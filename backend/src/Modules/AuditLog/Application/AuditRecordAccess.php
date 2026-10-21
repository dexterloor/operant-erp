<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Application;

use App\Modules\AuditLog\Infrastructure\DenialAuditWriter;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\Identity;

/**
 * **E-5's second check — the subject's own read code, beside `audit_log.view_record`** (audit reads C2;
 * `docs/session13/audit-reads-design.md` §4.1; `SECURITY_MODEL.md` PC-11 and E-5 as amended 2026-10-21, H-3 = (ii)).
 *
 * The gate has already refused a caller without `audit_log.view_record` (E-1). This class asks the second question: does
 * the caller hold the read code of the subject's TYPE ({@see RecordHistorySubjects}, PC-9's map — never
 * `"{subject_type}.view"`, R-7)? If not, it writes the E-3 row and throws the gate's own 403, byte-identical.
 *
 * - **Before any `audit_log` read** (E-1's ordering applied to the second code): a refused caller learns nothing about the
 *   subject, so the 403 is the same for a real id and an invented one.
 * - **The denial row** names `permission_code` = the subject's read code, and `(subject_type, subject_id)` = the query's
 *   pair — caller-supplied and unresolved, as every gate denial's id is (E-1/E-3a). It goes through
 *   {@see DenialAuditWriter::record()} exactly as `PermissionGate` calls it, so E-3a's cap applies unchanged.
 * - **Never inside `TenantContext::run()`**: the caller (the provider) calls this before the reader opens one, so no
 *   exception crosses a transaction (`LockTimeDenial`'s `EntityManagerClosed` trap; mutation N10).
 * - **Code-level, not row-level** (H-3 (ii), the judge's ruling under the owner's delegation): no openability check. A
 *   deleted record's History stays readable by id to its type's code holders — `RESIDUAL_GAPS.md` H-3's gap (G-52).
 *
 * The edge `AuditRecordAccess -> DenialAuditWriter` is A-7 (a module's Application reaching its own Infrastructure),
 * registered in `deptrac.yaml` (`ARCH` TO CONFIRM the tag); the precedent is `RoleAdministrationActor`'s A-5 edge to the same
 * writer.
 */
final readonly class AuditRecordAccess
{
    public function __construct(private DenialAuditWriter $denials)
    {
    }

    /** @throws ApiProblem a 403 naming the subject's read code, after one E-3 row */
    public function refuseUnlessTheSubjectIsReadable(Identity $identity, ?string $ipAddress, string $subjectType, string $subjectId): void
    {
        $code = RecordHistorySubjects::readCodeOf($subjectType)
            ?? throw new \LogicException(\sprintf('"%s" is not a History subject; the declared enum should have refused it.', $subjectType));

        if ($identity->holds($code)) {
            return;
        }

        $this->denials->record(
            tenantId: $identity->tenantId,
            actorUserId: $identity->userId,
            actorLabel: $identity->actorLabel,
            permissionCode: $code,
            subjectId: $subjectId,
            ipAddress: $ipAddress,
            subjectType: $subjectType,
        );

        throw ApiProblem::permissionDenied($code);
    }
}

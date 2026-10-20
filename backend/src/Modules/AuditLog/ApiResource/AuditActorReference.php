<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * API-23b's expansion for `audit_log.actor_user_id`: `actor = {id, display_name}` (§4.4.3's AuditLogEntry row), the
 * user's **live** name. The name as recorded is the row's own `actor_label`, served beside it.
 *
 * A fourth copy of a two-property DTO, for the reasons `SalesActorReference` records: no `AuditLogApiResource` edge to
 * another module's `ApiResource` layer exists (`deptrac.yaml`), and API Platform names a spec component after the short
 * class name, so the module prefix keeps the names distinct. It never carries `email` (PC-15 condition 2).
 */
final readonly class AuditActorReference
{
    public function __construct(
        public string $id,
        #[SerializedName('display_name')]
        public string $displayName,
    ) {
    }
}

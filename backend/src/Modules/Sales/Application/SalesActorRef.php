<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `shipped_by = {id, display_name}` (owner ruling R6; `SECURITY_MODEL.md` PC-11 (4a)): a business-document
 * actor, evidence for SoD-4 and not enforcement. `app_user` has no `name` column; `display_name` is its name.
 */
final readonly class SalesActorRef
{
    public function __construct(
        public string $id,
        #[SerializedName('display_name')]
        public string $displayName,
    ) {
    }
}

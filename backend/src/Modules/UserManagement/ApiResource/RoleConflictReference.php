<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * One `OfferedRole.conflicts_with[]` entry — `{role = {id, name}, sod_row}` (users slice 1 C3, K-2): the other role as a
 * PC-15 reference (API-23b: id and display field only) and the SoD row id the pair would newly span, so ST-4e's reason
 * can name the job split and never a code.
 */
final readonly class RoleConflictReference
{
    public function __construct(
        public RoleReference $role,
        #[SerializedName('sod_row')]
        public string $sodRow,
    ) {
    }
}

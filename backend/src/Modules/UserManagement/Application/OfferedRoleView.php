<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

/**
 * One offered role as `OfferedRoleReader` derives it — scalars only (A-7; `RoleView`'s rule). §4.4.3's `OfferedRole` row
 * (users slice 1 C3, K-2; `docs/session13/users-s1-design.md` rev 3 §2.2).
 */
final readonly class OfferedRoleView
{
    /**
     * @param list<array{role: array{id: string, name: string}, sod_row: string}> $conflictsWith      pairwise PC-13, one entry per SoD row
     * @param list<array{id: string, name: string}>                               $marginExposureWith PC-12 clause 4's partners
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $conflictsWith,
        public array $marginExposureWith,
        public ?string $refusal,
        public bool $requiresOwnerConfirmation,
    ) {
    }
}

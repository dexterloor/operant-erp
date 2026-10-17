<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Domain;

/**
 * **The companion codes a composed role must carry** — PC-4a at composition, roles slice 4 (design §4.4; owner rulings
 * D-5a, D-5b and D-6 option (1), `docs/session13/session22-owner-ruling.md`, 2026-10-07).
 *
 * A composed role holding a code in the left column is REFUSED unless it also holds the code on the right. Each row has a
 * named harm, and a row without one is refused at review — this is deliberately NOT "every write code needs its resource's
 * `.view`", which would contradict PC-17's ruling that a write holder without the view may receive the body:
 *
 *  - **D-5a** `credit_note.issue`, `credit_note.adjust` → `invoice.view`: otherwise the seat records a `same` decision with
 *    no sight of the decision members (`g29-g30-sec.md` §5; G-32's condition 1).
 *  - **D-5b** `role.create`, `role.edit`, `role.delete` → `role.view`: their flow reads `ListPermissions`/`GetRole` (both
 *    `role.view`), and PC-17's empty-edit read of `permission_codes`/`holder_count` then discloses nothing new (the PC-17
 *    re-ask).
 *  - **D-6 (1)** `role.delete` → `role.assign`: `DeleteRole` refuses a role with pending grant proposals, and pending
 *    existence is privilege history served only under `role.assign` (PC-16 condition 1, `SECURITY_MODEL.md` §5.2.3). Every
 *    seat that can meet that refusal is then a seat allowed to be told about it. Affects no default role (only `owner` and
 *    `admin` hold `role.delete`, and both hold `role.assign`).
 *  - **Q-U3** `role.assign` → `app_user.view` (owner ruling 2026-10-08, `session23-owner-ruling.md`; `SEC` R1-1): the
 *    picker read `ListOfferedRoles` is gated on `role.assign` alone, so without this a seat that gives roles but cannot
 *    see users would read a person's held roles, removed/inactive state and SoD position in one silent request. The
 *    picker lives on the user page, which already needs `app_user.view`, so the row strands nobody. Affects no default
 *    role (only `owner` and `admin` hold `role.assign`, and both hold `app_user.view`).
 *  - **SA-1** `stock_movement.adjust` → `stock_level.view` (`SEC`, 2026-10-16, `docs/session13/stock-adjustment-sec.md` §4):
 *    a count quotes a `version` only `ListStockLevels` serves. Without the view, the seat could learn on-hand only from
 *    `stale-record`'s `current` and the matching-count refusal. Affects no default role (`owner`, `admin` and
 *    `warehouse_operator` hold both). `stock_movement.adjust` → `stock_movement.view` is REFUSED (PC-17 serves the 201 body
 *    to the write's holder) and → `storage_location.view` DECLINED (`kind` disclosure accepted, Low), both `SEC` §4.
 *  - `role.assign` → `role.view` is deliberately NOT a row: §5.2.11 records that exposure as accepted (design §4.4, F-7).
 *
 * **Per role, not per union** (R-3): the same blind seat is reachable by granting two clean roles and revoking the one with
 * the view. The per-role guarantee makes the common composed seat correct; union drift is disclosed, not solved.
 *
 * Applies to COMPOSED roles. The eight defaults satisfy it by construction, and `RoleCompanionsTest` asserts they do, which
 * makes this table PC-4a's first mechanical check (`FLOWS.md` is parsed by nothing).
 *
 * Every row names ONE holding code, never a list: a list of two `X(admin)` codes is what `XAdminIsNeverTypedTest` refuses.
 */
final class RoleCompanions
{
    /** @var list<array{holding: string, requires: string, ruling: string}> */
    private const ROWS = [
        ['holding' => 'credit_note.issue', 'requires' => 'invoice.view', 'ruling' => 'D-5a'],
        ['holding' => 'credit_note.adjust', 'requires' => 'invoice.view', 'ruling' => 'D-5a'],
        ['holding' => 'role.create', 'requires' => 'role.view', 'ruling' => 'D-5b'],
        ['holding' => 'role.edit', 'requires' => 'role.view', 'ruling' => 'D-5b'],
        ['holding' => 'role.delete', 'requires' => 'role.view', 'ruling' => 'D-5b'],
        ['holding' => 'role.delete', 'requires' => 'role.assign', 'ruling' => 'D-6'],
        ['holding' => 'role.assign', 'requires' => 'app_user.view', 'ruling' => 'Q-U3'],
        ['holding' => 'stock_movement.adjust', 'requires' => 'stock_level.view', 'ruling' => 'SA-1'],
    ];

    /** Not instantiable: a table, not a service. */
    private function __construct()
    {
    }

    /**
     * Every row, in this table's order.
     *
     * @return list<array{holding: string, requires: string, ruling: string}>
     */
    public static function rows(): array
    {
        return self::ROWS;
    }

    /**
     * The rows a role holding `$codes` breaks: it holds the left code and lacks the right one. Empty = the role is complete.
     *
     * @param list<string> $codes
     *
     * @return list<array{holding: string, requires: string, ruling: string}>
     */
    public static function missingFrom(array $codes): array
    {
        return array_values(array_filter(
            self::ROWS,
            static fn (array $row): bool => \in_array($row['holding'], $codes, true) && !\in_array($row['requires'], $codes, true),
        ));
    }
}

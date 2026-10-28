<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Domain;

/**
 * **A fingerprint of the separation-of-duty table and the default roles** (`RESIDUAL_GAPS.md` G-91; BK-11). A change to
 * either can turn people who already hold two roles into people who combine two separated duties, with no database edit
 * (SoD-5 did that to every `finance` + `warehouse_operator` holder on 2026-10-01). `OwedAmendmentTripwireTest` pins
 * {@see self::current()}, so such a commit cannot land without its author being told to run `app:roles:reconcile` arm H
 * for every non-demo tenant.
 *
 * sha256 over canonical JSON: rows and role names in key order, every code list sorted, so the order a list is typed in
 * does not move the pin and a code moving does. `admin` and `viewer` are derived from the catalogue, so a catalogue change
 * also moves it — correctly, since `admin`'s set is what a holder of it carries.
 */
final class DutyCatalogueFingerprint
{
    /**
     * @param array<string, array{preparing: list<string>, controlled: list<string>}> $sodRows      row id => both halves
     * @param array<string, list<string>>                                              $defaultRoles role name => codes
     */
    public static function of(array $sodRows, array $defaultRoles): string
    {
        $rows = [];
        foreach ($sodRows as $id => $row) {
            $preparing = $row['preparing'];
            $controlled = $row['controlled'];
            sort($preparing);
            sort($controlled);
            $rows[(string) $id] = ['controlled' => $controlled, 'preparing' => $preparing];
        }
        ksort($rows);

        $roles = [];
        foreach ($defaultRoles as $name => $codes) {
            sort($codes);
            $roles[(string) $name] = $codes;
        }
        ksort($roles);

        return hash('sha256', json_encode(['default_roles' => $roles, 'sod_rows' => $rows], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
    }

    /** Today's table and today's default roles. */
    public static function current(): string
    {
        $rows = [];
        foreach (SeparationOfDuty::rowIds() as $id) {
            $rows[$id] = SeparationOfDuty::row($id);
        }

        return self::of($rows, DefaultRoles::all());
    }

    /** Not instantiable: pure functions. */
    private function __construct()
    {
    }
}

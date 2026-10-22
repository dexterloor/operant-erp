<?php

declare(strict_types=1);

namespace App\Platform\Security;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * **A derived permission's resolver (G5-D0, `g5-g6-final-red.md` H-1; `g5-g6-bulk-export-design.md` §5.1a).**
 *
 * An operation that declares {@see PermissionGate::DERIVED} names, in {@see PermissionGate::DERIVED_PERMISSION}, the
 * service id of one of these. Its one job is a MAP, not a function: every `resource` value the operation accepts, and the
 * one code a request naming it must hold (`T.delete` for a bulk archival or restoration, ST-9; the List operation's
 * DECLARED code for an export -- `invoice.view` for credit notes, which `<resource>.view` concatenation would get wrong).
 *
 * A map rather than `codeFor($resource)` so that "every code this operation can demand" is a property of the resolver and
 * not a second list somebody keeps: the catalogue check, the rig's "holds everything" principal and the sweeps all read
 * these values, and a sweep drives EVERY key (final RED L-4).
 *
 * Tagged by the interface itself, so implementing it IS the wiring: an implementing service is in
 * {@see PermissionRequirements}'s locator under its own id, and `DerivedPermissionWiringTest` holds the locator and the
 * operations that name resolvers equal, both ways.
 *
 * Contract (checked by {@see PermissionRequirements::codesByResource()}, a violation is a LogicException): a non-empty map,
 * each key a non-empty string, each value a §3-shaped code -- never {@see PermissionGate::DERIVED}. The map is read once per
 * request and must not read the database: the gate decides before any row is loaded (E-1).
 */
#[AutoconfigureTag(PermissionRequirements::RESOLVER_TAG)]
interface DerivedPermission
{
    /**
     * @return array<string, string> resource => the code a request naming it must hold
     */
    public function codesByResource(): array;

    /** The 422 message for a `resource` the map does not hold (e.g. *"This kind of record can't be archived or restored in bulk."*). */
    public function unknownResourceMessage(): string;
}

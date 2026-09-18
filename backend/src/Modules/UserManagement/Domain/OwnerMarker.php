<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Domain;

/**
 * **E-15b's owner-equivalence marker. This is not a separation of duty.**
 *
 * ---------------------------------------------------------------------------
 * Why it gets its own class instead of a sixth entry in a list
 * ---------------------------------------------------------------------------
 * `X(admin)` has **two** sources and they are not the same kind of thing:
 *
 *  - the *controlled half* of every row in ADR-008 §2's SoD table — five codes
 *    today, derived by `SeparationOfDuty::controlledCodes()`;
 *  - **`tenant_settings.edit`**, which appears in **no** SoD row at all.
 *
 * `SECURITY_MODEL.md` PC-14 states exactly this and gives the second its own
 * clause: *"`tenant_settings.edit` is the one member that is not a separation of
 * duty, so it gets its own clause instead of being folded in. It is
 * configuration, and it is load-bearing elsewhere: **E-15b** uses it as the
 * *code* that marks `owner`'s seat."*
 *
 * **E-15b:** *"no actor but `owner` may grant a role conferring
 * `tenant_settings.edit`"*. `owner` holds it alone (§4), which is what makes it
 * the marker distinguishing the principal's seat from every other. If `admin`
 * held it, E-15b's predicate would admit `admin` and the rule would confer
 * nothing.
 *
 * ---------------------------------------------------------------------------
 * The defect this class exists to make impossible
 * ---------------------------------------------------------------------------
 * `docs/adr/sod_check.py` types `ADMIN_EXCL` as **six** literals and asserts
 * `len(ROLES['admin']) == 54`. Its own docstring records that this makes the
 * assertion not evidence — *"editing the constant and the assert together
 * validates a number the same edit chose"*. Six literals is also what hides the
 * provenance: five of them are §2's and one is E-15b's, and a flat list cannot
 * say so. Derive the whole six from §2 and `admin` is **55**, not 54; type all
 * six and nothing derives at all. **The shape that is correct is a derivation
 * plus one separately-provenanced code**, which is what
 * `AdministrativeCharter::excludedCodes()` computes from `SeparationOfDuty` and
 * this class.
 *
 * ---------------------------------------------------------------------------
 * A predicate, not an identity — and that matters to five other rules
 * ---------------------------------------------------------------------------
 * E-15b keys on a **code**, deliberately not on a role name or an exact
 * permission set: an exact-set test never fires on the largest PC-4-clean
 * composed role, which is 56 codes and not `owner`'s 60. DSoD-1's `owner`
 * exemption, A2.3, E-15b, E-15e and E-15g are all owed the same single
 * predicate — *the caller holds `tenant_settings.edit` through a live role*
 * (`V2-S-6`, owned by `SEC`, gated before the first roles operation ships).
 * **That predicate is not built here**: this class publishes the code it is
 * written over and nothing more. Resolving a caller's live roles needs
 * `IdentityReader`, which is Infrastructure, and this is a Domain layer that may
 * reach nothing but `SharedKernel`.
 */
final class OwnerMarker
{
    /**
     * The code E-15b is written over.
     *
     * A single literal is correct here and is not the defect above: this class
     * *is* the code's home, and there is nothing it could be derived from. The
     * defect is a literal standing in for something derivable, which is what the
     * other five members of `X(admin)` are.
     */
    public const CODE = 'tenant_settings.edit';

    /** Not instantiable: one named code and the reason it is named. */
    private function __construct()
    {
    }
}

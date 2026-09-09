<?php

declare(strict_types=1);

namespace App\Auth\Application;

/**
 * The four answers `POST /auth/token` has, and the reason there are four rather
 * than one per branch.
 *
 * `Refused` covers **every** refusal E-7 requires to be indistinguishable —
 * unknown workspace, unknown email, wrong password, a verified credential
 * against a non-active tenant, and a verified credential for a deactivated user.
 * They are one case here because they are one case on the wire, and an enum
 * with five refusal members is an invitation to render them differently.
 *
 * What the branches *do* differ in is which `audit_log` row they write, and that
 * decision is taken inside `SignIn` where the resolved identity is in scope —
 * never carried out to the boundary, where it would be one refactor away from
 * reaching a response body.
 */
enum SignInOutcome
{
    case Issued;
    case Refused;
    case Paused;
    case Throttled;
}

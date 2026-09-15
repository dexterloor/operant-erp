<?php

declare(strict_types=1);

namespace App\Platform\Tenancy;

/**
 * The database principals, in one place.
 *
 * W0-a's strongest guards -- the BYPASSRLS membership closure and "the
 * application roles hold no memberships at all" -- were written as
 * `foreach (['erp_app', 'erp_ai'] as $role)`. A hardcoded list means a role
 * added later is invisible to exactly the checks that exist to catch it, which
 * is ADR-006's stated implementation condition and D-9's blocking condition.
 *
 * A list here is still a list, so it is not trusted on its own:
 * SchemaGuardTest::theRoleListCoversEveryLoginRoleInTheDatabase derives the
 * real set from pg_roles and fails if the two disagree. Adding a role to the
 * database without adding it here fails the suite; adding it here without
 * creating it fails too.
 *
 * @see ADR-002 §1 + Amendment 1 §A1.3
 * @see ADR-006 §2
 */
final class DatabaseRoles
{
    /** Symfony application and workers. Writes its `src/` callers issue, RLS-bound. ADR-002 A4. */
    public const APP = 'erp_app';

    /** NL query agent. SELECT only, RLS-bound. ADR-002 §1. */
    public const AI = 'erp_ai';

    /** Pre-auth slug + credential read. No write anywhere. ADR-006 §2. */
    public const AUTH = 'erp_auth';

    /** Pre-auth audit write. INSERT on audit_log only, no SELECT on it. ADR-006 §2. */
    public const AUTH_LOG = 'erp_authlog';

    /**
     * Pre-auth rate limiting and lockout counters.
     *
     * Not in ADR-006, which puts §2.1 step 1 on erp_app. That is the exposure
     * ADR-006's own Condition 2 promotes to equal priority: erp_app then held DML on
     * every table with no tenant_id -- including messenger_messages -- so running
     * the rate limiter on it hands an unauthenticated request a principal that
     * can inject into the message queue. Five roles do not close that; a sixth,
     * whose entire footprint is two counter tables, does.
     */
    public const RATE_LIMIT = 'erp_ratelimit';

    /**
     * Every principal the application connects as. The schema owner is
     * deliberately absent: it owns the schema, runs migrations and never serves
     * a request, so the assertions that iterate this list do not apply to it.
     *
     * @var list<string>
     */
    public const APPLICATION_ROLES = [
        self::APP,
        self::AI,
        self::AUTH,
        self::AUTH_LOG,
        self::RATE_LIMIT,
    ];

    /**
     * The principals reachable before authentication, and the reason the
     * list exists separately: their privilege footprint is asserted to be
     * minimal, which is a stronger claim than the one made about erp_app.
     *
     * The count is deliberately absent. This docblock read "The two
     * principals" while the list held three -- erp_ratelimit was added as the
     * sixth role and the prose was not, so the constant defining the pre-auth
     * boundary carried a stale count of it. Deleted rather than corrected
     * (Rule 15): a number here has no reader that a consumer of the list does
     * not already have, and GrantFootprintTest derives its domain from the
     * array, never from this text.
     *
     * @var list<string>
     */
    public const PRE_AUTH_ROLES = [
        self::AUTH,
        self::AUTH_LOG,
        self::RATE_LIMIT,
    ];
}

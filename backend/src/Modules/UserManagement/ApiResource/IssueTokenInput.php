<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

use ApiPlatform\Metadata\ApiProperty;

/**
 * §4.2's `IssueToken` row, `Accepted request fields`: `workspace`; `email`;
 * `password`. **Exhaustive** — that column is the subject of §3.3's check, and
 * `allow_extra_attributes: false` on the operation is what makes it true at
 * runtime rather than only in the spec (API-9).
 *
 * **`workspace` and not `tenant_id`.** ADR-005 Amendment 2 makes the tenant
 * qualifier a field on the login form, and T-1a's whole point is that the one
 * pre-authentication operation resolves a *slug* the client typed — never an id
 * it was given. A `tenant_id` here would be tenant selection handed to the
 * caller.
 *
 * The fields are `public` and untyped-nullable-free on purpose: a missing field
 * is a `MissingConstructorArgumentsException`, which
 * `FrameworkProblemTranslator` renders as `validation-failed` with one `required`
 * entry at the field's wire pointer (G-101; before it, `malformed_body` at `""`).
 */
final class IssueTokenInput
{
    public function __construct(
        #[ApiProperty(required: true)]
        public string $workspace,
        #[ApiProperty(required: true)]
        public string $email,
        #[ApiProperty(required: true)]
        public string $password,
    ) {
    }
}

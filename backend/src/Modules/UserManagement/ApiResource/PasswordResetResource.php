<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use App\Modules\UserManagement\ApiResource\State\CreatePasswordResetProcessor;
use App\Platform\Http\ProblemResponses;
use App\Platform\Security\PermissionGate;

/**
 * **`CreatePasswordReset` — `POST /auth/password-resets`** (Mail-C1; `API.md` §4.2 Authentication, §4.3a item 4, §4.4.1
 * API-21 branch 6). Pre-authentication, like `IssueToken`: `/auth/*` needs no token, and the `Perm` cell is `—`, declared.
 *
 * **202, no body, for every well-formed input** — the request is queued, not performed, and the answer never depends on
 * whether the workspace or the address exists. Malformed input is a 422 with field pointers before anything else runs;
 * a spent sign-in budget is a 429. `Idem: no`: a replay mails at most once more, under the worker's per-person cap.
 */
#[ApiResource(
    shortName: 'PasswordReset',
    operations: [
        new Post(
            uriTemplate: '/auth/password-resets',
            name: 'CreatePasswordReset',
            status: 202,
            // BK-18 R18-h C1: this operation's own refusals beyond the derived set (its `Errors` cell). The decorator
            // keeps each description and gives it the house problem shape (`App\Platform\Http\ProblemResponses`).
            openapi: new OpenApiOperation(
                operationId: 'CreatePasswordReset',
                responses: [
                    '429' => new OpenApiResponse(description: 'Too many attempts from this network. This limit is shared with signing in. Wait before trying again; no wait time is given.'),
                ],
            ),
            input: CreatePasswordResetInput::class,
            output: false,
            denormalizationContext: [
                // API-9 / B-3: only a deny-unknown deserializer stops an undeclared field being accepted.
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
            ],
            read: false,
            processor: CreatePasswordResetProcessor::class,
            // Queued, not performed: it cannot answer the write-wide 409 (`ProblemResponses::QUEUED`).
            extraProperties: [PermissionGate::OPERATION_PERMISSION => null, ProblemResponses::QUEUED => true],
        ),
    ],
)]
final class PasswordResetResource
{
}

<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use App\Modules\UserManagement\ApiResource\State\CreateBulkRoleAssignmentProcessor;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Security\PermissionGate;

/**
 * **`CreateBulkRoleAssignment` (G5-C1, 2026-10-23): give one role to up to 50 people, each exactly as `AssignRole` would.**
 *
 * - **Permission `role.assign`** (static, §4.2): one decision before any id is read (E-1, E-14). The operation declares no
 *   denial subject, so a denial row is `(null, null)` + `role.assign` (the path names no id; the body is not read before
 *   the gate). Precedence 401 → 403 → 428 (`Idempotency-Key`) → 415 → body 422.
 * - **Idem `store`** (§6.2; the second store operation): a retry with the same key and the same request is answered with
 *   the stored envelope, byte for byte, for 24 hours; another request under the key — or the same key from another
 *   person — is 409, an expired key 422.
 * - **Success: 200 `{"requested": n, "applied": m, "margin_exposure": bool}`** (§4.4.1 branch 5 as amended for this
 *   operation): which people were given the role, and why the rest were not, is never said (E-14). Declared by hand: the
 *   body is a stored envelope, returned as bytes, never re-serialised.
 * - The coordinator is {@see \App\Modules\UserManagement\Application\BulkRoleAssignment}.
 */
#[ApiResource(
    shortName: 'BulkRoleAssignment',
    operations: [
        new Post(
            uriTemplate: '/bulk/role-assignments',
            name: 'CreateBulkRoleAssignment',
            status: 200,
            openapi: new OpenApiOperation(
                operationId: 'CreateBulkRoleAssignment',
                responses: [
                    '200' => new OpenApiResponse(
                        description: 'How many people were named, how many were given the role, and the role\'s margin warning. Which ones, and why the rest were not, is never said (E-14).',
                        content: new \ArrayObject(['application/json' => ['schema' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['requested', 'applied', 'margin_exposure'],
                            'properties' => [
                                'requested' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                                'applied' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 50],
                                'margin_exposure' => ['type' => 'boolean'],
                            ],
                        ]]]),
                    ),
                ],
            ),
            input: CreateBulkRoleAssignmentInput::class,
            output: false,
            read: false,
            denormalizationContext: [
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
            ],
            processor: CreateBulkRoleAssignmentProcessor::class,
            extraProperties: [
                PermissionGate::OPERATION_PERMISSION => 'role.assign',
                IdempotencyKeyGuard::OPERATION_FLAG => true,
            ],
        ),
    ],
)]
final class BulkRoleAssignmentRequest
{
}

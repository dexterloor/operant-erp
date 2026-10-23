<?php

declare(strict_types=1);

namespace App\Platform\Http\Bulk\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use App\Platform\Http\Bulk\ApiResource\State\CreateBulkRestorationProcessor;
use App\Platform\Http\Bulk\BulkRestorationPermission;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Security\PermissionGate;

/**
 * **`CreateBulkRestoration` (G5-C3, 2026-10-23): restore up to 50 archived records of one kind, each exactly as its own
 * `Restore*` would** (`docs/session13/g5-c3-design.md`; `CreateBulkArchival`'s twin, one coordinator).
 *
 * - **Permission `*(derived)*`**: the body's `resource` resolves, through {@see BulkRestorationPermission}, to the code that
 *   resource's single `Restore*` is declared with (`<resource>.delete`, ST-9: "with the same permission that archived it") —
 *   one decision, before any id is read (E-1, E-14). Precedence as `CreateBulkArchival`'s.
 * - **Idem `store`**: the same envelope protocol; an envelope is scoped to its operation, so a key used for an archival and
 *   then for a restoration is two envelopes, never a replay of the other.
 * - **Success: 200 `{"requested": n, "applied": m}`** and nothing else (E-14); no read shape, so G-46's archive-then-restore
 *   read is not widened. Declared by hand: the body is a stored envelope, returned as bytes.
 */
#[ApiResource(
    shortName: 'BulkRestoration',
    operations: [
        new Post(
            uriTemplate: '/bulk/restorations',
            name: 'CreateBulkRestoration',
            status: 200,
            openapi: new OpenApiOperation(
                operationId: 'CreateBulkRestoration',
                responses: [
                    '200' => new OpenApiResponse(
                        description: 'How many records were asked for and how many were restored. Which ones, and why the rest were not, is never said (E-14).',
                        content: new \ArrayObject(['application/json' => ['schema' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['requested', 'applied'],
                            'properties' => [
                                'requested' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                                'applied' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 50],
                            ],
                        ]]]),
                    ),
                ],
            ),
            input: CreateBulkRestorationInput::class,
            output: false,
            denormalizationContext: [
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
            ],
            processor: CreateBulkRestorationProcessor::class,
            extraProperties: [
                PermissionGate::OPERATION_PERMISSION => PermissionGate::DERIVED,
                PermissionGate::DERIVED_PERMISSION => BulkRestorationPermission::class,
                IdempotencyKeyGuard::OPERATION_FLAG => true,
            ],
        ),
    ],
)]
final class BulkRestorationRequest
{
}

<?php

declare(strict_types=1);

namespace App\Platform\Http\Bulk\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use App\Platform\Http\Bulk\ApiResource\State\CreateBulkArchivalProcessor;
use App\Platform\Http\Bulk\BulkArchivalPermission;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Security\PermissionGate;

/**
 * **`CreateBulkArchival` (G5-C2, 2026-10-22): archive up to 50 records of one kind, each exactly as its own `Delete*` would.**
 *
 * - **Permission `*(derived)*`** (§4.2 :963; G5-D0): the body's `resource` resolves, through {@see BulkArchivalPermission},
 *   to the code that resource's single `Delete*` is declared with (`<resource>.delete`, ST-9) — one decision, before any
 *   id is read (E-1, E-14). Precedence 401 → 422 (`resource`) → 403 → 428 (`Idempotency-Key`) → 415 → body 422.
 * - **Idem `store`** (§6.2; the first store operation): a retry with the same key and the same request is answered with
 *   the stored envelope, byte for byte, for 24 hours; another request under the key is 409, an expired key 422.
 * - **Success: 200 `{"requested": n, "applied": m}`** (§4.4.1 branch 5) and nothing else (E-14). No `{id}` sibling, so a
 *   create answers 200 by `ResponseStatusTest`'s reading (1). The response is declared by hand: the body is a stored
 *   envelope, returned as bytes, never re-serialised.
 * - The operation lives in `Platform\Http\Bulk` because it belongs to no module: each module serves its own resource
 *   through a {@see \App\Platform\Http\Bulk\BulkArchiveMember} (the `ExportSource` inversion; `ARCH` to confirm).
 */
#[ApiResource(
    shortName: 'BulkArchival',
    operations: [
        new Post(
            uriTemplate: '/bulk/archivals',
            name: 'CreateBulkArchival',
            status: 200,
            openapi: new OpenApiOperation(
                operationId: 'CreateBulkArchival',
                responses: [
                    '200' => new OpenApiResponse(
                        description: 'How many records were asked for and how many were archived. Which ones, and why the rest were not, is never said (E-14).',
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
            input: CreateBulkArchivalInput::class,
            output: false,
            denormalizationContext: [
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
            ],
            processor: CreateBulkArchivalProcessor::class,
            extraProperties: [
                PermissionGate::OPERATION_PERMISSION => PermissionGate::DERIVED,
                PermissionGate::DERIVED_PERMISSION => BulkArchivalPermission::class,
                IdempotencyKeyGuard::OPERATION_FLAG => true,
            ],
        ),
    ],
)]
final class BulkArchivalRequest
{
}

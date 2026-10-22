<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use App\Modules\AuditLog\ApiResource\State\CreateExportProcessor;
use App\Modules\AuditLog\Application\ExportPermission;
use App\Platform\Security\PermissionGate;

/**
 * **`CreateExport` (G6-C0): the current filtered view of one List, as a CSV file (TB-7; API-25; `API.md` §4.4.5).**
 *
 * - **Permission `*(derived)*`** (§4.2 :963; G5-D0): the body's `resource` resolves, through {@see ExportPermission}, to
 *   the code that resource's `List*` operation is declared with — one decision, before any id or row is read (E-1, E-14).
 *   Precedence 401 → 422 (`resource` unreadable/unknown) → 403 → 415 → body 422.
 * - **Idem `no`** (API-O-31 (3)): repeating an export is harmless and a second `data.export.completed` row is correct.
 * - **Success: 200 `text/csv; charset=utf-8`**, `Content-Disposition: attachment` — the one operation whose success body
 *   is not JSON (API-3's named exception; `ExportContractTest::onlyTheExportOperationReturnsANonJsonSuccessBody`, domain =
 *   the registry). Declared as this operation's only output format, so a client must accept `text/csv`; every error is
 *   still `problem+json` (API-4). The response is declared by hand below: the body is a rendering, not a resource, so it
 *   has no schema for `ResponseShapeTest`/`WireScaleTest` to read, and both skip it by its declared content type.
 * - No `{id}` sibling, so a create answers 200 by `ResponseStatusTest`'s reading (1).
 */
#[ApiResource(
    shortName: 'Export',
    operations: [
        new Post(
            uriTemplate: '/exports',
            name: 'CreateExport',
            status: 200,
            outputFormats: ['csv' => ['text/csv']],
            openapi: new OpenApiOperation(
                operationId: 'CreateExport',
                responses: [
                    '200' => new OpenApiResponse(
                        description: 'The current filtered view of the named List, as CSV (RFC 4180, UTF-8 with a BOM, CRLF line ends).',
                        content: new \ArrayObject(['text/csv' => ['schema' => ['type' => 'string']]]),
                    ),
                ],
            ),
            input: CreateExportInput::class,
            output: false,
            denormalizationContext: [
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
            ],
            processor: CreateExportProcessor::class,
            extraProperties: [
                PermissionGate::OPERATION_PERMISSION => PermissionGate::DERIVED,
                PermissionGate::DERIVED_PERMISSION => ExportPermission::class,
            ],
        ),
    ],
)]
final class ExportRequest
{
}

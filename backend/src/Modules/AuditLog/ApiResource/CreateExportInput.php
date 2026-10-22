<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\ApiResource;

use ApiPlatform\Metadata\ApiProperty;

/**
 * `CreateExport`'s body: `{resource, filters?, sort?}` (§4.2; design §6.2). **`view` is dropped** (Departure 4, notice N-6):
 * TB-7's "status segment" is the `status` filter and its "archived view" is the `archived` filter, both members of
 * `filters` under API-14a, so a separate `view` was a second copy of the same choice. An unknown top-level field is refused
 * by the deserializer (`allow_extra_attributes: false`, API-9).
 *
 * The types are checked by the processor, not by PHP property types, so a wrong type is a 422 naming the field rather than
 * a deserializer exception: `resource` is already a string by the time this is built (the gate refused anything else, 422
 * before the 403), `sort` must be a string or absent, and `filters` is held to the List's declared members by
 * `ExportRunner` (API-14a; members per resource cannot be one static schema, so the schema names the shape and the run-time
 * check names the members — `ExportContractTest::filterMembersAreTheListOperationsQueryParameters`).
 */
final class CreateExportInput
{
    #[ApiProperty(description: 'The kind of record to export: the primary table of the List whose current view is rendered (e.g. inventory_item).', required: true, schema: ['type' => 'string'])]
    public mixed $resource = null;

    #[ApiProperty(
        description: 'The List\'s filters, each as the text its query string would carry (API-14a): its declared query parameters minus limit, after, before and sort.',
        schema: ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
    )]
    public mixed $filters = null;

    #[ApiProperty(description: 'One of the List\'s sort values, when it declares any.', schema: ['type' => 'string'])]
    public mixed $sort = null;
}

<?php

declare(strict_types=1);

namespace App\Platform\Http\Bulk\ApiResource;

use ApiPlatform\Metadata\ApiProperty;

/**
 * `CreateBulkRestoration`'s body (G5-C3; the archival's shape): `{resource, ids}` (§4.2). An unknown top-level field is refused by the deserializer
 * (`allow_extra_attributes: false`, API-9). The types are checked by the processor, not by PHP property types, so a wrong
 * type is a 422 naming the field: `resource` is already a known string by the time this is built (the gate refused
 * anything else, 422 before the 403), and `ids` must be a list of 1–50 distinct uuids.
 */
final class CreateBulkRestorationInput
{
    #[ApiProperty(description: 'The kind of record to restore: its table (e.g. inventory_item, storage_location, customer).', required: true, schema: ['type' => 'string'])]
    public mixed $resource = null;

    #[ApiProperty(
        description: 'The archived records to restore: 1 to 50 distinct ids.',
        required: true,
        schema: ['type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'uniqueItems' => true, 'items' => ['type' => 'string', 'format' => 'uuid']],
    )]
    public mixed $ids = null;
}

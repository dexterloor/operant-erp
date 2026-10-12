<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\ApiResource;

use App\Modules\Warehouses\Application\StorageLocationView;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **The `StorageLocation` item `ListStorageLocations` serves** (warehouses slice 1 C1). §4.4.3's derivation (API-23):
 * `id`, the accepted fields `code`, `kind`, then `archived_at` (the table is `soft`). `warehouse_id` is path-implied and
 * not served (`SupplierProductResource`'s `supplier_id` precedent).
 *
 * **No operation is declared on this class yet** — `CreateStorageLocation` and `UpdateStorageLocation` arrive at C3, and
 * API Platform mints a component only from an operation's input or output. So `StorageLocationCollection.items` publishes
 * {@see self::WIRE_SCHEMA} inline rather than a `$ref` that would dangle (`ListSalesOrderLines`' precedent, `3e55a99`),
 * and `StorageLocationListTest::theWireSchemaIsTheResourcesOwnMembers` holds the two spellings equal.
 *
 * `kind` is one of `bin`, `shelf`, `zone`, `staging`, `quarantine` (`storage_location_kind_values`). It decides whether
 * stock in the location can be picked (`quarantine` never can — `PickableStock`), which is why changing it is C3's
 * guarded write, not this read's concern.
 */
final class StorageLocationResource
{
    /**
     * **The shape `StorageLocationCollection.items` publishes, written here rather than as a `$ref`** — this resource has
     * no built operation, so API Platform mints no `StorageLocation` component (measured at C1 by
     * `OpenApiContractTest::everyReferenceInTheSpecResolves`, as `ListSalesOrderLines` measured it). **When C3 ships the
     * first `StorageLocation` write, delete this and use a `$ref`,** because the component will exist from that commit on.
     *
     * @var array<string, mixed>
     */
    public const WIRE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'string'],
            'code' => ['type' => 'string'],
            'kind' => ['type' => 'string'],
            // Nullable: OpenAPI 3.1 spells it as a type union, the form API Platform emits for `archived_at` elsewhere.
            'archived_at' => ['type' => ['string', 'null']],
        ],
    ];

    public function __construct(
        public string $id,
        public string $code,
        public string $kind,
        #[SerializedName('archived_at')]
        public ?string $archivedAt,
    ) {
    }

    public static function fromView(StorageLocationView $view): self
    {
        return new self($view->id, $view->code, $view->kind, $view->archivedAt);
    }
}

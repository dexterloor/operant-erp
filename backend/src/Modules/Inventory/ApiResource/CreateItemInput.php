<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use App\SharedKernel\Reference\UnitsOfMeasure;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `CreateItem`'s accepted request fields, exhaustively (§4.2).
 *
 * **There is no `id` property, and that is B-3.** The row identifier is minted
 * server-side by `Uuid::v4()` (API-10, D-12). This class is what the generated
 * spec documents as the request schema, so the phase-2 check reads its absence
 * from the artifact rather than from a promise.
 *
 * `supplier_id` **is** here and is not a violation: §3.1 case 1 -- a foreign-key
 * reference names an *existing* row the caller is entitled to see, validated
 * under the caller's tenant context. D-12's oracle is about the id of the row
 * being created.
 *
 * The three quantities are `numeric(19,6)` (S-12) and are carried as decimal
 * **strings** in both directions. A JSON number is a double on the way through
 * most clients, and a reorder point that rounds is a forecast input that
 * silently stops being deterministic (ADR-004).
 */
final class CreateItemInput
{
    #[ApiProperty(required: true, schema: ['type' => 'string', 'minLength' => 1])]
    public ?string $sku = null;

    #[ApiProperty(required: true, schema: ['type' => 'string', 'minLength' => 1])]
    public ?string $name = null;

    /**
     * Served as one of {@see UnitsOfMeasure::CODES}, never `null` and with NO `default` (BK-22, items batch C2): the unit
     * is required on a create (an absent or `null` unit is refused `required`), and a required picker starts unselected.
     * An annotation only; `ItemWriter` still looks the code up in `unit_of_measure`.
     */
    #[SerializedName('unit_of_measure_code')]
    #[ApiProperty(required: true, schema: ['type' => 'string', 'enum' => UnitsOfMeasure::CODES])]
    public ?string $unitOfMeasureCode = null;

    #[SerializedName('supplier_id')]
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'format' => 'uuid'])]
    public ?string $supplierId = null;

    #[SerializedName('reorder_point')]
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'minLength' => 1])]
    public ?string $reorderPoint = null;

    #[SerializedName('reorder_quantity')]
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'minLength' => 1])]
    public ?string $reorderQuantity = null;

    #[SerializedName('safety_stock')]
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'minLength' => 1])]
    public ?string $safetyStock = null;
}

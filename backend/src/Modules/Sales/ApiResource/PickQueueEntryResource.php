<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

use App\Modules\Sales\Application\PickQueueEntryView;
use App\Modules\Sales\Application\SalesNamedRef;
use App\Modules\Sales\Application\SalesOrderViews;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **One row of the pick queue — `ListPickQueueEntries`' item** (BK-21; `API.md` §4.4.3's PickQueueEntry row). An open,
 * released sales order: its number, its raw status, the date it was confirmed, and its customer's and warehouse's
 * `{id, name}`. **Nothing else** — no money, quantity, bin or ship-to column (`SECURITY_MODEL.md` PC-9's fifth exception;
 * `PickQueueReadTest` holds the member set).
 *
 * Not an `ApiResource` of its own: no §4.2 row reads one entry, so API Platform mints no component for it and
 * `PickQueueEntryCollection` publishes the item schema INLINE ({@see self::WIRE_SCHEMA}), held equal to this class's members
 * by `PickQueueReadTest`. `ResponseShapeTest` finds it by the `<Resource>Resource` convention beside its envelope.
 *
 * `ordered_at` is non-null on the wire because the reader's row predicate carries `ordered_at IS NOT NULL` — the column is
 * nullable and no CHECK ties it to the status.
 */
final readonly class PickQueueEntryResource
{
    /** The published item schema — the members below, nothing more. `status` is the open segment, bound so it cannot drift. */
    public const WIRE_SCHEMA = [
        'type' => 'object',
        'required' => ['id', 'order_number', 'status', 'ordered_at', 'customer', 'warehouse'],
        'properties' => [
            'id' => ['type' => 'string', 'format' => 'uuid'],
            'order_number' => ['type' => 'string'],
            'status' => ['type' => 'string', 'enum' => SalesOrderViews::SEGMENTS['open']],
            'ordered_at' => ['type' => 'string', 'format' => 'date-time'],
            'customer' => SalesReferenceSchema::NAMED,
            'warehouse' => SalesReferenceSchema::NAMED,
        ],
    ];

    public function __construct(
        public string $id,
        #[SerializedName('order_number')]
        public string $orderNumber,
        public string $status,
        #[SerializedName('ordered_at')]
        public string $orderedAt,
        public SalesNamedRef $customer,
        public SalesNamedRef $warehouse,
    ) {
    }

    public static function fromView(PickQueueEntryView $view): self
    {
        return new self($view->id, $view->orderNumber, $view->status, $view->orderedAt, $view->customer, $view->warehouse);
    }
}

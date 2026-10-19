<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure\Entity;

use App\Modules\Warehouses\Infrastructure\Entity\StorageLocation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Modules\Inventory. **Append-only.** The ledger stock_level is the balance of.
 *
 * ---------------------------------------------------------------------------
 * Immutability is a grant, not a convention
 * ---------------------------------------------------------------------------
 * Version20260906000100 grants erp_app `SELECT, INSERT` on this table and
 * nothing else -- no UPDATE, no DELETE -- for the same reason audit_log is
 * INSERT-only (S-9). A corrected movement is a compensating movement, never an
 * edit. So this class exposes no mutator of any kind: an `UPDATE` the ORM
 * emitted would not be a bug caught in review, it would be `permission denied`
 * in production, and the honest place to prevent it is here.
 *
 * `quantity` is signed and numeric(19,6) (S-12) -- negative for stock leaving,
 * positive for stock arriving -- and `stock_movement_quantity_nonzero` forbids
 * zero, because a movement of nothing is a bug in the caller and not a fact
 * worth recording.
 *
 * ---------------------------------------------------------------------------
 * source_type / source_id is the "did this document already post?" key
 * ---------------------------------------------------------------------------
 * The pair names the document that produced the movement -- a goods receipt, a
 * fulfilment, a sales return. (Corrected 2026-10-16, stock count C0: this read
 * "is the idempotency key" and listed "an adjustment" as a document. A stock
 * count has no originating document and carries neither half; its RETRY key is
 * `idempotency_key` / `request_digest` below.) ARCHITECTURE O-6: a message that is retried after a
 * partial failure asks "did this goods receipt already post its movements?" and
 * `stock_movement_tenant_source_idx` is the index that answers it. Without the
 * pair a redelivery doubles the stock; with it the second attempt is a no-op.
 * Both halves are nullable together for movements that have no originating
 * document (a manual count), which is why the partial index carries
 * `(source_id IS NOT NULL)`.
 *
 * **A transfer leg names its partner leg** (stock transfer C1, 2026-10-20): each
 * of a `transfer_out` / `transfer_in` pair carries source_type `stock_movement`
 * and the other leg's id (`StockTransferrer`, written only as SQL text;
 * `SEC` stock-transfer-sec.md §2). C0's `stock_movement_transfer_source` CHECK
 * ties that value to exactly those two reasons, and `stock_movement_source_paired`
 * keeps the two halves set together.
 *
 * Two tenant-scoped parents, both single-column associations (A1.6); their
 * composite keys are hand-written in the migration and not mapped.
 */
#[ORM\Entity]
#[ORM\Table(name: 'stock_movement')]
#[ORM\UniqueConstraint(name: 'stock_movement_tenant_id_id_key', columns: ['tenant_id', 'id'])]
// "The tenant's movement history, newest first" — kept from W0-a; ends `(occurred_at, id)` since stock reads C2
// (`Version20261015000100`, `DATA`): `occurred_at` is whole-second business time, so a tie group is unbounded and the
// list's keyset `(occurred_at, id)` must be an index condition.
#[ORM\Index(name: 'stock_movement_tenant_occurred_id_idx', columns: ['tenant_id', 'occurred_at', 'id'])]
// "The movement history of this SKU" — `ListStockMovements?inventory_item_id=`, and ADR-004's forecasting input on its
// `(tenant_id, inventory_item_id, occurred_at)` prefix.
#[ORM\Index(name: 'stock_movement_tenant_item_occurred_id_idx', columns: ['tenant_id', 'inventory_item_id', 'occurred_at', 'id'])]
// "Did this document already post its movements" — ARCHITECTURE O-6.
#[ORM\Index(name: 'stock_movement_tenant_source_idx', columns: ['tenant_id', 'source_type', 'source_id'], options: ['where' => '(source_id IS NOT NULL)'])]
// `CreateStockAdjustment`'s retry key (stock count C0, `Version20261016000100`): TOTAL, the table is class `none`; NULL keys
// (every receipt, shipment and return) never collide.
#[ORM\UniqueConstraint(name: 'stock_movement_idem_unique', columns: ['tenant_id', 'idempotency_key'])]
class StockMovement
{
    public const REASON_RECEIPT = 'receipt';
    public const REASON_FULFILMENT = 'fulfilment';
    public const REASON_TRANSFER_IN = 'transfer_in';
    public const REASON_TRANSFER_OUT = 'transfer_out';
    public const REASON_ADJUSTMENT = 'adjustment';
    public const REASON_RETURN = 'return';

    public const SOURCE_GOODS_RECEIPT = 'goods_receipt';
    public const SOURCE_FULFILMENT = 'fulfilment';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** An ordinary mapped field. Never an #[ORM\Id], never a join column (A1.6). */
    #[ORM\Column(name: 'tenant_id', type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\ManyToOne(targetEntity: InventoryItem::class)]
    #[ORM\JoinColumn(name: 'inventory_item_id', referencedColumnName: 'id', nullable: false)]
    private InventoryItem $inventoryItem;

    #[ORM\ManyToOne(targetEntity: StorageLocation::class)]
    #[ORM\JoinColumn(name: 'storage_location_id', referencedColumnName: 'id', nullable: false)]
    private StorageLocation $storageLocation;

    /** Signed, and never zero: `stock_movement_quantity_nonzero`. */
    #[ORM\Column(type: Types::DECIMAL, precision: 19, scale: 6)]
    private string $quantity;

    #[ORM\Column(type: Types::TEXT)]
    private string $reason;

    /** Half of the idempotency key; null only where no document produced this. */
    #[ORM\Column(name: 'source_type', type: Types::TEXT, nullable: true)]
    private ?string $sourceType = null;

    /**
     * The other half. A scalar rather than an association: the document may be a
     * goods_receipt or a fulfilment, so there is no single target entity, and the
     * value is a lookup key rather than a navigable edge.
     */
    #[ORM\Column(name: 'source_id', type: UuidType::NAME, nullable: true)]
    private ?Uuid $sourceId = null;

    #[ORM\Column(name: 'occurred_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $occurredAt;

    /**
     * The hex SHA-256 of the `Idempotency-Key` header (D-B), never the header — set only by the stock count's writer, in
     * its raw `INSERT` (stock count C0, `Version20261016000100`). **Nullable**: the receipt, shipment and return writers
     * name neither column, and rows predating it exist on any database that has moved stock. No mutator, as for every
     * column of this append-only table; never read into the domain.
     */
    #[ORM\Column(name: 'idempotency_key', type: Types::TEXT, nullable: true)]
    private ?string $idempotencyKey = null;

    /** `RequestDigest::of()` of the count that wrote the row; set and NULL together with the key (`stock_movement_key_both_or_neither`). */
    #[ORM\Column(name: 'request_digest', type: Types::TEXT, nullable: true)]
    private ?string $requestDigest = null;

    public function __construct(
        Uuid $id,
        Uuid $tenantId,
        InventoryItem $inventoryItem,
        StorageLocation $storageLocation,
        string $quantity,
        string $reason,
        ?string $sourceType = null,
        ?Uuid $sourceId = null,
        ?\DateTimeImmutable $occurredAt = null,
    ) {
        if (0 === bccomp($quantity, '0', 6)) {
            throw new \InvalidArgumentException('A stock movement of zero is not a fact worth recording.');
        }

        if ((null === $sourceType) !== (null === $sourceId)) {
            throw new \InvalidArgumentException('source_type and source_id are set together or not at all.');
        }

        // `SECURITY_MODEL.md` E-26's entity half: `reason` is one of this class's own
        // `REASON_*` values -- the constructor validated quantity and the source pairing
        // and NOT this, which is the defect `SESSION_LOG.md` Move 74 found (CD-2 (ii)).
        // No `src/` path constructs this entity today (the writers are raw SQL, bound by
        // the write-path half of E-26 in `WriteOwnershipTest`); this is the guard the
        // first ORM writer meets.
        if (!\in_array($reason, [
            self::REASON_RECEIPT,
            self::REASON_FULFILMENT,
            self::REASON_TRANSFER_IN,
            self::REASON_TRANSFER_OUT,
            self::REASON_ADJUSTMENT,
            self::REASON_RETURN,
        ], true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a stock movement reason.', $reason));
        }

        // E-27's refusal of `return` LIFTED here on 2026-10-01 (sales step 7 R2), in the commit that built
        // `CreateSalesReturn`, shipped `sales_return.create` and registered `return` -> `StockReturner` (E-26). It refused
        // the reason until then; E-27 is rewritten in the same commit.

        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->inventoryItem = $inventoryItem;
        $this->storageLocation = $storageLocation;
        $this->quantity = $quantity;
        $this->reason = $reason;
        $this->sourceType = $sourceType;
        $this->sourceId = $sourceId;
        $this->occurredAt = $occurredAt ?? new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenantId(): Uuid
    {
        return $this->tenantId;
    }

    public function getInventoryItem(): InventoryItem
    {
        return $this->inventoryItem;
    }

    public function getStorageLocation(): StorageLocation
    {
        return $this->storageLocation;
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getSourceType(): ?string
    {
        return $this->sourceType;
    }

    public function getSourceId(): ?Uuid
    {
        return $this->sourceId;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}

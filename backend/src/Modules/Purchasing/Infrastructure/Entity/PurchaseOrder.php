<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Infrastructure\Entity;

use App\Modules\Suppliers\Infrastructure\Entity\Supplier;
use App\Modules\UserManagement\Infrastructure\Entity\AppUser;
use App\Modules\Warehouses\Infrastructure\Entity\Warehouse;
use App\Platform\Domain\GuardsStatusTransitions;
use App\Platform\Domain\IllegalTransition;
use App\SharedKernel\Reference\Currency;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Modules\Purchasing. **The entity W0-a could not test.**
 *
 * A1.6 disqualified the composite ORM identifier because the association, not
 * the field, owns a shared `tenant_id` column: with `$supplier = null` Doctrine
 * writes NULL into tenant_id, and with the field set to one tenant and the
 * parent belonging to another the parent silently wins. W0-a proved the
 * replacement works -- scalar id, `tenantId` as a plain field, single-column
 * association, composite FK by hand -- but it proved it on an entity with
 * **one** parent. Its second composite key was on `stock_movement`, which had
 * no mapping at all, so "does this survive an entity that is a child of two
 * tenant-scoped parents at once" stayed open. That is the realistic ERP shape
 * and it is exactly where the rejected mapping failed.
 *
 * This class has **three** tenant-scoped parents -- supplier, warehouse and the
 * ordering user -- each a single-column association whose composite foreign key
 * is written by hand in Version20260906000100:
 *
 *   FOREIGN KEY (tenant_id, supplier_id)        REFERENCES supplier  (tenant_id, id)
 *   FOREIGN KEY (tenant_id, warehouse_id)       REFERENCES warehouse (tenant_id, id)
 *   FOREIGN KEY (tenant_id, created_by_user_id) REFERENCES app_user  (tenant_id, id)
 *
 * All three share the single `tenant_id` column, which is the case the rejected
 * mapping could not express at all: three associations cannot each own the same
 * column. Under this shape none of them owns it -- the field does -- and
 * Postgres enforces the tenant agreement regardless of what the ORM believes.
 * TwoParentCompositeKeyTest is the measurement.
 */
#[ORM\Entity]
#[ORM\Table(name: 'purchase_order')]
#[ORM\UniqueConstraint(name: 'purchase_order_tenant_id_id_key', columns: ['tenant_id', 'id'])]
#[ORM\UniqueConstraint(name: 'purchase_order_tenant_number_live', columns: ['tenant_id', 'order_number'], options: ['where' => '(deleted_at IS NULL)'])]
#[ORM\UniqueConstraint(name: 'purchase_order_tenant_idem_live', columns: ['tenant_id', 'idempotency_key'], options: ['where' => '(deleted_at IS NULL)'])]
#[ORM\Index(name: 'purchase_order_tenant_open_idx', columns: ['tenant_id', 'ordered_at'], options: ['where' => "((deleted_at IS NULL) AND (status = ANY (ARRAY['ordered'::text, 'partially_received'::text])))"])]
#[ORM\Index(name: 'purchase_order_tenant_supplier_idx', columns: ['tenant_id', 'supplier_id', 'ordered_at'], options: ['where' => '(deleted_at IS NULL)'])]
class PurchaseOrder
{
    use GuardsStatusTransitions;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ORDERED = 'ordered';
    public const STATUS_PARTIALLY_RECEIVED = 'partially_received';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /**
     * An ordinary mapped field. Never an #[ORM\Id], never a join column, and in
     * particular not shared with any of the three associations below -- that is
     * the whole of A1.6.
     */
    #[ORM\Column(name: 'tenant_id', type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\Column(name: 'order_number', type: Types::TEXT)]
    private string $orderNumber;

    /**
     * **API-O-31 step 5 — the client's retry key, and the reason this column is
     * nullable is HISTORY rather than the product's main path.**
     *
     * `CreatePurchaseOrder`'s §4.2 `Idem` cell is
     * `unique:purchase_order_tenant_idem_live`, so the operation demands an
     * `Idempotency-Key` header and the value lands here. A retried timeout then
     * collides on that live-row unique exactly as a repeated `CreateItem`
     * collides on `sku`, which is the only route by which an operation whose
     * response carries a server-minted `id` can be made retry-safe: the envelope
     * store cannot serve it, because §2.2 serializes the response after commit
     * and the `id` and `Location` do not exist until the insert has happened.
     *
     * **NULL means "raised before `Version20260911000100`".** It does not mean
     * "optional". ADR-008:231 criticised a nullable column justified as *"a
     * fixture argument for a column the product writes NULL to on its main
     * automated path"*, and attached the correction this docblock exists to
     * honour: *"the migration cannot tell you why a column is nullable and the
     * entity can."* Two NULLs do not collide — Postgres treats them as distinct
     * in a unique index — which is what lets the historical rows coexist.
     *
     * **There is still no setter, and as of step 6 that is a decision rather
     * than a residual.** `CreatePurchaseOrder` writes this column in the
     * `INSERT` itself (`PurchaseOrderWriter::create()`), the way every other
     * write in this slice reaches the database, and the **constructor** is the
     * ORM route to the same value. A setter would be a second write path for a
     * value that may only ever be set once, at insert, and
     * `Version20260911000200`'s `BEFORE INSERT` trigger is what makes "at
     * insert" non-negotiable: a row arriving without a key is refused by the
     * database, so there is no state in which a later `setIdempotencyKey()`
     * would be the right repair.
     */
    #[ORM\Column(name: 'idempotency_key', type: Types::TEXT, nullable: true)]
    private ?string $idempotencyKey = null;

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(name: 'supplier_id', referencedColumnName: 'id', nullable: false)]
    private Supplier $supplier;

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'warehouse_id', referencedColumnName: 'id', nullable: false)]
    private Warehouse $warehouse;

    /** Nullable: a purchase order raised by the reorder agent has no human author. */
    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'created_by_user_id', referencedColumnName: 'id', nullable: true)]
    private ?AppUser $createdBy = null;

    #[ORM\Column(type: Types::TEXT, options: ['default' => 'draft'])]
    private string $status = self::STATUS_DRAFT;

    #[ORM\ManyToOne(targetEntity: Currency::class)]
    #[ORM\JoinColumn(name: 'currency_code', referencedColumnName: 'code', nullable: false)]
    private Currency $currency;

    /** S-12: money is numeric(19,4) and a string in PHP. Never a float. */
    #[ORM\Column(name: 'total_amount', type: Types::DECIMAL, precision: 19, scale: 4, options: ['default' => '0'])]
    private string $totalAmount = '0';

    #[ORM\Column(name: 'ordered_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $orderedAt = null;

    #[ORM\Column(name: 'expected_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expectedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'deleted_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    /**
     * `$idempotencyKey` is **optional in the signature and mandatory at the
     * database**, and the asymmetry is deliberate rather than sloppy.
     *
     * Optional here, because this parameter is the *only* way an ORM-constructed
     * order can carry one — there is no setter, by the argument on the property
     * — and defaulting it keeps `null` expressible, which is what a row raised
     * before `Version20260911000100` legitimately holds.
     *
     * Mandatory there, because `Version20260911000200`'s `BEFORE INSERT` trigger
     * refuses a row that arrives without one. So a caller may *construct* an
     * order with no key and cannot *persist* it, and the refusal names the column
     * and the reason rather than surfacing as a silent NULL on a live-row unique
     * that then refuses nothing. **That is the whole design: the value is set
     * once, at construction or in the `INSERT`, and never afterwards.**
     */
    public function __construct(
        Uuid $id,
        Uuid $tenantId,
        string $orderNumber,
        Supplier $supplier,
        Warehouse $warehouse,
        Currency $currency,
        ?string $idempotencyKey = null,
    ) {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->orderNumber = $orderNumber;
        $this->supplier = $supplier;
        $this->warehouse = $warehouse;
        $this->currency = $currency;
        $this->idempotencyKey = $idempotencyKey;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenantId(): Uuid
    {
        return $this->tenantId;
    }

    public function getOrderNumber(): string
    {
        return $this->orderNumber;
    }

    /** NULL exactly on orders raised before `Version20260911000100`. */
    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    public function getSupplier(): Supplier
    {
        return $this->supplier;
    }

    public function getWarehouse(): Warehouse
    {
        return $this->warehouse;
    }

    public function getCreatedBy(): ?AppUser
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?AppUser $user): void
    {
        $this->createdBy = $user;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCurrency(): Currency
    {
        return $this->currency;
    }

    public function getTotalAmount(): string
    {
        return $this->totalAmount;
    }

    /**
     * E-19's *"guarded call"*, and until now it was guarded by nothing: it would
     * re-open a `cancelled` or a `received` order, and `purchase_order_ordered_at_set`
     * is satisfied by that write. §8.3's matrix makes `draft` the only legal source.
     *
     * **Step 6a (product-owner ruling, 2026-09-11): an order with no live lines
     * is not placed.** This class maps no lines, so the caller passes the count
     * of `purchase_order_line` rows with `deleted_at IS NULL`, read inside the
     * transaction that will flush this change. The parameter is required, with
     * no default, so any caller has to *state* a count rather than skip it —
     * today `PurchaseOrderApproval` is the only one. **This method checks only
     * the number it is handed**: it refuses a count below 1 and cannot tell a
     * true count from a false one, so counting honestly is the caller's job. The status
     * guard runs first, so a replayed approval is still answered with the status
     * refusal it relies on; both guards run before anything is written.
     */
    public function place(\DateTimeImmutable $orderedAt, int $liveLineCount, ?\DateTimeImmutable $expectedAt = null): void
    {
        $this->refuseUnlessStatusIsOneOf(__FUNCTION__, [self::STATUS_DRAFT]);

        if ($liveLineCount < 1) {
            throw IllegalTransition::unmetPrecondition(
                self::class,
                __FUNCTION__,
                $this->status,
                'has_a_live_line',
                'This purchase order has no lines. Add at least one line before ordering it.',
            );
        }

        $this->status = self::STATUS_ORDERED;
        $this->orderedAt = $orderedAt;
        $this->expectedAt = $expectedAt;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * **§8.3's cancel row, and the method X9-1 exists to find missing.**
     *
     * Until this commit `PurchaseOrder` had no cancellation method at all:
     * `STATUS_CANCELLED` was reachable by nothing, `CancelPurchaseOrder` was
     * declared in §4.2 and unimplementable, and `TransitionGuardTest`'s clause-(b)
     * pin carried the status as owed. §8.3's matrix row is the authority for the
     * three legal sources and they are **passed in as this class's own constants**,
     * never as literals — API-15, and `noGuardEnumeratesAStatusAsAStringLiteral`
     * is where a literal fails.
     *
     * **Why `partially_received` is legal and `received` is not**: a delivery
     * that has partly arrived is a commitment the supplier has not completed, and
     * L-9's reversal register routes the correction for what did arrive through a
     * stock adjustment rather than through the order. A fully received order has
     * nothing left to withdraw, so cancelling it would repudiate a commitment
     * that has already been met on both sides.
     *
     * **`ordered_at` is deliberately not touched.** An order cancelled after it
     * was placed keeps the date it was placed on — that is the fact the trail
     * needs — and one cancelled while still a draft never had one.
     * `purchase_order_ordered_at_set` was widened by `Version20260911000200` to
     * admit exactly that second row; before it, this transition was legal in the
     * contract and refused by the database.
     *
     * **Who may call it is not this method's business.** DSoD-1's
     * `canceller != creator` is a rule about the *actor*, which an entity does
     * not have — `PurchaseOrderDutySeparation` is where it lives, and
     * `PurchaseOrderCancellation` is what runs the two in E-19's fixed order.
     */
    public function cancel(): void
    {
        $this->refuseUnlessStatusIsOneOf(__FUNCTION__, [
            self::STATUS_DRAFT,
            self::STATUS_ORDERED,
            self::STATUS_PARTIALLY_RECEIVED,
        ]);

        $this->status = self::STATUS_CANCELLED;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }

    public function delete(): void
    {
        $this->deletedAt = new \DateTimeImmutable();
        $this->updatedAt = $this->deletedAt;
    }
}

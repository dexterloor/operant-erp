<?php

declare(strict_types=1);

namespace App\Modules\Sales\Infrastructure\Entity;

use App\Modules\Customers\Infrastructure\Entity\Customer;
use App\Modules\UserManagement\Infrastructure\Entity\AppUser;
use App\Modules\Warehouses\Infrastructure\Entity\Warehouse;
use App\Platform\Domain\GuardsStatusTransitions;
use App\Platform\Domain\IllegalTransition;
use App\SharedKernel\Reference\Country;
use App\SharedKernel\Reference\Currency;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Modules\Sales. PurchaseOrder's mirror on the sell side, and the second
 * entity with **three** tenant-scoped parents — customer, warehouse and the
 * ordering user — each a single-column association whose composite foreign key
 * is written by hand in Version20260906000100:
 *
 *   FOREIGN KEY (tenant_id, customer_id)        REFERENCES customer  (tenant_id, id)
 *   FOREIGN KEY (tenant_id, warehouse_id)       REFERENCES warehouse (tenant_id, id)
 *   FOREIGN KEY (tenant_id, created_by_user_id) REFERENCES app_user  (tenant_id, id)
 *
 * All three share the single `tenant_id` column, which is precisely what the
 * rejected composite-identifier mapping could not express: three associations
 * cannot each own the same column. Under this shape none of them owns it — the
 * field does — and Postgres enforces the tenant agreement regardless of what
 * the ORM believes (A1.6).
 *
 * ---------------------------------------------------------------------------
 * Why `ship_to_*` are snapshot columns and not a customer_address_id
 * ---------------------------------------------------------------------------
 * `customer_address` is hard-deletable exactly because these four columns
 * exist. An order that pointed at a live address row would silently rewrite the
 * history of a past shipment every time the customer moves — the shipment that
 * went to the old warehouse district would, a year later, claim it went to the
 * new head office. Copying the address at order time makes the order a record
 * of what happened rather than a view of what is currently true.
 *
 * `ship_to_country_code` is the one exception, and it is not an exception to
 * that rule: it is a plain reference-data association to `Country` (ADR-002 §6,
 * immutable catalogue), not a link back to the address row.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sales_order')]
#[ORM\UniqueConstraint(name: 'sales_order_tenant_id_id_key', columns: ['tenant_id', 'id'])]
// ADR-007 §1: business uniqueness goes partial the moment the row can be
// soft-deleted. UNIQUE (tenant_id, id) above stays total, because a composite
// FK cannot reference a partial index and children reference deleted parents.
#[ORM\UniqueConstraint(name: 'sales_order_tenant_number_live', columns: ['tenant_id', 'order_number'], options: ['where' => '(deleted_at IS NULL)'])]
// A retried CreateSalesOrder collides here (V2-R-11, Version20260919000100).
// Partial, unlike `goods_receipt_idem_unique`, because this table soft-deletes
// and a discarded draft must release its key.
#[ORM\UniqueConstraint(name: 'sales_order_tenant_idem_live', columns: ['tenant_id', 'idempotency_key'], options: ['where' => '(deleted_at IS NULL)'])]
// "Orders waiting to ship" — the warehouse's work queue.
#[ORM\Index(name: 'sales_order_tenant_open_idx', columns: ['tenant_id', 'ordered_at'], options: ['where' => "((deleted_at IS NULL) AND (status = ANY (ARRAY['confirmed'::text, 'partially_fulfilled'::text])))"])]
// "This customer's order history" — the account screen.
#[ORM\Index(name: 'sales_order_tenant_customer_idx', columns: ['tenant_id', 'customer_id', 'ordered_at'], options: ['where' => '(deleted_at IS NULL)'])]
class SalesOrder
{
    use GuardsStatusTransitions;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_PARTIALLY_FULFILLED = 'partially_fulfilled';
    public const STATUS_FULFILLED = 'fulfilled';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /**
     * An ordinary mapped field. Never an #[ORM\Id], never a join column, and in
     * particular not shared with any of the three associations below — that is
     * the whole of A1.6.
     */
    #[ORM\Column(name: 'tenant_id', type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\Column(name: 'order_number', type: Types::TEXT)]
    private string $orderNumber;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id', nullable: false)]
    private Customer $customer;

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'warehouse_id', referencedColumnName: 'id', nullable: false)]
    private Warehouse $warehouse;

    /** Nullable: an order raised by an agent or an import has no human author. */
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

    // ---- The shipping snapshot. Copied at order time, never a foreign key. ----

    #[ORM\Column(name: 'ship_to_line', type: Types::TEXT, options: ['default' => ''])]
    private string $shipToLine = '';

    #[ORM\Column(name: 'ship_to_city', type: Types::TEXT, options: ['default' => ''])]
    private string $shipToCity = '';

    #[ORM\Column(name: 'ship_to_postal_code', type: Types::TEXT, options: ['default' => ''])]
    private string $shipToPostalCode = '';

    /**
     * Reference data (ADR-002 §6), not a link to the address row. A country
     * code is an immutable catalogue entry, so pointing at it cannot rewrite
     * where a past shipment went; the three text columns above are what carry
     * the mutable part of the address.
     */
    #[ORM\ManyToOne(targetEntity: Country::class)]
    #[ORM\JoinColumn(name: 'ship_to_country_code', referencedColumnName: 'code', nullable: false)]
    private Country $shipToCountry;

    /**
     * The hex SHA-256 of the `Idempotency-Key` header (D-B), never the header.
     * **Non-nullable, and it follows `GoodsReceipt::$idempotencyKey` rather than
     * `PurchaseOrder::$idempotencyKey`:** that column is nullable for rows older
     * than it, and no sales order predates this one — nothing in `src/` has ever
     * written the table (`Version20260919000100`). Set once, at construction or
     * in the `INSERT`, and there is no setter.
     */
    #[ORM\Column(name: 'idempotency_key', type: Types::TEXT)]
    private string $idempotencyKey;

    /** `RequestDigest::of()` of the request that created the row. Request plumbing, never read into the domain. */
    #[ORM\Column(name: 'request_digest', type: Types::TEXT)]
    private string $requestDigest;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'deleted_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct(
        Uuid $id,
        Uuid $tenantId,
        string $orderNumber,
        Customer $customer,
        Warehouse $warehouse,
        Currency $currency,
        Country $shipToCountry,
        string $idempotencyKey,
        string $requestDigest,
    ) {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->orderNumber = $orderNumber;
        $this->customer = $customer;
        $this->warehouse = $warehouse;
        $this->currency = $currency;
        $this->shipToCountry = $shipToCountry;
        $this->idempotencyKey = $idempotencyKey;
        $this->requestDigest = $requestDigest;
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

    public function getCustomer(): Customer
    {
        return $this->customer;
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

    public function getShipToCountry(): Country
    {
        return $this->shipToCountry;
    }

    /**
     * ⚠ **`shipTo()` USED TO BE HERE AND WAS DELETED ON 2026-09-21, WITH `UpdateSalesOrder`. DO NOT
     * BRING IT BACK WITHOUT READING `RESIDUAL_GAPS.md` G-6.**
     *
     * It was an ORM mutator assigning the four `ship_to_*` columns, and it had **zero callers** —
     * measured twice, anchored (`grep -rnE "(->|::)shipTo\b" src tests` returned nothing but prose):
     * `CreateSalesOrder` writes those columns in its raw `INSERT`, and `UpdateSalesOrder` writes them in
     * a raw `UPDATE`.
     *
     * **Why an uncalled method was worth deleting rather than leaving.** G-6's own shape analysis
     * planted three ways of writing these columns and measured what each reddens. The third —
     * `$fulfilment->getSalesOrder()->shipTo(…)`, an ORM write reached through an association — reddens
     * **neither** `WriteOwnershipTest` (it counts SQL statements) **nor** `PurchaseOrderRowLockTest`
     * (it needs the source to name `SalesOrder::class`). G-6 calls that third shape *"the likely one for
     * this gap's own candidates"*, because `Fulfilment`, `Invoice` and `SalesOrderLine` all expose
     * `getSalesOrder()`. **This method was that shape's only vehicle.** Deleting it does not make the
     * scans see an ORM mutator — nothing does, and G-6 says so in its own text — it removes the thing
     * they would have to see.
     *
     * A fulfilment- or invoice-side snapshot therefore has to write SQL, which both scans read.
     * **Anyone re-adding an entity mutator for these columns is re-opening G-6 and owes its entry an
     * edit in the same commit.**
     */
    /**
     * PC-4 calls this *"structurally the mirror of `place()`"*, and the mirror of
     * `place()`'s missing guard came with it: unguarded it re-opens a `cancelled`
     * or `fulfilled` order. §8.3: `draft` only.
     *
     * -----------------------------------------------------------------------
     * `$liveLineCount` — V2-R-22 Half 1, and it is D-C copied, not designed
     * -----------------------------------------------------------------------
     * D-C already ruled that a zero-line purchase order cannot be approved. The
     * selling side's analogue is worse rather than milder: a confirmed sales
     * order is a **customer commitment**, it lands on the warehouse's work queue
     * through `sales_order_tenant_open_idx`, and `IssueInvoice` would later bill
     * nothing. **The state is reachable, not hypothetical** —
     * `CreateSalesOrder` accepts an absent or null `lines[]` as a legal empty
     * draft, so an order with no lines exists in the database today through a
     * shipped operation.
     *
     * The parameter is **required, with no default**, for `place()`'s reason:
     * a caller has to *state* a count rather than skip it. **This method checks
     * only the number it is handed** — it refuses a count below 1 and cannot
     * tell a true count from a false one, so counting honestly is the caller's
     * job. The entity maps no lines and must not learn to; the count is read by
     * `SalesOrderConfirmation` inside the transaction, under RLS, after the row
     * lock.
     *
     * **The status guard runs first**, so a replayed confirmation is still
     * answered with the status refusal §4.2's `guard:sales_order.status` cell
     * relies on, and a `cancelled` empty order is told *wrong status* rather
     * than *add a line* — advice it could not follow, line writes requiring
     * `draft`. `TransitionGuardTest::theStatusGuardRefusesBeforeAnyPreconditionDoes`
     * pins that order, and the required scalar forces a
     * `PRECONDITION_SCALARS` row by reflection, so the guard is a mechanism
     * rather than a promise.
     *
     * **No reservation is written here, and that is ruled rather than omitted.**
     * V2-R-22 Half 2 adopted model A: `stock_level.qty_reserved` stays unwritten,
     * confirming is a document transition and nothing else. See
     * `SchemaManifest`'s `stock_level` entry and `docs/RESIDUAL_GAPS.md` G-7.
     *
     * -----------------------------------------------------------------------
     * `$customerIsLive` — C5 C3 (owner ruling Q-2, 2026-10-13)
     * -----------------------------------------------------------------------
     * **An order is not confirmed to a customer that has been archived.** The
     * sales twin of `PurchaseOrder::place()`'s `$supplierIsLive`: required, with
     * no default, for `$liveLineCount`'s reason, and trusted as stated — the
     * caller reads it inside its transaction by statement, never through
     * `$this->customer` (`SoftDeleteFilter` would hide an archived customer).
     * It runs **after** the line guard, so an empty draft is still told to add a
     * line and no response the line guard shipped changes. It is the backstop,
     * not the control: `CreateSalesOrder`/`UpdateSalesOrder` read the customer
     * `FOR KEY SHARE`, and `DeleteCustomer` (C5 C4b) refuses a customer with an
     * open order (a draft is open), so this fires only on an order reached by
     * owner SQL, a future `RestoreSalesOrder`, or a lock regression.
     */
    public function confirm(\DateTimeImmutable $orderedAt, int $liveLineCount, bool $customerIsLive): void
    {
        $this->refuseUnlessStatusIsOneOf(__FUNCTION__, [self::STATUS_DRAFT]);

        if ($liveLineCount < 1) {
            throw IllegalTransition::unmetPrecondition(
                self::class,
                __FUNCTION__,
                $this->status,
                'has_a_live_line',
                'This sales order has no lines. Add at least one line before confirming it.',
            );
        }

        if (!$customerIsLive) {
            throw IllegalTransition::unmetPrecondition(
                self::class,
                __FUNCTION__,
                $this->status,
                'customer_is_live',
                'This order\'s customer has been archived, so the order can\'t be confirmed. It can be confirmed once the customer is restored or another customer is chosen for it, or the order can be deleted.',
            );
        }

        $this->status = self::STATUS_CONFIRMED;
        $this->orderedAt = $orderedAt;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * **§8.3's sales cancel row — the method API-O-17 has been naming *(no method
     * exists)* since 2026-09-10, and the last of that row's four.**
     *
     * The three legal sources are §8.3's and are passed as this class's own
     * constants, never as literals — API-15, and
     * `noGuardEnumeratesAStatusAsAStringLiteral` is where a literal fails.
     * `STATUS_FULFILLED` is **not** among them: an order that has shipped in full
     * has nothing left to withdraw, which is `PurchaseOrder::cancel()`'s reason
     * for excluding `received` read onto the sell side.
     *
     * =======================================================================
     * There is NO duty-separation rule here, and ADR-008 REFUSES one
     * =======================================================================
     * The purchase mirror runs `PurchaseOrderDutySeparation` between the load and
     * this guard. **Nothing equivalent exists or may be added here**, and the
     * distinction is not an omission in ADR-008 — it is a decision ADR-008 §2
     * states in terms:
     *
     * > *"The sales side is deliberately asymmetric, and the asymmetry is stated
     * > rather than left looking like an oversight. `sales_rep` holds **both**
     * > `sales_order.confirm` and `sales_order.cancel`, where the buying side
     * > splits the equivalent pair. … placing a purchase order commits the
     * > tenant's money to an outside party, while confirming a sales order
     * > commits *the customer* and moves nothing out. **There is no outbound
     * > commitment for a cancellation to repudiate.** This is the weaker of the
     * > two controls and it is knowingly weaker — a rep who cancels confirmed
     * > orders can conceal lost sales, and **the compensating control is
     * > `audit_log`, not a second pair of hands.**"*
     *
     * ADR-008 **Amendment 1** — which the project notes compress to *"DSoD-1 extends
     * to cancellation as `canceller != creator`"* — is **purchase-only**, and its
     * own predicate says so by column: *"`purchase_order.cancel` is refused when
     * `purchase_order.created_by_user_id` equals the caller's `app_user.id`."*
     * `sales_order` has no `placed_by`-equivalent column, and SEC-O-9 verified
     * that `created_by_user_id` records **the creator, not the confirmer**, so the
     * comparison has no second hand to name even if the rule were wanted.
     * IA-O-8 states as a tested claim that F-5 *"has no mirror of this gap"*.
     *
     * **That quotation is why this operation writes an audit row** (see
     * `SalesOrderAuditWriter::recordCancelled()`): `audit_log` is not a nicety
     * here, it is the named compensating control for the split ADR-008 declines.
     *
     * =======================================================================
     * OI-2's remainder rule is a DERIVATION, so this method writes nothing for it
     * =======================================================================
     * §8.3's OI-2 block rules that cancelling a `partially_fulfilled` order
     * cancels the remainder, and defines it: *"per line,
     * `quantity − quantity_fulfilled` … **derived and never written into
     * `quantity`**. `quantity` stays what was ordered, so the order still states
     * what the customer asked for."* **No new status is added.** So the rule
     * prescribes no statement at all — no line `UPDATE`, no line `DELETE` and no
     * column — and `erp_app` holds no `DELETE` on either sales table precisely
     * because none is wanted (A4.4 duty 1's word is *"exactly"*).
     *
     * **The `partially_fulfilled` source is REACHABLE since 2026-09-22** (`CreateFulfilment`, sales step 5
     * commit 4, which writes that status and `quantity_fulfilled` through `recordFulfilment()` above). It was a
     * forward declaration with that hard trigger until then. Cancelling such an order cancels the derived
     * remainder and nothing else (owner ruling R5: OI-2 applied as ruled): `quantity_fulfilled` is preserved,
     * the shipped fulfilments stay billable under D-A, and `SalesOrderLineRow`'s V2-C-14(b) CASE reads the
     * remainder as 0. `FulfilmentCreateTest` drives it through the API.
     *
     * **`ordered_at` is deliberately not touched**, for `PurchaseOrder::cancel()`'s
     * reason: an order cancelled after it was confirmed keeps the date it was
     * confirmed on, which is the fact the trail needs, and one cancelled while
     * still a draft never had one. `sales_order` carries no `ordered_at` `CHECK`
     * to widen — verified against the catalogue — so no migration is owed for it.
     */
    public function cancel(): void
    {
        $this->refuseUnlessStatusIsOneOf(__FUNCTION__, [
            self::STATUS_DRAFT,
            self::STATUS_CONFIRMED,
            self::STATUS_PARTIALLY_FULFILLED,
        ]);

        $this->status = self::STATUS_CANCELLED;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * **§8.3's fulfilment-posting row — the transition API-O-17 named *(no method exists)* for, and the
     * last of its four.** Built with `CreateFulfilment` (sales step 5, commit 4, 2026-09-22), the mirror of
     * `PurchaseOrder::recordReceipt()`.
     *
     * Legal from `confirmed` and `partially_fulfilled`. The result is `fulfilled` when every live line has
     * reached its quantity with this fulfilment, and `partially_fulfilled` otherwise. **This method checks
     * only the flag it is handed**, as `recordReceipt()` does: the caller (`FulfilmentWriter`) decides it
     * from the locked lines plus the request, inside the transaction, after the row lock. The sources are
     * this class's own constants (API-15, `noGuardEnumeratesAStatusAsAStringLiteral`).
     *
     * `draft` (never released to the warehouse), `cancelled` (the remainder will not ship; OI-2) and
     * `fulfilled` (nothing left) are refused by the guard, which `TransitionGuardTest` pins, and
     * **`ordered_at` is untouched**: it records the confirmation. Nothing in the schema ties `status` to
     * `quantity_fulfilled` (`RED` P9, 2026-09-22) — this method and its one caller carry that rule alone.
     */
    public function recordFulfilment(bool $allLinesFulfilled): void
    {
        $this->refuseUnlessStatusIsOneOf(__FUNCTION__, [
            self::STATUS_CONFIRMED,
            self::STATUS_PARTIALLY_FULFILLED,
        ]);

        $this->status = $allLinesFulfilled ? self::STATUS_FULFILLED : self::STATUS_PARTIALLY_FULFILLED;
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

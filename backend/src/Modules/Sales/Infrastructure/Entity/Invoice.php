<?php

declare(strict_types=1);

namespace App\Modules\Sales\Infrastructure\Entity;

use App\Modules\Customers\Infrastructure\Entity\Customer;
use App\Modules\UserManagement\Infrastructure\Entity\AppUser;
use App\Platform\Domain\GuardsStatusTransitions;
use App\Platform\Persistence\Type\TimestampTzMicrosecondsImmutableType;
use App\SharedKernel\Reference\Currency;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Modules\Sales. The demand for payment.
 *
 * ---------------------------------------------------------------------------
 * Never deleted. **Voided**, which is an UPDATE
 * ---------------------------------------------------------------------------
 * There is no `deleted_at` here and never a DELETE grant. The table-level INSERT and UPDATE that
 * Version20260906000100 granted were revoked (ADR-002 Amendment 4, `Version20260916000300`), and each writer
 * grants exactly the COLUMNS it writes in its own migration: `IssueInvoice` INSERT on ten (C3,
 * `Version20260928000200`), `PayInvoice` UPDATE on four (C5, below). *(This sentence read "Today erp_app holds
 * SELECT and nothing more … no `src/` path writes an invoice yet" until C5 — stale since C3.)* An issued
 * invoice is a statement made to a third party and a row in someone's tax
 * record; it cannot be unsent. Cancelling one is the `void` status, and moving
 * to it is an UPDATE — which is why those builds grant UPDATE on a table that is
 * otherwise append-only, and still never DELETE. `paid` is likewise a status transition, guarded since sales
 * step 6 C5 by the two-way `invoice_paid_at_matches_status` and `invoice_paid_by_matches_status` (replacing the
 * one-way `invoice_paid_at_set`), so a paid invoice has a payment date and a recorder and nothing else has either.
 * **`erp_app` holds a COLUMN-level UPDATE on exactly `status`, `paid_at`, `paid_by_user_id` and `updated_at`**
 * (`Version20260928000400`), which is `markPaid()`'s flush and nothing more.
 *
 * `invoice_number_unique` is therefore total. Nothing here is soft-deleted, so
 * there is no invisible row for a re-used number to collide with and ADR-007
 * §1's partial-index rule does not apply.
 *
 * `total_amount` carries no DEFAULT, unlike sales_order's. A zero default on a
 * document that is sent to a customer would make "we failed to price it" look
 * like "it was free"; a failed INSERT is the safer failure.
 *
 * Tenant-scoped parents, all single-column associations whose composite
 * keys are hand-written in the migrations and not mapped (A1.6):
 *
 *   FOREIGN KEY (tenant_id, sales_order_id)    REFERENCES sales_order (tenant_id, id)
 *   FOREIGN KEY (tenant_id, customer_id)       REFERENCES customer    (tenant_id, id)
 *   FOREIGN KEY (tenant_id, fulfilment_id)     REFERENCES fulfilment  (tenant_id, id)   -- sales step 6 C2
 *   FOREIGN KEY (tenant_id, issued_by_user_id) REFERENCES app_user    (tenant_id, id)   -- sales step 6 C2
 *   FOREIGN KEY (tenant_id, paid_by_user_id)   REFERENCES app_user    (tenant_id, id)   -- sales step 6 C5, nullable
 *
 * **The billed shipment and the issuer (sales step 6, C2, `Version20260923000100`).** An invoice bills exactly one
 * fulfilment (D-A as amended), and at most one LIVE invoice exists per fulfilment —
 * `invoice_tenant_fulfilment_not_void`, a partial unique index whose predicate is a **lifecycle** predicate on this
 * deletion-class-`none` table (declared below so SchemaTool does not propose dropping it). `issued_by_user_id` is
 * NOT NULL by owner ruling Q5 (*"Column + audit event"*). **Nothing in `src/` constructs this entity:** `IssueInvoice`
 * (C3) writes it with raw SQL naming only its column-granted columns, because the ORM would insert columns
 * `erp_app` is not granted.
 *
 * The customer is denormalised onto the invoice rather than reached through the
 * order because the collections screen filters by account and must not join
 * through a soft-deleted order to do it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoice')]
#[ORM\UniqueConstraint(name: 'invoice_tenant_id_id_key', columns: ['tenant_id', 'id'])]
#[ORM\UniqueConstraint(name: 'invoice_number_unique', columns: ['tenant_id', 'invoice_number'])]
// One LIVE invoice per shipment (D-A). The `where` is the catalogue's normalised spelling, measured with
// `pg_get_expr(indpred, indrelid)` after the migration (M-5): `(status <> 'void'::text)`.
#[ORM\UniqueConstraint(name: 'invoice_tenant_fulfilment_not_void', columns: ['tenant_id', 'fulfilment_id'], options: ['where' => "(status <> 'void'::text)"])]
// "Overdue invoices, oldest first" — ADR-004's at-risk-account signal and the
// collections screen, in one index. Partial on status, not on a delete flag.
#[ORM\Index(name: 'invoice_tenant_due_idx', columns: ['tenant_id', 'due_at'], options: ['where' => "(status = ANY (ARRAY['issued'::text, 'overdue'::text]))"])]
// "This account's invoices" — the customer screen.
#[ORM\Index(name: 'invoice_tenant_customer_idx', columns: ['tenant_id', 'customer_id', 'issued_at'])]
class Invoice
{
    use GuardsStatusTransitions;

    public const STATUS_ISSUED = 'issued';
    public const STATUS_PAID = 'paid';
    public const STATUS_OVERDUE = 'overdue';
    public const STATUS_VOID = 'void';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** An ordinary mapped field (A1.6), shared with neither association below. */
    #[ORM\Column(name: 'tenant_id', type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\ManyToOne(targetEntity: SalesOrder::class)]
    #[ORM\JoinColumn(name: 'sales_order_id', referencedColumnName: 'id', nullable: false)]
    private SalesOrder $salesOrder;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id', nullable: false)]
    private Customer $customer;

    /** The shipment this invoice bills (D-A). */
    #[ORM\ManyToOne(targetEntity: Fulfilment::class)]
    #[ORM\JoinColumn(name: 'fulfilment_id', referencedColumnName: 'id', nullable: false)]
    private Fulfilment $fulfilment;

    /** Who issued it (owner ruling Q5). Never exposed on a read (`SEC` §2). */
    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'issued_by_user_id', referencedColumnName: 'id', nullable: false)]
    private AppUser $issuedBy;

    #[ORM\Column(name: 'invoice_number', type: Types::TEXT)]
    private string $invoiceNumber;

    #[ORM\Column(type: Types::TEXT, options: ['default' => 'issued'])]
    private string $status = self::STATUS_ISSUED;

    #[ORM\ManyToOne(targetEntity: Currency::class)]
    #[ORM\JoinColumn(name: 'currency_code', referencedColumnName: 'code', nullable: false)]
    private Currency $currency;

    /** S-12: money is numeric(19,4) and a string in PHP. Never a float. */
    #[ORM\Column(name: 'total_amount', type: Types::DECIMAL, precision: 19, scale: 4)]
    private string $totalAmount;

    #[ORM\Column(name: 'issued_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $issuedAt;

    #[ORM\Column(name: 'due_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $dueAt;

    /**
     * Written at MICROSECOND precision (sales step 6, C5): `invoice_paid_not_before_issue` compares it with `issued_at`,
     * which the database writes with microseconds, and the default type drops the fraction — a payment in the issue's
     * own second would land before it. See `TimestampTzMicrosecondsImmutableType`.
     */
    #[ORM\Column(name: 'paid_at', type: TimestampTzMicrosecondsImmutableType::NAME, nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    /**
     * Who recorded the payment (sales step 6, C5; OI-2 R1a). NULL unless `paid` — `invoice_paid_by_matches_status`
     * makes that a database fact — and written only by `markPaid()`, from the authenticated caller. Exposed as
     * `paid_by = {id, display_name}` under `invoice.view` (`SEC` §2, Q7; PC-11 (4a)); it is A2.3's input, since the
     * recorder may not reverse their own payment.
     */
    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'paid_by_user_id', referencedColumnName: 'id', nullable: true)]
    private ?AppUser $paidBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Uuid $id,
        Uuid $tenantId,
        SalesOrder $salesOrder,
        Customer $customer,
        Fulfilment $fulfilment,
        AppUser $issuedBy,
        string $invoiceNumber,
        Currency $currency,
        string $totalAmount,
        \DateTimeImmutable $dueAt,
    ) {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->salesOrder = $salesOrder;
        $this->customer = $customer;
        $this->fulfilment = $fulfilment;
        $this->issuedBy = $issuedBy;
        $this->invoiceNumber = $invoiceNumber;
        $this->currency = $currency;
        $this->totalAmount = $totalAmount;
        $this->dueAt = $dueAt;
        $this->createdAt = new \DateTimeImmutable();
        $this->issuedAt = $this->createdAt;
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

    public function getSalesOrder(): SalesOrder
    {
        return $this->salesOrder;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function getInvoiceNumber(): string
    {
        return $this->invoiceNumber;
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

    public function getIssuedAt(): \DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function getDueAt(): \DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function getPaidBy(): ?AppUser
    {
        return $this->paidBy;
    }

    /**
     * `invoice_paid_at_matches_status` and `invoice_paid_by_matches_status` (sales step 6, C5) are two-way: a paid
     * invoice has a payment date and a recorder, and nothing else has either. So the three are set together, in this
     * one method, and never separately — **the actor is a parameter of the transition** (`ARCH` §7 item 3, option
     * (a)), not a sibling setter a caller could forget. `$paidBy` is the authenticated caller; no request field can
     * name it (`SEC` §2).
     */
    public function markPaid(\DateTimeImmutable $paidAt, AppUser $paidBy): void
    {
        // `void()` voids the invoice, not the payment, so a `void` invoice that could be marked paid would leave a
        // settled receivable with no way back. §8.3: `issued` or `overdue`. (The way back from `paid` is the
        // reversal, `ReverseInvoicePayment` — never a void.)
        $this->refuseUnlessStatusIsOneOf(__FUNCTION__, [self::STATUS_ISSUED, self::STATUS_OVERDUE]);

        $this->status = self::STATUS_PAID;
        $this->paidAt = $paidAt;
        $this->paidBy = $paidBy;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** §8.3: `issued` only — a `paid` or `void` invoice does not become overdue. */
    public function markOverdue(): void
    {
        $this->refuseUnlessStatusIsOneOf(__FUNCTION__, [self::STATUS_ISSUED]);

        $this->status = self::STATUS_OVERDUE;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** The cancellation. Not a delete — see the class docblock. */
    public function void(): void
    {
        // Voiding a `paid` invoice silently removes a settled receivable from
        // `invoice_tenant_due_idx`'s complement. §8.3: `issued` or `overdue`.
        $this->refuseUnlessStatusIsOneOf(__FUNCTION__, [self::STATUS_ISSUED, self::STATUS_OVERDUE]);

        $this->status = self::STATUS_VOID;
        $this->updatedAt = new \DateTimeImmutable();
    }
}

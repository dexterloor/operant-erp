<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\GuardsStatusTransitions;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Modules\Sales. The demand for payment.
 *
 * ---------------------------------------------------------------------------
 * Never deleted. **Voided**, which is why UPDATE is retained
 * ---------------------------------------------------------------------------
 * There is no `deleted_at` here and no DELETE grant: erp_app holds SELECT,
 * INSERT and UPDATE and nothing more (Version20260906000100's "documents that
 * are voided or corrected, never removed" block). An issued invoice is a
 * statement made to a third party and a row in someone's tax record; it cannot
 * be unsent. Cancelling one is the `void` status, and moving to it is an
 * UPDATE — which is precisely why UPDATE stays granted on a table that is
 * otherwise append-only. `paid` is likewise a status transition, guarded by
 * `invoice_paid_at_set` so that a paid invoice cannot exist without a payment
 * date.
 *
 * `invoice_number_unique` is therefore total. Nothing here is soft-deleted, so
 * there is no invisible row for a re-used number to collide with and ADR-007
 * §1's partial-index rule does not apply.
 *
 * `total_amount` carries no DEFAULT, unlike sales_order's. A zero default on a
 * document that is sent to a customer would make "we failed to price it" look
 * like "it was free"; a failed INSERT is the safer failure.
 *
 * Two tenant-scoped parents, both single-column associations whose composite
 * keys are hand-written in the migration and not mapped (A1.6):
 *
 *   FOREIGN KEY (tenant_id, sales_order_id) REFERENCES sales_order (tenant_id, id)
 *   FOREIGN KEY (tenant_id, customer_id)    REFERENCES customer    (tenant_id, id)
 *
 * The customer is denormalised onto the invoice rather than reached through the
 * order because the collections screen filters by account and must not join
 * through a soft-deleted order to do it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoice')]
#[ORM\UniqueConstraint(name: 'invoice_tenant_id_id_key', columns: ['tenant_id', 'id'])]
#[ORM\UniqueConstraint(name: 'invoice_number_unique', columns: ['tenant_id', 'invoice_number'])]
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

    #[ORM\Column(name: 'paid_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Uuid $id,
        Uuid $tenantId,
        SalesOrder $salesOrder,
        Customer $customer,
        string $invoiceNumber,
        Currency $currency,
        string $totalAmount,
        \DateTimeImmutable $dueAt,
    ) {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->salesOrder = $salesOrder;
        $this->customer = $customer;
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

    /**
     * `invoice_paid_at_set` refuses a paid invoice with no payment date, so the
     * two are set together and never separately.
     */
    public function markPaid(\DateTimeImmutable $paidAt): void
    {
        // L-9: there is no un-pay. `void()` voids the invoice, not the payment,
        // so a `void` invoice that could be marked paid would leave a settled
        // receivable with no way back. §8.3: `issued` or `overdue`.
        $this->refuseUnlessStatusIsOneOf(__FUNCTION__, [self::STATUS_ISSUED, self::STATUS_OVERDUE]);

        $this->status = self::STATUS_PAID;
        $this->paidAt = $paidAt;
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

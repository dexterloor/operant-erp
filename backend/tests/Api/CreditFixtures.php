<?php

declare(strict_types=1);

namespace App\Tests\Api;

use Symfony\Component\Uid\Uuid;

/**
 * **Credit notes and bare invoices, written as the schema OWNER** (sales step 7 C0). At C0 no operation writes a credit
 * note — `erp_app` holds SELECT only on `credit_note`/`credit_note_line` until `IssueCreditNote` (C1) — so every test
 * that reads a credited invoice seeds its credits here. **A green run proves the READS, not what the API can produce**
 * (the G-7 shape): the over-credited row, for one, is unreachable through the API by design §4.4 step 8b's telescope
 * and exists only to pin `<= 0`.
 *
 * @phpstan-require-extends PurchaseOrderTestCase
 */
trait CreditFixtures
{
    private int $creditNoteSequence = 0;

    /** A line-less fulfilment of `$orderId`, as the owner (`InvoiceListTest`'s shape), so each invoice has its own. */
    protected function seedBareFulfilment(string $orderId): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO fulfilment (id, tenant_id, sales_order_id, warehouse_id, fulfilment_number, shipped_by_user_id,
                                    idempotency_key, request_digest)
            SELECT ?::uuid, so.tenant_id, so.id, so.warehouse_id, ?, ?::uuid,
                   encode(sha256(gen_random_uuid()::text::bytea), 'hex'), encode(sha256(gen_random_uuid()::text::bytea), 'hex')
              FROM sales_order so WHERE so.id = ?::uuid
            SQL, [$id, self::freshNumber('FUL'), (string) $this->actorId, $orderId]);

        return $id;
    }

    /**
     * An invoice row written as the owner. `$status` is STORED (`overdue` is legal in the CHECK, though nothing in
     * `src/` stores it, G-18). A paid one names the actor as its recorder (`invoice_paid_by_matches_status`).
     */
    protected function seedInvoiceRow(string $fulfilmentId, string $number, string $status, string $total,
        string $issuedOn = '2026-08-01', string $dueOn = '2026-08-31', bool $paymentReversed = false): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO invoice (id, tenant_id, fulfilment_id, sales_order_id, customer_id, invoice_number, currency_code,
                                 total_amount, issued_at, due_at, status, paid_at, issued_by_user_id, paid_by_user_id,
                                 payment_reversed_at)
            SELECT ?::uuid, f.tenant_id, f.id, so.id, so.customer_id, ?, so.currency_code, ?::numeric,
                   (?::date)::timestamptz, (?::date)::timestamptz, ?,
                   CASE WHEN ? = 'paid' THEN (?::date)::timestamptz + interval '1 day' END, ?::uuid,
                   CASE WHEN ? = 'paid' THEN ?::uuid END,
                   CASE WHEN ?::boolean THEN (?::date)::timestamptz + interval '2 days' END
              FROM fulfilment f JOIN sales_order so ON so.tenant_id = f.tenant_id AND so.id = f.sales_order_id
             WHERE f.id = ?::uuid
            SQL, [$id, $number, $total, $issuedOn, $dueOn, $status, $status, $issuedOn, (string) $this->actorId,
                $status, (string) $this->actorId, $paymentReversed ? 'true' : 'false', $issuedOn, $fulfilmentId]);

        return $id;
    }

    /**
     * A credit note on `$invoiceId` for `$total`, as the owner, with optional lines `[fulfilment_line_id, quantity,
     * amount, ?sales_return_line_id]`. No `sales_return_id` means an adjustment (`damaged_goods_kept`), as
     * `credit_note_return_iff_goods_returned` requires.
     *
     * @param list<array{0: string, 1: string, 2: string, 3?: ?string}> $lines
     */
    protected function seedCreditNote(string $invoiceId, string $total, array $lines = [], ?string $salesReturnId = null): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO credit_note (id, tenant_id, invoice_id, sales_return_id, credit_note_number, reason, currency_code,
                                     total_amount, issued_by_user_id, idempotency_key, request_digest)
            SELECT ?::uuid, inv.tenant_id, inv.id, ?::uuid, ?, ?, inv.currency_code, ?::numeric, ?::uuid,
                   encode(sha256(gen_random_uuid()::text::bytea), 'hex'), encode(sha256(gen_random_uuid()::text::bytea), 'hex')
              FROM invoice inv WHERE inv.id = ?::uuid
            SQL, [$id, $salesReturnId, \sprintf('CN-%06d', 900000 + ++$this->creditNoteSequence),
                null === $salesReturnId ? 'damaged_goods_kept' : 'goods_returned', $total, (string) $this->actorId, $invoiceId]);

        foreach ($lines as $line) {
            $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                INSERT INTO credit_note_line (id, tenant_id, credit_note_id, fulfilment_line_id, sales_return_line_id, quantity, amount)
                VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric, ?::numeric)
                SQL, [Uuid::v4()->toRfc4122(), self::TENANT_A, $id, $line[0], $line[3] ?? null, $line[1], $line[2]]);
        }

        return $id;
    }
}

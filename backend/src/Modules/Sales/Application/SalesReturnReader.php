<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Infrastructure\Entity\Invoice;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **The read half of `/sales-returns` — `GetSalesReturn` (sales step 7 R2; built with the create so the 201's
 * `Location` answers, V2-R-4).** `FulfilmentReader`'s shape: one statement for the header, one for the lines.
 *
 * ---------------------------------------------------------------------------
 * The ONE creditable predicate (design §4.2; `SEC` L5-1 §1.4, the R2 form)
 * ---------------------------------------------------------------------------
 * A return **owes a credit** when the fulfilment has a LIVE invoice, and
 *   - its stamp is that invoice, or a VOID invoice of the same fulfilment (the reissue after an uncredited void), or
 *     NULL with `deducts = false` (Q-L (a′): a pre-invoice return nobody may deduct);
 *   - and some line has a positive amount: `round(r × p, 4) > 0` with `r = min(returned − credited, billed − credited)`,
 *     `p = unit_price > 0`, `billed = shipped − returned_before_issue` (the deducting returns of that shipped line).
 * It is **creditable** when it owes a credit AND the live invoice is not `paid` (`RED-3` N-3; Q-E: a paid invoice
 * cannot be credited yet); it is **blocked by payment** when it owes a credit AND the live invoice IS `paid`.
 * `credit_target` (the invoice a credit must be issued against) is the live invoice exactly when creditable, computed
 * from THIS predicate and never inline (`SEC` L5-1 FINAL item 6) — so dropping "not paid" from it moves both.
 *
 * **The credited quantity is ONE named term, `CREDITED`** (`SEC` L5-1 §1.4, `RED-2` L-1): `0::numeric(19,6)` until
 * the credit tables exist; C0 replaces only that term with `COALESCE(SUM(credit_note_line.quantity), 0::numeric(19,6))`.
 * A bare `0` would serialize `quantity_credited` at scale 0 and bake that into `wire-scale.json`.
 *
 * **L5-1, decided (b) by `SEC` 2026-10-01:** `credit_target` stays null unless creditable, and PC-9's third deliberate
 * exception states the four bounded facts a `sales_return.view` holder without `invoice.view` can infer from it and
 * from `creditable` membership. `credit_blocked_by_payment` — the field that NAMES payment as the cause — is served
 * only to an `invoice.view` holder and is ABSENT otherwise (`RED-4` M4-1).
 */
final readonly class SalesReturnReader
{
    /** The credited quantity, one named term (see the class docblock). */
    private const CREDITED = '0::numeric(19,6)';

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
    ) {
    }

    public function get(string $id): ?SalesReturnView
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->tenants->run(
            $this->tenant->tenantId(),
            fn (EntityManagerInterface $em): ?SalesReturnView => $this->readInside($em, Uuid::fromString($id)->toRfc4122()),
        );
    }

    /** Inside a transaction the caller already holds. */
    public function readInside(EntityManagerInterface $em, string $id): ?SalesReturnView
    {
        $connection = $em->getConnection();

        $row = $connection->fetchAssociative(self::headerSql().' WHERE r.id = ?::uuid', [$id]);
        if (false === $row) {
            return null;
        }

        return $this->view($row, $connection->fetchAllAssociative(self::linesSql().' WHERE srl.sales_return_id = ?::uuid'.self::LINES_ORDER, [$id]));
    }

    /**
     * The header, with the predicate's two outcomes. The statuses are the entity's constants, interpolated (no
     * placeholder), so the statement binds only the id.
     */
    private static function headerSql(): string
    {
        return \sprintf(<<<'SQL'
            SELECT r.id, r.sales_return_number, r.reason, r.returned_at, r.deducts, r.created_at,
                   f.id AS fulfilment_id, f.fulfilment_number,
                   stamp.id AS invoice_id, stamp.invoice_number,
                   u.id AS recorded_by_id, u.display_name AS recorded_by_display_name,
                   live.id AS live_invoice_id, live.invoice_number AS live_invoice_number,
                   (live.id IS NOT NULL AND %1$s AND live.status <> '%2$s') AS creditable,
                   (live.id IS NOT NULL AND %1$s AND live.status = '%2$s') AS credit_blocked_by_payment
              FROM sales_return r
              JOIN fulfilment f ON f.tenant_id = r.tenant_id AND f.id = r.fulfilment_id
              JOIN app_user u ON u.tenant_id = r.tenant_id AND u.id = r.recorded_by_user_id
              LEFT JOIN invoice stamp ON stamp.tenant_id = r.tenant_id AND stamp.id = r.invoice_id
              LEFT JOIN invoice live ON live.tenant_id = r.tenant_id AND live.fulfilment_id = r.fulfilment_id AND live.status <> '%3$s'
            SQL, self::owesACredit(), Invoice::STATUS_PAID, Invoice::STATUS_VOID);
    }

    /**
     * **The shared predicate, minus the payment split**: the return's stamp makes it a credit's to settle, and some
     * line would credit a positive amount. Needs `r` (the return) and `live` (the fulfilment's live invoice) in scope;
     * binds no placeholder. `ListSalesReturns` (R3) and `IssueCreditNote` (C1) use THIS text.
     */
    public static function owesACredit(): string
    {
        return \sprintf(<<<'SQL'
            (
                (r.invoice_id = live.id
                 OR (r.invoice_id IS NULL AND NOT r.deducts)
                 OR EXISTS (SELECT 1 FROM invoice v
                             WHERE v.tenant_id = r.tenant_id AND v.id = r.invoice_id
                               AND v.fulfilment_id = r.fulfilment_id AND v.status = '%3$s'))
                AND EXISTS (
                    SELECT 1
                      FROM sales_return_line rl
                      JOIN fulfilment_line rfl ON rfl.tenant_id = rl.tenant_id AND rfl.id = rl.fulfilment_line_id
                      JOIN sales_order_line rsol ON rsol.tenant_id = rfl.tenant_id AND rsol.id = rfl.sales_order_line_id
                     WHERE rl.tenant_id = r.tenant_id AND rl.sales_return_id = r.id
                       AND rsol.unit_price > 0
                       AND round(LEAST(rl.quantity - %1$s,
                                       rfl.quantity - %2$s - %1$s) * rsol.unit_price, 4) > 0
                )
            )
            SQL, self::CREDITED, self::returnedBeforeIssue('rfl'), Invoice::STATUS_VOID);
    }

    /**
     * `returned_before_issue(l)`: the quantity of shipped line `$alias` that came back on DEDUCTING returns (Q-L (a′)),
     * which `IssueInvoice` takes off the bill (`sales_return ⋈ sales_return_line`; `deducts` is the header's column).
     */
    public static function returnedBeforeIssue(string $alias): string
    {
        return \sprintf(<<<'SQL'
            COALESCE((SELECT SUM(dl.quantity)
                        FROM sales_return_line dl
                        JOIN sales_return dr ON dr.tenant_id = dl.tenant_id AND dr.id = dl.sales_return_id
                       WHERE dl.tenant_id = %1$s.tenant_id AND dl.fulfilment_line_id = %1$s.id AND dr.deducts), 0::numeric(19,6))
            SQL, $alias);
    }

    private static function linesSql(): string
    {
        return \sprintf(<<<'SQL'
        SELECT srl.id, srl.quantity, %s AS quantity_credited,
               fl.id AS fulfilment_line_id, sol.id AS sales_order_line_id, sol.line_number,
               i.id AS inventory_item_id, i.sku AS inventory_item_sku, i.name AS inventory_item_name,
               fsl.id AS shipped_from_id, fsl.code AS shipped_from_code,
               sl.id AS storage_location_id, sl.code AS storage_location_code
          FROM sales_return_line srl
          JOIN fulfilment_line fl ON fl.tenant_id = srl.tenant_id AND fl.id = srl.fulfilment_line_id
          JOIN sales_order_line sol ON sol.tenant_id = fl.tenant_id AND sol.id = fl.sales_order_line_id
          JOIN inventory_item i ON i.tenant_id = sol.tenant_id AND i.id = sol.inventory_item_id
          JOIN storage_location fsl ON fsl.tenant_id = fl.tenant_id AND fsl.id = fl.storage_location_id
          JOIN storage_location sl ON sl.tenant_id = srl.tenant_id AND sl.id = srl.storage_location_id
        SQL, self::CREDITED);
    }

    private const LINES_ORDER = ' ORDER BY sol.line_number, fl.storage_location_id, srl.id';

    /**
     * @param array<string, mixed>       $row
     * @param list<array<string, mixed>> $lines
     */
    private function view(array $row, array $lines): SalesReturnView
    {
        $creditable = (bool) $row['creditable'];

        return new SalesReturnView(
            id: (string) $row['id'],
            salesReturnNumber: (string) $row['sales_return_number'],
            reason: (string) $row['reason'],
            returnedAt: (string) SalesOrderRow::timestamp($row['returned_at']),
            fulfilment: new FulfilmentRef((string) $row['fulfilment_id'], (string) $row['fulfilment_number']),
            invoice: null === $row['invoice_id'] ? null : new InvoiceNumberRef((string) $row['invoice_id'], (string) $row['invoice_number']),
            creditTarget: $creditable ? new InvoiceNumberRef((string) $row['live_invoice_id'], (string) $row['live_invoice_number']) : null,
            deducts: (bool) $row['deducts'],
            recordedBy: new SalesActorRef((string) $row['recorded_by_id'], (string) $row['recorded_by_display_name']),
            createdAt: (string) SalesOrderRow::timestamp($row['created_at']),
            lines: array_map(static fn (array $line): SalesReturnLineView => new SalesReturnLineView(
                id: (string) $line['id'],
                fulfilmentLine: new ShippedLineRef(
                    (string) $line['fulfilment_line_id'],
                    new SalesLineRef((string) $line['sales_order_line_id'], (int) $line['line_number']),
                    new SalesItemRef((string) $line['inventory_item_id'], (string) $line['inventory_item_sku'], (string) $line['inventory_item_name']),
                    new SalesLocationRef((string) $line['shipped_from_id'], (string) $line['shipped_from_code']),
                ),
                inventoryItem: new SalesItemRef((string) $line['inventory_item_id'], (string) $line['inventory_item_sku'], (string) $line['inventory_item_name']),
                quantity: (string) $line['quantity'],
                storageLocation: new SalesLocationRef((string) $line['storage_location_id'], (string) $line['storage_location_code']),
                quantityCredited: (string) $line['quantity_credited'],
            ), $lines),
            // M4-1: the field that names payment as the cause is an `invoice.view` holder's alone.
            creditBlockedByPayment: $this->identities->authenticated()->holds('invoice.view') ? (bool) $row['credit_blocked_by_payment'] : null,
        );
    }
}

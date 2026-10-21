<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`DeleteSalesOrder` — the behaviour, over HTTP** (Output 9, sales slice 5, 2026-09-21).
 *
 * The contract registries (`EditWindowGuardTest`, `ArchivedRowTest`, `PermissionEnforcementTest`,
 * `TenantClaimProbeTest`, `ResponseStatusTest`, `PurchaseOrderRowLockTest`) each hold one property of this operation
 * over a derived domain. This class holds what is true of this operation alone.
 *
 * *(Order restores C2, 2026-10-21: `RestoreSalesOrder` now ships, with `ListSalesOrders?archived` — G-8's sales half is
 * closed; `SalesOrderRestoreTest`. The paragraph below is the record as shipped.)*
 * **It ships WITHOUT `RestoreSalesOrder`** — the owner's Q1 ruling of 2026-09-21. E9-3 blocks that restore while
 * `ListSalesOrders` declares no `archived` parameter, so a discarded draft is recoverable only by direct SQL
 * (`RESIDUAL_GAPS.md` **G-8**) and nothing records who discarded it (**G-9**). Both are accepted, both are carried on
 * a mechanism: {@see SalesOrderCreateTest::theArchivedIdProblemIsWhyNoOrderRestoreShips} for G-8 and
 * {@see self::aDeletionWritesNoAuditRowBecauseB4aOwnsThatDeferral} for G-9.
 */
final class SalesOrderDeleteTest extends PurchaseOrderTestCase
{
    /**
     * **API-21 branch 3: 204 and no body.** A soft delete: the row stays readable by direct hit (§5.2a) carrying its
     * archive stamp, and the cascade trigger takes its lines with it.
     */
    #[Test]
    public function deletingADraftArchivesItAndItsLines(): void
    {
        $o = $this->draftWithLines(1);

        $this->send('DELETE', '/sales-orders/'.$o['order']);

        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('', (string) $this->client()->getResponse()->getContent());

        $this->send('GET', '/sales-orders/'.$o['order']);
        self::assertSame(200, $this->httpStatus());
        self::assertNotNull($this->json()['archived_at'], 'The order was not archived.');
        self::assertSame(SalesOrder::STATUS_DRAFT, $this->json()['status'], 'Archiving changed the status.');

        $this->send('GET', '/sales-orders/'.$o['order'].'/lines?archived=true');
        self::assertCount(1, $this->json()['items'], 'The cascade trigger did not take the line with the order.');

        $this->send('GET', '/sales-orders?status=draft');
        self::assertNotContains($o['order'], array_column($this->json()['items'], 'id'), 'A discarded order is still listed.');
    }

    /**
     * **Commit 1's cascade fix, reached through the API end to end.** A line the user discarded before discarding
     * the order keeps the instant the user discarded it; the line the order took with it carries the order's.
     */
    #[Test]
    public function aLineDiscardedBeforeItsOrderKeepsItsOwnArchiveStamp(): void
    {
        $o = $this->draftWithLines(2);

        $this->send('DELETE', '/sales-orders/'.$o['order'].'/lines/'.$o['lines'][1]);
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->send('DELETE', '/sales-orders/'.$o['order']);
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $stamps = [];
        foreach ($this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT l.id::text AS id, l.deleted_at = o.deleted_at AS with_order FROM sales_order_line l JOIN sales_order o ON o.id = l.sales_order_id WHERE o.id = ?::uuid',
            [$o['order']]) as $row) {
            $stamps[$row['id']] = $row['with_order'];
        }

        self::assertTrue($stamps[$o['lines'][0]], 'Control: the line archived with the order does not carry the order\'s stamp.');
        self::assertFalse($stamps[$o['lines'][1]],
            'The line discarded BEFORE the order was re-stamped with the order\'s instant, so nothing can tell it '
            .'from the lines the order took with it (Version20260921000200).');
    }

    /**
     * **L-8: a document is deletable only while it is a draft.** A committed order leaves through
     * `CancelSalesOrder`, which is a different act with a different permission. The acting principal holds every
     * code, so a 409 here is the status refusing and not the permission set.
     *
     * The message must be TRUE for the status it names — `RED` found the shared refusal saying *"has been confirmed"*
     * of every non-draft, including a cancelled one.
     */
    #[Test]
    public function onlyADraftCanBeDeleted(): void
    {
        foreach ([SalesOrder::STATUS_CONFIRMED, SalesOrder::STATUS_CANCELLED] as $n => $status) {
            $order = $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'), status: $status, orderedAt: '2026-09-01T00:00:00+00:00');

            $this->send('DELETE', '/sales-orders/'.$order);

            self::assertSame(409, $this->httpStatus(), $status.': '.(string) $this->client()->getResponse()->getContent());
            self::assertSame('sales_order.is_editable_only_while_draft', $this->json()['rule']);
            self::assertStringContainsString($status, (string) $this->json()['message'],
                'The refusal does not name the status the order is actually in.');
            self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A,
                'SELECT count(*) FROM sales_order WHERE id = ?::uuid AND deleted_at IS NOT NULL', [$order]),
                $status.': the refused delete archived the order anyway.');
        }
    }

    /** A confirmed order's refusal says what to do instead: cancel it (L-8, TB-3a). */
    #[Test]
    public function aConfirmedOrdersRefusalPointsAtCancellation(): void
    {
        $order = $this->seedSalesOrder(self::TENANT_A, 'SO-141000', status: SalesOrder::STATUS_CONFIRMED, orderedAt: '2026-09-01T00:00:00+00:00');

        $this->send('DELETE', '/sales-orders/'.$order);

        self::assertSame(409, $this->httpStatus());
        self::assertStringContainsString('Cancel it', (string) $this->json()['message']);
    }

    /** `Idem = no`: a repeat is refused by the archived guard, the answer `DeletePurchaseOrder` gives. */
    #[Test]
    public function aSecondDeleteIsRefusedAsArchived(): void
    {
        $o = $this->draftWithLines(1);

        $this->send('DELETE', '/sales-orders/'.$o['order']);
        self::assertSame(204, $this->httpStatus());

        $this->send('DELETE', '/sales-orders/'.$o['order']);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('sales_order.is_archived', $this->json()['rule']);
        self::assertSame('This sales order is already archived.', $this->json()['detail']);
    }

    /** §5.3 clause 2: an id this tenant cannot see and an id that never existed answer identically. */
    #[Test]
    public function anIdNoRowAnswersIsNotFound(): void
    {
        $this->send('DELETE', '/sales-orders/'.Uuid::v4()->toRfc4122());

        self::assertSame(404, $this->httpStatus());
    }

    /**
     * **`RESIDUAL_GAPS.md` G-9 CLOSED by B-4a (roles slice 2, C1, 2026-09-29): each delete writes exactly one row
     * naming the deleter, and both modules got theirs together** (G-9's fallback — minting one alone would have been a
     * new asymmetry). ⚠ This was `aDeletionWritesNoAuditRowBecauseB4aOwnsThatDeferral`, an absence pin whose message
     * asked for exactly this change *"in the same commit"*. G-9's case — rep B deletes rep A's draft, then raises and
     * confirms the same deal — is now answerable from the trail. `SEC` filed both business, subject the order, `{}`
     * (`docs/session13/roles-s2-sec.md` §3).
     *
     * **Positive and negative in one test, in both modules:** each row is read back cell by cell, and a repeat delete
     * — refused as archived — leaves each count at one.
     */
    #[Test]
    public function aDeletionWritesExactlyOneDeletedRowInBothModules(): void
    {
        $sales = $this->draftWithLines(1, 'SO-126000');
        $purchase = $this->seedDraft(self::TENANT_A, 'PO-126000');

        $this->send('DELETE', '/sales-orders/'.$sales['order']);
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->send('DELETE', '/purchase-orders/'.$purchase['order']);
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        self::assertSame([$this->businessRow(AuditEvent::SALES_ORDER_DELETED, 'sales_order', $sales['order'])],
            $this->auditRowsOf(AuditEvent::SALES_ORDER_DELETED),
            'DeleteSalesOrder did not write exactly one sales_order.deleted row naming the deleter (G-9 / B-4a).');
        self::assertSame([$this->businessRow(AuditEvent::PURCHASE_ORDER_DELETED, 'purchase_order', $purchase['order'])],
            $this->auditRowsOf(AuditEvent::PURCHASE_ORDER_DELETED),
            'DeletePurchaseOrder did not write exactly one purchase_order.deleted row naming the deleter (G-9 / B-4a).');

        $this->send('DELETE', '/sales-orders/'.$sales['order']);
        self::assertSame(409, $this->httpStatus(), 'Control: a repeat sales delete was not refused.');
        $this->send('DELETE', '/purchase-orders/'.$purchase['order']);
        self::assertSame(409, $this->httpStatus(), 'Control: a repeat purchase delete was not refused.');
        self::assertSame(2, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM audit_log'),
            'A refused repeat delete wrote a row.');
    }

    /**
     * A live draft with `$count` lines on distinct items.
     *
     * @return array{order: string, lines: list<string>}
     */
    private function draftWithLines(int $count, string $number = 'SO-149000'): array
    {
        $order = $this->seedSalesOrder(self::TENANT_A, $number);
        $lines = [];
        foreach (range(1, $count) as $n) {
            $lines[] = $this->seedSalesOrderLine(self::TENANT_A, $order, $this->seedItem(self::TENANT_A, $number.'-SKU-'.$n), $n);
        }

        return ['order' => $order, 'lines' => $lines];
    }
}

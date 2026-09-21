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
            $order = $this->seedSalesOrder(self::TENANT_A, 'SO-ND'.$n, status: $status, orderedAt: '2026-09-01T00:00:00+00:00');

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
        $order = $this->seedSalesOrder(self::TENANT_A, 'SO-CF', status: SalesOrder::STATUS_CONFIRMED, orderedAt: '2026-09-01T00:00:00+00:00');

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
    }

    /** §5.3 clause 2: an id this tenant cannot see and an id that never existed answer identically. */
    #[Test]
    public function anIdNoRowAnswersIsNotFound(): void
    {
        $this->send('DELETE', '/sales-orders/'.Uuid::v4()->toRfc4122());

        self::assertSame(404, $this->httpStatus());
    }

    /**
     * **`RESIDUAL_GAPS.md` G-9 — nothing records who discarded a draft order — carried by a mechanism.**
     *
     * The owner accepted it on 2026-09-21 (Q1 of sales step 4) as a **named B-4a carry**: `sales_order.deleted` and
     * `purchase_order.deleted` (and the matching `*.restored` when those restores ship), minted together through
     * `SEC`. The case it matters for is written into G-9: rep B deletes rep A's draft, then raises and confirms the same
     * deal, and nothing records that B removed A's draft. Unreachable today — every tenant holds one user.
     *
     * **Two sibling drafts, and the order is load-bearing.** One fixture cannot carry both drives: once deleted, an
     * order is refused by `CancelSalesOrder` (`is_archived`), and a cancelled order cannot be deleted (L-8, and
     * `sales_order_archived_only_while_draft`). The count is tenant-wide, so the delete runs FIRST — otherwise the
     * zero is never observed. `SalesOrder::cancel()` admits `draft`, so B is a valid subject for the control.
     */
    #[Test]
    public function aDeletionWritesNoAuditRowBecauseB4aOwnsThatDeferral(): void
    {
        self::assertContains(AuditEvent::SALES_ORDER_CANCELLED, AuditEvent::ALL,
            'Control: the sales cancellation event has left the catalogue, so the absences below prove nothing.');
        foreach (['sales_order.deleted', 'purchase_order.deleted'] as $event) {
            self::assertNotContains($event, AuditEvent::ALL, \sprintf(
                "%s exists now. RESIDUAL_GAPS.md G-9 routed it as a named B-4a carry, to be minted TOGETHER with its\n"
                ."twin through SEC (minting one alone creates a new unrecorded asymmetry). Write DeleteSalesOrder's and\n"
                .'DeletePurchaseOrder\'s rows, close G-9, and change this test in the same commit.', $event));
        }

        $a = $this->draftWithLines(1, 'SO-A');
        $b = $this->draftWithLines(1, 'SO-B');

        $this->send('DELETE', '/sales-orders/'.$a['order']);
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM audit_log'),
            'DeleteSalesOrder wrote a trail row for an event the catalogue does not declare. If the event was minted, '
            .'see RESIDUAL_GAPS.md G-9 and B-4a: both deletes get their event together.');

        $this->send('POST', '/sales-orders/'.$b['order'].'/cancellation', ['reason' => 'Control']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(
            [['event_type' => AuditEvent::SALES_ORDER_CANCELLED, 'subject_id' => $b['order']]],
            $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT event_type, subject_id::text AS subject_id FROM audit_log'),
            'Control: a cancellation of the sibling draft did not write exactly one sales_order.cancelled row, so the '
            .'zero above could be a trail that writes nothing at all.');
    }

    /**
     * A live draft with `$count` lines on distinct items.
     *
     * @return array{order: string, lines: list<string>}
     */
    private function draftWithLines(int $count, string $number = 'SO-DEL'): array
    {
        $order = $this->seedSalesOrder(self::TENANT_A, $number);
        $lines = [];
        foreach (range(1, $count) as $n) {
            $lines[] = $this->seedSalesOrderLine(self::TENANT_A, $order, $this->seedItem(self::TENANT_A, $number.'-SKU-'.$n), $n);
        }

        return ['order' => $order, 'lines' => $lines];
    }
}

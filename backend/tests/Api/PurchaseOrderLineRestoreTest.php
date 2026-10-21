<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`RestorePurchaseOrderLine`** (order-restores C0, 2026-10-21; `docs/session13/order-restores-design.md` §3 and
 * "Revision 1 FINAL — C0"). The sales twin is `SalesOrderLineWriteTest`'s restore cases.
 *
 * What this class holds, each case reached on its own:
 *   - the restore itself — 200, the line live, **the order total raised** (the pointer path);
 *   - the `guard:` mechanism — a repeat is refused with no write;
 *   - the coherence answer — a line archived WITH its order cannot be restored alone (`purchase_order.is_archived`);
 *   - the edit window — a placed order's archived line is refused;
 *   - BOTH line uniques, each reached alone, plus the case where both collide (the line number fires first, T-2);
 *     the retry-key case through a line created on ANOTHER order, because the key is tenant-wide (RED M-3);
 *   - a raised total that leaves `numeric(19,4)` is a 422 naming `/unit_cost`, never a 500;
 *   - `viewer` 403, a foreign tenant's line 404.
 *
 * The race (a concurrent restore committing first) is `PurchaseOrderRowLockTest`'s, where the holder harness lives.
 */
final class PurchaseOrderLineRestoreTest extends PurchaseOrderTestCase
{
    /** @return array{order: string, supplier: string, warehouse: string, item: string} */
    private function draft(string $number = 'PO-000001'): array
    {
        $draft = $this->seedDraft(self::TENANT_A, $number);
        $this->seedSupplierProduct(self::TENANT_A, $draft['supplier'], $draft['item']);

        return $draft;
    }

    /**
     * A second item the draft's supplier lists, so `CreatePurchaseOrderLine` accepts it.
     *
     * @param array{supplier: string} $draft
     */
    private function listedItem(array $draft, string $sku): string
    {
        $item = $this->seedItem(self::TENANT_A, $sku);
        $this->seedSupplierProduct(self::TENANT_A, $draft['supplier'], $item);

        return $item;
    }

    private function createLine(string $orderId, string $item, string $key): string
    {
        $this->send('POST', '/purchase-orders/'.$orderId.'/lines', [
            'inventory_item_id' => $item, 'quantity' => '2', 'unit_cost' => '3.0000',
        ], [IdempotencyKeyGuard::HEADER => $key]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        return (string) $this->json()['id'];
    }

    private function deleteLine(string $orderId, string $lineId): void
    {
        $this->send('DELETE', '/purchase-orders/'.$orderId.'/lines/'.$lineId);
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    private function restore(string $orderId, string $lineId): void
    {
        $this->send('POST', '/purchase-orders/'.$orderId.'/lines/'.$lineId.'/restoration');
    }

    private function isArchived(string $lineId): bool
    {
        return 1 === $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM purchase_order_line WHERE id = ?::uuid AND deleted_at IS NOT NULL', [$lineId]);
    }

    private function storedTotal(string $orderId): string
    {
        return (string) $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT total_amount::text AS t FROM purchase_order WHERE id = ?::uuid', [$orderId])[0]['t'];
    }

    /** 200 with the line, live again, and the order's total raised by its product (the pointer path, not null). */
    #[Test]
    public function restoringAnIndividuallyDeletedLineBringsItBackAndRaisesTheTotal(): void
    {
        $draft = $this->draft();
        $this->seedLine(self::TENANT_A, $draft['order'], $draft['item'], 1);
        $line = $this->seedLine(self::TENANT_A, $draft['order'], $this->seedItem(self::TENANT_A, 'SKU-R2'), 2);
        $this->deleteLine($draft['order'], $line);
        self::assertSame('25.0000', $this->storedTotal($draft['order']), 'Fixture: the delete did not lower the total to one line.');

        $this->restore($draft['order'], $line);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertNull($this->client()->getResponse()->headers->get('Location'), 'A restore mints nothing, so it names no Location.');
        $body = $this->json();
        self::assertSame($line, $body['id']);
        self::assertSame(2, $body['line_number']);
        self::assertNull($body['archived_at']);
        self::assertSame('10.000000', $body['quantity']);
        self::assertSame('2.5000', $body['unit_cost']);
        self::assertFalse($this->isArchived($line));
        self::assertSame('50.0000', $this->storedTotal($draft['order']), 'The restore did not put the line back into the order total.');

        $this->send('GET', '/purchase-orders/'.$draft['order'].'/lines');
        self::assertCount(2, $this->json()['items']);
    }

    /** The declared `guard:` mechanism: a repeat finds a live row and is refused, and nothing is written. */
    #[Test]
    public function restoringALiveLineIsRefusedAndWritesNothing(): void
    {
        $draft = $this->draft();
        $line = $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);
        $before = $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT updated_at::text AS u FROM purchase_order_line WHERE id = ?::uuid', [$line]);

        $this->restore($draft['order'], $line);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('purchase_order_line.is_not_archived', $this->json()['rule']);
        self::assertSame('This line is not archived, so there is nothing to restore.', $this->json()['detail']);
        self::assertSame($before, $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT updated_at::text AS u FROM purchase_order_line WHERE id = ?::uuid', [$line]));
    }

    /**
     * **The coherence answer** the blocked state recorded: a line archived WITH its order cannot be restored alone,
     * because the order is archived and `refuseIfArchived()` runs before the line is looked at.
     */
    #[Test]
    public function aLineArchivedWithItsOrderCannotBeRestoredAlone(): void
    {
        $draft = $this->draft();
        $line = $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);
        $this->send('DELETE', '/purchase-orders/'.$draft['order']);
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertTrue($this->isArchived($line), 'Fixture: the order delete did not cascade to its line.');

        $this->restore($draft['order'], $line);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('purchase_order.is_archived', $this->json()['rule']);
        self::assertTrue($this->isArchived($line));
    }

    /** L-5: a placed order is read-only except for its transitions, and that includes bringing a line back. */
    #[Test]
    public function aPlacedOrdersArchivedLineCannotBeRestored(): void
    {
        $draft = $this->draft();
        $this->seedLine(self::TENANT_A, $draft['order'], $draft['item'], 1);
        $line = $this->seedLine(self::TENANT_A, $draft['order'], $this->seedItem(self::TENANT_A, 'SKU-P2'), 2);
        $this->deleteLine($draft['order'], $line);
        $this->insertAsOwnerForTenant(self::TENANT_A,
            "UPDATE purchase_order SET status = 'ordered', ordered_at = now() WHERE id = ?::uuid", [$draft['order']]);

        $this->restore($draft['order'], $line);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('purchase_order.is_editable_only_while_draft', $this->json()['rule']);
        self::assertTrue($this->isArchived($line));
    }

    /**
     * **Both uniques collide, and the LINE NUMBER fires first** (T-2): line numbers are minted `MAX + 1` over live
     * lines, so a replacement takes the archived line's number as well as its key, and `purchase_order_line_unique`
     * is the older index. Without the per-path map the caller would be told "another line was added at the same
     * moment. Try again" — advice into a wall.
     */
    #[Test]
    public function aReplacementHoldingTheNumberAndTheKeyIsRefusedOnTheNumberFirst(): void
    {
        $draft = $this->draft();
        $key = Uuid::v4()->toRfc4122();
        $first = $this->createLine($draft['order'], $draft['item'], $key);
        $this->deleteLine($draft['order'], $first);
        $second = $this->createLine($draft['order'], $this->listedItem($draft, 'SKU-NK'), $key);
        self::assertNotSame($first, $second, 'Step 3 replayed the archived line, so there is no second row holding K.');

        $this->restore($draft['order'], $first);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('purchase_order_line.line_number_is_taken_by_a_live_line', $this->json()['rule'] ?? null);
        self::assertTrue($this->isArchived($first), 'The refusal did not roll the restore back.');
    }

    /** The line-number branch reached ALONE: the archived line carried no key at all (seeded), so only its number collides. */
    #[Test]
    public function aReplacementHoldingOnlyTheNumberIsRefusedOnTheNumber(): void
    {
        $draft = $this->draft();
        $first = $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);
        $this->deleteLine($draft['order'], $first);
        $this->createLine($draft['order'], $this->listedItem($draft, 'SKU-N'), Uuid::v4()->toRfc4122());

        $this->restore($draft['order'], $first);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('purchase_order_line.line_number_is_taken_by_a_live_line', $this->json()['rule'] ?? null);
        self::assertSame('A newer line on this order now has this line\'s number, so this line cannot be restored. '
            .'Delete the newer line first, or add this line again.', $this->json()['detail']);
        self::assertTrue($this->isArchived($first));
    }

    /**
     * **The retry-key branch reached ALONE, through a line on ANOTHER order** (RED M-3): the key is tenant-wide, so the
     * live line holding it need not be on this order — which is why the sentence must not say "on this order". This
     * order's line number stays free (it has no other line), so only `purchase_order_line_tenant_idem_live` collides.
     */
    #[Test]
    public function aLineOnAnotherOrderHoldingTheKeyIsRefusedOnTheKey(): void
    {
        $draft = $this->draft();
        $other = $this->draft('PO-000002');
        $key = Uuid::v4()->toRfc4122();
        $first = $this->createLine($draft['order'], $draft['item'], $key);
        $this->deleteLine($draft['order'], $first);
        $this->createLine($other['order'], $other['item'], $key);

        $this->restore($draft['order'], $first);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('purchase_order_line.retry_key_is_held_by_a_live_line', $this->json()['rule'] ?? null,
            'The retry-key branch of restoreLine()\'s collision map was not reached, so it is untested code.');
        $detail = (string) $this->json()['detail'];
        self::assertSame('Another line, on this order or another, was created with the same retry key as this one, so '
            .'this line cannot be restored. Add this line again instead.', $detail);
        self::assertStringNotContainsString('newer line on this order', $detail);
        self::assertTrue($this->isArchived($first));
    }

    /**
     * **A restore raises the total, so its overflow is a refusal naming a field, never a 500.** Each line's product
     * fits `numeric(19,4)`; their sum does not. A null pointer (the `archiveLine()` choice) would send this down
     * `recomputeTotal()`'s `LogicException` branch.
     */
    #[Test]
    public function aRestoreThatWouldOverflowTheTotalIsRefusedNamingTheCost(): void
    {
        $draft = $this->draft();
        $this->seedLine(self::TENANT_A, $draft['order'], $draft['item'], 1, '1000000.000000', '600000000.0000');
        $line = $this->seedLine(self::TENANT_A, $draft['order'], $this->seedItem(self::TENANT_A, 'SKU-BIG'), 2, '1000000.000000', '600000000.0000');
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'UPDATE purchase_order_line SET deleted_at = now() WHERE id = ?::uuid', [$line]);

        $this->restore($draft['order'], $line);

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([['pointer' => '/unit_cost', 'code' => 'total_out_of_range']],
            array_map(static fn (array $e): array => ['pointer' => $e['pointer'], 'code' => $e['code']], $this->json()['errors']));
        self::assertTrue($this->isArchived($line));
    }

    /** `purchase_order.edit` gates it; `viewer` holds only the view. */
    #[Test]
    public function aViewerCannotRestoreALine(): void
    {
        $draft = $this->draft();
        $line = $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'UPDATE purchase_order_line SET deleted_at = now() WHERE id = ?::uuid', [$line]);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::VIEWER));

        $this->restore($draft['order'], $line);

        self::assertSame(403, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertTrue($this->isArchived($line));
    }

    /** ST-5: another tenant's line is not found, and a line of another order is not found at this order's address. */
    #[Test]
    public function aForeignOrMisaddressedLineIsNotFound(): void
    {
        // Everything is seeded BEFORE the first request: a refused request closes the shared entity manager the
        // fixtures write through.
        $foreign = $this->seedDraft(self::TENANT_B, 'PO-000001');
        $foreignLine = $this->seedLine(self::TENANT_B, $foreign['order'], $foreign['item']);
        $this->insertAsOwnerForTenant(self::TENANT_B,
            'UPDATE purchase_order_line SET deleted_at = now() WHERE id = ?::uuid', [$foreignLine]);
        $draft = $this->draft();
        $other = $this->draft('PO-000002');
        $lineOfOther = $this->seedLine(self::TENANT_A, $other['order'], $other['item']);
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'UPDATE purchase_order_line SET deleted_at = now() WHERE id = ?::uuid', [$lineOfOther]);

        $this->restore($foreign['order'], $foreignLine);
        self::assertSame(404, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->restore($draft['order'], $lineOfOther);
        self::assertSame(404, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertTrue($this->isArchived($lineOfOther));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **Output 9, sales slice 1 — `CancelSalesOrder` over HTTP.**
 *
 * §8.3's sales cancel row, the last of **API-O-17**'s four missing transition methods, plus the two
 * decisions this commit had to take rather than inherit: the `sales_order.cancelled` audit row (**OI-2**
 * R1a, and `ADR-008` §2's named compensating control) and the absence of any duty-separation check.
 *
 * ⚠ **It extends `PurchaseOrderTestCase` for `SalesOrderConfirmTest`'s reason** — the customer, address,
 * warehouse and item fixtures live there because `OperationSuccessRecipes` is
 * `@phpstan-require-extends PurchaseOrderTestCase` and the sales recipes have to live inside that trait.
 *
 * **What is NOT asserted here, deliberately:** the lock's position and the two-connection races, which
 * belong to `PurchaseOrderRowLockTest` beside their purchase mirrors and the `SALES_LOCK_FIRST_CHECKED`
 * accounting that derives its domain from `src/`. A second copy here would be a race test that cannot
 * fail for a reason the accounting can see.
 */
final class SalesOrderCancellationTest extends PurchaseOrderTestCase
{
    /**
     * **API-21 branch 5: 200, the resource in its NEW state, and no `Location`.** A cancellation creates
     * no row and has no row-level URI to name beyond the one the caller is already on.
     *
     * The body's key list is compared with `ConfirmSalesOrder`'s and `GetSalesOrder`'s, because all three
     * are built by `SalesOrderRow` — an operation that assembled its own answer would drift the first
     * time a column moved.
     */
    #[Test]
    public function aCancellationAnswers200WithTheOrderInItsNewState(): void
    {
        $order = $this->raiseADraft();

        $this->send('POST', '/sales-orders/'.$order.'/cancellation', ['reason' => 'Customer withdrew']);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertNull($this->client()->getResponse()->headers->get('Location'),
            'A transition that creates no row must not name one.');

        $body = $this->json();
        self::assertSame(SalesOrder::STATUS_CANCELLED, $body['status']);
        self::assertSame([
            'id', 'customer', 'warehouse', 'currency_code', 'order_number', 'status', 'total_amount',
            'ordered_at', 'created_by', 'ship_to_line', 'ship_to_city', 'ship_to_postal_code',
            'ship_to_country_code', 'archived_at',
        ], array_keys($body));

        // Read back from the database, not from the body it just produced: the response is the thing
        // under test, so it cannot also be the evidence.
        self::assertSame([['status' => SalesOrder::STATUS_CANCELLED]], $this->fetchAsOwnerForTenant(
            self::TENANT_A, 'SELECT status FROM sales_order WHERE id = ?::uuid', [$order]));
    }

    /**
     * **§8.3's three legal sources, driven rather than read off the table** — `draft` and `confirmed`
     * both cancel. `partially_fulfilled` is the third and is **not** driven here because it is
     * unreachable: nothing in `src/` writes `quantity_fulfilled`, so no shipped operation can put an
     * order in that status. Forcing it with raw SQL would test the entity's guard, which
     * `TransitionGuardTest` already derives by reflection over the matrix, and would not test a path a
     * user can take. See `SalesOrder::cancel()`'s FORWARD DECLARATION and `RESIDUAL_GAPS.md` G-7 —
     * **`CreateFulfilment` is the trigger that makes this case drivable.**
     */
    #[Test]
    public function aConfirmedOrderCanBeCancelled(): void
    {
        $order = $this->raiseADraftWithALine();

        $this->send('POST', '/sales-orders/'.$order.'/confirmation');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(SalesOrder::STATUS_CONFIRMED, $this->json()['status']);

        $this->send('POST', '/sales-orders/'.$order.'/cancellation', ['reason' => 'Customer withdrew']);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(SalesOrder::STATUS_CANCELLED, $this->json()['status']);

        // `ordered_at` survives the cancellation, which is `PurchaseOrder::cancel()`'s rule read onto the
        // sell side: an order cancelled after it was confirmed keeps the date it was confirmed on,
        // because that is the fact the trail needs.
        self::assertSame([['status' => SalesOrder::STATUS_CANCELLED, 'stamped' => true]],
            $this->fetchAsOwnerForTenant(self::TENANT_A,
                'SELECT status, ordered_at IS NOT NULL AS stamped FROM sales_order WHERE id = ?::uuid', [$order]));
    }

    /**
     * **A cancelled order cannot be cancelled again** — §4.2's `Idem` cell is
     * `guard:sales_order.status`, and this is the mechanism that cell names. A replay is refused by
     * `cancel()`'s own status guard, which is how the caller learns the first attempt committed; no
     * `Idempotency-Key` is demanded, because API-14 as amended requires the header for `store` and
     * `unique:` only.
     *
     * **Error shape: 409 `business-rule-violated`, and NO JSON pointer even though this operation DOES
     * accept a field.** That is the same answer `ConfirmSalesOrder` gives and it is not inherited
     * carelessly: `d8aae9a`'s rule for choosing a pointer is *"name a field of the request that made
     * it"*, and the refusal here is about the order's status, not about `reason`.
     */
    #[Test]
    public function aCancelledOrderCannotBeCancelledAgain(): void
    {
        $order = $this->raiseADraft();

        $this->send('POST', '/sales-orders/'.$order.'/cancellation', ['reason' => 'First']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->send('POST', '/sales-orders/'.$order.'/cancellation', ['reason' => 'Second']);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertSame('urn:erp:problem:business-rule-violated', $body['type']);
        self::assertArrayNotHasKey('pointer', $body,
            'A refusal about the order\'s status named a field of the request instead.');

        // Exactly one trail row, not two. The second request's reason never reached `metadata`.
        self::assertSame([['reason' => 'First']], $this->fetchAsOwnerForTenant(self::TENANT_A,
            "SELECT metadata->>'reason' AS reason FROM audit_log WHERE event_type = ? AND subject_id = ?::uuid",
            [AuditEvent::SALES_ORDER_CANCELLED, $order]));
    }

    /**
     * **OI-2's `sales_order.cancelled`, read back cell by cell** — and this row is not decoration.
     * `ADR-008` §2 declines the sales duty split and names the compensation in the same sentence: *"the
     * compensating control is `audit_log`, not a second pair of hands."*
     *
     * Every cell is checked against what the catalogue declares rather than against a literal, so the
     * event name and its subject type cannot be asserted into agreement separately from `AuditEvent`.
     * **`ip_address` must be null** — API-16 restricts the column to security events, this is a
     * `BUSINESS` event, and `audit_log_ip_address_scope` refuses one at the database, so a non-null value
     * here would mean the writer had found a way past all three.
     */
    #[Test]
    public function theCancellationWritesItsAuditRow(): void
    {
        $order = $this->raiseADraft();

        $this->send('POST', '/sales-orders/'.$order.'/cancellation', ['reason' => '  Customer withdrew the order.  ']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $rows = $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT event_type, subject_type, subject_id::text AS subject_id, actor_label,
                   actor_user_id IS NOT NULL AS has_actor, ip_address, permission_code,
                   metadata->>'reason' AS reason
            FROM audit_log WHERE subject_id = ?::uuid
            SQL, [$order]);

        self::assertCount(1, $rows, 'A cancellation wrote no audit row, or more than one.');
        self::assertSame(AuditEvent::SALES_ORDER_CANCELLED, $rows[0]['event_type']);
        self::assertSame(AuditEvent::SUBJECT_TYPES[AuditEvent::SALES_ORDER_CANCELLED], $rows[0]['subject_type']);
        self::assertSame($order, $rows[0]['subject_id']);
        self::assertTrue((bool) $rows[0]['has_actor'], 'The trail row names no actor.');
        self::assertNotSame('', (string) $rows[0]['actor_label'], 'actor_label is NOT NULL so the trail survives the principal.');
        self::assertNull($rows[0]['ip_address'], 'A business event carried a source IP. API-16 restricts the column to security events.');
        self::assertNull($rows[0]['permission_code']);

        // Trimmed, not stored as sent: `metadata` is written once on an append-only table, so the
        // surrounding whitespace would be permanent.
        self::assertSame('Customer withdrew the order.', $rows[0]['reason']);

        // The classification is `SEC`'s and is asserted here too, because a business event that had
        // drifted onto the security list would be unreadable through `.view_record` and this operation's
        // whole control would be invisible to the colleague ADR-008 §2 relies on.
        self::assertContains(AuditEvent::SALES_ORDER_CANCELLED, AuditEvent::BUSINESS);
        self::assertNotContains(AuditEvent::SALES_ORDER_CANCELLED, AuditEvent::SECURITY);
    }

    /**
     * **Absent, null and blank `reason` all mean "none was given", and all three write `{}`** — not
     * `{"reason": null}` and not `{"reason": ""}`. A key carrying an empty string on an append-only table
     * is a permanent record that says nothing while looking like a record that says something.
     *
     * The positive control is the previous test: a real reason **does** land, so a writer that dropped
     * every reason would not pass both.
     */
    #[Test]
    public function aBlankOrAbsentReasonWritesAnEmptyMetadataObject(): void
    {
        // `[]` rather than `null` for the absent case: this operation deserializes, so a null body is sent
        // with no JSON content type and answered 415 before it reaches the processor. Read off the run,
        // not predicted. `[]` is an empty JSON object, which IS the "no reason field" request.
        foreach ([[], ['reason' => null], ['reason' => '   ']] as $i => $body) {
            $order = $this->raiseADraft('SO-BLANK-'.$i);

            $this->send('POST', '/sales-orders/'.$order.'/cancellation', $body);
            self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

            self::assertSame([['metadata' => '{}', 'has_key' => false]], $this->fetchAsOwnerForTenant(
                self::TENANT_A,
                // `jsonb_exists(...)` rather than the `?` operator: DBAL reads `?` as a positional
                // placeholder and rewrites it, so `metadata ? 'reason'` reaches Postgres as
                // `metadata $1 'reason'` and raises `42601`. Read off the run, not predicted.
                "SELECT metadata::text AS metadata, jsonb_exists(metadata, 'reason') AS has_key FROM audit_log WHERE subject_id = ?::uuid",
                [$order],
            ), 'Case '.$i.' stored a reason key for a reason nobody gave.');
        }
    }

    /**
     * **⚠ There is NO duty-separation refusal on this path, and that is ADR-008's decision rather than a
     * gap — so it is asserted, not merely left unbuilt.**
     *
     * The purchase mirror refuses `canceller == creator` (Amendment 1, whose predicate names
     * `purchase_order.cancel` and `purchase_order.created_by_user_id` by column). §2 declines the sales
     * equivalent in terms:
     *
     * > *"`sales_rep` holds **both** `sales_order.confirm` and `sales_order.cancel`, where the buying
     * > side splits the equivalent pair. … There is no outbound commitment for a cancellation to
     * > repudiate. This is the weaker of the two controls and it is knowingly weaker."*
     *
     * This test drives the case Amendment 1 would refuse — **the creator cancels their own order** — and
     * asserts it **succeeds**. That direction matters: a test asserting the refusal does not exist can be
     * satisfied by an operation that is broken for everyone, whereas this one fails the day somebody
     * copies `PurchaseOrderDutySeparation` across, which is the mistake `b1n2-ruling.md` records being
     * caught twice in one day.
     *
     * `created_by_user_id` is asserted to be the caller first, so the case is known to be the
     * self-cancellation it claims to be rather than passing because the column was null (which R-6 would
     * make legal on the purchase side).
     */
    #[Test]
    public function theCreatorMayCancelTheirOwnOrderBecauseNoDsodRuleReachesSales(): void
    {
        $order = $this->raiseADraft();

        self::assertSame([['is_caller' => true]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT created_by_user_id = ?::uuid AS is_caller FROM sales_order WHERE id = ?::uuid',
            [$this->actorId, $order]),
            'This order was not raised by the caller, so cancelling it is not the self-cancellation case.');

        $this->send('POST', '/sales-orders/'.$order.'/cancellation', ['reason' => 'I raised it and I am withdrawing it.']);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(SalesOrder::STATUS_CANCELLED, $this->json()['status']);
    }

    /**
     * **API-9: an unknown field is refused, never ignored.** `reason` is the only field §4.2 accepts.
     */
    #[Test]
    public function anUndeclaredFieldIsRefused(): void
    {
        $order = $this->raiseADraft();

        $this->send('POST', '/sales-orders/'.$order.'/cancellation', ['reason' => 'ok', 'cancelled_by' => 'someone']);

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('urn:erp:problem:validation-failed', $this->json()['type']);
        self::assertSame([['pointer' => '/cancelled_by', 'code' => 'undeclared_field']], array_map(
            static fn (array $e): array => ['pointer' => $e['pointer'], 'code' => $e['code']],
            $this->json()['errors'],
        ));
        $this->assertStillDraft($order);
    }

    /**
     * **An id that is not a uuid is `not-found`, not `validation-failed`** — §5.3 clause 2: it names no
     * row in any tenant, so its answer must be the one another tenant's id gets.
     */
    #[Test]
    public function anIdThatIsNotAUuidIsNotFound(): void
    {
        $this->send('POST', '/sales-orders/not-a-uuid/cancellation', ['reason' => 'x']);

        self::assertSame(404, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /**
     * **An archived order cannot be cancelled, and the refusal promises no restore** (`owed-ux-c5.md` FINAL (a3)): it read
     * *"Restore it before changing it"*, pointing at `RestoreSalesOrder`, which is unbuilt (G-8). Archived by owner SQL
     * (a draft, the only state `sales_order_archived_only_while_draft` allows).
     * **Mutation:** put the old sentence back in `SalesOrderCancellation`; red here on the `detail` literal.
     */
    #[Test]
    public function anArchivedOrderCannotBeCancelledAndNoRestoreIsPromised(): void
    {
        $order = $this->raiseADraft('SO-140000');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE sales_order SET deleted_at = now() WHERE id = ?::uuid', [$order]);

        $this->send('POST', '/sales-orders/'.$order.'/cancellation', ['reason' => 'x']);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('sales_order.is_archived', $this->json()['rule']);
        self::assertSame('This sales order is archived, so it can\'t be cancelled.', $this->json()['detail']);
        $this->assertStillDraft($order);
    }

    /** An order raised through the shipped create, with no lines — a legal empty draft. */
    private function raiseADraft(string $sku = 'SO-138000'): string
    {
        $c = $this->salesWorkspace($sku);
        $this->send('POST', '/sales-orders', [
            'customer_id' => $c['customer'], 'warehouse_id' => $c['warehouse'],
            'currency_code' => 'EUR', 'ship_to_address_id' => $c['address'],
        ], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        return (string) $this->json()['id'];
    }

    /** The same, with one line, so the order can be confirmed first. */
    private function raiseADraftWithALine(string $sku = 'SO-139000'): string
    {
        $c = $this->salesWorkspace($sku);
        $this->send('POST', '/sales-orders', [
            'customer_id' => $c['customer'], 'warehouse_id' => $c['warehouse'],
            'currency_code' => 'EUR', 'ship_to_address_id' => $c['address'],
            'lines' => [['inventory_item_id' => $c['item'], 'quantity' => '1', 'unit_price' => '10']],
        ], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        return (string) $this->json()['id'];
    }

    /** @return array{customer: string, address: string, warehouse: string, item: string} */
    private function salesWorkspace(string $sku): array
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Acme Retail '.$sku);

        return [
            'customer' => $customer,
            'address' => $this->seedCustomerAddress(self::TENANT_A, $customer),
            'warehouse' => $this->seedWarehouse(self::TENANT_A, 'WH '.$sku),
            'item' => $this->seedItem(self::TENANT_A, 'SKU-'.$sku),
        ];
    }

    private function assertStillDraft(string $order): void
    {
        self::assertSame([['status' => SalesOrder::STATUS_DRAFT]], $this->fetchAsOwnerForTenant(
            self::TENANT_A, 'SELECT status FROM sales_order WHERE id = ?::uuid', [$order]));
    }
}

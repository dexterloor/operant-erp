<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Inventory\Application\StockAdjuster;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Http\Idempotency\IdempotencyKeyHash;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`CreateStockAdjustment` — the stock count, over HTTP** (stock count C1, 2026-10-16). Authority:
 * `docs/session13/stock-adjustment-ruling.md` (owner Q2 count-to with a required nullable `version`, Q3 (i) a matching
 * count is 409, Q4 archived items refused) > `stock-adjustment-final-red.md` (H-1: version → match → release rule) >
 * design Revision 1; codes `SEC` (`stock-adjustment-sec.md`), sentences `UX` (`stock-adjustment-ux.md`).
 *
 * The release rule (owner Q1 (C′)) is `StockAdjustmentReleaseTest`'s; the races are in `PurchaseOrderRowLockTest`
 * (R-2′, R-2″, R-5). Every fixture is laid as the owner and every assertion reads what was committed. A refused request
 * closes the shared entity manager, so each test lays its fixtures before its first refusal.
 */
final class StockAdjustmentTest extends PurchaseOrderTestCase
{
    use InvoiceFixtures;

    /**
     * Count-to: the level becomes the counted figure, ONE movement records the signed difference, the version moves. The
     * 201 is §4.4.3's `StockMovement` shape (API-23a) with `source_type` and `source_id` null **by literal**, on the body
     * and on the row (`SEC` §2: the raw INSERT never calls the entity constructor's pairing check). The scale is pinned by
     * literal (`"-3.000000"`), not left to `WireScaleTest`'s walk (the M2 lesson). The key is stored hashed (D-B).
     */
    #[Test]
    public function aCountSetsOnHandAndRecordsTheDifference(): void
    {
        $s = $this->stocked('10', 3);
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);
        $key = Uuid::v4()->toRfc4122();

        $this->recount($s, '7', 3, 'Shelf recount: three damaged and scrapped', $key);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertSame('/api/v1/stock-movements/'.$body['id'], $this->client()->getResponse()->headers->get('Location'));
        self::assertSame(['id', 'quantity', 'occurred_at', 'reason', 'source_type', 'source_id', 'inventory_item', 'storage_location'], array_keys($body));
        self::assertSame('-3.000000', $body['quantity']);
        self::assertSame('adjustment', $body['reason']);
        self::assertNull($body['source_type']);
        self::assertNull($body['source_id']);
        self::assertSame(['id' => $s['item'], 'sku' => $s['sku'], 'name' => $s['sku']], $body['inventory_item']);
        self::assertSame(['id' => $s['bin'], 'code' => 'A-01'], $body['storage_location']);

        self::assertSame([['qty_on_hand' => '7.000000', 'qty_reserved' => '0.000000', 'version' => 4]], $this->level($s));
        self::assertSame([[
            'quantity' => '-3.000000', 'reason' => 'adjustment', 'source_type' => null, 'source_id' => null,
            'idempotency_key' => IdempotencyKeyHash::of($key),
        ]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT quantity::text AS quantity, reason, source_type, source_id::text AS source_id, idempotency_key FROM stock_movement WHERE id = ?::uuid',
            [$body['id']]));

        $this->send('GET', '/stock-movements/'.$body['id']);
        self::assertSame(200, $this->httpStatus());
        self::assertSame($body, $this->json(), 'PC-17: the 201 is not GetStockMovement\'s document.');
    }

    #[Test]
    public function aCountUpRecordsAPositiveDifference(): void
    {
        $s = $this->stocked('2.5', 1);
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $this->recount($s, '4', 1);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('1.500000', $this->json()['quantity']);
        self::assertSame([['qty_on_hand' => '4.000000', 'qty_reserved' => '0.000000', 'version' => 2]], $this->level($s));
    }

    /** Q2: the version is the lock against a lost count. A moved version refuses with exactly `{qty_on_hand, version}` (`SEC` §5). */
    #[Test]
    public function aStaleVersionIsRefusedAndWritesNothing(): void
    {
        $s = $this->stocked('10', 3);
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $this->recount($s, '7', 2);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('urn:erp:problem:stale-record', $this->json()['type']);
        self::assertSame(['qty_on_hand' => '10.000000', 'version' => 3], $this->json()['current']);
        $this->assertNothingWritten($s, '10.000000', 3);
    }

    #[Test]
    public function aNullVersionIsStaleWhenARowExists(): void
    {
        $s = $this->stocked('10', 3);
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $this->recount($s, '7', null);

        self::assertSame(409, $this->httpStatus());
        self::assertSame(['qty_on_hand' => '10.000000', 'version' => 3], $this->json()['current']);
        $this->assertNothingWritten($s, '10.000000', 3);
    }

    #[Test]
    public function aVersionIsStaleWhenNoRowExists(): void
    {
        $s = $this->neverStocked();
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $this->recount($s, '5', 1);

        self::assertSame(409, $this->httpStatus());
        self::assertSame(['qty_on_hand' => '0.000000', 'version' => null], $this->json()['current']);
        self::assertSame([], $this->level($s));
    }

    /** Design §6: a bin that never held the item shows no row, so the counter quotes `version: null`, and the row is created at version 1. */
    #[Test]
    public function aNeverStockedBinIsCountedIntoWithANullVersion(): void
    {
        $s = $this->neverStocked();
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $this->recount($s, '5', null);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('5.000000', $this->json()['quantity']);
        self::assertSame([['qty_on_hand' => '5.000000', 'qty_reserved' => '0.000000', 'version' => 1]], $this->level($s));
    }

    /**
     * Q3 (i): a count equal to on-hand is 409, nothing stored — no movement (a zero movement would be a 23514 → 500 on
     * `stock_movement_quantity_nonzero`, mutation M11), no key, no audit row. Zero into a never-stocked bin is the same.
     */
    #[Test]
    public function aCountThatMatchesIsRefusedAndWritesNothing(): void
    {
        $s = $this->stocked('10', 3);
        $empty = $this->neverStocked();
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $this->recount($s, '10.000', 3);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('stock_level.count_matches_on_hand', $this->json()['rule']);
        self::assertSame(StockAdjuster::MATCHES, $this->json()['detail']);
        self::assertSame('This count matches the stock on record, so there\'s nothing to change. Nothing was recorded.', $this->json()['detail']);
        $this->assertNothingWritten($s, '10.000000', 3);

        $this->recount($empty, '0', null);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('stock_level.count_matches_on_hand', $this->json()['rule']);
        self::assertSame([], $this->level($empty));
        self::assertSame([], $this->auditRowsOf(AuditEvent::STOCK_MOVEMENT_ADJUSTED));
    }

    /**
     * **Final RED H-1, the lost count.** The counter saw 5 at version 3 and counted 7 (two found). A receipt of 2 then
     * posted, so the record reads 7 at version 4 while the shelf holds 9. Sending 7 with version 3 must be STALE, never
     * "matches": a match against a moved version is a coincidence of arithmetic, and answering it loses the found two.
     * **Mutation M25** (match before the version) → this goes red.
     */
    #[Test]
    public function aMatchingCountWithAnOldVersionIsStale(): void
    {
        $s = $this->stocked('7', 4);
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $this->recount($s, '7', 3);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('urn:erp:problem:stale-record', $this->json()['type']);
        self::assertSame(['qty_on_hand' => '7.000000', 'version' => 4], $this->json()['current']);
    }

    /** L-12: `version` is required AND nullable — absent is a 422 at its own pointer; `null` is a value (asserted by the never-stocked test). */
    #[Test]
    public function omittingVersionIsA422DistinctFromNull(): void
    {
        $s = $this->stocked('10', 3);
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $this->send('POST', '/stock-movements/adjustments', [
            'inventory_item_id' => $s['item'], 'storage_location_id' => $s['bin'], 'counted_quantity' => '7', 'note' => 'n',
        ], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([['pointer' => '/version', 'code' => 'required',
            'message' => 'Send the version shown with the stock figure you counted against, or null if this location showed none of this item.']],
            $this->json()['errors']);
        $this->assertNothingWritten($s, '10.000000', 3);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string, 2: string, 3: string}>
     */
    public static function malformedBodies(): iterable
    {
        yield 'a negative count (never a 23514)' => [['counted_quantity' => '-1'], '/counted_quantity', 'negative', 'A count can\'t be less than zero.'];
        yield 'a count with 14 integer digits' => [['counted_quantity' => '10000000000000'], '/counted_quantity', 'out_of_range', 'A count can have at most 13 digits before the decimal point and 6 after.'];
        yield 'a count with 7 decimals' => [['counted_quantity' => '1.0000001'], '/counted_quantity', 'out_of_range', 'A count can have at most 13 digits before the decimal point and 6 after.'];
        yield 'a count that is not a number' => [['counted_quantity' => 'seven'], '/counted_quantity', 'not_a_decimal', 'Enter the quantity counted as a number, for example 12 or 12.5.'];
        yield 'a count sent as a JSON number' => [['counted_quantity' => 7], '/counted_quantity', 'not_a_decimal', 'Enter the quantity counted as a number, for example 12 or 12.5.'];
        yield 'no count' => [['counted_quantity' => null], '/counted_quantity', 'required', 'Enter the quantity you counted.'];
        yield 'a blank note' => [['note' => '   '], '/note', 'blank', 'Say why the stock is being corrected.'];
        yield 'no note' => [['note' => null], '/note', 'required', 'Say why the stock is being corrected.'];
        yield 'a note of 501 characters' => [['note' => str_repeat('x', 501)], '/note', 'too_long', 'The note must be at most 500 characters.'];
        yield 'a version of 0' => [['version' => 0], '/version', 'not_an_integer', 'The version must be a whole number of at least 1, or null.'];
        yield 'a version sent as a string' => [['version' => '3'], '/version', 'not_an_integer', 'The version must be a whole number of at least 1, or null.'];
        yield 'an item id that is not a uuid' => [['inventory_item_id' => 'nope'], '/inventory_item_id', 'not_a_uuid', 'This field must be a UUID naming a row in this workspace.'];
    }

    /** @param array<string, mixed> $override */
    #[Test]
    #[DataProvider('malformedBodies')]
    public function aMalformedCountIsRefusedAtItsFieldAndWritesNothing(array $override, string $pointer, string $code, string $message): void
    {
        $s = $this->stocked('10', 3);
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $this->send('POST', '/stock-movements/adjustments', $override + [
            'inventory_item_id' => $s['item'], 'storage_location_id' => $s['bin'], 'counted_quantity' => '7', 'version' => 3, 'note' => 'n',
        ], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([['pointer' => $pointer, 'code' => $code, 'message' => $message]], $this->json()['errors']);
        $this->assertNothingWritten($s, '10.000000', 3);
    }

    /** Q4: an archived item is refused until restored (S-1, a new sentence: `ItemWriter::archived()`'s "changed" is the wrong verb). */
    #[Test]
    public function aCountOfAnArchivedItemIsRefused(): void
    {
        $s = $this->stocked('10', 3);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE inventory_item SET deleted_at = now() WHERE id = ?::uuid', [$s['item']]);
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $this->recount($s, '7', 3);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('inventory_item.is_archived', $this->json()['rule']);
        self::assertSame('This item is archived, so its stock can\'t be counted. It can be counted once it\'s restored.', $this->json()['detail']);
        $this->assertNothingWritten($s, '10.000000', 3);
    }

    /** Design §2.4 (mutation M16): an archived bin, or any bin of an archived warehouse, is not counted (S-2, S-3). */
    #[Test]
    public function aCountInAnArchivedLocationOrWarehouseIsRefused(): void
    {
        $bin = $this->stocked('10', 3);
        $site = $this->stocked('10', 3);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE storage_location SET deleted_at = now() WHERE id = ?::uuid', [$bin['bin']]);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE warehouse SET deleted_at = now() WHERE id = ?::uuid', [$site['warehouse']]);
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $this->recount($bin, '7', 3);
        self::assertSame(409, $this->httpStatus());
        self::assertSame(['storage_location.is_archived', 'This location is archived, so stock can\'t be counted in it.'], [$this->json()['rule'], $this->json()['detail']]);

        $this->recount($site, '7', 3);
        self::assertSame(409, $this->httpStatus());
        self::assertSame(['warehouse.is_archived', 'This warehouse is archived, so stock can\'t be counted in it until it\'s restored.'], [$this->json()['rule'], $this->json()['detail']]);

        $this->assertNothingWritten($bin, '10.000000', 3);
        $this->assertNothingWritten($site, '10.000000', 3);
    }

    /** `SEC` §5: an unknown id and another workspace's are ONE body, byte for byte (no cross-tenant existence oracle). */
    #[Test]
    public function anUnknownOrForeignItemOrLocationIsTheSameUnknownReference(): void
    {
        $s = $this->stocked('10', 3);
        $foreign = $this->stocked('10', 3, self::TENANT_B);
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $bodies = [];
        foreach (['inventory_item_id' => [Uuid::v4()->toRfc4122(), $foreign['item']], 'storage_location_id' => [Uuid::v4()->toRfc4122(), $foreign['bin']]] as $field => $ids) {
            foreach ($ids as $id) {
                $this->send('POST', '/stock-movements/adjustments', [$field => $id] + [
                    'inventory_item_id' => $s['item'], 'storage_location_id' => $s['bin'], 'counted_quantity' => '7', 'version' => 3, 'note' => 'n',
                ], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
                self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
                $bodies[$field][] = (string) $this->client()->getResponse()->getContent();
            }
            self::assertSame($bodies[$field][0], $bodies[$field][1], 'An unknown '.$field.' and another workspace\'s are told apart.');
        }
        self::assertSame([['pointer' => '/inventory_item_id', 'code' => 'unknown_reference', 'message' => 'No inventory item in this workspace has this id.']],
            json_decode($bodies['inventory_item_id'][0], true)['errors']);
        self::assertSame([['pointer' => '/storage_location_id', 'code' => 'unknown_reference', 'message' => 'No storage location in this workspace has this id.']],
            json_decode($bodies['storage_location_id'][0], true)['errors']);
        $this->assertNothingWritten($s, '10.000000', 3);
    }

    /**
     * **E-25 (a)'s audit half** (`SEC` §3): one `stock_movement.adjusted` row per movement, actor the caller, subject the
     * movement, no IP, `metadata` keys EXACTLY `counted_quantity`, `note` — a third key reddens this, so `SEC`'s "never"
     * list is enforced by the key set. A replay writes no second row. Mutation M10 (omit the row) → red.
     */
    #[Test]
    public function anAdjustmentWritesAnAuditRowNamingItsActor(): void
    {
        $s = $this->stocked('10', 3);
        $actor = $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);
        $key = Uuid::v4()->toRfc4122();

        $this->recount($s, '7.5', 3, 'Cycle count, aisle 4', $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $id = $this->json()['id'];
        $this->recount($s, '7.5', 3, 'Cycle count, aisle 4', $key);
        self::assertSame(201, $this->httpStatus(), 'The replay.');

        $rows = $this->auditRowsOf(AuditEvent::STOCK_MOVEMENT_ADJUSTED);
        self::assertCount(1, $rows, 'A replay wrote a second audit row, or the count wrote none.');
        self::assertSame([
            'event_type' => 'stock_movement.adjusted', 'actor_user_id' => $actor, 'actor_label' => 'Fixture Actor',
            'subject_type' => 'stock_movement', 'subject_id' => $id, 'ip_address' => null, 'permission_code' => null,
        ], array_diff_key($rows[0], ['metadata' => true]));
        $metadata = json_decode($rows[0]['metadata'], true);
        $keys = array_keys($metadata);
        sort($keys);
        self::assertSame(['counted_quantity', 'note'], $keys, '`SEC` §3: exactly these two keys.');
        ksort($metadata);
        self::assertSame(['counted_quantity' => '7.500000', 'note' => 'Cycle count, aisle 4'], $metadata);
    }

    /**
     * API-14 / D-B: a retry after success is the first movement (201, same body), **never `stale-record`** although the
     * version it quotes has moved — the replay runs before every guard (mutation M14). One movement row.
     */
    #[Test]
    public function aRetryAfterSuccessIsAReplayNotStale(): void
    {
        $s = $this->stocked('10', 3);
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);
        $key = Uuid::v4()->toRfc4122();

        $this->recount($s, '7', 3, 'n', $key);
        self::assertSame(201, $this->httpStatus());
        $first = $this->json();
        $this->recount($s, '7', 3, 'n', $key);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($first, $this->json());
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM stock_movement WHERE storage_location_id = ?::uuid', [$s['bin']]));
        self::assertSame([['qty_on_hand' => '7.000000', 'qty_reserved' => '0.000000', 'version' => 4]], $this->level($s));
    }

    /** @return iterable<string, array{0: string, 1: mixed}> */
    public static function oneFieldChanged(): iterable
    {
        yield 'counted_quantity' => ['counted_quantity', '6'];
        yield 'version' => ['version', 4];
        yield 'note' => ['note', 'another reason'];
        yield 'storage_location_id' => ['storage_location_id', 'OTHER_BIN'];
        yield 'inventory_item_id' => ['inventory_item_id', 'OTHER_ITEM'];
    }

    /** The digest binds every accepted field (mutation M15: drop one from the digest → that row of this provider is red). */
    #[Test]
    #[DataProvider('oneFieldChanged')]
    public function theSameKeyWithAnotherBodyIsRefused(string $field, mixed $value): void
    {
        $s = $this->stocked('10', 3);
        $other = $this->stocked('10', 3);
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);
        $key = Uuid::v4()->toRfc4122();
        $body = ['inventory_item_id' => $s['item'], 'storage_location_id' => $s['bin'], 'counted_quantity' => '7', 'version' => 3, 'note' => 'n'];

        $this->send('POST', '/stock-movements/adjustments', $body, [IdempotencyKeyGuard::HEADER => $key]);
        self::assertSame(201, $this->httpStatus());

        $body[$field] = match ($value) { 'OTHER_BIN' => $other['bin'], 'OTHER_ITEM' => $other['item'], default => $value };
        $this->send('POST', '/stock-movements/adjustments', $body, [IdempotencyKeyGuard::HEADER => $key]);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('urn:erp:problem:idempotency-key-conflict', $this->json()['type']);
        self::assertSame('This Idempotency-Key was already used for a different request in this workspace. A new request needs a new key. '
            .'A recount is a new request, so it needs a new key too.', $this->json()['detail']);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM stock_movement WHERE reason = 'adjustment'"));
    }

    #[Test]
    public function aCountWithoutAnIdempotencyKeyIs428(): void
    {
        $s = $this->stocked('10', 3);
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $this->send('POST', '/stock-movements/adjustments', [
            'inventory_item_id' => $s['item'], 'storage_location_id' => $s['bin'], 'counted_quantity' => '7', 'version' => 3, 'note' => 'n',
        ]);

        self::assertSame(428, $this->httpStatus());
        $this->assertNothingWritten($s, '10.000000', 3);
    }

    /** @return iterable<string, array{0: string}> */
    public static function rolesWithoutAdjust(): iterable
    {
        foreach ([DefaultRoles::BUYER, DefaultRoles::SALES_REP, DefaultRoles::FINANCE, DefaultRoles::VIEWER] as $role) {
            yield $role => [$role];
        }
    }

    #[Test]
    #[DataProvider('rolesWithoutAdjust')]
    public function aRoleWithoutAdjustIsRefused(string $role): void
    {
        $s = $this->stocked('10', 3);
        $this->actAsRole($role);

        $this->recount($s, '7', 3);

        self::assertSame(403, $this->httpStatus());
        self::assertSame('stock_movement.adjust', $this->json()['required_permission']);
        $this->assertNothingWritten($s, '10.000000', 3);
    }

    /**
     * **G-32 / G-30 path (iii)'s paperwork half: found `short_shipped` units come back by a count, without a return.**
     * Shipped 10 from bin B, invoiced, an owner's `short_shipped` adjustment credit of 2; the 2 are then found on the shelf
     * and a `warehouse_operator` counts B up by 2. **The load-bearing assertion is the 201** (final RED L-6 / design R1.3):
     * the fixture holds NO quarantine stock of the item, so under owner Q1 (C′) the count is open to the operator; under
     * the literal option (A) it would be refused (mutation M18). The money assertions are context — Inventory cannot write
     * Sales tables (`WriteOwnershipTest`) — and say the count moved no money and wrote no return.
     */
    #[Test]
    public function foundShortShippedUnitsComeBackByACountWithoutAReturn(): void
    {
        $s = $this->shipped('CNT-P3', [['10', '10.0000', '10']]);
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);
        $this->send('GET', '/fulfilments/'.$s['fulfilment']);
        $line = $this->json()['lines'][0];
        $bin = $line['storage_location']['id'];
        $item = $line['inventory_item']['id'];
        $this->actAsRole(DefaultRoles::SALES_REP);
        $this->issue($s['fulfilment']);
        self::assertSame(201, $this->httpStatus(), 'Fixture: the invoice. '.(string) $this->client()->getResponse()->getContent());
        $invoice = $this->json()['id'];
        $this->actAsRole(DefaultRoles::OWNER);
        $this->send('POST', '/invoices/'.$invoice.'/credit-note-adjustments', ['reason' => 'short_shipped',
            'lines' => [['fulfilment_line_id' => $line['id'], 'quantity' => '2']]], ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), 'Fixture: the short_shipped credit. '.(string) $this->client()->getResponse()->getContent());
        $this->send('GET', '/invoices/'.$invoice);
        $credited = [$this->json()['amount_credited'], $this->json()['amount_due']];
        $level = $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT qty_on_hand::text AS q, version FROM stock_level WHERE inventory_item_id = ?::uuid AND storage_location_id = ?::uuid', [$item, $bin]);
        self::assertSame('0.000000', $level[0]['q'], 'Fixture: all ten left the bin.');
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A,
            "SELECT count(*) FROM stock_level s JOIN storage_location l ON l.id = s.storage_location_id WHERE s.inventory_item_id = ?::uuid AND l.kind = 'quarantine' AND s.qty_on_hand > 0", [$item]),
            'Fixture: no quarantine stock of the item, which is what keeps the count open to the operator under (C′).');

        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);
        $this->recount(['item' => $item, 'bin' => $bin], '2', (int) $level[0]['version'], 'Found the two short-shipped units behind the rack');

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('2.000000', $this->json()['quantity']);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM sales_return'), 'The count wrote no return.');
        $this->actAsRole(DefaultRoles::OWNER);
        $this->send('GET', '/invoices/'.$invoice);
        self::assertSame($credited, [$this->json()['amount_credited'], $this->json()['amount_due']], 'The count moved no money.');
    }

    /**
     * One live item in one live bin of a live warehouse, with a level seeded as the owner.
     *
     * @return array{warehouse: string, bin: string, item: string, sku: string}
     */
    private function stocked(string $onHand, int $version, string $tenant = self::TENANT_A, string $kind = 'bin'): array
    {
        $s = $this->neverStocked($tenant, $kind);
        $this->insertAsOwnerForTenant($tenant,
            'INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand, version) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric, ?)',
            [Uuid::v4()->toRfc4122(), $tenant, $s['item'], $s['bin'], $onHand, $version]);

        return $s;
    }

    /** @return array{warehouse: string, bin: string, item: string, sku: string} */
    private function neverStocked(string $tenant = self::TENANT_A, string $kind = 'bin'): array
    {
        $warehouse = $this->seedWarehouse($tenant, 'WH '.Uuid::v4()->toRfc4122());
        $sku = 'CNT-'.strtoupper(substr(Uuid::v4()->toRfc4122(), 0, 8));

        return [
            'warehouse' => $warehouse,
            'bin' => $this->seedStorageLocation($tenant, $warehouse, 'A-01', $kind),
            'item' => $this->seedItem($tenant, $sku),
            'sku' => $sku,
        ];
    }

    /** @param array{item: string, bin: string} $s */
    private function recount(array $s, string $counted, ?int $version, string $note = 'Recounted', ?string $key = null): void
    {
        $this->send('POST', '/stock-movements/adjustments', [
            'inventory_item_id' => $s['item'], 'storage_location_id' => $s['bin'], 'counted_quantity' => $counted, 'version' => $version, 'note' => $note,
        ], [IdempotencyKeyGuard::HEADER => $key ?? Uuid::v4()->toRfc4122()]);
    }

    /**
     * @param array{item: string, bin: string} $s
     *
     * @return list<array<string, mixed>>
     */
    private function level(array $s): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT qty_on_hand::text AS qty_on_hand, qty_reserved::text AS qty_reserved, version FROM stock_level WHERE inventory_item_id = ?::uuid AND storage_location_id = ?::uuid',
            [$s['item'], $s['bin']]);
    }

    /** @param array{item: string, bin: string} $s */
    private function assertNothingWritten(array $s, string $onHand, int $version): void
    {
        self::assertSame([['qty_on_hand' => $onHand, 'qty_reserved' => '0.000000', 'version' => $version]], $this->level($s), 'A refused count moved the level.');
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM stock_movement WHERE storage_location_id = ?::uuid', [$s['bin']]), 'A refused count wrote a movement.');
    }
}

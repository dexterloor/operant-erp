<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\AuditLog\Infrastructure\StockMovementAuditWriter;
use App\Modules\Inventory\Application\StockTransferrer;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Http\Idempotency\IdempotencyKeyHash;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`CreateStockTransfer` — moving stock between two bins, over HTTP** (stock transfer C1, 2026-10-20). Authority:
 * `docs/session13/s35-ruling.md` (owner Q-T1 within and between warehouses, one instant move; Q-T2 quarantine →
 * quarantine open; Q-T3 the note optional) > `stock-transfer-sec-2.md` > `stock-transfer-design.md` Revision 2 §C1.4
 * tests 1–10; codes `SEC` (`stock-transfer-sec.md`), sentences PLACEHOLDERS for `UX`.
 *
 * The release rule is `StockTransferReleaseTest`'s; the races are `StockTransferRaceTest`'s. Every fixture is laid as the
 * owner and every assertion reads what was committed. A refused request closes the shared entity manager, so each test
 * lays its fixtures before its first refusal.
 */
final class StockTransferTest extends PurchaseOrderTestCase
{
    use InvoiceFixtures;

    /**
     * Test 1. One request posts an equal-and-opposite PAIR, each leg naming the other (`source_type` `stock_movement`,
     * `SEC` §2), asserted **by literal** on the 201 and on both rows read back as the owner (the raw INSERT never calls the
     * entity constructor). The destination bin never held the item, so its row is created. The key is stored hashed on the
     * out leg alone (D-B); the in leg carries none. The 201 is the out leg, and it is `GetStockMovement`'s document (PC-17).
     */
    #[Test]
    public function aTransferPostsALinkedPairAndMovesBothLevels(): void
    {
        $s = $this->site();
        $this->level($s['item'], $s['a'], '10');
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);
        $key = Uuid::v4()->toRfc4122();

        $this->transfer($s['item'], $s['a'], $s['b'], '5', 'Moved to the front shelf', $key);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertSame('/api/v1/stock-movements/'.$body['id'], $this->client()->getResponse()->headers->get('Location'));
        self::assertSame(['id', 'quantity', 'occurred_at', 'reason', 'source_type', 'source_id', 'inventory_item', 'storage_location'], array_keys($body));
        self::assertSame('-5.000000', $body['quantity']);
        self::assertSame('transfer_out', $body['reason']);
        self::assertSame('stock_movement', $body['source_type']);
        self::assertSame(['id' => $s['item'], 'sku' => $s['sku'], 'name' => $s['sku']], $body['inventory_item']);
        self::assertSame(['id' => $s['a'], 'code' => 'A-01'], $body['storage_location']);
        $in = $body['source_id'];

        self::assertSame([
            ['id' => $body['id'], 'storage_location_id' => $s['a'], 'quantity' => '-5.000000', 'reason' => 'transfer_out',
                'source_type' => 'stock_movement', 'source_id' => $in, 'idempotency_key' => IdempotencyKeyHash::of($key), 'has_digest' => true],
            ['id' => $in, 'storage_location_id' => $s['b'], 'quantity' => '5.000000', 'reason' => 'transfer_in',
                'source_type' => 'stock_movement', 'source_id' => $body['id'], 'idempotency_key' => null, 'has_digest' => false],
        ], $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT id::text AS id, storage_location_id::text AS storage_location_id, quantity::text AS quantity, reason, source_type,
                   source_id::text AS source_id, idempotency_key, request_digest IS NOT NULL AS has_digest
              FROM stock_movement WHERE inventory_item_id = ?::uuid ORDER BY quantity
            SQL, [$s['item']]));

        self::assertSame(['a' => ['5.000000', 2], 'b' => ['5.000000', 1]], $this->levels($s), 'The source fell by 5; the destination row was created.');

        $this->send('GET', '/stock-movements/'.$body['id']);
        self::assertSame(200, $this->httpStatus());
        self::assertSame($body, $this->json(), 'PC-17: the 201 is not GetStockMovement\'s document.');
        $this->send('GET', '/stock-movements/'.$in);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['5.000000', 'transfer_in', 'stock_movement', $body['id'], ['id' => $s['b'], 'code' => 'A-02']],
            [$this->json()['quantity'], $this->json()['reason'], $this->json()['source_type'], $this->json()['source_id'], $this->json()['storage_location']]);
    }

    /**
     * Test 2 — **E-25 (b): a transfer nets to zero for the item** — the legs sum to zero and the item's total on hand is
     * unchanged; and after a run of refusals (shortage, same bin, archived destination, release) there is no lone
     * `transfer_in` and no movement at all. Mutation M-N1 (an unequal in leg) → red.
     */
    #[Test]
    public function aTransferNetsToZeroForTheItem(): void
    {
        $s = $this->site();
        $q = $this->bin($s['warehouse'], 'Q-01', 'quarantine');
        $archived = $this->bin($s['warehouse'], 'X-01');
        $this->level($s['item'], $s['a'], '10');
        $this->level($s['item'], $q, '3');
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE storage_location SET deleted_at = now() WHERE id = ?::uuid', [$archived]);
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        foreach ([[$s['a'], $s['b'], '11'], [$s['a'], $s['a'], '1'], [$s['a'], $archived, '1'], [$q, $s['b'], '1']] as [$from, $to, $qty]) {
            $this->transfer($s['item'], $from, $to, $qty);
            self::assertContains($this->httpStatus(), [409, 422], (string) $this->client()->getResponse()->getContent());
        }
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM stock_movement WHERE inventory_item_id = ?::uuid', [$s['item']]),
            'A refused transfer wrote a movement (a lone transfer_in is stock from nowhere).');

        $this->transfer($s['item'], $s['a'], $s['b'], '2.5');
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->transfer($s['item'], $s['b'], $s['a'], '1');
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        self::assertSame([['legs' => '0.000000', 'n' => 4, 'outs' => 2, 'ins' => 2, 'total' => '13.000000']], $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT (SELECT sum(quantity)::numeric(19,6)::text FROM stock_movement WHERE inventory_item_id = ?::uuid) AS legs,
                   (SELECT count(*) FROM stock_movement WHERE inventory_item_id = ?::uuid)::int AS n,
                   (SELECT count(*) FROM stock_movement WHERE inventory_item_id = ?::uuid AND reason = 'transfer_out')::int AS outs,
                   (SELECT count(*) FROM stock_movement WHERE inventory_item_id = ?::uuid AND reason = 'transfer_in')::int AS ins,
                   (SELECT sum(qty_on_hand)::numeric(19,6)::text FROM stock_level WHERE inventory_item_id = ?::uuid) AS total
            SQL, [$s['item'], $s['item'], $s['item'], $s['item'], $s['item']]));
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT count(*) FROM stock_movement m
             WHERE m.inventory_item_id = ?::uuid
               AND NOT EXISTS (SELECT 1 FROM stock_movement p WHERE p.id = m.source_id AND p.source_id = m.id AND p.quantity = -m.quantity)
            SQL, [$s['item']]), 'A leg has no equal-and-opposite partner naming it back.');
    }

    /** Test 3 — owner Q-T1: a transfer between two warehouses is one instant move, the same pair. */
    #[Test]
    public function aTransferAcrossWarehouses(): void
    {
        $s = $this->site();
        $elsewhere = $this->bin($this->seedWarehouse(self::TENANT_A, 'WH '.Uuid::v4()->toRfc4122()), 'B-01');
        $this->level($s['item'], $s['a'], '10');
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $this->transfer($s['item'], $s['a'], $elsewhere, '4');

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['6.000000', 2], $this->onHand($s['item'], $s['a']));
        self::assertSame(['4.000000', 1], $this->onHand($s['item'], $elsewhere));
    }

    /** @return iterable<string, array{0: ?string}> */
    public static function shortSources(): iterable
    {
        yield 'a source holding less' => ['3'];
        yield 'a source with no row for the item' => [null];
    }

    /**
     * Test 4 — the shortage refusal (`SEC` §3) states the source bin's on-hand when checked, that figure only: no
     * reserved or available figure (G-7), no destination figure, no version. The fixture seeds ONLY the shortage (the
     * destination has room), so the refusal is not the overflow's whatever the bin ids' order. Mutation M-S1 (decrement
     * without `>=`) → 23514 → 500 → red.
     */
    #[Test]
    #[DataProvider('shortSources')]
    public function aShortSourceIsRefusedWithItsOnHandAndWritesNothing(?string $onHand): void
    {
        $s = $this->site();
        if (null !== $onHand) {
            $this->level($s['item'], $s['a'], $onHand);
        }
        $this->level($s['item'], $s['b'], '1');
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $this->transfer($s['item'], $s['a'], $s['b'], '5');

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $problem = $this->json();
        self::assertSame('stock_level.on_hand_covers_the_transfer', $problem['rule']);
        $held = null === $onHand ? '0' : '3';
        self::assertSame('The location you\'re moving stock from held '.$held.' of this item when this was checked, so 5 can\'t be moved out of it. Nothing was moved.', $problem['detail']);
        self::assertSame(StockTransferrer::onHandDoesNotCover(null === $onHand ? '0.000000' : '3.000000', '5.000000'), $problem['detail']);
        foreach (['qty_reserved', 'reserved', 'qty_available', 'available', 'version', 'on_hand', 'current'] as $member) {
            self::assertArrayNotHasKey($member, $problem, 'The shortage refusal serves `'.$member.'`.');
        }
        self::assertStringNotContainsString('1.000000', (string) $this->client()->getResponse()->getContent(), 'The destination\'s figure leaked.');
        self::assertSame(null === $onHand ? null : ['3.000000', 1], $this->onHandOrNull($s['item'], $s['a']));
        self::assertSame(['1.000000', 1], $this->onHand($s['item'], $s['b']));
        $this->assertNoMovement($s['item']);
    }

    /**
     * Test 5 — the overflow refusal (`stock_level.on_hand_fits`) states no figure; the bound is inclusive. The fixture seeds
     * ONLY the overflow (the source has the stock). Mutation M-S2 (the upsert without its bound) → 22003 → 500 → red.
     */
    #[Test]
    public function aDestinationThatWouldOverflowIsRefusedAndTheBoundIsInclusive(): void
    {
        $s = $this->site();
        $this->level($s['item'], $s['a'], '10');
        $this->level($s['item'], $s['b'], '9999999999998.999999');
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $this->transfer($s['item'], $s['a'], $s['b'], '1.000001');
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['stock_level.on_hand_fits', StockTransferrer::ON_HAND_FITS], [$this->json()['rule'], $this->json()['detail']]);
        self::assertSame('The location you\'re moving stock to already holds so much of this item that the quantity moved would exceed 13 digits before the decimal point. Nothing was moved.', $this->json()['detail']);
        $this->assertNoMovement($s['item']);

        $this->transfer($s['item'], $s['a'], $s['b'], '1');
        self::assertSame(201, $this->httpStatus(), 'The bound is inclusive: 9999999999999.999999 fits. '.$this->client()->getResponse()->getContent());
        self::assertSame(['9999999999999.999999', 2], $this->onHand($s['item'], $s['b']));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string, 2: string, 3: string}>
     */
    public static function malformedBodies(): iterable
    {
        yield 'the same bin' => [['destination_storage_location_id' => 'SAME'], '/destination_storage_location_id', 'same_as_source', 'Choose a location other than the one the stock is moving from.'];
        yield 'the same bin, its id in capitals' => [['destination_storage_location_id' => 'SAME_UPPER'], '/destination_storage_location_id', 'same_as_source', 'Choose a location other than the one the stock is moving from.'];
        yield 'a quantity of zero' => [['quantity' => '0'], '/quantity', 'not_positive', 'The quantity to move must be more than zero.'];
        yield 'a negative quantity' => [['quantity' => '-2'], '/quantity', 'not_positive', 'The quantity to move must be more than zero.'];
        yield 'a quantity with 7 decimals' => [['quantity' => '0.0000001'], '/quantity', 'out_of_range', 'A quantity can have at most 13 digits before the decimal point and 6 after.'];
        yield 'a quantity with 14 integer digits' => [['quantity' => '10000000000000'], '/quantity', 'out_of_range', 'A quantity can have at most 13 digits before the decimal point and 6 after.'];
        yield 'a quantity that is not a number' => [['quantity' => 'five'], '/quantity', 'not_a_decimal', 'Enter the quantity to move as a number, for example 12 or 12.5.'];
        yield 'a quantity sent as a JSON number' => [['quantity' => 5], '/quantity', 'not_a_decimal', 'Enter the quantity to move as a number, for example 12 or 12.5.'];
        yield 'no quantity' => [['quantity' => null], '/quantity', 'required', 'Enter the quantity to move.'];
        yield 'a note of 501 characters' => [['note' => str_repeat('x', 501)], '/note', 'too_long', 'The note must be at most 500 characters.'];
        yield 'a note that is not a string' => [['note' => 7], '/note', 'not_a_string', 'This field must be a string.'];
        yield 'a source that is not a uuid' => [['source_storage_location_id' => 'nope'], '/source_storage_location_id', 'not_a_uuid', 'This field must be a UUID naming a row in this workspace.'];
        yield 'no destination' => [['destination_storage_location_id' => null], '/destination_storage_location_id', 'required', 'This field is required.'];
    }

    /**
     * Test 6 — shape, at its own pointer, nothing written. The same bin is `same_as_source` on CANONICAL ids, so the source's
     * id in capitals is the same bin (mutations M-E2 / M-E2b).
     *
     * @param array<string, mixed> $override
     */
    #[Test]
    #[DataProvider('malformedBodies')]
    public function aMalformedTransferIsRefusedAtItsFieldAndWritesNothing(array $override, string $pointer, string $code, string $message): void
    {
        $s = $this->site();
        $this->level($s['item'], $s['a'], '10');
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $override = array_map(static fn (mixed $v): mixed => match ($v) { 'SAME' => $s['a'], 'SAME_UPPER' => strtoupper($s['a']), default => $v }, $override);
        $this->send('POST', '/stock-movements/transfers', $override + [
            'inventory_item_id' => $s['item'], 'source_storage_location_id' => $s['a'], 'destination_storage_location_id' => $s['b'], 'quantity' => '5',
        ], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([['pointer' => $pointer, 'code' => $code, 'message' => $message]], $this->json()['errors']);
        self::assertSame(['10.000000', 1], $this->onHand($s['item'], $s['a']));
        $this->assertNoMovement($s['item']);
    }

    /**
     * Test 7 — archived item, either warehouse, either bin: 409 naming the side (mutation M-E1: an archived source
     * accepted → red). Unknown and another workspace's ids are ONE 422 body, byte for byte (`SEC` §8).
     */
    #[Test]
    public function anArchivedItemWarehouseOrBinOnEitherSideIsRefused(): void
    {
        $item = $this->site();
        $this->level($item['item'], $item['a'], '10');
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE inventory_item SET deleted_at = now() WHERE id = ?::uuid', [$item['item']]);

        $cases = [];
        foreach (['source bin', 'destination bin', 'source warehouse', 'destination warehouse'] as $case) {
            $s = $this->site();
            $other = $this->bin($this->seedWarehouse(self::TENANT_A, 'WH '.Uuid::v4()->toRfc4122()), 'B-01');
            $this->level($s['item'], $s['a'], '10');
            [$from, $to] = str_starts_with($case, 'source') ? [$s['a'], $other] : [$other, $s['a']];
            if (str_starts_with($case, 'destination')) {
                $this->level($s['item'], $other, '10');
            }
            $archivedBin = str_starts_with($case, 'source') ? $from : $to;
            if (str_ends_with($case, 'bin')) {
                $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE storage_location SET deleted_at = now() WHERE id = ?::uuid', [$archivedBin]);
            } else {
                $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE warehouse SET deleted_at = now() WHERE id = (SELECT warehouse_id FROM storage_location WHERE id = ?::uuid)', [$archivedBin]);
            }
            $cases[$case] = [$s['item'], $from, $to];
        }
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $this->transfer($item['item'], $item['a'], $item['b'], '1');
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['inventory_item.is_archived', StockTransferrer::ITEM_ARCHIVED], [$this->json()['rule'], $this->json()['detail']]);

        $expected = [
            'source bin' => ['storage_location.is_archived', StockTransferrer::SOURCE_LOCATION_ARCHIVED],
            'destination bin' => ['storage_location.is_archived', StockTransferrer::DESTINATION_LOCATION_ARCHIVED],
            'source warehouse' => ['warehouse.is_archived', StockTransferrer::SOURCE_WAREHOUSE_ARCHIVED],
            'destination warehouse' => ['warehouse.is_archived', StockTransferrer::DESTINATION_WAREHOUSE_ARCHIVED],
        ];
        foreach ($cases as $case => [$caseItem, $from, $to]) {
            $this->transfer($caseItem, $from, $to, '1');
            self::assertSame(409, $this->httpStatus(), $case.': '.$this->client()->getResponse()->getContent());
            self::assertSame($expected[$case], [$this->json()['rule'], $this->json()['detail']], $case);
            $this->assertNoMovement($caseItem);
        }
        $this->assertNoMovement($item['item']);
    }

    /** Test 7, continued: an unknown id and another workspace's are ONE body, at the field's pointer, for all three ids. */
    #[Test]
    public function anUnknownOrForeignItemOrBinIsTheSameUnknownReference(): void
    {
        $s = $this->site();
        $this->level($s['item'], $s['a'], '10');
        $foreign = $this->site(self::TENANT_B);
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $messages = [
            'inventory_item_id' => 'No inventory item in this workspace has this id.',
            'source_storage_location_id' => 'No storage location in this workspace has this id.',
            'destination_storage_location_id' => 'No storage location in this workspace has this id.',
        ];
        foreach (['inventory_item_id' => $foreign['item'], 'source_storage_location_id' => $foreign['a'], 'destination_storage_location_id' => $foreign['b']] as $field => $theirs) {
            $bodies = [];
            foreach ([Uuid::v4()->toRfc4122(), $theirs] as $id) {
                $this->send('POST', '/stock-movements/transfers', [$field => $id] + [
                    'inventory_item_id' => $s['item'], 'source_storage_location_id' => $s['a'], 'destination_storage_location_id' => $s['b'], 'quantity' => '1',
                ], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
                self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
                $bodies[] = (string) $this->client()->getResponse()->getContent();
            }
            self::assertSame($bodies[0], $bodies[1], 'An unknown '.$field.' and another workspace\'s are told apart.');
            self::assertSame([['pointer' => '/'.$field, 'code' => 'unknown_reference', 'message' => $messages[$field]]], json_decode($bodies[0], true)['errors']);
        }
        $this->assertNoMovement($s['item']);
    }

    /**
     * Test 8 — **E-25 (b)'s audit half, per leg** (`SEC` §1.3): exactly two `stock_movement.adjusted` rows, subjects the out
     * leg and the in leg, actor the caller, no IP, `metadata` keys exactly `TRANSFER_METADATA_KEYS` **and** the literal
     * `["note"]`, the same note on both; still two after a same-key replay; `null` when no note was sent; none after a
     * refusal. Mutations M-A1 (omit the in-leg row), M-A4 (add `counted_quantity`) → red.
     */
    #[Test]
    public function aTransferWritesOneAuditRowPerLegNamingItsActor(): void
    {
        $s = $this->site();
        $this->level($s['item'], $s['a'], '10');
        $actor = $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);
        $key = Uuid::v4()->toRfc4122();

        $this->transfer($s['item'], $s['a'], $s['b'], '12');
        self::assertSame(409, $this->httpStatus(), 'The shortage.');
        self::assertCount(0, $this->auditRowsOf(AuditEvent::STOCK_MOVEMENT_ADJUSTED), 'A refusal wrote an audit row.');

        $this->transfer($s['item'], $s['a'], $s['b'], '4', '  Front shelf restock  ', $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $out = $this->json()['id'];
        $in = $this->json()['source_id'];
        $this->transfer($s['item'], $s['a'], $s['b'], '4', '  Front shelf restock  ', $key);
        self::assertSame(201, $this->httpStatus(), 'The replay.');

        $rows = $this->auditRowsOf(AuditEvent::STOCK_MOVEMENT_ADJUSTED);
        self::assertCount(2, $rows, 'Not exactly one row per leg (or a replay wrote more).');
        $subjects = array_column($rows, 'subject_id');
        sort($subjects);
        $legs = [$out, $in];
        sort($legs);
        self::assertSame($legs, $subjects);
        foreach ($rows as $row) {
            self::assertSame([
                'event_type' => 'stock_movement.adjusted', 'actor_user_id' => $actor, 'actor_label' => 'Fixture Actor',
                'subject_type' => 'stock_movement', 'ip_address' => null, 'permission_code' => null,
            ], array_diff_key($row, ['metadata' => true, 'subject_id' => true]));
            $metadata = json_decode($row['metadata'], true);
            self::assertSame(StockMovementAuditWriter::TRANSFER_METADATA_KEYS, array_keys($metadata));
            self::assertSame(['note'], array_keys($metadata), '`SEC` §1.3: exactly `note`.');
            self::assertSame(['note' => 'Front shelf restock'], $metadata);
        }

        $this->transfer($s['item'], $s['a'], $s['b'], '1');
        self::assertSame(201, $this->httpStatus());
        $rows = $this->auditRowsOf(AuditEvent::STOCK_MOVEMENT_ADJUSTED);
        self::assertCount(4, $rows);
        self::assertSame(['{"note": null}', '{"note": null}'], [$rows[2]['metadata'], $rows[3]['metadata']], 'No note is JSON null (owner Q-T3).');
    }

    /**
     * Test 9 — API-14 / D-B. A replay is the first out leg, one pair; another body under the key is 409 (each field
     * digested, mutation M-I3); no key is 428; a COUNT's key sent on a transfer is 409 (the digest names the operation,
     * never a cross-operation replay); absent vs `null` note is the same request (canonical); `null` vs `"x"` is not.
     */
    #[Test]
    public function aRetryReplaysTheFirstOutLegAndOneTuplePair(): void
    {
        $s = $this->site();
        $this->level($s['item'], $s['a'], '10');
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);
        $key = Uuid::v4()->toRfc4122();
        $body = ['inventory_item_id' => $s['item'], 'source_storage_location_id' => $s['a'], 'destination_storage_location_id' => $s['b'], 'quantity' => '3'];

        $this->send('POST', '/stock-movements/transfers', $body, [IdempotencyKeyGuard::HEADER => $key]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $first = $this->json();
        $this->send('POST', '/stock-movements/transfers', $body + ['note' => null], [IdempotencyKeyGuard::HEADER => $key]);
        self::assertSame(201, $this->httpStatus(), 'Absent and null note are one request: '.$this->client()->getResponse()->getContent());
        self::assertSame($first, $this->json());
        $this->send('POST', '/stock-movements/transfers', $body + ['note' => '   '], [IdempotencyKeyGuard::HEADER => $key]);
        self::assertSame(201, $this->httpStatus(), 'A blank note is no note: '.$this->client()->getResponse()->getContent());
        self::assertSame($first, $this->json());

        $this->send('POST', '/stock-movements/transfers', $body + ['note' => 'x'], [IdempotencyKeyGuard::HEADER => $key]);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('urn:erp:problem:idempotency-key-conflict', $this->json()['type']);
        self::assertSame('This Idempotency-Key was already used for a different request in this workspace. A new request needs a new key. '
            .'Another move is a new request, so it needs a new key too.', $this->json()['detail']);

        self::assertSame(2, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM stock_movement WHERE inventory_item_id = ?::uuid', [$s['item']]));
        self::assertSame(['7.000000', 2], $this->onHand($s['item'], $s['a']));
        self::assertSame(['3.000000', 1], $this->onHand($s['item'], $s['b']));
    }

    /** @return iterable<string, array{0: string, 1: mixed}> */
    public static function oneFieldChanged(): iterable
    {
        yield 'quantity' => ['quantity', '2'];
        yield 'note' => ['note', 'another note'];
        yield 'source_storage_location_id' => ['source_storage_location_id', 'OTHER_BIN'];
        yield 'destination_storage_location_id' => ['destination_storage_location_id', 'OTHER_BIN'];
        yield 'inventory_item_id' => ['inventory_item_id', 'OTHER_ITEM'];
    }

    /** Test 9, continued: the digest binds every accepted field (mutation M-I3: drop one → that row is red). */
    #[Test]
    #[DataProvider('oneFieldChanged')]
    public function theSameKeyWithAnotherBodyIsRefused(string $field, mixed $value): void
    {
        $s = $this->site();
        $this->level($s['item'], $s['a'], '10');
        $otherBin = $this->bin($s['warehouse'], 'C-01');
        $otherItem = $this->seedItem(self::TENANT_A, 'XFR-OTHER-'.strtoupper(substr(Uuid::v4()->toRfc4122(), 0, 8)));
        $this->level($otherItem, $s['a'], '10');
        $this->level($s['item'], $otherBin, '10');
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);
        $key = Uuid::v4()->toRfc4122();
        $body = ['inventory_item_id' => $s['item'], 'source_storage_location_id' => $s['a'], 'destination_storage_location_id' => $s['b'], 'quantity' => '1', 'note' => 'n'];

        $this->send('POST', '/stock-movements/transfers', $body, [IdempotencyKeyGuard::HEADER => $key]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $body[$field] = match ($value) { 'OTHER_BIN' => $otherBin, 'OTHER_ITEM' => $otherItem, default => $value };
        $this->send('POST', '/stock-movements/transfers', $body, [IdempotencyKeyGuard::HEADER => $key]);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('urn:erp:problem:idempotency-key-conflict', $this->json()['type']);
        self::assertSame(2, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM stock_movement WHERE reason IN ('transfer_out', 'transfer_in')"));
    }

    /** Test 9, continued: a count's key sent on a transfer is a conflict, never a cross-operation replay. */
    #[Test]
    public function aCountsKeySentOnATransferIsAConflict(): void
    {
        $s = $this->site();
        $this->level($s['item'], $s['a'], '10');
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);
        $key = Uuid::v4()->toRfc4122();

        $this->send('POST', '/stock-movements/adjustments', [
            'inventory_item_id' => $s['item'], 'storage_location_id' => $s['a'], 'counted_quantity' => '9', 'version' => 1, 'note' => 'Recount',
        ], [IdempotencyKeyGuard::HEADER => $key]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->transfer($s['item'], $s['a'], $s['b'], '1', null, $key);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('urn:erp:problem:idempotency-key-conflict', $this->json()['type']);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM stock_movement WHERE reason IN ('transfer_out', 'transfer_in')"));
    }

    #[Test]
    public function aTransferWithoutAnIdempotencyKeyIs428(): void
    {
        $s = $this->site();
        $this->level($s['item'], $s['a'], '10');
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $this->send('POST', '/stock-movements/transfers', [
            'inventory_item_id' => $s['item'], 'source_storage_location_id' => $s['a'], 'destination_storage_location_id' => $s['b'], 'quantity' => '1',
        ]);

        self::assertSame(428, $this->httpStatus());
        $this->assertNoMovement($s['item']);
    }

    /** @return iterable<string, array{0: string}> */
    public static function rolesWithoutAdjust(): iterable
    {
        foreach ([DefaultRoles::BUYER, DefaultRoles::SALES_REP, DefaultRoles::FINANCE, DefaultRoles::VIEWER, DefaultRoles::PURCHASING_MANAGER] as $role) {
            yield $role => [$role];
        }
    }

    /** Test 10: E-1, the gate before anything is read; the denial names `stock_movement.adjust`. */
    #[Test]
    #[DataProvider('rolesWithoutAdjust')]
    public function aRoleWithoutAdjustIsRefused(string $role): void
    {
        $s = $this->site();
        $this->level($s['item'], $s['a'], '10');
        $this->actAsRole($role);

        $this->transfer($s['item'], $s['a'], $s['b'], '1');

        self::assertSame(403, $this->httpStatus());
        self::assertSame('stock_movement.adjust', $this->json()['required_permission']);
        $this->assertNoMovement($s['item']);
    }

    /** @return array{warehouse: string, a: string, b: string, item: string, sku: string} */
    private function site(string $tenant = self::TENANT_A): array
    {
        $warehouse = $this->seedWarehouse($tenant, 'WH '.Uuid::v4()->toRfc4122());
        $sku = 'XFR-'.strtoupper(substr(Uuid::v4()->toRfc4122(), 0, 8));

        return [
            'warehouse' => $warehouse,
            'a' => $this->seedStorageLocation($tenant, $warehouse, 'A-01'),
            'b' => $this->seedStorageLocation($tenant, $warehouse, 'A-02'),
            'item' => $this->seedItem($tenant, $sku),
            'sku' => $sku,
        ];
    }

    private function bin(string $warehouse, string $code, string $kind = 'bin'): string
    {
        return $this->seedStorageLocation(self::TENANT_A, $warehouse, $code, $kind);
    }

    private function level(string $item, string $bin, string $onHand, string $tenant = self::TENANT_A): void
    {
        $this->insertAsOwnerForTenant($tenant,
            'INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric)',
            [Uuid::v4()->toRfc4122(), $tenant, $item, $bin, $onHand]);
    }

    private function transfer(string $item, string $from, string $to, string $quantity, ?string $note = null, ?string $key = null): void
    {
        $body = ['inventory_item_id' => $item, 'source_storage_location_id' => $from, 'destination_storage_location_id' => $to, 'quantity' => $quantity];
        if (null !== $note) {
            $body['note'] = $note;
        }
        $this->send('POST', '/stock-movements/transfers', $body, [IdempotencyKeyGuard::HEADER => $key ?? Uuid::v4()->toRfc4122()]);
    }

    /** @return array{0: string, 1: int} */
    private function onHand(string $item, string $bin): array
    {
        $found = $this->onHandOrNull($item, $bin);
        self::assertNotNull($found, 'No stock_level row for the item in that bin.');

        return $found;
    }

    /** @return array{0: string, 1: int}|null */
    private function onHandOrNull(string $item, string $bin): ?array
    {
        $row = $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT qty_on_hand::text AS q, version FROM stock_level WHERE inventory_item_id = ?::uuid AND storage_location_id = ?::uuid', [$item, $bin]);

        return [] === $row ? null : [(string) $row[0]['q'], (int) $row[0]['version']];
    }

    /**
     * @param array{a: string, b: string, item: string} $s
     *
     * @return array{a: array{0: string, 1: int}, b: array{0: string, 1: int}}
     */
    private function levels(array $s): array
    {
        return ['a' => $this->onHand($s['item'], $s['a']), 'b' => $this->onHand($s['item'], $s['b'])];
    }

    private function assertNoMovement(string $item): void
    {
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM stock_movement WHERE inventory_item_id = ?::uuid', [$item]), 'A refused transfer wrote a movement.');
    }
}

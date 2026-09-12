<?php

declare(strict_types=1);

namespace App\Tests\Platform\Http;

use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Http\Idempotency\IdempotencyKeyHash;
use App\Platform\Http\Idempotency\RequestDigest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * D-B's two Platform pieces, with no kernel and no database: `RequestDigest`
 * (what a same-key replay is compared against) and `IdempotencyKeyHash` (what the
 * key column holds).
 *
 * Both are reused — step 8's `CreateGoodsReceipt` is the next caller — so their
 * properties are pinned here rather than only through `CreatePurchaseOrder`'s
 * HTTP tests.
 */
final class RetryKeyTest extends TestCase
{
    private const SUPPLIER = '0f1f4b89-ec30-4c30-82ec-a73427d3ce35';
    private const WAREHOUSE = '219ae76b-aa84-433e-93b1-dc30d7f0ecac';

    /**
     * **The canonical form, spelled out byte for byte.**
     *
     * Every stored `request_digest` was computed by this encoding, so changing it
     * — a flag, a key order, the timestamp format — turns every live key into a
     * conflict on its next honest retry. This is the test that makes such a
     * change a decision rather than an accident.
     */
    #[Test]
    public function theCanonicalFormIsFixed(): void
    {
        $expected = hash('sha256', '{"operation":"CreatePurchaseOrder","fields":{'
            .'"currency_code":null,'
            .'"expected_at":"2026-10-01T00:00:00.000000Z",'
            .'"supplier_id":"'.self::SUPPLIER.'",'
            .'"warehouse_id":"'.self::WAREHOUSE.'"}}');

        self::assertSame($expected, RequestDigest::of('CreatePurchaseOrder', [
            'supplier_id' => RequestDigest::uuid(self::SUPPLIER),
            'warehouse_id' => RequestDigest::uuid(self::WAREHOUSE),
            'currency_code' => null,
            'expected_at' => RequestDigest::instant('2026-10-01T00:00:00Z'),
        ]));
    }

    #[Test]
    public function oneInstantDigestsAlikeInEveryOffsetAndPrecisionTheValidatorAccepts(): void
    {
        $forms = ['2026-10-01T00:00:00Z', '2026-10-01T00:00:00+00:00', '2026-10-01T02:00:00+02:00', '2026-10-01T00:00:00.000Z'];

        self::assertSame(['2026-10-01T00:00:00.000000Z'], array_values(array_unique(array_map(RequestDigest::instant(...), $forms))));
        self::assertNotSame(RequestDigest::instant('2026-10-01T00:00:00Z'), RequestDigest::instant('2026-10-01T00:00:01Z'));
    }

    #[Test]
    public function aUuidDigestsAlikeInEitherCaseAndAnythingElseIsRefused(): void
    {
        self::assertSame(self::SUPPLIER, RequestDigest::uuid(strtoupper(self::SUPPLIER)));

        $this->expectException(\InvalidArgumentException::class);
        RequestDigest::uuid('not-a-uuid');
    }

    #[Test]
    public function objectKeyOrderIsNotPartOfTheDigestAndListOrderIs(): void
    {
        self::assertSame(
            RequestDigest::of('Op', ['a' => '1', 'b' => '2']),
            RequestDigest::of('Op', ['b' => '2', 'a' => '1']),
        );

        // §4.2's `lines[]`, when it arrives: two lines swapped are a different order.
        self::assertNotSame(
            RequestDigest::of('Op', ['lines' => [['sku' => 'A'], ['sku' => 'B']]]),
            RequestDigest::of('Op', ['lines' => [['sku' => 'B'], ['sku' => 'A']]]),
        );
    }

    #[Test]
    public function theOperationAndEveryValueArePartOfTheDigest(): void
    {
        $fields = ['supplier_id' => self::SUPPLIER, 'currency_code' => null];

        self::assertNotSame(RequestDigest::of('CreatePurchaseOrder', $fields), RequestDigest::of('CreateGoodsReceipt', $fields));
        self::assertNotSame(RequestDigest::of('Op', $fields), RequestDigest::of('Op', ['currency_code' => 'EUR'] + $fields));
        // Nulls are explicit: the caller passes every declared field, so a field
        // missing from the array is a different request from one sent as null.
        self::assertNotSame(RequestDigest::of('Op', $fields), RequestDigest::of('Op', ['supplier_id' => self::SUPPLIER]));
        self::assertMatchesRegularExpression(RequestDigest::PATTERN, RequestDigest::of('Op', $fields));
    }

    /** S-12: decimals travel as strings. A float's JSON spelling is exactly the cosmetic difference to remove. */
    #[Test]
    public function aFloatIsRefusedRatherThanEncoded(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RequestDigest::of('Op', ['quantity' => 1.5]);
    }

    /**
     * **6b's empty-list residual, closed here rather than in each caller.**
     *
     * `json_encode([])` is `[]` for both an empty list and an empty object, so a
     * digest holding one cannot tell two different requests apart. Both callers
     * that digest a collection today refuse an empty one — but *"both callers
     * happen to"* is not a guarantee, and the third would re-open it in silence.
     * Refused for the same reason a float is: an ambiguous spelling has no place
     * in a value whose whole job is to distinguish requests.
     */
    #[Test]
    public function anEmptyCollectionIsRefusedRatherThanEncodedAmbiguously(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RequestDigest::of('Op', ['supplier_id' => self::SUPPLIER, 'lines' => []]);
    }

    #[Test]
    public function aKeyIsStoredAsTheLowerCaseHexSha256OfTheHeaderValue(): void
    {
        self::assertSame(hash('sha256', 'retry-1'), IdempotencyKeyHash::of('retry-1'));
        self::assertMatchesRegularExpression(RequestDigest::PATTERN, IdempotencyKeyHash::of(str_repeat('k', IdempotencyKeyGuard::MAXIMUM_KEY_BYTES)));
    }

    /**
     * `sha256('')` is well-formed hex, so after hashing the column's
     * `octet_length BETWEEN 1 AND 255` CHECK cannot see an empty key. Every
     * keyless create would then share one stored key and "replay" each other.
     */
    #[Test]
    public function anEmptyOrOverlongKeyIsRefusedBeforeItCanBeHashedIntoSomethingValid(): void
    {
        foreach (['', str_repeat('k', IdempotencyKeyGuard::MAXIMUM_KEY_BYTES + 1)] as $key) {
            try {
                IdempotencyKeyHash::of($key);
                self::fail(\sprintf('A %d-byte key was hashed.', \strlen($key)));
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}

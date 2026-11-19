<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Http\Problem\ProblemType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **A `POST` is a `POST`: no header may turn it into another request method.**
 *
 * Until `framework.allowed_http_method_override: []`, Symfony honoured `X-HTTP-Method-Override` on every `POST`, and the
 * operation was chosen on the overridden method. Measured 2026-11-19: a `POST /items/{id}` carrying the header as `PATCH`
 * renamed the item (200), carrying it as `DELETE` archived the item (204), a `POST /items` carrying it as `GET` answered the
 * item list, and a malformed value (`a-b`) got a 400 outside the refusal shape. The caller still needed the permission of
 * the operation the request became, so no permission check was bypassed; what the header defeated is any rule written
 * against the method outside the app ("no `DELETE` from here").
 *
 * Each case names the operation the request would have become, so a red says which route came back. The caller holds
 * every code except the owner marker, so a refusal here is never a permission refusal. Items are seeded before any
 * request: a refused write closes the shared entity manager the seeders use.
 */
final class MethodOverrideTest extends ApiTestCase
{
    private const HEADER = 'X-HTTP-Method-Override';

    /** Was `UpdateItem` (200, name saved). A `POST` on a row address meets no route. */
    #[Test]
    public function aPostOverriddenToPatchIsRefusedAndChangesNothing(): void
    {
        $item = $this->seedItem(self::TENANT_A, 'MO-PATCH');

        $this->send('POST', '/items/'.$item, ['name' => 'After'], [self::HEADER => 'PATCH']);

        $this->assertMethodNotAllowed();
        $this->send('GET', '/items/'.$item);
        self::assertSame(200, $this->httpStatus());
        self::assertSame('MO-PATCH', $this->json()['name'], 'The overridden POST renamed the item.');
    }

    /** Was `DeleteItem` (204, item archived). */
    #[Test]
    public function aPostOverriddenToDeleteIsRefusedAndArchivesNothing(): void
    {
        $item = $this->seedItem(self::TENANT_A, 'MO-DELETE');

        $this->send('POST', '/items/'.$item, null, [self::HEADER => 'DELETE']);

        $this->assertMethodNotAllowed();
        $this->send('GET', '/items/'.$item);
        self::assertSame(200, $this->httpStatus());
        self::assertNull($this->json()['archived_at'], 'The overridden POST archived the item.');
    }

    /**
     * Was `ListItems` (200, the list). Sent without an `Idempotency-Key`, so only `CreateItem` can answer 428: a read
     * needs no key and would answer 200.
     */
    #[Test]
    public function aPostOverriddenToGetIsStillTheCreate(): void
    {
        $this->send('POST', '/items', ['sku' => 'MO-GET', 'name' => 'Get', 'unit_of_measure_code' => 'EA'], [self::HEADER => 'GET']);

        self::assertSame(428, $this->httpStatus(), $this->body());
        self::assertSame(ProblemType::IdempotencyKeyRequired->uri(), $this->json()['type']);
    }

    /** Was a 400 outside the refusal shape ("Invalid HTTP method override."): the value is no longer read at all. */
    #[Test]
    public function aMalformedOverrideValueIsIgnoredAndTheCreateRuns(): void
    {
        $this->send(
            'POST',
            '/items',
            ['sku' => 'MO-BAD', 'name' => 'Bad value', 'unit_of_measure_code' => 'EA'],
            [self::HEADER => 'a-b', IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()],
        );

        self::assertSame(201, $this->httpStatus(), $this->body());
        self::assertSame('MO-BAD', $this->json()['sku']);
    }

    /** Control: the real method still works, so the refusals above are about the header, not the operation. */
    #[Test]
    public function aRealPatchStillEditsTheItem(): void
    {
        $item = $this->seedItem(self::TENANT_A, 'MO-REAL');

        $this->send('PATCH', '/items/'.$item, ['name' => 'After']);

        self::assertSame(200, $this->httpStatus(), $this->body());
        self::assertSame('After', $this->json()['name']);
    }

    private function assertMethodNotAllowed(): void
    {
        $content = $this->body();
        self::assertSame(405, $this->httpStatus(), $content);
        self::assertSame(ProblemType::MethodNotAllowed->uri(), $this->json()['type'], $content);
        self::assertSame('GET, PATCH, DELETE', $this->json()['allow'], $content);
    }

    /** The first bytes only: a debug 400 carries a trace that echoes the request headers, the bearer token among them. */
    private function body(): string
    {
        return substr((string) $this->client()->getResponse()->getContent(), 0, 300);
    }
}

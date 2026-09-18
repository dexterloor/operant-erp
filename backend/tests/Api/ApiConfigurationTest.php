<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Http\Problem\ProblemType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Routing\RouterInterface;

/**
 * The three configuration rules the recipe contradicts: API-2 (one versioned
 * base path), API-3 (JSON, Hydra off) and API-4 (problem+json on every non-2xx).
 */
final class ApiConfigurationTest extends ApiTestCase
{
    /** API-2 / P-1. The expected prefix is the parameter, not a string here — one declaration, two readers. */
    #[Test]
    public function everyContractRouteIsUnderTheVersionedBasePath(): void
    {
        $basePath = self::getContainer()->getParameter('app.api_base_path');
        self::assertSame(self::BASE, $basePath);

        $router = self::getContainer()->get(RouterInterface::class);
        \assert($router instanceof RouterInterface);

        $checked = [];
        $notExposed = [];

        foreach ($router->getRouteCollection() as $name => $route) {
            $resourceClass = $route->getDefault('_api_resource_class');
            if (!\is_string($resourceClass) || !str_starts_with($resourceClass, 'App\\')) {
                continue;
            }

            // API Platform mints a `NotExposed` operation at
            // `/.well-known/genid/{id}` for any resource that declares no item
            // `GET` -- `Token` is one, because a token has no row and no id --
            // and there is no opt-out short of declaring an operation this
            // contract does not have. It is separated rather than filtered by
            // name: the assertion below is that every excluded route is the
            // vendor's not-exposed action, so a real route cannot hide here.
            if ('api_platform.action.not_exposed' === $route->getDefault('_controller')) {
                $notExposed[(string) $name] = $resourceClass;
                self::assertStringStartsWith($basePath.'/', $route->getPath(), $name.' is mounted outside the versioned base path.');

                continue;
            }

            $checked[(string) $name] = $route->getPath();
            self::assertStringStartsWith($basePath.'/', $route->getPath(), $name.' is mounted outside the versioned base path.');
        }

        // Three, and each is the same vendor behaviour for a different reason.
        // `Token` has no row and no id, so its fallback lands on the skolem
        // path. `PurchaseOrderLine` **does** have a row-level URI -- §4.2
        // declares `PATCH` and `DELETE` at `/purchase-orders/{id}/lines/{lineId}`
        // -- but declares no item `GET` there, which is exactly the shape
        // §4.4.1's `AssignRole` paragraph rules legitimate, so API Platform
        // mints one at the pluralised default path instead. It answers 404 by
        // construction (`NotFoundAction`) and `CreatedRowLocation` is what stops
        // the vendor's `Location` naming it.
        //
        // `SalesOrder` is the third, added 2026-09-19, and it is the **transient** case: §4.2 does declare
        // `GetSalesOrder` at `/sales-orders/{id}`, and ruling R-A deferred it one commit behind its writer.
        // Until it ships, this resource declares only a `POST`, so API Platform mints the same not-exposed
        // fallback — and `CreatedRowLocation::OPERATION_URI_TEMPLATE` on `CreateSalesOrder` is what stops
        // the 201's `Location` naming it. **This entry leaves this list when `GetSalesOrder` lands**, which
        // is the opposite of the two above.
        self::assertSame([
            '_api_/purchase_order_lines/{id}{._format}_get' => \App\Modules\Purchasing\ApiResource\PurchaseOrderLineResource::class,
            '_api_/sales_orders/{id}{._format}_get' => \App\Modules\Sales\ApiResource\SalesOrderResource::class,
            '_api_/.well-known/genid/{id}{._format}_get' => \App\Modules\UserManagement\ApiResource\TokenResource::class,
        ], $notExposed,
            'A route was excluded from the count as "not exposed" that is not one of the API Platform mints listed here.');

        // Six for the `/items` slice, three for Output 4 §7's authentication
        // paths (`IssueToken`, `GetCurrentUser`, and `ChangePassword` added
        // 2026-09-09 under E-23), nine for the purchasing slice added
        // 2026-09-10, and **two more on 2026-09-11** — `CreatePurchaseOrder`,
        // which E9-2 released once `Version20260911000100` gave it a
        // client-supplied retry key, and `CancelPurchaseOrder`, which X9-1 held
        // shut until `PurchaseOrder::cancel()` existed. The count is a floor
        // against a route appearing that nobody declared; which operations those
        // are is pinned by name in ItemOperationContractTest,
        // AuthOperationContractTest and PurchaseOrderOperationContractTest.
        // **Two more at step 8 (2026-09-11):** `CreateGoodsReceipt`, released by
        // 8-i's retry key and `PurchaseOrder::recordReceipt()`, and
        // `GetGoodsReceipt`, which exists so its 201's `Location` resolves.
        // **One more on 2026-09-19:** `CreateSalesOrder`, the first sales write, released by
        // `Version20260919000100`'s retry key and granted its two INSERTs by `Version20260919000200`.
        self::assertCount(23, $checked, 'The route set changed. This slice declares twenty-three operations: '.implode(', ', array_keys($checked)));
    }

    /**
     * API-3 — Hydra off. Asserted on what the recipe actually leaves behind:
     * the entrypoint and the JSON-LD context route, both of which are Hydra
     * documents, and the enabled format list.
     */
    #[Test]
    public function hydraAndJsonLdAreOff(): void
    {
        $router = self::getContainer()->get(RouterInterface::class);
        \assert($router instanceof RouterInterface);

        foreach (['api_entrypoint', 'api_jsonld_context'] as $recipeRoute) {
            self::assertNull($router->getRouteCollection()->get($recipeRoute), $recipeRoute.' is still registered.');
        }

        // Asserted over every format list API Platform keeps, and on the
        // media types rather than on the format keys: a format named something
        // else that still emits `application/ld+json` is the same defect.
        $lists = ['api_platform.formats', 'api_platform.patch_formats', 'api_platform.error_formats', 'api_platform.docs_formats'];
        $mediaTypes = [];

        foreach ($lists as $parameter) {
            /** @var array<string, list<string>> $formats */
            $formats = self::getContainer()->getParameter($parameter);
            self::assertNotSame([], $formats, $parameter.' is empty, which would make the assertion below vacuous.');
            self::assertArrayNotHasKey('jsonld', $formats, $parameter.' still registers the jsonld format.');

            foreach ($formats as $types) {
                $mediaTypes = [...$mediaTypes, ...$types];
            }
        }

        self::assertNotContains('application/ld+json', $mediaTypes);
        self::assertNotContains('application/vnd.api+json', $mediaTypes);
        self::assertSame(['json' => ['application/json']], self::getContainer()->getParameter('api_platform.formats'));
    }

    /** A client that asks for JSON-LD is refused rather than served it. */
    #[Test]
    public function aRequestForJsonLdIsRefused(): void
    {
        $this->seedItem(self::TENANT_A, 'SKU-1');

        foreach (['application/ld+json', 'application/vnd.api+json', 'application/xml'] as $mediaType) {
            $this->send('GET', '/items', null, [], $mediaType);

            self::assertSame(406, $this->httpStatus(), $mediaType.' was served.');
            self::assertStringStartsWith('application/problem+json', $this->contentType());
            self::assertSame(ProblemType::NotAcceptable->uri(), $this->json()['type']);
        }
    }

    /**
     * API-4 / P-2 — every non-2xx is `application/problem+json`, and API-13 —
     * every `type` is a `urn:erp:problem:` URN.
     *
     * The cases are the ones this slice can reach, and they deliberately span
     * both routes into the problem shape: the four raised inside an operation
     * (API Platform's error pipeline) and the two raised during routing, which
     * API Platform hands to Symfony's error controller unless something
     * intervenes.
     */
    #[Test]
    public function everyNonSuccessResponseIsProblemJsonWithAUrnType(): void
    {
        $id = $this->seedItem(self::TENANT_A, 'SKU-1');

        $cases = [
            'not found (in an operation)' => fn () => $this->send('GET', '/items/00000000-0000-4000-8000-000000000000'),
            'not found (no route at all)' => fn () => $this->send('GET', '/nothing-is-here'),
            'method not allowed (routing)' => fn () => $this->send('PUT', '/items/'.$id, ['name' => 'x']),
            'not acceptable' => fn () => $this->send('GET', '/items', null, [], 'application/ld+json'),
            'unsupported media type' => fn () => $this->sendRaw('POST', '/items', '{}', 'text/plain'),
            'validation failed' => fn () => $this->send('GET', '/items?nope=1'),
            'business rule violated' => fn () => $this->send('POST', '/items/'.$id.'/restoration', null, [IdempotencyKeyGuard::HEADER => 'k']),
            'idempotency key required' => fn () => $this->send('POST', '/items', ['sku' => 'x', 'name' => 'x', 'unit_of_measure_code' => 'EA']),
        ];

        foreach ($cases as $label => $drive) {
            $drive();

            self::assertGreaterThanOrEqual(400, $this->httpStatus(), $label);
            self::assertStringStartsWith('application/problem+json', $this->contentType(), $label.' is not problem+json.');

            $body = $this->json();
            self::assertArrayHasKey('type', $body, $label);
            self::assertStringStartsWith(ProblemType::URN_PREFIX, $body['type'], $label.' carries a type that is not a urn:erp:problem: URN.');
            self::assertSame($this->httpStatus(), $body['status'], $label);
            self::assertArrayNotHasKey('trace', $body, $label.' leaked a stack trace.');
            self::assertArrayNotHasKey('class', $body, $label.' leaked the exception class.');
        }
    }

    /** ST-8a/ST-8b — `Retry-After` is the remaining time, and the remaining time is reconnaissance. */
    #[Test]
    public function noResponseCarriesRetryAfter(): void
    {
        $this->send('GET', '/items');
        self::assertFalse($this->client()->getResponse()->headers->has('Retry-After'));

        $this->send('GET', '/items?nope=1');
        self::assertFalse($this->client()->getResponse()->headers->has('Retry-After'));
    }

    private function sendRaw(string $method, string $path, string $content, string $contentType): void
    {
        $this->client()->request(
            $method,
            self::BASE.$path,
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => $contentType, 'HTTP_IDEMPOTENCY_KEY' => 'k'],
            $content,
        );
    }
}

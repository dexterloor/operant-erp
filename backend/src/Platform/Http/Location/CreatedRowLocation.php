<?php

declare(strict_types=1);

namespace App\Platform\Http\Location;

use App\Platform\Security\CurrentOperation;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * **API-21 branch 4 — *"201 with `Location` naming that URI"*, where "that URI"
 * is the row-level URI of the row the `POST` created.**
 *
 * ===========================================================================
 * Why this exists: the vendor's own header names the wrong URI
 * ===========================================================================
 * API Platform writes `Location` in `ApiPlatform\State\Util\HttpResponseHeadersTrait`
 * from `IriConverter::getIriFromResource($data)` with **no operation**, which
 * resolves to the resource's *first item operation*. For a resource whose only
 * row-level operations are a `PATCH` and a `DELETE` — which is what §4.2 declares
 * for `PurchaseOrderLine`, and what §4.4.1's `AssignRole` paragraph says is
 * legitimate — there is no item `GET`, so
 * `NotExposedOperationResourceMetadataCollectionFactory` mints one at the
 * pluralised default path and `getOperation(null, false, true)` returns **that**.
 *
 * Measured, not assumed: before this listener, `POST /api/v1/purchase-orders/{id}/lines`
 * answered `201` with `Location: /api/v1/purchase_order_lines/<uuid>` — a path
 * that answers `404` by construction (`NotFoundAction`) and is not the URI §4.2
 * declares the row at. §4.4.1's own *fails if* names exactly this: *"or its
 * `Location` is not the URI the branch tested for"*.
 *
 * ===========================================================================
 * The mechanism: a declaration on the operation, not a rule about a path
 * ===========================================================================
 * The operation carries the row-level URI template as an extra property, exactly
 * as it carries §4.2's `Perm` cell (`PermissionGate::OPERATION_PERMISSION`) and
 * its `Idem` cell (`IdempotencyKeyGuard::OPERATION_FLAG`). This class then fills
 * that template from two sources and **nothing else**:
 *
 *   - every variable the **request's own route** already bound — the parent ids
 *     in the path the client posted to;
 *   - the **one remaining** variable, from the `id` of the resource in the
 *     response body, which branch 4 requires the response to carry.
 *
 * *"Exactly one remaining"* is the check rather than a convention: a template
 * with two unbound variables, or none, raises rather than writing a header
 * somebody would have to verify by eye. And the id is read from the body rather
 * than passed out of the processor, so the header cannot name a row the response
 * does not describe.
 *
 * ===========================================================================
 * Why it is general and lives in `Platform`
 * ===========================================================================
 * API-21 branch 4 is a rule over §4.2's whole table, not over one operation.
 * `CreateGoodsReceipt`, `CreateFulfilment`, `CreateSalesOrderLine`, `AssignRole`
 * and `IssueInvoice` are all branch-4 rows and every one of them will need this;
 * `CreateItem` does not, because `GetItem` exists and the vendor's IRI is already
 * right. An operation that declares nothing is left completely alone, so this
 * listener changes nothing about any shipped response.
 *
 * `Content-Location` is corrected in the same pass. The vendor derives it from
 * the same wrong IRI, and a representation-location header pointing at a route
 * that 404s is the same defect one field over.
 */
#[AsEventListener(event: ResponseEvent::class, priority: self::PRIORITY)]
final readonly class CreatedRowLocation
{
    /**
     * Behind API Platform's own response listeners, which write the header this
     * one replaces. Negative rather than a large positive: `kernel.response`
     * runs highest-first, so the last word belongs to the lowest priority.
     */
    public const PRIORITY = -64;

    /**
     * The extra property an operation carries to declare the row-level URI of the
     * row it creates. §4.2's `Path` column for the operation that addresses that
     * row, with the `/api/v1` prefix omitted exactly as §4.2 omits it.
     */
    public const OPERATION_URI_TEMPLATE = 'created_row_uri_template';

    public function __construct(
        private CurrentOperation $operations,
        #[Autowire('%app.api_base_path%')]
        private string $basePath,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        $response = $event->getResponse();

        if (Response::HTTP_CREATED !== $response->getStatusCode()) {
            return;
        }

        $operation = $this->operations->of($event->getRequest());

        if (null === $operation) {
            return;
        }

        $template = $operation->getExtraProperties()[self::OPERATION_URI_TEMPLATE] ?? null;

        if (!\is_string($template)) {
            return;
        }

        $location = $this->basePath.$this->fill($template, $event->getRequest()->attributes->get('_route_params'), $response);

        $response->headers->set('Location', $location);
        $response->headers->set('Content-Location', $location);
    }

    /**
     * @param mixed $routeParameters the request's own `_route_params`
     */
    private function fill(string $template, mixed $routeParameters, Response $response): string
    {
        $bound = \is_array($routeParameters) ? $routeParameters : [];

        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $template, $matches);
        $unbound = array_values(array_diff($matches[1], array_keys($bound)));

        if (1 !== \count($unbound)) {
            throw new \LogicException(\sprintf(
                'The row-level URI template "%s" leaves %d variables unbound by the request\'s route and '
                .'API-21 branch 4 names exactly one row: the one this POST created. A template whose parent '
                .'variables are not the ones the caller posted to is a Location nobody could verify.',
                $template,
                \count($unbound),
            ));
        }

        $body = json_decode((string) $response->getContent(), true);
        $id = \is_array($body) ? ($body['id'] ?? null) : null;

        if (!\is_string($id) || '' === $id) {
            throw new \LogicException(\sprintf(
                'A 201 declaring the row-level URI template "%s" returned a body with no "id". API-21 '
                .'branch 4 requires the created resource as the body, and the Location is derived from it '
                .'so the header cannot name a row the response does not describe.',
                $template,
            ));
        }

        $bound[$unbound[0]] = $id;

        return (string) preg_replace_callback(
            '/\{([A-Za-z_][A-Za-z0-9_]*)\}/',
            static fn (array $match): string => rawurlencode((string) $bound[$match[1]]),
            $template,
        );
    }
}

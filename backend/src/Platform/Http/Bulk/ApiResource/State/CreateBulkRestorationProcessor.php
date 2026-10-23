<?php

declare(strict_types=1);

namespace App\Platform\Http\Bulk\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Platform\Http\Bulk\ApiResource\CreateBulkRestorationInput;
use App\Platform\Http\Bulk\BulkArchival;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Security\PermissionRequirements;
use App\Platform\Security\RequestIdentity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `CreateBulkRestoration` (G5-C3, 2026-10-23) — `CreateBulkArchivalProcessor`'s shape exactly: acts ONLY on the resource the
 * gate resolved (G5-D0 carry (1): a body read two ways is a 500 — `BulkRestorationTest::aBodyReadTwoWaysIsAServerErrorNotARestoration`
 * plants the mismatch), then the same `ids` validation ({@see CreateBulkArchivalProcessor::ids()}, one copy of the five
 * codes), then {@see BulkArchival::restore()}. The answer is the stored envelope's bytes, first call and replay alike.
 *
 * @implements ProcessorInterface<CreateBulkRestorationInput, Response>
 */
final readonly class CreateBulkRestorationProcessor implements ProcessorInterface
{
    public function __construct(
        private BulkArchival $bulk,
        private RequestIdentity $identities,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        \assert($data instanceof CreateBulkRestorationInput);
        $request = $context['request'] ?? null;
        if (!$request instanceof Request) {
            throw new \LogicException('CreateBulkRestoration runs only on an HTTP request.');
        }

        // The gate refused a non-string `resource` before the 403, so a non-string here means the body was read two ways.
        $resource = \is_string($data->resource) ? $data->resource : '';
        PermissionRequirements::resolvedFor($request, $resource);

        $ids = CreateBulkArchivalProcessor::ids($data->ids);
        $key = (string) $request->headers->get(IdempotencyKeyGuard::HEADER);

        $replay = $this->bulk->restore($resource, $ids, $key, $this->identities->authenticated()->userId);

        return new Response($replay->body, $replay->status, ['Content-Type' => $replay->headers['content-type'] ?? BulkArchival::CONTENT_TYPE]);
    }
}

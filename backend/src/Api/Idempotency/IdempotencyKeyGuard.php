<?php

declare(strict_types=1);

namespace App\Api\Idempotency;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use App\Api\Problem\ApiProblem;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;

/**
 * API-14 — an operation marked `Idem` in §4.2 requires an `Idempotency-Key`
 * header and refuses the request without one.
 *
 * ---------------------------------------------------------------------------
 * Why a listener over the operation registry rather than a call in each processor
 * ---------------------------------------------------------------------------
 * Because the domain has to be the registry. A guard invoked by hand from every
 * processor is a second list of idempotent operations, kept in step by memory,
 * and the failure it produces is silent: the operation that forgets to call it
 * simply stops requiring the header. Here the flag lives on the operation --
 * where §4.2's column lives -- and `IdempotencyContractTest` reconciles the two.
 *
 * ---------------------------------------------------------------------------
 * What this does NOT do, stated because the gap is the finding
 * ---------------------------------------------------------------------------
 * It does not deduplicate a replay. §6.1: the schema has nowhere to keep a key
 * (API-O-1), so the header is required and then discarded. The operations in
 * this slice are safe on §6.1's interim rule -- a repeat of `CreateItem` hits
 * `inventory_item_tenant_sku_live` and a repeat of `RestoreItem` hits a live row,
 * and both are answered `business-rule-violated`, which tells the caller the
 * first attempt committed. An operation whose repeat writes a second row cannot
 * ship before that table exists.
 */
#[AsEventListener(event: ControllerEvent::class)]
final readonly class IdempotencyKeyGuard
{

    /** §6.2 — the key is a client-generated opaque string, at most 255 bytes. */
    public const MAXIMUM_KEY_BYTES = 255;

    public const HEADER = 'Idempotency-Key';

    /** The extra property an operation carries to declare itself `Idem`. */
    public const OPERATION_FLAG = 'idempotency_key_required';

    public function __construct(private ResourceMetadataCollectionFactoryInterface $operations)
    {
    }

    public function __invoke(ControllerEvent $event): void
    {
        // `_api_operation` is resolved inside API Platform's main controller,
        // which is *after* this event. The route defaults carry the two values
        // needed to look the operation up, and they are set by the same metadata
        // this guard reads -- so the domain is still the registry.
        $attributes = $event->getRequest()->attributes;
        $resourceClass = $attributes->get('_api_resource_class');
        $operationName = $attributes->get('_api_operation_name');

        if (!\is_string($resourceClass) || !\is_string($operationName)) {
            return;
        }

        $operation = $this->operations->create($resourceClass)->getOperation($operationName);

        if (!$operation instanceof HttpOperation
            || true !== ($operation->getExtraProperties()[self::OPERATION_FLAG] ?? null)
        ) {
            return;
        }

        $key = $event->getRequest()->headers->get(self::HEADER);

        if (null === $key || '' === $key) {
            throw ApiProblem::idempotencyKeyRequired();
        }

        if (\strlen($key) > self::MAXIMUM_KEY_BYTES) {
            throw ApiProblem::validationFailed([[
                'pointer' => '#'.self::HEADER,
                'code' => 'key_too_long',
                'message' => \sprintf('%s must be at most %d bytes.', self::HEADER, self::MAXIMUM_KEY_BYTES),
            ]]);
        }
    }
}

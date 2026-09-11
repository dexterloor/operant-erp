<?php

declare(strict_types=1);

namespace App\Platform\Http\Idempotency;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use App\Platform\Http\Problem\ApiProblem;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;

/**
 * API-14 **as amended 2026-09-10** — the `Idempotency-Key` header is required by
 * the operations whose §4.2 `Idem` cell names a mechanism that **needs** it:
 * `store` and `unique:<index>`. Not by every marked operation.
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
 * ⚠ **That last sentence was a lie from the day it was written until 2026-09-11.**
 * `IdempotencyContractTest` did not exist. The docblock asserted a reconciliation
 * nothing performed, which is this project's twentieth recorded fake-green and is
 * the exact shape it keeps finding: a claim about a check, in place of the check.
 * The test now exists, and it is what makes the sentence true -- it holds this
 * flag against §4.2's cell in both directions, so an operation that carries the
 * flag with a `guard:` cell is red, and so is one that omits it with a `unique:`
 * cell.
 *
 * ---------------------------------------------------------------------------
 * Why a `guard:` operation is NOT flagged, which is the amendment's substance
 * ---------------------------------------------------------------------------
 * It refuses its own repeat regardless. `ApprovePurchaseOrder` replayed hits an
 * order that is no longer `draft` and `place()` refuses it; `RestoreItem`
 * replayed hits a live row and `ItemWriter::restore()` refuses that. Both answer
 * `business-rule-violated`, which is how the caller learns the first attempt
 * committed. Demanding a header first, and 428-ing a caller who omitted one, adds
 * a refusal that protects nothing -- so both operations lost the flag on
 * 2026-09-11.
 *
 * ---------------------------------------------------------------------------
 * What this does NOT do, stated because the gap is still the finding
 * ---------------------------------------------------------------------------
 * It does not deduplicate a replay, and **the table existing did not change
 * that.** `idempotency_key` shipped in `Version20260910000200`; this class has no
 * database dependency at all -- it requires the header, bounds it at 255 octets,
 * and discards it. So an operation could carry the flag, pass every gate, and
 * still write a second row on a retried timeout. That is why §4.2 has a fifth
 * cell value, `⛔ none`, and why the nine operations carrying it are blocked by
 * `DELIVERY_LEDGER.md` E9-2 rather than merely noted: the header is not a
 * mechanism, and a column that could not say so is what hid the gap.
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

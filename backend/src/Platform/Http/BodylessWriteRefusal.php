<?php

declare(strict_types=1);

namespace App\Platform\Http;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;

/**
 * API-9 for the write operations that read no body.
 *
 * `allow_extra_attributes: false` is API-9's runtime limb, and it lives in the
 * deserializer -- so it refuses nothing on an operation that never deserializes.
 * `DeleteItem`, `DeletePurchaseOrder`, `DeletePurchaseOrderLine` and
 * `RestoreItem` all have `—` in §4.2's `Accepted request fields` column, which
 * §3.3 reads as *"this operation accepts no request body"*. So every field sent
 * to one is undeclared. Until 2026-09-14 all four ignored it: the three deletes
 * answered 204 and applied the delete (`RequestContractTest`, C5 inventory row 6).
 *
 * ---------------------------------------------------------------------------
 * Why a provider in API Platform's chain, and not a `kernel.controller` listener
 * ---------------------------------------------------------------------------
 * **Order.** A body-reading write refuses an undeclared field inside
 * `DeserializeProvider`, which runs after `ReadProvider` -- so `UpdateItem` at an
 * id no row answers is `not-found` before its body is looked at. Sitting directly
 * outside `DeserializeProvider` (priority 250: inside `validate` at 200, around
 * `deserialize` at 300) gives a body-less write the same order: 401 → 403 → 428
 * on `kernel.controller` (`AuthorizationOrderTest`), then the row load, then the
 * body. A listener would have refused the body ahead of the row load, and
 * `DeleteItem` would have answered 422 where `UpdateItem` answers 404.
 *
 * **Shape.** It raises the serializer's own `ExtraAttributesException`, so the
 * refusal reaches `FrameworkProblemTranslator`'s existing arm and is the same
 * `validation-failed` with one `{pointer, code: undeclared_field, message}` per
 * field that a body-reading write returns. No second problem shape exists to
 * drift from the first.
 *
 * ---------------------------------------------------------------------------
 * What it refuses, and what it does not
 * ---------------------------------------------------------------------------
 * A body that decodes to a JSON object with at least one member. An empty body
 * and `{}` carry no field and pass. A body that is not a JSON object -- an
 * array, a scalar, unparseable bytes -- names no field either and is left alone:
 * API-9's rule is about fields, and the operation reads none of it. Top-level
 * members only, which is what `ExtraAttributesException` reports on the
 * body-reading path too.
 *
 * The subject is decided by `canDeserialize()` **as `MainController` resolves
 * it** -- `false` for `DELETE` and for an operation declaring `deserialize: false`
 * -- rather than by a list of operation names, so the next body-less write is
 * covered on the day it is declared.
 */
#[AsDecorator(decorates: 'api_platform.state_provider.main', priority: self::PRIORITY)]
final readonly class BodylessWriteRefusal implements ProviderInterface
{
    /** Inside `api_platform.state_provider.validate` (200), around `.deserialize` (300). */
    public const PRIORITY = 250;

    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * @param ProviderInterface<object> $inner
     */
    public function __construct(
        #[AutowireDecorated]
        private ProviderInterface $inner,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $data = $this->inner->provide($operation, $uriVariables, $context);

        $request = $context['request'] ?? null;

        if (!$operation instanceof HttpOperation
            || !$request instanceof Request
            || true === $operation->canDeserialize()
            || !\in_array($operation->getMethod(), self::WRITE_METHODS, true)
        ) {
            return $data;
        }

        $body = json_decode((string) $request->getContent());

        if ($body instanceof \stdClass && [] !== ($fields = array_keys(get_object_vars($body)))) {
            throw new ExtraAttributesException(array_map(strval(...), $fields));
        }

        return $data;
    }
}

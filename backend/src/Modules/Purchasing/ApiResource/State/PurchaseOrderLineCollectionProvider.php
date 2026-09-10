<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Purchasing\ApiResource\PurchaseOrderLineCollection;
use App\Modules\Purchasing\Application\PurchaseOrderReader;
use App\Platform\Http\Problem\ApiProblem;
use Symfony\Component\HttpFoundation\Request;

/**
 * `ListPurchaseOrderLines` — one parameter, and the refusal API-19b requires for
 * everything else.
 *
 * The **accepted** parameter set is read off the operation's own declared
 * parameters, never from a list in this class. §4.2's `Query parameters` column
 * is exhaustive, `PurchaseOrderLineCollection` declares exactly that column, and
 * the spec documents the same set — so a parameter accepted here but undocumented,
 * or documented and refused, is not expressible.
 *
 * **The list is `(bounded)`, so `limit`, `after` and `before` are undeclared and
 * therefore refused.** That is not a smaller version of `ListPurchaseOrders`: a
 * client that sends `?limit=10` here gets `validation-failed` naming the
 * parameter, which is API-19's *"refuse rather than ignore"* applied to
 * pagination. Silently accepting and ignoring a `limit` would return a set the
 * client believes is a page.
 *
 * @implements ProviderInterface<PurchaseOrderLineCollection>
 */
final readonly class PurchaseOrderLineCollectionProvider implements ProviderInterface
{
    public function __construct(private PurchaseOrderReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PurchaseOrderLineCollection
    {
        $request = $context['request'] ?? null;
        $query = $request instanceof Request ? $request->query->all() : [];

        QueryParameters::refuseUndeclared($operation, $query);

        return $this->reader->listLines(
            (string) ($uriVariables['id'] ?? ''),
            self::archived($query),
        ) ?? throw ApiProblem::notFound();
    }

    /**
     * `archived` is a boolean in the query string, and the accepted spellings are
     * the two a client can produce from a checkbox — `true`/`false`, `1`/`0` —
     * with anything else refused rather than coerced. `?archived=maybe` returning
     * the live set is the silent-coercion shape API-19 refuses on `sort`.
     *
     * @param array<string, mixed> $query
     */
    private static function archived(array $query): bool
    {
        if (!isset($query['archived'])) {
            return false;
        }

        $raw = $query['archived'];

        return match (true) {
            'true' === $raw, '1' === $raw => true,
            'false' === $raw, '0' === $raw => false,
            default => throw ApiProblem::validationFailed([[
                'pointer' => '?archived',
                'code' => 'not_a_boolean',
                'message' => 'archived must be true or false.',
            ]]),
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Sales\ApiResource\SalesOrderLineCollection;
use App\Modules\Sales\Application\SalesOrderReader;
use App\Platform\Http\Problem\ApiProblem;
use Symfony\Component\HttpFoundation\Request;

/**
 * `ListSalesOrderLines` — one parameter, and the refusal API-19b requires for
 * everything else.
 *
 * The **accepted** parameter set is the operation's own declared parameters, and
 * an undeclared one never reaches this class
 * (`Platform\Http\UndeclaredQueryParameterRefusal`). §4.2's `Query parameters`
 * column is exhaustive, `SalesOrderLineCollection` declares exactly that column,
 * and the spec documents the same set — so a parameter accepted here but
 * undocumented, or documented and refused, is not expressible.
 *
 * **The list is `(bounded)`, so `limit`, `after`, `before` and `sort` are
 * undeclared and therefore refused.** A client that sends `?limit=10` gets
 * `validation-failed` naming the parameter, which is API-19's *"refuse rather
 * than ignore"* applied to pagination: silently accepting and ignoring a `limit`
 * would return a set the client believes is a page.
 *
 * @implements ProviderInterface<SalesOrderLineCollection>
 */
final readonly class SalesOrderLineCollectionProvider implements ProviderInterface
{
    public function __construct(private SalesOrderReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SalesOrderLineCollection
    {
        $request = $context['request'] ?? null;
        $query = $request instanceof Request ? $request->query->all() : [];

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

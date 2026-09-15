<?php

declare(strict_types=1);

namespace App\Platform\Http;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Platform\Http\Problem\ApiProblem;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;

/**
 * API-19b for every operation — *"§4.2's `Query parameters` column is exhaustive
 * per row"*, and *"`—` means this operation takes no query parameter"*.
 *
 * Until 2026-09-15 the refusal lived in the three list providers only
 * (`ItemCollectionProvider`'s own copy and `Purchasing`'s `QueryParameters`), so
 * every other operation — all nineteen built reads, writes and the two
 * credential operations — answered 2xx to a query parameter it does not declare
 * and ignored it (`QueryParameterContractTest::everyDeclaredParameterIsAcceptedAndNoOtherIs`,
 * C5 inventory row 20; fixed as step F2 under standing rule 1). This is now the
 * one place it happens, and the list providers no longer carry a copy.
 *
 * ---------------------------------------------------------------------------
 * The declared set is the operation's registry `parameters`
 * ---------------------------------------------------------------------------
 * The same source `QueryParameterContractTest` compares with §4.2's cell, so there
 * is no second list: an operation declares a `QueryParameter` and that name is
 * accepted, on the day it is declared. Names compare exactly — `?Limit=` is not
 * `?limit=`, and a client that sends it is told so rather than silently served
 * the default. The names are PHP's parsed query keys (`$request->query->all()`),
 * which is what the list providers read: `?a[]=1` is the parameter `a`, `?a=` is
 * the parameter `a` with an empty value, and PHP's own mangling applies (`?a.b=`
 * arrives as `a_b` and is refused under that name).
 *
 * ---------------------------------------------------------------------------
 * Order — where the list providers already refused, measured before moving it
 * ---------------------------------------------------------------------------
 *   401 authentication → 403 permission → 428 idempotency key (`kernel.controller`,
 *   `AuthorizationOrderTest`) → 406 content negotiation (provider, 100) → the
 *   vendor's value validation of **declared** parameters (191) → **this refusal
 *   (400)** → the row load (`read`, 500: a missing id is `not-found`) → the body
 *   (`deserialize`, 300, and `BodylessWriteRefusal`, 250, both after their inner
 *   call) → validation (200).
 *
 * The list providers refused as the first statement of the operation's own
 * provider, which `read` calls — so refusing immediately outside `read`, before
 * calling it, is the identical position for a list: `/purchase-orders/{missing}/lines?x=1`
 * was 422 before 404 and still is, and `/items?limit=0&x=1` was the vendor's
 * `limit` refusal and still is (`docs/session10/progress-F2.md`, byte-compared
 * before and after). For every other operation it puts the query string where it
 * belongs among the request's shape checks: after the gates, before the row and
 * the body, so a request is refused for what its URL carries before anything is
 * read on its behalf. `BodylessWriteRefusal` sits after the row load because its
 * body-reading counterpart does; the query string has no such counterpart, and
 * the lists fix its position.
 *
 * **Unauthenticated and credential operations are not exempt.** `IssueToken` and
 * `ChangePassword` both have `—` in the column. The refusal depends only on the
 * URL the caller wrote — never on an account, a tenant or a password — so it
 * discloses nothing E-3 cares about, and it runs before the processor, so no
 * rate-limit budget is spent, no hasher is invoked and no trail row is written
 * for a request that is refused for its shape (`UndeclaredQueryParameterRefusalTest`).
 *
 * **Shape:** `ApiProblem::validationFailed` with one `{pointer: "?<name>", code:
 * undeclared_parameter, message}` per undeclared name, in the order the query
 * string carries them — exactly what the list providers returned.
 *
 * Only this application's operations are subject: API Platform's own error
 * operations (`_api_*`) re-enter the provider chain while rendering a problem and
 * declare nothing, so refusing there would turn every problem raised on a URL
 * with a query string into a second one.
 */
#[AsDecorator(decorates: 'api_platform.state_provider.main', priority: self::PRIORITY)]
final readonly class UndeclaredQueryParameterRefusal implements ProviderInterface
{
    /** Outside `api_platform.state_provider.read` (500), inside `.deserialize` (300) and `.parameter_validator` (191). */
    public const PRIORITY = 400;

    public const CODE = 'undeclared_parameter';

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
        $request = $context['request'] ?? null;

        if ($request instanceof Request
            && str_starts_with((string) $operation->getClass(), 'App\\')
            && !str_starts_with((string) $operation->getName(), '_api_')
        ) {
            self::refuseUndeclared($operation, $request);
        }

        return $this->inner->provide($operation, $uriVariables, $context);
    }

    private static function refuseUndeclared(Operation $operation, Request $request): void
    {
        $declared = [];
        foreach ($operation->getParameters() ?? [] as $key => $parameter) {
            $declared[] = (string) ($parameter->getKey() ?? $key);
        }

        $undeclared = array_values(array_diff(array_map(strval(...), array_keys($request->query->all())), $declared));

        if ([] === $undeclared) {
            return;
        }

        throw ApiProblem::validationFailed(array_map(
            static fn (string $name): array => [
                // A query parameter is not addressable by a JSON pointer into a
                // body, so it is named with the `?` form the lists have always used.
                'pointer' => '?'.$name,
                'code' => self::CODE,
                'message' => \sprintf('This operation declares no query parameter named "%s".', $name),
            ],
            $undeclared,
        ));
    }
}

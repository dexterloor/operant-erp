<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\RequestIdentity;
use App\Modules\UserManagement\ApiResource\CurrentUserResource;
use App\Modules\UserManagement\Application\CurrentUserReader;

/**
 * `GetCurrentUser`.
 *
 * ---------------------------------------------------------------------------
 * The token is no longer read here — §14.2 item 25's second half, closed
 * ---------------------------------------------------------------------------
 * Until the enforcement slice this provider read its own `Authorization` header,
 * because `/me` was the only operation in the wave that required a token and
 * *"wiring that seam without a permission check would ship half of an enforcement
 * design"*. `App\Platform\Security\AuthenticationGate` now authenticates every
 * operation outside `/auth/*` on `kernel.controller`, before any provider runs,
 * so this one takes the resolved identity and re-reads nothing.
 *
 * That is not tidying. Two readers of one header are two places the answer to
 * *"who is calling"* can be computed, and this one ran **after** the gate had
 * already answered it — so a divergence between them would have been a caller
 * authorised as one principal and described as another.
 *
 * ---------------------------------------------------------------------------
 * Why the reader still runs, given the gate already read the user
 * ---------------------------------------------------------------------------
 * §4.4.3's `CurrentUser` shape carries `roles[]`, which the gate has no use for
 * and does not fetch. `CurrentUserReader` is the whole shape; `IdentityReader` is
 * the enforcement subset. `PermissionEnforcementTest::theSetTheApiEnforcesIsTheSetMeAdvertises`
 * drives one request pair and compares the two, so PC-6's *"the sidebar is a
 * rendering of the enforced set"* is asserted at the boundary rather than assumed
 * from the two queries looking alike.
 *
 * ---------------------------------------------------------------------------
 * Every refusal is still the same refusal
 * ---------------------------------------------------------------------------
 * The gate turns absent header, wrong scheme, malformed token, bad signature,
 * wrong algorithm, expired and an unresolvable `sub` into one
 * `authentication-failed`. The one arm left here is the read returning nothing
 * after the gate resolved the principal — a race with a soft delete — and it
 * answers identically.
 *
 * @implements ProviderInterface<CurrentUserResource>
 */
final readonly class CurrentUserProvider implements ProviderInterface
{
    public function __construct(private RequestIdentity $identities, private CurrentUserReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CurrentUserResource
    {
        $identity = $this->identities->authenticated();

        return $this->reader->read($identity->tenantId, $identity->userId) ?? throw ApiProblem::authenticationFailed();
    }
}

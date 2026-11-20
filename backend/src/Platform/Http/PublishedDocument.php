<?php

declare(strict_types=1);

namespace App\Platform\Http;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\Model\SecurityScheme;
use ApiPlatform\OpenApi\Model\Server;
use ApiPlatform\OpenApi\Model\Tag;
use ApiPlatform\OpenApi\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The published spec says how to sign in, and carries none of the framework's document-level filler (2b, S1).
 *
 * API Platform builds `securitySchemes` and the top-level `security` only from its own `api_keys`/`http_auth`/OAuth
 * config, none of which describes this API (its `http_auth` form would publish "Value for the http bearer parameter."
 * and could not exempt the sign-in operations). So the spec said nothing about authentication at all. This writes:
 *
 * - **one security scheme**, {@see self::SCHEME}: `http`/`bearer`, no `bearerFormat` (the contract treats the token as
 *   opaque; wording and the omission TO CONFIRM by `UX` and `SEC`);
 * - **the top-level requirement** `[{bearer: []}]`, and `security: []` on each operation whose path, after the base
 *   path, starts with {@see self::PRE_AUTHENTICATION_PREFIX} — the operations that answer without a token;
 * - **tag names only**: the vendor gives every tag the description "Resource 'X' operations.", which says nothing; the
 *   names stay, because tests key resources on them;
 * - **the server's description**, which the vendor leaves as an empty string.
 *
 * **Its own copy of the pre-authentication rule, on purpose.** `AuthenticationGate::isPreAuthentication()` decides the
 * same thing and is private; making it public is not yet agreed (`ARCH`, `SEC`), so this holds its own `'/auth/'`. The
 * other copies are the gate itself, `ProblemResponses::AUTH_PREFIX`, `ErrorContractRules`, two in
 * `PermissionEnforcementTest`, one in `ContractArtefactTest` and the web app's `api-surface-lib.mjs`. A drift between
 * this copy and the gate is not left to reading: `PermissionEnforcementTest::everyOperationOutsideAuthRefusesARequestCarryingNoToken`
 * sends every operation without a token and holds the spec's `security` to what the server answered.
 *
 * **No runtime effect.** This decorates the OpenAPI factory only; the docs endpoint and the contract export read it, no
 * provider, processor, listener or route does. It changes the document through `with*` calls only, so what another
 * decorator of the same factory writes (refusals, success texts) survives whichever order they are stacked in.
 */
final readonly class PublishedDocument implements OpenApiFactoryInterface
{
    public const SCHEME = 'bearer';

    /** The scheme's description (wording owner: `UX`; it repeats facts `IssueToken` and the 401 text already publish). */
    public const SCHEME_DESCRIPTION = 'The `access_token` that `IssueToken` returns, sent as `Authorization: Bearer <token>`. It stops working at its `expires_at`, or sooner, for example if the account is deactivated or deleted, or its password is changed or reset.';

    /** The server's description (wording owner: `UX`). Its `url` stays relative. */
    public const SERVER_DESCRIPTION = 'The host serving this document.';

    /** The path prefix, after the base path, of the operations that answer without a token (see the class docblock). */
    private const PRE_AUTHENTICATION_PREFIX = '/auth/';

    private const METHODS = ['Get', 'Post', 'Patch', 'Put', 'Delete'];

    public function __construct(
        private OpenApiFactoryInterface $inner,
        #[Autowire(param: 'app.api_base_path')]
        private string $basePath,
    ) {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->inner)($context);

        $paths = new Paths();
        foreach ($openApi->getPaths()->getPaths() as $path => $pathItem) {
            $paths->addPath($path, $this->isPreAuthentication((string) $path) ? self::withoutSignIn($pathItem) : $pathItem);
        }

        $schemes = new \ArrayObject((array) ($openApi->getComponents()->getSecuritySchemes() ?? new \ArrayObject()));
        $schemes[self::SCHEME] = new SecurityScheme(type: 'http', description: self::SCHEME_DESCRIPTION, scheme: 'bearer');

        $servers = [];
        foreach ($openApi->getServers() as $server) {
            $servers[] = $server instanceof Server ? new Server($server->getUrl(), self::SERVER_DESCRIPTION, $server->getVariables()) : $server;
        }

        $tags = [];
        foreach ($openApi->getTags() as $tag) {
            // A new Tag with no description: `withDescription()` takes no null.
            $tags[] = $tag instanceof Tag ? new Tag($tag->getName()) : $tag;
        }

        return $openApi
            ->withPaths($paths)
            ->withComponents($openApi->getComponents()->withSecuritySchemes($schemes))
            ->withSecurity([[self::SCHEME => []]])
            ->withServers($servers)
            ->withTags($tags);
    }

    private function isPreAuthentication(string $path): bool
    {
        if (str_starts_with($path, $this->basePath)) {
            $path = substr($path, \strlen($this->basePath));
        }

        return str_starts_with($path, self::PRE_AUTHENTICATION_PREFIX);
    }

    /** Every operation of the path answers without a token: OpenAPI's empty requirement overrides the top-level one. */
    private static function withoutSignIn(PathItem $pathItem): PathItem
    {
        foreach (self::METHODS as $method) {
            $operation = $pathItem->{'get'.$method}();
            if ($operation instanceof OpenApiOperation) {
                $pathItem = $pathItem->{'with'.$method}($operation->withSecurity([]));
            }
        }

        return $pathItem;
    }
}

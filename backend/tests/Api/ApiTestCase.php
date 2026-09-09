<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Api\Security\Identity;
use App\Api\Security\PermissionGate;
use App\Api\Security\RequestIdentity;
use App\Auth\Infrastructure\AccessTokens;
use App\Tests\Support\SqlRecorder;
use App\Tests\Tenancy\TenancyTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Uid\Uuid;

/**
 * The HTTP rig for the `/api/v1` slice.
 *
 * It extends the tenancy rig rather than `WebTestCase` on purpose: the truncate,
 * the tenant-registry seed and the leaked-GUC reset are all still required, and
 * a second copy of them would be a second thing to keep in step. The browser is
 * taken from the container instead of `WebTestCase::createClient()`, and reboot
 * is disabled -- a reboot would build a new container and discard the identity
 * the test just established.
 *
 * ---------------------------------------------------------------------------
 * The rig authenticates for real, and that is the change the enforcement slice
 * made to it
 * ---------------------------------------------------------------------------
 * `actAs()` used to set a tenant id on a test-only `FixedTenant`. That service is
 * gone: it was a **second source of tenant** that no request could contradict, so
 * a resource reading the wrong claim -- or a gate resolving a different principal
 * from the one the operation then acted as -- would still have been green. The
 * rig now seeds a principal, mints a real token with the application's own
 * `AccessTokens`, and sends it; the tenant every reader and writer sees is the one
 * `AuthenticationGate` took out of that token.
 *
 * The token is minted directly rather than by calling `POST /auth/token`. Going
 * through the endpoint would consume E-21's `(ip_prefix)` budget -- 300 attempts
 * per fixed 15-minute window -- once per test in a suite with far more than 300
 * HTTP tests, so the rig would start failing on rate limits that have nothing to
 * do with what any of them assert.
 *
 * ---------------------------------------------------------------------------
 * The default principal holds every permission the registry declares
 * ---------------------------------------------------------------------------
 * Derived from the operation registry, not listed: a test about pagination or
 * problem shapes is not a test about permissions, and it should not have to be
 * edited when a code changes. A test that *is* about permissions calls
 * `actAs(..., $codes)` with the set it wants -- `actAs(self::TENANT_A, [])` is the
 * caller who holds nothing, which is E-1's subject.
 */
abstract class ApiTestCase extends TenancyTestCase
{
    protected const BASE = '/api/v1';

    private ?KernelBrowser $client = null;

    private ?string $bearer = null;

    /** The principal `actAs()` last established, so a test can name its own actor in an assertion. */
    protected ?string $actorId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $client = self::getContainer()->get('test.client');
        \assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $this->client = $client;

        $this->actAs(self::TENANT_A);
    }

    /**
     * T-1 / API-7: the tenant is never carried by the request as a tenant -- it
     * arrives inside a signed token, which is the only channel §4.2 offers.
     *
     * @param list<string>|null $permissionCodes null means every code the operation
     *                                           registry declares; `[]` means a caller who holds nothing
     */
    protected function actAs(string $tenantId, ?array $permissionCodes = null): void
    {
        $this->actorId = $this->seedPrincipal($tenantId, $permissionCodes ?? self::everyDeclaredPermission());
        $this->bearer = $this->tokenFor($tenantId, $this->actorId);

        // The non-HTTP half. `TransactionBoundaryTest` and friends drive
        // `ItemReader` and `ItemWriter` directly, and those resolve their tenant
        // through the same seam a request does. An HTTP request overwrites this
        // from its own token, so the two cannot silently disagree.
        $this->identities()->set(new Identity($tenantId, $this->actorId, 'Fixture Actor', $permissionCodes ?? self::everyDeclaredPermission()));
    }

    /**
     * Send nothing in `Authorization` unless a test writes the header itself.
     *
     * `AuthTestCase` calls this: §7's directory is about the pre-authentication
     * path and about `/me`'s own refusals, and both need requests that carry
     * exactly the header the test chose and no default.
     */
    protected function actAsNobody(): void
    {
        $this->bearer = null;
        $this->actorId = null;
        $this->identities()->clear();
    }

    protected function recorder(): SqlRecorder
    {
        $recorder = self::getContainer()->get(SqlRecorder::class);
        \assert($recorder instanceof SqlRecorder);

        return $recorder;
    }

    protected function identities(): RequestIdentity
    {
        $identities = self::getContainer()->get(RequestIdentity::class);
        \assert($identities instanceof RequestIdentity);

        return $identities;
    }

    protected function client(): KernelBrowser
    {
        return $this->client ?? throw new \LogicException('The client is built in setUp().');
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers
     */
    protected function send(string $method, string $path, ?array $body = null, array $headers = [], string $accept = 'application/json'): void
    {
        $server = ['HTTP_ACCEPT' => $accept];

        // The rig's token, unless the test wrote its own header. Never both: a
        // test driving `Authorization` is testing the gate and must get exactly
        // the value it chose.
        if (null !== $this->bearer && !\array_key_exists('Authorization', $headers)) {
            $headers['Authorization'] = 'Bearer '.$this->bearer;
        }

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        if (null !== $body) {
            $server['CONTENT_TYPE'] = 'application/json';
        }

        $this->client()->request(
            $method,
            // A `Location` header comes back already carrying the base path, and
            // following it is half of what makes API-21 branch 4 checkable.
            str_starts_with($path, self::BASE) ? $path : self::BASE.$path,
            [],
            [],
            $server,
            null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    protected function httpStatus(): int
    {
        return $this->client()->getResponse()->getStatusCode();
    }

    protected function contentType(): string
    {
        return (string) $this->client()->getResponse()->headers->get('Content-Type');
    }

    /** @return array<string, mixed> */
    protected function json(): array
    {
        $content = (string) $this->client()->getResponse()->getContent();

        $decoded = json_decode($content, true);
        self::assertIsArray($decoded, 'The response body is not a JSON object: '.substr($content, 0, 400));

        return $decoded;
    }

    /**
     * Every permission code the **registry** declares, in §4.2's `Perm` column.
     *
     * Derived, not listed. An operation whose code changes, or a new operation
     * with a code nobody has granted, changes what the default principal holds
     * without anybody editing this file -- which is the only way a rig-wide
     * "holds everything" can stay true.
     *
     * @return list<string>
     */
    protected static function everyDeclaredPermission(): array
    {
        $codes = [];

        foreach (TransactionBoundaryTest::contractOperations() as $operation) {
            $code = $operation->getExtraProperties()[PermissionGate::OPERATION_PERMISSION] ?? null;

            if (\is_string($code)) {
                $codes[] = $code;
            }
        }

        $codes = array_values(array_unique($codes));
        sort($codes);

        return $codes;
    }

    /**
     * @param list<string> $permissionCodes
     *
     * @return string the principal's id
     */
    protected function seedPrincipal(string $tenantId, array $permissionCodes): string
    {
        $userId = Uuid::v4()->toRfc4122();

        // `password_hash` is NOT NULL and this principal never signs in -- the
        // token is minted directly -- so the column carries a value that matches
        // no password by construction rather than a hash of a fixture secret.
        $this->insertAsOwnerForTenant($tenantId, <<<'SQL'
            INSERT INTO app_user (id, tenant_id, email, password_hash, display_name)
            VALUES (?::uuid, ?::uuid, ?, 'not-a-hash', 'Fixture Actor')
            SQL, [$userId, $tenantId, 'actor-'.$userId.'@example.test']);

        if ([] === $permissionCodes) {
            return $userId;
        }

        $roleId = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant($tenantId,
            'INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, ?)',
            [$roleId, $tenantId, 'Fixture Role '.substr($roleId, 0, 8)]);
        $this->insertAsOwnerForTenant($tenantId,
            'INSERT INTO user_role (id, tenant_id, user_id, role_id) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid)',
            [Uuid::v4()->toRfc4122(), $tenantId, $userId, $roleId]);

        foreach ($permissionCodes as $code) {
            // `permission` is a non-tenant reference table the migrations leave
            // empty and `role_permission` carries a foreign key onto it, so
            // seeding the code is part of building the fixture.
            $this->owner()->executeStatement(
                "INSERT INTO permission (code, module, description) VALUES (?, 'Fixture', ?) ON CONFLICT (code) DO NOTHING",
                [$code, $code]);
            $this->insertAsOwnerForTenant($tenantId,
                'INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (?::uuid, ?::uuid, ?::uuid, ?)',
                [Uuid::v4()->toRfc4122(), $tenantId, $roleId, $code]);
        }

        return $userId;
    }

    /** A real token from the application's own issuer, so the gate reads what production would read. */
    protected function tokenFor(string $tenantId, string $userId): string
    {
        $tokens = self::getContainer()->get('test.auth.access_tokens');
        \assert($tokens instanceof AccessTokens);

        return $tokens->issue($tenantId, $userId, new \DateTimeImmutable())->jwt;
    }
}

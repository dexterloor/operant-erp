<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Infrastructure\AccessTokenIssuer;
use App\Platform\Security\Identity;
use App\Platform\Security\PermissionRequirement;
use App\Platform\Security\PermissionRequirements;
use App\Platform\Security\RequestIdentity;
use App\Tests\Support\OwnerRequestWindow;
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
 * `AccessTokenIssuer`, and sends it; the tenant every reader and writer sees is the one
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
     * V3-1(i) item (5): no HTTP test may see the schema owner's connection issue a statement while a request is served.
     * Coverage and limits: `App\Tests\Support\OwnerRequestWindow`; positive control: `OwnerRequestWindowTest`.
     */
    protected function tearDown(): void
    {
        $window = self::getContainer()->get(OwnerRequestWindow::class);
        \assert($window instanceof OwnerRequestWindow);
        $inside = $window->takeStatementsInsideRequests();

        parent::tearDown();

        self::assertSame([], $inside, 'The schema owner\'s connection issued statements while a request was served (V3-1(i)); a request path reaches the owner.');
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
     * Act as a principal a test already seeded (roles slice 2: an owner revoking another owner must be a NAMED user, not a
     * fresh one). A real token from the application's issuer, exactly as `actAs()` mints; `$permissionCodes` is only the
     * non-HTTP half (`RequestIdentity` for directly-driven services) — a request re-reads the codes from the database.
     *
     * @param list<string> $permissionCodes
     */
    protected function actAsUser(string $tenantId, string $userId, array $permissionCodes = []): void
    {
        $this->actorId = $userId;
        $this->bearer = $this->tokenFor($tenantId, $userId);
        $this->identities()->set(new Identity($tenantId, $userId, 'Fixture Actor', $permissionCodes));
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

    /**
     * **Every `audit_log` row of one event type in a tenant, cell by cell** — the read-back `SEC` requires of a
     * business writer (`docs/session13/roles-s2-sec.md` §3, `output9-sales-7-sec.md` §5.1): the exact `metadata` object,
     * no IP, no permission code. Added with B-4a (roles slice 2, C1) because five operations now owe the same read.
     *
     * @return list<array{event_type: string, actor_user_id: ?string, actor_label: string, subject_type: ?string, subject_id: ?string, ip_address: ?string, permission_code: ?string, metadata: string}>
     */
    protected function auditRowsOf(string $eventType, string $tenantId = self::TENANT_A): array
    {
        /** @var list<array{event_type: string, actor_user_id: ?string, actor_label: string, subject_type: ?string, subject_id: ?string, ip_address: ?string, permission_code: ?string, metadata: string}> */
        return $this->fetchAsOwnerForTenant($tenantId, <<<'SQL'
            SELECT event_type, actor_user_id::text AS actor_user_id, actor_label, subject_type,
                   subject_id::text AS subject_id, ip_address::text AS ip_address, permission_code, metadata::text AS metadata
              FROM audit_log WHERE event_type = ? ORDER BY occurred_at, id
            SQL, [$eventType]);
    }

    /**
     * The one row a B-4a business event must be: this actor, the fixture label, the subject pair, no IP, no
     * permission code, `metadata` exactly `{}`.
     *
     * @return array{event_type: string, actor_user_id: ?string, actor_label: string, subject_type: string, subject_id: string, ip_address: null, permission_code: null, metadata: string}
     */
    protected function businessRow(string $eventType, string $subjectType, string $subjectId, ?string $actorUserId = null): array
    {
        return [
            'event_type' => $eventType,
            'actor_user_id' => $actorUserId ?? $this->actorId,
            'actor_label' => 'Fixture Actor',
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'ip_address' => null,
            'permission_code' => null,
            'metadata' => '{}',
        ];
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
        // P-0: through the container-aware seam, so a derived operation (G5-D0) is answered by its resolver, or throws --
        // never silently dropped from "holds everything" the way `\is_string()` dropped it (F-H1 point 3).
        $requirements = static::getContainer()->get(PermissionRequirements::class);
        \assert($requirements instanceof PermissionRequirements);

        $codes = [];

        foreach (TransactionBoundaryTest::contractOperations() as $operation) {
            foreach ($requirements->possibleCodes(PermissionRequirement::of($operation)) as $code) {
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
            // `permission` is a non-tenant reference table and `role_permission`
            // carries a foreign key onto it, so the code has to exist before the
            // grant does. **`Version20260918000100` now seeds all 60**, so for
            // every code the API declares this statement is a no-op and the
            // `'Fixture'` module never reaches a row — the `DO NOTHING` is what
            // makes that true, and removing it would overwrite the catalogue with
            // a placeholder. It is kept rather than deleted so that a fixture
            // reaching for a code the catalogue does not have still builds; if one
            // ever does, `PermissionCatalogueSeedTest` fails naming the code,
            // which is the correct outcome.
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
        $tokens = self::getContainer()->get('test.auth.token_issuer');
        \assert($tokens instanceof AccessTokenIssuer);

        return $tokens->issue($tenantId, $userId, new \DateTimeImmutable())->jwt;
    }
}

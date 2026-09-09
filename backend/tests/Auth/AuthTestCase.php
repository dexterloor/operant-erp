<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Auth\Infrastructure\EmailHasher;
use App\Tests\Api\ApiTestCase;
use App\Tests\Support\CountingPasswordVerifier;
use App\Tests\Support\SqlRecorder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * The rig for `API.md` §7.
 *
 * It extends the HTTP rig rather than the tenancy one because every assertion
 * here is about a response *or* about the statements a request issued, and both
 * need a real request. What it adds is the three things the pre-authentication
 * path needs and no earlier slice did: counter tables that are **not**
 * tenant-scoped and therefore not covered by the base class's truncate, tenants
 * whose `status` is the variable under test, and `app_user` rows carrying a real
 * hash at the configured cost.
 *
 * Fixtures are hashed through the **application's own** verifier rather than
 * through `password_hash()` here. A fixture that hashed at a different cost
 * would make every timing claim in this directory a claim about the fixture.
 */
abstract class AuthTestCase extends ApiTestCase
{
    /** Every tenant this directory creates carries this prefix, and setUp removes them. */
    protected const SLUG_PREFIX = 'auth-';

    protected function setUp(): void
    {
        parent::setUp();

        // Not tenant-scoped, so the base class's manifest-derived truncate does
        // not reach them. As the owner, because no application role holds
        // TRUNCATE on any of the three -- which is the property
        // GrantFootprintTest asserts and this line depends on.
        $this->owner()->executeStatement('TRUNCATE auth_attempt, auth_ip_budget, auth_lockout');

        // parent::setUp() has already emptied every tenant-scoped table, so
        // nothing references these rows by the time they go.
        $this->owner()->executeStatement('DELETE FROM tenant WHERE slug LIKE ?', [self::SLUG_PREFIX.'%']);

        $this->hasher()->reset();
    }

    /** @return string the new tenant's id */
    protected function seedTenant(string $slug, string $status): string
    {
        $id = Uuid::v4()->toRfc4122();

        $this->owner()->executeStatement(
            'INSERT INTO tenant (id, slug, name, status) VALUES (?::uuid, ?, ?, ?)',
            [$id, $slug, 'Fixture '.$slug, $status],
        );

        return $id;
    }

    /** @return string the new user's id */
    protected function seedUser(string $tenantId, string $email, string $password, bool $isActive = true): string
    {
        $id = Uuid::v4()->toRfc4122();

        $this->insertAsOwnerForTenant($tenantId, <<<'SQL'
            INSERT INTO app_user (id, tenant_id, email, password_hash, display_name, is_active)
            VALUES (?::uuid, ?::uuid, ?, ?, ?, ?)
            SQL, [$id, $tenantId, mb_strtolower($email), $this->hasher()->hash($password), 'Fixture User', $isActive ? 'true' : 'false']);

        return $id;
    }

    /**
     * @param array<string, string> $headers
     */
    protected function signIn(string $workspace, string $email, string $password, string $ip = '203.0.113.7', array $headers = []): void
    {
        $this->client()->setServerParameter('REMOTE_ADDR', $ip);
        $this->send('POST', '/auth/token', [
            'workspace' => $workspace,
            'email' => $email,
            'password' => $password,
        ], $headers);
    }

    protected function hasher(): CountingPasswordVerifier
    {
        $hasher = self::getContainer()->get(CountingPasswordVerifier::class);
        \assert($hasher instanceof CountingPasswordVerifier);

        return $hasher;
    }

    /** The statements one named connection issued, for `API.md` §7.2's per-connection assertion. */
    protected function recorderFor(string $connection): SqlRecorder
    {
        $recorder = self::getContainer()->get('test.sql_recorder.'.$connection);
        \assert($recorder instanceof SqlRecorder);

        return $recorder;
    }

    protected function rateLimit(): Connection
    {
        return self::getContainer()->get('test.doctrine.dbal.ratelimit_connection');
    }

    /** @return list<array<string, mixed>> the trail, read as the owner because `erp_authlog` cannot read it back */
    protected function trailRows(string $tenantId): array
    {
        return $this->fetchAsOwnerForTenant($tenantId,
            'SELECT event_type, actor_user_id, actor_label, subject_type, subject_id, ip_address FROM audit_log ORDER BY occurred_at, event_type');
    }

    /** @return array{failure_count: int, locked: bool}|null */
    protected function lockoutFor(string $slug, string $email): ?array
    {
        $hasher = self::getContainer()->get('test.auth.email_hasher');
        \assert($hasher instanceof EmailHasher);

        $row = $this->owner()->fetchAssociative(
            'SELECT failure_count, locked_until FROM auth_lockout WHERE slug_normalised = ? AND email_hash = ?',
            [mb_strtolower($slug), $hasher->hash(mb_strtolower($email))],
            [ParameterType::STRING, ParameterType::BINARY],
        );

        return \is_array($row)
            ? ['failure_count' => (int) $row['failure_count'], 'locked' => null !== $row['locked_until']]
            : null;
    }
}

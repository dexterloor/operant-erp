<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Modules\UserManagement\Domain\SealsCredentials;
use App\Platform\Tenancy\Entity\Tenant;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\Test;

/**
 * **Cred-C1 — a credential written by SQL as `erp_app` no longer signs in** (`RESIDUAL_GAPS.md` G-12's closing work
 * `(e)-cred`; design §3.4 T-C2, T-C3, T-C11; final RED M-1, L-8; Cred-C1 RED D-4).
 *
 * Every tamper below is written **as `erp_app`**, the attacker G-12 names: it holds `UPDATE (password_hash)` because
 * `ChangePassword` needs it, and `UPDATE (password_mac)` for the same caller. What it does not hold is the key. Each case
 * answers the ordinary refusal (`TokenEndpointIndistinguishabilityTest` holds it byte-identical) and writes one
 * `auth.login.credential_mismatch` row — the one signal G-12 had no way to see — through the same statements as a wrong
 * password.
 */
final class CredentialTransplantTest extends AuthTestCase
{
    private const SLUG = self::SLUG_PREFIX.'transplant';

    private const VICTIM = 'owner@example.test';

    private const ATTACKER = 'mallory@example.test';

    private const VICTIM_PASSWORD = 'the-victims-own-password';

    private const ATTACKER_PASSWORD = 'a-password-mallory-knows';

    private string $tenantId;

    private string $victimId;

    private string $attackerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = $this->seedTenant(self::SLUG, Tenant::STATUS_ACTIVE);
        $this->victimId = $this->seedUser($this->tenantId, self::VICTIM, self::VICTIM_PASSWORD);
        $this->attackerId = $this->seedUser($this->tenantId, self::ATTACKER, self::ATTACKER_PASSWORD);
    }

    /**
     * **T-C2.** Mallory copies her own hash onto the owner's row and signs in as the owner with her own password. Before
     * Cred-C1 that was a 200 (G-12's transplant variant). The copy-hash variant — her hash **and** her seal — fails too,
     * because the seal binds the row's id.
     *
     * *Mutation:* drop the seal from `SignIn`'s verdict (`classify()` ignores `$seal`) → 200 → red.
     */
    #[Test]
    public function aTransplantedHashIsRefusedAtSignIn(): void
    {
        // Controls: both fixtures sign in as themselves, and Mallory's password is a plain wrong password on the owner.
        $this->signIn(self::SLUG, self::ATTACKER, self::ATTACKER_PASSWORD);
        self::assertSame(200, $this->httpStatus(), 'The fixture seal does not verify, so nothing below can mean anything.');
        $this->signIn(self::SLUG, self::VICTIM, self::ATTACKER_PASSWORD);
        self::assertSame(401, $this->httpStatus());
        self::assertSame([[AuditEvent::AUTH_LOGIN_SUCCESS, $this->attackerId, '{}'], [AuditEvent::AUTH_LOGIN_FAILURE, $this->victimId, '{}']], $this->trail());

        $this->asApp('UPDATE app_user SET password_hash = (SELECT password_hash FROM app_user WHERE id = ?::uuid) WHERE id = ?::uuid', [$this->attackerId, $this->victimId]);
        $this->signIn(self::SLUG, self::VICTIM, self::ATTACKER_PASSWORD);
        self::assertSame(401, $this->httpStatus(), 'A transplanted password hash signed in: G-12 is open.');

        $this->asApp(<<<'SQL'
            UPDATE app_user SET password_mac = (SELECT password_mac FROM app_user WHERE id = ?::uuid) WHERE id = ?::uuid
            SQL, [$this->attackerId, $this->victimId]);
        $this->signIn(self::SLUG, self::VICTIM, self::ATTACKER_PASSWORD);
        self::assertSame(401, $this->httpStatus(), 'A copied hash with its copied seal signed in: the seal does not bind the row.');

        self::assertSame([
            [AuditEvent::AUTH_LOGIN_CREDENTIAL_MISMATCH, $this->victimId, '{}'],
            [AuditEvent::AUTH_LOGIN_CREDENTIAL_MISMATCH, $this->victimId, '{}'],
        ], \array_slice($this->trail(), 2), 'Each tampered sign-in writes exactly one credential_mismatch row naming the owner.');
    }

    /**
     * **T-C3 (with final RED L-8's plants).** The owner's own password, after `erp_app` NULLs the seal, then writes a
     * well-shaped seal under the current key that is not this row's: refused both times. Restoring the true seal signs in
     * again (the control). `'v1.' . 64×'0'` is no longer a plant: the CHECK refuses it.
     *
     * *Mutation:* make `CredentialSeal::check()` answer `Verified` for NULL → the first refusal is a 200 → red.
     */
    #[Test]
    public function aNullOrMalformedMacIsRefused(): void
    {
        $true = $this->macOf($this->victimId);
        $keyId = substr($true, 3, 8);

        $this->asApp('UPDATE app_user SET password_mac = NULL WHERE id = ?::uuid', [$this->victimId]);
        $this->signIn(self::SLUG, self::VICTIM, self::VICTIM_PASSWORD);
        self::assertSame(401, $this->httpStatus(), 'A NULL seal was skipped rather than refused.');

        $this->asApp('UPDATE app_user SET password_mac = ? WHERE id = ?::uuid', ['v1.'.$keyId.'.'.str_repeat('0', 64), $this->victimId]);
        $this->signIn(self::SLUG, self::VICTIM, self::VICTIM_PASSWORD);
        self::assertSame(401, $this->httpStatus(), 'A forged seal under the current key id signed in.');

        $this->asApp('UPDATE app_user SET password_mac = ? WHERE id = ?::uuid', [$true, $this->victimId]);
        $this->signIn(self::SLUG, self::VICTIM, self::VICTIM_PASSWORD);
        self::assertSame(200, $this->httpStatus(), 'The restored seal did not verify, so the two refusals above prove nothing.');

        self::assertSame([
            [AuditEvent::AUTH_LOGIN_CREDENTIAL_MISMATCH, $this->victimId, '{}'],
            [AuditEvent::AUTH_LOGIN_CREDENTIAL_MISMATCH, $this->victimId, '{}'],
            [AuditEvent::AUTH_LOGIN_SUCCESS, $this->victimId, '{}'],
        ], $this->trail());

        $refused = $this->attemptAsOwnerForTenant($this->tenantId, 'UPDATE app_user SET password_mac = ? WHERE id = ?::uuid', ['v1.'.str_repeat('0', 64), $this->victimId]);
        self::assertSame('23514', $refused, 'app_user_password_mac_shape admitted a seal with no key id.');
    }

    /**
     * **T-C11 (final RED M-1, applied as prescribed).** A well-shaped seal under a key id that is not the configured one,
     * with the owner's **correct** password, is still a tamper signal: `auth.login.credential_mismatch` with
     * `{"unknown_key_id": true}` — never an ordinary failure, or SQL as `erp_app` could switch the alarm off for free.
     * With a **wrong** password it stays `auth.login.failure`.
     *
     * *Mutation:* route an unknown key id to `auth.login.failure` → red.
     */
    #[Test]
    public function anUnknownKeyIdOnACorrectPasswordIsStillATamperSignal(): void
    {
        $true = $this->macOf($this->victimId);
        $foreignKeyId = 'deadbeef' === substr($true, 3, 8) ? 'feedface' : 'deadbeef';
        $this->asApp('UPDATE app_user SET password_mac = ? WHERE id = ?::uuid', ['v1.'.$foreignKeyId.'.'.substr($true, 12), $this->victimId]);

        $this->signIn(self::SLUG, self::VICTIM, self::VICTIM_PASSWORD);
        self::assertSame(401, $this->httpStatus());
        $this->signIn(self::SLUG, self::VICTIM, 'not the password');
        self::assertSame(401, $this->httpStatus());

        self::assertSame([
            [AuditEvent::AUTH_LOGIN_CREDENTIAL_MISMATCH, $this->victimId, '{"'.SealsCredentials::UNKNOWN_KEY_ID.'": true}'],
            [AuditEvent::AUTH_LOGIN_FAILURE, $this->victimId, '{}'],
        ], $this->trail());
    }

    /**
     * **Precedence (Cred-C1 RED D-4 (a)):** the seal is decided after the KDF and **before** the tenant-status and
     * `is_active` questions, so a tampered row in a suspended workspace is recorded as a tampered row. The response is
     * the same refusal either way (§7.6's order changes the trail row, never the answer).
     *
     * *Mutation:* move the seal branch below the status branch → `auth.login.tenant_inactive` → red.
     */
    #[Test]
    public function aTamperedRowInASuspendedWorkspaceIsRecordedAsTampered(): void
    {
        $this->asApp('UPDATE app_user SET password_mac = NULL WHERE id = ?::uuid', [$this->victimId]);
        $this->owner()->executeStatement('UPDATE tenant SET status = ? WHERE id = ?::uuid', [Tenant::STATUS_SUSPENDED, $this->tenantId]);

        $this->signIn(self::SLUG, self::VICTIM, self::VICTIM_PASSWORD);
        self::assertSame(401, $this->httpStatus());
        $this->signIn(self::SLUG, self::ATTACKER, self::ATTACKER_PASSWORD);
        self::assertSame(401, $this->httpStatus());

        self::assertSame([
            [AuditEvent::AUTH_LOGIN_CREDENTIAL_MISMATCH, $this->victimId, '{}'],
            [AuditEvent::AUTH_LOGIN_TENANT_INACTIVE, $this->attackerId, '{}'],
        ], $this->trail(), 'The untampered row is the control: the workspace really is refusing sign-in.');
    }

    /**
     * **E-7 / V-8: the tamper branch costs the statements a wrong password costs, on every connection** (design §2.1 R;
     * T-C5's statement half). The seal is read in step 4's one statement and the row is written through the one step-8
     * INSERT, so nothing may move.
     */
    #[Test]
    public function theTamperBranchIssuesTheStatementsAWrongPasswordIssues(): void
    {
        $wrong = $this->measure(fn () => $this->signIn(self::SLUG, self::VICTIM, 'not the password'));
        $this->asApp('UPDATE app_user SET password_mac = NULL WHERE id = ?::uuid', [$this->victimId]);
        $tampered = $this->measure(fn () => $this->signIn(self::SLUG, self::VICTIM, self::VICTIM_PASSWORD));

        self::assertGreaterThan(0, $wrong['authlog'], 'The wrong password wrote no trail row, so the comparison is vacuous.');
        self::assertSame($wrong, $tampered, 'The tamper branch issued a different statement count on some connection.');
        self::assertSame([AuditEvent::AUTH_LOGIN_FAILURE, AuditEvent::AUTH_LOGIN_CREDENTIAL_MISMATCH], array_column($this->trail(), 0));
    }

    /** One statement as `erp_app` under this workspace — the attacker G-12 names. */
    private function asApp(string $sql, array $params): void
    {
        $app = $this->app();
        $app->beginTransaction();
        try {
            $this->tenantContext()->establishOn($app, $this->tenantId);
            $app->executeStatement($sql, $params);
            $app->commit();
        } catch (\Throwable $e) {
            $app->rollBack();

            throw $e;
        }
    }

    private function macOf(string $userId): string
    {
        return (string) $this->fetchAsOwnerForTenant($this->tenantId, 'SELECT password_mac FROM app_user WHERE id = ?::uuid', [$userId])[0]['password_mac'];
    }

    /** @return list<array{0: string, 1: string, 2: string}> event, actor, metadata — in order */
    private function trail(): array
    {
        return array_map(static fn (array $r): array => [(string) $r['event_type'], (string) $r['actor_user_id'], (string) $r['metadata']],
            $this->fetchAsOwnerForTenant($this->tenantId, 'SELECT event_type, actor_user_id, metadata::text AS metadata FROM audit_log ORDER BY occurred_at, id'));
    }

    /**
     * @param callable(): void $attempt
     *
     * @return array{app: int, auth: int, authlog: int, ratelimit: int}
     */
    private function measure(callable $attempt): array
    {
        foreach (['app', 'auth', 'authlog', 'ratelimit'] as $connection) {
            $this->recorderFor($connection)->start();
        }
        $attempt();
        $counts = [];
        foreach (['app', 'auth', 'authlog', 'ratelimit'] as $connection) {
            $this->recorderFor($connection)->stop();
            $counts[$connection] = \count($this->recorderFor($connection)->statements());
        }

        return $counts;
    }
}

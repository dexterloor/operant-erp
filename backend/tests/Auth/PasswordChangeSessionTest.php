<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Modules\UserManagement\Application\ChangePassword;
use App\Modules\UserManagement\Domain\SealsIdentities;
use App\Modules\UserManagement\Infrastructure\AccessTokenIssuer;
use App\Platform\Tenancy\Entity\Tenant;
use App\SharedKernel\ValueObject\AuditEvent;
use App\Tests\Support\ArmableKdf;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\Uuid;

/**
 * **W1-b: a password change ends every session, the calling one included** (owner ruling P-A, 2026-10-27;
 * `RESIDUAL_GAPS.md` G-84 closed). T-P1…T-P10 of `docs/session13/w1-design.md` Revision 1 FINAL, as corrected by
 * `w1-final-red.md`.
 *
 * **The clock rule for every method here (final RED M-3):** the mock is set FIRST, 10 s **behind** real time (firebase
 * checks `iat` against `time()`), and every token is minted under it (`accessTokenAt`) — never `tokenFor()`, which
 * issues at real time: `ChangePassword` floors the change at the calling token's `iat`, so a real-time token would put
 * the floor ahead of every mock-minted token. The KDF seam (`ArmableKdf`, fires once on `hash()` = step 6) changes state
 * during a request only through a fresh owner connection, with the affected-row count asserted (Mail-C2 final RED M-1).
 * Mutation results: `docs/session13/w1-progress.md`.
 */
final class PasswordChangeSessionTest extends AuthTestCase
{
    use ClockSensitiveTrait;

    private const SLUG = self::SLUG_PREFIX.'change-session';

    private const EMAIL = 'carol@example.test';

    private const OLD = 'the-password-carol-starts-with';

    private const NEW = 'a-new-passphrase-for-carol';

    private const INSERT_LINK = <<<'SQL'
        INSERT INTO password_reset_token (id, tenant_id, user_id, purpose, selector, verifier_mac, message_id, created_at, expires_at)
        VALUES (?::uuid, ?::uuid, ?::uuid, ?, ?, ?, ?::uuid, to_timestamp(?), to_timestamp(?))
        SQL;

    private string $tenant;

    private string $user;

    private ?Connection $freshOwner = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->seedTenant(self::SLUG, Tenant::STATUS_ACTIVE);
        $this->user = $this->seedUser($this->tenant, self::EMAIL, self::OLD);
        $this->executeAsOwnerForTenant($this->tenant, 'UPDATE app_user SET identity_mac = ? WHERE id = ?::uuid',
            [$this->identitySeal()->sealIdentity($this->tenant, $this->user, self::EMAIL), $this->user]);
    }

    protected function tearDown(): void
    {
        $this->kdf()->disarm();
        $this->freshOwner?->close();
        parent::tearDown();
    }

    /**
     * **T-P1:** tokens A (the caller) and B minted at T; the change at T+1 with A → 200; A and B → 401, each byte-equal to
     * an expired token's 401 (the gate's constant answer). MP-1 (no stamp) → A and B read → red.
     */
    #[Test]
    public function aChangeEndsEverySessionIncludingTheOneThatMadeIt(): void
    {
        $clock = $this->mockAt(time() - 10);
        $a = $this->tokenAt($clock->now()->getTimestamp());
        $b = $this->tokenAt($clock->now()->getTimestamp());
        $clock->sleep(1);

        $this->change($a, self::OLD, self::NEW);
        self::assertSame(200, $this->httpStatus(), $this->responseContent());

        $expired = $this->answerTo($this->tokenAt(time() - 9 * 3600));
        self::assertSame(401, $expired['status'], 'Control: an expired token was not refused.');
        self::assertSame($expired, $this->answerTo($a), 'The token that made the change survived it, or its 401 is not the constant one.');
        self::assertSame($expired, $this->answerTo($b), 'Another session survived the change, or its 401 is not the constant one.');
    }

    /**
     * **T-P2:** after a change at T+1, a sign-in with the new password in that same second is refused once; the next
     * second it works; the old password is refused. MP-1 → the same-second token reads → red; MP-5 (the floor from the
     * database clock, ≈ real time, 10 s ahead) → the next-second token is refused → red.
     */
    #[Test]
    public function aSignInInTheChangesOwnSecondIsRefusedOnceAndTheNextSecondWorks(): void
    {
        $clock = $this->mockAt(time() - 10);
        $caller = $this->tokenAt($clock->now()->getTimestamp());
        $clock->sleep(1);

        $this->change($caller, self::OLD, self::NEW);
        self::assertSame(200, $this->httpStatus(), $this->responseContent());

        self::assertSame(401, $this->me($this->signedIn(self::NEW)), 'A sign-in in the change\'s own second was not refused once (the named cost).');
        $clock->sleep(1);
        self::assertSame(200, $this->me($this->signedIn(self::NEW)), 'A sign-in the second after the change is refused.');
        $this->signIn(self::SLUG, self::EMAIL, self::OLD);
        self::assertSame(401, $this->httpStatus(), 'The old password still signs in.');
    }

    /**
     * **T-P3:** a floor raised during the change (the seam writes T+5 on a fresh owner connection) is kept: the change at
     * T+1 leaves the column at T+5 while `changed_at` reads T+1. Asserts the column, not the response. MP-3 (write `$at`
     * with no max) and MP-3b (no refresh — the stale step-2 entity carries NULL, so the ORM writes T+1) → red.
     */
    #[Test]
    public function aFloorRaisedDuringTheChangeIsKept(): void
    {
        $t = time() - 10;
        $clock = $this->mockAt($t);
        $caller = $this->tokenAt($t);
        $clock->sleep(1);
        $this->kdf()->arm(function () use ($t): void {
            self::assertSame(1, $this->asFreshOwner('UPDATE app_user SET sessions_valid_after = to_timestamp(?) WHERE id = ?::uuid', [$t + 5, $this->user]));
        });

        $this->change($caller, self::OLD, self::NEW);
        self::assertSame(200, $this->httpStatus(), $this->responseContent());

        self::assertSame($t + 1, $this->changedAt(), 'Control: the change was not at T+1.');
        self::assertSame($t + 5, $this->floor(), 'The change lowered a session floor raised while it ran.');
    }

    /**
     * **T-P4** (Mail-C2 T-R6b's analogue): the seam moves the mock T → T+2 and mints a token at T+2 — a session started
     * while the new password was being hashed. (i) `changed_at` = T+2 (the floor was read after the KDF); (ii) the seam's
     * token → 401; (iii) a control token at T+3 → 200, so (ii) is the floor and not a broken token. MP-4 (`$at` = the
     * request start) → (i)(ii) red; MP-5 (the database clock) → (iii) red. A frozen mock cannot tell "inside the step-7
     * callback" from "just before it": this proves only "after the KDF".
     */
    #[Test]
    public function aSignInDuringTheChangeDoesNotSurviveIt(): void
    {
        $t = time() - 10;
        $clock = $this->mockAt($t);
        $caller = $this->tokenAt($t);
        $during = null;
        $this->kdf()->arm(function () use ($clock, &$during): void {
            $clock->sleep(2);
            $during = $this->tokenAt($clock->now()->getTimestamp());
        });

        $this->change($caller, self::OLD, self::NEW);
        self::assertSame(200, $this->httpStatus(), $this->responseContent());

        self::assertSame($t + 2, $this->changedAt(), '(i) The floor was not read after the KDF.');
        self::assertIsString($during, 'The KDF seam never fired.');
        self::assertSame(401, $this->me($during), '(ii) A session that started while the new password was being hashed survived the change.');
        self::assertSame(200, $this->me($this->tokenAt($t + 3)), '(iii) A session the second after the change is refused.');
    }

    /**
     * **T-P5:** the calling token was issued at T+5 and the server clock now reads T+1 (it stepped back): the change still
     * ends that token, and the floor is T+5. MP-6 (no `iat` term) → the floor is T+1 and the caller reads → red.
     */
    #[Test]
    public function theCallingTokenDiesEvenWhenTheClockHasSteppedBack(): void
    {
        $t = time() - 10;
        $this->mockAt($t + 1);
        $caller = $this->tokenAt($t + 5);

        $this->change($caller, self::OLD, self::NEW);
        self::assertSame(200, $this->httpStatus(), $this->responseContent());

        self::assertSame(401, $this->me($caller), 'The token that made the change survived a stepped-back clock.');
        self::assertSame($t + 5, $this->floor());
    }

    /**
     * **T-P6:** a refused change ends no session — (i) a wrong current password (422) and (ii) a throttled change (three
     * changes seeded in the trail as the owner → 429): the floor stays NULL, the hash is unchanged, and the calling token
     * still reads. MP-7 (raise the floor at step 1, ahead of the refusals) → red.
     */
    #[Test]
    public function aRefusedChangeEndsNoSession(): void
    {
        $clock = $this->mockAt(time() - 10);
        $caller = $this->tokenAt($clock->now()->getTimestamp());
        $clock->sleep(1);
        $before = $this->credential();

        $this->change($caller, 'not-the-password-at-all', self::NEW);
        self::assertSame(422, $this->httpStatus(), $this->responseContent());
        self::assertNull($this->credential()['sessions_valid_after'], '(i) A refused change moved the session floor.');
        self::assertSame($before, $this->credential(), '(i) A refused change moved the credential.');
        self::assertSame(200, $this->me($caller), '(i) A refused change ended the calling session.');

        for ($i = 0; $i < 3; ++$i) {
            $this->insertAsOwnerForTenant($this->tenant, <<<'SQL'
                INSERT INTO audit_log (id, tenant_id, event_type, actor_user_id, actor_label, metadata, ip_address)
                VALUES (?::uuid, ?::uuid, ?, ?::uuid, 'Fixture User', '{}'::jsonb, '198.51.100.9'::inet)
                SQL, [Uuid::v4()->toRfc4122(), $this->tenant, AuditEvent::AUTH_PASSWORD_CHANGED, $this->user]);
        }
        $this->change($caller, self::OLD, self::NEW);
        self::assertSame(429, $this->httpStatus(), $this->responseContent());
        self::assertNull($this->credential()['sessions_valid_after'], '(ii) A throttled change moved the session floor.');
        self::assertSame($before, $this->credential(), '(ii) A throttled change moved the credential.');
        self::assertSame(200, $this->me($caller), '(ii) A throttled change ended the calling session.');
    }

    /**
     * **T-P7:** a failed audit write (a planted trigger on `auth.password.changed` for this actor) is a 500 and moves
     * neither the floor nor the hash. MP-8 (the floor raised in its own committed transaction BEFORE step 7's — final RED
     * M-2) → the floor is not NULL → red. The old variant, "raised in a second transaction AFTER the audit write", is a
     * review check: the planted failure stops the code before that transaction, so it cannot go red here.
     */
    #[Test]
    public function aFailedAuditWriteLeavesTheFloorUnmoved(): void
    {
        $clock = $this->mockAt(time() - 10);
        $caller = $this->tokenAt($clock->now()->getTimestamp());
        $clock->sleep(1);
        $before = $this->credential();
        $owner = $this->owner();
        $owner->executeStatement('DROP TRIGGER IF EXISTS w1_audit_fails ON audit_log');
        $owner->executeStatement('DROP FUNCTION IF EXISTS w1_audit_fails()');
        $owner->executeStatement("CREATE FUNCTION w1_audit_fails() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'planted' USING ERRCODE = 'P0001'; END \$\$");
        $owner->executeStatement('REVOKE ALL ON FUNCTION w1_audit_fails() FROM PUBLIC');
        $owner->executeStatement(\sprintf("CREATE TRIGGER w1_audit_fails BEFORE INSERT ON audit_log FOR EACH ROW WHEN (NEW.event_type = '%s' AND NEW.actor_user_id = '%s'::uuid) EXECUTE FUNCTION w1_audit_fails()", AuditEvent::AUTH_PASSWORD_CHANGED, $this->user));
        try {
            $this->change($caller, self::OLD, self::NEW);
            self::assertSame(500, $this->httpStatus());
        } finally {
            $owner->executeStatement('DROP TRIGGER IF EXISTS w1_audit_fails ON audit_log');
            $owner->executeStatement('DROP FUNCTION IF EXISTS w1_audit_fails()');
        }
        self::assertSame($before, $this->credential(), 'The floor or the hash moved without its audit row.');
        self::assertNull($this->credential()['sessions_valid_after']);
    }

    /**
     * **T-P8:** a person deleted during the change (the seam soft-deletes and raises the floor to T+5) is not changed: the
     * constant 401, byte-equal to the gate's; the hash unchanged; the floor still T+5; no `auth.password.changed` row.
     * **Before W1-b this answered 200 and rewrote the deleted row** (final RED M-1: the step-7 `find()` hit the identity
     * map, so the old `RuntimeException` guard was unreachable) — a shipped defect, fixed here. MP-9 (drop the DBAL locking
     * read; find + refresh only) → 200, hash changed → red.
     */
    #[Test]
    public function aPersonDeletedDuringTheChangeIsNotChanged(): void
    {
        $this->assertNotChangedWhenTheSeamRuns('deleted_at = now()');
    }

    /**
     * **T-P8b:** the same with the person switched off (`is_active = false`, floor T+5) → 401, hash unchanged, floor T+5.
     * Before W1-b: 200, row rewritten (as T-P8). MP-9 and MP-9b (drop `AND is_active` from the read) → red.
     */
    #[Test]
    public function aPersonSwitchedOffDuringTheChangeIsNotChanged(): void
    {
        $this->assertNotChangedWhenTheSeamRuns('is_active = false');
    }

    /**
     * **T-P9 (owner Q-3, Mail-C2):** a change ends every unused reset link minted at or before it. A link minted at T,
     * a change at T+1: redeeming the link → 401, the link still unused; a control link minted at T+2 → 200. MP-1 → red.
     */
    #[Test]
    public function aChangeEndsEveryResetLinkMintedAtOrBeforeIt(): void
    {
        $t = time() - 10;
        $clock = $this->mockAt($t);
        $caller = $this->tokenAt($t);
        $old = $this->mint($t);
        $clock->sleep(1);

        $this->change($caller, self::OLD, self::NEW);
        self::assertSame(200, $this->httpStatus(), $this->responseContent());

        $this->redeem($old, 'a passphrase set from a link');
        self::assertSame(401, $this->httpStatus(), 'A reset link minted before the change still set the password.');
        self::assertSame(1, $this->unusedLinks(), 'The refused redeem consumed the link.');

        $clock->sleep(1);
        $this->redeem($this->mint($t + 2), 'a passphrase set from a link');
        self::assertSame(200, $this->httpStatus(), 'Control: a link minted after the change is refused. '.$this->responseContent());
    }

    /**
     * **T-P10 (RED L-5):** the credential is replaced while the new password is being hashed (the seam writes another
     * hash by SQL): the change is refused — 422 `current_password_invalid` at `/current_password`, the literal sentence —
     * and the stored hash is the seam's; the floor is unchanged; no audit row. MP-10 (drop the compare) → red.
     */
    #[Test]
    public function aCredentialReplacedDuringTheChangeIsNotOverwritten(): void
    {
        $clock = $this->mockAt(time() - 10);
        $caller = $this->tokenAt($clock->now()->getTimestamp());
        $clock->sleep(1);
        $other = $this->hasher()->hash('a-password-set-behind-the-change');
        $this->kdf()->arm(function () use ($other): void {
            self::assertSame(1, $this->asFreshOwner('UPDATE app_user SET password_hash = ? WHERE id = ?::uuid', [$other, $this->user]));
        });

        $this->change($caller, self::OLD, self::NEW);

        self::assertSame(422, $this->httpStatus(), $this->responseContent());
        $errors = (array) ($this->json()['errors'] ?? []);
        self::assertSame([['pointer' => '/current_password', 'code' => ChangePassword::CURRENT_PASSWORD_INVALID, 'message' => 'The current password is not correct.']],
            array_map(static fn (array $e): array => ['pointer' => $e['pointer'] ?? null, 'code' => $e['code'] ?? null, 'message' => $e['message'] ?? null], $errors));
        self::assertSame($other, $this->credential()['password_hash'], 'The change overwrote a credential replaced while it ran.');
        self::assertNull($this->credential()['sessions_valid_after']);
        self::assertSame([], $this->changeRows(), 'A refused change wrote its audit row.');
    }

    private function assertNotChangedWhenTheSeamRuns(string $set): void
    {
        $t = time() - 10;
        $clock = $this->mockAt($t);
        $caller = $this->tokenAt($t);
        $clock->sleep(1);
        $before = $this->credential();
        $this->kdf()->arm(function () use ($t, $set): void {
            self::assertSame(1, $this->asFreshOwner('UPDATE app_user SET '.$set.', sessions_valid_after = to_timestamp(?) WHERE id = ?::uuid', [$t + 5, $this->user]));
        });

        $expired = $this->answerTo($this->tokenAt(time() - 9 * 3600));
        $this->change($caller, self::OLD, self::NEW);

        self::assertSame($expired, $this->answer(), 'The change was not refused with the gate\'s constant 401 ('.$set.').');
        self::assertSame($before['password_hash'], $this->credential()['password_hash'], 'The credential of a person who stopped being a principal was changed.');
        self::assertSame($t + 5, $this->floor());
        self::assertSame([], $this->changeRows(), 'A refused change wrote its audit row.');
    }

    private function change(string $token, string $current, string $new): void
    {
        $this->client()->setServerParameter('REMOTE_ADDR', '198.51.100.9');
        $this->send('POST', '/me/password', ['current_password' => $current, 'new_password' => $new], ['Authorization' => 'Bearer '.$token]);
    }

    private function changedAt(): int
    {
        return (new \DateTimeImmutable((string) $this->json()['changed_at']))->getTimestamp();
    }

    private function floor(): ?int
    {
        $f = $this->fetchAsOwnerForTenant($this->tenant, 'SELECT extract(epoch FROM sessions_valid_after)::bigint AS f FROM app_user WHERE id = ?::uuid', [$this->user])[0]['f'];

        return null === $f ? null : (int) $f;
    }

    /** @return array<string, mixed> */
    private function credential(): array
    {
        return $this->fetchAsOwnerForTenant($this->tenant, 'SELECT password_hash, password_mac, sessions_valid_after FROM app_user WHERE id = ?::uuid', [$this->user])[0];
    }

    /** @return list<array<string, mixed>> */
    private function changeRows(): array
    {
        return $this->fetchAsOwnerForTenant($this->tenant, 'SELECT id FROM audit_log WHERE event_type = ?', [AuditEvent::AUTH_PASSWORD_CHANGED]);
    }

    private function unusedLinks(): int
    {
        return (int) $this->fetchAsOwnerForTenant($this->tenant, 'SELECT count(*) AS n FROM password_reset_token WHERE used_at IS NULL')[0]['n'];
    }

    private function kdf(): ArmableKdf
    {
        $kdf = self::getContainer()->get(ArmableKdf::class);
        \assert($kdf instanceof ArmableKdf);

        return $kdf;
    }

    /** @param list<mixed> $params @return int affected rows, on a fresh owner client in the tenant's context, committed */
    private function asFreshOwner(string $sql, array $params): int
    {
        return $this->executeOnClientForTenant($this->freshOwner ??= $this->freshOwnerClient(), $this->tenant, $sql, $params);
    }

    private function mockAt(int $second): MockClock
    {
        \assert($second <= time(), 'Mock at or behind real time (firebase checks iat with time()).');
        $clock = self::mockTime(new \DateTimeImmutable('@'.$second));
        \assert($clock instanceof MockClock);

        return $clock;
    }

    private function tokenAt(int $issuedAt): string
    {
        \assert($issuedAt <= time());
        $issuer = self::getContainer()->get('test.auth.token_issuer');
        \assert($issuer instanceof AccessTokenIssuer);

        return $issuer->issue($this->tenant, $this->user, new \DateTimeImmutable('@'.$issuedAt))->jwt;
    }

    private function signedIn(string $password): string
    {
        $this->signIn(self::SLUG, self::EMAIL, $password);
        self::assertSame(200, $this->httpStatus(), $this->responseContent());

        return (string) $this->json()['access_token'];
    }

    private function me(string $token): int
    {
        $this->send('GET', '/me', null, ['Authorization' => 'Bearer '.$token]);

        return $this->httpStatus();
    }

    /** @return array{status: int, body: string, headers: array<string, mixed>} */
    private function answerTo(string $token): array
    {
        $this->me($token);

        return $this->answer();
    }

    /** @return array{status: int, body: string, headers: array<string, mixed>} the response, minus per-request headers */
    private function answer(): array
    {
        $response = $this->client()->getResponse();
        $headers = $response->headers->all();
        unset($headers['date'], $headers['x-debug-token'], $headers['x-debug-token-link']);
        ksort($headers);

        return ['status' => $response->getStatusCode(), 'body' => (string) $response->getContent(), 'headers' => $headers];
    }

    private function identitySeal(): SealsIdentities
    {
        $seal = self::getContainer()->get('test.auth.identity_seal');
        \assert($seal instanceof SealsIdentities);

        return $seal;
    }

    /** A real keyed reset link created at `$createdAt`, as the worker would mint it, and its mailed token. */
    private function mint(int $createdAt): string
    {
        $selector = bin2hex(random_bytes(16));
        $verifier = bin2hex(random_bytes(32));
        $expires = $createdAt + 1800;
        $this->executeAsOwnerForTenant($this->tenant, self::INSERT_LINK, [Uuid::v4()->toRfc4122(), $this->tenant, $this->user, 'reset', $selector,
            $this->identitySeal()->macVerifier($this->tenant, $this->user, 'reset', $selector, $expires, $verifier), Uuid::v4()->toRfc4122(), $createdAt, $expires]);

        return rtrim(strtr(base64_encode(Uuid::fromString($this->tenant)->toBinary().hex2bin($selector).hex2bin($verifier)), '+/', '-_'), '=');
    }

    private function redeem(string $token, string $password): void
    {
        $this->client()->setServerParameter('REMOTE_ADDR', '203.0.113.7');
        $this->send('POST', '/auth/password-resets/redemption', ['token' => $token, 'password' => $password]);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\UserManagement\Infrastructure\AccessTokenIssuer;
use App\Platform\Tenancy\Entity\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * **G4-C1 — `UpdateUser` writes the session stamp, and it kills exactly the tokens it should** (`docs/session13/g4-users-design.md`
 * §4.3, §5.7 T-1-3 / T-1-4 / T-1-17; Revision 1 FINAL R1.2 G4-C1 items 3 and 8; first RED M-1(b)/(c), L-2, L-10).
 *
 * The stamp is written from the injected `Psr\Clock\ClockInterface` — the same `clock` service `SignIn` mints with — so
 * `ClockSensitiveTrait::mockTime()` moves both, with no container edit (design §1.2). **The mocked clock is always at or
 * BEHIND real time** (firebase/php-jwt checks `iat` with PHP's `time()`, so a token minted ahead is refused for the wrong
 * reason), it is frozen and shared, and it is advanced only with `sleep()` (L-10). **No failed sign-in happens here**
 * (`LoginAuditCapTest`'s one-second PHP-vs-DB margin); every sign-in is of an active person with the right password.
 */
final class UserSessionStampTest extends AuthTestCase
{
    use ClockSensitiveTrait;

    private const PASSWORD = 'correct horse battery staple';

    /**
     * **T-1-3 — a reactivation revives no old token, and a sign-in in the re-enable second is refused once.** At mocked
     * second R (10 s behind real time): sign in (T1), switch off (stamp R), advance 2 s, switch on (stamp R+2). T1 stays
     * dead; a sign-in at R+2 mints a token refused on first use; one second later a sign-in reads. Mutations M-1-6 (no stamp
     * on reactivate → the R+2 token reads) and M-1-7 (stamp from the database clock → the R+3 token is refused) → red.
     */
    #[Test]
    public function aReactivationRevivesNoOldToken(): void
    {
        [$slug, $tenant, $subject, $email] = $this->workspace('revive');
        $clock = $this->mockAt(time() - 10);

        $t1 = $this->signedIn($slug, $email);
        self::assertSame(200, $this->me($t1), 'Control: the first token did not read /me.');

        $this->patchAsAdmin($tenant, $subject, ['is_active' => false]);
        self::assertSame(401, $this->me($t1));
        $clock->sleep(2);
        $this->patchAsAdmin($tenant, $subject, ['is_active' => true]);

        self::assertSame(401, $this->me($t1), 'A token minted before the deactivation came back with the reactivation.');
        self::assertSame(401, $this->me($this->signedIn($slug, $email)), 'A sign-in in the re-enable second was not refused once (the named cost).');
        $clock->sleep(1);
        self::assertSame(200, $this->me($this->signedIn($slug, $email)), 'A sign-in the second after the reactivation is refused.');
    }

    /**
     * **T-1-4 — the race token dies at reactivation** (first RED M-1(b)). A token minted the second AFTER the deactivation's
     * stamp (a sign-in that read the person before the deactivation committed) is not stopped by that stamp — only by
     * `is_active`. The reactivation's stamp is what keeps it dead. Control: a token minted after the reactivation reads.
     * Mutation M-1-6 → red.
     */
    #[Test]
    public function theRaceTokenDiesAtReactivation(): void
    {
        [, $tenant, $subject] = $this->workspace('race');
        $r = time() - 10;
        $clock = $this->mockAt($r);

        $this->patchAsAdmin($tenant, $subject, ['is_active' => false]);
        $race = $this->mintAt($tenant, $subject, $r + 1);
        $clock->sleep(5);
        $this->patchAsAdmin($tenant, $subject, ['is_active' => true]);

        self::assertSame(401, $this->me($race), 'The race token (minted after the deactivation stamp) revived with the reactivation.');
        self::assertSame(200, $this->me($this->mintAt($tenant, $subject, $r + 6)), 'Control: a token minted after the reactivation is refused.');
    }

    /**
     * **T-1-17 — the stamp is monotone** (first RED L-2, `SEC`'s ruling): a person switched off by SQL whose stamp is LATER
     * than the request's `$at` (a forward-stepped clock earlier) is switched back on with the stamp unchanged. Mutation M-1-14
     * (last write wins) → the stamp falls to `$at`, red.
     */
    #[Test]
    public function aReactivationNeverLowersTheStamp(): void
    {
        [, $tenant, $subject] = $this->workspace('monotone');
        $later = time() - 5;
        $this->executeAsOwnerForTenant($tenant, 'UPDATE app_user SET is_active = false, sessions_valid_after = to_timestamp(?) WHERE id = ?::uuid', [$later, $subject]);
        $this->mockAt(time() - 20);

        $this->patchAsAdmin($tenant, $subject, ['is_active' => true]);

        self::assertSame((string) $later, $this->fetchAsOwnerForTenant($tenant,
            'SELECT extract(epoch FROM sessions_valid_after)::bigint::text AS s, is_active FROM app_user WHERE id = ?::uuid', [$subject])[0]['s'],
            'The reactivation lowered the session stamp.');
    }

    /**
     * **T-2-2 (G4-C2)** — a deleted person's token is 401 at once, and their password answers the constant sign-in refusal
     * (G4-C0a end to end). Real clock; one failed sign-in, the last request of the test.
     */
    #[Test]
    public function aDeletedPersonsTokenAndPasswordStopWorking(): void
    {
        [$slug, $tenant, $subject, $email] = $this->workspace('deleted');
        $token = $this->signedIn($slug, $email);
        self::assertSame(200, $this->me($token), 'Control: the token did not read /me.');

        $this->actAsUser($tenant, $this->seedPrincipal($tenant, DefaultRoles::codesFor(DefaultRoles::ADMIN)));
        $this->send('DELETE', '/users/'.$subject);
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->actAsNobody();

        self::assertSame(401, $this->me($token), "A deleted person's live token still authenticates.");
        $this->signIn($slug, $email, self::PASSWORD);
        self::assertSame([401, 'urn:erp:problem:authentication-failed'], [$this->httpStatus(), $this->json()['type'] ?? null],
            "A deleted person's password still signs in.");
    }

    /**
     * **T-3-2 (G4-C3)** — a restore revives no earlier token. At mocked second R: sign in (T1), delete (stamp R), a race token
     * minted at R+1 (a sign-in that read the person before the delete committed), advance 5 s, restore (stamp R+5). T1 and the
     * race token stay 401; a token minted after the restore reads. Mutation M-3-1 (no stamp on restore) → the race token reads,
     * red. **M-2-2 (no stamp on delete) cannot go red here**: the restore's own stamp kills T1 as well — a review check (C2's
     * own T-2-1 stamp assertion is what catches it).
     */
    #[Test]
    public function aRestoreRevivesNoEarlierToken(): void
    {
        [$slug, $tenant, $subject, $email] = $this->workspace('restore');
        $r = time() - 10;
        $clock = $this->mockAt($r);

        $t1 = $this->signedIn($slug, $email);
        $this->deleteAsAdmin($tenant, $subject);
        $race = $this->mintAt($tenant, $subject, $r + 1);
        $clock->sleep(5);
        $this->restoreAsAdmin($tenant, $subject);

        self::assertSame(401, $this->me($t1), 'A token minted before the delete came back with the restore.');
        self::assertSame(401, $this->me($race), 'The race token (minted after the delete stamp) revived with the restore.');
        self::assertSame(200, $this->me($this->mintAt($tenant, $subject, $r + 6)), 'Control: a token minted after the restore is refused.');
    }

    /**
     * **T-3-5** `aRestoredPersonSignsInWithTheirOldPassword` — pins today's behaviour (an API restore is meant to bring the
     * account back) and names cred-final-RED M-3: the SQL variant of this (clear `deleted_at` as `erp_app`, then G-10) is the
     * residual recorded in G-10 / G-12, closed only by G-10's closing work. The clock is advanced one second past the restore:
     * a sign-in in the restore's own second is refused once (the named cost, owner notice 3) — measured, the first draft of
     * this test signed in within that second and was refused.
     */
    #[Test]
    public function aRestoredPersonSignsInWithTheirOldPassword(): void
    {
        [$slug, $tenant, $subject, $email] = $this->workspace('restored-signin');
        $clock = $this->mockAt(time() - 10);
        $this->deleteAsAdmin($tenant, $subject);
        $this->restoreAsAdmin($tenant, $subject);
        $clock->sleep(1);

        $this->me($this->signedIn($slug, $email));
        self::assertSame(200, $this->httpStatus(), 'A restored person cannot sign in with their old password.');
    }

    private function deleteAsAdmin(string $tenant, string $subject): void
    {
        $this->actAsUser($tenant, $this->seedPrincipal($tenant, DefaultRoles::codesFor(DefaultRoles::ADMIN)));
        $this->send('DELETE', '/users/'.$subject);
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->actAsNobody();
    }

    private function restoreAsAdmin(string $tenant, string $subject): void
    {
        $this->actAsUser($tenant, $this->seedPrincipal($tenant, DefaultRoles::codesFor(DefaultRoles::ADMIN)));
        $this->send('POST', '/users/'.$subject.'/restoration');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->actAsNobody();
    }

    // ---------------------------------------------------------------------

    /** @return array{0: string, 1: string, 2: string, 3: string} slug, tenant id, subject id, subject email */
    private function workspace(string $name): array
    {
        $slug = self::SLUG_PREFIX.'stamp-'.$name;
        $tenant = $this->seedTenant($slug, Tenant::STATUS_ACTIVE);
        $email = $name.'@example.com';

        return [$slug, $tenant, $this->seedUser($tenant, $email, self::PASSWORD), $email];
    }

    private function mockAt(int $second): MockClock
    {
        \assert($second <= time(), 'Mock at or behind real time (firebase checks iat with time()).');
        $clock = self::mockTime(new \DateTimeImmutable('@'.$second));
        \assert($clock instanceof MockClock);

        return $clock;
    }

    private function signedIn(string $slug, string $email): string
    {
        $this->actAsNobody();
        $this->signIn($slug, $email, self::PASSWORD);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        return (string) $this->json()['access_token'];
    }

    private function mintAt(string $tenant, string $userId, int $issuedAt): string
    {
        \assert($issuedAt <= time());
        $issuer = self::getContainer()->get('test.auth.token_issuer');
        \assert($issuer instanceof AccessTokenIssuer);

        return $issuer->issue($tenant, $userId, new \DateTimeImmutable('@'.$issuedAt))->jwt;
    }

    /** @param array<string, mixed> $body */
    private function patchAsAdmin(string $tenant, string $subject, array $body): void
    {
        $this->actAsUser($tenant, $this->seedPrincipal($tenant, DefaultRoles::codesFor(DefaultRoles::ADMIN)));
        $this->send('PATCH', '/users/'.$subject, $body);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->actAsNobody();
    }

    private function me(string $token): int
    {
        $this->send('GET', '/me', null, ['Authorization' => 'Bearer '.$token]);

        return $this->httpStatus();
    }
}

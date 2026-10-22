<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Modules\UserManagement\Infrastructure\AccessTokenIssuer;
use App\Platform\Tenancy\Entity\Tenant;
use PHPUnit\Framework\Attributes\Test;

/**
 * **G4-C0 — a token dies with its user's liveness** (`docs/session13/g4-users-design.md` §4 + Revision 1 FINAL R1.2 G4-C0;
 * `remaining-ops-final-red.md` M-1, H-2). `IdentityReader`'s one `app_user` statement refuses, as the same `null` and so the
 * same constant 401: a soft-deleted principal (shipped, `CurrentUserTest::aSoftDeletedPrincipalsLiveTokenNoLongerReads` is
 * its regression twin, T-0-3), an **inactive** one (API-O-22 closed, option (a)), and a token whose `iat` is not strictly
 * after `app_user.sessions_valid_after`.
 *
 * C0 has no writer of the stamp (C1/C3/Mail-C2 write it); the owner seeds it and `is_active` here. **Tokens are minted with
 * explicit `issuedAt` values at or BEFORE real time**: firebase/php-jwt checks `iat` with PHP's `time()`, so a token minted
 * ahead of real time is refused (`BeforeValidException`) for the wrong reason (design §1.2). No failed sign-in happens here.
 */
final class SessionLivenessTest extends AuthTestCase
{
    private const PASSWORD = 'correct horse battery staple';

    /**
     * **T-0-1 — API-O-22's own probe, reversed.** A live token whose user is then set `is_active = false` (as the owner)
     * stops working on the next request: `GET /me` answers the constant 401. Control: the same token read 200 first.
     *
     * *Mutation M-0-1, run:* drop `AND is_active` from `IdentityReader` → 200, red.
     */
    #[Test]
    public function aDeactivatedUsersLiveTokenIsRefused(): void
    {
        [$tenantId, $userId] = $this->person('deactivated');
        $token = $this->mintAt($tenantId, $userId, time() - 5);

        $this->me($token);
        self::assertSame(200, $this->httpStatus(), 'Control: the live token did not read /me, so the refusal below proves nothing.');

        $this->executeAsOwnerForTenant($tenantId, 'UPDATE app_user SET is_active = false WHERE id = ?::uuid', [$userId]);

        $this->me($token);
        self::assertSame([401, 'urn:erp:problem:authentication-failed'], [$this->httpStatus(), $this->json()['type'] ?? null],
            "A deactivated person's live token still authenticates: API-O-22 is open again.");
    }

    /**
     * **T-0-2 — the stamp is strict and whole-second safe.** The owner seeds the stamp at `S + 0.700` (a fractional SQL
     * seed; an ORM-written stamp is whole-second). A token with `iat = S` is refused; `iat = S + 1` reads; with the stamp
     * NULL, `iat = S` reads. `S` is 100 s in the past, so every token is minted behind real time.
     *
     * *Mutations, run:* M-0-2 `>` → `>=` → the integer-stamp block's `iat = S` reads, red (on the fractional stamp `>=`
     * and `>` agree, which is why the second block seeds `S` exactly); M-0-3 drop the stamp clause → `iat = S` reads, red;
     * M-0-4 compare `extract(epoch FROM now())` instead of the bound `iat` → `iat = S` reads (now > S.7), red; M-0-5 the
     * verifier drops `iat` and the gate passes 0 → `S + 1` refused, red. M-0-6 (`floor` added or removed) cannot go red by the
     * equivalence `iat > s ⇔ iat > floor(s)`: a review check, not evidence.
     */
    #[Test]
    public function aTokenFromTheStampedSecondIsRefusedAndTheNextSecondIsNot(): void
    {
        [$tenantId, $userId] = $this->person('stamped');
        $s = time() - 100;

        $this->stamp($tenantId, $userId, $s.'.700');
        $this->me($this->mintAt($tenantId, $userId, $s));
        self::assertSame(401, $this->httpStatus(), 'A token minted in the stamped second (iat = S, stamp = S.7) still reads.');
        $this->me($this->mintAt($tenantId, $userId, $s + 1));
        self::assertSame(200, $this->httpStatus(), 'A token minted the second after the stamp (iat = S + 1, stamp = S.7) is refused.');

        // Integer stamp: strictness is visible only here (iat = S is NOT after S).
        $this->stamp($tenantId, $userId, (string) $s);
        $this->me($this->mintAt($tenantId, $userId, $s));
        self::assertSame(401, $this->httpStatus(), 'iat = S passed a stamp of exactly S: the comparison is not strict.');
        $this->me($this->mintAt($tenantId, $userId, $s + 1));
        self::assertSame(200, $this->httpStatus());

        $this->stamp($tenantId, $userId, null);
        $this->me($this->mintAt($tenantId, $userId, $s));
        self::assertSame(200, $this->httpStatus(), 'A NULL stamp must mean no restriction.');
    }

    /** @return array{0: string, 1: string} tenant id, user id */
    private function person(string $slug): array
    {
        $tenantId = $this->seedTenant(self::SLUG_PREFIX.$slug, Tenant::STATUS_ACTIVE);

        return [$tenantId, $this->seedUser($tenantId, $slug.'@example.com', self::PASSWORD)];
    }

    private function mintAt(string $tenantId, string $userId, int $issuedAt): string
    {
        \assert($issuedAt <= time(), 'firebase/php-jwt refuses a future iat by time(): mint at or behind real time.');
        $issuer = self::getContainer()->get('test.auth.token_issuer');
        \assert($issuer instanceof AccessTokenIssuer);

        return $issuer->issue($tenantId, $userId, new \DateTimeImmutable('@'.$issuedAt))->jwt;
    }

    private function stamp(string $tenantId, string $userId, ?string $epoch): void
    {
        $this->executeAsOwnerForTenant($tenantId,
            'UPDATE app_user SET sessions_valid_after = CASE WHEN ?::text IS NULL THEN NULL ELSE to_timestamp(?::numeric) END WHERE id = ?::uuid',
            [$epoch, $epoch, $userId]);
    }

    private function me(string $token): void
    {
        $this->send('GET', '/me', null, ['Authorization' => 'Bearer '.$token]);
    }
}

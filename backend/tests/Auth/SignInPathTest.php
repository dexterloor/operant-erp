<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Platform\Security\AccessTokenVerifier;
use App\Platform\Tenancy\Entity\Tenant;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\Test;

/**
 * The success branch of `API.md` §7.1, and the two things about it no refusal
 * test can show: that a token is actually issued, and that steps 7, 8 and 9 run
 * in the order and on the principals §7.1 names.
 */
final class SignInPathTest extends AuthTestCase
{
    private const PASSWORD = 'correct horse battery staple';

    /**
     * *Mutation, run:* make `SignIn::attempt()` return `SignInResult::refused()`
     * on the success branch; every assertion below is red.
     */
    #[Test]
    public function aCorrectCredentialForAnActiveTenantIsIssuedAToken(): void
    {
        $tenantId = $this->seedTenant(self::SLUG_PREFIX.'live', Tenant::STATUS_ACTIVE);
        $userId = $this->seedUser($tenantId, 'Alice@Example.com', self::PASSWORD);

        $this->signIn(self::SLUG_PREFIX.'live', 'alice@example.com', self::PASSWORD);

        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('application/json', $this->contentType());

        $body = $this->json();
        self::assertSame(['access_token', 'token_type', 'expires_at'], array_keys($body));
        self::assertSame('Bearer', $body['token_type']);

        $tokens = self::getContainer()->get('test.auth.token_verifier');
        \assert($tokens instanceof AccessTokenVerifier);

        self::assertSame(
            ['tenant_id' => $tenantId, 'sub' => $userId],
            $tokens->verify((string) $body['access_token']),
            'The minted token does not carry the resolved tenant and user. ADR-005 D-5 makes tenant_id a '
            .'signed claim read server-side, and nothing else on any later request supplies it.',
        );
    }

    /**
     * §7.1 step 8 on the success branch, and E-13 with it.
     *
     * *Mutation, run:* declare `AUTH_LOGIN_SUCCESS` in `AuditEvent::SUBJECT_TYPES`
     * instead of `SUBJECTLESS`; `AuthTrailWriter` refuses to write it and this is
     * red — which is E-13 enforced structurally rather than by an assertion on
     * two columns.
     */
    #[Test]
    public function aSuccessfulSignInWritesOneSubjectlessAuditRowCarryingTheAddress(): void
    {
        $tenantId = $this->seedTenant(self::SLUG_PREFIX.'live', Tenant::STATUS_ACTIVE);
        $userId = $this->seedUser($tenantId, 'alice@example.com', self::PASSWORD);

        $this->signIn(self::SLUG_PREFIX.'live', 'alice@example.com', self::PASSWORD, '198.51.100.9');

        self::assertSame(200, $this->httpStatus());

        $rows = $this->trailRows($tenantId);
        self::assertCount(1, $rows);
        self::assertSame(AuditEvent::AUTH_LOGIN_SUCCESS, $rows[0]['event_type']);
        self::assertSame($userId, $rows[0]['actor_user_id']);
        self::assertSame('alice@example.com', $rows[0]['actor_label'], 'actor_label is not the address app_user stores.');
        self::assertNull($rows[0]['subject_type'], 'E-13: an auth.* event carries no subject.');
        self::assertNull($rows[0]['subject_id']);
        self::assertSame('198.51.100.9', $rows[0]['ip_address'], 'API-16: a security event carries the observed address.');
    }

    /**
     * §7.1 step 9. Also E-22's only reset: `failure_count` returns to zero on a
     * success **for that pair**.
     *
     * *Mutation, run:* delete the `$this->lockouts->clear(...)` call from
     * `SignIn`; the counter assertion is red. *Second mutation:* delete the
     * `LoginRecorder::record()` call; `last_login_at` stays null and the first
     * assertion is red.
     */
    #[Test]
    public function aSuccessRecordsTheLoginAndClearsThatPairsFailureCount(): void
    {
        $tenantId = $this->seedTenant(self::SLUG_PREFIX.'live', Tenant::STATUS_ACTIVE);
        $this->seedUser($tenantId, 'alice@example.com', self::PASSWORD);

        $this->signIn(self::SLUG_PREFIX.'live', 'alice@example.com', 'wrong');
        self::assertSame(1, $this->lockoutFor(self::SLUG_PREFIX.'live', 'alice@example.com')['failure_count']);

        $this->signIn(self::SLUG_PREFIX.'live', 'alice@example.com', self::PASSWORD);
        self::assertSame(200, $this->httpStatus());

        self::assertSame(0, $this->lockoutFor(self::SLUG_PREFIX.'live', 'alice@example.com')['failure_count']);

        // Read under tenant context: FORCE ROW LEVEL SECURITY binds the owner
        // too, so an unqualified read here would return zero rows and this
        // assertion would fail for a reason that has nothing to do with step 9.
        self::assertSame(1, $this->countAsOwnerForTenant($tenantId,
            'SELECT count(*) FROM app_user WHERE last_login_at IS NOT NULL'),
            'Step 9 did not run: AppUser::recordLogin() writes last_login_at on erp_app after the JWT is minted.');
    }

    /**
     * ADR-006 §6.4(a): the limiter key and the lookup key are the **same**
     * normalisation, so `ACME` cannot buy a fresh bucket for `acme` and an
     * upper-case address still resolves.
     *
     * *Mutation, run:* drop `mb_strtolower` from `PreAuthKeys::slug()`; the
     * lookup finds no tenant and this is red with a 401.
     */
    #[Test]
    public function theSlugAndTheEmailAreNormalisedOnceBeforeBothTheLookupAndTheLimiter(): void
    {
        $tenantId = $this->seedTenant(self::SLUG_PREFIX.'live', Tenant::STATUS_ACTIVE);
        $this->seedUser($tenantId, 'alice@example.com', self::PASSWORD);

        $this->signIn(strtoupper(self::SLUG_PREFIX.'live'), '  Alice@Example.COM ', self::PASSWORD);
        self::assertSame(200, $this->httpStatus());

        $keys = $this->owner()->fetchAllAssociative('SELECT slug_normalised FROM auth_attempt');
        self::assertSame([['slug_normalised' => self::SLUG_PREFIX.'live']], $keys,
            'The limiter bucketed a slug the lookup normalised differently, which is exactly the shift-key bypass §6.4(a) names.');
    }
}

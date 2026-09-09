<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Modules\UserManagement\Infrastructure\LockoutStore;
use App\Platform\Tenancy\Entity\Tenant;
use App\Tests\Support\SecurityModelDocument;
use PHPUnit\Framework\Attributes\Test;

/**
 * **E-22** — ten accrued failures, a fifteen-minute pause, and a reset on a
 * success for that same pair or on the expiry of a pause, **and on nothing
 * else**.
 *
 * ST-8b is what makes disclosing the pause safe, and it rests entirely on
 * **E-8**: the counter is *"attempts against a pair, by anyone"*, including the
 * unresolved-workspace and unresolved-email paths. Without that, *"locked"* means
 * *"this pair is real"* — a user-existence oracle, the one axis ST-7.1 marks
 * absolute. So E-8 is asserted here too, on the miss paths, rather than left to
 * `PreAuthPrincipalTest`'s statement counts.
 */
final class AuthLockoutThresholdTest extends AuthTestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private const EMAIL = 'alice@example.com';

    /**
     * **E-22's figures, pinned here and not read from the code under test.**
     *
     * The first version of this file looped `LockoutStore::THRESHOLD` times.
     * Lowering that constant to 5 left the suite **green** — measured — because
     * the loop shortened with it and the tenth attempt stopped being driven at
     * all. The register is the authority for the figure; these literals carry it
     * into the test, and `theFiguresAreTheOnesTheEnforcementRegisterSets` is
     * where the two are reconciled.
     */
    private const PINNED_THRESHOLD = 10;

    private const PINNED_PAUSE_SECONDS = 900;

    /**
     * The pin, against the constants and against E-22's own row.
     *
     * *Mutation, run:* lower `LockoutStore::THRESHOLD` to 5; red here **and** on
     * the boundary below — the pair of failures the first version of this file
     * did not produce.
     */
    #[Test]
    public function theFiguresAreTheOnesTheEnforcementRegisterSets(): void
    {
        self::assertSame(self::PINNED_THRESHOLD, LockoutStore::THRESHOLD);
        self::assertSame(self::PINNED_PAUSE_SECONDS, LockoutStore::PAUSE_SECONDS);

        if (!SecurityModelDocument::isAvailable()) {
            self::markTestSkipped('docs/SECURITY_MODEL.md is gitignored and absent from this checkout, so the pin is checked against the code only.');
        }

        $row = SecurityModelDocument::enforcementRow('E-22');
        self::assertNotSame('', $row, 'E-22 has left the register, so the pin above is unanchored.');

        // The register writes both figures in words, which is how ST-8b's copy
        // has to read them. Matched on the words rather than on the numerals for
        // that reason, and both are required.
        self::assertStringContainsString('ten accrued failures', $row);
        self::assertStringContainsString('fifteen minutes', $row);
    }

    /**
     * §5.1.3's *fails if*, both halves: *"the tenth failure … is answered with
     * anything but `authentication-failed`, or the eleventh with anything but
     * `sign-in-paused`."*
     *
     * *Mutation, run:* lower `LockoutStore::THRESHOLD` to 5; the
     * *tenth-is-still-a-wrong-password* half is red — which is the half a test
     * asserting only *"eventually it locks"* would not have.
     *
     * *Second mutation, run:* delete the `pausedUntil` early return from
     * `SignIn::attempt()`; the eleventh answers 401 and the second half is red.
     */
    #[Test]
    public function theTenthFailureIsStillAWrongPasswordAndTheEleventhIsPaused(): void
    {
        $slug = self::SLUG_PREFIX.'lockout';
        $tenantId = $this->seedTenant($slug, Tenant::STATUS_ACTIVE);
        $this->seedUser($tenantId, self::EMAIL, self::PASSWORD);

        for ($attempt = 1; $attempt <= self::PINNED_THRESHOLD; ++$attempt) {
            $this->signIn($slug, self::EMAIL, 'wrong');
            self::assertSame(401, $this->httpStatus(), "Attempt $attempt was not answered as a wrong password.");
            self::assertSame('urn:erp:problem:authentication-failed', $this->json()['type']);
        }

        self::assertSame(
            ['failure_count' => self::PINNED_THRESHOLD, 'locked' => true],
            $this->lockoutFor($slug, self::EMAIL),
            'The threshold was reached and no pause was set.',
        );

        $this->signIn($slug, self::EMAIL, 'wrong');
        self::assertSame(429, $this->httpStatus());
        self::assertSame('urn:erp:problem:sign-in-paused', $this->json()['type']);
        self::assertNull($this->client()->getResponse()->headers->get('Retry-After'),
            'ST-8a: the remaining time is not disclosed, and Retry-After is the remaining time.');
    }

    /**
     * E-22's sharpest clause: *"a reset on anything but a success for that pair
     * or the expiry of a pause — in particular a reset on any successful sign-in
     * from the same prefix, which lets an attacker who holds one valid credential
     * clear a victim's counter at will."*
     *
     * *Mutation, run:* give `LockoutStore` a `clearForPrefix()` and call it on
     * the success branch of `SignIn::attempt()`; the victim's counter drops to
     * zero and this is red.
     */
    #[Test]
    public function aSuccessForAnotherPairDoesNotResetTheCounter(): void
    {
        $slug = self::SLUG_PREFIX.'shared-prefix';
        $tenantId = $this->seedTenant($slug, Tenant::STATUS_ACTIVE);
        $this->seedUser($tenantId, 'victim@example.com', self::PASSWORD);
        $this->seedUser($tenantId, 'attacker@example.com', self::PASSWORD);

        for ($attempt = 0; $attempt < 4; ++$attempt) {
            $this->signIn($slug, 'victim@example.com', 'wrong');
        }
        self::assertSame(4, $this->lockoutFor($slug, 'victim@example.com')['failure_count']);

        // Same workspace, same address, a credential the attacker really holds.
        $this->signIn($slug, 'attacker@example.com', self::PASSWORD);
        self::assertSame(200, $this->httpStatus(), 'The control attempt did not succeed, so the assertion below proves nothing.');

        self::assertSame(4, $this->lockoutFor($slug, 'victim@example.com')['failure_count'],
            "A success for a different pair at the same prefix cleared the victim's counter.");
    }

    /**
     * E-22's other reset: *"on the expiry of a pause."*
     *
     * The clock is moved by ageing the row rather than by mocking, because the
     * expiry is decided by Postgres' `now()` inside `LockoutStore`'s single
     * upsert — a mocked PHP clock would not reach it, and a test that mocked the
     * wrong clock would pass while the real reset never fired.
     *
     * *Mutation, run:* drop the `WHEN $expired THEN 1` arm from the upsert's
     * `failure_count`; the count continues from eleven and this is red — a pause
     * that never resets is a permanent lockout, which is ST-8b's denial of
     * service made unbounded.
     */
    #[Test]
    public function anExpiredPauseResetsTheCounterAndTheNextAttemptIsAWrongPasswordAgain(): void
    {
        $slug = self::SLUG_PREFIX.'expiry';
        $tenantId = $this->seedTenant($slug, Tenant::STATUS_ACTIVE);
        $this->seedUser($tenantId, self::EMAIL, self::PASSWORD);

        for ($attempt = 0; $attempt < self::PINNED_THRESHOLD; ++$attempt) {
            $this->signIn($slug, self::EMAIL, 'wrong');
        }
        self::assertTrue($this->lockoutFor($slug, self::EMAIL)['locked']);

        $this->owner()->executeStatement("UPDATE auth_lockout SET locked_until = now() - interval '1 second'");

        $this->signIn($slug, self::EMAIL, 'wrong');

        self::assertSame(401, $this->httpStatus(), 'An expired pause still refused with sign-in-paused.');
        self::assertSame(['failure_count' => 1, 'locked' => false], $this->lockoutFor($slug, self::EMAIL));
    }

    /**
     * **E-8** — the counter increments on every failed attempt that survives the
     * IP budget, *"including the unresolved-workspace and unresolved-email
     * paths"*. This is what keeps ST-8b's message from being a user-existence
     * oracle.
     *
     * *Mutation, run:* move the `recordFailure` call inside the
     * `$row->isResolvedPair()` branch of `SignIn`; both rows below disappear and
     * this is red.
     */
    #[Test]
    public function bothMissPathsAccrueAgainstThePairTheClientTyped(): void
    {
        $slug = self::SLUG_PREFIX.'live';
        $tenantId = $this->seedTenant($slug, Tenant::STATUS_ACTIVE);
        $this->seedUser($tenantId, self::EMAIL, self::PASSWORD);

        $this->signIn(self::SLUG_PREFIX.'no-such-workspace', 'stranger@example.com', 'x');
        $this->signIn($slug, 'nobody@example.com', 'x');

        self::assertSame(
            ['failure_count' => 1, 'locked' => false],
            $this->lockoutFor(self::SLUG_PREFIX.'no-such-workspace', 'stranger@example.com'),
            'An unresolved workspace accrued nothing, so "locked" would mean "this pair is real".',
        );
        self::assertSame(
            ['failure_count' => 1, 'locked' => false],
            $this->lockoutFor($slug, 'nobody@example.com'),
            'An unresolved email accrued nothing, so "locked" would mean "this user exists".',
        );
    }

    /**
     * The pause duration is the one ST-8b publishes on screen. §5.1.3's *fails
     * if* names a divergence directly: *"a pause duration that differs from the
     * one ST-8b's copy states"* would make a published constant false.
     *
     * Read back off the row rather than off the constant the code used, so this
     * is a claim about the database's `now() + interval` and not about PHP.
     *
     * *Mutation, run:* change `PAUSE_SECONDS` to 60; red.
     */
    #[Test]
    public function thePauseLastsTheDurationTheProductPublishes(): void
    {
        $slug = self::SLUG_PREFIX.'duration';
        $tenantId = $this->seedTenant($slug, Tenant::STATUS_ACTIVE);
        $this->seedUser($tenantId, self::EMAIL, self::PASSWORD);

        for ($attempt = 0; $attempt < self::PINNED_THRESHOLD; ++$attempt) {
            $this->signIn($slug, self::EMAIL, 'wrong');
        }

        $seconds = (int) round((float) $this->owner()->fetchOne(
            'SELECT extract(epoch FROM (locked_until - last_failure_at)) FROM auth_lockout',
        ));

        self::assertSame(self::PINNED_PAUSE_SECONDS, $seconds, 'The pause is not the fifteen minutes ST-8b publishes.');
        self::assertSame(LockoutStore::PAUSE_SECONDS, $seconds, 'The constant and the row disagree.');
    }
}

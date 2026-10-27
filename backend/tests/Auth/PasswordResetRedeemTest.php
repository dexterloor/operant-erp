<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Modules\UserManagement\Domain\SealsIdentities;
use App\Modules\UserManagement\Infrastructure\AccessTokenIssuer;
use App\Platform\Tenancy\Entity\Tenant;
use App\SharedKernel\ValueObject\AuditEvent;
use App\Tests\Support\CountingSeal;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\Uuid;

/**
 * **Mail-C2 `CreatePasswordResetRedemption`** (`docs/session13/mail-c2-build-plan.md` Revision 1 FINAL; final RED).
 * T-R1, T-R2, T-R2b, T-R4, T-R5a/b/c, T-R6, T-R6b, T-R7, T-R8, T-R10…T-R20 and T-R14b live here; T-R3 is in
 * `PreAuthPrincipalTest`, T-R21 in `GrantFootprintTest`, T-R22 in `CheckCredentialKeyCommandTest`. Every link is a real
 * keyed row minted as the worker would (owner, tenant context set); no test hard-codes a token. The KDF seam
 * (`ArmableKdf`) changes state DURING a request only through `freshOwnerClient()` (final RED M-1), with the affected-row
 * count asserted as the non-vacuity control. Mutation results: `docs/session13/mail-c2-progress.md`.
 */
final class PasswordResetRedeemTest extends AuthTestCase
{
    use ClockSensitiveTrait;

    private const SLUG = self::SLUG_PREFIX.'redeem';

    private const EMAIL = 'alice@example.test';

    private const OLD = 'password-number-one';

    private const NEW = 'a brand new passphrase';

    private const INSERT_LINK = <<<'SQL'
        INSERT INTO password_reset_token (id, tenant_id, user_id, purpose, selector, verifier_mac, message_id, created_at, expires_at)
        VALUES (?::uuid, ?::uuid, ?::uuid, ?, ?, ?, ?::uuid, to_timestamp(?), to_timestamp(?))
        SQL;

    private const HOLDER_DEADLINE_SECONDS = 10.0;

    /** **T-R1:** a link sets the password once; the old password is refused, the new one signs in; one trail row. */
    #[Test]
    public function aLinkSetsThePasswordOnceAndSignInThenWorks(): void
    {
        [$tenant, $user] = $this->liveUser();
        $token = $this->mint($tenant, $user);

        $this->redeem($token, self::NEW);
        self::assertSame(200, $this->httpStatus(), $this->responseContent());
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', (string) $this->json()['changed_at']);

        $this->redeem($token, self::NEW.'2');
        self::assertSame(401, $this->httpStatus(), 'A used link worked twice.');

        $this->signIn(self::SLUG, self::EMAIL, self::OLD);
        self::assertSame(401, $this->httpStatus(), 'The old password still signs in.');
        $this->signIn(self::SLUG, self::EMAIL, self::NEW);
        self::assertSame(200, $this->httpStatus(), 'The new password does not sign in.');

        $rows = $this->fetchAsOwnerForTenant($tenant, 'SELECT actor_user_id, actor_label, subject_type, ip_address, metadata FROM audit_log WHERE event_type = ?', [AuditEvent::AUTH_PASSWORD_RESET]);
        self::assertCount(1, $rows);
        self::assertSame($user, $rows[0]['actor_user_id']);
        self::assertSame(self::EMAIL, $rows[0]['actor_label']);
        self::assertNull($rows[0]['subject_type']);
        self::assertSame(['purpose' => 'reset'], json_decode((string) $rows[0]['metadata'], true));
    }

    /**
     * **T-R2:** every invalid link of §7 gets the byte-identical 401 — status, body and headers — and each is sent again
     * with an 11-character password, which must still be the 401, never the 422 (MC-10: the rules run only after a good
     * link). Mutations MC-1, MC-3x, MC-10, MC-13 → red here.
     */
    #[Test]
    public function everyBadTokenGetsTheSameAnswer(): void
    {
        $answers = [];
        foreach ($this->badTokens() as $case => $token) {
            foreach ([self::NEW, 'elevenchars'] as $password) {
                $this->redeem($token, $password);
                $answers["$case/".\strlen($password)] = $this->answer();
            }
        }
        $first = reset($answers);
        self::assertSame(401, $first['status']);
        foreach ($answers as $case => $answer) {
            self::assertSame($first, $answer, "$case answered differently.");
        }
        $this->signIn(self::SLUG, self::EMAIL, self::OLD);
        self::assertSame(200, $this->httpStatus(), 'A refused link changed the password.');
        self::assertSame([], $this->resetRows($this->tenantOf(self::SLUG)), 'A refused link wrote the trail.');
    }

    /**
     * **T-R2b:** every invalid branch issues the same statements, per connection, in the same order — and nothing on
     * `erp_app` (design §2.5 row E). Positive half: the budget and the lookup did run. A branch that adds or drops a
     * statement (a "record the tamper" write, an early return before the lookup) → red.
     */
    #[Test]
    public function everyBadTokenIssuesTheSameStatements(): void
    {
        $sequences = [];
        foreach ($this->badTokens() as $case => $token) {
            foreach (['app', 'auth', 'ratelimit', 'authlog'] as $connection) {
                $this->recorderFor($connection)->start();
            }
            $this->redeem($token, self::NEW);
            $sequence = [];
            foreach (['app', 'auth', 'ratelimit', 'authlog'] as $connection) {
                $this->recorderFor($connection)->stop();
                $sequence[$connection] = $this->recorderFor($connection)->statements();
            }
            self::assertSame(401, $this->httpStatus(), $case);
            $sequences[$case] = $sequence;
        }
        $first = reset($sequences);
        self::assertSame([], $first['app'], "A refused link reached erp_app:\n".implode("\n", $first['app']));
        self::assertNotSame([], $first['auth'], 'The lookup issued nothing, so the identity below is vacuous.');
        self::assertNotSame([], $first['ratelimit'], 'The budget issued nothing.');
        foreach ($sequences as $case => $sequence) {
            self::assertSame($first, $sequence, "$case issued a different statement sequence.");
        }
    }

    /**
     * **T-R16:** the verifier MAC and the identity seal are each computed exactly once on every branch — every invalid
     * link and the success — so the cost does not say which check failed (`CountingSeal`, the T-C4 precedent). Mutation
     * MC-12 (skip the MAC on a selector miss) → red.
     */
    #[Test]
    public function theTokenChecksCostTheSameOnEveryPath(): void
    {
        $seal = self::getContainer()->get(CountingSeal::class);
        \assert($seal instanceof CountingSeal);
        $tokens = $this->badTokens();
        $tokens['valid'] = $this->mint($this->tenantOf(self::SLUG), $this->userOf(self::EMAIL));
        foreach ($tokens as $case => $token) {
            $seal->reset();
            $this->redeem($token, self::NEW.' '.$case);
            self::assertSame('valid' === $case ? 200 : 401, $this->httpStatus(), $case);
            self::assertSame([1, 1], [$seal->verifierMatchCount(), $seal->identityCheckCount()],
                "$case: the verifier MAC and the identity seal were not each computed once.");
        }
    }

    /** **T-R7:** a bad new password on a good link is 422 and keeps the link usable. */
    #[Test]
    public function aBadPasswordKeepsTheLinkUsable(): void
    {
        [$tenant, $user] = $this->liveUser();
        $token = $this->mint($tenant, $user);
        $this->redeem($token, 'elevenchars');
        self::assertSame(422, $this->httpStatus());
        self::assertContains('/password', array_column((array) ($this->json()['errors'] ?? []), 'pointer'));
        $this->redeem($token, self::NEW);
        self::assertSame(200, $this->httpStatus(), 'A bad password consumed the link.');
    }

    /** **T-R12:** a redeem ends every other link of that person; another person's link still works. */
    #[Test]
    public function aRedeemEndsEveryOtherLinkOfThatPerson(): void
    {
        [$tenant, $user] = $this->liveUser();
        $bob = $this->seedUser($tenant, 'bob@example.test', self::OLD);
        $this->sealIdentity($tenant, $bob, 'bob@example.test');
        $first = $this->mint($tenant, $user);
        $second = $this->mint($tenant, $user);
        $bobs = $this->mint($tenant, $bob);

        $this->redeem($first, self::NEW);
        self::assertSame(200, $this->httpStatus());
        $this->redeem($second, self::NEW);
        self::assertSame(401, $this->httpStatus(), 'A second link of the same person survived the redeem.');
        $this->redeem($bobs, self::NEW);
        self::assertSame(200, $this->httpStatus(), 'Another person\'s link was consumed.');
    }

    /** **T-R5a (M-3):** every link of the person is used between the check and the write → 401, nothing changed (MC-4). */
    #[Test]
    public function aLinkUsedBetweenCheckAndWriteIsRefused(): void
    {
        [$tenant, $user] = $this->liveUser();
        $this->mint($tenant, $user);
        $presented = $this->mint($tenant, $user);
        $before = $this->credentialOf($tenant, $user);
        $this->kdf()->arm(function () use ($tenant, $user): void {
            self::assertSame(2, $this->asFreshOwner($tenant, 'UPDATE password_reset_token SET used_at = now() WHERE user_id = ?::uuid AND used_at IS NULL', [$user]));
        });

        $this->redeem($presented, self::NEW);

        self::assertSame(401, $this->httpStatus(), 'A link consumed between the check and the write still set the password.');
        self::assertSame($before, $this->credentialOf($tenant, $user));
        self::assertSame([], $this->resetRows($tenant));
    }

    /** **T-R5c:** a stale link cannot use a newer link minted meanwhile → 401, the newer link unused (MC-5). */
    #[Test]
    public function aStaleLinkCannotUseANewerLink(): void
    {
        [$tenant, $user] = $this->liveUser();
        $presented = $this->mint($tenant, $user);
        $newer = null;
        $this->kdf()->arm(function () use ($tenant, $user, &$newer): void {
            self::assertSame(1, $this->asFreshOwner($tenant, 'UPDATE password_reset_token SET used_at = now() WHERE user_id = ?::uuid AND used_at IS NULL', [$user]));
            $newer = $this->mint($tenant, $user, client: $this->freshOwner);
        });

        $this->redeem($presented, self::NEW);

        self::assertSame(401, $this->httpStatus(), 'A stale link set the password through a newer link.');
        self::assertSame(1, (int) $this->fetchAsOwnerForTenant($tenant, 'SELECT count(*) AS n FROM password_reset_token WHERE used_at IS NULL')[0]['n'], 'The newer link was consumed by a refused redeem.');
    }

    /** **T-R10:** a person switched off between the check and the write is refused; nothing consumed (MC-8). */
    #[Test]
    public function aUserDeactivatedBetweenCheckAndWriteIsRefused(): void
    {
        [$tenant, $user] = $this->liveUser();
        $token = $this->mint($tenant, $user);
        $before = $this->credentialOf($tenant, $user);
        $this->kdf()->arm(function () use ($tenant, $user): void {
            self::assertSame(1, $this->asFreshOwner($tenant, 'UPDATE app_user SET is_active = false WHERE id = ?::uuid', [$user]));
        });

        $this->redeem($token, self::NEW);

        self::assertSame(401, $this->httpStatus());
        self::assertSame($before, $this->credentialOf($tenant, $user));
        self::assertSame(1, (int) $this->fetchAsOwnerForTenant($tenant, 'SELECT count(*) AS n FROM password_reset_token WHERE used_at IS NULL')[0]['n'], 'The link was consumed.');
    }

    /** **T-R11 (G4 amendment):** a later floor is kept; a NULL floor becomes the redeem second (MC-6). */
    #[Test]
    public function theSessionFloorNeverMovesBackwards(): void
    {
        [$tenant, $user] = $this->liveUser();
        $token = $this->mint($tenant, $user, createdAt: time() - 5);
        $this->redeem($token, self::NEW);
        self::assertSame(200, $this->httpStatus());
        $floor = $this->fetchAsOwnerForTenant($tenant, 'SELECT extract(epoch FROM sessions_valid_after)::bigint AS f FROM app_user WHERE id = ?::uuid', [$user])[0]['f'];
        self::assertSame((new \DateTimeImmutable((string) $this->json()['changed_at']))->getTimestamp(), (int) $floor, 'A NULL floor did not become the redeem second.');

        $future = time() + 3600;
        $this->executeAsOwnerForTenant($tenant, 'UPDATE app_user SET sessions_valid_after = to_timestamp(?) WHERE id = ?::uuid', [$future, $user]);
        // A link minted after that floor (Q-3 would refuse an older one).
        $later = $this->mint($tenant, $user, createdAt: $future + 1, expiresIn: 7200);
        $this->redeem($later, self::NEW.'x');
        self::assertSame(200, $this->httpStatus(), $this->responseContent());
        $floor = $this->fetchAsOwnerForTenant($tenant, 'SELECT extract(epoch FROM sessions_valid_after)::bigint AS f FROM app_user WHERE id = ?::uuid', [$user])[0]['f'];
        self::assertSame($future, (int) $floor, 'The redeem lowered a later session floor.');
    }

    /** **T-R13 (D-M8):** a paused pair is lifted by a redeem. */
    #[Test]
    public function aRedeemLiftsTheSignInPause(): void
    {
        [$tenant, $user] = $this->liveUser();
        for ($i = 0; $i < 10; ++$i) {
            $this->signIn(self::SLUG, self::EMAIL, 'wrong-password-'.$i);
        }
        self::assertTrue($this->lockoutFor(self::SLUG, self::EMAIL)['locked'] ?? false, 'Control: the pair is not paused.');
        $this->redeem($this->mint($tenant, $user), self::NEW);
        self::assertSame(200, $this->httpStatus());
        $this->signIn(self::SLUG, self::EMAIL, self::NEW);
        self::assertSame(200, $this->httpStatus(), 'The pause survived the redeem.');
    }

    /** **T-R15 (owner Q-3):** a link minted before a deactivate/reactivate is dead. */
    #[Test]
    public function aLinkMintedBeforeTheLatestLivenessChangeIsDead(): void
    {
        [$tenant, $user] = $this->liveUser();
        $token = $this->mint($tenant, $user, createdAt: time() - 5);
        $this->executeAsOwnerForTenant($tenant, 'UPDATE app_user SET sessions_valid_after = to_timestamp(?) WHERE id = ?::uuid', [time() - 2, $user]);
        $this->redeem($token, self::NEW);
        self::assertSame(401, $this->httpStatus(), 'A link minted before the latest session floor still worked.');
    }

    /** **T-R17:** an address rewritten by SQL after the mail no longer matches its identity seal → 401 (MC-11). */
    #[Test]
    public function anEmailChangedBySqlAfterTheMailIsRefused(): void
    {
        [$tenant, $user] = $this->liveUser();
        $token = $this->mint($tenant, $user);
        $this->executeAsOwnerForTenant($tenant, 'UPDATE app_user SET email = ? WHERE id = ?::uuid', ['mallory@example.test', $user]);
        $this->redeem($token, self::NEW);
        self::assertSame(401, $this->httpStatus(), 'A link worked for an account whose address was rewritten by SQL.');
    }

    /** **T-R14b (M-2):** a failed audit write (planted trigger) is a 500 and leaves nothing changed (MC-19). */
    #[Test]
    public function aFailedAuditWriteLeavesNothingChanged(): void
    {
        [$tenant, $user] = $this->liveUser();
        $token = $this->mint($tenant, $user);
        $before = $this->credentialOf($tenant, $user);
        $owner = $this->owner();
        $owner->executeStatement('DROP TRIGGER IF EXISTS mail_c2_audit_fails ON audit_log');
        $owner->executeStatement('DROP FUNCTION IF EXISTS mail_c2_audit_fails()');
        $owner->executeStatement("CREATE FUNCTION mail_c2_audit_fails() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'planted' USING ERRCODE = 'P0001'; END \$\$");
        $owner->executeStatement('REVOKE ALL ON FUNCTION mail_c2_audit_fails() FROM PUBLIC');
        $owner->executeStatement(\sprintf("CREATE TRIGGER mail_c2_audit_fails BEFORE INSERT ON audit_log FOR EACH ROW WHEN (NEW.event_type = 'auth.password.reset' AND NEW.actor_user_id = '%s'::uuid) EXECUTE FUNCTION mail_c2_audit_fails()", $user));
        try {
            $this->redeem($token, self::NEW);
            self::assertSame(500, $this->httpStatus());
        } finally {
            $owner->executeStatement('DROP TRIGGER IF EXISTS mail_c2_audit_fails ON audit_log');
            $owner->executeStatement('DROP FUNCTION IF EXISTS mail_c2_audit_fails()');
        }
        self::assertSame($before, $this->credentialOf($tenant, $user), 'The password moved without its audit row.');
        self::assertSame(1, (int) $this->fetchAsOwnerForTenant($tenant, 'SELECT count(*) AS n FROM password_reset_token WHERE used_at IS NULL')[0]['n'], 'The link was consumed.');
    }

    /**
     * **T-R4 (carry item 5, RED L-7):** a token row forged in the database — by the owner, and by the mail worker's login
     * WITHOUT the seal key — with a test-chosen verifier and a well-shaped `verifier_mac` that is a plain sha256 rather than
     * the keyed MAC, is refused; nothing changes. Mutations MC-1 (skip the MAC) and MC-2 (plain sha256 compare) → 200 → red.
     */
    #[Test]
    public function aForgedTokenRowIsRefused(): void
    {
        [$tenant, $user] = $this->liveUser();
        $before = $this->credentialOf($tenant, $user);
        $kid = $this->seal()->keyId();
        $worker = $this->freshUnpooledWorkerClient();
        try {
            foreach (['owner' => null, 'erp_worker' => $worker] as $who => $client) {
                $selector = bin2hex(random_bytes(16));
                $verifier = bin2hex(random_bytes(32));
                $created = time() - 1;
                $forged = 'v1.'.$kid.'.'.hash('sha256', $verifier);
                $params = [Uuid::v4()->toRfc4122(), $tenant, $user, 'reset', $selector, $forged, Uuid::v4()->toRfc4122(), $created, $created + 1800];
                if (null === $client) {
                    $this->executeAsOwnerForTenant($tenant, self::INSERT_LINK, $params);
                } else {
                    self::assertSame(1, $this->executeOnClientForTenant($client, $tenant, self::INSERT_LINK, $params), 'Control: the worker could not insert the row.');
                }
                $this->redeem($this->tokenFrom($tenant, $selector, $verifier), self::NEW);
                self::assertSame(401, $this->httpStatus(), "A token row forged by $who set the password.");
            }
        } finally {
            $worker->close();
        }
        self::assertSame($before, $this->credentialOf($tenant, $user));
        self::assertSame(2, (int) $this->fetchAsOwnerForTenant($tenant, 'SELECT count(*) AS n FROM password_reset_token WHERE used_at IS NULL')[0]['n'], 'A forged row was consumed.');
        self::assertSame([], $this->resetRows($tenant));
    }

    /**
     * **T-R5b (the real race; supporting evidence for MC-4, which is shown red on T-R5a):** an out-of-process holder on the
     * UNPOOLED endpoint (`hold_order_lock.php`, `HOLD_TABLE = password_reset_token`) locks the link and runs 7a for the
     * person; the HTTP redeem of the same link passes steps 3–6 (its `erp_auth` read does not see the uncommitted `used_at`)
     * and blocks in 7a; the holder sees the waiter and commits; under READ COMMITTED the request re-evaluates `used_at IS
     * NULL`, gets the empty set and answers 401 — the password unchanged. Never two in-process `erp_app` connections (the
     * pool serialises them before any row lock).
     */
    #[Test]
    public function twoRedeemsOfOneLinkSetOnePassword(): void
    {
        [$tenant, $user] = $this->liveUser();
        $token = $this->mint($tenant, $user);
        $linkId = (string) $this->fetchAsOwnerForTenant($tenant, 'SELECT id FROM password_reset_token WHERE user_id = ?::uuid', [$user])[0]['id'];
        $before = $this->credentialOf($tenant, $user);
        self::assertSame('read committed', $this->app()->fetchOne('SHOW transaction_isolation'));

        $holder = $this->raceAgainstAHolder($tenant, $linkId,
            [['UPDATE password_reset_token SET used_at = now() WHERE user_id = ?::uuid AND used_at IS NULL', [$user]]],
            function () use ($token): void {
                $this->redeem($token, self::NEW);
            });

        self::assertTrue($holder['waiter_seen'], 'The redeem never waited on the holder, so nothing here was a race: '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertSame(401, $this->httpStatus(), 'Two redeems of one link both succeeded.');
        self::assertSame($before, $this->credentialOf($tenant, $user));
        self::assertSame([], $this->resetRows($tenant));
    }

    /**
     * **T-R6:** a reset ends earlier sessions. Mock clock at T (behind real time; no failed sign-in here): a token signed in
     * at T dies after a redeem at T+1; a sign-in in the redeem's own second is refused once (the accepted cost, N-2); a
     * sign-in the second after reads. Mutation MC-7 (drop the stamp from 7b) → the T token still reads → red.
     */
    #[Test]
    public function aResetEndsEarlierSessions(): void
    {
        [$tenant, $user] = $this->liveUser();
        $clock = $this->mockAt(time() - 10);
        $earlier = $this->signedIn(self::OLD);
        self::assertSame(200, $this->me($earlier), 'Control: the earlier token did not read /me.');
        $token = $this->mint($tenant, $user);
        $clock->sleep(1);

        $this->redeem($token, self::NEW);
        self::assertSame(200, $this->httpStatus(), $this->responseContent());

        self::assertSame(401, $this->me($earlier), 'A session from before the reset survived it.');
        self::assertSame(401, $this->me($this->signedIn(self::NEW)), 'A sign-in in the reset\'s own second was not refused once (the named cost).');
        $clock->sleep(1);
        self::assertSame(200, $this->me($this->signedIn(self::NEW)), 'A sign-in the second after the reset is refused.');
    }

    /**
     * **T-R6b (final RED H-1):** a sign-in made while the new password is being hashed does not survive the reset. The mock
     * starts 10 s BEHIND real time (firebase checks `iat` against `time()`). The armed KDF seam moves the mock T → T+2 and
     * mints a token at T+2 — a session that started after `$now` but before the save. Assertions: (i) `changed_at` = T+2,
     * the non-vacuity control that the stamp was read after the KDF; (ii) the seam's token → 401; (iii) a control token at
     * T+3 → 200, so (ii) is the floor and not a broken token. MC-24 (stamp from step 1's `$now`) → (i) and (ii) red; MC-25
     * (stamp from the database's `now()`, ≈T+10) → (iii) red. RC-5: a frozen mock cannot tell "inside the step-7
     * callback" from "just before it" — this proves only "after the KDF".
     */
    #[Test]
    public function aSignInDuringTheRedeemDoesNotSurviveIt(): void
    {
        [$tenant, $user] = $this->liveUser();
        $t = time() - 10;
        $clock = $this->mockAt($t);
        $token = $this->mint($tenant, $user);
        $during = null;
        $this->kdf()->arm(function () use ($clock, $tenant, $user, &$during): void {
            $clock->sleep(2);
            $during = $this->accessTokenAt($tenant, $user, $clock->now()->getTimestamp());
        });

        $this->redeem($token, self::NEW);
        self::assertSame(200, $this->httpStatus(), $this->responseContent());

        self::assertSame($t + 2, (new \DateTimeImmutable((string) $this->json()['changed_at']))->getTimestamp(), '(i) The stamp was not read after the KDF.');
        self::assertIsString($during, 'The KDF seam never fired.');
        self::assertSame(401, $this->me($during), '(ii) A session that started while the new password was being hashed survived the reset.');
        self::assertSame(200, $this->me($this->accessTokenAt($tenant, $user, $t + 3)), '(iii) A session the second after the reset is refused.');
    }

    /** **T-R8:** a reset re-seals a row whose credential was tampered with by SQL — the copied seal refuses the old password; after the reset the new one signs in. */
    #[Test]
    public function aResetResealsATamperedRow(): void
    {
        [$tenant, $user] = $this->liveUser();
        $bob = $this->seedUser($tenant, 'bob@example.test', self::OLD);
        $this->executeAsOwnerForTenant($tenant, 'UPDATE app_user SET password_mac = (SELECT password_mac FROM app_user WHERE id = ?::uuid) WHERE id = ?::uuid', [$bob, $user]);
        $this->signIn(self::SLUG, self::EMAIL, self::OLD);
        self::assertSame(401, $this->httpStatus(), 'Control: a copied seal still signs in, so nothing here was tampered.');

        $this->redeem($this->mint($tenant, $user), self::NEW);
        self::assertSame(200, $this->httpStatus(), $this->responseContent());
        $this->signIn(self::SLUG, self::EMAIL, self::NEW);
        self::assertSame(200, $this->httpStatus(), 'The reset did not re-seal the tampered row.');
    }

    /** **T-R18:** a link whose MAC names another key id (a rotated key) is refused and consumes nothing (R-C2-9, `RESIDUAL_GAPS.md` G-88). */
    #[Test]
    public function aLinkSealedUnderAnotherKeyIsRefused(): void
    {
        [$tenant, $user] = $this->liveUser();
        $kid = $this->seal()->keyId();
        $other = 'ffffffff' === $kid ? '00000000' : 'ffffffff';
        $selector = bin2hex(random_bytes(16));
        $verifier = bin2hex(random_bytes(32));
        $created = time() - 1;
        $mac = $this->seal()->macVerifier($tenant, $user, 'reset', $selector, $created + 1800, $verifier);
        $this->executeAsOwnerForTenant($tenant, self::INSERT_LINK, [Uuid::v4()->toRfc4122(), $tenant, $user, 'reset', $selector,
            'v1.'.$other.substr($mac, 11), Uuid::v4()->toRfc4122(), $created, $created + 1800]);

        $this->redeem($this->tokenFrom($tenant, $selector, $verifier), self::NEW);
        self::assertSame(401, $this->httpStatus(), 'A link sealed under another key id worked.');
        self::assertSame(1, (int) $this->fetchAsOwnerForTenant($tenant, 'SELECT count(*) AS n FROM password_reset_token WHERE used_at IS NULL')[0]['n'], 'A refused link was consumed.');
    }

    /**
     * **T-R19 (R1.8):** a malformed token is never a server error. The guarded thrower is `TenantContext::establishOn()`'s
     * `Uuid::isValid` (a non-RFC tenant prefix), not `Uuid::fromString` (which accepts any 16 bytes). MC-13 (drop the gate
     * before the sentinel switch) → the non-variant row is a 500 → red.
     */
    #[Test]
    public function aMalformedTokenIsNeverAServerError(): void
    {
        [$tenant, $user] = $this->liveUser();
        $good = $this->mint($tenant, $user);
        foreach ([
            '85' => substr($good, 0, 85),
            '87' => $good.'A',
            'padding' => substr($good, 0, 84).'==',
            'plus' => '+'.substr($good, 1),
            'slash' => substr($good, 0, 85).'/',
            'non-variant tenant' => $this->tokenFrom('00000000-0000-4000-0000-000000000000', str_repeat('a', 32), str_repeat('b', 64)),
            'empty' => '',
            'one megabyte' => str_repeat('A', 1024 * 1024),
        ] as $case => $token) {
            $this->redeem($token, self::NEW);
            self::assertSame(401, $this->httpStatus(), "$case: ".substr($this->responseContent(), 0, 300));
        }
    }

    /** **T-R20:** the new password follows E-24, on pointer `/password`: 11 characters → 422, 12 → 200 (`PasswordRules`, shared with ChangePassword). */
    #[Test]
    public function theNewPasswordFollowsE24(): void
    {
        [$tenant, $user] = $this->liveUser();
        $token = $this->mint($tenant, $user);
        $this->redeem($token, str_repeat('x', 11));
        self::assertSame(422, $this->httpStatus());
        $errors = (array) ($this->json()['errors'] ?? []);
        self::assertSame(['/password'], array_values(array_unique(array_column($errors, 'pointer'))));
        self::assertContains('new_password_too_short', array_column($errors, 'code'));
        $this->redeem($token, str_repeat('x', 12));
        self::assertSame(200, $this->httpStatus(), $this->responseContent());
    }

    // ------------------------------------------------------------------------------------------------------------------

    private ?\Doctrine\DBAL\Connection $freshOwner = null;

    /**
     * **T-U9 (U-C1a; Mail-C2 carry items 1, 2):** an invitation link sets the FIRST password of a person who has never had
     * one; the trail says `{"purpose":"invitation"}`; the new password signs in. Mutation MU-11 (metadata hard-coded
     * `reset`) → red.
     */
    #[Test]
    public function anInvitationSetsTheFirstPassword(): void
    {
        [$tenant, $user] = $this->uncredentialedUser();
        $token = $this->mint($tenant, $user, expiresIn: 259200, purpose: 'invitation');

        $this->redeem($token, self::NEW);
        self::assertSame(200, $this->httpStatus(), $this->responseContent());
        $rows = $this->fetchAsOwnerForTenant($tenant, 'SELECT metadata FROM audit_log WHERE event_type = ?', [AuditEvent::AUTH_PASSWORD_RESET]);
        self::assertCount(1, $rows);
        self::assertSame(['purpose' => 'invitation'], json_decode((string) $rows[0]['metadata'], true));
        $this->signIn(self::SLUG, self::EMAIL, self::NEW);
        self::assertSame(200, $this->httpStatus(), 'The password an invitation set does not sign in.');
    }

    /**
     * **T-U9:** an invitation for a person who already has a credential is the one constant 401, refused at step 4 —
     * before the KDF runs (the armed KDF must never fire). Mutation MU-5 (accept `invitation` without the rule) → the KDF
     * runs (7b would still refuse, which is why the KDF is the observable) → red.
     */
    #[Test]
    public function anInvitationForSomeoneWithAPasswordIsRefusedBeforeTheKdf(): void
    {
        [$tenant, $user] = $this->liveUser();
        $token = $this->mint($tenant, $user, purpose: 'invitation');
        $hashed = false;
        $this->kdf()->arm(function () use (&$hashed): void {
            $hashed = true;
        });

        $this->redeem($token, self::NEW);

        self::assertSame(401, $this->httpStatus());
        self::assertFalse($hashed, 'An invitation for a credentialed person reached the KDF.');
        $this->signIn(self::SLUG, self::EMAIL, self::OLD);
        self::assertSame(200, $this->httpStatus());
    }

    /**
     * **T-U9 (7b TOCTOU):** a credential set while the invitation's KDF runs is never overwritten → 401, nothing changed.
     * Mutation MU-6 (drop 7b's invitation predicate) → 200 → red.
     */
    #[Test]
    public function aCredentialSetDuringAnInvitationRedeemIsKept(): void
    {
        [$tenant, $user, $mac] = $this->uncredentialedUser();
        $token = $this->mint($tenant, $user, purpose: 'invitation');
        $this->kdf()->arm(function () use ($tenant, $user, $mac): void {
            self::assertSame(1, $this->asFreshOwner($tenant, 'UPDATE app_user SET password_mac = ? WHERE id = ?::uuid', [$mac, $user]));
        });
        $this->redeem($token, self::NEW);

        self::assertSame(401, $this->httpStatus(), 'An invitation overwrote a credential set while it was being redeemed.');
        $after = $this->credentialOf($tenant, $user);
        self::assertSame($mac, $after['password_mac']);
        self::assertSame([], $this->resetRows($tenant));
    }

    /**
     * **T-U9 (Mail-C2 carry item 4):** a reset redeemed by a never-credentialed person also uses up their outstanding
     * invitation. Mutation MU-14 (7a only consumes `reset` links) → the invitation row stays unused → red.
     */
    #[Test]
    public function aResetRedeemUsesUpAnOutstandingInvitation(): void
    {
        [$tenant, $user] = $this->uncredentialedUser();
        $invitation = $this->mint($tenant, $user, expiresIn: 259200, purpose: 'invitation');
        $reset = $this->mint($tenant, $user);

        $this->redeem($reset, self::NEW);
        self::assertSame(200, $this->httpStatus(), $this->responseContent());
        $open = $this->fetchAsOwnerForTenant($tenant, "SELECT count(*) AS n FROM password_reset_token WHERE purpose = 'invitation' AND used_at IS NULL");
        self::assertSame(0, (int) $open[0]['n'], 'The invitation survived the reset.');
        $this->redeem($invitation, self::NEW.'2');
        self::assertSame(401, $this->httpStatus());
    }

    /** **T-U9 (owner Q-3; Mail-C2 carry item 5):** an invitation minted before the person's latest session floor is dead. */
    #[Test]
    public function anInvitationMintedBeforeTheLatestLivenessChangeIsDead(): void
    {
        [$tenant, $user] = $this->uncredentialedUser();
        $token = $this->mint($tenant, $user, expiresIn: 259200, createdAt: time() - 5, purpose: 'invitation');
        $this->executeAsOwnerForTenant($tenant, 'UPDATE app_user SET sessions_valid_after = to_timestamp(?) WHERE id = ?::uuid', [time() - 2, $user]);
        $this->redeem($token, self::NEW);
        self::assertSame(401, $this->httpStatus(), 'An invitation minted before the latest session floor still worked.');
    }

    /** @return array{0: string, 1: string, 2: string} tenant, a live sealed user whose credential seal was removed, and that seal */
    private function uncredentialedUser(): array
    {
        [$tenant, $user] = $this->liveUser();
        $mac = (string) $this->credentialOf($tenant, $user)['password_mac'];
        $this->executeAsOwnerForTenant($tenant, 'UPDATE app_user SET password_mac = NULL WHERE id = ?::uuid', [$user]);

        return [$tenant, $user, $mac];
    }

    protected function tearDown(): void
    {
        $this->kdf()->disarm();
        $this->freshOwner?->close();
        parent::tearDown();
    }

    private function kdf(): \App\Tests\Support\ArmableKdf
    {
        $kdf = self::getContainer()->get(\App\Tests\Support\ArmableKdf::class);
        \assert($kdf instanceof \App\Tests\Support\ArmableKdf);

        return $kdf;
    }

    /** @param list<mixed> $params @return int affected rows, on a fresh owner client in the tenant's context, committed */
    private function asFreshOwner(string $tenant, string $sql, array $params): int
    {
        return $this->executeOnClientForTenant($this->freshOwner ??= $this->freshOwnerClient(), $tenant, $sql, $params);
    }

    /** @return array<string, mixed> */
    private function credentialOf(string $tenant, string $user): array
    {
        return $this->fetchAsOwnerForTenant($tenant, 'SELECT password_hash, password_mac, sessions_valid_after FROM app_user WHERE id = ?::uuid', [$user])[0];
    }

    /** @return list<array<string, mixed>> */
    private function resetRows(string $tenant): array
    {
        return $this->fetchAsOwnerForTenant($tenant, 'SELECT id FROM audit_log WHERE event_type = ?', [AuditEvent::AUTH_PASSWORD_RESET]);
    }

    /** @return array{0: string, 1: string} */
    private function liveUser(): array
    {
        $tenant = $this->seedTenant(self::SLUG, Tenant::STATUS_ACTIVE);
        $user = $this->seedUser($tenant, self::EMAIL, self::OLD);
        $this->sealIdentity($tenant, $user, self::EMAIL);

        return [$tenant, $user];
    }

    private function sealIdentity(string $tenant, string $user, string $email): void
    {
        $this->executeAsOwnerForTenant($tenant, 'UPDATE app_user SET identity_mac = ? WHERE id = ?::uuid', [$this->seal()->sealIdentity($tenant, $user, $email), $user]);
    }

    private function seal(): SealsIdentities
    {
        $seal = self::getContainer()->get('test.auth.identity_seal');
        \assert($seal instanceof SealsIdentities);

        return $seal;
    }

    /** A real keyed link row, as the worker would mint it (owner, tenant context set), and its mailed token. */
    private function mint(string $tenant, string $user, int $expiresIn = 1800, bool $store = true, ?int $createdAt = null, ?\Doctrine\DBAL\Connection $client = null, string $purpose = 'reset'): string
    {
        $selector = bin2hex(random_bytes(16));
        $verifier = bin2hex(random_bytes(32));
        $created = $createdAt ?? (time() - 1);
        $expires = $created + $expiresIn;
        if ($expiresIn < 0) {
            $created = time() - 3600;
            $expires = time() + $expiresIn;
        }
        $params = [Uuid::v4()->toRfc4122(), $tenant, $user, $purpose, $selector,
            $this->seal()->macVerifier($tenant, $user, $purpose, $selector, $expires, $verifier), Uuid::v4()->toRfc4122(), $created, $expires];
        if ($store && null !== $client) {
            self::assertSame(1, $this->executeOnClientForTenant($client, $tenant, self::INSERT_LINK, $params));
        } elseif ($store) {
            $this->executeAsOwnerForTenant($tenant, self::INSERT_LINK, $params);
        }

        return $this->tokenFrom($tenant, $selector, $verifier);
    }

    /**
     * Every invalid link of §7, each for its own person so no case consumes another's: malformed shapes, an unknown
     * selector, a wrong verifier, a foreign-kid MAC, expired, used, a purpose not redeemable, an inactive person, a removed
     * person, a workspace not active, an identity seal that no longer matches, and (owner Q-3) a link minted before the
     * person's latest session floor. Seeds the live workspace `self::SLUG` with `self::EMAIL` as well.
     *
     * @return array<string, string>
     */
    private function badTokens(): array
    {
        [$tenant, $user] = $this->liveUser();
        $person = function (string $name, bool $active = true, ?string $inTenant = null) use ($tenant): string {
            $t = $inTenant ?? $tenant;
            $id = $this->seedUser($t, $name.'@example.test', self::OLD, $active);
            $this->sealIdentity($t, $id, $name.'@example.test');

            return $id;
        };
        $good = $this->mint($tenant, $user);

        $used = $person('used');
        $usedToken = $this->mint($tenant, $used);
        $this->executeAsOwnerForTenant($tenant, 'UPDATE password_reset_token SET used_at = now() WHERE user_id = ?::uuid', [$used]);

        $removed = $this->seedUserWithId($tenant, Uuid::v4()->toRfc4122(), 'removed@example.test', self::OLD, true);
        $this->sealIdentity($tenant, $removed, 'removed@example.test');

        $suspended = $this->seedTenant(self::SLUG.'-suspended', Tenant::STATUS_SUSPENDED);

        $moved = $person('moved');
        $movedToken = $this->mint($tenant, $moved);
        $this->executeAsOwnerForTenant($tenant, 'UPDATE app_user SET email = ? WHERE id = ?::uuid', ['elsewhere@example.test', $moved]);

        $floored = $person('floored');
        $flooredToken = $this->mint($tenant, $floored, createdAt: time() - 5);
        $this->executeAsOwnerForTenant($tenant, 'UPDATE app_user SET sessions_valid_after = to_timestamp(?) WHERE id = ?::uuid', [time() - 2, $floored]);

        $rotated = $person('rotated');
        $selector = bin2hex(random_bytes(16));
        $verifier = bin2hex(random_bytes(32));
        $created = time() - 1;
        $mac = $this->seal()->macVerifier($tenant, $rotated, 'reset', $selector, $created + 1800, $verifier);
        $kid = $this->seal()->keyId();
        $this->executeAsOwnerForTenant($tenant, self::INSERT_LINK, [Uuid::v4()->toRfc4122(), $tenant, $rotated, 'reset', $selector,
            'v1.'.('ffffffff' === $kid ? '00000000' : 'ffffffff').substr($mac, 11), Uuid::v4()->toRfc4122(), $created, $created + 1800]);

        return [
            'short' => substr($good, 0, 85),
            'long' => $good.'A',
            'padding' => substr($good, 0, 84).'==',
            'plus' => '+'.substr($good, 1),
            'non-variant tenant' => $this->tokenFrom('00000000-0000-4000-0000-000000000000', str_repeat('a', 32), str_repeat('b', 64)),
            'unknown selector' => $this->mint($tenant, $user, store: false),
            'wrong verifier' => substr($good, 0, 43).('A' === $good[43] ? 'B' : 'A').substr($good, 44),
            'foreign kid' => $this->tokenFrom($tenant, $selector, $verifier),
            'expired' => $this->mint($tenant, $person('expired'), expiresIn: -60),
            'used' => $usedToken,
            // U-C1a: an invitation is redeemable only while the person has no credential; this person has one.
            'invitation, person already credentialed' => $this->mint($tenant, $person('invited'), purpose: 'invitation'),
            'inactive person' => $this->mint($tenant, $person('inactive', active: false)),
            'removed person' => $this->mint($tenant, $removed),
            'suspended workspace' => $this->mint($suspended, $person('suspended', inTenant: $suspended)),
            'identity mismatch' => $movedToken,
            'minted before the floor' => $flooredToken,
        ];
    }

    private function tenantOf(string $slug): string
    {
        return (string) $this->owner()->fetchOne('SELECT id FROM tenant WHERE slug = ?', [$slug]);
    }

    private function userOf(string $email): string
    {
        return (string) $this->fetchAsOwnerForTenant($this->tenantOf(self::SLUG), 'SELECT id FROM app_user WHERE email = ?', [$email])[0]['id'];
    }

    private function mockAt(int $second): MockClock
    {
        \assert($second <= time(), 'Mock at or behind real time (firebase checks iat with time()).');
        $clock = self::mockTime(new \DateTimeImmutable('@'.$second));
        \assert($clock instanceof MockClock);

        return $clock;
    }

    private function signedIn(string $password): string
    {
        $this->signIn(self::SLUG, self::EMAIL, $password);
        self::assertSame(200, $this->httpStatus(), $this->responseContent());

        return (string) $this->json()['access_token'];
    }

    private function accessTokenAt(string $tenant, string $user, int $issuedAt): string
    {
        \assert($issuedAt <= time());
        $issuer = self::getContainer()->get('test.auth.token_issuer');
        \assert($issuer instanceof AccessTokenIssuer);

        return $issuer->issue($tenant, $user, new \DateTimeImmutable('@'.$issuedAt))->jwt;
    }

    private function me(string $token): int
    {
        $this->send('GET', '/me', null, ['Authorization' => 'Bearer '.$token]);

        return $this->httpStatus();
    }

    /**
     * T-R5b's holder, in `PurchaseOrderRowLockTest::raceAgainstAHolder()`'s shape: the out-of-process holder takes the link's
     * row lock and runs `$before` on the unpooled endpoint, signals readiness, waits until a backend is blocked by it, then
     * commits. Waits on conditions, never on a clock (the deadline is for the failing case only).
     *
     * @param list<array{0: string, 1: list<string>}> $before
     *
     * @return array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string}
     */
    private function raceAgainstAHolder(string $tenant, string $linkId, array $before, callable $request): array
    {
        $readyFile = tempnam(sys_get_temp_dir(), 'erp_reset_lock_');
        file_put_contents($readyFile, '');
        $proc = proc_open(
            [PHP_BINARY, \dirname(__DIR__).'/Api/support/hold_order_lock.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [
                'APP_DIRECT_DATABASE_URL' => $_ENV['APP_DIRECT_DATABASE_URL'],
                'HOLD_TENANT' => $tenant,
                'HOLD_ORDER' => $linkId,
                'HOLD_TABLE' => 'password_reset_token',
                'HOLD_BEFORE' => json_encode($before, \JSON_THROW_ON_ERROR),
                'HOLD_AFTER' => '[]',
                'HOLD_READY_FILE' => $readyFile,
                'HOLD_DEADLINE_SECONDS' => (string) self::HOLDER_DEADLINE_SECONDS,
                'PATH' => (string) getenv('PATH'),
            ],
        );
        self::assertIsResource($proc);
        try {
            $deadline = microtime(true) + self::HOLDER_DEADLINE_SECONDS;
            while ('' === trim((string) file_get_contents($readyFile))) {
                if (microtime(true) > $deadline) {
                    self::fail('The holder never took the lock: '.stream_get_contents($pipes[2]));
                }
                usleep(1000);
            }
            if ('failed' === trim((string) file_get_contents($readyFile))) {
                self::fail('The holder failed before taking the lock: '.stream_get_contents($pipes[1]).stream_get_contents($pipes[2]));
            }
            $request();
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
        } finally {
            if (proc_get_status($proc)['running']) {
                proc_terminate($proc);
            }
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($proc);
            @unlink($readyFile);
        }
        $report = json_decode(trim($out), true);
        self::assertIsArray($report, 'The holder printed no report. stdout: '.$out.' stderr: '.$err);

        return $report;
    }

    private function tokenFrom(string $tenant, string $selectorHex, string $verifierHex): string
    {
        return rtrim(strtr(base64_encode(Uuid::fromString($tenant)->toBinary().hex2bin($selectorHex).hex2bin($verifierHex)), '+/', '-_'), '=');
    }

    private function redeem(string $token, string $password, string $ip = '203.0.113.7'): void
    {
        $this->client()->setServerParameter('REMOTE_ADDR', $ip);
        $this->send('POST', '/auth/password-resets/redemption', ['token' => $token, 'password' => $password]);
    }

    /** @return array{status: int, body: string, headers: array<string, mixed>} */
    private function answer(): array
    {
        $response = $this->client()->getResponse();
        $headers = $response->headers->all();
        unset($headers['date'], $headers['x-debug-token'], $headers['x-debug-token-link']);
        ksort($headers);

        return ['status' => $response->getStatusCode(), 'body' => (string) $response->getContent(), 'headers' => $headers];
    }
}

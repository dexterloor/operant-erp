<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Audit\AuditEvent;
use App\Auth\Application\ChangePassword;
use App\Auth\Application\PasswordChangeThrottle;
use App\Auth\Infrastructure\AuthTrailWriter;
use App\Entity\Tenant;
use PHPUnit\Framework\Attributes\Test;

/**
 * **E-23 — `POST /api/v1/me/password`.**
 *
 * ===========================================================================
 * What E-23 asks for, and which method answers which clause
 * ===========================================================================
 * `SECURITY_MODEL.md` §5's E-23 names three tests by name and this file is them,
 * plus the four the *operation* needs that the register does not reach:
 *
 *   `::oneChangeWritesExactlyOneSecurityRow`      E-23 clause 1
 *   `::theEventIsSubjectlessAndOnTheSecuritySide` E-23 clause 2
 *   `::itsMetadataIsEmpty`                        E-23 clause 3
 *   `::theAuditRowIsWrittenInsideTheTransactionThatChangesTheCredential`
 *                                                 §9.2's synchronous clause
 *   `::theKdfRunsExactlyOnceOnEveryPathThatReachesIt`
 *                                                 the E-7 ruling in `ChangePassword`
 *   `::theThrottleRefusesTheActionAndSuppressesNoRecord`
 *                                                 E-23's *"throttle the action"*
 *   `::theOperationIsAuthenticatedThoughItDeclaresNoCode`
 *                                                 §4.2's `—` rule
 *   `::theTokenThatMadeTheChangeStillWorksAfterwards`
 *                                                 API-O-13 / API-O-21, pinned
 *
 * ===========================================================================
 * Every classification assertion reads the constants; none restates them
 * ===========================================================================
 * E-23's own wording asks for the subject test to work *"reading the three
 * subject declarations and the two classification constants rather than
 * restating them"*. So this file contains **no literal `'auth.password.changed'`
 * anywhere** — it goes through `AuditEvent::AUTH_PASSWORD_CHANGED` — and the
 * side-of-the-split assertions are set operations over `AuditEvent::SECURITY`,
 * `::BUSINESS`, `::SUBJECT_TYPES`, `::SUBJECTLESS` and
 * `::SUBJECT_RESOLVED_AT_WRITE`.
 *
 * **The independent pin lives elsewhere and that is deliberate**, so this file
 * cannot both make and check the claim: PC-11 (4b)'s pin is
 * `AuditEventCatalogueTest::PINNED_ALLOWLIST` (which was **not** edited when this
 * event landed — that is what makes the `SECURITY` filing falsifiable), and
 * API-16's is `AuditWriterTest::PINNED_IP_BEARING_EVENTS` (which was).
 */
final class PasswordChangeTest extends AuthTestCase
{
    private const SLUG = self::SLUG_PREFIX.'credential';

    private const EMAIL = 'holder@example.test';

    private const PASSWORD = 'the-password-this-fixture-starts-with';

    private const PROBE_IP = '198.51.100.9';

    private string $tenantId;

    private string $userId;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = $this->seedTenant(self::SLUG, Tenant::STATUS_ACTIVE);
        $this->userId = $this->seedUser($this->tenantId, self::EMAIL, self::PASSWORD);
        $this->token = $this->tokenFor($this->tenantId, $this->userId);

        // Seeding hashed a password. `CountingPasswordVerifier` deliberately does
        // not count `hash()`, but resetting here means every invocation figure
        // below is measured from the start of the request under test whatever the
        // fixture did.
        $this->hasher()->reset();
    }

    /**
     * **E-23 clause 1: a successful change writes exactly one row, and the change
     * is real.**
     *
     * The *"the change is real"* half is not decoration. A handler that wrote the
     * audit row and forgot the `UPDATE` would satisfy every row-shaped assertion
     * in this file, so the new credential is driven back through `IssueToken` and
     * the old one is driven through it too — which is also the only place the
     * suite can see that `AppUser::replacePasswordHash()` reached the database.
     *
     * *Mutation, run:* delete the `$this->trail->record(...)` call from
     * `ChangePassword`; the row count goes to zero and **five** methods in this
     * file are red — measured — because four others read the trail as well.
     * *Second mutation, run:* delete `$user->replacePasswordHash($hash)` and keep
     * the audit write; the row assertions all still hold and the sign-in half
     * goes red — which is what tells you the two halves are checking different
     * things.
     */
    #[Test]
    public function oneChangeWritesExactlyOneSecurityRow(): void
    {
        $newPassword = 'a-completely-different-secret';

        $this->changePassword(self::PASSWORD, $newPassword);

        self::assertSame(200, $this->httpStatus(), 'A valid change did not answer 200: '.$this->bodyForFailure());
        self::assertArrayHasKey('changed_at', $this->json());
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339, (string) $this->json()['changed_at']),
            '`changed_at` is not RFC 3339.');

        $rows = $this->credentialTrail();

        self::assertCount(1, $rows, 'E-23: a successful credential change writes exactly one audit_log row.');
        self::assertSame($this->userId, (string) $rows[0]['actor_user_id']);
        self::assertSame('Fixture User', (string) $rows[0]['actor_label'],
            'actor_label is not the stored display name. audit_log.actor_label is NOT NULL so the trail stays '
            .'readable after the principal is deleted.');
        self::assertSame(self::PROBE_IP, (string) $rows[0]['ip_address'],
            'API-16 / E-23: this is a security event and carries the observed address.');

        // The credential actually moved, in both directions.
        $this->signIn(self::SLUG, self::EMAIL, $newPassword);
        self::assertSame(200, $this->httpStatus(), 'The new password does not authenticate, so nothing was changed.');

        $this->signIn(self::SLUG, self::EMAIL, self::PASSWORD);
        self::assertSame(401, $this->httpStatus(), 'The old password still authenticates.');
    }

    /**
     * **E-23 clause 2, read off the catalogue rather than restated.**
     *
     * Two halves, and both are needed: the **declaration** (which lists the
     * constant appears in) and the **row** (what the writer actually wrote). A
     * declaration nothing writes to is the shape this project has found more than
     * a dozen times, and a row nothing declares is how `role.assigned` sat on the
     * allowlist through a green suite.
     *
     * *Mutation, run.* `AUTH_PASSWORD_CHANGED` moved from `AuditEvent::SECURITY`
     * into `AuditEvent::BUSINESS`: **five methods across three files go red**, and
     * the list is written out because the count was measured rather than guessed —
     * `PasswordChangeTest::theEventIsSubjectlessAndOnTheSecuritySide`,
     * `AuditEventCatalogueTest::theAllowlistIsExactlyThePinnedMembership`
     * (PC-11 (4b)'s pin), `AuditEventCatalogueTest::noAdmittedEventNamesAppUserAs`
     * `ItsSubject` ((4c), which refuses an admitted event with no `SUBJECT_TYPES`
     * entry), `AuditWriterTest::thePinAndTheConstantAgreeInBothDirections`
     * (API-16's pin), and `AuditWriterTest::onlySecurityEventsCarryAnIpAddress`
     * (the event now writes an IP on the business side of the split). **None of
     * the four outside this file can be quieted from inside it**, which is the
     * property the two independent pins were built for.
     * *Second mutation, run:* declare it in `SUBJECT_TYPES` as `app_user` as well
     * as in `SUBJECTLESS`; `AuditEventCatalogueTest::everyEventDeclaresItsSubject`
     * `ExactlyOnce` is red, and this method's `SUBJECT_TYPES` clause is red too.
     */
    #[Test]
    public function theEventIsSubjectlessAndOnTheSecuritySide(): void
    {
        $event = AuditEvent::AUTH_PASSWORD_CHANGED;

        self::assertContains($event, AuditEvent::SECURITY,
            'PC-11 (4a): an event about a principal\'s credentials is not admitted to `.view_record`.');
        self::assertNotContains($event, AuditEvent::BUSINESS,
            'The credential-change event is on the colleague-readable allowlist.');

        self::assertContains($event, AuditEvent::SUBJECTLESS,
            'E-13: an `auth.*` event is about a principal and the principal is already `actor_user_id`.');
        self::assertArrayNotHasKey($event, AuditEvent::SUBJECT_TYPES,
            'PC-11 (4c) and E-23: declaring a subject here would put a colleague\'s credential history on '
            .'/admin/users/[id] the day anybody moved the event across the split.');
        self::assertNotContains($event, AuditEvent::SUBJECT_RESOLVED_AT_WRITE);

        // And what the writer actually wrote, which is the half a declaration
        // cannot promise. `audit_log_subject_pair` permits both-or-neither, so a
        // half-populated pair would have been refused by the database; a *fully*
        // populated one would not.
        $this->changePassword(self::PASSWORD, 'another-secret-entirely');
        self::assertSame(200, $this->httpStatus(), $this->bodyForFailure());

        $rows = $this->credentialTrail();
        self::assertCount(1, $rows);
        self::assertNull($rows[0]['subject_type'], 'E-13: subject_type must be NULL on this event.');
        self::assertNull($rows[0]['subject_id'], 'E-13: subject_id must be NULL on this event.');
    }

    /**
     * **E-23 clause 3 — a pin at *empty*, not a denylist.**
     *
     * E-23 forbids *"any credential-derived value"* in `metadata`: the password, a
     * hash, a prefix, a length, a strength score, a *"same as previous"* flag. A
     * denylist over `password`-shaped key names would enumerate the ones somebody
     * thought of; the key set is pinned at **empty**, so it is red on any added
     * key at all.
     *
     * *Mutation, run:* add a `metadata` parameter to `PasswordChangeAuditWriter`
     * and write `{"length": 14}`; this is red naming the key — where a denylist
     * over `password`-shaped names would have stayed green. *Second mutation,
     * run:* write `{"changed_by": "self"}`, which is not credential-derived at all
     * and is exactly the innocuous-looking addition a denylist admits; also red.
     */
    #[Test]
    public function itsMetadataIsEmpty(): void
    {
        $this->changePassword(self::PASSWORD, 'yet-another-secret-value');
        self::assertSame(200, $this->httpStatus(), $this->bodyForFailure());

        $rows = $this->credentialTrail();
        self::assertCount(1, $rows);

        $metadata = json_decode((string) $rows[0]['metadata'], true);

        self::assertIsArray($metadata, 'metadata is not a JSON object.');
        self::assertSame([], array_keys($metadata),
            'E-23: `metadata` carries a key. On an append-only table no role can UPDATE or DELETE, a value '
            .'written there is written permanently. Keys found: '.implode(', ', array_keys($metadata)));
    }

    /**
     * **§9.2's synchronous clause, proved on the statement stream rather than
     * asserted from the code's shape.**
     *
     * *"Audit is synchronous and inside the tenancy transaction — its failure must
     * roll back the change it records."* Transaction boundaries are not visible to
     * `SqlRecorder` (the middleware wraps `prepare`/`query`/`exec`, not
     * `beginTransaction`), so the observable used is the one statement that marks
     * a **newly opened** context: `TenantContext::openContext()` issues exactly one
     * `set_config('app.tenant_id', …)` per transaction it opens, and a re-entrant
     * join issues none.
     *
     * So the claim is: between the `UPDATE app_user` and the
     * `INSERT INTO audit_log`, **no context is established**, which can only be
     * true if the insert is inside the transaction the update is in.
     *
     * *Mutation, run:* move `$this->trail->record(...)` out of the
     * `$this->tenants->run(...)` closure in `ChangePassword`, one line down. The
     * writer then opens its own transaction, a `set_config` appears between the
     * two statements, and this is red — while every other test in this file stays
     * green, which is the point of measuring it here.
     */
    #[Test]
    public function theAuditRowIsWrittenInsideTheTransactionThatChangesTheCredential(): void
    {
        $recorder = $this->recorderFor('app');
        $recorder->start();

        $this->changePassword(self::PASSWORD, 'a-secret-worth-recording');

        $recorder->stop();
        self::assertSame(200, $this->httpStatus(), $this->bodyForFailure());

        $statements = $recorder->statements();

        $update = $this->indexOfFirst($statements, '/UPDATE\s+app_user/i');
        $insert = $this->indexOfFirst($statements, '/INSERT\s+INTO\s+audit_log/i');

        self::assertNotNull($update, 'No UPDATE on app_user was issued on the application connection.');
        self::assertNotNull($insert, 'No INSERT INTO audit_log was issued on the application connection.');
        self::assertGreaterThan($update, $insert, 'The audit row was written before the credential moved.');

        $between = \array_slice($statements, $update + 1, $insert - $update - 1);
        $opened = array_values(array_filter($between,
            static fn (string $sql): bool => 1 === preg_match('/set_config\(\s*\'app\.tenant_id\'/i', $sql)));

        self::assertSame([], $opened,
            'A tenant context was established between the credential UPDATE and the audit INSERT, which means '
            .'the audit write opened its own transaction. §9.2 requires the audit write to be inside the '
            .'transaction it records, so its failure rolls the change back.');
    }

    /**
     * **The E-7 ruling in `ChangePassword`, and the half of E-7 this endpoint
     * keeps: the KDF runs exactly once on every path that reaches it.**
     *
     * Verifying the current password is a second credential-comparison surface.
     * Indistinguishability is *not* carried over — the caller is already resolved
     * as this principal, so the response says which of their own inputs was wrong
     * — but the **invocation count** is, and it is the half with teeth: it makes
     * the "cheap" ordering (check the new password first, return early)
     * structurally unavailable, so a wrong-current-password probe costs the server
     * exactly what a correct one does.
     *
     * All four combinations are driven, because a test covering only the two
     * *current-password* branches would stay green under precisely the mutation
     * this exists to catch.
     *
     * *Mutation, run:* in `ChangePassword`, move the `newPasswordErrors()` call
     * above the `verify()` call and return early when it is non-empty. The two
     * bad-new-password rows go to **0** invocations and this is red naming them.
     * *Second mutation, run — the one that shows the count is not incidental:*
     * short-circuit `verify()` when the stored hash is null. Every row here stays
     * green (the fixture principal always resolves), and
     * `SignInPathTest`/`TokenEndpointIndistinguishabilityTest` go red instead —
     * recorded so the limit of this method is on the record: it measures *this*
     * endpoint's ordering, not the verifier's own dummy-hash rule.
     */
    #[Test]
    public function theKdfRunsExactlyOnceOnEveryPathThatReachesIt(): void
    {
        $cases = [
            'correct current, valid new' => [self::PASSWORD, 'first-replacement-secret'],
            'wrong current, valid new' => ['not-the-password', 'second-replacement-secret'],
            'correct current, empty new' => [self::PASSWORD, ''],
            'wrong current, empty new' => ['not-the-password', ''],
        ];

        // The first case succeeds, so it has to run last or it would change the
        // credential the other three present. Ordered explicitly rather than left
        // to the array's order, because that dependency is invisible otherwise.
        $order = ['wrong current, valid new', 'correct current, empty new', 'wrong current, empty new', 'correct current, valid new'];

        foreach ($order as $label) {
            [$current, $new] = $cases[$label];

            $this->hasher()->reset();
            $this->changePassword($current, $new);

            self::assertSame(1, $this->hasher()->invocations(),
                \sprintf('"%s": the KDF ran %d times, not once. A path that skips it is a path whose cost '
                    .'differs by branch, which is the short-circuit `ChangePassword` rules out.',
                    $label, $this->hasher()->invocations()));
        }
    }

    /**
     * **Both refusals are reported together, which is the response-level face of
     * the no-short-circuit rule.**
     *
     * A request that is wrong in both ways gets both pointers. If the new-password
     * check ran first and returned, the caller would see one error, fix it, and
     * only then learn the current password was wrong — and the endpoint's cost
     * would depend on which check failed.
     *
     * *Mutation, run:* return from `newPasswordErrors()`'s branch before the
     * current-password error is unshifted; the `/current_password` pointer
     * disappears and this is red.
     */
    #[Test]
    public function aRefusalNamesEveryInputThatWasWrongAndChangesNothing(): void
    {
        $this->changePassword('not-the-password', '');

        self::assertSame(422, $this->httpStatus(), $this->bodyForFailure());
        self::assertStringContainsString('application/problem+json', $this->contentType());

        $body = $this->json();
        self::assertSame('urn:erp:problem:validation-failed', $body['type'] ?? null);

        $errors = $body['errors'] ?? [];
        self::assertIsArray($errors);

        $pointers = array_column($errors, 'pointer');
        $codes = array_column($errors, 'code');

        self::assertContains('/current_password', $pointers, 'The wrong current password was not reported.');
        self::assertContains('/new_password', $pointers, 'The empty new password was not reported.');
        self::assertContains(ChangePassword::CURRENT_PASSWORD_INVALID, $codes);
        self::assertContains(ChangePassword::NEW_PASSWORD_EMPTY, $codes);

        self::assertSame([], $this->credentialTrail(),
            'A refused change wrote an audit_log row. E-23 records changes that happened, and nothing happened.');

        // Nothing moved.
        $this->signIn(self::SLUG, self::EMAIL, self::PASSWORD);
        self::assertSame(200, $this->httpStatus(), 'The original password no longer authenticates after a refusal.');
    }

    /**
     * **E-23's *"throttle the action; never suppress the record"*, and the
     * assertion is deliberately about both halves.**
     *
     * The ceiling's worth of changes all succeed **and all leave rows** — that is
     * the anti-vacuity half, and without it a throttle that refused everything
     * would pass the 429 assertion. The next one is refused with
     * `too-many-attempts` and the row count **does not move**, which is the
     * asymmetry with E-3a: E-3a suppresses the record and lets the action through,
     * because a denial cannot be refused; this refuses the action and keeps every
     * record of something that actually happened.
     *
     * *Mutation, run:* make `PasswordChangeThrottle::admits()` return `true`
     * unconditionally; the last request answers 200, the row count reaches
     * ceiling + 1, and this is red on both clauses.
     * *Second mutation, run — and it is the one that matters, because the first is
     * satisfied by any refusal:* leave the throttle alone and have
     * `ChangePasswordProcessor` map `Throttled` to `ApiProblem::validationFailed([])`.
     * The row count still holds; the status assertion goes red on 422 against 429.
     */
    #[Test]
    public function theThrottleRefusesTheActionAndSuppressesNoRecord(): void
    {
        $ceiling = PasswordChangeThrottle::CHANGES_PER_WINDOW;
        $current = self::PASSWORD;

        for ($i = 1; $i <= $ceiling; ++$i) {
            $next = 'rotation-'.$i.'-secret';
            $this->changePassword($current, $next);

            self::assertSame(200, $this->httpStatus(),
                \sprintf('Change %d of %d was refused, so the throttle assertion below would hold over a '
                    .'writer that never worked: %s', $i, $ceiling, $this->bodyForFailure()));

            self::assertCount($i, $this->credentialTrail(),
                'A successful change did not leave its row. E-23 forbids suppressing the record of a change '
                .'that happened.');

            $current = $next;
        }

        $this->changePassword($current, 'one-rotation-too-many');

        self::assertSame(429, $this->httpStatus(), $this->bodyForFailure());
        self::assertSame('urn:erp:problem:too-many-attempts', $this->json()['type'] ?? null);

        self::assertCount($ceiling, $this->credentialTrail(),
            'The throttled request wrote a row. It refused the action, so there is nothing to record.');

        // And it refused the *action*, not merely the response: the password the
        // refused request offered must not authenticate.
        $this->signIn(self::SLUG, self::EMAIL, 'one-rotation-too-many');
        self::assertSame(401, $this->httpStatus(), 'The throttled change was applied anyway.');
    }

    /**
     * **§4.2's `—` is not "public", on the row that most looks like it might be.**
     *
     * `PermissionEnforcementContractTest::anOperationWithNoCodeIsNotThereforePublic`
     * makes this claim over the whole `—` set but drives only `GetCurrentUser`.
     * This drives the other authenticated member of that set. §4.2's rule is that
     * the JWT requirement is decided by the row's own `Path`, and `/me/password`
     * is not under `/auth/`.
     *
     * *Mutation, run:* change `PasswordResource`'s `uriTemplate` to
     * `/auth/me/password`. `AuthenticationGate::isPreAuthentication()` then returns
     * true, the request reaches the processor with no identity,
     * `RequestIdentity::authenticated()` throws, and this is red on 500 against
     * 401 — a *louder* failure than the silent one, which is the fail-closed
     * behaviour that holder was written for.
     */
    #[Test]
    public function theOperationIsAuthenticatedThoughItDeclaresNoCode(): void
    {
        $this->send('POST', '/me/password', [
            'current_password' => self::PASSWORD,
            'new_password' => 'should-never-be-applied',
        ]);

        self::assertSame(401, $this->httpStatus(), $this->bodyForFailure());
        self::assertSame('urn:erp:problem:authentication-failed', $this->json()['type'] ?? null);
        self::assertSame([], $this->credentialTrail());

        $this->signIn(self::SLUG, self::EMAIL, self::PASSWORD);
        self::assertSame(200, $this->httpStatus(), 'An unauthenticated request changed the credential.');
    }

    /**
     * **API-9 / B-3 at runtime: the accepted-field list is exhaustive.**
     *
     * §4.2's `Accepted request fields` cell is the subject of §3.3's check, and
     * that check is document-level. `allow_extra_attributes: false` is what makes
     * the cell true of the running endpoint — most sharply on a credential
     * operation, where a silently-ignored `user_id` would read to a caller as an
     * administrative reset that quietly changed their own password instead.
     *
     * *Mutation, run:* remove `allow_extra_attributes` from the operation's
     * `denormalizationContext`; the request is accepted, the change succeeds, and
     * this is red on 200 against 422.
     */
    #[Test]
    public function anUndeclaredFieldIsRefused(): void
    {
        $this->send('POST', '/me/password', [
            'current_password' => self::PASSWORD,
            'new_password' => 'a-perfectly-good-secret',
            'user_id' => $this->userId,
        ], ['Authorization' => 'Bearer '.$this->token], 'application/json');

        self::assertSame(422, $this->httpStatus(), $this->bodyForFailure());
        self::assertSame([], $this->credentialTrail(), 'A refused request wrote a row.');
    }

    /**
     * **The residual, pinned: a password change revokes nothing.**
     *
     * There is no session store (**API-O-13**) and E-20 makes the access token an
     * absolute eight hours with no revocation, so the token that made the change
     * keeps working — as does every other live token for this principal, for the
     * remainder of its lifetime. `SECURITY_MODEL.md` §5.1.1 states the consequence
     * in terms; §4.2's Authentication prose and `PasswordResource`'s docblock say
     * it where a client reads it.
     *
     * **This test asserts the residual rather than the fix, and that is on
     * purpose.** Building revocation needs the session table D-14 declined and
     * E4-1's migration slot does not authorise, so the honest thing a suite can do
     * today is make the consequence visible instead of leaving it as a sentence in
     * a document nobody diffs.
     *
     * *Mutation — and it is the useful direction:* the day anybody implements
     * revocation, this method goes red. That is the intended failure: it forces
     * the residual to be closed **in the documents and in this file, deliberately**,
     * rather than a stale API-O-13 outliving the gap it describes.
     */
    #[Test]
    public function theTokenThatMadeTheChangeStillWorksAfterwards(): void
    {
        $this->changePassword(self::PASSWORD, 'a-secret-that-revokes-nothing');
        self::assertSame(200, $this->httpStatus(), $this->bodyForFailure());

        $this->send('GET', '/me', null, ['Authorization' => 'Bearer '.$this->token]);

        self::assertSame(200, $this->httpStatus(),
            'The token used to change the password stopped working. That would be revocation, which '
            .'API-O-13 records as impossible today — if it has been built, close API-O-13 and rewrite this.');
    }

    /**
     * **The pre-authentication trail writer refuses this event, and that guard
     * had no test until this method.**
     *
     * E-23 names `erp_app` as the principal and says the `erp_authlog` principal
     * *"is not reachable here"*. `AuthTrailWriter` writes on `erp_authlog` and
     * admitted anything matching `auth.` **and** `AuditEvent::SUBJECTLESS` — which
     * `auth.password.changed` does. So on the day the constant landed, the only
     * thing stopping a *"this credential moved"* row being written from an
     * unauthenticated path was that nobody called it that way. The prefix was
     * narrowed to `auth.login.`; this is what makes that a guard rather than a
     * comment.
     *
     * *Mutation, run:* restore the prefix to `auth.`. The pre-auth writer accepts
     * the event and this is red — **and nothing else in the suite changes
     * colour**, which is exactly why the narrowing needed its own test rather
     * than being left as a tightening somebody could revert on a tidy-up.
     */
    #[Test]
    public function thePreAuthenticationTrailWriterRefusesThisEvent(): void
    {
        $writer = self::getContainer()->get(AuthTrailWriter::class);
        \assert($writer instanceof AuthTrailWriter);

        // The three sign-in events it does write, so this is not a test that
        // would pass over a writer that refuses everything.
        $accepted = 0;

        foreach (AuditEvent::SUBJECTLESS as $event) {
            if (!str_starts_with($event, 'auth.login.')) {
                continue;
            }

            $writer->record($event, $this->tenantId, $this->userId, 'Fixture User', self::PROBE_IP);
            ++$accepted;
        }

        self::assertGreaterThan(0, $accepted, 'The writer wrote nothing at all, so the refusal below is vacuous.');

        $this->expectException(\InvalidArgumentException::class);
        $writer->record(AuditEvent::AUTH_PASSWORD_CHANGED, $this->tenantId, $this->userId, 'Fixture User', self::PROBE_IP);
    }

    private function changePassword(string $current, string $new): void
    {
        $this->client()->setServerParameter('REMOTE_ADDR', self::PROBE_IP);

        // The header is written explicitly on every call. `AuthTestCase::setUp()`
        // clears the rig's default bearer, because §7's directory is about which
        // credential a request carries and a default would make the
        // no-credential case unwritable.
        $this->send('POST', '/me/password', [
            'current_password' => $current,
            'new_password' => $new,
        ], ['Authorization' => 'Bearer '.$this->token]);
    }

    /**
     * The credential-change rows only. Read as the owner, because `erp_app`'s
     * `SELECT` is under RLS and this assertion should not depend on the reader's
     * tenant context being what the writer's was.
     *
     * @return list<array<string, mixed>>
     */
    private function credentialTrail(): array
    {
        return $this->fetchAsOwnerForTenant($this->tenantId, <<<'SQL'
            SELECT event_type, actor_user_id, actor_label, subject_type, subject_id,
                   host(ip_address) AS ip_address, metadata::text AS metadata
            FROM audit_log
            WHERE event_type = ?
            ORDER BY occurred_at, id
            SQL, [AuditEvent::AUTH_PASSWORD_CHANGED]);
    }

    /** @param list<string> $statements */
    private function indexOfFirst(array $statements, string $pattern): ?int
    {
        foreach ($statements as $index => $sql) {
            if (1 === preg_match($pattern, $sql)) {
                return $index;
            }
        }

        return null;
    }

    /** The response body, so a status assertion that fails says why rather than only what. */
    private function bodyForFailure(): string
    {
        return substr((string) $this->client()->getResponse()->getContent(), 0, 400);
    }
}

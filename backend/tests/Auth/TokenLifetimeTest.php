<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use ApiPlatform\Metadata\HttpOperation;
use App\Modules\UserManagement\Infrastructure\AccessTokenIssuer;
use App\Platform\Tenancy\Entity\Tenant;
use App\Tests\Api\TransactionBoundaryTest;
use App\Tests\Support\ContractDocument;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **E-20** — *"the access token is one credential with an absolute lifetime of
 * eight hours from issuance, and nothing extends it."*
 *
 * §5.1.1's *fails if*: *"a minted token's `exp` is not exactly eight hours after
 * its `iat`; **or** any operation issues a token without the password among its
 * accepted request fields."*
 */
final class TokenLifetimeTest extends AuthTestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private const EMAIL = 'alice@example.com';

    /**
     * Read off the minted token rather than off the constant, so this is a claim
     * about what a client receives.
     *
     * *Mutation, run:* set `AccessTokenIssuer::LIFETIME_SECONDS` to fifteen minutes;
     * red.
     */
    #[Test]
    public function theAccessTokenExpiresEightHoursAfterIssue(): void
    {
        $slug = self::SLUG_PREFIX.'lifetime';
        $tenantId = $this->seedTenant($slug, Tenant::STATUS_ACTIVE);
        $this->seedUser($tenantId, self::EMAIL, self::PASSWORD);

        $this->signIn($slug, self::EMAIL, self::PASSWORD);
        self::assertSame(200, $this->httpStatus());

        $body = $this->json();
        [, $payload] = explode('.', (string) $body['access_token'], 3);
        /** @var array<string, mixed> $claims */
        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true, 512, \JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('iat', $claims);
        self::assertArrayHasKey('exp', $claims);
        self::assertSame(8 * 60 * 60, $claims['exp'] - $claims['iat'], 'exp is not iat + 8h.');
        self::assertSame(AccessTokenIssuer::LIFETIME_SECONDS, $claims['exp'] - $claims['iat']);

        // The advertised expiry is the claim, not a second calculation.
        self::assertSame(
            (new \DateTimeImmutable('@'.$claims['exp']))->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::RFC3339),
            $body['expires_at'],
        );
    }

    /**
     * E-20's *"no path mints a token from a token"*, as a **derived** set.
     *
     * §5.1.1 is explicit about why the derivation matters: naming `IssueToken`
     * by hand *"is how a sliding window arrives under a new name"*. The domain
     * here is every shipped operation whose response declares an
     * `access_token` member, read off the registry and the output DTO — so an
     * operation added tomorrow that returns a token is in scope without anybody
     * editing this file.
     *
     * **And the set is asserted non-empty before anything is asserted about its
     * members.** A derivation whose domain silently collapses to zero is the
     * fake-green this project keeps finding; *deriving is necessary and not
     * sufficient.*
     *
     * *Mutation, run:* add a `Post` operation returning `TokenResource` whose
     * input declares only a bearer token. The derived set gains a member whose
     * accepted fields lack `password`; red — where a test naming `IssueToken`
     * would have stayed green.
     *
     * *Second mutation, run:* delete `password` from `IssueTokenInput`; red.
     */
    #[Test]
    public function everyTokenIssuingOperationCostsThePassword(): void
    {
        $issuing = [];

        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            \assert($operation instanceof HttpOperation);

            $output = $operation->getOutput()['class'] ?? $operation->getClass();

            if (\is_string($output) && \in_array('access_token', self::serializedFields($output), true)) {
                $issuing[$name] = $operation->getInput()['class'] ?? null;
            }
        }

        self::assertNotSame([], $issuing,
            'No shipped operation returns an access token, so this check has no subject. Deriving is '
            .'necessary and not sufficient — a derivation must also prove it found something.');

        foreach ($issuing as $name => $input) {
            self::assertIsString($input, $name.' issues a token and declares no request body at all, so it cannot cost a password.');
            self::assertContains('password', self::serializedFields($input),
                $name.' issues a token without the password among its accepted request fields. '
                .'E-20: a new token costs the password, whatever the operation is named.');
        }
    }

    /**
     * The document side of the same rule.
     *
     * Every `Token` row in §4.2 is in one of two states — **absent from the
     * shipped registry**, or **present and costing a password**. The domain is
     * pinned exactly: since 2026-09-14 §4.2 declares **only `IssueToken`**,
     * because the owner withdrew `RefreshToken` (with `RevokeSession`) from the
     * table (D-14, ruling C). A Token row reappearing — a refresh operation
     * re-declared without reopening D-14 and E-20 — is red at the pin, whether
     * or not it ships. **So today the loop below only ever visits `IssueToken`**:
     * it is red when §4.2 stops putting `password` among `IssueToken`'s accepted
     * fields, and it can see no other row.
     *
     * **This test reads the document, not the registry.** A *shipped*
     * `RefreshToken` with no §4.2 row is invisible here. Since `da44513` that
     * case is caught elsewhere — see the last mutation below.
     *
     * Mutations (C5, 2026-09-14; `docs/API.md` restored byte-identical, sha256
     * `8da02630…`, after each):
     *  - Re-add a `RefreshToken | Refresh / Token | POST | /auth/token/refresh |
     *    — | no | refresh_token | …` row after `IssueToken` in §4.2 → RED at the
     *    pin, `§4.2 no longer declares exactly one Token row, IssueToken…`,
     *    diff `+ 'RefreshToken'`.
     *  - Delete `` `password` `` from `IssueToken`'s accepted-fields cell → RED
     *    in the loop, `IssueToken is shipped and §4.2 does not put `password`
     *    among its accepted fields.`
     *  - **Ship** a `RefreshToken` (`Post /auth/token/refresh` on
     *    `TokenResource`, input declaring only `refresh_token`;
     *    `backend/var/cache/test` deleted before the mutated run and before the
     *    revert run — Rule 17) → **this test GREEN**. Red instead:
     *    `everyTokenIssuingOperationCostsThePassword` above ("RefreshToken
     *    issues a token without the password…"),
     *    `AuthOperationContractTest::theRegistryDeclaresExactlyThePinnedAuthOperations`
     *    (the `/auth/` pin gains `/auth/token/refresh`) and
     *    `::theShippedSurfaceIsExactlyTheOperationsThisWaveHasPinned` (at its
     *    union assertion, `+ 'RefreshToken'` — which fires before that method's
     *    "neither ships" loop, so the loop is not what caught it).
     *  - Each revert: GREEN.
     *
     * *The previous mutation sentence here — "implement a `RefreshToken`
     * operation; red" — stopped reaching this test when `da44513` withdrew the
     * row: it was the loop that caught it, and the loop no longer sees it.*
     */
    #[Test]
    public function everyTokenRowInTheContractIsEitherUnshippedOrCostsThePassword(): void
    {
        if (!ContractDocument::isAvailable()) {
            self::markTestSkipped('docs/API.md is gitignored and absent from this checkout, so §4.2 has no reader here. The registry-derived half above still ran.');
        }

        $rows = ContractDocument::operationRows();
        self::assertGreaterThan(50, \count($rows), 'The §4.2 parser found almost nothing, so every comparison below would be vacuous.');

        $tokenRows = array_filter($rows, static function (array $row): bool {
            [, $resource] = array_map(trim(...), explode('/', $row['verbResource'], 2));

            return 'Token' === $resource;
        });

        $tokenRowIds = array_keys($tokenRows);
        sort($tokenRowIds);
        self::assertSame(['IssueToken'], $tokenRowIds,
            '§4.2 no longer declares exactly one Token row, IssueToken. RefreshToken was withdrawn from §4.2 '
            .'on 2026-09-14 (D-14, owner ruling C); a Token row returning needs a ruling that reopens D-14 and '
            .'E-20, and a Token row vanishing means the domain below has no subject.');

        $shipped = array_keys(TransactionBoundaryTest::contractOperations());

        foreach ($tokenRows as $operationId => $row) {
            if (!\in_array($operationId, $shipped, true)) {
                continue;
            }

            self::assertContains('password', ContractDocument::acceptedFields($operationId),
                $operationId.' is shipped and §4.2 does not put `password` among its accepted fields.');
        }
    }

    /**
     * E-20's *"no session store and therefore no revocation"* (API-O-13), stated
     * as the schema fact it rests on rather than as a note.
     *
     * *Mutation, run:* add a `session` entry to `SchemaManifest::TABLES`; red,
     * and deliberately so — a session table is a D-14 decision with its own
     * condition, not something an implementation adds.
     */
    #[Test]
    public function thereIsNoSessionStoreForATokenToBeRevokedAgainst(): void
    {
        $declared = array_keys(\App\Platform\Tenancy\SchemaManifest::TABLES);
        self::assertNotSame([], $declared);

        $sessionish = array_values(array_filter(
            $declared,
            static fn (string $table): bool => str_contains($table, 'session') || str_contains($table, 'refresh_token'),
        ));

        self::assertSame([], $sessionish,
            'D-14 ships no session table this wave, and E4-1\'s migration slot is scoped to the audit '
            .'catalogue: '.implode(', ', $sessionish));
    }

    /**
     * The serialized field names a DTO declares, honouring `#[SerializedName]`.
     *
     * @return list<string>
     */
    private static function serializedFields(string $class): array
    {
        if (!class_exists($class)) {
            return [];
        }

        $fields = [];

        foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();

            foreach ($property->getAttributes(SerializedName::class) as $attribute) {
                // The property and not `getSerializedName()`: the accessor is
                // deprecated in symfony/serializer 7.4, and `failOnDeprecation`
                // is on in phpunit.dist.xml.
                $name = $attribute->newInstance()->serializedName;
            }

            $fields[] = $name;
        }

        return $fields;
    }
}

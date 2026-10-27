<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Tenancy\Entity\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`API.md` §4.3a's two named tests — an account `CreateUser` makes cannot authenticate until its holder sets a password**
 * (U-C1b, T-U1; the names are §4.3a's, specified since phase 2 and built with the operation). `password_hash` is a real
 * KDF output over a discarded 32-byte secret and `password_mac` is NULL, so sign-in fails closed — and the KDF still runs,
 * so the branch costs what a wrong password costs. Mutation MU-1 (`''` hash and a short-circuit on it) → the invocation
 * count goes to zero → red.
 */
final class UserProvisioningTest extends AuthTestCase
{
    private const SLUG = self::SLUG_PREFIX.'provision';

    private const NEW = 'new-person@example.test';

    /** The empty string, a random string and the stored hash itself: each the ordinary wrong-password answer. */
    #[Test]
    public function aCreatedAccountCannotAuthenticate(): void
    {
        $tenant = $this->createdAccount();
        $stored = (string) $this->fetchAsOwnerForTenant($tenant, 'SELECT password_hash FROM app_user WHERE email = ?', [self::NEW])[0]['password_hash'];
        self::assertStringStartsWith('$argon2id$', $stored, 'The created account\'s hash is not a KDF output.');

        $this->seedUser($tenant, 'known@example.test', 'the known password');
        $this->signIn(self::SLUG, 'known@example.test', 'not the known password', '203.0.113.20');
        $ordinary = [$this->httpStatus(), $this->responseContent()];
        self::assertSame(401, $ordinary[0]);

        foreach (['empty' => '', 'random' => bin2hex(random_bytes(16)), 'the stored hash' => $stored] as $case => $password) {
            $this->signIn(self::SLUG, self::NEW, $password, '203.0.113.21');
            self::assertSame($ordinary, [$this->httpStatus(), $this->responseContent()], "The created account answered \"$case\" differently from a wrong password.");
        }
    }

    /** The KDF runs on a created account's sign-in, as on any other (§4.3a item 3; E-7's clock argument). */
    #[Test]
    public function theHasherIsInvokedOnACreatedAccountsLoginAttempt(): void
    {
        $this->createdAccount();
        $this->hasher()->reset();
        $this->signIn(self::SLUG, self::NEW, 'any password at all');
        self::assertSame(401, $this->httpStatus());
        self::assertGreaterThanOrEqual(1, $this->hasher()->invocations(), 'No KDF ran on the created account\'s sign-in.');
    }

    private function createdAccount(): string
    {
        $tenant = $this->seedTenant(self::SLUG, Tenant::STATUS_ACTIVE);
        $this->actAsUser($tenant, $this->seedPrincipal($tenant, DefaultRoles::codesFor(DefaultRoles::ADMIN)));
        $this->send('POST', '/users', ['email' => self::NEW, 'display_name' => 'New Person'], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), $this->responseContent());
        $this->actAsNobody();

        return $tenant;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Modules\UserManagement\Infrastructure\EmailHasher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * **V1-S-12 (V4 B3c, 2026-09-16): `EmailHasher` refuses a key shorter than 32 bytes.**
 *
 * `auth_lockout.email_hash` is an HMAC-SHA256 of every attempted address, so the table is safe to keep only while the key is
 * one a dictionary attack cannot reach. `AccessTokenVerifier` already refused a signing key under 32 bytes; this class had
 * no floor, so an empty or short `AUTH_EMAIL_HASH_KEY` turned the table — and every backup of `erp_ratelimit`'s rows — into a
 * reversible directory of who tried to sign in.
 *
 * The boundary pair is inside one test: 31 bytes throws, 32 constructs and hashes.
 *
 * *Mutation:* remove the floor from `EmailHasher::__construct()` → the 31-byte case is red.
 */
final class EmailHasherTest extends TestCase
{
    #[Test]
    public function aKeyShorterThan32BytesIsRefusedAnd32BytesIsAccepted(): void
    {
        $hasher = new EmailHasher(str_repeat('k', 32));
        self::assertSame(32, \strlen($hasher->hash('alice@example.com')),
            'The positive control did not construct and hash, so the refusal below proves nothing.');

        try {
            new EmailHasher(str_repeat('k', 31));
            self::fail('A 31-byte AUTH_EMAIL_HASH_KEY was accepted. The lockout table\'s address hashes are only as strong as this key.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('AUTH_EMAIL_HASH_KEY', $e->getMessage());
            self::assertStringNotContainsString(str_repeat('k', 31), $e->getMessage(), 'The refusal must not echo the key (Standing Rule 10).');
        }

        $this->expectException(\InvalidArgumentException::class);
        new EmailHasher('');
    }
}

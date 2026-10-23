<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Modules\UserManagement\Domain\SealCheck;
use App\Modules\UserManagement\Infrastructure\CredentialSeal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * **T-C1 — the seal's encoding, fixed** (Cred-C1; design §3.1, §3.4; Cred-C1 RED D-3).
 *
 * The vector below was computed by an **independent implementation** (Python `hmac`/`hashlib`, RFC 5869 HKDF with the
 * zero salt PHP's `hash_hkdf` uses), not by this class, so a change to the info string `erp.credential.v1`, the
 * `v1.cred\0` prefix, the `\0` separators, the uuid form, the kid label or the output shape goes red here. The stored
 * seals of every user depend on that encoding: changing it silently would lock everyone out.
 */
final class CredentialSealTest extends TestCase
{
    /** base64 of the bytes 0x00…0x1f. A test key, nothing else. */
    private const KEY = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

    private const TENANT = '11111111-1111-4111-8111-111111111111';

    private const USER = '22222222-2222-4222-8222-222222222222';

    private const HASH = '$argon2id$v=19$m=1024,t=3,p=1$c2FsdHNhbHQ$aGFzaGhhc2hoYXNo';

    private const KNOWN_SEAL = 'v1.c4c9b72b.def743ee496f3a7d4b8286cef69e7e1aad2131eaaa6b6ef143bcd0321c516eb3';

    #[Test]
    public function theEncodingMatchesAnIndependentImplementation(): void
    {
        $seal = new CredentialSeal(self::KEY);

        self::assertSame('c4c9b72b', $seal->keyId());
        self::assertSame(self::KNOWN_SEAL, $seal->seal(self::TENANT, self::USER, self::HASH));
        self::assertSame(self::KNOWN_SEAL, $seal->seal(strtoupper(self::TENANT), strtoupper(self::USER), self::HASH),
            'The uuids are not canonicalised before sealing, so one row could carry two valid seals.');
        self::assertSame(SealCheck::Verified, $seal->check(self::TENANT, self::USER, self::HASH, self::KNOWN_SEAL));
        self::assertMatchesRegularExpression('/^v1\.[0-9a-f]{8}\.[0-9a-f]{64}$/', self::KNOWN_SEAL, 'The shape app_user_password_mac_shape admits.');
    }

    #[Test]
    public function aSealBindsTheTenantTheUserAndTheHash(): void
    {
        $seal = new CredentialSeal(self::KEY);
        $other = '33333333-3333-4333-8333-333333333333';

        foreach ([[$other, self::USER, self::HASH], [self::TENANT, $other, self::HASH], [self::TENANT, self::USER, self::HASH.'x']] as $label => [$t, $u, $h]) {
            self::assertNotSame(self::KNOWN_SEAL, $seal->seal($t, $u, $h), "Changing part $label of the tuple did not change the seal.");
            self::assertSame(SealCheck::Mismatch, $seal->check($t, $u, $h, self::KNOWN_SEAL), "A seal verified for a different tuple ($label): a copied hash or seal would sign in.");
        }
        self::assertNotSame(self::KNOWN_SEAL, (new CredentialSeal(base64_encode(str_repeat('z', 32))))->seal(self::TENANT, self::USER, self::HASH), 'A different key made the same seal.');
    }

    /** Final RED L-8 / M-1: NULL, empty, malformed and wrong-under-this-key are mismatches; another key id is told apart. */
    #[Test]
    public function verificationFailsClosed(): void
    {
        $seal = new CredentialSeal(self::KEY);
        $wrong = 'v1.c4c9b72b.'.str_repeat('0', 64);
        $foreign = 'v1.'.('deadbeef' === $seal->keyId() ? 'feedface' : 'deadbeef').'.'.substr(self::KNOWN_SEAL, 12);

        foreach ([null, '', 'v1', 'garbage', $wrong, strtoupper(self::KNOWN_SEAL), self::KNOWN_SEAL.'0'] as $stored) {
            self::assertSame(SealCheck::Mismatch, $seal->check(self::TENANT, self::USER, self::HASH, $stored), 'A stored value verified or was told apart: '.var_export($stored, true));
        }
        self::assertSame(SealCheck::UnknownKey, $seal->check(self::TENANT, self::USER, self::HASH, $foreign));
    }

    #[Test]
    public function aWeakOrMalformedKeyIsRefusedWithoutEchoingIt(): void
    {
        foreach ([base64_encode(str_repeat('k', 31)), '', 'not base64 at all!', 'kkkk'] as $key) {
            try {
                new CredentialSeal($key);
                self::fail('A key that is not base64 of at least 32 bytes was accepted.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('AUTH_CREDENTIAL_MAC_KEY', $e->getMessage());
                if ('' !== $key) {
                    self::assertStringNotContainsString($key, $e->getMessage(), 'Standing Rule 10: the refusal repeated the key.');
                }
            }
        }
    }

    /**
     * The purposes this class derives keys for, as an exact list (Cred-C1 RED D-3). Mail-C1 adds `erp.identity.v1` and must
     * then extend this test with a cross-purpose vector (a seal under one purpose never verifies under another) rather
     * than forget it.
     */
    #[Test]
    public function theDerivationPurposesAreExactlyTheseTwo(): void
    {
        $constants = (new \ReflectionClass(CredentialSeal::class))->getConstants();
        $infos = array_filter($constants, static fn (string $name): bool => str_ends_with($name, '_INFO'), \ARRAY_FILTER_USE_KEY);

        self::assertSame(['CREDENTIAL_INFO' => 'erp.credential.v1', 'KEY_ID_INFO' => 'erp.kid.v1'], $infos);
        $source = (string) file_get_contents((string) (new \ReflectionClass(CredentialSeal::class))->getFileName());
        self::assertSame(1, substr_count($source, 'hash_hkdf('), 'A second HKDF derivation appeared: add its purpose to the list above and its cross-purpose vector.');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Modules\UserManagement\Domain\SealsIdentities;
use App\Modules\UserManagement\Infrastructure\Console\CheckCredentialKeyCommand;
use App\Modules\UserManagement\Infrastructure\CredentialSeal;
use App\Platform\Tenancy\Entity\Tenant;
use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\ValueObject\AuditEvent;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * **`app:credentials:check-key` tells a wrong key from a planted key id** (Cred-C1; design Response to RED M-5; final RED
 * M-1, L-10; Cred-C1 RED D-5). In-kernel (`CommandTester`), as `ReconcileRolesCommandTest` is: the command runs on the
 * application connection, so what is asserted is its verdict and that it prints counts, never a seal.
 *
 * *Mutations:* exit 0 on a mixed state (the drafted rule "fail only when none carries the current key id") → the second
 * case is 0 → red; compare against the stored kid of the first row instead of the configured one → red.
 */
final class CheckCredentialKeyCommandTest extends AuthTestCase
{
    #[Test]
    public function itPassesASoundStoreAndFlagsAWrongKeyAndAPlantedKeyIdApart(): void
    {
        $a = $this->seedTenant(self::SLUG_PREFIX.'key-a', Tenant::STATUS_ACTIVE);
        $b = $this->seedTenant(self::SLUG_PREFIX.'key-b', Tenant::STATUS_ACTIVE);
        $one = $this->seedUser($a, 'one@example.test', 'password-number-one');
        $two = $this->seedUser($a, 'two@example.test', 'password-number-two');
        $three = $this->seedUser($b, 'three@example.test', 'password-number-three');
        $seal = (string) $this->fetchAsOwnerForTenant($a, 'SELECT password_mac FROM app_user WHERE id = ?::uuid', [$one])[0]['password_mac'];
        $keyId = substr($seal, 3, 8);
        $foreign = 'deadbeef' === $keyId ? 'feedface' : 'deadbeef';
        $replant = "UPDATE app_user SET password_mac = 'v1.' || ? || substr(password_mac, 12) WHERE id = ?::uuid";

        [$code, $output] = $this->check();
        self::assertSame(Command::SUCCESS, $code, $output);
        self::assertStringContainsString('Configured key id: '.$keyId, $output);
        self::assertStringContainsString('2 under this key, 0 under another key id, 0 with no seal', $output);
        self::assertStringNotContainsString(substr($seal, 12), $output, 'The command printed a seal.');

        // One planted key id among sound seals: a possible tamper, not a deploy mistake.
        $this->executeAsOwnerForTenant($b, $replant, [$foreign, $three]);
        [$code, $output] = $this->check();
        self::assertSame(CheckCredentialKeyCommand::MIXED_KEYS, $code, $output);

        // Every seal under another key id: this server's key is wrong.
        $this->executeAsOwnerForTenant($a, $replant, [$foreign, $one]);
        $this->executeAsOwnerForTenant($a, $replant, [$foreign, $two]);
        [$code, $output] = $this->check();
        self::assertSame(CheckCredentialKeyCommand::WRONG_KEY, $code, $output);

        // A row with no seal is reported and does not fail a sound store.
        $this->executeAsOwnerForTenant($a, $replant, [$keyId, $one]);
        $this->executeAsOwnerForTenant($a, $replant, [$keyId, $two]);
        $this->executeAsOwnerForTenant($b, 'UPDATE app_user SET password_mac = NULL WHERE id = ?::uuid', [$three]);
        [$code, $output] = $this->check();
        self::assertSame(Command::SUCCESS, $code, $output);
        self::assertStringContainsString('0 under this key, 0 under another key id, 1 with no seal', $output);
    }

    /**
     * **T-K1 (Mail-C1, T-G75-5): full verification, not a key-id prefix.** A seal under the configured key id whose MAC is
     * wrong → 2; a wrong identity seal → 2; a sound store with both seals → 0. Mutation: compare prefixes only → the
     * wrong-MAC case is 0 → red.
     */
    #[Test]
    public function aSealWithTheRightKeyIdButTheWrongMacIsAPossibleTamper(): void
    {
        $tenant = $this->seedTenant(self::SLUG_PREFIX.'key-mac', Tenant::STATUS_ACTIVE);
        $one = $this->seedUser($tenant, 'one@example.test', 'password-number-one');
        $two = $this->seedUser($tenant, 'two@example.test', 'password-number-two');
        foreach ([[$one, 'one@example.test'], [$two, 'two@example.test']] as [$id, $email]) {
            $this->executeAsOwnerForTenant($tenant, 'UPDATE app_user SET identity_mac = ? WHERE id = ?::uuid', [$this->identitySeal()->sealIdentity($tenant, $id, $email), $id]);
        }
        [$code, $output] = $this->check();
        self::assertSame(Command::SUCCESS, $code, $output);
        self::assertStringContainsString('identities: 2 verified, 0 unverified, 0 with no seal', $output);

        $keyId = $this->identitySeal()->keyId();
        $sound = (string) $this->fetchAsOwnerForTenant($tenant, 'SELECT password_mac FROM app_user WHERE id = ?::uuid', [$one])[0]['password_mac'];
        $this->executeAsOwnerForTenant($tenant, 'UPDATE app_user SET password_mac = ? WHERE id = ?::uuid', ['v1.'.$keyId.'.'.str_repeat('0', 64), $one]);
        [$code, $output] = $this->check();
        self::assertSame(CheckCredentialKeyCommand::MIXED_KEYS, $code, 'A forged seal carrying the right key id passed: '.$output);

        $this->executeAsOwnerForTenant($tenant, "UPDATE app_user SET password_mac = NULL, identity_mac = ? WHERE id = ?::uuid", ['v1.'.$keyId.'.'.str_repeat('1', 64), $two]);
        $this->executeAsOwnerForTenant($tenant, 'UPDATE app_user SET password_mac = ? WHERE id = ?::uuid', [$sound, $one]);
        [$code, $output] = $this->check();
        self::assertSame(CheckCredentialKeyCommand::MIXED_KEYS, $code, 'A wrong identity seal passed: '.$output);
        self::assertStringNotContainsString('two@example.test', $output, 'The command printed an address.');
    }

    /**
     * **T-K1 (T-D1): the wipe check.** A user with NO password seal is a possible wipe if they ever signed in
     * (`last_login_at`) or the trail holds a credential event for them with no later revocation → 2. A never-activated
     * account (no seal, no event, never signed in) → 0. The revocation half runs the command's own SQL with a PLANTED
     * revocation member, because none exists before Cred-C2 and the catalogue CHECK refuses an unknown one; the command's
     * lists are pinned so the real ones are what the SQL is built from. Mutation MU-20 (drop the trail signal) → red.
     */
    #[Test]
    public function aSealRemovedFromAnAccountThatWasInUseIsAPossibleWipe(): void
    {
        // Moved deliberately at Mail-C2: a redeemed reset link is a credential in use (carry item 6).
        self::assertSame([AuditEvent::AUTH_LOGIN_SUCCESS, AuditEvent::AUTH_PASSWORD_CHANGED, AuditEvent::AUTH_PASSWORD_RESET], CheckCredentialKeyCommand::CREDENTIAL_EVENTS);
        self::assertSame([], CheckCredentialKeyCommand::REVOCATION_EVENTS, 'Cred-C2 added a revocation member: drop the planted one below and use it.');

        $tenant = $this->seedTenant(self::SLUG_PREFIX.'key-wipe', Tenant::STATUS_ACTIVE);
        $invited = $this->seedUser($tenant, 'invited@example.test', 'password-number-one');
        $this->executeAsOwnerForTenant($tenant, 'UPDATE app_user SET password_mac = NULL WHERE id = ?::uuid', [$invited]);
        [$code, $output] = $this->check();
        self::assertSame(Command::SUCCESS, $code, 'A never-activated account was reported as wiped: '.$output);

        $signedIn = $this->seedUser($tenant, 'signedin@example.test', 'password-number-two');
        $this->executeAsOwnerForTenant($tenant, 'UPDATE app_user SET password_mac = NULL, last_login_at = now() WHERE id = ?::uuid', [$signedIn]);
        [$code, $output] = $this->check();
        self::assertSame(CheckCredentialKeyCommand::MIXED_KEYS, $code, 'A wiped seal on an account that signed in passed: '.$output);
        self::assertStringContainsString('1 possible wipe(s)', $output);
        $this->executeAsOwnerForTenant($tenant, 'UPDATE app_user SET last_login_at = NULL WHERE id = ?::uuid', [$signedIn]);

        // The trail signal alone (last_login_at cleared too, as an attacker with UPDATE on it would).
        $this->trailRow($tenant, $signedIn, AuditEvent::AUTH_LOGIN_SUCCESS, '2026-10-01 10:00:00+00');
        [$code, $output] = $this->check();
        self::assertSame(CheckCredentialKeyCommand::MIXED_KEYS, $code, 'A wiped seal with a sign-in in the trail passed: '.$output);

        // The revocation half, with a planted member standing in for Cred-C2's.
        $planted = [AuditEvent::USER_DEACTIVATED];
        $count = fn (): int => (int) $this->fetchAsOwnerForTenant($tenant, CheckCredentialKeyCommand::wipeCountSql(CheckCredentialKeyCommand::CREDENTIAL_EVENTS, $planted))[0]['count'];
        self::assertSame(1, $count());
        $this->trailRow($tenant, $signedIn, AuditEvent::USER_DEACTIVATED, '2026-10-02 10:00:00+00');
        self::assertSame(0, $count(), 'A revocation after the last sign-in still counted as a wipe.');
        $this->trailRow($tenant, $signedIn, AuditEvent::AUTH_LOGIN_SUCCESS, '2026-10-03 10:00:00+00');
        self::assertSame(1, $count(), 'A sign-in after the revocation was not counted.');
    }

    /**
     * **T-R22 (Mail-C2):** a wiped seal on an account whose only credential event is a redeemed reset link is a possible
     * wipe → 2. Mutation MC-20 (drop the member from `CREDENTIAL_EVENTS`) → red.
     */
    #[Test]
    public function aWipedSealAfterAResetIsFlagged(): void
    {
        $tenant = $this->seedTenant(self::SLUG_PREFIX.'key-reset', Tenant::STATUS_ACTIVE);
        $user = $this->seedUser($tenant, 'reset@example.test', 'password-number-one');
        $this->executeAsOwnerForTenant($tenant, 'UPDATE app_user SET password_mac = NULL WHERE id = ?::uuid', [$user]);
        [$code, $output] = $this->check();
        self::assertSame(Command::SUCCESS, $code, 'Control: a never-activated account was reported as wiped: '.$output);

        $this->trailRow($tenant, $user, AuditEvent::AUTH_PASSWORD_RESET, '2026-10-01 10:00:00+00');
        [$code, $output] = $this->check();
        self::assertSame(CheckCredentialKeyCommand::MIXED_KEYS, $code, 'A wiped seal after a redeemed reset passed: '.$output);
    }

    /**
     * **T-K1 (T-G75-5, M-7): PREVIOUS and the declared key id.** A store sealed partly under the current key and partly
     * under a previous one is 2 without `AUTH_CREDENTIAL_MAC_KEY_PREVIOUS` and 0 with it (and says sign-in does not accept
     * it yet); a declared id that is not the key's is exit 3 before anything is read, even on a store with nothing in it.
     * Mutation MU-19 (exit 3 only when sealed rows exist) and MU-36 (build PREVIOUS like the current seal) → red.
     */
    #[Test]
    public function aMidRotationStoreNeedsPreviousAndAWrongKeyIdIsExitThree(): void
    {
        $previousKey = base64_encode(str_repeat('p', 32));
        $previous = new CredentialSeal($previousKey);
        $tenant = $this->seedTenant(self::SLUG_PREFIX.'key-rot', Tenant::STATUS_ACTIVE);
        $this->seedUser($tenant, 'current@example.test', 'password-number-one');
        $old = $this->seedUser($tenant, 'old@example.test', 'password-number-two');
        $hash = (string) $this->fetchAsOwnerForTenant($tenant, 'SELECT password_hash FROM app_user WHERE id = ?::uuid', [$old])[0]['password_hash'];
        $this->executeAsOwnerForTenant($tenant, 'UPDATE app_user SET password_mac = ? WHERE id = ?::uuid', [$previous->seal($tenant, $old, $hash), $old]);

        [$code, $output] = $this->checkWith(null, null);
        self::assertSame(CheckCredentialKeyCommand::MIXED_KEYS, $code, $output);
        [$code, $output] = $this->checkWith(null, $previousKey);
        self::assertSame(Command::SUCCESS, $code, 'A store sealed under the current and the previous key failed with PREVIOUS set: '.$output);
        self::assertStringContainsString('sign-in does not accept it until Cred-C2', $output);

        $this->owner()->executeStatement('DELETE FROM app_user WHERE tenant_id = ?::uuid', [$tenant]);
        $wrong = 'deadbeef' === $this->identitySeal()->keyId() ? 'feedface' : 'deadbeef';
        [$code, $output] = $this->checkWith($wrong, null);
        self::assertSame(CheckCredentialKeyCommand::KEY_ID_MISMATCH, $code, $output);
        self::assertStringNotContainsString('workspace', $output, 'Exit 3 was decided after reading the store.');
    }

    /** @return array{0: int, 1: string} */
    private function checkWith(?string $declaredKeyId, ?string $previousKey): array
    {
        $container = self::getContainer();
        $key = $_SERVER['AUTH_CREDENTIAL_MAC_KEY'] ?? $_ENV['AUTH_CREDENTIAL_MAC_KEY'] ?? null;
        self::assertIsString($key);
        $em = $container->get('doctrine.orm.default_entity_manager');
        \assert($em instanceof EntityManagerInterface);
        $tenants = $container->get(TenantContext::class);
        \assert($tenants instanceof TenantContext);
        $tester = new CommandTester(new CheckCredentialKeyCommand($em, $tenants, static fn (): CredentialSeal => CredentialSeal::configured($key, $declaredKeyId, 'test'), $previousKey));

        return [$tester->execute([]), $tester->getDisplay()];
    }

    private function trailRow(string $tenant, string $user, string $event, string $at): void
    {
        $this->insertAsOwnerForTenant($tenant, <<<'SQL'
            INSERT INTO audit_log (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label, metadata)
            VALUES (?::uuid, ?::uuid, ?::timestamptz, ?, ?::uuid, 'fixture', '{}'::jsonb)
            SQL, [Uuid::v4()->toRfc4122(), $tenant, $at, $event, $user]);
    }

    private function identitySeal(): SealsIdentities
    {
        $seal = self::getContainer()->get('test.auth.identity_seal');
        \assert($seal instanceof SealsIdentities);

        return $seal;
    }

    /** @return array{0: int, 1: string} */
    private function check(): array
    {
        $application = new Application(self::$kernel ?? throw new \LogicException('No kernel.'));
        $tester = new CommandTester($application->find('app:credentials:check-key'));
        $code = $tester->execute([]);

        return [$code, $tester->getDisplay()];
    }
}

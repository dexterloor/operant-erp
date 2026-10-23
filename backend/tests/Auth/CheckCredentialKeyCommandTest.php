<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Modules\UserManagement\Infrastructure\Console\CheckCredentialKeyCommand;
use App\Platform\Tenancy\Entity\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

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

    /** @return array{0: int, 1: string} */
    private function check(): array
    {
        $application = new Application(self::$kernel ?? throw new \LogicException('No kernel.'));
        $tester = new CommandTester($application->find('app:credentials:check-key'));
        $code = $tester->execute([]);

        return [$code, $tester->getDisplay()];
    }
}

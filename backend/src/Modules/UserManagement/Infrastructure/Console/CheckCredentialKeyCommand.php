<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Infrastructure\Console;

use App\Modules\UserManagement\Domain\SealsCredentials;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * **`app:credentials:check-key` — is this server's `AUTH_CREDENTIAL_MAC_KEY` the key the stored seals were made with?**
 * (Cred-C1; design Response to RED M-5, which replaced a boot-time check — `ServingCredentialGuard` is container-free and
 * runs on every request, so it may not read the database; final RED M-1, L-10; Cred-C1 RED D-5.)
 *
 * A wrong key refuses every sign-in, and every correct-password attempt is then recorded as
 * `auth.login.credential_mismatch` with `unknown_key_id`. That is also what a row tampered with a planted key id looks
 * like. This command tells the two apart. Per workspace it prints three counts — seals under the configured key id,
 * seals under any other key id, and rows with no seal — and the configured key id (an HMAC of a constant, not a secret).
 * It never prints a seal, a hash or the key.
 *
 * Exit codes:
 *  - **0** — every seal carries the configured key id (or nothing is sealed yet).
 *  - **1** — seals exist and **none** carries the configured key id: the key on this server is wrong.
 *  - **2** — some seals carry the configured key id and some carry another: with one key in force that is not a deploy
 *    mistake; treat it as a possible tamper and read the `auth.login.credential_mismatch` rows. *(When a re-seal command
 *    exists with `AUTH_CREDENTIAL_MAC_KEY_PREVIOUS`, a seal under the previous key id becomes expected during a rotation.)*
 *
 * Rows with no seal are reported, never failed on: a never-activated account (U-C1's unusable credential) has none by
 * design, and sign-in refuses them either way.
 *
 * **It runs on the application connection (`erp_app`)**, per workspace through `TenantContext::run()`: `erp_app` reads
 * `tenant` (no RLS) and `app_user` (table SELECT). It is **not** an owner command and is not in
 * `ServingCredentialGuard::CONSOLE_OWNER_COMMANDS` (final RED L-10), so it cannot run in the migration environment, where
 * the owner DSN is present and the console guard refuses. Run it after deploying a key. **There is no runner:** nothing
 * invokes it automatically (no deploy pipeline exists); its trigger is the first deploy artefact.
 */
#[AsCommand(name: 'app:credentials:check-key', description: 'Check that stored password seals were made with this server\'s AUTH_CREDENTIAL_MAC_KEY (Cred-C1).')]
final class CheckCredentialKeyCommand extends Command
{
    public const WRONG_KEY = 1;

    public const MIXED_KEYS = 2;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $tenants,
        private readonly SealsCredentials $seals,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $keyId = $this->seals->keyId();
        $prefix = 'v1.'.$keyId.'.%';
        $output->writeln(\sprintf('Configured key id: %s', $keyId));

        $current = $other = 0;
        /** @var list<string> $tenantIds */
        $tenantIds = $this->em->getConnection()->fetchFirstColumn('SELECT id FROM tenant ORDER BY id');
        foreach ($tenantIds as $tenantId) {
            $counts = $this->tenants->run((string) $tenantId, static fn (EntityManagerInterface $em): array|false => $em->getConnection()->fetchAssociative(<<<'SQL'
                SELECT count(*) FILTER (WHERE password_mac LIKE ?) AS current,
                       count(*) FILTER (WHERE password_mac IS NOT NULL AND password_mac NOT LIKE ?) AS other,
                       count(*) FILTER (WHERE password_mac IS NULL) AS unsealed
                  FROM app_user
                SQL, [$prefix, $prefix]));
            \assert(\is_array($counts));
            if (0 === (int) $counts['current'] + (int) $counts['other'] + (int) $counts['unsealed']) {
                continue;
            }
            $output->writeln(\sprintf('  workspace %s: %d under this key, %d under another key id, %d with no seal',
                $tenantId, (int) $counts['current'], (int) $counts['other'], (int) $counts['unsealed']));
            $current += (int) $counts['current'];
            $other += (int) $counts['other'];
        }

        if (0 === $current && $other > 0) {
            $output->writeln('No stored seal was made with this key: AUTH_CREDENTIAL_MAC_KEY on this server is wrong. Every sign-in is refused until it is fixed.');

            return self::WRONG_KEY;
        }
        if ($other > 0) {
            $output->writeln('Some seals carry another key id while others carry this one. With one key in force that is not a deploy mistake: treat it as a possible tamper and read the auth.login.credential_mismatch rows.');

            return self::MIXED_KEYS;
        }
        $output->writeln('Every stored seal carries this key id.');

        return Command::SUCCESS;
    }
}

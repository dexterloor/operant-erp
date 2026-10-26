<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Infrastructure\Console;

use App\Modules\UserManagement\Domain\SealCheck;
use App\Modules\UserManagement\Domain\SealsCredentials;
use App\Modules\UserManagement\Domain\SealsIdentities;
use App\Modules\UserManagement\Infrastructure\CredentialKeyIdRefused;
use App\Modules\UserManagement\Infrastructure\CredentialSeal;
use App\Modules\UserManagement\Infrastructure\PreviousSealFactory;
use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\ValueObject\AuditEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * **`app:credentials:check-key` — is this server's `AUTH_CREDENTIAL_MAC_KEY` the key every stored seal was made with,
 * and has any seal been wiped?** (Cred-C1; extended at Mail-C1 for G-75 T-G75-5 and T-D1, `mail-c1-build-plan.md`
 * R1.4 item 6; design Response to RED M-5; final RED M-1, L-10.)
 *
 * **Full verification, per row** (Mail-C1): every `password_mac` (over the stored hash) and every `identity_mac` (over the
 * stored email) is recomputed under the current key and, when `AUTH_CREDENTIAL_MAC_KEY_PREVIOUS` is set, under that one
 * too. A prefix comparison would pass a forged seal that merely carries the right key id. Per workspace it prints counts
 * — verified under this key, under another key id, unverified, with no seal — and the configured key id (an HMAC of a
 * constant, not a secret). It never prints a seal, a hash, an email or a key (Standing Rule 10).
 *
 * **The wipe check (T-D1):** a user with NO password seal is a *possible wipe* if they have signed in
 * (`last_login_at`) or the trail holds a credential event for them ({@see self::CREDENTIAL_EVENTS}) with no later
 * revocation ({@see self::REVOCATION_EVENTS} — empty until Cred-C2 adds its member). A never-activated account has no
 * seal by design and no such event, and passes.
 *
 * Exit codes:
 *  - **3** — `AUTH_CREDENTIAL_MAC_KEY_ID` is not this key's id (or production has none). Checked FIRST, before any read,
 *    so it answers even on an empty store.
 *  - **1** — seals exist and none verifies under this key (nor under PREVIOUS, when set): the key on this server is wrong.
 *  - **2** — some seal does not verify (under this key, or this-or-PREVIOUS when PREVIOUS is set), or a possible wipe:
 *    treat it as a possible tamper and read the `auth.login.credential_mismatch` rows. It blocks a release (owner Q-4).
 *  - **0** — otherwise.
 * When PREVIOUS is set it says so: sign-in does not accept it until Cred-C2 (FA-9, *SEC TO CONFIRM*).
 *
 * **It runs on the application connection (`erp_app`)**, per workspace through `TenantContext::run()`: `erp_app` reads
 * `tenant`, `app_user` (table SELECT) and `audit_log`. It is **not** an owner command and not in
 * `ServingCredentialGuard::CONSOLE_OWNER_COMMANDS` (final RED L-10). Run it after deploying a key; nothing invokes it
 * automatically (no deploy pipeline exists).
 */
#[AsCommand(name: 'app:credentials:check-key', description: 'Check every stored password and identity seal against this server\'s AUTH_CREDENTIAL_MAC_KEY (Cred-C1, Mail-C1).')]
final class CheckCredentialKeyCommand extends Command
{
    public const WRONG_KEY = 1;

    public const MIXED_KEYS = 2;

    public const KEY_ID_MISMATCH = 3;

    /** T-D1: the events that show a credential was in use. Read from here, one list (Mail-C2 added its redeem member, carry item 6). */
    public const CREDENTIAL_EVENTS = [AuditEvent::AUTH_LOGIN_SUCCESS, AuditEvent::AUTH_PASSWORD_CHANGED, AuditEvent::AUTH_PASSWORD_RESET];

    /** T-D1: the events after which a missing seal is expected. Empty until Cred-C2's `auth.credential.revoked`. */
    public const REVOCATION_EVENTS = [];

    /** @param \Closure(): (SealsCredentials&SealsIdentities) $currentSeal */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $tenants,
        #[AutowireServiceClosure(CredentialSeal::class)]
        private readonly \Closure $currentSeal,
        private readonly ?string $previousKey,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $seal = ($this->currentSeal)();
        } catch (CredentialKeyIdRefused $e) {
            $output->writeln($e->getMessage());

            return self::KEY_ID_MISMATCH;
        }
        $previous = PreviousSealFactory::create($this->previousKey);

        $output->writeln(\sprintf('Configured key id: %s', $seal->keyId()));
        if (null !== $previous) {
            $output->writeln(\sprintf('Previous key id: %s. PREVIOUS is accepted by this check only; sign-in does not accept it until Cred-C2.', $previous->keyId()));
        }

        $totals = ['current' => 0, 'previous' => 0, 'other' => 0, 'unverified' => 0, 'sealed' => 0, 'wiped' => 0];
        /** @var list<string> $tenantIds */
        $tenantIds = $this->em->getConnection()->fetchFirstColumn('SELECT id FROM tenant ORDER BY id');
        foreach ($tenantIds as $tenantId) {
            $counts = $this->tenants->run((string) $tenantId, fn (EntityManagerInterface $em): array => $this->countWorkspace($em, (string) $tenantId, $seal, $previous));
            if (0 === $counts['rows']) {
                continue;
            }
            $output->writeln(\sprintf(
                '  workspace %s: %d under this key, %d under another key id, %d with no seal; %d unverified, %d possible wipe(s); identities: %d verified, %d unverified, %d with no seal',
                $tenantId, $counts['password']['current'] + $counts['password']['previous'], $counts['password']['other'], $counts['password']['null'],
                $counts['password']['unverified'], $counts['wiped'],
                $counts['identity']['current'] + $counts['identity']['previous'], $counts['identity']['other'] + $counts['identity']['unverified'], $counts['identity']['null'],
            ));
            foreach (['password', 'identity'] as $kind) {
                foreach (['current', 'previous', 'other', 'unverified'] as $bucket) {
                    $totals[$bucket] += $counts[$kind][$bucket];
                }
                $totals['sealed'] += $counts[$kind]['current'] + $counts[$kind]['previous'] + $counts[$kind]['other'] + $counts[$kind]['unverified'];
            }
            $totals['wiped'] += $counts['wiped'];
        }

        if ($totals['sealed'] > 0 && 0 === $totals['current'] && 0 === $totals['previous']) {
            $output->writeln('No stored seal verifies under this key: AUTH_CREDENTIAL_MAC_KEY on this server is wrong. Every sign-in is refused until it is fixed.');

            return self::WRONG_KEY;
        }
        if ($totals['other'] + $totals['unverified'] + $totals['wiped'] > 0) {
            $output->writeln('Some seals do not verify, or a seal was removed from an account that was in use. With one key in force that is not a deploy mistake: treat it as a possible tamper and read the auth.login.credential_mismatch rows.');

            return self::MIXED_KEYS;
        }
        $output->writeln('Every stored seal verifies under this key.');

        return Command::SUCCESS;
    }

    /**
     * @return array{rows: int, wiped: int, password: array{current: int, previous: int, other: int, unverified: int, null: int}, identity: array{current: int, previous: int, other: int, unverified: int, null: int}}
     */
    private function countWorkspace(EntityManagerInterface $em, string $tenantId, SealsCredentials&SealsIdentities $seal, ?CredentialSeal $previous): array
    {
        $connection = $em->getConnection();
        $empty = ['current' => 0, 'previous' => 0, 'other' => 0, 'unverified' => 0, 'null' => 0];
        $counts = ['rows' => 0, 'wiped' => 0, 'password' => $empty, 'identity' => $empty];

        foreach ($connection->iterateAssociative('SELECT id, email, password_hash, password_mac, identity_mac FROM app_user ORDER BY id') as $row) {
            ++$counts['rows'];
            $userId = (string) $row['id'];
            $passwordMac = null === $row['password_mac'] ? null : (string) $row['password_mac'];
            $identityMac = null === $row['identity_mac'] ? null : (string) $row['identity_mac'];
            $counts['password'][self::bucket($passwordMac, $seal->check($tenantId, $userId, (string) $row['password_hash'], $passwordMac),
                $previous?->check($tenantId, $userId, (string) $row['password_hash'], $passwordMac))]++;
            $counts['identity'][self::bucket($identityMac, $seal->checkIdentity($tenantId, $userId, (string) $row['email'], $identityMac),
                $previous?->checkIdentity($tenantId, $userId, (string) $row['email'], $identityMac))]++;
        }

        $counts['wiped'] = (int) $connection->fetchOne(self::wipeCountSql(self::CREDENTIAL_EVENTS, self::REVOCATION_EVENTS));

        return $counts;
    }

    /** @return 'current'|'previous'|'other'|'unverified'|'null' */
    private static function bucket(?string $stored, SealCheck $current, ?SealCheck $previous): string
    {
        return match (true) {
            null === $stored => 'null',
            SealCheck::Verified === $current => 'current',
            SealCheck::Verified === $previous => 'previous',
            SealCheck::UnknownKey === $current && SealCheck::Verified !== $previous => 'other',
            default => 'unverified',
        };
    }

    /**
     * T-D1's count, for the workspace in context. Public and static so `CheckCredentialKeyCommandTest` can run it with a
     * planted revocation member (none exists in the catalogue before Cred-C2, and the CHECK refuses an unknown one).
     *
     * @param list<string> $credentialEvents
     * @param list<string> $revocationEvents
     */
    public static function wipeCountSql(array $credentialEvents, array $revocationEvents): string
    {
        $quote = static fn (array $events): string => [] === $events ? 'NULL' : implode(', ', array_map(
            static fn (string $event): string => "'".str_replace("'", "''", $event)."'", $events));

        return \sprintf(<<<'SQL'
            SELECT count(*) FROM app_user u
             WHERE u.password_mac IS NULL
               AND (u.last_login_at IS NOT NULL
                    OR EXISTS (SELECT 1 FROM audit_log a
                                WHERE a.actor_user_id = u.id AND a.event_type IN (%s)
                                  AND NOT EXISTS (SELECT 1 FROM audit_log r
                                                   WHERE r.actor_user_id = u.id AND r.event_type IN (%s)
                                                     AND (r.occurred_at, r.id) > (a.occurred_at, a.id))))
            SQL, $quote($credentialEvents), $quote($revocationEvents));
    }
}

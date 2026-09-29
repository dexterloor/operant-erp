<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Infrastructure\Console;

use App\Modules\UserManagement\Application\RoleReconciliation;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `app:roles:reconcile --tenant=<uuid>` — the thin console face of {@see RoleReconciliation} (G-10 condition (1); roles
 * slice 2, C2: arms B and F). One line per finding, `finding,user_id,role_id,at`; exit **1** on any finding, **0** on none,
 * **2** when a control refuses (no findings printed); the cadence sentence on every run.
 *
 * **Not on `ServingCredentialGuard::CONSOLE_OWNER_COMMANDS`, on purpose** (design D-9): it never needs the owner, and adding
 * a non-migration command to that list widens what runs beside the owner DSN. `RED` measured (R2-14) that the console
 * boots with application credentials only and reaches `erp_app` under READ COMMITTED; with the owner DSN present the
 * console guard refuses it, which is the correct outcome.
 */
#[AsCommand(name: 'app:roles:reconcile', description: 'Compare a workspace\'s role grants with its audit trail (G-10).')]
final class ReconcileRolesCommand extends Command
{
    public const EXIT_FINDINGS = 1;

    public const EXIT_REFUSED = 2;

    public function __construct(private readonly RoleReconciliation $reconciliation)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'The workspace (tenant) id to reconcile.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tenant = $input->getOption('tenant');
        if (!\is_string($tenant) || '' === $tenant) {
            $output->writeln('Refused: --tenant=<uuid> is required.');

            return self::EXIT_REFUSED;
        }

        $result = $this->reconciliation->run($tenant);
        if (null !== $result['refused']) {
            $output->writeln('Refused: '.$result['refused'].'. No findings are printed.');

            return self::EXIT_REFUSED;
        }

        foreach ($result['findings'] as $finding) {
            $line = implode(',', [$finding['finding'], $finding['user_id'] ?? '', $finding['role_id'] ?? '', $finding['at'] ?? '']);
            if (RoleReconciliation::FINDING_NO_ACTIVE_OWNER === $finding['finding']) {
                $line .= ' (attribution and recovery triage, not detection)';
            }
            $output->writeln($line);
        }
        $output->writeln(RoleReconciliation::CADENCE);

        return [] === $result['findings'] ? Command::SUCCESS : self::EXIT_FINDINGS;
    }
}

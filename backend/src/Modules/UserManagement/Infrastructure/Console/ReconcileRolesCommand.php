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
 * slice 2: arms B and F in C2, A and C in C3). One line per finding, `finding,user_id,role_id,at` — arm A adds
 * `,inserted_by,owner_process_row=yes|no`; exit **1** on any finding, **0** on none,
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
            if (\array_key_exists('inserted_by', $finding)) {
                // Arm A (H1): which login inserted the row, and whether that is an owner process rather than the application.
                $line .= ','.$finding['inserted_by'].',owner_process_row='.(($finding['owner_process_row'] ?? false) ? 'yes' : 'no');
            }
            if (\array_key_exists('proposal_id', $finding)) {
                // Arm G (roles slice 3 C2): which proposal, and which of its cases.
                $line .= ',proposal_id='.$finding['proposal_id'].',case='.($finding['case'] ?? '');
            }
            if (RoleReconciliation::FINDING_NO_ACTIVE_OWNER === $finding['finding']) {
                $line .= ' (attribution and recovery triage, not detection)';
            }
            $output->writeln($line);
        }
        $output->writeln(RoleReconciliation::CADENCE);

        return [] === $result['findings'] ? Command::SUCCESS : self::EXIT_FINDINGS;
    }
}

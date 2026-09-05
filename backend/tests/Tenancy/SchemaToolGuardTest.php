<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Tenancy\DatabaseRoles;
use App\Tenancy\SchemaManifest;
use Doctrine\DBAL\ArrayParameterType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * Detecting drift is not the same as preventing it.
 *
 * DoctrineMappingTest asserts that the mapping and the schema diverge in exactly
 * one way. Nothing in that test stops someone running the command that resolves
 * the divergence in the wrong direction and drops the composite tenant foreign
 * key. These tests run the command for real and check the constraint is still
 * standing afterwards.
 */
final class SchemaToolGuardTest extends TenancyTestCase
{
    private function compositeForeignKeyDefinition(): ?string
    {
        $def = $this->owner()->fetchOne(<<<'SQL'
            SELECT pg_get_constraintdef(oid) FROM pg_constraint
            WHERE conrelid = 'inventory_item'::regclass AND contype = 'f'
            SQL);

        return \is_string($def) ? $def : null;
    }

    private function runCommand(array $argv): array
    {
        // phpunit.dist.xml pins SHELL_VERBOSITY=-1, which silences the listener's
        // explanation. Lifted for the duration so the message can be asserted as
        // well as the exit code -- an unexplained 113 would send someone hunting.
        $previous = getenv('SHELL_VERBOSITY');
        putenv('SHELL_VERBOSITY=0');

        try {
            $application = new Application(self::$kernel);
            $application->setAutoExit(false);
            $tester = new ApplicationTester($application);
            $exitCode = $tester->run($argv, ['decorated' => false]);

            return [$exitCode, $tester->getDisplay()];
        } finally {
            false === $previous ? putenv('SHELL_VERBOSITY') : putenv('SHELL_VERBOSITY='.$previous);
        }
    }

    #[Test]
    public function doctrineSchemaUpdateWithForceIsRefusedAndTheCompositeForeignKeySurvives(): void
    {
        $before = $this->compositeForeignKeyDefinition();
        self::assertSame('FOREIGN KEY (tenant_id, supplier_id) REFERENCES supplier(tenant_id, id)', $before);

        [$exitCode] = $this->runCommand(['command' => 'doctrine:schema:update', '--force' => true]);

        self::assertSame(ConsoleCommandEvent::RETURN_CODE_DISABLED, $exitCode,
            'doctrine:schema:update --force was not blocked by the listener.');
        self::assertSame($before, $this->compositeForeignKeyDefinition(),
            'The composite tenant foreign key was dropped by doctrine:schema:update.');
    }

    #[Test]
    public function doctrineSchemaDropIsRefused(): void
    {
        [$exitCode] = $this->runCommand(['command' => 'doctrine:schema:drop', '--force' => true]);

        self::assertSame(ConsoleCommandEvent::RETURN_CODE_DISABLED, $exitCode);
        // Every tenant-scoped table, derived. A literal here would keep passing
        // while covering a shrinking fraction of the schema.
        self::assertSame(\count(SchemaManifest::tenantScopedTables()), (int) $this->owner()->fetchOne(<<<'SQL'
            SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relkind = 'r' AND c.relname IN (?)
            SQL, [SchemaManifest::tenantScopedTables()], [ArrayParameterType::STRING]));
    }

    /**
     * The second layer, asserted rather than assumed: even with the listener
     * gone, the role the ORM connects as cannot execute the drop.
     */
    #[Test]
    public function theOrmConnectionHoldsNoDdlRightsOnTheTenantTables(): void
    {
        self::assertSame('erp_app', $this->app()->fetchOne('SELECT current_user'));

        // Only an owner may ALTER or DROP a table. erp_app owns nothing.
        self::assertSame([], $this->owner()->fetchFirstColumn(<<<'SQL'
            SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p')
              AND pg_get_userbyid(c.relowner) IN (?)
            SQL, [DatabaseRoles::APPLICATION_ROLES], [ArrayParameterType::STRING]));

        foreach (DatabaseRoles::APPLICATION_ROLES as $role) {
            self::assertFalse((bool) $this->owner()->fetchOne(
                'SELECT has_schema_privilege(?, ?, ?)', [$role, 'public', 'CREATE']),
                $role.' can create objects in public, so it could own one -- and ownership plus a missing '
                .'FORCE voids isolation.');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Platform\Console;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The schema in this project is not derivable from the ORM mapping.
 *
 * ADR-002 §5's composite foreign key -- FOREIGN KEY (tenant_id, supplier_id)
 * REFERENCES supplier (tenant_id, id) -- cannot be expressed in a Doctrine
 * association without making tenant_id part of the identifier, which was
 * measured and rejected. Neither can ENABLE/FORCE ROW LEVEL SECURITY, the
 * policies, or the default privileges.
 *
 * SchemaTool does not know about any of that, so it proposes to drop all of it.
 * DoctrineMappingTest detects the divergence; it cannot stop the command that
 * acts on it. This can.
 *
 * A second layer already exists and is asserted separately: the ORM connects as
 * erp_app, which owns nothing and holds no DDL rights, so the drop would be
 * refused by Postgres too. Neither layer is sufficient alone -- a future
 * entity manager on the owner connection would defeat the second, and deleting
 * this listener would defeat the first.
 */
#[AsEventListener(event: ConsoleEvents::COMMAND)]
final class ForbidSchemaToolCommands
{
    private const FORBIDDEN = [
        'doctrine:schema:update',
        'doctrine:schema:create',
        'doctrine:schema:drop',
    ];

    public function __invoke(ConsoleCommandEvent $event): void
    {
        $name = $event->getCommand()?->getName();

        if (!\in_array($name, self::FORBIDDEN, true)) {
            return;
        }

        $event->getOutput()->writeln([
            '',
            sprintf('  <error> %s is disabled in this project. </error>', $name),
            '',
            '  The database carries constraints the ORM mapping cannot express: the composite',
            '  tenant foreign keys of ADR-002 §5, ENABLE/FORCE ROW LEVEL SECURITY, the isolation',
            '  policies, and ALTER DEFAULT PRIVILEGES. SchemaTool does not know about any of them',
            '  and will propose to drop them.',
            '',
            '  Use a migration:  bin/console doctrine:migrations:diff  then edit it by hand.',
            '',
        ]);

        $event->disableCommand();
    }
}

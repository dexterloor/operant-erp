<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Console\ForbidSchemaToolCommands;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The listener on its own, away from the console's verbosity handling.
 *
 * SchemaToolGuardTest proves the command is stopped and the constraint survives;
 * it cannot assert the explanation, because phpunit.dist.xml pins
 * SHELL_VERBOSITY=-1 for the whole suite. An unexplained exit code 113 would
 * send whoever hits it hunting, so the message is worth pinning somewhere.
 */
final class ForbidSchemaToolCommandsTest extends TestCase
{
    private function dispatch(string $commandName): array
    {
        $output = new BufferedOutput();
        $event = new ConsoleCommandEvent(new Command($commandName), new ArrayInput([]), $output);

        (new ForbidSchemaToolCommands())($event);

        return [$event->commandShouldRun(), $output->fetch()];
    }

    #[Test]
    public function everySchemaToolCommandIsDisabledAndSaysWhy(): void
    {
        foreach (['doctrine:schema:update', 'doctrine:schema:create', 'doctrine:schema:drop'] as $name) {
            [$shouldRun, $message] = $this->dispatch($name);

            self::assertFalse($shouldRun, $name.' was allowed to run.');
            self::assertStringContainsString($name.' is disabled in this project', $message);
            self::assertStringContainsString('doctrine:migrations:diff', $message,
                'The refusal must name the supported route, or it just looks like a broken install.');
        }
    }

    #[Test]
    public function unrelatedCommandsAreUntouched(): void
    {
        foreach (['doctrine:migrations:migrate', 'cache:clear', 'doctrine:schema:validate'] as $name) {
            [$shouldRun, $message] = $this->dispatch($name);

            self::assertTrue($shouldRun, $name.' was blocked by mistake.');
            self::assertSame('', $message);
        }
    }
}

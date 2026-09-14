<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * The generated OpenAPI document, produced by running `api:openapi:export`.
 *
 * Moved out of `OpenApiContractTest` (C5, 2026-09-14) so the phase-2 checks
 * over the spec's `paths` — `OperationContractTest`, `OperationIdTest` — read
 * the same artifact the same way. It runs the command rather than reaching into
 * the factory because **NO ARTIFACT is one of API-10a's three outcomes**, and a
 * generator that cannot produce a document has to be able to say so.
 */
final class GeneratedSpec
{
    /** @return array<string, mixed> */
    public static function regenerate(KernelInterface $kernel): array
    {
        $application = new Application($kernel);
        $application->setAutoExit(false);

        // `phpunit.dist.xml` sets SHELL_VERBOSITY=-1, and Symfony's console
        // reads that into VERBOSITY_QUIET -- under which the export command
        // writes nothing at all and the artifact silently disappears. Cleared
        // for the duration of the run so that "the generator produced nothing"
        // means what it says.
        $verbosity = $_SERVER['SHELL_VERBOSITY'] ?? null;
        unset($_SERVER['SHELL_VERBOSITY'], $_ENV['SHELL_VERBOSITY']);
        putenv('SHELL_VERBOSITY');

        $output = new BufferedOutput();

        try {
            $status = $application->run(new ArrayInput(['command' => 'api:openapi:export']), $output);
        } finally {
            if (null !== $verbosity) {
                $_SERVER['SHELL_VERBOSITY'] = $verbosity;
                $_ENV['SHELL_VERBOSITY'] = $verbosity;
                putenv('SHELL_VERBOSITY='.$verbosity);
            }
        }

        Assert::assertSame(0, $status, 'NO ARTIFACT: the spec could not be generated. API-10a: an absent artifact is not evidence.');

        $spec = json_decode($output->fetch(), true);
        Assert::assertIsArray($spec, 'NO ARTIFACT: the generator produced something that is not a JSON document.');
        Assert::assertArrayHasKey('paths', $spec);

        return $spec;
    }

    /**
     * Every operation in the spec's `paths`, keyed `METHOD path`, with the
     * method as the spec spells it (lower case). Path-level members that are
     * not HTTP methods (`parameters`, `summary`, …) are not operations.
     *
     * @param array<string, mixed> $spec
     *
     * @return array<string, array{method: string, path: string, operation: array<string, mixed>}>
     */
    public static function operations(array $spec): array
    {
        $operations = [];

        foreach ($spec['paths'] as $path => $item) {
            foreach ($item as $method => $operation) {
                if (!\is_array($operation) || \in_array($method, ['parameters', 'summary', 'description', 'servers', '$ref'], true)) {
                    continue;
                }

                $operations[strtoupper((string) $method).' '.$path] = [
                    'method' => (string) $method,
                    'path' => (string) $path,
                    'operation' => $operation,
                ];
            }
        }

        return $operations;
    }
}

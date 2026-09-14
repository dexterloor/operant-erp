<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ContractDocument;
use App\Tests\Support\GeneratedSpec;
use PHPUnit\Framework\Attributes\Test;

/**
 * `docs/API.md` §3.2 (API-5) and §4.1 (API-11) — two properties of the whole
 * operation surface, read from the **generated spec's `paths`**, never from a
 * list of operations written into this file.
 *
 * ---------------------------------------------------------------------------
 * Why each test first proves the spec is the registry's projection
 * ---------------------------------------------------------------------------
 * A check that says "no path holds a `put`" is green over an empty `paths`,
 * and green over a spec that has stopped listing some operations. So each test
 * holds the spec's `(method, operationId)` pairs equal to
 * `TransactionBoundaryTest::contractOperations()`'s, inside the same test, before
 * it asserts anything — the domain is every built operation, and it widens by
 * itself as rows are built.
 *
 * Mutations (C5 batch A, 2026-09-14; `backend/var/cache/test` deleted before the
 * mutated run and before the revert run — Rule 17): recorded on each method.
 */
final class OperationContractTest extends ApiTestCase
{
    /**
     * API-5 — no operation uses `PUT`. §3.2: `PUT`-to-create is a
     * client-supplied row id wearing a path, and it is the one form of B-3's
     * defect a body-schema check cannot see.
     *
     * Mutation: `UpdateItem` declared as `new Put(...)` instead of
     * `new Patch(...)` → RED, "API-5: an operation uses PUT." naming
     * `PUT /api/v1/items/{id}`. Revert → GREEN.
     */
    #[Test]
    public function noOperationUsesPut(): void
    {
        $operations = $this->specOperationsHeldToTheRegistry();

        $offenders = [];
        foreach ($operations as $label => $operation) {
            if ('put' === strtolower($operation['method'])) {
                $offenders[] = $label;
            }
        }

        self::assertSame([], $offenders, 'API-5: an operation uses PUT.');
    }

    /**
     * API-11 — the mechanical half: no path carries an `admin` segment. Which
     * segments are "groupings" is `[judgement]`; this is only the half a test
     * can decide.
     *
     * Checked over the generated spec always, and over §4.2's `Path` column
     * wherever the document exists — the document is where the unbuilt
     * `/users`, `/roles`, `/audit-log` and `/settings` rows live, which are
     * exactly the paths an `/admin` prefix would be tempted onto.
     *
     * Mutation 1 (code): `CreateItem`'s `uriTemplate` → `/admin/items` → RED,
     * naming `POST /api/v1/admin/items`. Mutation 2 (document): `ListUsers`'
     * path → `/admin/users` → RED, naming `ListUsers`. Reverts → GREEN.
     */
    #[Test]
    public function noPathContainsAnAdminSegment(): void
    {
        // Positive control for the predicate itself, inside this test so a
        // `--filter` run carries it: a scanner that never matches would pass
        // every path below.
        self::assertTrue(self::hasAdminSegment('/api/v1/admin/users'), 'The segment predicate cannot see an admin segment.');
        self::assertTrue(self::hasAdminSegment('/Admin'), 'The segment predicate is case-sensitive.');
        self::assertFalse(self::hasAdminSegment('/api/v1/administrators'), 'The segment predicate matches a substring, not a segment.');

        $offenders = [];
        foreach ($this->specOperationsHeldToTheRegistry() as $label => $operation) {
            if (self::hasAdminSegment($operation['path'])) {
                $offenders[] = $label;
            }
        }

        self::assertSame([], $offenders, 'API-11: a generated path carries an admin segment.');

        if (!ContractDocument::isAvailable()) {
            self::markTestSkipped('docs/API.md is gitignored and absent, so §4.2\'s unbuilt paths cannot be read. The generated spec above was checked.');
        }

        $rows = ContractDocument::operationRows();
        self::assertNotSame([], $rows, '§4.2 yielded no rows, so the document half would be vacuous.');

        $documented = [];
        foreach ($rows as $operationId => $row) {
            self::assertStringStartsWith('/', $row['path'], $operationId.'\'s Path cell is not a path.');
            if (self::hasAdminSegment($row['path'])) {
                $documented[] = $operationId.' '.$row['path'];
            }
        }

        self::assertSame([], $documented, 'API-11: §4.2 declares a path with an admin segment.');
    }

    private static function hasAdminSegment(string $path): bool
    {
        foreach (explode('/', $path) as $segment) {
            if ('admin' === strtolower(trim($segment))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The spec's operations, after asserting they are exactly the registry's.
     *
     * @return array<string, array{method: string, path: string, operation: array<string, mixed>}>
     */
    private function specOperationsHeldToTheRegistry(): array
    {
        $spec = GeneratedSpec::regenerate(self::$kernel ?? throw new \LogicException('The kernel is booted in setUp().'));
        $operations = GeneratedSpec::operations($spec);
        $registry = TransactionBoundaryTest::contractOperations();

        self::assertNotSame([], $registry, 'The operation registry is empty, so there is no domain.');
        self::assertNotSame([], $operations, 'The generated spec declares no operation, so there is no domain.');

        $fromSpec = [];
        foreach ($operations as $label => $operation) {
            self::assertArrayHasKey('operationId', $operation['operation'], $label.' has no operationId.');
            $fromSpec[] = strtoupper($operation['method']).' '.$operation['operation']['operationId'];
        }

        $fromRegistry = [];
        foreach ($registry as $name => $operation) {
            $fromRegistry[] = strtoupper((string) $operation->getMethod()).' '.$name;
        }

        sort($fromSpec);
        sort($fromRegistry);
        self::assertSame($fromRegistry, $fromSpec, 'The generated spec is not the registry\'s projection, so a scan of its paths would not see every operation.');

        return $operations;
    }
}

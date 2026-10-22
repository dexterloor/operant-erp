<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Platform\Http\Bulk\BulkArchiveMembers;
use App\Tests\Support\PhpSourceScan;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * **Every bulk member's delegated writer method issues DBAL statements only** (G5-C2; design R1 L-4 as amended by final RED
 * L-6). A member's savepoint rolls back its SQL, not the unit of work: an ORM change it scheduled would be flushed at the
 * end of the bulk's `run()` even for a refused member. `BulkArchival` asserts a clean unit of work after every member at run
 * time; this scan finds the shape before it can run.
 *
 * **The domain is DERIVED** from the tagged {@see BulkArchiveMembers} registry, never a hand list: for each member, the
 * methods its `archive()` and `lockOrder()` call on its own constructor-injected properties (`$this->writer->delete(`),
 * resolved to the property's declared class; then, transitively, every method of THAT class those methods call. Each
 * reached method's calls must not include an ORM write or load: `persist`, `flush`, `find`, `getRepository`, `remove`,
 * `getReference`, `merge`. An eighth member is scanned the day it is tagged.
 *
 * **Limits, stated:** a callee in ANOTHER class (a port, a reader) is not followed — the shipped members' writers reach
 * ports (`openWorkInside`, `stockedLocationCountInside`) that are DBAL readers, checked by reading, not by this scan; the
 * runtime guard (`BulkArchival::refuseAPendingOrmChange`, proven by `BulkArchivalCoordinatorTest`) is the REAL backstop,
 * and covers what the scan cannot see (RED L-4). *Plant control:* {@see self::thePlantedOrmWriteIsFound} — the same walk
 * over a fixture source that calls `persist(` must report it, so the scan can go red.
 */
final class BulkMemberWritersAreDbalOnlyTest extends KernelTestCase
{
    private const ORM_CALLS = ['persist', 'flush', 'find', 'getRepository', 'remove', 'getReference', 'merge'];

    #[Test]
    public function everyMembersDelegatedWriterMethodIsDbalOnly(): void
    {
        self::bootKernel();
        $members = self::getContainer()->get(BulkArchiveMembers::class);
        \assert($members instanceof BulkArchiveMembers);

        $reached = [];
        foreach ($members->all() as $resource => $member) {
            foreach (self::reachedMethods(new \ReflectionClass($member)) as $key => $calls) {
                $reached[$resource.' → '.$key] = $calls;
            }
        }

        self::assertGreaterThanOrEqual(14, \count($reached), 'Anti-vacuity: the walk reached too few methods to mean anything.');
        self::assertArrayHasKey('inventory_item → ItemWriter::archive', $reached, 'Positive control: the item member\'s single-delete method was not reached.');
        self::assertArrayHasKey('storage_location → StorageLocationWriter::delete', $reached);

        foreach ($reached as $key => $calls) {
            self::assertSame([], array_values(array_intersect($calls, self::ORM_CALLS)), $key.' calls an ORM method; a bulk member must issue DBAL statements only.');
        }
    }

    #[Test]
    public function thePlantedOrmWriteIsFound(): void
    {
        $scan = PhpSourceScan::scan(<<<'PHP'
            <?php
            namespace X;
            final class PlantedWriter {
                public function delete(string $id): void { $this->helper($id); }
                private function helper(string $id): void { $this->tenants->run('t', function ($em) { $em->persist(new \stdClass()); }); }
            }
            PHP);

        self::assertSame(['persist'], array_values(array_intersect(self::closure($scan, 'PlantedWriter', ['delete'])['PlantedWriter::helper'], self::ORM_CALLS)));
    }

    /**
     * @param \ReflectionClass<object> $member
     *
     * @return array<string, list<string>> `Short::method` => its calls
     */
    private static function reachedMethods(\ReflectionClass $member): array
    {
        $scan = PhpSourceScan::scan((string) file_get_contents((string) $member->getFileName()));
        $short = $member->getShortName();
        $properties = [];
        foreach ($member->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if ($parameter->isPromoted() && $type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $properties[$parameter->getName()] = $type->getName();
            }
        }

        $reached = [];
        foreach (['archive', 'lockOrder'] as $entry) {
            $source = self::methodSource($member, $entry);
            preg_match_all('/\$this->(\w+)->(\w+)\(/', $source, $m, \PREG_SET_ORDER);
            foreach ($m as [, $property, $method]) {
                $class = $properties[$property] ?? null;
                self::assertNotNull($class, $short.'::'.$entry.' calls $this->'.$property.', which is not a typed promoted constructor property.');
                $target = new \ReflectionClass($class);
                $targetScan = PhpSourceScan::scan((string) file_get_contents((string) $target->getFileName()));
                $reached += self::closure($targetScan, $target->getShortName(), [$method]);
            }
            $reached[$short.'::'.$entry] = $scan['methods'][$short.'::'.$entry]['calls'] ?? [];
        }

        return $reached;
    }

    /**
     * The given methods and every own-class method they call, transitively.
     *
     * @param array{methods: array<string, array{calls: list<string>}>} $scan
     * @param list<string>                                                $start
     *
     * @return array<string, list<string>>
     */
    private static function closure(array $scan, string $short, array $start): array
    {
        $reached = [];
        $queue = $start;
        while ([] !== $queue) {
            $method = array_shift($queue);
            $key = $short.'::'.$method;
            if (isset($reached[$key]) || !isset($scan['methods'][$key])) {
                continue;
            }
            $reached[$key] = $scan['methods'][$key]['calls'];
            foreach ($reached[$key] as $call) {
                if (isset($scan['methods'][$short.'::'.$call])) {
                    $queue[] = $call;
                }
            }
        }

        return $reached;
    }

    /** @param \ReflectionClass<object> $class */
    private static function methodSource(\ReflectionClass $class, string $method): string
    {
        $reflection = $class->getMethod($method);
        $lines = file((string) $class->getFileName());
        \assert(\is_array($lines));

        return implode('', \array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
    }
}

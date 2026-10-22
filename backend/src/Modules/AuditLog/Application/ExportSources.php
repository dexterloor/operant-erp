<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Application;

use App\Platform\Http\Export\ExportSource;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * **The export registry (G6-C0): every {@see ExportSource}, by `resource`, and the List operation each one names.**
 *
 * The one place `CreateExport` learns what is exportable. Built from the tagged sources (implementing the interface is the
 * wiring), so a new source is exportable without an edit here, and `ExportAdmissibleSetTest` holds the set equal, two-way,
 * to the flat single-permission `List*` operations the registry derives (design §6.1, rules (1)–(4)).
 *
 * Contract, checked on first use (a violation is a LogicException — a wiring fault, never a request's): each `resource` is
 * named by one source only. What each source's List DECLARES (its permission, members, orders) is read through the Platform
 * port {@see \App\Platform\Http\Export\ListDeclarations}, so this module never reads framework metadata (A-7).
 */
final class ExportSources
{
    /** @var array<string, ExportSource>|null */
    private ?array $byResource = null;

    /**
     * @param iterable<ExportSource> $sources
     */
    public function __construct(
        #[AutowireIterator(ExportSource::TAG)]
        private readonly iterable $sources,
    ) {
    }

    /**
     * Every source, keyed by `resource`, byte-sorted.
     *
     * @return array<string, ExportSource>
     */
    public function all(): array
    {
        if (null === $this->byResource) {
            $byResource = [];
            foreach ($this->sources as $source) {
                if (!$source instanceof ExportSource) {
                    throw new \LogicException(\sprintf('A service tagged "%s" is not an %s.', ExportSource::TAG, ExportSource::class));
                }
                $resource = $source->resource();
                if ('' === $resource || isset($byResource[$resource])) {
                    throw new \LogicException(\sprintf('The export resource "%s" is empty or named by two sources.', $resource));
                }
                $byResource[$resource] = $source;
            }
            ksort($byResource, \SORT_STRING);
            $this->byResource = $byResource;
        }

        return $this->byResource;
    }

    public function has(string $resource): bool
    {
        return isset($this->all()[$resource]);
    }

    public function get(string $resource): ExportSource
    {
        return $this->all()[$resource]
            ?? throw new \LogicException(\sprintf('No export source serves "%s"; the gate resolves only resources a source names.', $resource));
    }
}

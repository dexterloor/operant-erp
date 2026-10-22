<?php

declare(strict_types=1);

namespace App\Platform\Http\Bulk;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Every {@see BulkArchiveMember}, keyed by `resource`, byte-sorted (`ExportSources`' shape). A duplicate or empty
 * resource, or a member for a resource {@see BulkExclusions::RULED} keeps out, is a wiring fault (LogicException).
 */
final class BulkArchiveMembers
{
    /** @var array<string, BulkArchiveMember>|null */
    private ?array $byResource = null;

    /**
     * @param iterable<BulkArchiveMember> $members
     */
    public function __construct(
        #[AutowireIterator(BulkArchiveMember::TAG)]
        private readonly iterable $members,
    ) {
    }

    /** @return array<string, BulkArchiveMember> */
    public function all(): array
    {
        if (null === $this->byResource) {
            $byResource = [];
            foreach ($this->members as $member) {
                if (!$member instanceof BulkArchiveMember) {
                    throw new \LogicException(\sprintf('A service tagged "%s" is not a %s.', BulkArchiveMember::TAG, BulkArchiveMember::class));
                }
                $resource = $member->resource();
                if ('' === $resource || isset($byResource[$resource])) {
                    throw new \LogicException(\sprintf('The bulk resource "%s" is empty or named by two members.', $resource));
                }
                if (\array_key_exists($resource, BulkExclusions::RULED)) {
                    throw new \LogicException(\sprintf('"%s" is out of bulk archive by %s; no member may serve it.', $resource, BulkExclusions::RULED[$resource]));
                }
                $byResource[$resource] = $member;
            }
            ksort($byResource, \SORT_STRING);
            $this->byResource = $byResource;
        }

        return $this->byResource;
    }

    public function get(string $resource): BulkArchiveMember
    {
        return $this->all()[$resource]
            ?? throw new \LogicException(\sprintf('No bulk member serves "%s"; the gate resolves only resources a member names.', $resource));
    }
}

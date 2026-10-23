<?php

declare(strict_types=1);

namespace App\Platform\Http\Bulk;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use App\Platform\Security\DerivedPermission;
use App\Platform\Security\PermissionRequirement;

/**
 * **`CreateBulkRestoration`'s derived permission** (G5-C3, 2026-10-23; `docs/session13/g5-c3-design.md` D-4; ST-9: *"with the
 * same permission that archived it"*).
 *
 * `resource → the code its single Restore* operation is DECLARED with`, read through {@see PermissionRequirement::of()} off the
 * operation each {@see BulkArchiveMember} names — so the bulk restore is gated exactly as the single restore, never by
 * concatenation and never by borrowing the archival's resolver. Also asserted to be `<resource>.delete` (D-19) on a `POST`
 * `Restore*`; anything else is a wiring fault (LogicException). Reads no database (E-1).
 */
final readonly class BulkRestorationPermission implements DerivedPermission
{
    public function __construct(
        private BulkArchiveMembers $members,
        private ResourceMetadataCollectionFactoryInterface $resources,
    ) {
    }

    public function codesByResource(): array
    {
        $map = [];
        foreach ($this->members->all() as $resource => $member) {
            $map[$resource] = self::restoreCodeOf($this->resources, $member);
        }

        return $map;
    }

    public function unknownResourceMessage(): string
    {
        return BulkArchivalPermission::UNKNOWN_RESOURCE;
    }

    /** The single Restore's declared code, which must be exactly `<resource>.delete`. */
    public static function restoreCodeOf(ResourceMetadataCollectionFactoryInterface $resources, BulkArchiveMember $member): string
    {
        $name = $member->restoreOperationName();
        $operation = $resources->create($member->deleteResourceClass())->getOperation($name);
        if (!$operation instanceof HttpOperation || 'POST' !== $operation->getMethod() || !str_starts_with($name, 'Restore')) {
            throw new \LogicException(\sprintf('The bulk member for "%s" names "%s", which is not a POST Restore* operation.', $member->resource(), $name));
        }

        $requirement = PermissionRequirement::of($operation);
        $expected = $member->resource().'.delete';
        if (PermissionRequirement::ALL_OF !== $requirement->kind() || [$expected] !== $requirement->codes()) {
            throw new \LogicException(\sprintf(
                'The bulk member for "%s" names %s, which is gated on %s; a bulk-restorable resource\'s Restore is gated on exactly %s (ST-9, D-19).',
                $member->resource(),
                $name,
                $requirement->describe(),
                $expected,
            ));
        }

        return $expected;
    }
}

<?php

declare(strict_types=1);

namespace App\Platform\Http\Bulk;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use App\Platform\Security\DerivedPermission;
use App\Platform\Security\PermissionRequirement;

/**
 * **`CreateBulkArchival`'s derived permission** (G5-C2; design §5.1, D-17/D-19; ST-9: *"with the same permission that
 * archived it"*).
 *
 * The map is `resource → the code its single Delete* operation is DECLARED with`, read through the one permission parser
 * ({@see PermissionRequirement::of()}) off the operation each {@see BulkArchiveMember} names — so the bulk is gated exactly
 * as the single delete, never by a concatenation. It is ALSO asserted to be `<resource>.delete` (D-19: the resource is the
 * table, the prefix of its `.delete` code); a member whose Delete is gated otherwise (a line's parent `.edit`) is not
 * admissible and naming it is a wiring fault (LogicException).
 *
 * Reads no database: operation metadata only (the gate decides before any row is loaded, E-1).
 */
final readonly class BulkArchivalPermission implements DerivedPermission
{
    /** Design R1 L-10: also the answer for `app_user` / `role`, which RO-B2 keeps out. */
    public const UNKNOWN_RESOURCE = 'This kind of record can\'t be archived or restored in bulk.';

    public function __construct(
        private BulkArchiveMembers $members,
        private ResourceMetadataCollectionFactoryInterface $resources,
    ) {
    }

    public function codesByResource(): array
    {
        $map = [];
        foreach ($this->members->all() as $resource => $member) {
            $map[$resource] = self::deleteCodeOf($this->resources, $member);
        }

        return $map;
    }

    public function unknownResourceMessage(): string
    {
        return self::UNKNOWN_RESOURCE;
    }

    /** The single Delete's declared code, which must be exactly `<resource>.delete`. */
    public static function deleteCodeOf(ResourceMetadataCollectionFactoryInterface $resources, BulkArchiveMember $member): string
    {
        $name = $member->deleteOperationName();
        $operation = $resources->create($member->deleteResourceClass())->getOperation($name);

        if (!$operation instanceof HttpOperation || 'DELETE' !== $operation->getMethod() || !str_starts_with($name, 'Delete')) {
            throw new \LogicException(\sprintf('The bulk member for "%s" names "%s", which is not a DELETE Delete* operation.', $member->resource(), $name));
        }

        $requirement = PermissionRequirement::of($operation);
        $expected = $member->resource().'.delete';

        if (PermissionRequirement::ALL_OF !== $requirement->kind() || [$expected] !== $requirement->codes()) {
            throw new \LogicException(\sprintf(
                'The bulk member for "%s" names %s, which is gated on %s; a bulk-archivable resource\'s Delete is gated on exactly %s (ST-9, D-19).',
                $member->resource(),
                $name,
                $requirement->describe(),
                $expected,
            ));
        }

        return $expected;
    }
}

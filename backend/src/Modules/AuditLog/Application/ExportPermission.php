<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Application;

use App\Platform\Http\Export\ListDeclarations;
use App\Platform\Security\DerivedPermission;
use App\Platform\Security\PermissionRequirement;

/**
 * **`CreateExport`'s derived permission (G6-C0; design §6.1 D-20; G5-D0's resolver contract).**
 *
 * The map is `resource → the code its List operation is DECLARED with`, read through the one permission parser
 * ({@see PermissionRequirement::of()}, through {@see ListDeclarations}) off the operation each {@see \App\Platform\Http\Export\ExportSource} names. So the
 * export is gated exactly as the list it renders: `<resource>.view` everywhere today, and `credit_note → invoice.view` /
 * `permission → role.view` (G6-C1) by construction rather than by a concatenation that would get them wrong (`API.md` :963,
 * reconciled: "the `.view` its `List*` operation is gated on"). B1N-5: the pick resource has no `List*`, so it is not
 * exportable; if one ever ships it resolves to whatever it is gated on.
 *
 * Rule (2) of the admissible set is enforced HERE as well as in `ExportAdmissibleSetTest`: a List gated on no code, on
 * several (`ListReorderSuggestions`' all-of), or on a derived permission is not exportable, and naming one is a wiring
 * fault (LogicException), because a single code per request is what makes the export one decision (E-1, E-14).
 *
 * Reads no database (the gate decides before any row is loaded): only operation metadata.
 */
final readonly class ExportPermission implements DerivedPermission
{
    public const UNKNOWN_RESOURCE = 'This kind of record can\'t be exported.';

    public function __construct(
        private ExportSources $sources,
        private ListDeclarations $declarations,
    ) {
    }

    public function codesByResource(): array
    {
        $map = [];
        foreach ($this->sources->all() as $resource => $source) {
            $requirement = $this->declarations->requirement($source);

            if (PermissionRequirement::ALL_OF !== $requirement->kind() || 1 !== \count($requirement->codes())) {
                throw new \LogicException(\sprintf(
                    'The export source for "%s" names %s, which is gated on %s; an exportable list declares exactly one code.',
                    $resource,
                    $source->listOperationName(),
                    $requirement->describe(),
                ));
            }

            $map[$resource] = $requirement->codes()[0];
        }

        return $map;
    }

    public function unknownResourceMessage(): string
    {
        return self::UNKNOWN_RESOURCE;
    }
}

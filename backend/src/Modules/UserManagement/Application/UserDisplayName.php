<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

/**
 * **A person's display name — one rule, two callers** (`UpdateUser` since G4-C1; `CreateUser` since U-C1b, extracted then so
 * the two cannot drift): trimmed, not blank, at most `RoleDefinitionFields::NAME_MAX` characters, no invisible or control
 * character (`RoleDefinitionFields::NAME_FORBIDDEN`). Pointer `/display_name`.
 */
final class UserDisplayName
{
    /**
     * @return array{0: ?string, 1: ?array{pointer: string, code: string, message: string}} the stored name, or the one error
     */
    public static function check(mixed $name): array
    {
        if (null === $name) {
            return [null, RoleDefinitionFields::error('/display_name', 'required', 'This field is required.')];
        }
        if (!\is_string($name)) {
            return [null, RoleDefinitionFields::error('/display_name', 'not_a_string', 'This field must be a string.')];
        }
        $name = RoleDefinitionFields::trimmed($name);
        if ('' === $name) {
            return [null, RoleDefinitionFields::error('/display_name', 'blank', 'This field may not be blank.')];
        }
        if (mb_strlen($name, 'UTF-8') > RoleDefinitionFields::NAME_MAX) {
            return [null, RoleDefinitionFields::error('/display_name', 'too_long', \sprintf('A name can be at most %d characters.', RoleDefinitionFields::NAME_MAX))];
        }
        if (1 === preg_match(RoleDefinitionFields::NAME_FORBIDDEN, $name)) {
            return [null, RoleDefinitionFields::error('/display_name', 'invalid_character', 'A name can\'t contain invisible or control characters.')];
        }

        return [$name, null];
    }
}

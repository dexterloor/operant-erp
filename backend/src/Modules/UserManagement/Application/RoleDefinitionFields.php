<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Platform\Http\Problem\ApiProblem;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * **The three fields a role definition accepts — `name`, `description`, `permission_codes` — validated ONE way for
 * `CreateRole` and `UpdateRole`** (roles slice 4; extracted from `RoleCreation` at C2 so the two operations cannot drift:
 * `DATA` D-10.1's name rule, the description bound, the code list rules, the case-insensitive live-name collision and the
 * code domain read from the `permission` TABLE).
 *
 * `validated()` validates exactly the keys it is given, every field error at once in body order (422):
 *  - `name`: required (null is `required`) / string / not blank after trimming Unicode white space / ≤ 100 characters / no
 *    control or listed format character (the `role_name_shape` CHECK's set, mirrored exactly);
 *  - `description`: null → `''`; string; ≤ 500; no control character but tab, newline, carriage return;
 *  - `permission_codes`: required (null is `required`), a list (may be empty), each a string, no duplicate.
 * `CreateRole` passes all three (absent and null are one case at creation); `UpdateRole` passes only the members its PATCH
 * body carried (absent = unchanged).
 *
 * `nameIsTaken()` is design §3.1 step 4 / D-10.1 item 2 — one statement whose collision key the DATABASE computes, so it
 * cannot drift between PHP and SQL; `$exceptRoleId` excludes the role being edited (a rename to a spelling of its own name
 * is not a collision). `unknownCodeErrors()` is step 5. `translated()` answers the two backstops (`role_tenant_name_live`
 * `23505`, the shape CHECKs' `23514`) the way the application rule would — reachable only by an out-of-band writer that
 * never takes the role-administration lock.
 */
final class RoleDefinitionFields
{
    public const NAME_MAX = 100;

    public const DESCRIPTION_MAX = 500;

    /** PLACEHOLDER (`UX` §9 item 8): the caller's own name typed back, never "reserved". */
    public const NAME_TAKEN = 'A role called "%s" already exists in this workspace (names are compared ignoring capitals, spaces, dashes and underscores). Choose another name.';

    /** The Unicode `White_Space` set the name is trimmed of (the CHECK's `btrim` covers ASCII space only). */
    private const EDGE_SPACE = '/^[\s\x{85}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+|[\s\x{85}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+$/u';

    /** `role_name_shape`'s two character classes (`DATA` D-10.1), as one PCRE class. Mirrors the CHECK exactly. */
    private const NAME_FORBIDDEN = '/[\x{0}-\x{1F}\x{7F}-\x{9F}\x{AD}\x{61C}\x{180E}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{206F}\x{FEFF}]/u';

    /** `role_description_shape`: controls but tab, newline, carriage return. */
    private const DESCRIPTION_FORBIDDEN = '/[\x{0}-\x{8}\x{B}\x{C}\x{E}-\x{1F}\x{7F}-\x{9F}]/u';

    private const NAME_UNIQUE = 'role_tenant_name_live';

    /** Step 4. One statement; the key is the database's (one engine, so it cannot drift between PHP and SQL). */
    private const LIVE_NAME_COLLISION = <<<'SQL'
        SELECT 1
          FROM role
         WHERE deleted_at IS NULL
           AND id IS DISTINCT FROM ?::uuid
           AND btrim(regexp_replace(lower(normalize(name, NFKC)), '[ _\-]+', ' ', 'g'))
             = btrim(regexp_replace(lower(normalize(?::text, NFKC)), '[ _\-]+', ' ', 'g'))
         LIMIT 1
        SQL;

    /** Step 5: the codes the FK admits, read from the table in this transaction. */
    private const KNOWN_CODES = 'SELECT code FROM permission WHERE code = ANY (?::text[])';

    /** Not instantiable: pure validation plus two reads through the caller's (locked) entity manager — never an injected `Connection` (A-10). */
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $fields the submitted members, keyed `name` / `description` / `permission_codes`
     *
     * @return array{name?: string, description?: string, permission_codes?: list<string>} the same keys, validated
     */
    public static function validated(array $fields): array
    {
        $errors = [];
        $valid = [];

        if (\array_key_exists('name', $fields)) {
            $name = $fields['name'];
            if (null === $name) {
                $errors[] = self::error('/name', 'required', 'This field is required.');
            } elseif (!\is_string($name)) {
                $errors[] = self::error('/name', 'not_a_string', 'This field must be a string.');
            } else {
                $name = self::trimmed($name);
                if ('' === $name) {
                    $errors[] = self::error('/name', 'blank', 'This field may not be blank.');
                } elseif (mb_strlen($name, 'UTF-8') > self::NAME_MAX) {
                    $errors[] = self::error('/name', 'too_long', sprintf('A role name can be at most %d characters.', self::NAME_MAX));
                } elseif (1 === preg_match(self::NAME_FORBIDDEN, $name)) {
                    $errors[] = self::error('/name', 'invalid_character', 'A role name can\'t contain invisible or control characters.');
                } else {
                    $valid['name'] = $name;
                }
            }
        }

        if (\array_key_exists('description', $fields)) {
            $description = $fields['description'] ?? '';
            if (!\is_string($description)) {
                $errors[] = self::error('/description', 'not_a_string', 'This field must be a string.');
            } elseif (mb_strlen($description, 'UTF-8') > self::DESCRIPTION_MAX) {
                $errors[] = self::error('/description', 'too_long', sprintf('A description can be at most %d characters.', self::DESCRIPTION_MAX));
            } elseif (1 === preg_match(self::DESCRIPTION_FORBIDDEN, $description)) {
                $errors[] = self::error('/description', 'invalid_character', 'A description can\'t contain control characters other than tabs and line breaks.');
            } else {
                $valid['description'] = $description;
            }
        }

        if (\array_key_exists('permission_codes', $fields)) {
            $permissionCodes = $fields['permission_codes'];
            $codes = [];
            if (null === $permissionCodes) {
                $errors[] = self::error('/permission_codes', 'required', 'This field is required. Send an empty list for a role with no permissions yet.');
            } elseif (!\is_array($permissionCodes) || !array_is_list($permissionCodes)) {
                $errors[] = self::error('/permission_codes', 'not_a_list', 'This field must be a list of permission codes.');
            } else {
                foreach ($permissionCodes as $i => $code) {
                    if (!\is_string($code)) {
                        $errors[] = self::error('/permission_codes/'.$i, 'not_a_string', 'Each permission code must be a string.');
                    } elseif (\in_array($code, $codes, true)) {
                        $errors[] = self::error('/permission_codes/'.$i, 'duplicate_value', 'This permission code is already in the list.');
                    } else {
                        $codes[] = $code;
                    }
                }
                $valid['permission_codes'] = $codes;
            }
        }

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }

        return $valid;
    }

    /** Step 4: another LIVE role's collision key equals this name's (`$exceptRoleId` = the role being edited, or null). */
    public static function nameIsTaken(EntityManagerInterface $em, string $name, ?string $exceptRoleId): bool
    {
        $connection = $em->getConnection();

        return false !== $connection->fetchOne(self::LIVE_NAME_COLLISION, [$exceptRoleId, $name]);
    }

    /** The 409 step 4 answers with — and the `23505` backstop, below. */
    public static function nameTaken(string $name): ApiProblem
    {
        return ApiProblem::businessRuleViolated('role.name_taken', sprintf(self::NAME_TAKEN, $name));
    }

    /**
     * Step 5: every code not in the `permission` table, as 422 errors at `/permission_codes/{i}`. Only code-shaped strings
     * reach the database (a NUL byte would be a 22021, not a refusal); the rest are unknown.
     *
     * @param list<string> $codes
     *
     * @return list<array{pointer: string, code: string, message: string}>
     */
    public static function unknownCodeErrors(EntityManagerInterface $em, array $codes): array
    {
        $connection = $em->getConnection();
        $shaped = array_values(array_filter($codes, static fn (string $code): bool => 1 === preg_match('/^[a-z_]+\.[a-z_]+$/', $code)));
        $known = [] === $shaped ? [] : $connection->fetchFirstColumn(self::KNOWN_CODES, ['{'.implode(',', $shaped).'}']);
        $unknown = [];
        foreach ($codes as $i => $code) {
            if (!\in_array($code, $known, true)) {
                $unknown[] = self::error('/permission_codes/'.$i, 'unknown_reference', 'There is no permission with this code.');
            }
        }

        return $unknown;
    }

    /**
     * The backstops, answered as the rule would answer — caught OUTSIDE `run()` (the failed statement aborted the
     * transaction and closed the entity manager). Null = not one of ours; the caller rethrows.
     */
    public static function translated(DriverException $e, mixed $name): ?ApiProblem
    {
        if ($e instanceof UniqueConstraintViolationException && str_contains($e->getMessage(), self::NAME_UNIQUE)) {
            return self::nameTaken(\is_string($name) ? self::trimmed($name) : '');
        }
        if ('23514' === $e->getSQLState() && str_contains($e->getMessage(), 'role_name_shape')) {
            return ApiProblem::validationFailed([self::error('/name', 'invalid_character', 'A role name can\'t contain invisible or control characters.')]);
        }
        if ('23514' === $e->getSQLState() && str_contains($e->getMessage(), 'role_description_shape')) {
            return ApiProblem::validationFailed([self::error('/description', 'invalid_character', 'A description can\'t contain control characters other than tabs and line breaks.')]);
        }

        return null;
    }

    public static function trimmed(string $value): string
    {
        return (string) preg_replace(self::EDGE_SPACE, '', $value);
    }

    /** @return array{pointer: string, code: string, message: string} */
    public static function error(string $pointer, string $code, string $message): array
    {
        return ['pointer' => $pointer, 'code' => $code, 'message' => $message];
    }
}

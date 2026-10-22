<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Modules\AuditLog\Infrastructure\SettingsAuditWriter;
use App\Modules\UserManagement\Application\Contract\WorkspaceNotConfigured;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * **`UpdateSettings` — rename the workspace's display name** (G7b, 2026-10-22; owner ruling RO-S1,
 * `docs/session13/remaining-ops-ruling.md`; `docs/session13/g3-g7b-reorder-settings-design.md` §3.3 + Revision 1 FINAL;
 * final RED F-M3).
 *
 * The body is `{display_name}` and nothing else: `default_currency_code` and `workspace` are refused as undeclared fields by
 * the operation's denormalizer (API-9) before this runs, and the currency is unwritable at the database as well
 * (`erp_app` holds `UPDATE (display_name, updated_at)` only, `Version20261022020000`). **`tenant` is never written** (RO-S1:
 * it has no RLS); the registry's `name` stays what it was.
 *
 * Validation first, outside any transaction (422 at `/display_name`, every rule `role_name_shape`'s, because the CHECK is
 * that predicate byte for byte — design L-8): `null` is `required`; a non-string `not_a_string`; trimmed of Unicode white
 * space at both ends; empty after trimming `blank`; over 100 characters `too_long`; a control or listed format character
 * `invalid_character`. An empty body changes nothing (merge-patch: absent = unchanged).
 *
 * Then, as `erp_app` in one `TenantContext::run()` on the caller's tenant — from the signed token, never the request (the
 * operation declares no path variable; G7a L-3):
 *  1. {@see self::LOCK} — the settings row `FOR NO KEY UPDATE` (legal since the column UPDATE grant; A4.3 constraint 1's
 *     precedent). No row → {@see WorkspaceNotConfigured} (500, ledger E4-3, G7a D-4/M-5): never a 404 and never created
 *     here (`erp_app` holds no INSERT);
 *  2. the same name after trimming → nothing is written: no UPDATE, no audit row, `updated_at` unchanged; 200;
 *  3. {@see self::RENAME} — one row, the tenant predicate stated beside RLS's; then `tenant_settings.updated`
 *     `{"fields": ["display_name"], "to": <name>}`, in the same transaction, under the lock — so two concurrent renames
 *     serialise and write two rows in commit order, and a second rename to the value the first committed is step 2;
 *  4. 200 with `GetSettings`' body, read inside the transaction.
 * A `23514` on `tenant_settings_display_name_shape` (unreachable while validation refuses first) is answered as validation
 * would answer it, outside `run()`.
 */
final readonly class SettingsWriter
{
    /** Step 1. The tenant predicate is explicit, beside RLS's (two layers; `SettingsWriteTest` mutates each). */
    public const LOCK = 'SELECT id, display_name FROM tenant_settings WHERE tenant_id = ?::uuid FOR NO KEY UPDATE';

    /** Step 3. Exactly the two columns `erp_app`'s column grant covers. */
    public const RENAME = 'UPDATE tenant_settings SET display_name = ?, updated_at = now() WHERE id = ?::uuid AND tenant_id = ?::uuid';

    private const SHAPE_CHECK = 'tenant_settings_display_name_shape';

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private SettingsAuditWriter $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $submitted wire name => value, for exactly the fields the body carried
     */
    public function rename(array $submitted): SettingsView
    {
        $displayName = \array_key_exists('display_name', $submitted) ? self::validated($submitted['display_name']) : null;
        $tenantId = $this->tenant->tenantId();
        $identity = $this->identities->authenticated();

        try {
            return $this->tenants->run($tenantId, function (EntityManagerInterface $em) use ($tenantId, $displayName, $identity): SettingsView {
                $connection = $em->getConnection();
                $row = $connection->fetchAssociative(self::LOCK, [$tenantId]);
                if (false === $row) {
                    throw WorkspaceNotConfigured::noSettingsRow();
                }

                if (null !== $displayName && $displayName !== $row['display_name']) {
                    $updated = $connection->executeStatement(self::RENAME, [$displayName, $row['id'], $tenantId]);
                    if (1 !== $updated) {
                        throw new \LogicException(\sprintf('The rename of the locked settings row changed %d rows.', $updated));
                    }

                    // After the UPDATE, in its transaction, under the row lock.
                    $this->audit->recordUpdated(
                        tenantId: $identity->tenantId,
                        actorUserId: $identity->userId,
                        actorLabel: $identity->actorLabel,
                        settingsId: (string) $row['id'],
                        displayName: $displayName,
                    );
                }

                return SettingsReader::readInside($em, $tenantId) ?? throw new \LogicException('The workspace just renamed is not readable.');
            });
        } catch (DriverException $e) {
            if ('23514' === $e->getSQLState() && str_contains($e->getMessage(), self::SHAPE_CHECK)) {
                throw ApiProblem::validationFailed([RoleDefinitionFields::error('/display_name', 'invalid_character', 'A workspace name can\'t contain invisible or control characters.')]);
            }

            throw $e;
        }
    }

    private static function validated(mixed $value): string
    {
        $error = static fn (string $code, string $message): ApiProblem => ApiProblem::validationFailed([RoleDefinitionFields::error('/display_name', $code, $message)]);

        if (null === $value) {
            throw $error('required', 'This field is required.');
        }
        if (!\is_string($value)) {
            throw $error('not_a_string', 'This field must be a string.');
        }
        $name = RoleDefinitionFields::trimmed($value);
        if ('' === $name) {
            throw $error('blank', 'This field may not be blank.');
        }
        if (mb_strlen($name, 'UTF-8') > RoleDefinitionFields::NAME_MAX) {
            throw $error('too_long', \sprintf('A workspace name can be at most %d characters.', RoleDefinitionFields::NAME_MAX));
        }
        if (1 === preg_match(RoleDefinitionFields::NAME_FORBIDDEN, $name)) {
            throw $error('invalid_character', 'A workspace name can\'t contain invisible or control characters.');
        }

        return $name;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource\State;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\UserManagement\Application\RoleRevocation;
use App\Platform\Security\PermissionGate;
use Symfony\Component\HttpFoundation\Request;

/**
 * `RevokeRole` — `DELETE /api/v1/users/{id}/roles/{roleId}`. API-21 branch 3: **204, and no body**.
 *
 * `user_role` is `SchemaManifest::HARD` (a join row; that it existed lives in `audit_log`), so this is a real `DELETE` —
 * the project's first, granted with this caller (ADR-002 A4.1). Declared `read: false`: nothing is read before the
 * role-administration lock is taken (a `HARD` row read early can go stale into a wrong answer).
 *
 * The processor hands the application service the two request facts it cannot see: the client IP (E-3's field on both
 * the `role.revoked` row and a lock-time denial row) and **the denial subject as the gate would resolve it for this very
 * request** — `PermissionGate::denialSubjectOf()`, the one resolver, so a denial found under the lock records the same
 * `(subject_type, subject_id)` pair a denial at the gate does (`roles-s2-sec.md` Acknowledgement B.3 item 4).
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class RevokeRoleProcessor implements ProcessorInterface
{
    public function __construct(private RoleRevocation $revocation)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $request = $context['request'] ?? null;
        if (!$operation instanceof HttpOperation || !$request instanceof Request) {
            throw new \LogicException('RevokeRole is an HTTP operation and runs inside a request.');
        }

        $this->revocation->revoke(
            (string) ($uriVariables['id'] ?? ''),
            (string) ($uriVariables['roleId'] ?? ''),
            $request->getClientIp(),
            PermissionGate::denialSubjectOf($operation, $request),
        );

        return null;
    }
}

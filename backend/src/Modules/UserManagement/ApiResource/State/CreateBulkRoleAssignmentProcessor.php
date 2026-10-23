<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource\State;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\UserManagement\ApiResource\CreateBulkRoleAssignmentInput;
use App\Modules\UserManagement\Application\BulkRoleAssignment;
use App\Platform\Http\Bulk\BulkArchival;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\PermissionGate;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * `CreateBulkRoleAssignment` — the body's shape (422, before any transaction; both fields' errors in ONE 422), then
 * {@see BulkRoleAssignment}. The answer is the stored envelope's bytes on the first call and on every replay alike, so the
 * two cannot differ by construction.
 *
 * Validation codes: `/role_id` `required` (absent or null) and `not_a_uuid` (not a string uuid) — `AssignRole`'s; `/user_ids`
 * `CreateBulkArchival`'s five codes with its own sentences (about people, not records): `at_least_one_id`, `not_a_list`,
 * `too_many_ids` (over {@see BulkArchival::MAXIMUM_MEMBERS}), `/user_ids/<n>` `not_a_uuid` and `duplicate_id` (the later
 * occurrence; ids compare as canonical lower-case uuids).
 *
 * Like `AssignRoleProcessor`, it hands the coordinator the request facts it cannot see: the client IP and the denial
 * subject as the gate resolves it for this very request (`PermissionGate::denialSubjectOf()`).
 *
 * @implements ProcessorInterface<CreateBulkRoleAssignmentInput, Response>
 */
final readonly class CreateBulkRoleAssignmentProcessor implements ProcessorInterface
{
    public function __construct(private BulkRoleAssignment $bulk)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        \assert($data instanceof CreateBulkRoleAssignmentInput);
        $request = $context['request'] ?? null;
        if (!$operation instanceof HttpOperation || !$request instanceof Request) {
            throw new \LogicException('CreateBulkRoleAssignment is an HTTP operation and runs inside a request.');
        }

        [$roleId, $userIds] = self::body($data);
        $key = (string) $request->headers->get(IdempotencyKeyGuard::HEADER);

        $replay = $this->bulk->run($roleId, $userIds, $key, $request->getClientIp(), PermissionGate::denialSubjectOf($operation, $request));

        return new Response($replay->body, $replay->status, ['Content-Type' => $replay->headers['content-type'] ?? BulkArchival::CONTENT_TYPE]);
    }

    /**
     * @return array{0: string, 1: list<string>} the canonical role id and user ids (in the order given)
     */
    public static function body(CreateBulkRoleAssignmentInput $data): array
    {
        $errors = [];

        $roleId = null;
        if (null === $data->roleId) {
            $errors[] = ['pointer' => '/role_id', 'code' => 'required', 'message' => 'This field is required.'];
        } elseif (!\is_string($data->roleId) || !Uuid::isValid($data->roleId)) {
            $errors[] = ['pointer' => '/role_id', 'code' => 'not_a_uuid', 'message' => 'This field must be a UUID naming a role in this workspace.'];
        } else {
            $roleId = Uuid::fromString($data->roleId)->toRfc4122();
        }

        $userIds = [];
        $ids = $data->userIds;
        if (null === $ids || [] === $ids) {
            $errors[] = ['pointer' => '/user_ids', 'code' => 'at_least_one_id', 'message' => 'Name at least one person.'];
        } elseif (!\is_array($ids) || !array_is_list($ids)) {
            $errors[] = ['pointer' => '/user_ids', 'code' => 'not_a_list', 'message' => 'user_ids must be a list of user ids.'];
        } elseif (\count($ids) > BulkArchival::MAXIMUM_MEMBERS) {
            $errors[] = ['pointer' => '/user_ids', 'code' => 'too_many_ids', 'message' => \sprintf('Name at most %d people at a time.', BulkArchival::MAXIMUM_MEMBERS)];
        } else {
            $seen = [];
            foreach ($ids as $n => $id) {
                if (!\is_string($id) || !Uuid::isValid($id)) {
                    $errors[] = ['pointer' => '/user_ids/'.$n, 'code' => 'not_a_uuid', 'message' => 'This is not a user id.'];
                    continue;
                }
                $value = Uuid::fromString($id)->toRfc4122();
                if (isset($seen[$value])) {
                    $errors[] = ['pointer' => '/user_ids/'.$n, 'code' => 'duplicate_id', 'message' => 'This person is already named earlier in the list.'];
                    continue;
                }
                $seen[$value] = true;
                $userIds[] = $value;
            }
        }

        if ([] !== $errors || null === $roleId) {
            throw ApiProblem::validationFailed($errors);
        }

        return [$roleId, $userIds];
    }
}

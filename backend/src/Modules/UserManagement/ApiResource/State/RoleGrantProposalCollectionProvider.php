<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\UserManagement\ApiResource\RoleGrantProposalCollection;
use App\Modules\UserManagement\ApiResource\RoleGrantProposalResource;
use App\Modules\UserManagement\Application\RoleGrantProposalListCriteria;
use App\Modules\UserManagement\Application\RoleGrantProposalReader;
use App\Platform\Http\Pagination\Cursor;
use App\Platform\Http\Pagination\InvalidCursor;
use App\Platform\Http\Pagination\TimestampSortKey;
use App\Platform\Http\Problem\ApiProblem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** `ListRoleGrantProposals` (roles slice 3, C1). `CreditNoteCollectionProvider`'s parsing. */
final readonly class RoleGrantProposalCollectionProvider implements ProviderInterface
{
    public const DEFAULT_LIMIT = 50;
    public const MAXIMUM_LIMIT = 200;

    /** The five stored values (`role_grant_proposal_status_values`). */
    public const STATUSES = ['pending', 'confirmed', 'rejected', 'cancelled', 'lapsed'];

    public function __construct(private RoleGrantProposalReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): RoleGrantProposalCollection
    {
        $request = $context['request'] ?? null;
        $query = $request instanceof Request ? $request->query->all() : [];

        $page = $this->reader->list($this->criteria($query));

        return new RoleGrantProposalCollection(
            items: array_map(RoleGrantProposalResource::fromView(...), $page->items),
            nextCursor: $page->nextCursor,
            previousCursor: $page->previousCursor,
            tenantHasRows: $page->tenantHasRows,
        );
    }

    /** @param array<string, mixed> $query */
    private function criteria(array $query): RoleGrantProposalListCriteria
    {
        $errors = [];

        $limit = self::DEFAULT_LIMIT;
        if (isset($query['limit'])) {
            $raw = $query['limit'];
            if (!\is_string($raw) || 1 !== preg_match('/^\d+$/', $raw) || (int) $raw < 1 || (int) $raw > self::MAXIMUM_LIMIT) {
                $errors[] = self::error('limit', 'out_of_range', \sprintf('limit must be a whole number between 1 and %d.', self::MAXIMUM_LIMIT));
            } else {
                $limit = (int) $raw;
            }
        }

        $status = null;
        if (isset($query['status'])) {
            $raw = $query['status'];
            if (!\is_string($raw) || !\in_array($raw, self::STATUSES, true)) {
                $errors[] = self::error('status', 'not_allowed', 'status must be one of '.implode(', ', self::STATUSES).'.');
            } else {
                $status = $raw;
            }
        }

        $ids = [];
        foreach (['user', 'role'] as $name) {
            $ids[$name] = null;
            if (isset($query[$name])) {
                $raw = $query[$name];
                if (!\is_string($raw) || !Uuid::isValid($raw)) {
                    $errors[] = self::error($name, 'not_a_uuid', $name.' must be a UUID.');
                } else {
                    $ids[$name] = Uuid::fromString($raw)->toRfc4122();
                }
            }
        }

        if (isset($query['after'], $query['before'])) {
            $errors[] = self::error('after', 'mutually_exclusive', 'Send at most one of after and before.');
        }
        $after = self::cursor($query, 'after', $errors);
        $before = self::cursor($query, 'before', $errors);

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }

        return new RoleGrantProposalListCriteria(
            limit: $limit,
            status: $status,
            userId: $ids['user'],
            roleId: $ids['role'],
            after: $after,
            before: $before,
        );
    }

    /**
     * @param array<string, mixed>                                        $query
     * @param list<array{pointer: string, code: string, message: string}> $errors
     */
    private static function cursor(array $query, string $name, array &$errors): ?Cursor
    {
        if (!isset($query[$name]) || '' === $query[$name]) {
            return null;
        }

        try {
            $cursor = Cursor::decode((string) $query[$name]);
        } catch (InvalidCursor) {
            $cursor = null;
        }

        if (null === $cursor || !TimestampSortKey::isValid($cursor->sortKey)) {
            $errors[] = self::error($name, 'malformed_cursor', \sprintf('%s must be a cursor returned by a previous response for this view.', $name));

            return null;
        }

        return $cursor;
    }

    /** @return array{pointer: string, code: string, message: string} */
    private static function error(string $parameter, string $code, string $message): array
    {
        return ['pointer' => '?'.$parameter, 'code' => $code, 'message' => $message];
    }
}

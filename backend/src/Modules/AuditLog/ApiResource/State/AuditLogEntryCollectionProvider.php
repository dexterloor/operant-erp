<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\AuditLog\ApiResource\AuditLogEntryCollection;
use App\Modules\AuditLog\ApiResource\AuditLogEntryResource;
use App\Modules\AuditLog\Application\AuditLogEntryReader;
use App\Modules\AuditLog\Application\AuditLogListCriteria;
use App\Platform\Http\Problem\ApiProblem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * `ListAuditLogEntries` — parameter parsing and the refusals API-17/API-18 require (`StockMovementCollectionProvider`'s
 * shape). The gate has already refused a caller without `audit_log.view` (E-1) — so a non-holder's request issues no
 * statement of the reader's shape — and anything but the five declared parameters is refused before this class runs
 * (`UndeclaredQueryParameterRefusal`).
 *
 * **Two refusals of its own:**
 *  - **both filters** → 422 `one_filter_at_a_time` on `?actor_user_id` and `?event_type` (design L-5; `exactly_one_scope`
 *    is the precedent; `mutually_exclusive` stays the cursor pair's). No index serves the pair (design §3.1);
 *  - **a cursor whose sort key is not a timestamp** → `malformed_cursor`, never a 500 from `'SKU-1'::timestamptz` (the paging
 *    parameters live in {@see AuditListParameters}, shared with `ListAuditRecords` since C2).
 *
 * `event_type` outside `AuditEvent::ALL` is refused by the declared enum (`invalid_parameter`) before this class runs, so
 * this class carries no branch of its own for it (CD-5: a branch HTTP cannot reach is dead code no test asserts).
 *
 * @implements ProviderInterface<AuditLogEntryCollection>
 */
final readonly class AuditLogEntryCollectionProvider implements ProviderInterface
{
    public function __construct(private AuditLogEntryReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AuditLogEntryCollection
    {
        $request = $context['request'] ?? null;
        $query = $request instanceof Request ? $request->query->all() : [];

        $page = $this->reader->list($this->criteria($query));

        return new AuditLogEntryCollection(
            items: array_map(AuditLogEntryResource::fromView(...), $page->items),
            nextCursor: $page->nextCursor,
            previousCursor: $page->previousCursor,
            tenantHasRows: $page->tenantHasRows,
        );
    }

    /** @param array<string, mixed> $query */
    private function criteria(array $query): AuditLogListCriteria
    {
        $errors = [];

        $limit = AuditListParameters::limit($query, $errors);

        $actorUserId = null;
        if (isset($query['actor_user_id']) && '' !== $query['actor_user_id']) {
            $raw = $query['actor_user_id'];
            if (!\is_string($raw) || !Uuid::isValid($raw)) {
                $errors[] = AuditListParameters::error('actor_user_id', 'not_a_uuid', 'actor_user_id must be a UUID.');
            } else {
                $actorUserId = Uuid::fromString($raw)->toRfc4122();
            }
        }

        // Already one of `AuditEvent::ALL`: the declared enum refused anything else (`invalid_parameter`) before this
        // class ran — no second, unreachable branch here (`RESIDUAL_GAPS.md` CD-5's lesson). It is bound, never inlined.
        $eventType = isset($query['event_type']) && \is_string($query['event_type']) && '' !== $query['event_type'] ? $query['event_type'] : null;

        // Placeholder copy (`UX` owns it, TO CONFIRM): design §3.3.
        if (isset($query['actor_user_id'], $query['event_type']) && '' !== $query['actor_user_id'] && '' !== $query['event_type']) {
            $message = 'Filter by one person or one kind of event, not both.';
            $errors[] = AuditListParameters::error('actor_user_id', 'one_filter_at_a_time', $message);
            $errors[] = AuditListParameters::error('event_type', 'one_filter_at_a_time', $message);
        }

        [$after, $before] = AuditListParameters::cursors($query, $errors);

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }

        return new AuditLogListCriteria(limit: $limit, actorUserId: $actorUserId, eventType: $eventType, after: $after, before: $before);
    }
}

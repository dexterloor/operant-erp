<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\AuditLog\ApiResource\AuditRecordCollection;
use App\Modules\AuditLog\ApiResource\AuditRecordResource;
use App\Modules\AuditLog\Application\AuditRecordAccess;
use App\Modules\AuditLog\Application\AuditRecordCriteria;
use App\Modules\AuditLog\Application\AuditRecordReader;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\RequestIdentity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * `ListAuditRecords`. **Order of refusals** (design §3.2): the gate has already refused a caller without
 * `audit_log.view_record` (E-1), and `UndeclaredQueryParameterRefusal` anything undeclared; the declared schema has refused
 * a `subject_type` outside `RecordHistorySubjects::TYPES` and a malformed `subject_id`. What is left, in order:
 *  1. **422** — `subject_type` / `subject_id` missing (`required`, each on its own pointer), `limit`, the cursors;
 *  2. **403** — the caller lacks the subject's own read code (E-5, {@see AuditRecordAccess}: one E-3 row on the query's
 *     pair, the gate's body) — before any `audit_log` read, outside any transaction;
 *  3. **200** — the page.
 *
 * @implements ProviderInterface<AuditRecordCollection>
 */
final readonly class AuditRecordCollectionProvider implements ProviderInterface
{
    public function __construct(
        private AuditRecordReader $reader,
        private AuditRecordAccess $access,
        private RequestIdentity $identities,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AuditRecordCollection
    {
        $request = $context['request'] ?? null;
        $query = $request instanceof Request ? $request->query->all() : [];

        $criteria = self::criteria($query);

        $identity = $this->identities->current() ?? throw new \LogicException('ListAuditRecords reached its provider with no identity; the gate admits none.');
        $this->access->refuseUnlessTheSubjectIsReadable($identity, $request instanceof Request ? $request->getClientIp() : null, $criteria->subjectType, $criteria->subjectId);

        $page = $this->reader->list($criteria);

        return new AuditRecordCollection(
            items: array_map(AuditRecordResource::fromView(...), $page->items),
            nextCursor: $page->nextCursor,
            previousCursor: $page->previousCursor,
            tenantHasRows: $page->tenantHasRows,
        );
    }

    /** @param array<string, mixed> $query */
    private static function criteria(array $query): AuditRecordCriteria
    {
        $errors = [];

        // Placeholder copy (`UX` owns it, TO CONFIRM).
        foreach (['subject_type', 'subject_id'] as $name) {
            if (!isset($query[$name]) || !\is_string($query[$name]) || '' === $query[$name]) {
                $errors[] = AuditListParameters::error($name, 'required', $name.' is required: a History is the history of one record.');
            }
        }

        $limit = AuditListParameters::limit($query, $errors);
        [$after, $before] = AuditListParameters::cursors($query, $errors);

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }

        // Both are already schema-valid (the enum, `format: uuid`): no second, unreachable branch here (CD-5).
        return new AuditRecordCriteria(
            subjectType: (string) $query['subject_type'],
            subjectId: Uuid::fromString((string) $query['subject_id'])->toRfc4122(),
            limit: $limit,
            after: $after,
            before: $before,
        );
    }
}

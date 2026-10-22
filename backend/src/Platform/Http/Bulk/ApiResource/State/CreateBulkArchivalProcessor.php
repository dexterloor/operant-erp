<?php

declare(strict_types=1);

namespace App\Platform\Http\Bulk\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Platform\Http\Bulk\ApiResource\CreateBulkArchivalInput;
use App\Platform\Http\Bulk\BulkArchival;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\PermissionRequirements;
use App\Platform\Security\RequestIdentity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * `CreateBulkArchival` — acts ONLY on the resource the gate resolved (G5-D0 M-3, carry (1)): a body read two ways is a 500
 * (`BulkArchivalTest::aBodyReadTwoWaysIsAServerErrorNotAnArchival` plants the mismatch). Then the shape of `ids` (422,
 * before any transaction), then {@see BulkArchival}. The answer is the stored envelope's bytes on the first call and on
 * every replay alike, so the two cannot differ by construction.
 *
 * Validation codes (design §4.1's four, plus one for a non-list): `/ids` `at_least_one_id` (absent, null or `[]`),
 * `/ids` `not_a_list`, `/ids` `too_many_ids` (over {@see BulkArchival::MAXIMUM_MEMBERS}), `/ids/<n>` `not_a_uuid`,
 * `/ids/<n>` `duplicate_id` (the later occurrence; ids compare as canonical lower-case uuids).
 *
 * @implements ProcessorInterface<CreateBulkArchivalInput, Response>
 */
final readonly class CreateBulkArchivalProcessor implements ProcessorInterface
{
    public function __construct(
        private BulkArchival $bulk,
        private RequestIdentity $identities,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        \assert($data instanceof CreateBulkArchivalInput);
        $request = $context['request'] ?? null;
        if (!$request instanceof Request) {
            throw new \LogicException('CreateBulkArchival runs only on an HTTP request.');
        }

        // The gate refused a non-string `resource` before the 403, so a non-string here means the body was read two ways.
        $resource = \is_string($data->resource) ? $data->resource : '';
        PermissionRequirements::resolvedFor($request, $resource);

        $ids = self::ids($data->ids);
        $key = (string) $request->headers->get(IdempotencyKeyGuard::HEADER);

        $replay = $this->bulk->run($resource, $ids, $key, $this->identities->authenticated()->userId);

        return new Response($replay->body, $replay->status, ['Content-Type' => $replay->headers['content-type'] ?? BulkArchival::CONTENT_TYPE]);
    }

    /**
     * @return list<string> canonical lower-case uuids, in the order given
     */
    public static function ids(mixed $ids): array
    {
        if (null === $ids || [] === $ids) {
            throw ApiProblem::validationFailed([['pointer' => '/ids', 'code' => 'at_least_one_id', 'message' => 'Name at least one record.']]);
        }
        if (!\is_array($ids) || !array_is_list($ids)) {
            throw ApiProblem::validationFailed([['pointer' => '/ids', 'code' => 'not_a_list', 'message' => 'ids must be a list of record ids.']]);
        }
        if (\count($ids) > BulkArchival::MAXIMUM_MEMBERS) {
            throw ApiProblem::validationFailed([['pointer' => '/ids', 'code' => 'too_many_ids', 'message' => \sprintf('Name at most %d records at a time.', BulkArchival::MAXIMUM_MEMBERS)]]);
        }

        $errors = [];
        $seen = [];
        $canonical = [];
        foreach ($ids as $n => $id) {
            if (!\is_string($id) || !Uuid::isValid($id)) {
                $errors[] = ['pointer' => '/ids/'.$n, 'code' => 'not_a_uuid', 'message' => 'This is not a record id.'];
                continue;
            }
            $value = Uuid::fromString($id)->toRfc4122();
            if (isset($seen[$value])) {
                $errors[] = ['pointer' => '/ids/'.$n, 'code' => 'duplicate_id', 'message' => 'This record is already named earlier in the list.'];
                continue;
            }
            $seen[$value] = true;
            $canonical[] = $value;
        }

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }

        return $canonical;
    }
}

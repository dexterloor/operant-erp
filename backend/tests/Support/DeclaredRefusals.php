<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Platform\Http\ProblemResponses;
use PHPUnit\Framework\Assert;

/**
 * **Answerable ⇒ declared** (BK-18 R18-h C1, T-3): a test that has just provoked a refusal asks the published spec
 * whether the operation lists that status, in the house shape.
 *
 * Used by the tests that already drive each status, so the published list is held to behaviour, not only to the
 * contract's sentence (`ErrorResponsesTest` T-1 holds it to the sentence). The spec is generated once per test class
 * and kept: regenerating per call would multiply the cost by the number of calls.
 *
 * The transport refusals (405, 406, 415) are stated once for the whole API, not per operation, so they are never asked
 * here; {@see self::TRANSPORT_STATUSES}.
 */
trait DeclaredRefusals
{
    /** @var array<class-string, array<string, mixed>> */
    private static array $declaredRefusalsSpec = [];

    /**
     * 405, 406 and 415 answer how a request was sent, not what it asked; the API's description states them once.
     *
     * @var list<int>
     */
    public const TRANSPORT_STATUSES = [405, 406, 415];

    protected function assertSpecDeclaresRefusal(string $operationId, int $status): void
    {
        $fault = self::undeclaredRefusal($this->publishedSpec(), $operationId, $status);

        Assert::assertNull($fault, (string) $fault);
    }

    /**
     * A refusal body the server really sent fits `Problem`: its members are among `Problem`'s and include every
     * required one, and each item of a list member (`errors[]`) has exactly its item schema's required members.
     *
     * @param array<string, mixed> $body
     */
    protected function assertBodyFitsProblem(array $body, string $label): void
    {
        $problem = $this->publishedSpec()['components']['schemas'][ProblemResponses::SCHEMA] ?? [];
        $members = array_keys($problem['properties'] ?? []);
        Assert::assertNotSame([], $members, 'The published spec has no '.ProblemResponses::SCHEMA.' schema.');
        Assert::assertSame([], array_values(array_diff(array_keys($body), $members)), $label.': the body carries members '.ProblemResponses::SCHEMA.' does not declare.');
        Assert::assertSame([], array_values(array_diff($problem['required'] ?? [], array_keys($body))), $label.': the body lacks a member '.ProblemResponses::SCHEMA.' requires.');

        // Each item of a list member (`errors`) carries exactly the item schema's required members.
        $schemas = $this->publishedSpec()['components']['schemas'];
        foreach ($problem['properties'] ?? [] as $member => $schema) {
            $ref = $schema['items']['$ref'] ?? null;
            if (!\is_string($ref) || !isset($body[$member])) {
                continue;
            }
            $required = $schemas[substr($ref, \strlen('#/components/schemas/'))]['required'] ?? [];
            sort($required);
            Assert::assertIsArray($body[$member], $label.': '.$member.' is not a list.');
            foreach ($body[$member] as $item) {
                $keys = array_keys((array) $item);
                sort($keys);
                Assert::assertSame($required, $keys, $label.': an item of '.$member.' is not '.$ref.'.');
            }
        }
    }

    /**
     * The spec as the generator produces it, once per test class.
     *
     * @return array<string, mixed>
     */
    protected function publishedSpec(): array
    {
        return self::$declaredRefusalsSpec[static::class] ??= GeneratedSpec::regenerate(self::$kernel ?? self::bootKernel());
    }

    /**
     * Null when the spec declares `$status` on `$operationId` as one `application/problem+json` entry referencing
     * `Problem`; otherwise the sentence naming what is wrong. Pure, so a planted spec can prove it fires.
     *
     * @param array<string, mixed> $spec
     */
    public static function undeclaredRefusal(array $spec, string $operationId, int $status): ?string
    {
        foreach (GeneratedSpec::operations($spec) as $entry) {
            if (($entry['operation']['operationId'] ?? null) !== $operationId) {
                continue;
            }
            $response = $entry['operation']['responses'][(string) $status] ?? null;
            if (!\is_array($response)) {
                return $operationId.' answered '.$status.', which its published responses do not declare.';
            }
            $content = $response['content'] ?? [];
            $expected = [ProblemResponses::MEDIA_TYPE => ['schema' => ['$ref' => '#/components/schemas/'.ProblemResponses::SCHEMA]]];
            if ($content !== $expected) {
                return $operationId.' declares '.$status.', but not as one '.ProblemResponses::MEDIA_TYPE.' entry referencing '.ProblemResponses::SCHEMA.': '.json_encode($content);
            }

            return null;
        }

        return $operationId.' is not in the published spec.';
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\HttpOperation;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Http\Problem\ProblemType;
use App\Platform\Security\PermissionRequirement;
use App\Platform\Security\PermissionRequirements;
use App\Tests\Support\ContractDocument;
use App\Tests\Support\DeclaredRefusals;
use App\Tests\Support\ErrorContractRules;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * `docs/API.md` §5.2 — the `Errors` column is decidable only because one
 * sentence says which catalogue types are universal. This test reads that
 * sentence, and §4.2's cells, out of the document; neither side is a list here.
 */
final class ErrorContractTest extends PurchaseOrderTestCase
{
    use ArchivedRowSubjects;
    use DeclaredRefusals;

    /**
     * Every problem document the probes received, with its label, for the body check (BK-18 R18-h C1, T-3).
     *
     * @var list<array{0: string, 1: array<string, mixed>}>
     */
    private array $problemBodies = [];

    /** The fourth probe's label: clause 15's write on a soft-deleted row (ST-9). */
    private const STATEFUL_PROBE = 'soft-deleted row, ST-9';

    /**
     * *fails if:* an operation's `Errors` cell names a universal type; **or** an
     * operation returns a non-universal type its cell does not name.
     *
     * Half 1 runs over every §4.2 row. Half 2 runs over every operation in
     * `TransactionBoundaryTest::contractOperations()` — the built rows — driving
     * each with the requests every operation answers without a fixture: a
     * missing row with an empty body, an unacceptable `Accept`, and no token. Every problem `type` that comes back must be universal for that
     * operation or named in its cell.
     *
     * **The fourth probe (V1-C1a, V4 B5 — the owner's Q3 ruling of 2026-09-15).**
     * Half 2 also judges every write on a soft-deleted row that
     * `ArchivedRowTest::aSoftDeletedRowRefusesEveryWrite` drives (ST-9): the
     * domain, the fixtures, the bodies, the live-twin control and the requests
     * are `ArchivedRowSubjects::archivedRowWriteAnswers()`, the one derivation
     * both tests use, so neither keeps a copy. Those are real stateful
     * `business-rule-violated` refusals, so a cell missing that type on one of
     * those operations is red with nothing planted. Controls inside the method:
     * the fourth probe's domain is non-empty; each of its answers is a type NOT
     * universal for its operation (else that cell is not under test); and, per
     * stateful refusal, the detector run against the cell without that type
     * names it (a planted positive).
     *
     * **Database dependency, since B5.** The fourth probe seeds `inventory_item`,
     * `purchase_order` and `purchase_order_line` rows in `TENANT_A` as the schema
     * owner, so this class extends `PurchaseOrderTestCase` and needs the migrated
     * test database; the three fixture-free probes need none. The fourth probe
     * runs first, because a refused write closes the entity manager the fixtures
     * are seeded through.
     *
     * Mutation 1 (document): `not-found` added to `CreateSupplier`'s cell → RED,
     * half 1. Mutation 2 (API.md's reverse mutation; re-run 2026-09-15 and again
     * 2026-09-16 in B5, cache deleted before each run): `CreateGoodsReceiptProcessor::process` made to
     * throw `ApiProblem::businessRuleViolated()` as its first statement and
     * `business-rule-violated` removed from `CreateGoodsReceipt`'s cell → RED,
     * half 2, "CreateGoodsReceipt (missing row, empty body) returned
     * `business-rule-violated`"; the same throw with the cell intact → GREEN.
     * Reverts → GREEN. (Until 2026-09-15 the subject was `stale-record`, which
     * that cell named and nothing raises; the cell no longer names it.)
     * Mutation 5 (document-only reverse mutation, B5): `business-rule-violated`
     * removed from `UpdateItem`'s cell, nothing planted → GREEN at `de09853`
     * (before the fourth probe), RED after, "UpdateItem (soft-deleted row, ST-9)
     * returned `business-rule-violated`". Recorded in
     * `docs/session10/progress-B5.md`, with the three controls each shown RED.
     *
     * ⚠ **What half 2 still cannot see, measured.** Removing
     * `business-rule-violated` from `CreateGoodsReceipt`'s cell with NO throw
     * planted stays GREEN, although the operation really raises it
     * (`GoodsReceiptCreateTest::anOverReceiptIsRefusedAndNothingIsWritten`):
     * the fourth probe reaches only clause 15's writes (a `PATCH`, a `DELETE` or
     * a transition on a built soft-deleted resource), never a `Create`, a
     * `Restore`, `IssueToken` or `ChangePassword`, and the three fixture-free
     * requests reach no non-universal type on any built operation (2026-09-15:
     * clearing every such entry from all fifteen built rows stayed green). So:
     * (1) a stateful refusal missing from the cell of an operation outside the
     * fourth probe's domain is invisible here; (2) **a type a cell lists that
     * nothing can raise is invisible to every probe** — how `stale-record` stayed
     * on `CreateGoodsReceipt` from 2026-09-11 to 2026-09-15. (2) is carried as
     * V1-C1b (owner BE-LEAD + PO; re-checked at each new `Errors` entry and at
     * M4; `docs/session10/v4-worklist.md` §4.2), not built here.
     *
     * **It was red on the day it was written**, on the no-token probe of every
     * operation outside `/auth/`: §5.2's sentence did not name
     * `authentication-failed`, while §4.2's `ChangePassword` paragraph said it is
     * never listed there. §5.2 was corrected 2026-09-14 (C5 row 16) with a third,
     * path-conditional clause, which this test parses like the other two.
     * Mutation 3 (document): that clause's `authentication-failed` renamed to a
     * non-catalogue word → RED, the parser refuses a clause yielding no type;
     * renamed to `sign-in-paused` instead → RED, half 2, every no-token probe
     * outside `/auth/` returning an unlisted `authentication-failed`.
     * Mutation 4 (document): `authentication-failed` added to `ChangePassword`'s
     * cell → RED, half 1, naming it as universal.
     */
    #[Test]
    public function everyOperationDeclaresItsNonUniversalErrors(): void
    {
        if (!ContractDocument::isAvailable()) {
            self::markTestSkipped('docs/API.md is gitignored and absent: both the universal sentence and §4.2\'s cells live there.');
        }

        $catalogue = array_keys(ContractDocument::problemCatalogue());
        self::assertGreaterThan(10, \count($catalogue), '§5.2\'s catalogue parsed to almost nothing.');

        [$always, $keyed] = ErrorContractRules::universalSentence($catalogue);
        $tokenGated = ErrorContractRules::tokenGatedClause($catalogue);
        $rows = ContractDocument::operationRows();
        self::assertNotSame([], $rows, '§4.2 yielded no rows, so half 1 would be vacuous.');

        // Half 1 — no cell names a universal type.
        $named = [];
        $cellFaults = [];
        foreach ($rows as $operationId => $row) {
            $cell = ErrorContractRules::cellTypes($row['errors']);
            foreach (array_diff($cell, $catalogue) as $unknown) {
                $cellFaults[] = $operationId.' names `'.$unknown.'`, which is not in §5.2';
            }
            foreach (array_intersect($cell, ErrorContractRules::universalFor($operationId, $row['path'], $always, $keyed, $tokenGated)) as $universal) {
                $cellFaults[] = $operationId.' names universal `'.$universal.'`';
            }
            $named[$operationId] = $cell;
        }
        self::assertSame([], $cellFaults, '§5.2: an Errors cell names a universal or uncatalogued type.');

        // Half 2 — no built operation returns a non-universal type its cell omits.
        $registry = TransactionBoundaryTest::contractOperations();
        self::assertNotSame([], $registry, 'The registry is empty, so half 2 would be vacuous.');

        // The fourth probe first (V1-C1a, V4 B5): its fixtures are all seeded before
        // any request, because a refused write closes the entity manager they use.
        $stateful = [];
        foreach ($this->archivedRowWriteAnswers() as $name => $answer) {
            self::assertArrayHasKey($name, $registry, $name.' was driven by the fourth probe and is not in the registry.');
            $stateful[$name] = self::problemTypeOf($answer['contentType'], $answer['content']);
            if (null !== $stateful[$name]) {
                $this->problemBodies[] = [$name.' ('.self::STATEFUL_PROBE.')', (array) json_decode($answer['content'], true)];
            }
        }
        self::assertNotSame([], $stateful, 'The fourth probe drove no soft-deleted-row write, so half 2 observes no stateful refusal.');

        $allowed = [];
        $observations = [];
        foreach ($registry as $name => $operation) {
            self::assertArrayHasKey($name, $named, $name.' is built and has no §4.2 row.');
            $allowed[$name] = [...ErrorContractRules::universalFor($name, $rows[$name]['path'], $always, $keyed, $tokenGated), ...$named[$name]];

            foreach ($this->probe($operation) as $probe => $type) {
                $observations[] = [$name, $probe, $type];
            }
            if (\array_key_exists($name, $stateful) && null !== $stateful[$name]) {
                $observations[] = [$name, self::STATEFUL_PROBE, $stateful[$name]];
            }
        }

        self::assertGreaterThan(\count($registry), \count($observations), 'The probes returned almost no problem documents, so half 2 had no subject.');

        // Control: every fourth-probe answer is a problem type that is NOT universal
        // for its operation — otherwise that operation's cell is not under test.
        $reached = [];
        foreach ($stateful as $name => $type) {
            if (null !== $type && !\in_array($type, ErrorContractRules::universalFor($name, $rows[$name]['path'], $always, $keyed, $tokenGated), true)) {
                $reached[] = $name;
            }
        }
        self::assertSame(array_keys($stateful), $reached, 'The fourth probe did not reach a non-universal problem type on every write it drove: '.json_encode($stateful));

        // Control: a planted positive fires. For each stateful refusal, the same
        // observations judged against its cell without that type must name it.
        foreach ($stateful as $name => $type) {
            $planted = $allowed;
            $planted[$name] = array_values(array_diff($allowed[$name], [$type]));
            self::assertContains(self::undeclaredEntry($name, self::STATEFUL_PROBE, (string) $type), self::undeclared($observations, $planted),
                'A planted cell without `'.$type.'` on '.$name.' was not detected, so the fourth probe does not reach the detector.');
        }

        self::assertSame([], self::undeclared($observations, $allowed), '§5.2: an operation returned a non-universal type its Errors cell does not name.');

        // T-3 (BK-18 R18-h C1): answerable => declared. Every status a probe got must be listed on that operation in the
        // published spec, in the house shape; and every body must fit `Problem`. 405/406/415 answer how the request was
        // sent and are stated once for the whole API, so they are not asked per operation (the "unacceptable Accept"
        // probe's 406 is one of them).
        $asked = 0;
        foreach ($observations as [$name, $probe, $type]) {
            $status = ProblemType::from($type)->status();
            if (!\in_array($status, self::TRANSPORT_STATUSES, true)) {
                $this->assertSpecDeclaresRefusal($name, $status);
                ++$asked;
            }
        }
        self::assertGreaterThan(\count($registry), $asked, 'Almost no probe answer was a per-operation refusal, so the spec was not asked about any.');
        self::assertGreaterThan(\count($registry), \count($this->problemBodies), 'The probes collected almost no problem bodies.');
        foreach ($this->problemBodies as [$label, $body]) {
            $this->assertBodyFitsProblem($body, $label);
        }
    }

    /**
     * @param list<array{0: string, 1: string, 2: string}> $observations operation, probe label, problem type
     * @param array<string, list<string>>                   $allowed      operation => the types it may return
     *
     * @return list<string>
     */
    private static function undeclared(array $observations, array $allowed): array
    {
        $undeclared = [];
        foreach ($observations as [$name, $probe, $type]) {
            if (!\in_array($type, $allowed[$name] ?? [], true)) {
                $undeclared[] = self::undeclaredEntry($name, $probe, $type);
            }
        }

        return array_values(array_unique($undeclared));
    }

    private static function undeclaredEntry(string $name, string $probe, string $type): string
    {
        return $name.' ('.$probe.') returned `'.$type.'`';
    }

    /**
     * The problem types one operation returns to the fixture-free requests.
     *
     * @return array<string, string> probe label => problem type slug
     */
    private function probe(HttpOperation $operation): array
    {
        $method = (string) $operation->getMethod();
        $path = (string) preg_replace_callback('/\{[^}]+\}/', static fn (): string => Uuid::v4()->toRfc4122(), (string) $operation->getUriTemplate());
        $hasBody = \in_array($method, ['POST', 'PATCH'], true);
        $key = true === ($operation->getExtraProperties()[IdempotencyKeyGuard::OPERATION_FLAG] ?? false)
            ? [IdempotencyKeyGuard::HEADER => 'c5-error-contract-'.Uuid::v4()->toRfc4122()]
            : [];

        // The DERIVED arm (G6-C0; G5-D0's carry (3)): a derived gate reads the body's `resource` BEFORE the decision, so an
        // empty body (`{}` on the wire) is refused 422 at `/resource` ahead of every row this probe exists to reach. A
        // derived operation is therefore probed with a body naming the first resource of its map (byte-sorted) -- the smallest body the gate passes --
        // and the "unacceptable Accept" probe sends it too, so it reaches the 406 rather than the gate's `malformed_body`.
        $body = $hasBody ? [] : null;
        $requirement = PermissionRequirement::of($operation);
        if (PermissionRequirement::DERIVED === $requirement->kind()) {
            $requirements = self::getContainer()->get(PermissionRequirements::class);
            \assert($requirements instanceof PermissionRequirements);
            $body = ['resource' => (string) array_key_first($requirements->codesByResource($requirement))];
        }

        $types = [];

        // Past the permission gate on every operation (G7b, RED of the default-actor decision): the rig's default actor
        // leaves out the owner marker (`ApiTestCase::actAs()`), so an operation gated on it (`UpdateSettings`) would answer
        // only the universal `permission-denied` and this walk would be blind to every type it returns beyond the gate.
        $this->actAs(self::TENANT_A, self::everyDeclaredPermission());
        $this->send($method, $path, $body, $key);
        $types['missing row, empty body'] = $this->problemType();
        if (PermissionRequirement::NONE !== $requirement->kind()) {
            self::assertNotSame('permission-denied', $types['missing row, empty body'], (string) $operation->getName().': the probe did not get past the permission gate, so its Errors cell is not under test.');
        }

        $this->send($method, $path, PermissionRequirement::DERIVED === $requirement->kind() ? $body : null, $key, 'application/xml');
        $types['unacceptable Accept'] = $this->problemType();

        $this->actAsNobody();
        $this->send($method, $path, $body, $key);
        $types['no token'] = $this->problemType();
        $this->actAs(self::TENANT_A, self::everyDeclaredPermission());

        return array_filter($types, static fn (?string $type): bool => null !== $type);
    }

    private function problemType(): ?string
    {
        $response = $this->client()->getResponse();
        $type = self::problemTypeOf((string) $response->headers->get('Content-Type'), (string) $response->getContent());
        if (null !== $type) {
            $this->problemBodies[] = [(string) $response->getStatusCode().' '.$type, (array) json_decode((string) $response->getContent(), true)];
        }

        return $type;
    }

    /** The problem type slug of one answer, or null when the answer is not a problem document. */
    private static function problemTypeOf(string $contentType, string $content): ?string
    {
        if (!str_starts_with($contentType, 'application/problem+json')) {
            return null;
        }

        $type = json_decode($content, true)['type'] ?? '';

        return str_starts_with((string) $type, 'urn:erp:problem:') ? substr((string) $type, 16) : 'non-urn:'.$type;
    }
}

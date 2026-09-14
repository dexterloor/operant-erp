<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\HttpOperation;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Tests\Support\ContractDocument;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * `docs/API.md` §5.2 — the `Errors` column is decidable only because one
 * sentence says which catalogue types are universal. This test reads that
 * sentence, and §4.2's cells, out of the document; neither side is a list here.
 */
final class ErrorContractTest extends ApiTestCase
{
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
     * Mutation 1 (document): `not-found` added to `CreateSupplier`'s cell → RED,
     * half 1. Mutation 2 (API.md's reverse mutation): `stale-record` removed from
     * `CreateGoodsReceipt`'s cell and the operation made to raise it → RED,
     * half 2. Reverts → GREEN.
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

        [$always, $keyed] = self::universalSentence($catalogue);
        $tokenGated = self::tokenGatedClause($catalogue);
        $rows = ContractDocument::operationRows();
        self::assertNotSame([], $rows, '§4.2 yielded no rows, so half 1 would be vacuous.');

        // Half 1 — no cell names a universal type.
        $named = [];
        $cellFaults = [];
        foreach ($rows as $operationId => $row) {
            $cell = self::cellTypes($row['errors']);
            foreach (array_diff($cell, $catalogue) as $unknown) {
                $cellFaults[] = $operationId.' names `'.$unknown.'`, which is not in §5.2';
            }
            foreach (array_intersect($cell, self::universalFor($operationId, $row['path'], $always, $keyed, $tokenGated)) as $universal) {
                $cellFaults[] = $operationId.' names universal `'.$universal.'`';
            }
            $named[$operationId] = $cell;
        }
        self::assertSame([], $cellFaults, '§5.2: an Errors cell names a universal or uncatalogued type.');

        // Half 2 — no built operation returns a non-universal type its cell omits.
        $registry = TransactionBoundaryTest::contractOperations();
        self::assertNotSame([], $registry, 'The registry is empty, so half 2 would be vacuous.');

        $observed = 0;
        $undeclared = [];
        foreach ($registry as $name => $operation) {
            self::assertArrayHasKey($name, $named, $name.' is built and has no §4.2 row.');
            $allowed = [...self::universalFor($name, $rows[$name]['path'], $always, $keyed, $tokenGated), ...$named[$name]];

            foreach ($this->probe($operation) as $probe => $type) {
                ++$observed;
                if (!\in_array($type, $allowed, true)) {
                    $undeclared[] = $name.' ('.$probe.') returned `'.$type.'`';
                }
            }
        }

        self::assertGreaterThan(\count($registry), $observed, 'The probes returned almost no problem documents, so half 2 had no subject.');
        self::assertSame([], array_values(array_unique($undeclared)), '§5.2: an operation returned a non-universal type its Errors cell does not name.');
    }

    /**
     * §5.2's sentence: the types before "are **universal**", and the types after
     * "plus" that are universal only where `ContractDocument::headerIsRequiredFor()`.
     *
     * @param list<string> $catalogue
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function universalSentence(array $catalogue): array
    {
        // Whitespace folded first: the sentence is wrapped across lines.
        $section = (string) preg_replace('/\s+/', ' ', ContractDocument::sectionBetween('### 5.2 ', '### 5.2a'));
        $start = strpos($section, '**Which of these are universal');
        self::assertNotFalse($start, '§5.2\'s universal sentence was not found.');

        $sentence = substr($section, $start, (int) strpos($section, 'already declares.', $start) - $start);
        $pivot = strpos($sentence, 'are **universal**');
        $plus = strpos($sentence, 'plus ');
        $on = strpos($sentence, ' on exactly the operations');
        self::assertTrue(false !== $pivot && false !== $plus && false !== $on && $pivot < $plus && $plus < $on, '§5.2\'s universal sentence no longer has the shape this parser reads.');

        $always = array_values(array_intersect(self::cellTypes(substr($sentence, 0, $pivot)), $catalogue));
        $keyed = array_values(array_intersect(self::cellTypes(substr($sentence, $plus, $on - $plus)), $catalogue));

        self::assertNotSame([], $always, 'The universal sentence yielded no type.');
        self::assertNotSame([], $keyed, 'The universal sentence yielded no header-conditional type.');

        return [$always, $keyed];
    }

    /**
     * §5.2's third clause, corrected 2026-09-14 (C5 row 16): the types universal
     * on exactly the operations whose §4.2 `Path` is outside `/auth/`.
     *
     * @param list<string> $catalogue
     *
     * @return list<string>
     */
    private static function tokenGatedClause(array $catalogue): array
    {
        $section = (string) preg_replace('/\s+/', ' ', ContractDocument::sectionBetween('### 5.2 ', '### 5.2a'));
        $marker = ' is universal on exactly the operations whose §4.2 `Path` is outside `/auth/`**';
        $end = strpos($section, $marker);
        $start = false === $end ? false : strrpos(substr($section, 0, $end), '**And ');
        self::assertTrue(false !== $end && false !== $start, '§5.2\'s path-conditional universal clause was not found, or no longer has the shape this parser reads.');

        $types = array_values(array_intersect(self::cellTypes(substr($section, $start, $end - $start)), $catalogue));
        self::assertNotSame([], $types, '§5.2\'s path-conditional universal clause yielded no catalogued type.');

        return $types;
    }

    /**
     * @param list<string> $always
     * @param list<string> $keyed
     * @param list<string> $tokenGated
     *
     * @return list<string>
     */
    private static function universalFor(string $operationId, string $path, array $always, array $keyed, array $tokenGated): array
    {
        return [
            ...$always,
            ...(ContractDocument::headerIsRequiredFor($operationId) ? $keyed : []),
            ...(str_starts_with($path, '/auth/') ? [] : $tokenGated),
        ];
    }

    /** @return list<string> */
    private static function cellTypes(string $text): array
    {
        preg_match_all('/`([a-z]+(?:-[a-z]+)*)`/', $text, $matches);

        return array_values(array_unique($matches[1]));
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

        $types = [];

        $this->send($method, $path, $hasBody ? [] : null, $key);
        $types['missing row, empty body'] = $this->problemType();

        $this->send($method, $path, null, $key, 'application/xml');
        $types['unacceptable Accept'] = $this->problemType();

        $this->actAsNobody();
        $this->send($method, $path, $hasBody ? [] : null, $key);
        $types['no token'] = $this->problemType();
        $this->actAs(self::TENANT_A);

        return array_filter($types, static fn (?string $type): bool => null !== $type);
    }

    private function problemType(): ?string
    {
        $response = $this->client()->getResponse();
        if (!str_starts_with((string) $response->headers->get('Content-Type'), 'application/problem+json')) {
            return null;
        }

        $type = json_decode((string) $response->getContent(), true)['type'] ?? '';

        return str_starts_with((string) $type, 'urn:erp:problem:') ? substr((string) $type, 16) : 'non-urn:'.$type;
    }
}

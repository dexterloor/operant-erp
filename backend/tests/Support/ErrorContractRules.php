<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * `docs/API.md` §5.2's universal sentence and §4.2's `Errors` cells, parsed once for every test that needs them.
 *
 * Moved out of `ErrorContractTest` (BK-18 R18-h C1) so `ErrorResponsesTest` reads the same sentence the same way, never
 * a retyped copy. Behaviour is unchanged: `ErrorContractTest` calls these and is re-run before and after with identical
 * test and assertion counts. `problemCatalogue()` stays in {@see ContractDocument}.
 */
final class ErrorContractRules
{
    /**
     * §5.2's sentence: the types before "are **universal**", and the types after "plus" that are universal only where
     * `ContractDocument::headerIsRequiredFor()`.
     *
     * @param list<string> $catalogue
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function universalSentence(array $catalogue): array
    {
        // Whitespace folded first: the sentence is wrapped across lines.
        $section = self::foldedSection();
        $start = strpos($section, '**Which of these are universal');
        Assert::assertNotFalse($start, '§5.2\'s universal sentence was not found.');

        $sentence = substr($section, $start, (int) strpos($section, 'already declares.', $start) - $start);
        $pivot = strpos($sentence, 'are **universal**');
        $plus = strpos($sentence, 'plus ');
        $on = strpos($sentence, ' on exactly the operations');
        Assert::assertTrue(false !== $pivot && false !== $plus && false !== $on && $pivot < $plus && $plus < $on, '§5.2\'s universal sentence no longer has the shape this parser reads.');

        $always = array_values(array_intersect(self::cellTypes(substr($sentence, 0, $pivot)), $catalogue));
        $keyed = array_values(array_intersect(self::cellTypes(substr($sentence, $plus, $on - $plus)), $catalogue));

        Assert::assertNotSame([], $always, 'The universal sentence yielded no type.');
        Assert::assertNotSame([], $keyed, 'The universal sentence yielded no header-conditional type.');

        return [$always, $keyed];
    }

    /**
     * §5.2's third clause, corrected 2026-09-14 (C5 row 16): the types universal on exactly the operations whose §4.2
     * `Path` is outside `/auth/`.
     *
     * @param list<string> $catalogue
     *
     * @return list<string>
     */
    public static function tokenGatedClause(array $catalogue): array
    {
        $section = self::foldedSection();
        $marker = ' is universal on exactly the operations whose §4.2 `Path` is outside `/auth/`**';
        $end = strpos($section, $marker);
        $start = false === $end ? false : strrpos(substr($section, 0, $end), '**And ');
        Assert::assertTrue(false !== $end && false !== $start, '§5.2\'s path-conditional universal clause was not found, or no longer has the shape this parser reads.');

        $types = array_values(array_intersect(self::cellTypes(substr($section, $start, $end - $start)), $catalogue));
        Assert::assertNotSame([], $types, '§5.2\'s path-conditional universal clause yielded no catalogued type.');

        return $types;
    }

    /**
     * @param list<string> $always
     * @param list<string> $keyed
     * @param list<string> $tokenGated
     *
     * @return list<string>
     */
    public static function universalFor(string $operationId, string $path, array $always, array $keyed, array $tokenGated): array
    {
        return [
            ...$always,
            ...(ContractDocument::headerIsRequiredFor($operationId) ? $keyed : []),
            ...(str_starts_with($path, '/auth/') ? [] : $tokenGated),
        ];
    }

    /** @return list<string> */
    public static function cellTypes(string $text): array
    {
        preg_match_all('/`([a-z]+(?:-[a-z]+)*)`/', $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** §5.2 with every run of whitespace folded to one space: its sentences are wrapped across lines. */
    public static function foldedSection(): string
    {
        return (string) preg_replace('/\s+/', ' ', ContractDocument::sectionBetween('### 5.2 ', '### 5.2a'));
    }
}

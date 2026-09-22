<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ContractDocument;
use App\Tests\Support\GeneratedSpec;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\String\Inflector\EnglishInflector;

/**
 * `docs/API.md` §12.3 — operationIds. The per-module halves
 * (`ItemOperationContractTest`, `PurchaseOrderOperationContractTest`,
 * `AuthOperationContractTest`) each carry `::everyOperationIdIsItsOwnVerbAndResource`
 * over their own pin; this class holds the rule that spans every operation.
 */
final class OperationIdTest extends ApiTestCase
{
    /**
     * §12.3's one exception: `Settings` is a singleton resource whose name is a
     * mass noun. Declared here so that adding a second is an edit somebody makes
     * on purpose — the `PINNED_ALLOWLIST` mechanism.
     */
    public const PINNED_SINGLETON_RESOURCES = ['Settings'];

    /**
     * §12.3 — a `List` operation names a plural resource and every other verb a
     * singular one, outside the pinned exception. **Collection-ness is decided
     * by the verb, never by the resource string**; plurality is decided by
     * {@see self::isPlural()}.
     *
     * Domain: every operationId in the **generated spec** (phase 2), split into
     * its leading verb word and the rest; and, wherever the document exists,
     * every §4.2 row by its own `Verb / Resource` columns (phase 1), with the
     * spec's split held equal to the row's so the two halves agree on what the
     * verb is.
     *
     * Mutation 1 (code): `ListItems` → name and operationId `ListItem` → RED,
     * naming `ListItem`. Mutation 2 (document, API.md's own "third mutation"):
     * `ListAuditLogEntries` → `ListAuditLog` / `AuditLog` → RED naming it, while
     * the concatenation checks stay green. Reverts → GREEN.
     */
    #[Test]
    public function everyListOperationNamesAPluralResource(): void
    {
        // Positive and negative controls for the predicate, inside this test.
        foreach (['Items', 'PurchaseOrderLines', 'AuditLogEntries', 'CustomerAddresses', 'Warehouses'] as $plural) {
            self::assertTrue(self::isPlural($plural), 'The plurality predicate reads '.$plural.' as singular.');
        }
        foreach (['Item', 'PurchaseOrderLine', 'AuditLog', 'CustomerAddress', 'Password', 'Token', 'CurrentUser'] as $singular) {
            self::assertFalse(self::isPlural($singular), 'The plurality predicate reads '.$singular.' as plural.');
        }

        $spec = GeneratedSpec::regenerate(self::$kernel ?? throw new \LogicException('The kernel is booted in setUp().'));
        $split = [];
        foreach (GeneratedSpec::operations($spec) as $label => $operation) {
            $operationId = (string) ($operation['operation']['operationId'] ?? '');
            self::assertSame(1, preg_match('/^([A-Z][a-z]+)([A-Z][A-Za-z]*)$/', $operationId, $m), $label.'\'s operationId "'.$operationId.'" is not Verb ++ Resource.');
            $split[$operationId] = [$m[1], $m[2]];
        }

        self::assertNotSame([], $split, 'The generated spec declares no operation, so there is no domain.');
        self::assertCount(\count(TransactionBoundaryTest::contractOperations()), $split, 'The spec does not carry one operationId per registry operation.');

        self::assertSame([], self::offenders($split), '§12.3: an operation names a resource of the wrong number.');

        if (!ContractDocument::isAvailable()) {
            self::markTestSkipped('docs/API.md is gitignored and absent, so §4.2\'s unbuilt rows cannot be read. The generated spec above was checked.');
        }

        $rows = [];
        foreach (ContractDocument::operationRows() as $operationId => $row) {
            $rows[$operationId] = array_map(trim(...), explode('/', $row['verbResource'], 2)) + [1 => ''];
        }
        self::assertNotSame([], $rows, '§4.2 yielded no rows, so the document half would be vacuous.');

        foreach ($split as $operationId => $verbAndResource) {
            self::assertArrayHasKey($operationId, $rows, $operationId.' is built and has no §4.2 row.');
            self::assertSame($rows[$operationId], $verbAndResource, $operationId.': the spec\'s verb split is not §4.2\'s Verb / Resource.');
        }

        $used = array_intersect(self::PINNED_SINGLETON_RESOURCES, array_column($rows, 1));
        self::assertSame(self::PINNED_SINGLETON_RESOURCES, array_values($used), 'A pinned singleton resource no longer names any §4.2 row, so the exception is stale.');

        self::assertSame([], self::offenders($rows), '§12.3: a §4.2 row names a resource of the wrong number.');
    }

    /**
     * §12.3's closed verb set, committed. `docs/` is gitignored, so this mirror is
     * what a clean checkout checks; wherever the document is present the mirror is
     * held **equal** to the set parsed from §12.3, so neither can move alone — the
     * `PINNED_` mechanism.
     *
     * `Change` joined on 2026-09-23 by owner ruling (roles slice 1, Q1: *"Add
     * 'Change'"*) — `ChangePassword` had shipped outside the set since 2026-09-09.
     * `Refresh` and `Reject` name no built operation; membership is one-directional
     * (a verb with no row is legal), so they are not offenders.
     */
    public const PINNED_VERBS = [
        'List', 'Get', 'Create', 'Update', 'Delete', 'Restore', 'Approve', 'Cancel', 'Confirm', 'Issue',
        'Pay', 'Void', 'Assign', 'Revoke', 'Ask', 'Refresh', 'Reverse', 'Reject', 'Change',
    ];

    /**
     * §12.3 — *"`Verb` is drawn from a closed set"*. `RESIDUAL_GAPS.md` CD-6's
     * closing work: until this method, adding a verb to the set, or shipping an
     * operation outside it, reddened nothing.
     *
     * Domain, phase 2: every operationId in the **generated spec**, split with the
     * plural test's leading-word rule; each verb must be in {@see self::PINNED_VERBS}.
     * Phase 1, wherever `docs/API.md` exists: every §4.2 row's `Verb` column,
     * built or not, must be in the set parsed from §12.3 — which is §12.3's own
     * *"Second mutation"* (*"add a row with verb `Post`"*) given a mechanism. The
     * mirror and the parsed set are held equal.
     *
     * Membership only, one direction: a verb in the set that no row uses is legal.
     *
     * Controls, inside the test, so none can pass by having no subject: the
     * membership predicate refuses a planted `Post` and accepts `Reject`; the
     * phase-1 function refuses a planted `Fetch / Things` row (no mutation is ever
     * run against the document itself — {@see ContractDocument::path()} is fixed,
     * and the document is shared with a parallel track); the parser stops at the
     * bullet's first full stop and takes one capitalised word per token, so a
     * parenthetical `` `ChangePassword` `` is never read as a verb.
     *
     * Mutations (roles slice 1, C1; results in `docs/session13/roles-s1-progress.md`):
     * M1 remove `Change` from the pin → RED naming `ChangePassword`; M2 add `Post`
     * to the pin only → RED, pin ≠ §12.3; M3′ make the phase-1 function accept
     * everything → RED on the planted `Fetch` control; M4 make
     * {@see self::extractVerb()} return `''` → RED on membership.
     */
    #[Test]
    public function everyOperationVerbIsInTheClosedSet(): void
    {
        // Parser control: stops at the first full stop; one capitalised word per token.
        self::assertSame(
            ['Change', 'Get'],
            self::parseClosedVerbSet("- **`Verb` is drawn from a closed set**: `Change` (for `ChangePassword`), `Get`.\n  A verb `Post` after the stop."),
            'The §12.3 parser took a token it must not: a parenthetical operationId, or a word after the bullet\'s full stop.',
        );
        self::assertSame([], self::parseClosedVerbSet('no such bullet here.'), 'The parser invented a set from text that has no closed-set bullet.');

        // Membership controls.
        self::assertSame(['PostThings (Post)'], self::verbOffenders(['PostThings' => 'Post', 'RejectThing' => 'Reject'], self::PINNED_VERBS), 'The membership predicate accepted a planted `Post`, or refused `Reject`.');

        // Phase-1 control: a planted §4.2 row with an unlisted verb is refused.
        self::assertSame(
            ['FetchThings (Fetch)'],
            self::rowVerbOffenders(['FetchThings' => ['verbResource' => 'Fetch / Things'], 'GetThing' => ['verbResource' => 'Get / Thing']], self::PINNED_VERBS),
            'The §4.2 row check accepted a planted row whose verb is outside the set.',
        );

        // Phase 2: the generated spec.
        $spec = GeneratedSpec::regenerate(self::$kernel ?? throw new \LogicException('The kernel is booted in setUp().'));
        $split = [];
        foreach (GeneratedSpec::operations($spec) as $operation) {
            $operationId = (string) ($operation['operation']['operationId'] ?? '');
            $split[$operationId] = self::extractVerb($operationId);
        }

        self::assertNotSame([], $split, 'The generated spec declares no operation, so there is no domain.');
        self::assertCount(\count(TransactionBoundaryTest::contractOperations()), $split, 'The spec does not carry one operationId per registry operation.');
        self::assertSame([], self::verbOffenders($split, self::PINNED_VERBS), '§12.3: a built operation\'s verb is outside the closed set. Adding a verb is a design decision — add it to §12.3 and to PINNED_VERBS by ruling, never to get green.');

        if (!ContractDocument::isAvailable()) {
            self::markTestSkipped('docs/API.md is gitignored and absent, so §12.3\'s set and §4.2\'s unbuilt rows cannot be read. The generated spec above was checked against PINNED_VERBS.');
        }

        $parsed = self::parseClosedVerbSet(ContractDocument::sectionBetween('### 12.3 ', '### 12.4 '));
        self::assertNotSame([], $parsed, '§12.3\'s closed-set bullet yielded no verb, so the pin would be checked against nothing.');
        $pinned = self::PINNED_VERBS;
        sort($pinned);
        sort($parsed);
        self::assertSame($pinned, $parsed, 'PINNED_VERBS and §12.3\'s closed set differ — one moved without the other.');

        $rows = ContractDocument::operationRows();
        self::assertNotSame([], $rows, '§4.2 yielded no rows, so the document half would be vacuous.');
        self::assertSame([], self::rowVerbOffenders($rows, $parsed), '§12.3: a §4.2 row\'s Verb column is outside the closed set.');
    }

    /**
     * The verbs §12.3's closed-set bullet names: every backticked token from the
     * bullet's colon to its first full stop that is exactly one capitalised word.
     *
     * @return list<string>
     */
    private static function parseClosedVerbSet(string $section): array
    {
        $marker = '**`Verb` is drawn from a closed set**:';
        $at = strpos($section, $marker);
        if (false === $at) {
            return [];
        }
        $rest = substr($section, $at + \strlen($marker));
        $stop = strpos($rest, '.');
        $list = false === $stop ? '' : substr($rest, 0, $stop);

        preg_match_all('/`([^`]+)`/', $list, $m);

        return array_values(array_filter($m[1], static fn (string $token): bool => 1 === preg_match('/^[A-Z][a-z]+$/', $token)));
    }

    /** The leading verb word of an operationId — the plural test's split. */
    private static function extractVerb(string $operationId): string
    {
        return 1 === preg_match('/^([A-Z][a-z]+)/', $operationId, $m) ? $m[1] : '';
    }

    /**
     * @param array<string, string> $verbs    operationId → verb
     * @param list<string>          $closedSet
     *
     * @return list<string>
     */
    private static function verbOffenders(array $verbs, array $closedSet): array
    {
        $offenders = [];
        foreach ($verbs as $operationId => $verb) {
            if (!\in_array($verb, $closedSet, true)) {
                $offenders[] = $operationId.' ('.$verb.')';
            }
        }

        return $offenders;
    }

    /**
     * Phase 1 as a pure function over §4.2 row arrays, so a planted row can be
     * driven through it without touching the document.
     *
     * @param array<string, array{verbResource: string}> $rows
     * @param list<string>                                $closedSet
     *
     * @return list<string>
     */
    private static function rowVerbOffenders(array $rows, array $closedSet): array
    {
        $verbs = [];
        foreach ($rows as $operationId => $row) {
            $verbs[$operationId] = trim(explode('/', $row['verbResource'], 2)[0]);
        }

        return self::verbOffenders($verbs, $closedSet);
    }

    /**
     * @param array<string, array{0: string, 1: string}> $verbAndResource
     *
     * @return list<string>
     */
    private static function offenders(array $verbAndResource): array
    {
        $offenders = [];

        foreach ($verbAndResource as $operationId => [$verb, $resource]) {
            if (\in_array($resource, self::PINNED_SINGLETON_RESOURCES, true)) {
                continue;
            }
            if (('List' === $verb) !== self::isPlural($resource)) {
                $offenders[] = $operationId.' ('.$verb.' / '.$resource.')';
            }
        }

        return $offenders;
    }

    /**
     * A PascalCase resource is plural iff its last word is: the word singularizes
     * to a different word that pluralizes back to it, and it does not end in a
     * double `s` — a regular English plural never does, and without that clause
     * the inflector reads `Address` as the plural of `Addres` (measured).
     */
    private static function isPlural(string $resource): bool
    {
        if (1 !== preg_match('/[A-Z][a-z]*$/', $resource, $m) || str_ends_with($m[0], 'ss')) {
            return false;
        }

        $inflector = new EnglishInflector();
        foreach ($inflector->singularize($m[0]) as $singular) {
            if ($singular !== $m[0] && \in_array($m[0], $inflector->pluralize($singular), true)) {
                return true;
            }
        }

        return false;
    }
}

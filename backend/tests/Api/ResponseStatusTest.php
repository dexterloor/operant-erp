<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\HttpOperation;
use App\Platform\Http\Location\CreatedRowLocation;
use App\Tests\Support\ContractDocument;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * API-21 (`docs/API.md` §4.4.1) — the success-status rule, and the anti-vacuity
 * probe §15 item 11 names first.
 *
 * ---------------------------------------------------------------------------
 * The defect this exists for
 * ---------------------------------------------------------------------------
 * Revision 2 of §4.4.1 keyed its branches on operationId verb prefixes and was
 * not total: three `POST`s matched no branch and one matched two. **A test that
 * iterates a rule's branches reports green over every operation the branches do
 * not reach**, so the probe iterates the operations and counts branches per
 * operation, never the other way round.
 *
 * ---------------------------------------------------------------------------
 * Scope, as ruling A left it
 * ---------------------------------------------------------------------------
 * The domain is the **built** surface — `TransactionBoundaryTest::contractOperations()`,
 * every non-`_api_` operation of every `App\` resource — and it widens by
 * itself as rows are built. `::everyOperationReturnsTheStatusItsBranchRequires`
 * is the sibling this probe protects (added by C5 batch B, 2026-09-14): it
 * drives every operation and holds its answer to the one branch the probe
 * proved it matches. The probe still stands on its own, because a branch table
 * with a hole in it is a defect before any status is asserted against it.
 *
 * ---------------------------------------------------------------------------
 * Two readings taken here, stated rather than hidden
 * ---------------------------------------------------------------------------
 * **(1) "the row-level URI of the row this `POST` creates" is read from the
 * path alone**: the `POST`'s own path plus one `{param}` segment (`/items` →
 * `/items/{id}`), or its last segment re-rooted with one `{param}`
 * (`/purchase-orders/{id}/goods-receipts` → `/goods-receipts/{id}`). Parameter
 * names are ignored. Run over every `POST` in §4.2 on 2026-09-14 it gave the
 * answer §4.4.1's prose states for every operation the prose names —
 * `CreateSupplier`, `IssueInvoice`, `AssignRole` 201; `ApprovePurchaseOrder`,
 * `IssueToken`, `ChangePassword`, `AskAssistant`, `CreateExport`, the three bulk
 * operations, `ReverseInvoicePayment`, `CreateSalesReturn`, `IssueCreditNote` 200.
 *
 * **(2) "§4.2 declares" is evaluated against the registry**, which is §4.2's
 * built projection (§15.1). That keeps the probe free of the gitignored
 * document, so it runs on a clean checkout too. The branch table itself is a
 * pin, and {@see self::theBranchPinIsTheContractsTable()} holds the pin to the
 * document in both directions wherever the document exists.
 *
 * ⚠ **Reading (2) was exact until 2026-09-19 and stopped being so, and the third source below is the
 * repair.** Ruling R-A shipped `CreateSalesOrder` one commit ahead of `GetSalesOrder`, so for the first
 * time a `POST` creates a row §4.2 **does** address — `/sales-orders/{id}` — and the registry does not.
 * Under the registry alone that operation falls to branch 5 and is required to answer **200 with no
 * `Location`**, which contradicts API-21 branch 4 for a row the contract addresses.
 * `CreatePurchaseOrderLine` never showed this: its row carries a built `PATCH` and `DELETE`, so the
 * registry knew the URI even with no item `GET`.
 *
 * **(3) A `POST` also creates an addressable row when it DECLARES the row-level URI**, through
 * `CreatedRowLocation::OPERATION_URI_TEMPLATE`. That is committed code rather than the gitignored
 * document, it is the declaration the listener actually acts on, and it is not the `status:` attribute —
 * which this test must never trust, because that is the per-operation claim it exists to check. The
 * branch-4 `Location` assertion then holds the answer to **that template**, so declaring one is strictly
 * more checked than not declaring one.
 *
 * ⚠ **Reading (3) is PINNED, and the pin is the whole point** (RED exit audit F-1). As first written it
 * took the declaration from any operation that carried one, which handed every `POST` a way to move
 * itself out of branch 5: the auditor gave `ApprovePurchaseOrder` — a transition §4.4.1 names *in terms*
 * as branch 5 — a false `OPERATION_URI_TEMPLATE` plus `status: 201`, and this test went **green** where
 * the same mutation before the extension went red by name. That made a derived, structural
 * classification author-declarable on the one test here whose stated purpose is to be independent of
 * per-operation declarations. {@see self::DECLARED_ROW_URIS} is therefore the closed set reading (3)
 * honours, reconciled in both directions inside both probes, so a new declaration is a reviewed edit to
 * this file rather than something an operation grants itself.
 *
 * **What the pin is NOT, and what commit 3 owes.** It is a pin, not a derivation: it says *these two, by
 * name*, and a reviewer has to agree. The honest derivation is the auditor's second repair — reconcile
 * the declaring set against §4.2, under `ContractDocument::isAvailable()` the way
 * {@see self::theBranchPinIsTheContractsTable()} does: the operations declaring a template must be
 * exactly those whose declared template is a `Path` cell in §4.2 that the registry does not already
 * serve. That is **`GetSalesOrder`'s commit (commit 3)**, where it goes green-by-redundancy for
 * `CreateSalesOrder`, and it is deliberately not built here.
 */
final class ResponseStatusTest extends PurchaseOrderTestCase
{
    use OperationSuccessRecipes;

    /**
     * §4.4.1's table, row for row: branch number → `Method`, whether the branch
     * is the `POST` that creates an addressable row (`true`), the `POST` that
     * does not (`false`), or not a `POST` at all (`null`), and the status.
     *
     * Branches 4 and 5 are written as a predicate and its complement, which is
     * what makes `POST` total by construction — and it is also why the second
     * control in the probe exists: a predicate that is never true would leave
     * the count at one everywhere while branch 4 had no members at all.
     *
     * @var array<int, array{method: string, creates: bool|null, status: int}>
     */
    private const BRANCHES = [
        1 => ['method' => 'GET', 'creates' => null, 'status' => 200],
        2 => ['method' => 'PATCH', 'creates' => null, 'status' => 200],
        3 => ['method' => 'DELETE', 'creates' => null, 'status' => 204],
        4 => ['method' => 'POST', 'creates' => true, 'status' => 201],
        5 => ['method' => 'POST', 'creates' => false, 'status' => 200],
    ];

    /**
     * **Reading (3)'s closed domain** — operation name → the row-level URI template it may declare
     * through `CreatedRowLocation::OPERATION_URI_TEMPLATE`, derived from the tree on 2026-09-19 and
     * pinned here so that it cannot grow by itself.
     *
     * Both members are `POST`s whose row §4.2 addresses and the registry does not:
     * `CreateSalesOrder` because ruling R-A shipped it one commit ahead of `GetSalesOrder`
     * (`SalesOrderResource.php`), and `CreatePurchaseOrderLine` because its row carries a built `PATCH`
     * and `DELETE` but no item `GET` (`PurchaseOrderLineResource.php`).
     *
     * A template declared by an operation that is not named here, or a template that is not the one
     * named here, is **red** — not silently honoured. Adding a member is the deliberate act that says a
     * reviewer agreed the operation really is branch 4.
     *
     * @var array<string, string>
     */
    private const DECLARED_ROW_URIS = [
        'CreatePurchaseOrderLine' => '/purchase-orders/{id}/lines/{lineId}',
        'CreateSalesOrder' => '/sales-orders/{id}',
    ];

    /**
     * **The probe.** Every built operation matches exactly one branch.
     *
     * The controls are inside this method on purpose: a sibling test does not
     * protect a `--filter` run of this one.
     *
     * **Mutation (delete a branch):** remove branch 5 from `BRANCHES`;
     * `ApprovePurchaseOrder`, `CancelPurchaseOrder`, `RestoreItem`, `IssueToken`
     * and `ChangePassword` match zero branches; red. **Mutation (empty the
     * expectation):** `BRANCHES = []`; every operation matches zero, and the
     * per-branch control is red as well.
     */
    #[Test]
    public function everyOperationMatchesExactlyOneStatusBranch(): void
    {
        $operations = TransactionBoundaryTest::contractOperations();
        $paths = array_map(static fn (HttpOperation $operation): string => self::normalised($operation), $operations);

        // Control 1 — the domain is not empty. An empty registry would satisfy
        // "every operation matches one branch" with nothing examined.
        self::assertNotSame([], $operations, 'The registry produced no operations, so the probe examined nothing.');

        // Control 1b — reading (3)'s domain is the pin and nothing else, so no operation can reclassify
        // itself out of branch 5 by declaring a template. Inside the method, for the --filter reason above.
        self::assertTheDeclaringSetIsThePin($operations);

        // Control 2 — the counter can report something other than one. An
        // operation no branch names must count zero, or the assertion below
        // could be a constant.
        self::assertSame([], self::branchesMatching('PUT', '/probe', $paths), 'A method no branch names was classified, so the count below cannot go red.');

        $wrongCount = [];
        $members = array_fill_keys(array_keys(self::BRANCHES), []);

        foreach ($operations as $name => $operation) {
            $matched = self::branchesMatching((string) $operation->getMethod(), $paths[$name], $paths, self::declaredRowUri($name));

            if (1 !== \count($matched)) {
                $wrongCount[] = \sprintf('%s %s %s matches %d branch(es): [%s]', $name, $operation->getMethod(), $paths[$name], \count($matched), implode(', ', $matched));
            }
            foreach ($matched as $branch) {
                $members[$branch][] = $name;
            }
        }

        self::assertSame([], $wrongCount, 'An operation matches no status branch, or more than one. API-21 is total by construction or it is not a rule.');

        // Control 3 — every branch has a member on the built surface. The
        // surface holds a GET, a PATCH, a DELETE, a creating POST and a
        // transition POST today and only grows, so an empty branch here means
        // its predicate is never true, not that the surface lacks the case.
        $empty = array_keys(array_filter($members, static fn (array $names): bool => [] === $names));
        self::assertSame([], $empty, 'A status branch matched no built operation, so its predicate is never true and every POST fell to its complement.');
        self::assertNotSame([], self::BRANCHES, 'The branch table is empty.');
    }

    /**
     * **API-21's second half — every built operation, driven to success, answers
     * the status its branch requires**, and a branch-4 `POST` names the URI the
     * branch tested for; a `DELETE` answers with no body.
     *
     * **The expectation is derived, never written per operation.** The branch is
     * found by {@see self::branchesMatching()} from the operation's `Method` and
     * path against the registry, and the status is the pin's. The status each
     * success recipe returns is its author's expectation and is **discarded
     * unread**; so is the `status:` attribute on the operation, which is a
     * per-operation declaration and exactly what this test must not trust.
     *
     * Controls inside this method: the recipes equal the registry; every
     * operation matched exactly one branch before its answer was judged; every
     * branch had at least one driven member; at least one `Location` and at least
     * one `DELETE` body were examined.
     *
     * **Mutations (C5 batch B, 2026-09-14; `var/cache/test` deleted before the
     * mutated and the revert run — Rule 17):** see `docs/session9/progress-C5B.md`
     * row 7 — `CreateItem`'s `status: 201` → `200`; `ApprovePurchaseOrder`'s
     * `status: 200` → `201` (the one that proves `POST` has two branches);
     * `DeleteItemProcessor` made to return the resource. Each red, by name.
     */
    #[Test]
    public function everyOperationReturnsTheStatusItsBranchRequires(): void
    {
        $operations = TransactionBoundaryTest::contractOperations();
        $paths = array_map(static fn (HttpOperation $operation): string => self::normalised($operation), $operations);
        $recipes = $this->successRecipes();

        self::assertNotSame([], $operations, 'The registry produced no operations, so nothing was driven.');
        self::assertTheDeclaringSetIsThePin($operations);
        $names = array_keys($operations);
        sort($names);
        $recipeNames = array_keys($recipes);
        sort($recipeNames);
        self::assertSame($names, $recipeNames, 'Every operation in the registry must be driven here, and only those.');

        $prepared = [];
        foreach ($recipes as $name => [$prepare]) {
            $prepared[$name] = $prepare();
        }

        $wrong = [];
        $members = array_fill_keys(array_keys(self::BRANCHES), 0);
        $locationsExamined = 0;
        $deleteBodiesExamined = 0;

        foreach ($recipes as $name => [, $drive]) {
            $drive($prepared[$name]); // The recipe's own expected status is deliberately not read.

            $operation = $operations[$name];
            $method = (string) $operation->getMethod();
            $declaredRowUri = self::declaredRowUri($name);
            $matched = self::branchesMatching($method, $paths[$name], $paths, $declaredRowUri);
            self::assertCount(1, $matched, $name.' does not match exactly one branch, so no status can be derived for it.');

            $branch = $matched[0];
            ++$members[$branch];
            $expected = self::BRANCHES[$branch]['status'];
            $response = $this->client()->getResponse();

            if ($expected !== $response->getStatusCode()) {
                $wrong[] = \sprintf('%s %s %s is branch %d and answered %d, not %d: %s', $name, $method, $paths[$name], $branch,
                    $response->getStatusCode(), $expected, substr((string) $response->getContent(), 0, 200));
            }

            if (4 === $branch) {
                ++$locationsExamined;
                $location = $response->headers->get('Location');
                $tested = self::addressableRowUris($paths[$name], $paths, $declaredRowUri);
                $named = null === $location ? null : self::normalisedLocation($location);

                if (null === $named || !\in_array($named, $tested, true)) {
                    $wrong[] = \sprintf('%s is branch 4 and its Location is %s, not the row-level URI the branch tested for (%s)',
                        $name, var_export($location, true), implode(' or ', $tested));
                }
            }

            if ('DELETE' === $method) {
                ++$deleteBodiesExamined;
                if ('' !== (string) $response->getContent()) {
                    $wrong[] = \sprintf('%s is a DELETE and returned a body: %s', $name, substr((string) $response->getContent(), 0, 200));
                }
            }
        }

        self::assertSame([], array_keys(array_filter($members, static fn (int $count): bool => 0 === $count)),
            'A status branch had no driven member, so its status was never asserted.');
        self::assertGreaterThan(0, $locationsExamined, 'No branch-4 Location was examined.');
        self::assertGreaterThan(0, $deleteBodiesExamined, 'No DELETE body was examined.');

        self::assertSame([], $wrong, 'API-21: an operation answered other than its branch requires.');
    }

    /**
     * The pin is §4.4.1's table, and the table is the pin. Branch number,
     * `Method` token and status are read from the document's rows; a branch
     * deleted from the document, or added to it, is red here.
     *
     * **Mutation:** delete row 5 from §4.4.1's table in `docs/API.md`; red.
     */
    #[Test]
    public function theBranchPinIsTheContractsTable(): void
    {
        if (!ContractDocument::isAvailable()) {
            self::markTestSkipped('docs/API.md is gitignored; the probe above runs on the pin on every checkout.');
        }

        $section = ContractDocument::sectionBetween('#### 4.4.1 ', '#### 4.4.2 ');
        preg_match_all('/^\| (\d+) \| `([A-Z]+)`[^|]*\| \*\*(\d{3})\*\*/m', $section, $rows, \PREG_SET_ORDER);

        $document = [];
        foreach ($rows as [, $number, $method, $status]) {
            $document[(int) $number] = $method.' '.$status;
        }

        $pin = array_map(static fn (array $branch): string => $branch['method'].' '.$branch['status'], self::BRANCHES);

        self::assertNotSame([], $document, '§4.4.1 yielded no branch rows, so the comparison below would be empty against empty.');
        self::assertSame($pin, $document, 'The branch pin and §4.4.1\'s table disagree.');
    }

    /**
     * The branches one operation falls into, by number.
     *
     * @param array<string, string> $registryPaths every built operation's normalised path
     *
     * @return list<int>
     */
    private static function branchesMatching(string $method, string $path, array $registryPaths, ?string $declaredRowUri = null): array
    {
        $matched = [];

        foreach (self::BRANCHES as $number => $branch) {
            if ($branch['method'] !== $method) {
                continue;
            }
            if (null !== $branch['creates'] && self::createsAnAddressableRow($path, $registryPaths, $declaredRowUri) !== $branch['creates']) {
                continue;
            }
            $matched[] = $number;
        }

        return $matched;
    }

    /**
     * Reading (1) in the class docblock: a `POST` creates an addressable row iff
     * an operation is declared at its path plus one parameter segment, or at its
     * last segment re-rooted with one parameter segment.
     *
     * @param array<string, string> $registryPaths
     */
    private static function createsAnAddressableRow(string $path, array $registryPaths, ?string $declaredRowUri = null): bool
    {
        return [] !== self::addressableRowUris($path, $registryPaths, $declaredRowUri);
    }

    /**
     * The row-level URIs, in normalised form, that reading (1) found declared
     * for a `POST` at this path — what a branch-4 `Location` must name.
     *
     * @param array<string, string> $registryPaths
     *
     * @return list<string>
     */
    private static function addressableRowUris(string $path, array $registryPaths, ?string $declaredRowUri = null): array
    {
        $segments = explode('/', trim($path, '/'));
        $candidates = [$path.'/{}', '/'.end($segments).'/{}'];

        $found = array_intersect($candidates, $registryPaths);

        // Reading (3): the declared template, if the operation carries one. It is added rather than
        // substituted, so an operation whose row IS in the registry is unaffected and a declaration that
        // named some other URI would widen nothing -- the Location still has to be one of these strings.
        if (null !== $declaredRowUri) {
            $found[] = (string) preg_replace('/\{[^}]*\}/', '{}', $declaredRowUri);
        }

        return array_values(array_unique($found));
    }

    /**
     * The row-level URI template an operation declares for its 201's `Location`, or null.
     *
     * **Read from {@see self::DECLARED_ROW_URIS}, not from the operation**, so that reading (3) cannot be
     * invoked by an operation this file has not named. The declaration itself is reconciled against the
     * pin by {@see self::assertTheDeclaringSetIsThePin()}, which is what goes red when they disagree;
     * here the pin simply wins, so a mutation is red for the branch it actually belongs to rather than
     * for the pin alone.
     */
    private static function declaredRowUri(string $name): ?string
    {
        return self::DECLARED_ROW_URIS[$name] ?? null;
    }

    /**
     * Reading (3)'s domain, both directions: every operation carrying an `OPERATION_URI_TEMPLATE` is in
     * the pin with that exact template, and every pinned operation still carries it.
     *
     * **Mutation, run 2026-09-19:** `ApprovePurchaseOrder` given
     * `OPERATION_URI_TEMPLATE => '/purchase-orders/{orderId}'` and `status: 201` (the RED exit audit's
     * F-1 mutation, `var/cache` deleted first) — **both** probes red, each naming
     * `'ApprovePurchaseOrder' => '/purchase-orders/{orderId}'` as the unpinned declaration. This
     * reconciliation fires first, so to show that the *classification* is repaired and not merely
     * guarded, the call below was then commented out and the status probe re-run on the same mutation:
     * red with `ApprovePurchaseOrder POST /purchase-orders/{}/approval is branch 5 and answered 201, not
     * 200` — byte-for-byte the message the shipped control gave at `90162a6`, before reading (3) existed
     * and silenced it. Both mutations reverted.
     * **Mutation (the other direction), also run:** drop the `extraProperties` entry from
     * `SalesOrderResource` — both probes red here, naming the pinned operation that stopped declaring.
     * The pin is a two-way reconciliation, so this direction is caught by this method and not by the
     * branch-4 `Location` assertion, which never runs.
     *
     * @param array<string, HttpOperation> $operations
     */
    private static function assertTheDeclaringSetIsThePin(array $operations): void
    {
        $declared = [];
        foreach ($operations as $name => $operation) {
            $template = $operation->getExtraProperties()[CreatedRowLocation::OPERATION_URI_TEMPLATE] ?? null;
            if (\is_string($template)) {
                $declared[$name] = $template;
            }
        }
        ksort($declared);

        $pin = self::DECLARED_ROW_URIS;
        ksort($pin);

        self::assertNotSame([], $pin, 'Reading (3) has an empty pin, so the reconciliation below is empty against empty.');
        self::assertSame($pin, $declared,
            'An operation declares CreatedRowLocation::OPERATION_URI_TEMPLATE that ResponseStatusTest::DECLARED_ROW_URIS '
            .'does not name, or names a different URI, or a pinned operation has dropped its declaration. Reading (3) is '
            .'how a POST is classified into branch 4, so an unreviewed declaration would let a transition POST move itself '
            .'out of branch 5 -- which is exactly what the RED exit audit measured. Add the operation to the pin only if it '
            .'really does create the row that URI addresses.');
    }

    /** A `Location` header with the base path removed and every uuid segment read as a parameter. */
    private static function normalisedLocation(string $location): string
    {
        $path = (string) parse_url($location, \PHP_URL_PATH);
        $path = str_starts_with($path, self::BASE) ? substr($path, \strlen(self::BASE)) : $path;

        return implode('/', array_map(
            static fn (string $segment): string => Uuid::isValid($segment) ? '{}' : $segment,
            explode('/', $path),
        ));
    }

    private static function normalised(HttpOperation $operation): string
    {
        return (string) preg_replace('/\{[^}]*\}/', '{}', (string) $operation->getUriTemplate());
    }
}

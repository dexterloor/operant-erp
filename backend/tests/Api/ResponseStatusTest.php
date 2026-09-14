<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\HttpOperation;
use App\Tests\Support\ContractDocument;
use PHPUnit\Framework\Attributes\Test;

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
 * itself as rows are built. `::everyOperationReturnsTheStatusItsBranchRequires`,
 * the sibling this probe protects, does not exist yet; this probe stands on its
 * own because a branch table with a hole in it is a defect before any status is
 * asserted against it.
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
 */
final class ResponseStatusTest extends ApiTestCase
{
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

        // Control 2 — the counter can report something other than one. An
        // operation no branch names must count zero, or the assertion below
        // could be a constant.
        self::assertSame([], self::branchesMatching('PUT', '/probe', $paths), 'A method no branch names was classified, so the count below cannot go red.');

        $wrongCount = [];
        $members = array_fill_keys(array_keys(self::BRANCHES), []);

        foreach ($operations as $name => $operation) {
            $matched = self::branchesMatching((string) $operation->getMethod(), $paths[$name], $paths);

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
    private static function branchesMatching(string $method, string $path, array $registryPaths): array
    {
        $matched = [];

        foreach (self::BRANCHES as $number => $branch) {
            if ($branch['method'] !== $method) {
                continue;
            }
            if (null !== $branch['creates'] && self::createsAnAddressableRow($path, $registryPaths) !== $branch['creates']) {
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
    private static function createsAnAddressableRow(string $path, array $registryPaths): bool
    {
        $segments = explode('/', trim($path, '/'));
        $candidates = [$path.'/{}', '/'.end($segments).'/{}'];

        return [] !== array_intersect($candidates, $registryPaths);
    }

    private static function normalised(HttpOperation $operation): string
    {
        return (string) preg_replace('/\{[^}]*\}/', '{}', (string) $operation->getUriTemplate());
    }
}

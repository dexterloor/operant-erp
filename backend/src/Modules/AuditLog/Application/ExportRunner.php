<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Application;

use App\Modules\AuditLog\Infrastructure\ExportAuditWriter;
use App\Platform\Http\Export\CsvExportRenderer;
use App\Platform\Http\Export\ExportSource;
use App\Platform\Http\Export\ExportedRows;
use App\Platform\Http\Export\ListDeclarations;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Http\UndeclaredQueryParameterRefusal;
use App\Platform\Security\Identity;
use App\Platform\Tenancy\TenantContext;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * **`CreateExport`'s work, after the gate (G6-C0; design §6.2–§6.7; Response M-5, M-7; final RED M-5, item 13).**
 *
 * In order, and the order is the design:
 * 1. **`filters` and `sort` against the List's declaration (API-14a)**, read through {@see ListDeclarations} (a Platform
 *    port — this module never reads framework metadata, `g6-c0-red.md` M-2): a member outside the List's declared query
 *    parameters minus `limit`, `after`, `before` and `sort` is 422 `undeclared_parameter` at `/filters/<name>`; a value
 *    that is not a string is 422 `not_a_string` (every value is a JSON string, exactly as the list's query string carries
 *    it, D-21); the List's OWN parameter constraints are then run, absent members as `null` (422 `invalid_parameter`,
 *    `FrameworkProblemTranslator`'s code); a `sort` the List does not declare is 422 `unserved_sort` at `/sort`. These
 *    refusals are reported together, as one 422.
 * 2. **The List's own parse** ({@see ExportSource::prepare()}): validation, legal sorts per view and caller-conditional
 *    refusals are the list's BY CONSTRUCTION (TB-7). Its `?name` pointers are rewritten to `/filters/name` (`/sort` for
 *    `?sort`). Steps 1–2 happen before the tenancy transaction opens.
 * 3. **One tenancy transaction, one statement, one snapshot:** the source's read (`LIMIT cap + 1`; the reader joins this
 *    transaction), then — unless the view is over the cap — the `data.export.completed` row with `row_count` = the rows the
 *    file will hold, BEFORE COMMIT (§4.4.5; the writer refuses outside a transaction). An over-cap view writes no row and
 *    is refused only AFTER the transaction has closed, so the refusal never closes the entity manager mid-transaction.
 * 4. **Render after commit** (P-7), one row at a time into `php://temp` (M-7, L-6), from rows that are plain objects built
 *    from SQL inside the transaction — never ORM entities or proxies (`ExportContractTest::noExportedRowCanLazyLoadAfterCommit`).
 *
 * **The cap** is `app.export.row_cap` (10,000 in every environment but `test`, which lowers it so a fixture need not seed
 * 10,001 rows; `ExportCapTest` pins the production value). It is a PROPOSAL: the measurement at 10,000 rows is in
 * `docs/session13/g6-c0-progress.md`, and `PO`/`DATA` set the final figure. Over it → 409 `business-rule-violated`, rule
 * {@see self::TOO_MANY_ROWS}.
 */
final readonly class ExportRunner
{
    public const TOO_MANY_ROWS = 'export.too_many_rows';

    public function __construct(
        private ExportSources $sources,
        private ListDeclarations $declarations,
        private TenantContext $tenants,
        private ExportAuditWriter $audit,
        private CsvExportRenderer $renderer,
        #[Autowire('%app.export.row_cap%')]
        private int $cap,
    ) {
        if ($cap < 1) {
            throw new \LogicException('The export row cap must be at least 1.');
        }
    }

    public function cap(): int
    {
        return $this->cap;
    }

    /**
     * @return array{stream: resource, row_count: int}
     */
    public function run(string $resource, mixed $filters, ?string $sort, Identity $caller, ?string $ipAddress): array
    {
        $source = $this->sources->get($resource);

        $filters = $this->heldToTheList($source, $filters, $sort);
        $query = $filters + (null === $sort ? [] : [ListDeclarations::SORT => $sort]);

        try {
            $read = $source->prepare($query, $this->cap);
        } catch (ApiProblem $refusal) {
            throw self::repointed($refusal);
        }

        $recorded = $this->recordedFilters($source, $filters);
        $exported = $this->tenants->run($caller->tenantId, function () use ($read, $caller, $resource, $recorded, $sort, $ipAddress): ExportedRows {
            $rows = $read();
            if (!$rows instanceof ExportedRows) {
                throw new \LogicException(\sprintf('The export source for "%s" did not return ExportedRows.', $resource));
            }

            if (!$rows->overCap) {
                $this->audit->recordCompleted(
                    tenantId: $caller->tenantId,
                    actorUserId: $caller->userId,
                    actorLabel: $caller->actorLabel,
                    resource: $resource,
                    filters: $recorded,
                    sort: $sort,
                    rowCount: \count($rows->rows),
                    ipAddress: $ipAddress,
                );
            }

            return $rows;
        });

        if ($exported->overCap) {
            throw ApiProblem::businessRuleViolated(self::TOO_MANY_ROWS, \sprintf(
                'This view has more than %s rows. Narrow the filters and export again.',
                number_format($this->cap),
            ));
        }

        $rows = $exported->rows;
        $count = \count($rows);
        unset($exported);

        return ['stream' => $this->renderer->render($source->rowClass(), $rows), 'row_count' => $count];
    }

    /**
     * @return array<string, string>
     */
    private function heldToTheList(ExportSource $source, mixed $filters, ?string $sort): array
    {
        $errors = [];
        $held = [];

        if (null !== $filters && (!\is_array($filters) || ([] !== $filters && array_is_list($filters)))) {
            $errors[] = ['pointer' => '/filters', 'code' => 'not_an_object', 'message' => 'filters must be an object of the list\'s filters.'];
            $filters = [];
        }

        $members = $this->declarations->filterMembers($source);
        foreach ($filters ?? [] as $name => $value) {
            $name = (string) $name;
            if (!\array_key_exists($name, $members)) {
                $errors[] = ['pointer' => '/filters/'.self::pointerToken($name), 'code' => UndeclaredQueryParameterRefusal::CODE,
                    'message' => \sprintf('This list has no filter named "%s".', $name)];
                continue;
            }
            if (!\is_string($value)) {
                $errors[] = ['pointer' => '/filters/'.self::pointerToken($name), 'code' => 'not_a_string',
                    'message' => 'Each filter is given as text, exactly as the list\'s address would carry it.'];
                continue;
            }
            $held[$name] = $value;
        }

        foreach ($this->declarations->violations($source, $held) as [$name, $message]) {
            $errors[] = ['pointer' => '/filters/'.self::pointerToken($name), 'code' => 'invalid_parameter', 'message' => $message];
        }

        if (null !== $sort) {
            $values = $this->declarations->sortValues($source);
            if (!\in_array($sort, $values, true)) {
                $errors[] = ['pointer' => '/sort', 'code' => 'unserved_sort', 'message' => [] === $values
                    ? 'This list offers no other order.'
                    : \sprintf('This list can be ordered by %s.', implode(', ', $values))];
            }
        }

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }

        return $held;
    }

    /**
     * Every applied filter by name: its value as sent, a free-text one (`q`) as `true` — never its text (E-17). An empty
     * free-text filter applies no search, so it is not recorded at all (`g6-c0-red.md` L-2).
     *
     * @param array<string, string> $filters
     *
     * @return array<string, string|true>
     */
    private function recordedFilters(ExportSource $source, array $filters): array
    {
        $members = $this->declarations->filterMembers($source);
        $recorded = [];
        foreach ($filters as $name => $value) {
            if (!ListDeclarations::isFreeText($members[$name] ?? [])) {
                $recorded[$name] = $value;
            } elseif ('' !== $value) {
                $recorded[$name] = true;
            }
        }

        return $recorded;
    }

    /** The list's own refusal, re-pointed from its query string into the export's body. */
    private static function repointed(ApiProblem $refusal): ApiProblem
    {
        $errors = $refusal->getErrors();
        if (null === $errors) {
            return $refusal;
        }

        return ApiProblem::validationFailed(array_map(static function (array $error): array {
            $pointer = $error['pointer'];
            if (str_starts_with($pointer, '?')) {
                $name = substr($pointer, 1);
                $error['pointer'] = ListDeclarations::SORT === $name ? '/sort' : '/filters/'.self::pointerToken($name);
            }

            return $error;
        }, $errors), $refusal->getDetail());
    }

    /** RFC 6901: `~` → `~0`, `/` → `~1`. */
    private static function pointerToken(string $name): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $name);
    }
}

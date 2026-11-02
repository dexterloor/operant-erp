<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Tests\Support\GeneratedSpec;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * BK-15 T-16: every typed `public mixed` request field is described as what its writer accepts — asked of the running
 * server, not read from the attribute.
 *
 * ---------------------------------------------------------------------------
 * The cases, derived
 * ---------------------------------------------------------------------------
 * Every (operation, field) whose `public mixed` input property carries an `#[ApiProperty(schema:)]` with a `type`
 * ({@see OpenApiContractTest::mixedInputFields()}). The set actually PROBED is held two-way against T-5's reflected set
 * (since K3 deleted T-5's not-yet-typed pin, that is every `public mixed` input field), so a probe cannot quietly shrink:
 * a field typed tomorrow is probed tomorrow, and one this class stops reaching is red. (BK-15 K2, 2026-11-02: the
 * Customers, Suppliers and UserManagement fields, the two `CreateExport` corrections, and the fields typed before K2 —
 * bulk, export, `permission_codes`, `acknowledged_return_ids`. K3, 2026-11-02: the Inventory and Sales fields.)
 *
 * ---------------------------------------------------------------------------
 * The requests
 * ---------------------------------------------------------------------------
 * Each operation's success recipe ({@see OperationSuccessRecipes}), with one value planted into its body by `send()`
 * (the `ClientChosenIdTest` hook). Per (operation, recipe), an unplanted run comes first: it is the positive control
 * (the recipe still succeeds, and wherever its own body carries a probed field, that value conforms to the served
 * schema), and it COUNTS the N requests matching the operation's method and path template. Every probe then runs on a
 * fresh rig (truncated tables, rebooted kernel) and plants into the N-th match only, asserting exactly one plant. Paths
 * are compared with `/api/v1` and any query string stripped and each uuid segment written `{…}`.
 *
 * `CreateAdjustmentDecision` has a second, test-local recipe, `reversal` (the success recipe's `same` decision, then a
 * reversal of it), because a kind-conditional field can only be seen under the kind that accepts it. Which fields run
 * under which recipe is pinned two-way ({@see self::KIND_FOR_FIELD}). The count and the control are per (operation,
 * recipe): the `reversal` recipe sends the operation twice, the `same` recipe once (final RED FM-2).
 *
 * ---------------------------------------------------------------------------
 * The probes, generated from the SERVED field schema (resolved through the regenerated spec)
 * ---------------------------------------------------------------------------
 *  1. `null` not in the type → plant `null` → 422 with an error at `/<field>`, whose code is not `not_for_this_kind`.
 *  2. `null` in the type → plant `null` → status < 500 and no 422 error at `/<field>` (exemptions pinned two-way in
 *     {@see self::NULL_ACCEPTED_FOR_SOME_KINDS_ONLY}: the four kind-conditional `CreateAdjustmentDecision` fields).
 *  3. A value of the wrong JSON type → 422 at `/<field>`, whose code is neither `not_for_this_kind` nor `required`:
 *     integer → `"1"`, boolean → `"true"`, string → `1`, array → `"x"`, object → `"x"`.
 *  4. A string → `"not-a-uuid"`: with `format: uuid` the code at `/<field>` is `not_a_uuid`; without it, no
 *     `not_a_uuid` there.
 *  5. An `enum` → each member gives no 422 at `/<field>`, `"zz-not-a-member"` gives one. (The enums themselves are held
 *     equal to their writers' constants by `OpenApiContractTest::everyRequestEnumIsItsWritersConstant`, T-16b, which
 *     also sees an enum NARROWER than the writer's set — this probe cannot.)
 *
 * `CreateExport` asks for `text/csv`; its refusals are problem+json all the same (measured: the error pointers are read
 * from the body under that `Accept`), so it is planted as its recipe sends it.
 *
 * Not testable here (declared review checks): a type NARROWER than the writer accepts — e.g. `filters` as an object
 * where the writer also takes `[]`, which PHP decoding cannot tell from `{}` (final RED FL-4) — and an enum narrower
 * than the writer's set (T-16b's job).
 */
final class MixedInputSchemaProbeTest extends PurchaseOrderTestCase
{
    use OperationSuccessRecipes;

    private const SUCCESS = 'success';
    private const REVERSAL = 'reversal';

    /**
     * Which recipe a `CreateAdjustmentDecision` field is probed under, two-way against that operation's cases (final RED
     * FM-2, cut at K3). `reverses_decision_id` and `acknowledged_return_ids` are refused as `not_for_this_kind` unless the
     * kind is `reversal`; `sales_return_line_id`, `credit_note_line_id` and `quantity` unless it is `same`/`distinct`
     * (`quantity`: `same` only). `kind` itself runs under `same`: planting `distinct`/`reversal` into that body draws
     * errors at OTHER fields, none at `/kind`, so probe 5 holds for every member.
     */
    private const KIND_FOR_FIELD = [
        'CreateAdjustmentDecision.acknowledged_return_ids' => self::REVERSAL,
        'CreateAdjustmentDecision.credit_note_line_id' => self::SUCCESS,
        'CreateAdjustmentDecision.kind' => self::SUCCESS,
        'CreateAdjustmentDecision.quantity' => self::SUCCESS,
        'CreateAdjustmentDecision.reverses_decision_id' => self::REVERSAL,
        'CreateAdjustmentDecision.sales_return_line_id' => self::SUCCESS,
    ];

    /**
     * Nullable fields whose `null` is accepted for some kinds only, so probe 2 cannot hold for them under one recipe: each
     * is REQUIRED by the kind that uses it and required to be null by the others. `acknowledged_return_ids` is
     * null-accepting for every kind, so it is NOT exempt. Each entry must be a typed case whose type admits null.
     *
     * @var list<string>
     */
    private const NULL_ACCEPTED_FOR_SOME_KINDS_ONLY = [
        'CreateAdjustmentDecision.credit_note_line_id',
        'CreateAdjustmentDecision.quantity',
        'CreateAdjustmentDecision.reverses_decision_id',
        'CreateAdjustmentDecision.sales_return_line_id',
    ];

    /** @var array{method: string, template: string, nth: int, field: string, value: mixed}|null */
    private ?array $plant = null;

    private int $matches = 0;

    private int $plants = 0;

    /** @var list<array<string, mixed>|null> the bodies of the matching requests, in order */
    private array $matchingBodies = [];

    /** @var array{method: string, template: string}|null the operation whose requests are counted, unplanted */
    private ?array $counting = null;

    #[Test]
    public function everyTypedMixedInputFieldIsDescribedAsItsWriterAcceptsIt(): void
    {
        $spec = self::spec();
        $routes = self::routes($spec);

        $cases = [];
        foreach (OpenApiContractTest::mixedInputFields() as $field) {
            if (null !== $field['schema']) {
                $cases[$field['operation'].'.'.$field['wire']] = $field;
            }
        }
        self::assertNotSame([], $cases, 'No typed `public mixed` input field was found, so nothing was probed.');

        // KIND_FOR_FIELD is two-way against the operation it serves; the exemptions name real nullable cases.
        $kindCases = array_values(array_filter(array_keys($cases), static fn (string $k): bool => str_starts_with($k, 'CreateAdjustmentDecision.')));
        sort($kindCases);
        $pinned = array_keys(self::KIND_FOR_FIELD);
        sort($pinned);
        self::assertSame($kindCases, $pinned, 'Every typed CreateAdjustmentDecision field names the recipe (kind) it is probed under, and only those.');
        foreach (self::NULL_ACCEPTED_FOR_SOME_KINDS_ONLY as $exempt) {
            self::assertArrayHasKey($exempt, $cases, $exempt.' is exempt from probe 2 but is not a typed case.');
            self::assertContains('null', (array) ($cases[$exempt]['schema']['type'] ?? []), $exempt.' is exempt from probe 2 but its type does not admit null.');
        }

        // Group by (operation, recipe).
        $groups = [];
        foreach ($cases as $key => $field) {
            $groups[$field['operation'].' '.(self::KIND_FOR_FIELD[$key] ?? self::SUCCESS)][] = $key;
        }
        ksort($groups);

        $failures = [];
        $probed = [];
        $runs = 0;
        foreach ($groups as $group => $keys) {
            [$operation, $recipe] = explode(' ', $group);
            self::assertArrayHasKey($operation, $routes, $operation.' has a typed input field but no route in the served spec.');
            $route = $routes[$operation];

            // ---- The positive control: unplanted, counted.
            $this->freshRig();
            $this->counting = ['method' => $route['method'], 'template' => $route['template']];
            $this->matches = 0;
            $this->matchingBodies = [];
            [$expected, $label] = $this->drive($operation, $recipe);
            $this->counting = null;
            ++$runs;
            $status = $this->httpStatus();
            if ($status !== $expected) {
                $failures[] = \sprintf('%s: the unplanted %s recipe (%s) answered %d, not %d: %s', $group, $recipe, $label, $status, $expected, $this->bodyExcerpt());
                continue;
            }
            $nth = $this->matches;
            if ($nth < 1) {
                $failures[] = \sprintf('%s: the %s recipe sent no %s %s, so there is nothing to plant into.', $group, $recipe, $route['method'], $route['template']);
                continue;
            }
            foreach ($keys as $key) {
                $wire = $cases[$key]['wire'];
                foreach ($this->matchingBodies as $body) {
                    if (\is_array($body) && \array_key_exists($wire, $body) && null !== ($why = self::nonConformance($body[$wire], $route['properties'][$wire] ?? []))) {
                        $failures[] = \sprintf('%s: the recipe\'s own %s does not conform to the served schema: %s', $key, $wire, $why);
                    }
                }
            }

            // ---- The probes.
            foreach ($keys as $key) {
                $wire = $cases[$key]['wire'];
                self::assertArrayHasKey($wire, $route['properties'], $key.' is not a property of the served request schema.');
                $schema = $route['properties'][$wire];
                foreach (self::probesFor($key, $schema) as [$name, $value, $check]) {
                    $this->freshRig();
                    $this->plant = ['method' => $route['method'], 'template' => $route['template'], 'nth' => $nth, 'field' => $wire, 'value' => $value];
                    $this->matches = 0;
                    $this->plants = 0;
                    $this->drive($operation, $recipe);
                    $this->plant = null;
                    ++$runs;
                    $status = $this->httpStatus();
                    $codes = $this->codesAt('/'.$wire);
                    if (1 !== $this->plants) {
                        $failures[] = \sprintf('%s probe %s: %d plants happened, not exactly one.', $key, $name, $this->plants);
                        continue;
                    }
                    if (null !== ($why = $check($status, $codes))) {
                        $failures[] = \sprintf('%s probe %s (%s planted): %s — answered %d, codes at /%s: [%s]; %s',
                            $key, $name, json_encode($value), $why, $status, $wire, implode(', ', $codes), $this->bodyExcerpt());
                    }
                }
                $probed[] = $key;
            }
        }

        // ---- Assertions.
        self::assertSame([], $failures, "A request field's served description disagrees with what its writer does:\n".implode("\n", $failures));

        $reflected = [];
        foreach (OpenApiContractTest::mixedInputFields() as $field) {
            $reflected[$field['key']] = $field['operation'].'.'.$field['wire'];
        }
        $expectedCases = array_values($reflected);
        sort($expectedCases);
        $probed = array_values(array_unique($probed));
        sort($probed);
        self::assertSame($expectedCases, $probed, 'The probed set is T-5\'s reflected set, two-way: a typed field went unprobed, or a probed one is not a typed mixed input.');
        self::assertGreaterThan(\count($groups), $runs, 'Fewer recipe runs than (operation, recipe) groups: the probes never ran.');
    }

    /**
     * The probes owed to one field by its served schema.
     *
     * @param array<string, mixed> $schema
     *
     * @return list<array{0: string, 1: mixed, 2: callable(int, list<string>): ?string}>
     */
    private static function probesFor(string $key, array $schema): array
    {
        $types = (array) ($schema['type'] ?? []);
        $nullable = \in_array('null', $types, true);
        $base = array_values(array_diff($types, ['null']));
        self::assertCount(1, $base, $key.' must serve exactly one non-null type.');
        $base = $base[0];

        $probes = [];
        if (!$nullable) {
            $probes[] = ['1 (null refused)', null, static function (int $status, array $codes): ?string {
                if (422 !== $status || [] === $codes) {
                    return 'its type refuses null, but null was not refused at the field';
                }

                return \in_array('not_for_this_kind', $codes, true) ? 'refused only as not_for_this_kind (wrong recipe for the field)' : null;
            }];
        } elseif (!\in_array($key, self::NULL_ACCEPTED_FOR_SOME_KINDS_ONLY, true)) {
            $probes[] = ['2 (null accepted)', null, static function (int $status, array $codes): ?string {
                if ($status >= 500) {
                    return 'its type admits null, and null broke the server';
                }

                return 422 === $status && [] !== $codes ? 'its type admits null, but null was refused at the field' : null;
            }];
        }

        $wrong = match ($base) {
            'integer' => '1',
            'boolean' => 'true',
            'string' => 1,
            'array', 'object' => 'x',
            default => throw new \LogicException(\sprintf('%s serves type %s, which this probe has no wrong value for: teach it one.', $key, $base)),
        };
        $probes[] = ['3 (wrong type)', $wrong, static function (int $status, array $codes): ?string {
            if (422 !== $status || [] === $codes) {
                return 'a value of the wrong type was not refused at the field';
            }
            foreach (['not_for_this_kind', 'required'] as $code) {
                if (\in_array($code, $codes, true)) {
                    return 'refused as '.$code.', not as a wrong type';
                }
            }

            return null;
        }];

        if ('string' === $base) {
            $uuid = 'uuid' === ($schema['format'] ?? null);
            $probes[] = ['4 (not a uuid)', 'not-a-uuid', static function (int $status, array $codes) use ($uuid): ?string {
                if ($uuid) {
                    return 422 === $status && \in_array('not_a_uuid', $codes, true) ? null : 'it is described as a uuid, but a non-uuid was not refused as not_a_uuid';
                }

                return \in_array('not_a_uuid', $codes, true) ? 'it is refused as not_a_uuid, but is not described as a uuid' : null;
            }];
        }

        foreach ((array) ($schema['enum'] ?? []) as $member) {
            if (null === $member) {
                continue;
            }
            $probes[] = ['5 (member '.$member.')', $member, static fn (int $status, array $codes): ?string => 422 === $status && [] !== $codes ? 'a member of its enum was refused at the field' : null];
        }
        if (isset($schema['enum'])) {
            $probes[] = ['5 (non-member)', 'zz-not-a-member', static fn (int $status, array $codes): ?string => 422 === $status && [] !== $codes ? null : 'a value outside its enum was not refused at the field'];
        }

        return $probes;
    }

    /**
     * Runs one recipe, fixture first, and answers the status and label it expects.
     *
     * @return array{0: int, 1: string}
     */
    private function drive(string $operation, string $recipe): array
    {
        $recipes = $this->successRecipes();
        self::assertArrayHasKey($operation, $recipes, $operation.' has a typed input field but no success recipe.');
        [$prepare, $drive] = $recipes[$operation];
        $fixture = $prepare();
        $outcome = $drive($fixture);
        if (self::REVERSAL !== $recipe) {
            return $outcome;
        }

        // The `same` decision the success recipe recorded, reversed: the second request to the operation. Only an owner
        // may reverse a decision (`adjustment_decision.reversal_needs_the_owner`), and the rig's default actor never is
        // one, so the reversal carries an owner's token of its own; the rig's actor is not switched.
        $return = $this->json();
        $decision = $return['lines'][0]['adjustment_decisions'][0]['id'] ?? null;
        $owner = $this->seedPrincipal(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $this->send('POST', '/sales-returns/'.$return['id'].'/adjustment-decisions',
            ['kind' => 'reversal', 'reverses_decision_id' => $decision],
            ['Idempotency-Key' => Uuid::v4()->toRfc4122(), 'Authorization' => 'Bearer '.$this->tokenFor(self::TENANT_A, $owner)]);

        return [200, 'the success recipe\'s same decision, then its reversal'];
    }

    /** A fresh rig: tables truncated, kernel and client rebuilt, the default actor re-established. */
    private function freshRig(): void
    {
        $this->tearDown();
        $this->setUp();
    }

    /**
     * Counts (and, in a probe, plants into) the requests matching the operation under test.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers
     */
    protected function send(string $method, string $path, ?array $body = null, array $headers = [], string $accept = 'application/json'): void
    {
        $target = $this->plant ?? $this->counting;
        if (null !== $target && $method === $target['method'] && self::normalise($path) === $target['template']) {
            ++$this->matches;
            if (null !== $this->plant && $this->matches === $this->plant['nth']) {
                $body ??= [];
                $body[$this->plant['field']] = $this->plant['value'];
                ++$this->plants;
            }
            if (null !== $this->counting) {
                $this->matchingBodies[] = $body;
            }
        }

        parent::send($method, $path, $body, $headers, $accept);
    }

    private static function normalise(string $path): string
    {
        $path = (string) preg_replace('/\?.*$/', '', $path);
        if (str_starts_with($path, self::BASE)) {
            $path = substr($path, \strlen(self::BASE));
        }

        return (string) preg_replace('#/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(?=/|$)#i', '/{…}', $path);
    }

    /** @return list<string> the error codes the last response names at exactly this pointer */
    private function codesAt(string $pointer): array
    {
        $body = json_decode((string) $this->client()->getResponse()->getContent(), true);
        $codes = [];
        foreach (\is_array($body) && \is_array($body['errors'] ?? null) ? $body['errors'] : [] as $error) {
            if (\is_array($error) && $pointer === ($error['pointer'] ?? null)) {
                $codes[] = (string) ($error['code'] ?? '');
            }
        }

        return $codes;
    }

    private function bodyExcerpt(): string
    {
        return substr((string) $this->client()->getResponse()->getContent(), 0, 300);
    }

    /**
     * Why a value does not conform to a served field schema, or null when it does (type incl. null, uuid format, enum,
     * array items, string-valued object members).
     *
     * @param array<string, mixed> $schema
     */
    private static function nonConformance(mixed $value, array $schema): ?string
    {
        $types = (array) ($schema['type'] ?? []);
        $actual = match (true) {
            null === $value => ['null'],
            \is_bool($value) => ['boolean'],
            \is_int($value) => ['integer'],
            \is_float($value) => ['number'],
            \is_string($value) => ['string'],
            \is_array($value) && [] === $value => ['array', 'object'],
            \is_array($value) && array_is_list($value) => ['array'],
            default => ['object'],
        };
        if ([] === array_intersect($actual, $types)) {
            return \sprintf('%s is %s, served as %s', json_encode($value), implode('|', $actual), implode('|', $types));
        }
        if (\is_string($value) && 'uuid' === ($schema['format'] ?? null) && !Uuid::isValid($value)) {
            return json_encode($value).' is not a uuid';
        }
        if (isset($schema['enum']) && !\in_array($value, (array) $schema['enum'], true)) {
            return json_encode($value).' is not in its enum';
        }
        if (\is_array($value) && array_is_list($value) && isset($schema['items']) && \is_array($schema['items'])) {
            foreach ($value as $item) {
                if (null !== ($why = self::nonConformance($item, $schema['items']))) {
                    return 'an item: '.$why;
                }
            }
        }
        if (\is_array($value) && !array_is_list($value) && \is_array($schema['additionalProperties'] ?? null)) {
            foreach ($value as $member) {
                if (null !== ($why = self::nonConformance($member, $schema['additionalProperties']))) {
                    return 'a member: '.$why;
                }
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function spec(): array
    {
        static $memo = null;

        return $memo ??= GeneratedSpec::regenerate(self::$kernel ?? self::bootKernel());
    }

    /**
     * Each operation's method, normalised path template and request-body properties, from the served spec.
     *
     * @param array<string, mixed> $spec
     *
     * @return array<string, array{method: string, template: string, properties: array<string, array<string, mixed>>}>
     */
    private static function routes(array $spec): array
    {
        $routes = [];
        foreach ($spec['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (!\is_array($operation) || !isset($operation['operationId'])) {
                    continue;
                }
                $properties = [];
                foreach ($operation['requestBody']['content'] ?? [] as $media) {
                    $schema = $media['schema'] ?? [];
                    if (isset($schema['$ref'])) {
                        $schema = $spec['components']['schemas'][substr($schema['$ref'], \strlen('#/components/schemas/'))] ?? [];
                    }
                    $properties = $schema['properties'] ?? [];
                    break;
                }
                $template = (string) preg_replace('/\{[^}]+\}/', '{…}', str_starts_with($path, self::BASE) ? substr($path, \strlen(self::BASE)) : $path);
                $routes[(string) $operation['operationId']] = ['method' => strtoupper($method), 'template' => $template, 'properties' => $properties];
            }
        }

        return $routes;
    }
}

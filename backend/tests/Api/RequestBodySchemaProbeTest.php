<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Tests\Support\GeneratedSpec;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * BK-18 R18-b (was BK-15 T-16, `MixedInputSchemaProbeTest`): every request field is described as what its writer
 * accepts — whether it may be `null`, whether it must be sent, whether it is a uuid, and its enum — asked of the running
 * server, not read from the attribute.
 *
 * ---------------------------------------------------------------------------
 * The rules being published
 * ---------------------------------------------------------------------------
 *  N (nullable, BK-15 D-5): `null` is in a request field's type exactly when the server accepts an explicit `null` there
 *    without a 422 at that field.
 *  R (required): a POST field is in `required` exactly when leaving it out of the recipe's otherwise-successful request
 *    is refused with code `required` at `/<field>` — a constructor parameter without default included, since G-101
 *    closed (before it, `malformed_body` at `""`; {@see \App\Platform\Http\Problem\FrameworkProblemTranslator}).
 *    Merge-patch components never list `required` (API Platform drops it for a partial update), and no PATCH field is
 *    unconditionally required: `UpdateSalesOrder`'s "a new customer needs a new ship-to address" stays prose.
 *  U (uuid): a string field refused as `not_a_uuid` for `"not-a-uuid"` is served `format: uuid`, and only those.
 *
 * ---------------------------------------------------------------------------
 * The cases, derived
 * ---------------------------------------------------------------------------
 * Every top-level property of every served request-body component, held two-way against reflection over every `input:`
 * class's public properties and promoted constructor parameters ({@see OpenApiContractTest::requestInputFields()}), so a
 * field cannot escape the probe: a field added tomorrow is probed tomorrow. Plus every property of every hand-written
 * line-item schema (`lines[]`, seven operations), keyed `<Op>.lines/0/<field>` and planted at `lines[0]`; those are held
 * against the served item schemas here and against their writers' line-field constants by T-6
 * ({@see OpenApiContractTest::everyInputLineSchemaIsItsWritersFields()}). Anti-vacuity: at least one probed field of each
 * kind — native `?string = null`, native uninitialised, constructor, mixed, nested line.
 *
 * ---------------------------------------------------------------------------
 * The requests
 * ---------------------------------------------------------------------------
 * Each operation's success recipe ({@see OperationSuccessRecipes}), with one value planted (or one key removed) in its
 * body by `send()`. Per (operation, recipe) an unplanted run comes first: it is the positive control (the recipe still
 * succeeds, and wherever its own body carries a probed field, that value conforms to the served schema), and it COUNTS
 * the N requests matching the operation's method and path template. Every probe then runs on a fresh rig (truncated
 * tables, rebooted kernel, the three pre-authentication limiter tables emptied) and plants into the N-th match only,
 * asserting exactly one plant and that it landed (a removed key is absent from the body actually sent).
 *
 * `CreateAdjustmentDecision` has a second, test-local recipe, `reversal`, because a kind-conditional field can only be
 * seen under the kind that accepts it ({@see self::KIND_FOR_FIELD}). `IssueToken` has a test-local recipe too: the shared
 * one presents the password `ChangePassword`'s recipe set, so it cannot succeed alone (here it sets its own).
 *
 * The three tables `auth_attempt`, `auth_ip_budget` and `auth_lockout` are not tenant-scoped, so the base rig's truncate
 * does not reach them (the reason {@see \App\Tests\Auth\AuthTestCase} truncates them itself): each rig empties them, and
 * the test empties them again before it returns, also on failure, so it leaves no shared limiter state.
 *
 * ---------------------------------------------------------------------------
 * The probes, generated from the SERVED field schema (resolved through the regenerated spec)
 * ---------------------------------------------------------------------------
 *  1. `null` not in the type → plant `null` → 422 with an error at the field whose code is not `not_for_this_kind`; when
 *     the PHP type is non-nullable (the constructor strings, all on `POST`), exactly `required` (G-101).
 *  2. `null` in the type → plant `null` → status < 500, not 400, and no 422 error at the field (exemptions pinned two-way
 *     in {@see self::NULL_ACCEPTED_FOR_SOME_KINDS_ONLY}).
 *  3. Every field (G-101's closing test): a value of the wrong JSON type, chosen from the served base type. A natively
 *     typed field → 422 with exactly one error, at the field: `not_a_string` for a string, `not_a_list` for an array
 *     (any other served base type is a `LogicException`: teach the translator and this map). A `mixed` field or a line
 *     field (the writer refuses it) → 422 at the field, whose code is neither `not_for_this_kind` nor `required`.
 *     `probe3ByKind` is held two-way against the five kinds, with at least one native field of served base `array`.
 *  4. Every string field: `"not-a-uuid"` → with `format: uuid` the code at the field is `not_a_uuid`; without it, no
 *     `not_a_uuid` there.
 *  5. An `enum` → each member gives no 422 at the field, `"zz-not-a-member"` gives one.
 *  6. Absence. If the recipe's N-th body carries the field, the key is removed: served required → code `required` at
 *     the field (a constructor parameter without default too, since G-101); served not required → EXACTLY
 *     the unplanted control's status. If the recipe never sends it, the unplanted success already shows absence is
 *     accepted, so it must be served not required. Pins, each two-way and executed (a stale entry is red):
 *     {@see self::REQUIRED_FOR_SOME_KINDS_ONLY}, {@see self::ABSENCE_REFUSED_UNDER_ANOTHER_CODE},
 *     {@see self::ABSENCE_CHANGES_THE_OUTCOME}.
 *  7. A trailing line break (G-103): every carried field whose served base type is `string`, with no `format` and no `enum`,
 *     gets the value the N-th request itself carries at that path, read when it is sent, with `"\n"` appended — a value
 *     that is valid except for the line break (read at send time because each probe runs on a fresh rig: the counting
 *     run's email or reset token would name the other rig's records). Four outcomes, each a pin map, two-way and executed (a pinned key that is not a probe-7 case is stale and
 *     red; an answer its class does not allow is red):
 *     {@see self::LINE_BREAK_REFUSED} (422, exactly the pinned code at the field), {@see self::LINE_BREAK_TRIMMED} (the
 *     control's status, no error at the field), {@see self::LINE_BREAK_CHANGES_THE_OUTCOME} (status < 500, not 400), and
 *     every other carried plain-string field (the control's status, no error at the field). The probe CANNOT tell trimmed
 *     from stored-as-sent: the two assert the same thing, and the trim itself is shown by `TrailingLineBreakTest` E-15…E-17.
 *     The unpinned class is **recorded, not ruled**: stored as sent; identifier codes and SKUs are `RESIDUAL_GAPS.md`
 *     G-104; other free text is the G-103 design's Q-UX-1. `probe7ByOutcome` shows every class non-empty.
 * Any status ≥ 500 on any probe is its own failure: a shipped defect, not a description question. Any 400 is a failure
 * too: it is outside `API.md` §5.2 (G-101).
 *
 * `CreateExport` asks for `text/csv`; its refusals are problem+json all the same, so it is planted as its recipe sends it.
 *
 * Not testable here (declared review checks): a type NARROWER than the writer accepts (final RED FL-4) and an enum
 * narrower than the writer's set (T-16b's job, {@see OpenApiContractTest::everyRequestEnumIsItsWritersConstant()}).
 */
final class RequestBodySchemaProbeTest extends PurchaseOrderTestCase
{
    use OperationSuccessRecipes;

    private const SUCCESS = 'success';
    private const REVERSAL = 'reversal';

    /** The five kinds of probed field. */
    private const KINDS = ['native ?string = null', 'native uninitialised', 'constructor', 'mixed', 'nested line'];

    private const PROBE_3 = '3 (wrong type)';

    /** The password the T-R1-local `IssueToken` recipe sets and then presents. */
    private const PROBE_PASSWORD = 'a-probe-password-long-enough-for-e23';

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
     * null-accepting for every kind, so it is NOT exempt. Each entry must be a case whose type admits null.
     *
     * @var list<string>
     */
    private const NULL_ACCEPTED_FOR_SOME_KINDS_ONLY = [
        'CreateAdjustmentDecision.credit_note_line_id',
        'CreateAdjustmentDecision.quantity',
        'CreateAdjustmentDecision.reverses_decision_id',
        'CreateAdjustmentDecision.sales_return_line_id',
        // A return line's bin: required unless the return's reason is `recorded_in_error`, and null there (BK-18 R18-b,
        // measured 2026-11-13; pre-authorised by the design's apply list A-3 item 11).
        'CreateSalesReturn.lines/0/storage_location_id',
    ];

    /**
     * Fields served NOT required because only some kinds need them, whose absence is refused `required` under the recipe
     * they are probed in. Each entry must be served not required, carried by its recipe, and refused `required` at its
     * pointer when left out — so a stale entry is red.
     *
     * @var list<string>
     */
    private const REQUIRED_FOR_SOME_KINDS_ONLY = [
        'CreateAdjustmentDecision.credit_note_line_id',
        'CreateAdjustmentDecision.quantity',
        'CreateAdjustmentDecision.reverses_decision_id',
        'CreateAdjustmentDecision.sales_return_line_id',
        'CreateSalesReturn.lines/0/storage_location_id',
    ];

    /**
     * Fields served required whose absence a writer refuses under a code other than `required`: for an entry, probe 6's
     * required arm asserts THAT code at the field. An entry whose absence answers `required`, or anything other than its
     * pinned code, is red; so is an entry served not required. Each entry carries a one-line comment naming the
     * stop-and-report (date and ruling) that admitted it.
     *
     * @var array<string, string> `<Op>.<field>` => code
     */
    private const ABSENCE_REFUSED_UNDER_ANOTHER_CODE = [
        // The bulk writers read an absent id list as an empty one and refuse it as such (stop-and-report and ruling
        // of 2026-11-13, `docs/session13/r18b-stop-report-recommendation.md` item 2).
        'CreateBulkArchival.ids' => 'at_least_one_id',
        'CreateBulkRestoration.ids' => 'at_least_one_id',
        'CreateBulkRoleAssignment.user_ids' => 'at_least_one_id',
    ];

    /**
     * Fields served not required whose absence changes the answer: for an entry, probe 6's not-required arm asserts
     * absence answers EXACTLY that status and exactly that set of pointer => code pairs. An entry whose absence answers
     * the control's status is stale, so red. Each entry carries a one-line comment naming the stop-and-report (date and
     * ruling) that admitted it.
     *
     * @var array<string, array{status: int, errors: array<string, string>}>
     */
    private const ABSENCE_CHANGES_THE_OUTCOME = [];

    private const PROBE_7 = '7 (trailing line break)';

    /**
     * Probe 7: fields whose value followed by a line break is refused, with the code each one gives (G-103). Filled from
     * the report-mode run of 2026-11-18 on the fixed tree (`docs/session13/g103-build-progress.md`).
     *
     * @var array<string, string> `<Op>.<field>` => code
     */
    private const LINE_BREAK_REFUSED = [
        'CreateAdjustmentDecision.quantity' => 'not_a_positive_decimal',
        'CreateBulkArchival.resource' => 'unknown_resource',
        'CreateBulkRestoration.resource' => 'unknown_resource',
        'CreateCustomer.country_code' => 'not_a_country_code',
        'CreateCustomer.currency_code' => 'not_a_currency_code',
        'CreateCustomerAddress.country_code' => 'not_a_country_code',
        'CreateCustomerAddress.kind' => 'not_an_address_kind',
        'CreateExport.resource' => 'unknown_resource',
        'CreateFulfilment.lines/0/quantity' => 'not_a_positive_decimal',
        'CreateGoodsReceipt.lines/0/quantity' => 'not_a_positive_decimal',
        'CreatePurchaseOrder.currency_code' => 'not_a_currency_code',
        'CreatePurchaseOrder.lines/0/quantity' => 'not_a_positive_decimal',
        'CreatePurchaseOrder.lines/0/unit_cost' => 'not_a_decimal',
        'CreatePurchaseOrderLine.quantity' => 'not_a_positive_decimal',
        'CreatePurchaseOrderLine.unit_cost' => 'not_a_decimal',
        'CreateSalesOrder.currency_code' => 'not_a_currency_code',
        'CreateSalesOrder.lines/0/quantity' => 'not_a_positive_decimal',
        'CreateSalesOrder.lines/0/unit_price' => 'not_a_decimal',
        'CreateSalesOrderLine.quantity' => 'not_a_positive_decimal',
        'CreateSalesOrderLine.unit_price' => 'not_a_decimal',
        'CreateSalesReturn.lines/0/quantity' => 'not_a_positive_decimal',
        'CreateStockAdjustment.counted_quantity' => 'not_a_decimal',
        'CreateStockTransfer.quantity' => 'not_a_decimal',
        'CreateSupplier.country_code' => 'not_a_country_code',
        'CreateSupplier.currency_code' => 'not_a_currency_code',
        'CreateSupplierProduct.minimum_order_quantity' => 'not_a_positive_decimal',
        'CreateSupplierProduct.unit_cost' => 'not_a_decimal',
        'CreateWarehouse.country_code' => 'not_a_country_code',
        'IssueAdjustmentCreditNote.lines/0/quantity' => 'not_a_positive_decimal',
        'IssueCreditNote.lines/0/quantity' => 'not_a_positive_decimal',
        'UpdatePurchaseOrder.expected_at' => 'not_a_timestamp',
        'UpdatePurchaseOrderLine.quantity' => 'not_a_positive_decimal',
        'UpdateSalesOrderLine.quantity' => 'not_a_positive_decimal',
        'UpdateSalesOrderLine.unit_price' => 'not_a_decimal',
        'UpdateSupplierProduct.unit_cost' => 'not_a_decimal',
    ];

    /**
     * Probe 7: fields the writer trims, so a trailing line break is accepted and dropped. Each reason names the line that
     * trims. The probe asserts only "the control's status, no error at the field", the same as for a field stored as sent.
     *
     * @var array<string, string> `<Op>.<field>` => reason
     */
    private const LINE_BREAK_TRIMMED = [
        'CreatePasswordReset.email' => 'trimmed (`PasswordResetRequest.php:59` → `EmailAddress::fromInput()`); unobservable: the answer is always 202',
        'CreatePasswordReset.workspace' => 'trimmed (`PasswordResetRequest.php:54` → `WorkspaceSlug::fromInput()`); unobservable: the answer is always 202',
        'CreateRole.name' => 'the role name is trimmed: `RoleDefinitionFields.php:96` (`EDGE_SPACE`); shown over HTTP by TrailingLineBreakTest E-15',
        'CreateUser.display_name' => 'a display name is trimmed: `UserDisplayName.php:25` (`RoleDefinitionFields::trimmed()`)',
        'CreateUser.email' => 'the address is trimmed and lower-cased: `UserCreation.php:190` → `EmailAddress::fromInput()` → `PreAuthKeys.php:37`',
        'IssueToken.email' => 'the sign-in email is trimmed and lower-cased before the lookup: `SignIn.php:110` → `PreAuthKeys.php:37`; shown by E-17',
        'IssueToken.workspace' => 'the sign-in workspace is trimmed and lower-cased before the lookup: `SignIn.php:109` → `PreAuthKeys.php:32`',
        'UpdateRole.name' => 'the role name is trimmed: `RoleDefinitionFields.php:96` (`EDGE_SPACE`), the same predicate as CreateRole',
        'UpdateSettings.display_name' => 'the workspace name is trimmed: `SettingsWriter.php:116` (`RoleDefinitionFields::trimmed()`)',
        'UpdateUser.display_name' => 'a display name is trimmed: `UserDisplayName.php:25` (`RoleDefinitionFields::trimmed()`); shown by E-16',
    ];

    /**
     * Probe 7: fields whose value followed by a line break is a different, valid-shaped value, so the outcome changes with
     * no refusal at the field (a password, a token). Asserted: status < 500, not 400.
     *
     * @var array<string, string> `<Op>.<field>` => reason
     */
    private const LINE_BREAK_CHANGES_THE_OUTCOME = [
        'ChangePassword.current_password' => 'a password is taken as sent: with a line break it is a different, wrong current password (422 `current_password_invalid`)',
        'ChangePassword.new_password' => 'a password is taken as sent: a new password ending in a line break is stored as that different password',
        'CreatePasswordResetRedemption.password' => 'a password is taken as sent: a new password ending in a line break is stored as that different password',
        'CreatePasswordResetRedemption.token' => 'the reset token is checked by `$/D` and its length: with a line break it is not the token sent by mail',
        'IssueToken.password' => 'a password is taken as sent: with a line break it is a different, wrong password (401)',
    ];

    /**
     * `appendLineBreak` (probe 7): plant the value the request itself carries at the path, followed by `"\n"`, read at send
     * time — not the counting run's value, which names that run's rig (its actor's email, its reset token) and would be
     * refused for that reason alone.
     *
     * @var array{method: string, template: string, nth: int, path: list<string|int>, value: mixed, unset: bool, appendLineBreak: bool}|null
     */
    private ?array $plant = null;

    private int $matches = 0;

    private int $plants = 0;

    /** @var array<string, mixed>|null the body actually sent by the last plant */
    private ?array $plantedBody = null;

    /** @var list<array<string, mixed>|null> the bodies of the matching requests, in order */
    private array $matchingBodies = [];

    /** @var array{method: string, template: string}|null the operation whose requests are counted, unplanted */
    private ?array $counting = null;

    #[Test]
    public function everyRequestFieldIsDescribedAsItsWriterAcceptsIt(): void
    {
        try {
            $this->probeEveryRequestField();
        } finally {
            $this->owner()->executeStatement('TRUNCATE auth_attempt, auth_ip_budget, auth_lockout');
        }
    }

    private function probeEveryRequestField(): void
    {
        $spec = self::spec();
        $routes = self::routes($spec);
        $cases = self::cases($routes);

        // KIND_FOR_FIELD is two-way against the operation it serves; the pins name real cases.
        $kindCases = array_values(array_filter(array_keys($cases), static fn (string $k): bool => str_starts_with($k, 'CreateAdjustmentDecision.')));
        sort($kindCases);
        $pinned = array_keys(self::KIND_FOR_FIELD);
        sort($pinned);
        self::assertSame($kindCases, $pinned, 'Every CreateAdjustmentDecision field names the recipe (kind) it is probed under, and only those.');
        foreach (self::NULL_ACCEPTED_FOR_SOME_KINDS_ONLY as $exempt) {
            self::assertArrayHasKey($exempt, $cases, $exempt.' is exempt from probe 2 but is not a case.');
            self::assertContains('null', (array) ($cases[$exempt]['schema']['type'] ?? []), $exempt.' is exempt from probe 2 but its type does not admit null.');
        }
        $pinnedAbsence = [...self::REQUIRED_FOR_SOME_KINDS_ONLY, ...array_keys(self::ABSENCE_REFUSED_UNDER_ANOTHER_CODE), ...array_keys(self::ABSENCE_CHANGES_THE_OUTCOME)];
        foreach ($pinnedAbsence as $entry) {
            self::assertArrayHasKey($entry, $cases, $entry.' is pinned for probe 6 but is not a case.');
        }
        foreach (array_keys(self::ABSENCE_REFUSED_UNDER_ANOTHER_CODE) as $entry) {
            self::assertTrue($cases[$entry]['required'], $entry.' is pinned as refused-under-another-code, but is served not required.');
        }
        foreach ([...self::REQUIRED_FOR_SOME_KINDS_ONLY, ...array_keys(self::ABSENCE_CHANGES_THE_OUTCOME)] as $entry) {
            self::assertFalse($cases[$entry]['required'], $entry.' is pinned as a not-required field, but is served required.');
        }

        // Group by (operation, recipe).
        $groups = [];
        foreach ($cases as $key => $case) {
            $groups[$case['operation'].' '.(self::KIND_FOR_FIELD[$key] ?? self::SUCCESS)][] = $key;
        }
        ksort($groups);

        $failures = [];
        $probed = [];
        $absenceProbed = [];
        $runs = 0;
        /** @var array<string, int> $probe3ByKind */
        $probe3ByKind = [];
        $probe3NativeArrays = 0;
        /** @var array<string, int> $probe7ByOutcome */
        $probe7ByOutcome = [];
        /** @var array<string, string> $probe7Cases key => kind */
        $probe7Cases = [];
        foreach ($groups as $group => $keys) {
            [$operation, $recipe] = explode(' ', $group);
            self::assertArrayHasKey($operation, $routes, $operation.' has an input field but no route in the served spec.');
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
            $nthBody = $this->matchingBodies[$nth - 1] ?? null;
            foreach ($keys as $key) {
                $case = $cases[$key];
                foreach ($this->matchingBodies as $body) {
                    foreach (self::valuesAt($body, $case['path']) as $value) {
                        if (null !== ($why = self::nonConformance($value, $case['schema']))) {
                            $failures[] = \sprintf('%s: the recipe\'s own %s does not conform to the served schema: %s', $key, $case['pointer'], $why);
                        }
                    }
                }
            }

            // ---- The probes.
            foreach ($keys as $key) {
                $case = $cases[$key];
                $carried = self::carries($nthBody, $case['path']);
                if (!$carried) {
                    if ($case['required']) {
                        $failures[] = \sprintf('%s is served required, but its recipe succeeds without it.', $key);
                    }
                } else {
                    $absenceProbed[$key] = true;
                }
                $carriedValue = $carried ? (self::valuesAt($nthBody, $case['path'])[0] ?? null) : null;
                foreach (self::probesFor($key, $case, $carried, $expected, $carriedValue) as [$name, $value, $unset, $check]) {
                    $this->freshRig();
                    $this->plant = ['method' => $route['method'], 'template' => $route['template'], 'nth' => $nth, 'path' => $case['path'], 'value' => $value, 'unset' => $unset, 'appendLineBreak' => self::PROBE_7 === $name];
                    $this->matches = 0;
                    $this->plants = 0;
                    $this->plantedBody = null;
                    $this->drive($operation, $recipe);
                    $this->plant = null;
                    ++$runs;
                    $status = $this->httpStatus();
                    $errors = $this->errors();
                    $codes = array_values(array_map(static fn (array $e): string => $e[1], array_filter($errors, static fn (array $e): bool => $e[0] === $case['pointer'])));
                    $planted = $unset ? 'absent' : json_encode($value);
                    if (1 !== $this->plants) {
                        $failures[] = \sprintf('%s probe %s: %d plants happened, not exactly one.', $key, $name, $this->plants);
                        continue;
                    }
                    if ($unset && self::carries($this->plantedBody, $case['path'])) {
                        $failures[] = \sprintf('%s probe %s: the plant did not land — the body sent still carries %s.', $key, $name, $case['pointer']);
                        continue;
                    }
                    if ($status >= 500) {
                        $failures[] = \sprintf('%s probe %s (%s planted): answered %d — a shipped defect, not a description question; %s', $key, $name, $planted, $status, $this->bodyExcerpt());
                        continue;
                    }
                    if (400 === $status) {
                        $failures[] = \sprintf('%s probe %s (%s planted): answered 400 — outside API.md §5.2 (G-101); %s', $key, $name, $planted, $this->bodyExcerpt());
                        continue;
                    }
                    if (self::PROBE_3 === $name) {
                        $probe3ByKind[$case['kind']] = ($probe3ByKind[$case['kind']] ?? 0) + 1;
                        if ('nested line' !== $case['kind'] && !$case['mixed'] && \in_array('array', (array) ($case['schema']['type'] ?? []), true)) {
                            ++$probe3NativeArrays;
                        }
                    }
                    if (self::PROBE_7 === $name) {
                        $probe7Cases[$key] = $case['kind'];
                        $probe7ByOutcome[self::lineBreakOutcome($key)] = ($probe7ByOutcome[self::lineBreakOutcome($key)] ?? 0) + 1;
                    }
                    if (null !== ($why = $check($status, $codes, $errors, $this->detail()))) {
                        $failures[] = \sprintf('%s probe %s (%s planted): %s — answered %d, codes at %s: [%s]; %s',
                            $key, $name, $planted, $why, $status, $case['pointer'], implode(', ', $codes), $this->bodyExcerpt());
                    }
                }
                $probed[] = $key;
            }
        }

        // ---- Assertions.
        self::assertSame([], $failures, "A request field's served description disagrees with what its writer does:\n".implode("\n", $failures));

        foreach ($pinnedAbsence as $entry) {
            self::assertArrayHasKey($entry, $absenceProbed, $entry.' is pinned for probe 6, but its recipe never sends it, so the pin is never executed (stale).');
        }

        $expectedCases = array_keys($cases);
        sort($expectedCases);
        $probed = array_values(array_unique($probed));
        sort($probed);
        self::assertSame($expectedCases, $probed, 'The probed set is the case set, two-way: a field went unprobed.');
        $kinds = array_count_values(array_map(static fn (string $k): string => $cases[$k]['kind'], $probed));
        foreach (self::KINDS as $kind) {
            self::assertGreaterThanOrEqual(1, $kinds[$kind] ?? 0, 'Anti-vacuity: no field of kind "'.$kind.'" was probed.');
        }
        // G-101: probe 3 ran on every kind, two-way, and on at least one natively typed array (`lines`).
        $ran = array_keys($probe3ByKind);
        sort($ran);
        $all = self::KINDS;
        sort($all);
        self::assertSame($all, $ran, 'Probe 3 (wrong type) did not run on exactly the five kinds of field.');
        self::assertGreaterThanOrEqual(1, $probe3NativeArrays, 'Anti-vacuity: probe 3 ran on no natively typed field of served base array.');
        self::assertGreaterThan(\count($groups), $runs, 'Fewer recipe runs than (operation, recipe) groups: the probes never ran.');

        // G-103: probe 7's pins are two-way against the cases it ran on; it ran top-level and nested; every class is used.
        foreach ([...array_keys(self::LINE_BREAK_REFUSED), ...array_keys(self::LINE_BREAK_TRIMMED), ...array_keys(self::LINE_BREAK_CHANGES_THE_OUTCOME)] as $pinnedKey) {
            self::assertArrayHasKey($pinnedKey, $probe7Cases, $pinnedKey.' is pinned for probe 7, but probe 7 never ran on it (not a carried plain-string field): stale.');
        }
        self::assertContains('nested line', $probe7Cases, 'Anti-vacuity: probe 7 ran on no nested line field.');
        self::assertNotSame([], array_filter($probe7Cases, static fn (string $kind): bool => 'nested line' !== $kind), 'Anti-vacuity: probe 7 ran on no top-level field.');
        foreach (['not_a_positive_decimal', 'not_a_decimal', 'not_a_country_code', 'not_a_currency_code'] as $code) {
            self::assertContains($code, self::LINE_BREAK_REFUSED, 'Anti-vacuity: no field is pinned refusing a trailing line break as '.$code.'.');
        }
        $outcomes = array_keys($probe7ByOutcome);
        sort($outcomes);
        self::assertSame(['changes the outcome', 'refused', 'stored as sent', 'trimmed'], $outcomes, 'Probe 7 did not see all four outcome classes.');
    }

    /** Probe 7's class for a key: the map it is pinned in, else stored as sent. */
    private static function lineBreakOutcome(string $key): string
    {
        return match (true) {
            isset(self::LINE_BREAK_REFUSED[$key]) => 'refused',
            isset(self::LINE_BREAK_TRIMMED[$key]) => 'trimmed',
            isset(self::LINE_BREAK_CHANGES_THE_OUTCOME[$key]) => 'changes the outcome',
            default => 'stored as sent',
        };
    }

    /**
     * The cases: every top-level request field (two-way: reflection = served spec) and every line-item field.
     *
     * @param array<string, array{method: string, template: string, properties: array<string, array<string, mixed>>, required: list<string>}> $routes
     *
     * @return array<string, array{operation: string, path: list<string|int>, pointer: string, kind: string, mixed: bool, phpNullable: bool, constructorWithoutDefault: bool, schema: array<string, mixed>, required: bool}>
     */
    private static function cases(array $routes): array
    {
        $cases = [];
        foreach (OpenApiContractTest::requestInputFields() as $field) {
            $operation = $field['operation'];
            $wire = $field['wire'];
            $key = $operation.'.'.$wire;
            self::assertArrayHasKey($operation, $routes, $operation.' has an input class but no route in the served spec.');
            self::assertArrayHasKey($wire, $routes[$operation]['properties'], $key.' ('.$field['key'].') is not a property of the served request schema.');
            $kind = match ($field['declaration']) {
                'mixed' => 'mixed',
                'constructor' => 'constructor',
                default => $field['hasDefault'] ? 'native ?string = null' : 'native uninitialised',
            };
            $cases[$key] = [
                'operation' => $operation,
                'path' => [$wire],
                'pointer' => '/'.$wire,
                'kind' => $kind,
                'mixed' => 'mixed' === $field['declaration'],
                'phpNullable' => $field['phpNullable'],
                'constructorWithoutDefault' => 'constructor' === $field['declaration'] && !$field['hasDefault'],
                'schema' => $routes[$operation]['properties'][$wire],
                'required' => \in_array($wire, $routes[$operation]['required'], true),
            ];
        }

        $served = [];
        foreach ($routes as $operation => $route) {
            foreach ($route['properties'] as $wire => $property) {
                $served[] = $operation.'.'.$wire;
                if ('object' !== ($property['items']['type'] ?? null)) {
                    continue;
                }
                $required = $property['items']['required'] ?? [];
                foreach ($property['items']['properties'] ?? [] as $name => $schema) {
                    $cases[$operation.'.'.$wire.'/0/'.$name] = [
                        'operation' => $operation,
                        'path' => [$wire, 0, $name],
                        'pointer' => '/'.$wire.'/0/'.$name,
                        'kind' => 'nested line',
                        'mixed' => false,
                        'phpNullable' => true,
                        'constructorWithoutDefault' => false,
                        'schema' => $schema,
                        'required' => \in_array($name, $required, true),
                    ];
                }
            }
        }
        $reflected = array_values(array_filter(array_keys($cases), static fn (string $k): bool => !str_contains($k, '/')));
        sort($reflected);
        sort($served);
        self::assertSame($served, $reflected, 'Every served request-body property is a reflected input field, and every reflected input field is served.');
        ksort($cases);

        return $cases;
    }

    /**
     * The probes owed to one field by its served schema.
     *
     * @param array{operation: string, path: list<string|int>, pointer: string, kind: string, mixed: bool, phpNullable: bool, constructorWithoutDefault: bool, schema: array<string, mixed>, required: bool} $case
     *
     * @return list<array{0: string, 1: mixed, 2: bool, 3: callable(int, list<string>, list<array{0: string, 1: string}>, ?string): ?string}>
     */
    private static function probesFor(string $key, array $case, bool $carried, int $control, mixed $carriedValue = null): array
    {
        $schema = $case['schema'];
        $types = (array) ($schema['type'] ?? []);
        $nullable = \in_array('null', $types, true);
        $base = array_values(array_diff($types, ['null']));
        self::assertCount(1, $base, $key.' must serve exactly one non-null type.');
        $base = $base[0];

        $probes = [];
        if (!$nullable) {
            $exact = !$case['phpNullable'];
            $probes[] = ['1 (null refused)', null, false, static function (int $status, array $codes) use ($exact): ?string {
                if (422 !== $status || [] === $codes) {
                    return 'its type refuses null, but null was not refused at the field';
                }
                // G-101: a null a non-nullable PHP type cannot hold is `required` at the field (every such field is a
                // constructor string on a POST).
                if ($exact && ['required'] !== $codes) {
                    return 'its PHP type is non-nullable, but null was refused as ['.implode(', ', $codes).'], not exactly required';
                }

                return \in_array('not_for_this_kind', $codes, true) ? 'refused only as not_for_this_kind (wrong recipe for the field)' : null;
            }];
        } elseif (!\in_array($key, self::NULL_ACCEPTED_FOR_SOME_KINDS_ONLY, true)) {
            $probes[] = ['2 (null accepted)', null, false, static function (int $status, array $codes): ?string {
                if (400 === $status) {
                    return 'its type admits null, but null was refused as a malformed body (400)';
                }

                return 422 === $status && [] !== $codes ? 'its type admits null, but null was refused at the field' : null;
            }];
        }

        $native = !$case['mixed'] && 'nested line' !== $case['kind'];
        if ($native) {
            [$wrong, $code] = match ($base) {
                'string' => [1, 'not_a_string'],
                'array' => ['x', 'not_a_list'],
                default => throw new \LogicException(\sprintf('%s is a natively typed field of served type %s: teach the translator (G-101, FrameworkProblemTranslator::fieldTypeRefusal()) and this map.', $key, $base)),
            };
            $probes[] = [self::PROBE_3, $wrong, false, static function (int $status, array $codes, array $errors) use ($code): ?string {
                if (422 !== $status || 1 !== \count($errors) || [$code] !== $codes) {
                    return 'a value of the wrong type was not refused with exactly one error, '.$code.' at the field';
                }

                return null;
            }];
        } else {
            $wrong = match ($base) {
                'integer' => '1',
                'boolean' => 'true',
                'string' => 1,
                'array', 'object' => 'x',
                default => throw new \LogicException(\sprintf('%s serves type %s, which this probe has no wrong value for: teach it one.', $key, $base)),
            };
            $probes[] = [self::PROBE_3, $wrong, false, static function (int $status, array $codes): ?string {
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
        }

        if ('string' === $base) {
            $uuid = 'uuid' === ($schema['format'] ?? null);
            $probes[] = ['4 (not a uuid)', 'not-a-uuid', false, static function (int $status, array $codes) use ($uuid): ?string {
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
            $probes[] = ['5 (member '.$member.')', $member, false, static fn (int $status, array $codes): ?string => 422 === $status && [] !== $codes ? 'a member of its enum was refused at the field' : null];
        }
        if (isset($schema['enum'])) {
            $probes[] = ['5 (non-member)', 'zz-not-a-member', false, static fn (int $status, array $codes): ?string => 422 === $status && [] !== $codes ? null : 'a value outside its enum was not refused at the field'];
        }

        if ($carried) {
            $probes[] = ['6 (absent)', null, true, self::absenceCheck($key, $case, $control)];
        }

        if ($carried && 'string' === $base && !isset($schema['format']) && !isset($schema['enum']) && \is_string($carriedValue)) {
            $probes[] = [self::PROBE_7, $carriedValue."\n", false, self::lineBreakCheck($key, $control)];
        }

        return $probes;
    }

    /**
     * Probe 7's judgement of a response to the recipe's own value followed by one line break (G-103).
     *
     * @return callable(int, list<string>, list<array{0: string, 1: string}>, ?string): ?string
     */
    private static function lineBreakCheck(string $key, int $control): callable
    {
        if (isset(self::LINE_BREAK_REFUSED[$key])) {
            $code = self::LINE_BREAK_REFUSED[$key];

            return static fn (int $status, array $codes): ?string => 422 === $status && [$code] === $codes
                ? null
                : 'it is pinned to refuse a trailing line break as '.$code.', but did not (LINE_BREAK_REFUSED)';
        }
        if (isset(self::LINE_BREAK_CHANGES_THE_OUTCOME[$key])) {
            return static fn (): ?string => null;
        }
        $pinned = isset(self::LINE_BREAK_TRIMMED[$key]) ? 'pinned trimmed (LINE_BREAK_TRIMMED)' : 'recorded as stored as sent (not pinned)';

        return static function (int $status, array $codes) use ($control, $pinned): ?string {
            if ([] !== $codes) {
                return 'it refuses a trailing line break but is '.$pinned.': pin it in LINE_BREAK_REFUSED';
            }

            return $status === $control ? null : 'a trailing line break changed the answer from '.$control.' with no refusal at the field, but it is '.$pinned.': pin it in LINE_BREAK_CHANGES_THE_OUTCOME';
        };
    }

    /**
     * Probe 6's judgement of a response to the request with the field left out.
     *
     * @param array{pointer: string, required: bool, constructorWithoutDefault: bool} $case
     *
     * @return callable(int, list<string>, list<array{0: string, 1: string}>, ?string): ?string
     */
    private static function absenceCheck(string $key, array $case, int $control): callable
    {
        $seen = static fn (array $errors): string => implode(', ', array_map(static fn (array $e): string => $e[0].': '.$e[1], $errors));

        if ($case['required']) {
            $pointer = $case['pointer'];
            $code = self::ABSENCE_REFUSED_UNDER_ANOTHER_CODE[$key] ?? 'required';

            return static function (int $status, array $codes, array $errors) use ($key, $pointer, $code, $seen): ?string {
                foreach ($errors as [$at, $seenCode]) {
                    if ($at === $pointer && $seenCode === $code) {
                        return null;
                    }
                }

                return \sprintf('%s is served required, but leaving it out was not refused %s at "%s" (errors: %s)', $key, $code, $pointer, $seen($errors));
            };
        }

        if (\in_array($key, self::REQUIRED_FOR_SOME_KINDS_ONLY, true)) {
            $pointer = $case['pointer'];

            return static function (int $status, array $codes) use ($key, $pointer): ?string {
                return 422 === $status && \in_array('required', $codes, true)
                    ? null
                    : \sprintf('%s is pinned required for its kind, but leaving it out was not refused required at %s (stale pin)', $key, $pointer);
            };
        }

        if (isset(self::ABSENCE_CHANGES_THE_OUTCOME[$key])) {
            $pinned = self::ABSENCE_CHANGES_THE_OUTCOME[$key];

            return static function (int $status, array $codes, array $errors) use ($key, $pinned, $control, $seen): ?string {
                if ($status === $control) {
                    return \sprintf('%s is pinned as changing the outcome, but leaving it out answered the control\'s %d (stale pin)', $key, $control);
                }
                $observed = [];
                foreach ($errors as [$at, $code]) {
                    $observed[$at] = $code;
                }
                ksort($observed);
                $expected = $pinned['errors'];
                ksort($expected);

                return $status === $pinned['status'] && $observed === $expected
                    ? null
                    : \sprintf('%s is pinned to answer %d with [%s] when left out, but answered %d with [%s]', $key, $pinned['status'], json_encode($expected), $status, $seen($errors));
            };
        }

        return static function (int $status, array $codes, array $errors) use ($key, $control, $seen): ?string {
            return $status === $control
                ? null
                : \sprintf('%s is served optional, but leaving it out changed the answer to %d (errors: %s)', $key, $status, $seen($errors));
        };
    }

    /**
     * Runs one recipe, fixture first, and answers the status and label it expects.
     *
     * @return array{0: int, 1: string}
     */
    private function drive(string $operation, string $recipe): array
    {
        if ('IssueToken' === $operation) {
            // T-R1-local: the shared recipe presents the password ChangePassword's recipe set, so it cannot succeed alone
            // (both closures are local; final RED L-4).
            $this->setPassword(self::TENANT_A, $this->actorId, self::PROBE_PASSWORD);
            $email = (string) ($this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT email FROM app_user WHERE id = ?::uuid', [$this->actorId])[0]['email'] ?? '');
            $this->send('POST', '/auth/token', ['workspace' => 'tenant-a', 'email' => $email, 'password' => self::PROBE_PASSWORD]);

            return [200, 'sign in with the password this recipe set'];
        }

        $recipes = $this->successRecipes();
        self::assertArrayHasKey($operation, $recipes, $operation.' has an input field but no success recipe.');
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

    /**
     * A fresh rig: tables truncated, kernel and client rebuilt, the default actor re-established, and the three
     * pre-authentication limiter tables emptied (not tenant-scoped, so the base truncate does not reach them; as the
     * owner, because no application role holds TRUNCATE on them — `AuthTestCase::setUp` does the same).
     */
    private function freshRig(): void
    {
        $this->tearDown();
        $this->setUp();
        $this->owner()->executeStatement('TRUNCATE auth_attempt, auth_ip_budget, auth_lockout');
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
                $value = $this->plant['value'];
                if ($this->plant['appendLineBreak'] && \is_string($current = self::valuesAt($body, $this->plant['path'])[0] ?? null)) {
                    $value = $current."\n";
                }
                $body = self::planted($body, $this->plant['path'], $value, $this->plant['unset']);
                $this->plantedBody = $body;
                ++$this->plants;
            }
            if (null !== $this->counting) {
                $this->matchingBodies[] = $body;
            }
        }

        parent::send($method, $path, $body, $headers, $accept);
    }

    /**
     * @param array<string, mixed>   $body
     * @param list<string|int>       $path
     *
     * @return array<string, mixed>
     */
    private static function planted(array $body, array $path, mixed $value, bool $unset): array
    {
        $head = array_shift($path);
        if ([] === $path) {
            if ($unset) {
                unset($body[$head]);
            } else {
                $body[$head] = $value;
            }

            return $body;
        }
        $child = \is_array($body[$head] ?? null) ? $body[$head] : [];
        $body[$head] = self::planted($child, $path, $value, $unset);

        return $body;
    }

    /** @param list<string|int> $path */
    private static function carries(mixed $body, array $path): bool
    {
        foreach ($path as $segment) {
            if (!\is_array($body) || !\array_key_exists($segment, $body)) {
                return false;
            }
            $body = $body[$segment];
        }

        return true;
    }

    /**
     * Every value the body carries at the path, where the line index is any line (so the recipe's own lines are all
     * checked for conformance).
     *
     * @param list<string|int> $path
     *
     * @return list<mixed>
     */
    private static function valuesAt(mixed $body, array $path): array
    {
        if ([] === $path) {
            return [$body];
        }
        $head = array_shift($path);
        if (!\is_array($body)) {
            return [];
        }
        if (\is_int($head)) {
            $values = [];
            foreach (array_is_list($body) ? $body : [] as $item) {
                $values = [...$values, ...self::valuesAt($item, $path)];
            }

            return $values;
        }

        return \array_key_exists($head, $body) ? self::valuesAt($body[$head], $path) : [];
    }

    private static function normalise(string $path): string
    {
        $path = (string) preg_replace('/\?.*$/', '', $path);
        if (str_starts_with($path, self::BASE)) {
            $path = substr($path, \strlen(self::BASE));
        }

        return (string) preg_replace('#/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(?=/|$)#i', '/{…}', $path);
    }

    /** @return list<array{0: string, 1: string}> every (pointer, code) the last response names */
    private function errors(): array
    {
        $body = json_decode((string) $this->client()->getResponse()->getContent(), true);
        $errors = [];
        foreach (\is_array($body) && \is_array($body['errors'] ?? null) ? $body['errors'] : [] as $error) {
            if (\is_array($error)) {
                $errors[] = [(string) ($error['pointer'] ?? ''), (string) ($error['code'] ?? '')];
            }
        }

        return $errors;
    }

    private function detail(): ?string
    {
        $body = json_decode((string) $this->client()->getResponse()->getContent(), true);

        return \is_array($body) && \is_string($body['detail'] ?? null) ? $body['detail'] : null;
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
        if (\is_array($value) && array_is_list($value) && isset($schema['items']) && \is_array($schema['items']) && 'object' !== ($schema['items']['type'] ?? null)) {
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
     * Each operation's method, normalised path template, request-body properties and `required` list, from the served
     * spec (operations without a request body are left out).
     *
     * @param array<string, mixed> $spec
     *
     * @return array<string, array{method: string, template: string, properties: array<string, array<string, mixed>>, required: list<string>}>
     */
    private static function routes(array $spec): array
    {
        $routes = [];
        foreach ($spec['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (!\is_array($operation) || !isset($operation['operationId']) || !isset($operation['requestBody'])) {
                    continue;
                }
                $schema = [];
                foreach ($operation['requestBody']['content'] ?? [] as $media) {
                    $schema = $media['schema'] ?? [];
                    if (isset($schema['$ref'])) {
                        $schema = $spec['components']['schemas'][substr($schema['$ref'], \strlen('#/components/schemas/'))] ?? [];
                    }
                    break;
                }
                $template = (string) preg_replace('/\{[^}]+\}/', '{…}', str_starts_with($path, self::BASE) ? substr($path, \strlen(self::BASE)) : $path);
                $routes[(string) $operation['operationId']] = [
                    'method' => strtoupper($method),
                    'template' => $template,
                    'properties' => $schema['properties'] ?? [],
                    'required' => array_values($schema['required'] ?? []),
                ];
            }
        }

        return $routes;
    }
}

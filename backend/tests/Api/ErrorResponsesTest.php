<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Http\ProblemResponses;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Http\Problem\ProblemType;
use App\Platform\Security\PermissionRequirement;
use App\Platform\Security\PermissionRequirements;
use App\Tests\Support\ContractDocument;
use App\Tests\Support\DeclaredRefusals;
use App\Tests\Support\ErrorContractRules;
use App\Tests\Support\GeneratedSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactoryInterface;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **The published spec lists each operation's refusals, in the shape the server sends them** (BK-18 R18-h C1).
 *
 * - T-1 holds each operation's declared refusal statuses to the contract (`docs/API.md` §4.2 and §5.2), both ways. For
 *   401/403/404/409/422/428 that is a second statement of one rule (the decorator's and §5.2's); its independent bite
 *   is on the operations' own attribute entries (401 on `/auth/`, 429) against their `Errors` cells. Only the drivers
 *   ({@see DeclaredRefusals}) hold the rule to behaviour.
 * - T-2 holds every refusal entry to the house shape: one `application/problem+json` entry referencing `Problem`, whose
 *   members are the ones `ApiProblem` serializes. Needs no document.
 * - The key-less probe drives every keyed operation without an `Idempotency-Key` and asks the spec whether it lists the
 *   428 it got. Needs no document.
 *
 * What none of this proves: a status declared but not provoked by any driver — 404 and 409 beyond the operations a probe
 * reaches, 409 on the four contention-only writes, `CreatePasswordResetRedemption`'s 429, `CreateBulkRestoration`'s 409 —
 * rests on §5.2's sentences (V1-C1b, carried).
 */
final class ErrorResponsesTest extends ApiTestCase
{
    use DeclaredRefusals;

    /**
     * §5.2's paragraph "What the published spec lists, by status", whitespace folded. Each marker is copied from the
     * applied text and carries one rule T-1 applies: editing a rule there turns this test red.
     */
    private const MARKER_NARROWING = 'The published spec lists `not-found` only on operations whose path names a record, and `permission-denied` only on operations with a permission gate.';

    private const MARKER_WRITES = 'It lists 409 on every write except `CreatePasswordReset`, which is queued and answers 202 for every well-formed input';

    private const MARKER_CONTENTION = 'One 409 belongs to no operation\'s cell';

    /** The catalogue types that answer how a request was sent; stated once for the whole API, never per operation. */
    private const TRANSPORT = [ProblemType::UnsupportedMediaType, ProblemType::NotAcceptable, ProblemType::MethodNotAllowed];

    /** API Platform's own success description, which says nothing about the answer. */
    private const VENDOR_SUCCESS = '/^[A-Z][A-Za-z]* (resource|collection)( created| updated| deleted)?$/';

    /**
     * The success templates, word for word as `UX` wrote them (`{words}` = the served component's name, a trailing
     * `Collection` dropped, split at each capital, lowercased). Copied here on purpose: this test holds the published
     * wording to that text, so a change to the decorator's copy alone is red.
     *
     * @var array<string, string>
     */
    private const SUCCESS_TEMPLATES = [
        'paged list' => 'One page of the {words} list. `next_cursor` is null on the last page; otherwise send it as `after` for the next page.',
        'bounded list' => 'The {words} list. It is not paged: `next_cursor` and `previous_cursor` are always null.',
        'read' => 'The {words}.',
        'edit' => 'The {words}, as saved.',
        'action' => 'The {words}, as saved.',
        'create' => 'The {words} this request created. `Location` gives its address.',
        'delete' => 'Done. The answer has no body.',
    ];

    /**
     * Hand-written success descriptions held word for word to `UX`'s text, copied here on purpose: the attribute is
     * compared with the metadata, which is the same text, so without this copy a change to the attribute alone is green.
     *
     * @var array<string, array<string, string>>
     */
    private const OWN_SUCCESS_WORDING = [
        'CreateAdjustmentDecision' => ['200' => 'The sales return as it reads after the decision this request recorded. Each line lists its decisions in `adjustment_decisions`.'],
        'CreatePasswordReset' => ['202' => 'Accepted. If that email belongs to an active account in that workspace, and the workspace allows signing in, a link to set a new password is sent to it, subject to a limit on how many links one account is sent in 24 hours. The answer is the same in every case, so it does not say whether the account exists or whether a link was sent. The answer has no body.'],
    ];

    /** The API description's paragraph on refusals for how a request was sent, word for word as `UX` wrote it. */
    private const TRANSPORT_NOTE = 'Any request can also be refused for how it was sent rather than what it asks: 405 when the address does not serve that method (`allow` and the `Allow` header list the methods it does), 406 when the `Accept` header excludes the operation\'s format (`application/json` for every operation except the export, which is `text/csv`), and 415 when a body is sent with a `Content-Type` other than `application/json`. These three, like every refusal listed on an operation, are sent as `application/problem+json`. An `Accept` that names only `application/problem+json` is itself answered 406, so name the operation\'s format as well: `Accept: application/json, application/problem+json`, or `Accept: text/csv, application/problem+json` for the export. One refusal is outside that shape: a request whose `Host` header is malformed is refused 400 before any operation runs. None of these is listed on each operation.';

    /**
     * The hand-written descriptions that still end in a review id, kept until `PO` rules where it is dropped; the id is
     * the only one each may carry.
     */
    private const SUCCESS_ID_PENDING = ['CreateBulkArchival' => '(E-14)', 'CreateBulkRestoration' => '(E-14)', 'CreateBulkRoleAssignment' => '(E-14)'];

    /**
     * *fails if:* an operation declares a refusal status the contract does not give it, or omits one it does.
     *
     * Expected, per built §4.2 row: §5.2's universal sentence (parsed by {@see ErrorContractRules}, never retyped),
     * narrowed by the named rules of its "What the published spec lists" paragraph — `not-found` only where the path
     * names a record, `permission-denied` only where `Perm` is not `—`, the transport types never — plus 409 on every
     * write but the paragraph's named exception, plus each `Errors`-cell type's status.
     */
    #[Test]
    public function everyOperationDeclaresTheErrorStatusesTheContractGivesIt(): void
    {
        if (!ContractDocument::isAvailable()) {
            self::markTestSkipped('docs/API.md is gitignored and absent: §4.2\'s cells and §5.2\'s sentence live there.');
        }

        $folded = ErrorContractRules::foldedSection();
        foreach ([self::MARKER_NARROWING, self::MARKER_WRITES, self::MARKER_CONTENTION] as $marker) {
            // Not assertStringContainsString: its failure would print the whole section.
            self::assertTrue(str_contains($folded, $marker), '§5.2 no longer carries a rule this test applies: '.$marker);
        }

        $spec = $this->spec();
        $rows = ContractDocument::operationRows();
        $declared = self::declaredRefusalStatuses($spec);
        $expected = self::expectedRefusalStatuses($rows, array_keys($declared));

        // Anti-vacuity: every spec operation compared, every status reached by some expectation.
        self::assertSame(\count($declared), \count($expected), 'Not every operation in the spec has a §4.2 row.');
        self::assertGreaterThan(100, \count($expected), 'Almost no operation was compared.');
        $reached = array_unique(array_merge(...array_values($expected)));
        foreach ([401, 403, 404, 409, 422, 428, 429] as $status) {
            self::assertContains($status, $reached, 'No operation is expected to answer '.$status.', so that rule is untested.');
        }

        // Controls: each planted defect is named.
        $plant = static function (array $declared, string $operation, ?int $add, ?int $remove): array {
            if (null !== $add) {
                $declared[$operation][] = $add;
            }
            $declared[$operation] = array_values(array_diff($declared[$operation], null === $remove ? [] : [$remove]));

            return $declared;
        };
        self::assertContains('CreateItem declares 400, which the contract does not give it', self::faults($expected, $plant($declared, 'CreateItem', 400, null)));
        self::assertContains('GetItem can answer 404 per the contract but does not declare it', self::faults($expected, $plant($declared, 'GetItem', null, 404)));
        self::assertContains('ListItems declares 404, which the contract does not give it', self::faults($expected, $plant($declared, 'ListItems', 404, null)));
        self::assertContains('GetCurrentUser declares 403, which the contract does not give it', self::faults($expected, $plant($declared, 'GetCurrentUser', 403, null)));
        $plantedRows = $rows;
        $plantedRows['CreateItem']['errors'] .= ' `too-many-attempts`';
        self::assertContains('CreateItem can answer 429 per the contract but does not declare it',
            self::faults(self::expectedRefusalStatuses($plantedRows, array_keys($declared)), $declared),
            'A type added to a cell did not move the expectation, so the cells do not drive it.');

        self::assertSame([], self::faults($expected, $declared), 'The published refusal statuses disagree with the contract.');

        // The key-less probe's domain (the registry's keyed set) is §4.2's: the operations whose `Idem` demands the header.
        $keyed = self::keyedNames();
        $demanded = array_values(array_filter(array_keys($declared), ContractDocument::headerIsRequiredFor(...)));
        sort($demanded);
        self::assertSame($demanded, $keyed, 'The registry\'s keyed set (the key-less probe\'s domain) is not §4.2\'s.');
    }

    /**
     * *fails if:* a refusal entry is not one `application/problem+json` entry referencing `Problem`; a 400 or a `default`
     * is declared; `Problem`'s members differ from what `ApiProblem` serializes; its `type` enum is not the catalogue;
     * a 401 does not declare `WWW-Authenticate`; or a spec operation is not an application operation of the same name.
     */
    #[Test]
    public function everyRefusalIsDeclaredInTheShapeTheServerSends(): void
    {
        $spec = $this->spec();
        $byProperty = $this->serializedProblemMembers();
        // The derivation is under test only if it renames something: at least one member is serialized under a name
        // other than its PHP property's, so a list copied from the property names would differ from it.
        self::assertNotSame([], array_filter($byProperty, static fn (string $wire, string $property): bool => $wire !== $property, \ARRAY_FILTER_USE_BOTH),
            'The serializer metadata yielded no renamed member, so the derivation is not under test.');
        $members = array_values($byProperty);
        sort($members);
        // Control: the property names themselves are not the published member set.
        $properties = array_keys($byProperty);
        sort($properties);
        self::assertContains('Problem\'s members are not the ones ApiProblem serializes', self::shapeFaults($spec, $properties),
            'Problem\'s published members equal its PHP property names, so the serialized names were not read.');

        // L-8: the decorator indexes metadata by operation name; every spec operation must be found that way.
        $names = array_keys(TransactionBoundaryTest::contractOperations());
        $unmatched = [];
        foreach (GeneratedSpec::operations($spec) as $key => $entry) {
            if (!\in_array($entry['operation']['operationId'] ?? null, $names, true)) {
                $unmatched[] = $key.' ('.($entry['operation']['operationId'] ?? 'no operationId').')';
            }
        }
        self::assertSame([], $unmatched, 'A spec operation\'s operationId is not the name of an application operation.');

        // Controls.
        $planted = $spec;
        $first = array_key_first($planted['paths']);
        $method = (string) array_key_first($planted['paths'][$first]);
        $planted['paths'][$first][$method]['responses']['422']['content'] = ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Error']]];
        self::assertNotSame([], array_filter(self::shapeFaults($planted, $members), static fn (string $f): bool => str_contains($f, 'referencing Problem')), 'A refusal referencing Error was not named.');
        $planted = $spec;
        $planted['components']['schemas']['Problem']['properties']['instance'] = ['type' => 'string'];
        self::assertContains('Problem\'s members are not the ones ApiProblem serializes', self::shapeFaults($planted, $members));
        $planted = $spec;
        array_pop($planted['components']['schemas']['Problem']['properties']['type']['enum']);
        self::assertContains('Problem\'s type enum is not the catalogue', self::shapeFaults($planted, $members));
        $planted = $spec;
        unset($planted['paths']['/api/v1/items']['get']['responses']['401']['headers']);
        self::assertContains('GET /api/v1/items: its 401 does not declare WWW-Authenticate', self::shapeFaults($planted, $members));
        $planted = $spec;
        unset($planted['components']['schemas']['Problem']['additionalProperties']);
        self::assertContains('Problem\'s required members are not type, title and status, or it admits others', self::shapeFaults($planted, $members));
        $planted = $spec;
        $planted['components']['schemas']['ProblemFieldError']['properties']['hint'] = ['type' => 'string'];
        self::assertContains('ProblemFieldError is not {pointer, code, message}, all required', self::shapeFaults($planted, $members));
        $planted = $spec;
        $planted['paths']['/api/v1/items']['get']['responses']['default'] = $planted['paths']['/api/v1/items']['get']['responses']['422'];
        self::assertContains('GET /api/v1/items declares default', self::shapeFaults($planted, $members));

        // The 403 sentence names the member the server really sends, read from the serializer, not typed here.
        $named = '`'.$byProperty['requiredPermission'].'`';
        $without = [];
        foreach (GeneratedSpec::operations($spec) as $key => $entry) {
            $sentence = $entry['operation']['responses']['403']['description'] ?? null;
            if (null !== $sentence && !str_contains($sentence, $named)) {
                $without[] = $key;
            }
        }
        self::assertSame([], $without, 'A 403 description does not name the member that carries the permission.');

        self::assertSame([], self::shapeFaults($spec, $members));
    }

    /**
     * *fails if:* a success answer keeps API Platform's own description, or says something its status or body cannot back.
     *
     * Each 2xx description is either the operation's own (from its `openapi` attribute, read from the metadata) or the
     * one template its facts select: method, status, whether the served component pages (`next_cursor`, plus an `after`
     * parameter) and whether it has an `id`. Independently of that: "created" only on a 201; every 201 mentions and
     * declares `Location`; every 202 and 204 says it has no body; a `POST` 200 whose body has no `id` is never called
     * "as saved"; no description carries a review id.
     */
    #[Test]
    public function everySuccessDescriptionSaysWhatComesBack(): void
    {
        $spec = $this->spec();
        $own = self::ownSuccessDescriptions();
        self::assertGreaterThanOrEqual(6, \count($own), 'Almost no operation describes its own success answer, so that half is untested.');

        // Anti-vacuity: every template lands somewhere, and every success entry is compared.
        $counts = array_fill_keys(array_keys(self::SUCCESS_TEMPLATES), 0);
        $entries = 0;
        foreach (GeneratedSpec::operations($spec) as $entry) {
            foreach ($entry['operation']['responses'] ?? [] as $status => $response) {
                if (str_starts_with((string) $status, '2')) {
                    ++$entries;
                    $kind = self::successKind($spec, $entry, (string) $status);
                    if (null !== $kind && !isset($own[$entry['operation']['operationId']][(string) $status])) {
                        ++$counts[$kind];
                    }
                }
            }
        }
        self::assertSame(\count(GeneratedSpec::operations($spec)), $entries, 'An operation does not have exactly one success answer, so the comparison is not one per operation.');
        foreach ($counts as $kind => $count) {
            self::assertGreaterThan(0, $count, 'No operation takes the '.$kind.' template, so it is untested.');
        }

        // Controls: each planted defect is named.
        $with = static function (array $spec, string $path, string $method, string $status, ?string $description, bool $dropLocation = false): array {
            if (null !== $description) {
                $spec['paths'][$path][$method]['responses'][$status]['description'] = $description;
            }
            if ($dropLocation) {
                unset($spec['paths'][$path][$method]['responses'][$status]['headers']['Location']);
            }

            return $spec;
        };
        self::assertContains('GET /api/v1/items/{id} 200 keeps the vendor description', self::successFaults($with($spec, '/api/v1/items/{id}', 'get', '200', 'Item resource'), $own));
        self::assertContains('GET /api/v1/items/{id} 200 says "created" but is not a 201', self::successFaults($with($spec, '/api/v1/items/{id}', 'get', '200', 'The item you created.'), $own));
        self::assertContains('POST /api/v1/auth/token 200 calls a body with no id the record as saved', self::successFaults($with($spec, '/api/v1/auth/token', 'post', '200', 'The token, as saved.'), $own));
        self::assertContains('POST /api/v1/items 201 does not mention `Location`', self::successFaults($with($spec, '/api/v1/items', 'post', '201', 'The item this request created.'), $own));
        self::assertContains('POST /api/v1/items 201 does not declare a Location header', self::successFaults($with($spec, '/api/v1/items', 'post', '201', null, true), $own));
        self::assertContains('GET /api/v1/roles 200 says the list is paged, but the operation declares no `after`', self::successFaults($with($spec, '/api/v1/roles', 'get', '200', str_replace('{words}', 'role', self::SUCCESS_TEMPLATES['paged list'])), $own));
        self::assertContains('GET /api/v1/items 200 says the list is not paged, but the operation declares `after`', self::successFaults($with($spec, '/api/v1/items', 'get', '200', str_replace('{words}', 'item', self::SUCCESS_TEMPLATES['bounded list'])), $own));
        self::assertContains('DELETE /api/v1/items/{id} 204 does not say it has no body', self::successFaults($with($spec, '/api/v1/items/{id}', 'delete', '204', 'Done.'), $own));
        self::assertContains('GET /api/v1/items/{id} 200 carries a review id', self::successFaults($with($spec, '/api/v1/items/{id}', 'get', '200', 'The item (G-12).'), $own));
        self::assertContains('GET /api/v1/items/{id} 200 is not the read template', self::successFaults($with($spec, '/api/v1/items/{id}', 'get', '200', 'The Item.'), $own));

        self::assertSame([], self::successFaults($spec, $own), 'A success answer does not say what comes back.');

        $published = [];
        foreach (GeneratedSpec::operations($spec) as $entry) {
            foreach (self::OWN_SUCCESS_WORDING[$entry['operation']['operationId']] ?? [] as $status => $text) {
                $published[$entry['operation']['operationId']][$status] = $entry['operation']['responses'][$status]['description'] ?? null;
            }
        }
        ksort($published);
        self::assertSame(self::OWN_SUCCESS_WORDING, $published, 'A hand-written success description is not the wording it was given.');
    }

    /**
     * *fails if:* the API description's note on refusals for how a request was sent is not, word for word, the text
     * given for it (one paragraph of the published `info.description`).
     */
    #[Test]
    public function theNoteOnHowARequestWasSentIsWordForWord(): void
    {
        $paragraphs = explode("\n\n", (string) ($this->spec()['info']['description'] ?? ''));
        self::assertContains(self::TRANSPORT_NOTE, $paragraphs, 'The note on refusals for how a request was sent is not the wording it was given.');
    }

    /**
     * *fails if:* a keyed operation called without an `Idempotency-Key` does not answer 428, or the spec does not list it.
     *
     * A fixture-free probe, the shape of `ErrorContractTest::probe()`: the path's ids are fresh uuids; a body-reading
     * write gets `{}`, a derived one the smallest `resource` its gate passes (the gate reads it at priority 24, before the
     * key guard at 0, so an empty body there is 422); the caller holds every code. The order 401 → 403 (derived: 422 on
     * `resource`) → 428 is `PermissionGate::PRIORITY` (24) above `IdempotencyKeyGuard` (0): no row is read before the
     * guard, so no fixture is needed. One request per case: a refused write closes the shared entity manager.
     */
    #[Test]
    #[DataProvider('keyedOperations')]
    public function everyKeyedOperationRefusesAMissingKeyAsDeclared(string $name): void
    {
        $operation = TransactionBoundaryTest::contractOperations()[$name];
        [$method, $path, $body] = $this->keylessProbe($operation);

        $this->actAs(self::TENANT_A, self::everyDeclaredPermission());
        $this->send($method, $path, $body);

        self::assertSame(428, $this->httpStatus(), $name.' without a key answered '.$this->httpStatus().': '.substr($this->responseContent(), 0, 300));
        self::assertSame(ProblemType::IdempotencyKeyRequired->uri(), $this->json()['type'] ?? null, $name);
        $this->assertSpecDeclaresRefusal($name, 428);
    }

    /**
     * The probe's domain is the registry's keyed set — the same set §4.2's `Idem` column demands a header for (28 at
     * `8765844`; T-1 asserts the equality where the document is present) — and the probe would see the header: one keyed
     * operation sent WITH a key is not answered 428.
     */
    #[Test]
    public function theKeylessProbeCoversEveryKeyedOperationAndSeesTheHeader(): void
    {
        $names = self::keyedNames();
        self::assertNotSame([], $names, 'No operation demands a key, so the probe drives nothing.');
        // That the set is §4.2's `Idem` set is asserted by T-1, which runs only where the document is present.

        [$method, $path, $body] = $this->keylessProbe(TransactionBoundaryTest::contractOperations()['CreateItem']);
        $this->actAs(self::TENANT_A, self::everyDeclaredPermission());
        $this->send($method, $path, $body, [IdempotencyKeyGuard::HEADER => 'r18h-probe-'.Uuid::v4()->toRfc4122()]);
        self::assertNotSame(428, $this->httpStatus(), 'The probe sent with a key still answered 428, so it cannot see the header.');
    }

    /**
     * The helper the drivers call fires: a planted spec without `ApprovePurchaseOrder`'s 404 is named.
     */
    #[Test]
    public function theAnswerableHelperNamesAnUndeclaredStatus(): void
    {
        $spec = $this->spec();
        self::assertNull(self::undeclaredRefusal($spec, 'ApprovePurchaseOrder', 404));

        foreach ($spec['paths'] as $path => $item) {
            foreach ($item as $method => $operation) {
                if (\is_array($operation) && 'ApprovePurchaseOrder' === ($operation['operationId'] ?? null)) {
                    unset($spec['paths'][$path][$method]['responses']['404']);
                }
            }
        }
        self::assertSame('ApprovePurchaseOrder answered 404, which its published responses do not declare.', self::undeclaredRefusal($spec, 'ApprovePurchaseOrder', 404));
    }

    /** @return iterable<string, array{string}> */
    public static function keyedOperations(): iterable
    {
        self::bootKernel();
        $names = self::keyedNames();
        self::ensureKernelShutdown();

        foreach ($names as $name) {
            yield $name => [$name];
        }
    }

    /**
     * The operations carrying the idempotency flag, byte-sorted. Needs a booted kernel.
     *
     * @return list<string>
     */
    private static function keyedNames(): array
    {
        $names = [];
        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            if (true === ($operation->getExtraProperties()[IdempotencyKeyGuard::OPERATION_FLAG] ?? null)) {
                $names[] = (string) $name;
            }
        }
        sort($names);

        return $names;
    }

    /**
     * @param array<string, array{path: string, method: string, permission: array{kind: string, codes: list<string>}, errors: string}> $rows
     * @param list<string>                                                                                                            $built
     *
     * @return array<string, list<int>>
     */
    private static function expectedRefusalStatuses(array $rows, array $built): array
    {
        $catalogue = array_keys(ContractDocument::problemCatalogue());
        [$always, $keyed] = ErrorContractRules::universalSentence($catalogue);
        $tokenGated = ErrorContractRules::tokenGatedClause($catalogue);
        $transport = array_map(static fn (ProblemType $type): string => $type->value, self::TRANSPORT);
        preg_match_all('/`([A-Z][A-Za-z]+)`/', self::MARKER_WRITES, $exceptions);
        $writesExcept = $exceptions[1];
        self::assertNotSame([], $writesExcept, 'The writes marker names no exception, so the 409 exception is not under test.');

        $expected = [];
        foreach ($built as $name) {
            self::assertArrayHasKey($name, $rows, $name.' is built and has no §4.2 row.');
            $row = $rows[$name];
            $types = ErrorContractRules::universalFor($name, $row['path'], $always, $keyed, $tokenGated);
            $types = array_filter($types, static fn (string $type): bool => !\in_array($type, $transport, true)
                && ('not-found' !== $type || str_contains($row['path'], '{'))
                && ('permission-denied' !== $type || PermissionRequirement::NONE !== $row['permission']['kind']));
            $types = [...$types, ...ErrorContractRules::cellTypes($row['errors'])];

            $statuses = array_map(static fn (string $type): int => ProblemType::from($type)->status(), $types);
            if ('GET' !== $row['method'] && !\in_array($name, $writesExcept, true)) {
                $statuses[] = 409;
            }
            $statuses = array_values(array_unique($statuses));
            sort($statuses);
            $expected[$name] = $statuses;
        }

        return $expected;
    }

    /**
     * @param array<string, mixed> $spec
     *
     * @return array<string, list<int>> operationId => its declared non-2xx statuses
     */
    private static function declaredRefusalStatuses(array $spec): array
    {
        $declared = [];
        foreach (GeneratedSpec::operations($spec) as $entry) {
            $statuses = [];
            foreach (array_keys($entry['operation']['responses'] ?? []) as $status) {
                if (!str_starts_with((string) $status, '2')) {
                    $statuses[] = 'default' === $status ? 0 : (int) $status;
                }
            }
            sort($statuses);
            $declared[(string) $entry['operation']['operationId']] = $statuses;
        }

        return $declared;
    }

    /**
     * @param array<string, list<int>> $expected
     * @param array<string, list<int>> $declared
     *
     * @return list<string>
     */
    private static function faults(array $expected, array $declared): array
    {
        $faults = [];
        foreach ($expected as $name => $statuses) {
            foreach (array_diff($declared[$name] ?? [], $statuses) as $extra) {
                $faults[] = $name.' declares '.$extra.', which the contract does not give it';
            }
            foreach (array_diff($statuses, $declared[$name] ?? []) as $missing) {
                $faults[] = $name.' can answer '.$missing.' per the contract but does not declare it';
            }
        }

        return $faults;
    }

    /**
     * @param array<string, mixed> $spec
     * @param list<string>         $members
     *
     * @return list<string>
     */
    private static function shapeFaults(array $spec, array $members): array
    {
        $faults = [];
        $ref = ['$ref' => '#/components/schemas/'.ProblemResponses::SCHEMA];
        foreach (GeneratedSpec::operations($spec) as $key => $entry) {
            foreach ($entry['operation']['responses'] ?? [] as $status => $response) {
                if (str_starts_with((string) $status, '2')) {
                    continue;
                }
                if ('400' === (string) $status || 'default' === (string) $status) {
                    $faults[] = $key.' declares '.$status;
                }
                if (($response['content'] ?? null) !== [ProblemResponses::MEDIA_TYPE => ['schema' => $ref]]) {
                    $faults[] = $key.' '.$status.': not one '.ProblemResponses::MEDIA_TYPE.' entry referencing Problem';
                }
                if ('401' === (string) $status && !isset($response['headers']['WWW-Authenticate']['schema'])) {
                    $faults[] = $key.': its 401 does not declare WWW-Authenticate';
                }
            }
        }

        $schemas = $spec['components']['schemas'] ?? [];
        $problem = $schemas[ProblemResponses::SCHEMA] ?? [];
        $properties = array_keys($problem['properties'] ?? []);
        sort($properties);
        if ($properties !== $members) {
            $faults[] = 'Problem\'s members are not the ones ApiProblem serializes';
        }
        if (($problem['required'] ?? null) !== ['type', 'title', 'status'] || false !== ($problem['additionalProperties'] ?? null)) {
            $faults[] = 'Problem\'s required members are not type, title and status, or it admits others';
        }
        if (($problem['properties']['type']['enum'] ?? null) !== array_map(static fn (ProblemType $type): string => $type->uri(), ProblemType::cases())) {
            $faults[] = 'Problem\'s type enum is not the catalogue';
        }
        if (($problem['properties']['errors']['items'] ?? null) !== ['$ref' => '#/components/schemas/'.ProblemResponses::FIELD_ERROR_SCHEMA]) {
            $faults[] = 'Problem\'s errors[] is not a list of ProblemFieldError';
        }
        $field = $schemas[ProblemResponses::FIELD_ERROR_SCHEMA] ?? [];
        if (($field['required'] ?? null) !== ['pointer', 'code', 'message'] || array_keys($field['properties'] ?? []) !== ['pointer', 'code', 'message']) {
            $faults[] = 'ProblemFieldError is not {pointer, code, message}, all required';
        }
        foreach (['Error', 'ConstraintViolation'] as $vendor) {
            if (isset($schemas[$vendor])) {
                $faults[] = 'The vendor schema '.$vendor.' is still published';
            }
        }

        return $faults;
    }

    /**
     * @param array<string, mixed>                $spec
     * @param array<string, array<string, string>> $own  operationId => status => the description its attribute gives
     *
     * @return list<string>
     */
    private static function successFaults(array $spec, array $own): array
    {
        $faults = [];
        foreach (GeneratedSpec::operations($spec) as $key => $entry) {
            $name = (string) ($entry['operation']['operationId'] ?? '');
            foreach ($entry['operation']['responses'] ?? [] as $status => $response) {
                $status = (string) $status;
                if (!str_starts_with($status, '2')) {
                    continue;
                }
                $label = $key.' '.$status;
                $description = (string) ($response['description'] ?? '');
                $component = self::successComponent($spec, $response);

                if (1 === preg_match(self::VENDOR_SUCCESS, $description)) {
                    $faults[] = $label.' keeps the vendor description';
                }
                $kind = self::successKind($spec, $entry, $status);
                if (isset($own[$name][$status])) {
                    if ($own[$name][$status] !== $description) {
                        $faults[] = $label.' is not the description its own attribute gives';
                    }
                } elseif (null === $kind) {
                    $faults[] = $label.' takes no template and gives no description of its own';
                } elseif (str_replace('{words}', self::words((string) $component), self::SUCCESS_TEMPLATES[$kind]) !== $description) {
                    $faults[] = $label.' is not the '.$kind.' template';
                }

                if ('201' !== $status && str_contains(strtolower($description), 'created')) {
                    $faults[] = $label.' says "created" but is not a 201';
                }
                if ('201' === $status) {
                    if (!str_contains($description, '`Location`')) {
                        $faults[] = $label.' does not mention `Location`';
                    }
                    $header = $response['headers']['Location'] ?? [];
                    if ('string' !== ($header['schema']['type'] ?? null) || '' === (string) ($header['description'] ?? '')) {
                        $faults[] = $label.' does not declare a Location header';
                    }
                }
                if (\in_array($status, ['202', '204'], true) && !str_contains(strtolower($description), 'no body')) {
                    $faults[] = $label.' does not say it has no body';
                }
                if ('post' === $entry['method'] && '200' === $status && !self::componentHas($spec, $component, 'id')
                    && (str_contains($description, 'as saved') || str_contains($description, 'as it is after'))) {
                    $faults[] = $label.' calls a body with no id the record as saved';
                }
                $pages = str_contains($description, 'next page');
                $notPaged = str_contains($description, 'not paged');
                $after = self::declaresParameter($entry['operation'], 'after');
                if ($pages && !$after) {
                    $faults[] = $label.' says the list is paged, but the operation declares no `after`';
                }
                if ($notPaged && $after) {
                    $faults[] = $label.' says the list is not paged, but the operation declares `after`';
                }
                $withoutPending = str_replace(self::SUCCESS_ID_PENDING[$name] ?? "\0", '', $description);
                if (1 === preg_match('/\b(?:E|G|BK)-\d|\bRED\b|\bO[R]CH\b|docs\//', $withoutPending)) {
                    $faults[] = $label.' carries a review id';
                }
            }
        }

        return $faults;
    }

    /**
     * The template a success entry's facts select, or null when none is true of it (a `POST` 200 whose body has no
     * `id`, a 202, a body that is not one component).
     *
     * @param array<string, mixed>                                                       $spec
     * @param array{method: string, path: string, operation: array<string, mixed>} $entry
     */
    private static function successKind(array $spec, array $entry, string $status): ?string
    {
        $component = self::successComponent($spec, $entry['operation']['responses'][$status] ?? []);

        return match (true) {
            'get' === $entry['method'] && '200' === $status && self::componentHas($spec, $component, 'next_cursor') => self::declaresParameter($entry['operation'], 'after') ? 'paged list' : 'bounded list',
            'get' === $entry['method'] && '200' === $status && null !== $component => 'read',
            'patch' === $entry['method'] && '200' === $status && null !== $component => 'edit',
            'post' === $entry['method'] && '200' === $status && self::componentHas($spec, $component, 'id') => 'action',
            'post' === $entry['method'] && '201' === $status && null !== $component => 'create',
            'delete' === $entry['method'] && '204' === $status => 'delete',
            default => null,
        };
    }

    /** @param array<string, mixed> $response */
    private static function successComponent(array $spec, array $response): ?string
    {
        $ref = $response['content']['application/json']['schema']['$ref'] ?? null;

        return \is_string($ref) && str_starts_with($ref, '#/components/schemas/') ? substr($ref, \strlen('#/components/schemas/')) : null;
    }

    /** @param array<string, mixed> $spec */
    private static function componentHas(array $spec, ?string $component, string $member): bool
    {
        return null !== $component && \array_key_exists($member, $spec['components']['schemas'][$component]['properties'] ?? []);
    }

    /** @param array<string, mixed> $operation */
    private static function declaresParameter(array $operation, string $name): bool
    {
        foreach ($operation['parameters'] ?? [] as $parameter) {
            if ($name === ($parameter['name'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /** `SalesOrder` → "sales order", `PickQueueEntryCollection` → "pick queue entry". */
    private static function words(string $component): string
    {
        $noun = str_ends_with($component, 'Collection') ? substr($component, 0, -\strlen('Collection')) : $component;

        return strtolower(trim((string) preg_replace('/(?<!^)([A-Z])/', ' $1', $noun)));
    }

    /**
     * The 2xx descriptions operations give on their own `openapi` attribute, read from the metadata (never from the
     * spec, which is what is under test).
     *
     * @return array<string, array<string, string>> operationId => status => description
     */
    private static function ownSuccessDescriptions(): array
    {
        $own = [];
        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            $openapi = $operation->getOpenapi();
            foreach (($openapi instanceof OpenApiOperation ? $openapi->getResponses() : null) ?? [] as $status => $response) {
                if (str_starts_with((string) $status, '2') && $response instanceof OpenApiResponse && null !== $response->getDescription()) {
                    $own[(string) $name][(string) $status] = $response->getDescription();
                }
            }
        }

        return $own;
    }

    /**
     * `ApiProblem`'s serialized member names in the `jsonproblem` group, from the serializer's own metadata and name
     * converter — never listed here — less `instance`, which is never set and so never sent.
     *
     * @return array<string, string> PHP property name => serialized name
     */
    private function serializedProblemMembers(): array
    {
        $metadata = self::getContainer()->get('serializer.mapping.class_metadata_factory');
        \assert($metadata instanceof ClassMetadataFactoryInterface);
        $converter = self::getContainer()->get('api_platform.name_converter');
        \assert($converter instanceof NameConverterInterface);

        $members = [];
        foreach ($metadata->getMetadataFor(ApiProblem::class)->getAttributesMetadata() as $name => $attribute) {
            if (\in_array(ProblemResponses::GROUP, $attribute->getGroups(), true) && !$attribute->isIgnored()) {
                self::assertNull($attribute->getSerializedPath(), 'ApiProblem serializes '.$name.' under a nested path; the flat schema cannot describe it.');
                $members[(string) $name] = $attribute->getSerializedName() ?? $converter->normalize($name, ApiProblem::class, ProblemResponses::GROUP, ['groups' => [ProblemResponses::GROUP]]);
            }
        }
        unset($members['instance']);

        return $members;
    }

    /** @return array{0: string, 1: string, 2: array<string, mixed>|null} */
    private function keylessProbe(HttpOperation $operation): array
    {
        $method = (string) $operation->getMethod();
        $path = (string) preg_replace_callback('/\{[^}]+\}/', static fn (): string => Uuid::v4()->toRfc4122(), (string) $operation->getUriTemplate());
        $body = \in_array($method, ['POST', 'PATCH'], true) ? [] : null;

        $requirement = PermissionRequirement::of($operation);
        if (PermissionRequirement::DERIVED === $requirement->kind()) {
            $requirements = self::getContainer()->get(PermissionRequirements::class);
            \assert($requirements instanceof PermissionRequirements);
            $body = ['resource' => (string) array_key_first($requirements->codesByResource($requirement))];
        }

        return [$method, $path, $body];
    }

    /** @return array<string, mixed> */
    private function spec(): array
    {
        return $this->publishedSpec();
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Http\Problem\FrameworkProblemTranslator;
use App\Tests\Support\GeneratedSpec;
use App\Tests\Support\InputMemberTypedAsAClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\PropertyInfo\PropertyInfoExtractorInterface;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\CollectionType;
use Symfony\Component\TypeInfo\Type\CompositeTypeInterface;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\Type\WrappingTypeInterface;
use Symfony\Component\Uid\Uuid;

/**
 * G-102: a request body that is not a JSON object is refused before it is read, so it cannot be looked up as a link to
 * another record ({@see \App\Platform\Http\NonObjectBodyRefusal}).
 *
 * Until 2026-11-17 API Platform resolved a top-level JSON string body as an IRI and ran the linked route's provider without
 * that route's permission check: a caller holding only `inventory_item.create`/`.edit` got 400 for an existing item or user
 * id in its own workspace and 404 for a missing one, and an anonymous sign-in with a link body answered 500
 * (`docs/session13/g101-design.md` "H-1 measurement"). Every non-object body is now 422 `not_an_object` at `""`.
 *
 * Every body in this class that is not a JSON object is sent RAW ({@see ApiTestCase::sendRawBody()}), never through
 * `send()`: the rig's `send()` turns an empty PHP array into `{}` (final RED H-1), so a list cannot reach the wire
 * through it by accident or on purpose.
 *
 * The three pre-authentication limiter tables (`auth_attempt`, `auth_ip_budget`, `auth_lockout`) are not tenant-scoped,
 * so the base rig's truncate does not reach them: each fresh rig empties them and the class empties them again before a
 * test that touches them returns. A refused body is charged to no limiter, because the refusal comes before the
 * processors that charge (G-101 RED L-4).
 */
final class NonObjectBodyRefusalTest extends PurchaseOrderTestCase
{
    use OperationSuccessRecipes;

    private const SENTENCE = 'The request body must be a JSON object.';

    private const REFUSAL = [['pointer' => '', 'code' => 'not_an_object', 'message' => self::SENTENCE]];

    /** The seven non-object bodies, raw. */
    private const BODIES = ['"x"', '"/api/v1/me"', '5', 'true', 'null', '[1]', '[]'];

    private const AUTH_OPERATIONS = ['IssueToken', 'CreatePasswordReset', 'CreatePasswordResetRedemption'];

    private const PROBE_PASSWORD = 'a-probe-password-long-enough-for-e23';

    /** @var array{method: string, template: string}|null the operation whose requests are counted */
    private ?array $counting = null;

    /** @var array{method: string, template: string, nth: int}|null the operation whose N-th request is probed */
    private ?array $probing = null;

    private int $matches = 0;

    /** @var list<array{body: string, status: int, content: string}> the seven refusals the probe observed */
    private array $observed = [];

    /** @var array{0: string, 1: string}|null the limiter fingerprints before and after the seven bodies */
    private ?array $limiterAround = null;

    /**
     * The rig's `send()` turns an empty PHP array into `{}`. `json_encode([])` is the JSON list `[]`, and about sixty call
     * sites (three success recipes among them) send `[]` meaning "no fields". Reverting the rig change turns this test red,
     * and with G-102's refusal in place it also turns those recipes red.
     */
    #[Test]
    public function theRigSendsAnEmptyArrayAsAnEmptyObject(): void
    {
        $this->send('POST', '/items', ['sku' => 'RIG-1', 'name' => 'Widget', 'unit_of_measure_code' => 'EA'], [IdempotencyKeyGuard::HEADER => 'rig-empty-array']);
        self::assertSame(201, $this->httpStatus());
        $id = $this->json()['id'];

        $this->send('PATCH', '/items/'.$id, []);

        self::assertSame('{}', $this->client()->getRequest()->getContent());
        self::assertSame(200, $this->httpStatus());
    }

    /**
     * T1-1. Every operation whose served spec has a `requestBody` refuses the seven non-object bodies identically, at the
     * request its success recipe sends to it, and then still succeeds with the original body (the positive control: the
     * URL was reachable and the refusals wrote nothing that blocked the success).
     *
     * The domain is the served spec's `requestBody` operations, held two-way against the registry's body-reading writes
     * (`canDeserialize()` resolved as `MainController` resolves it: `null` on POST/PUT/PATCH means true) and against the
     * operations the success recipes can drive. That also proves every served request body is an object: an operation
     * whose body is a list would be refused here.
     */
    #[Test]
    public function everyBodyReadingOperationRefusesANonObjectBody(): void
    {
        try {
            $this->probeEveryBodyReadingOperation();
        } finally {
            $this->owner()->executeStatement('TRUNCATE auth_attempt, auth_ip_budget, auth_lockout');
        }
    }

    private function probeEveryBodyReadingOperation(): void
    {
        $routes = self::bodyRoutes();

        $registry = [];
        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            $method = strtoupper((string) $operation->getMethod());
            $deserializes = $operation->canDeserialize() ?? \in_array($method, ['POST', 'PUT', 'PATCH'], true);
            if ($deserializes && \in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
                $registry[] = $name;
            }
        }
        sort($registry);
        $served = array_keys($routes);
        sort($served);
        self::assertSame($registry, $served, 'The served requestBody operations are the registry\'s body-reading writes, two-way.');

        $recipes = array_keys($this->successRecipes());
        $undriven = array_values(array_diff($served, $recipes));
        self::assertSame([], $undriven, 'A body-reading operation has no success recipe, so its refusal is never probed.');

        $failures = [];
        $probed = [];
        foreach ($routes as $operation => $route) {
            // Count the matching requests, unplanted.
            $this->freshRig();
            $this->counting = ['method' => $route['method'], 'template' => $route['template']];
            $this->matches = 0;
            $this->drive($operation);
            $this->counting = null;
            $nth = $this->matches;
            if ($nth < 1) {
                $failures[] = \sprintf('%s: its recipe sent no %s %s.', $operation, $route['method'], $route['template']);
                continue;
            }

            // Probe the last of them.
            $this->freshRig();
            $this->probing = ['method' => $route['method'], 'template' => $route['template'], 'nth' => $nth];
            $this->matches = 0;
            $this->observed = [];
            $this->limiterAround = null;
            [$expected, $label] = $this->drive($operation);
            $this->probing = null;

            if (\count(self::BODIES) !== \count($this->observed)) {
                $failures[] = \sprintf('%s: %d refusals observed, not %d.', $operation, \count($this->observed), \count(self::BODIES));
                continue;
            }
            foreach ($this->observed as $seen) {
                $decoded = json_decode($seen['content'], true);
                if (422 !== $seen['status']
                    || !\is_array($decoded)
                    || 'urn:erp:problem:validation-failed' !== ($decoded['type'] ?? null)
                    || self::REFUSAL !== ($decoded['errors'] ?? null)
                ) {
                    $failures[] = \sprintf('%s: the body %s answered %d %s', $operation, $seen['body'], $seen['status'], substr($seen['content'], 0, 300));
                }
            }
            if ($this->httpStatus() !== $expected) {
                $failures[] = \sprintf('%s: after the refusals the original (%s) answered %d, not %d: %s', $operation, $label, $this->httpStatus(), $expected, substr($this->responseContent(), 0, 300));
            }
            if (\in_array($operation, self::AUTH_OPERATIONS, true)) {
                if (null === $this->limiterAround || $this->limiterAround[0] !== $this->limiterAround[1]) {
                    $failures[] = $operation.': the seven refused bodies changed the sign-in limiter tables (or were not measured).';
                }
            }
            $probed[] = $operation;
        }

        self::assertSame([], $failures, "A body-reading operation did not refuse a non-object body as G-102 requires:\n".implode("\n", $failures));

        // Anti-vacuity: the probed set is the served set, and it holds each shape the refusal must reach.
        sort($probed);
        self::assertSame($served, $probed, 'The probed set is the served requestBody set, two-way.');
        $methods = array_count_values(array_map(static fn (string $op): string => $routes[$op]['method'], $probed));
        self::assertGreaterThanOrEqual(1, $methods['POST'] ?? 0, 'Anti-vacuity: no POST was probed.');
        self::assertGreaterThanOrEqual(1, $methods['PATCH'] ?? 0, 'Anti-vacuity: no PATCH was probed.');
        self::assertNotSame([], array_filter($probed, static fn (string $op): bool => str_contains(substr($routes[$op]['template'], 1), '{…}/')), 'Anti-vacuity: no nested path was probed.');
        self::assertSame(self::AUTH_OPERATIONS, array_values(array_intersect(self::AUTH_OPERATIONS, $probed)), 'Anti-vacuity: the three /auth/* writes were not all probed.');

        // The limiter counter's own positive control: a well-formed wrong sign-in changes the tables.
        $this->freshRig();
        $before = $this->limiterFingerprint();
        $this->sendRawBody('POST', '/auth/token', '{"workspace":"tenant-a","email":"nobody@example.test","password":"a-wrong-password-long-enough"}');
        self::assertSame(401, $this->httpStatus(), 'The counter\'s control: a well-formed wrong sign-in is 401.');
        self::assertNotSame($before, $this->limiterFingerprint(), 'The counter\'s control: a well-formed wrong sign-in must change the limiter tables, or the 0 → 0 above proves nothing.');
    }

    /**
     * T1-2. A link body answers the same whether the record it names exists: a caller who holds only
     * `inventory_item.create` and `.edit` sends seven link strings to a create and to an edit of its own item, and all
     * fourteen answers are byte-identical and equal to T1-1's refusal. Then, signed out, the three `/auth/*` writes with a
     * link body answer the same 422, never 500.
     */
    #[Test]
    public function aLinkBodyAnswersTheSameWhetherTheRecordExists(): void
    {
        try {
            $own = $this->seedItem(self::TENANT_A, 'LINK-OWN');
            $edited = $this->seedItem(self::TENANT_A, 'LINK-EDITED');
            $foreign = $this->seedItem(self::TENANT_B, 'LINK-FOREIGN');
            $user = $this->seedPrincipal(self::TENANT_A, []);
            $this->actAs(self::TENANT_A, ['inventory_item.create', 'inventory_item.edit']);

            $links = [
                'an existing item' => '/api/v1/items/'.$own,
                'another tenant\'s item' => '/api/v1/items/'.$foreign,
                'a random item' => '/api/v1/items/'.Uuid::v4()->toRfc4122(),
                'the item collection' => '/api/v1/items',
                'an existing user' => '/api/v1/users/'.$user,
                'a random user' => '/api/v1/users/'.Uuid::v4()->toRfc4122(),
                'me' => '/api/v1/me',
            ];

            $answers = [];
            foreach ($links as $label => $link) {
                $raw = json_encode($link, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
                $this->sendRawBody('POST', '/items', $raw, [IdempotencyKeyGuard::HEADER => 'link-'.md5($label)]);
                $answers['POST '.$label] = $this->answer();
                $this->sendRawBody('PATCH', '/items/'.$edited, $raw);
                $answers['PATCH '.$label] = $this->answer();
            }

            $first = reset($answers);
            foreach ($answers as $label => $answer) {
                self::assertSame($first, $answer, $label.' answered differently from the others: a link body tells existing from missing records.');
            }
            self::assertSame(422, $first['status']);
            self::assertSame('urn:erp:problem:validation-failed', json_decode($first['content'], true)['type'] ?? null);
            self::assertSame(self::REFUSAL, json_decode($first['content'], true)['errors'] ?? null);

            // Signed out, on the three pre-authentication writes: never 500.
            $this->actAsNobody();
            $this->owner()->executeStatement('TRUNCATE auth_attempt, auth_ip_budget, auth_lockout');
            $paths = ['/auth/token', '/auth/password-resets', '/auth/password-resets/redemption'];
            foreach ($paths as $path) {
                foreach (['/api/v1/me', '/api/v1/items/'.$own, '/api/v1/items/'.Uuid::v4()->toRfc4122()] as $link) {
                    $this->sendRawBody('POST', $path, json_encode($link, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
                    self::assertSame($first, $this->answer(), 'Signed out, '.$path.' with the link '.$link.' answered differently: '.$this->responseContent());
                }
            }
        } finally {
            $this->owner()->executeStatement('TRUNCATE auth_attempt, auth_ip_budget, auth_lockout');
        }
    }

    /**
     * T1-3. The order: the row an operation's provider loads, and the media type, are checked before the body.
     *
     * The two 415s are different refusals and both are recorded: a `PATCH` with no `Content-Type` at all reaches
     * `DeserializeProvider`'s own missing-header 415 (`KernelBrowser` adds no type for `PATCH`), which comes after the row
     * load and after where this refusal stands — so this refusal must not fire without a JSON input format. A `POST` with
     * no `Content-Type` server key is sent by `KernelBrowser` as `application/x-www-form-urlencoded`, which content
     * negotiation refuses before any provider runs; it is recorded so nobody reads it as the missing-header case.
     */
    #[Test]
    public function theRowAndTheMediaTypeAreCheckedBeforeTheBody(): void
    {
        $own = $this->seedItem(self::TENANT_A, 'ORDER-OWN');

        // A missing row the provider loads: 404 before the body.
        $this->sendRawBody('PATCH', '/items/'.Uuid::v4()->toRfc4122(), '"x"');
        self::assertSame(404, $this->httpStatus(), $this->responseContent());

        // A present, non-JSON Content-Type: content negotiation's 415.
        $this->sendRawBody('POST', '/items', '"x"', [IdempotencyKeyGuard::HEADER => 'order-text-plain'], contentType: 'text/plain');
        self::assertSame(415, $this->httpStatus(), $this->responseContent());

        // No Content-Type on a PATCH to an existing row: the deserializer's missing-header 415, not this refusal.
        $this->sendRawBody('PATCH', '/items/'.$own, '"x"', contentType: null);
        self::assertSame(415, $this->httpStatus(), $this->responseContent());
        self::assertSame('urn:erp:problem:unsupported-media-type', $this->json()['type'] ?? null);
        self::assertSame('The "Content-Type" header must exist.', $this->json()['detail'] ?? null);

        // No Content-Type server key on a POST: KernelBrowser sends a form type, refused by content negotiation.
        $this->sendRawBody('POST', '/items', '"x"', [IdempotencyKeyGuard::HEADER => 'order-no-type'], contentType: null);
        self::assertSame(415, $this->httpStatus(), $this->responseContent());

        // Zero bytes: the deserializer's answer, unchanged (G-102 C1-deviations RED L-3).
        $this->sendRawBody('POST', '/items', '', [IdempotencyKeyGuard::HEADER => 'order-zero-bytes']);
        self::assertSame(422, $this->httpStatus(), $this->responseContent());
        self::assertSame([['pointer' => '', 'code' => 'malformed_body', 'message' => FrameworkProblemTranslator::MALFORMED_BODY_MESSAGE]], $this->json()['errors'] ?? null);
        $this->sendRawBody('PATCH', '/items/'.$own, '');
        self::assertSame(422, $this->httpStatus(), $this->responseContent());
        self::assertSame([['pointer' => '', 'code' => 'malformed_body', 'message' => FrameworkProblemTranslator::MALFORMED_BODY_MESSAGE]], $this->json()['errors'] ?? null);

        // Malformed JSON: the deserializer's malformed_body, as before.
        $this->sendRawBody('POST', '/items', '{', [IdempotencyKeyGuard::HEADER => 'order-malformed']);
        self::assertSame(422, $this->httpStatus(), $this->responseContent());
        self::assertSame([['pointer' => '', 'code' => 'malformed_body', 'message' => FrameworkProblemTranslator::MALFORMED_BODY_MESSAGE]], $this->json()['errors'] ?? null);

        // The stated residue (final RED L-1): a list whose decoding fails on a NUL-led object key is answered as before,
        // `undeclared_field` at `/0`. It names no record: only a top-level string is ever looked up.
        $this->sendRawBody('POST', '/items', '[{"\u0000":1}]', [IdempotencyKeyGuard::HEADER => 'order-nul-key']);
        self::assertSame(422, $this->httpStatus(), $this->responseContent());
        self::assertSame('/0', $this->json()['errors'][0]['pointer'] ?? null);
        self::assertSame('undeclared_field', $this->json()['errors'][0]['code'] ?? null);
    }

    /**
     * T1-4. No input member is typed as a class, so no field can make the deserializer resolve a link inside a body (the
     * nested half of D-H1b's mechanism). Types are resolved through the serializer's own property-info service, so a
     * docblock extractor installed later is seen too. Domain: every class an operation declares as `input:`, from the
     * resource metadata factory.
     */
    #[Test]
    public function noInputMemberIsTypedAsAClass(): void
    {
        $extractor = self::getContainer()->get('property_info');
        \assert($extractor instanceof PropertyInfoExtractorInterface);

        $classes = [];
        foreach (TransactionBoundaryTest::contractOperations() as $operation) {
            $input = $operation->getInput();
            $class = \is_array($input) ? ($input['class'] ?? null) : $input;
            if (\is_string($class) && class_exists($class)) {
                $classes[$class] = true;
            }
        }
        $classes = array_keys($classes);
        sort($classes);
        self::assertGreaterThanOrEqual(10, \count($classes), 'Anti-vacuity: the metadata factory named almost no input class.');

        $offenders = [];
        $members = 0;
        foreach ($classes as $class) {
            foreach (self::objectTypedMembers($extractor, $class, $members) as $member) {
                $offenders[] = $member;
            }
        }
        self::assertGreaterThan(\count($classes), $members, 'Anti-vacuity: almost no member was resolved.');
        self::assertSame([], $offenders, 'An input member is typed as a class, so a link string in it would be resolved as another record without that record\'s permission check (D-H1b\'s mechanism, G-102): '.implode(', ', $offenders));

        // Positive control: the same helper reports a member typed as a class.
        $none = 0;
        self::assertSame([InputMemberTypedAsAClass::class.'::$item'], self::objectTypedMembers($extractor, InputMemberTypedAsAClass::class, $none));
    }

    /**
     * T1-5. The other route into the same lookup is a body `id` key: `ItemNormalizer::updateObjectToPopulate` resolves it as
     * a link whenever the operation allows update. No body-reading write may. API Platform's default is to allow it on
     * `PUT`/`PATCH` and not on `POST` (`SerializerContextBuilder`), and an operation's own `denormalizationContext`
     * overrides the default either way, so the value is read as the serializer will resolve it, on every body-reading
     * write (G-102 C1-deviations RED M-1: the domain was `PUT`/`PATCH` only, so a `POST` declaring `true` went unseen).
     */
    #[Test]
    public function noWriteAllowsABodyIdToBeLookedUp(): void
    {
        $domain = [];
        $offenders = [];
        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            $method = strtoupper((string) $operation->getMethod());
            if (!\in_array($method, ['POST', 'PUT', 'PATCH'], true) || false === ($operation->canDeserialize() ?? true)) {
                continue;
            }
            $domain[] = $name;
            $allowsUpdate = ($operation->getDenormalizationContext() ?? [])['api_allow_update'] ?? \in_array($method, ['PUT', 'PATCH'], true);
            if (false !== $allowsUpdate) {
                $offenders[] = \sprintf('%s lets a body `id` be looked up as a link to another record without that record\'s permission check (G-102, the `id` door): set `api_allow_update => false`.', $name);
            }
        }
        self::assertNotSame([], $domain, 'Anti-vacuity: no body-reading write was found.');
        self::assertContains('UpdateItem', $domain, 'Anti-vacuity: UpdateItem is not in the domain.');
        self::assertContains('CreateItem', $domain, 'Anti-vacuity: CreateItem is not in the domain.');
        self::assertSame([], $offenders, implode("\n", $offenders));

        // HTTP control on UpdateItem.
        $own = $this->seedItem(self::TENANT_A, 'ID-DOOR');
        $this->sendRawBody('PATCH', '/items/'.$own, '{"id":"/api/v1/me"}');
        self::assertSame(422, $this->httpStatus(), $this->responseContent());
        self::assertSame('/id', $this->json()['errors'][0]['pointer'] ?? null);
        self::assertSame('undeclared_field', $this->json()['errors'][0]['code'] ?? null);
    }

    /**
     * Every member of a class (public property or constructor parameter) whose type, any union member or any collection
     * value or key type, is an object type.
     *
     * @return list<string>
     */
    private static function objectTypedMembers(PropertyInfoExtractorInterface $extractor, string $class, int &$resolved): array
    {
        $reflection = new \ReflectionClass($class);
        $names = [];
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if (!$property->isStatic()) {
                $names[$property->getName()] = true;
            }
        }
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $names[$parameter->getName()] = true;
        }

        $offenders = [];
        foreach (array_keys($names) as $name) {
            $type = $extractor->getType($class, $name);
            if (null === $type) {
                continue;
            }
            ++$resolved;
            if (self::holdsAnObject($type)) {
                $offenders[] = $class.'::$'.$name;
            }
        }

        return $offenders;
    }

    private static function holdsAnObject(Type $type): bool
    {
        if ($type instanceof ObjectType) {
            return true;
        }
        if ($type instanceof CollectionType && (self::holdsAnObject($type->getCollectionValueType()) || self::holdsAnObject($type->getCollectionKeyType()))) {
            return true;
        }
        if ($type instanceof CompositeTypeInterface) {
            foreach ($type->getTypes() as $member) {
                if (self::holdsAnObject($member)) {
                    return true;
                }
            }
        }
        if ($type instanceof WrappingTypeInterface) {
            return self::holdsAnObject($type->getWrappedType());
        }

        return false;
    }

    /**
     * Runs one operation's success recipe, fixture first, and answers the status and label it expects.
     *
     * @return array{0: int, 1: string}
     */
    private function drive(string $operation): array
    {
        if ('IssueToken' === $operation) {
            // The shared recipe presents the password ChangePassword's recipe set, so it cannot succeed alone (the same
            // local recipe as RequestBodySchemaProbeTest's).
            $this->setPassword(self::TENANT_A, $this->actorId, self::PROBE_PASSWORD);
            $email = (string) ($this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT email FROM app_user WHERE id = ?::uuid', [$this->actorId])[0]['email'] ?? '');
            $this->send('POST', '/auth/token', ['workspace' => 'tenant-a', 'email' => $email, 'password' => self::PROBE_PASSWORD]);

            return [200, 'sign in with the password this recipe set'];
        }

        [$prepare, $drive] = $this->successRecipes()[$operation];

        return $drive($prepare());
    }

    /**
     * Counts the requests matching the operation under test, and before its N-th one sends the seven non-object bodies
     * raw to the same URL with the same headers, recording each answer.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers
     */
    protected function send(string $method, string $path, ?array $body = null, array $headers = [], string $accept = 'application/json'): void
    {
        $target = $this->probing ?? $this->counting;
        if (null !== $target && $method === $target['method'] && self::normalise($path) === $target['template']) {
            ++$this->matches;
            if (null !== $this->probing && $this->matches === $this->probing['nth']) {
                $before = $this->limiterFingerprint();
                foreach (self::BODIES as $raw) {
                    $this->sendRawBody($method, $path, $raw, $headers, $accept);
                    $this->observed[] = ['body' => $raw, 'status' => $this->httpStatus(), 'content' => $this->responseContent()];
                }
                $this->limiterAround = [$before, $this->limiterFingerprint()];
            }
        }

        parent::send($method, $path, $body, $headers, $accept);
    }

    /** @return array{status: int, type: string, content: string} */
    private function answer(): array
    {
        return [
            'status' => $this->httpStatus(),
            'type' => $this->contentType(),
            'content' => $this->responseContent(),
        ];
    }

    /** The three pre-authentication limiter tables' whole contents, as one fingerprint (an update moves it as an insert does). */
    private function limiterFingerprint(): string
    {
        $parts = [];
        foreach (['auth_attempt', 'auth_ip_budget', 'auth_lockout'] as $table) {
            $parts[] = (string) $this->owner()->fetchOne(\sprintf("SELECT coalesce(string_agg(t::text, '|' ORDER BY t::text), '') FROM %s t", $table));
        }

        return md5(implode('#', $parts));
    }

    private function freshRig(): void
    {
        $this->tearDown();
        $this->setUp();
        $this->owner()->executeStatement('TRUNCATE auth_attempt, auth_ip_budget, auth_lockout');
    }

    private static function normalise(string $path): string
    {
        $path = (string) preg_replace('/\?.*$/', '', $path);
        if (str_starts_with($path, self::BASE)) {
            $path = substr($path, \strlen(self::BASE));
        }

        return (string) preg_replace('#/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(?=/|$)#i', '/{…}', $path);
    }

    /**
     * Each served operation with a request body: its method and normalised path template.
     *
     * @return array<string, array{method: string, template: string}>
     */
    private static function bodyRoutes(): array
    {
        $spec = GeneratedSpec::regenerate(self::$kernel ?? self::bootKernel());
        $routes = [];
        foreach ($spec['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (!\is_array($operation) || !isset($operation['operationId'], $operation['requestBody'])) {
                    continue;
                }
                $template = (string) preg_replace('/\{[^}]+\}/', '{…}', str_starts_with($path, self::BASE) ? substr($path, \strlen(self::BASE)) : $path);
                $routes[(string) $operation['operationId']] = ['method' => strtoupper($method), 'template' => $template];
            }
        }
        ksort($routes);

        return $routes;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Http\Problem\FrameworkProblemTranslator;
use App\Platform\Security\Identity;
use App\Platform\Security\PermissionGate;
use App\Platform\Security\PermissionRequirements;
use App\SharedKernel\ValueObject\AuditEvent;
use App\Tests\Support\PlantedDerivedPermission;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\Encoder\DecoderInterface;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * **G5-D0 — the DERIVED arm, decided by the REAL gate** (`g5-g6-final-red.md` H-1, M-1, L-4; design §5.1a, Revision 1
 * FINAL, M-3).
 *
 * No shipped operation is derived until G5-C2 (`CreateBulkArchival`), so this plants one: a `POST /planted` declaring
 * {@see PermissionGate::DERIVED} and a {@see PlantedDerivedPermission} resolver in a hand-made locator. Everything that
 * decides is the shipped code -- the gate, `PermissionRequirements`, the denial writer, the identity seam.
 *
 * What it holds:
 *  - one code per request, resolved from the body's `resource`, and one decision on it (E-1, E-14);
 *  - the gate reads no id and no row before refusing (`oneDecisionBeforeAnyIdIsRead`);
 *  - an unreadable body or a missing / non-string / unknown `resource` is a 422 AHEAD of the 403 and writes no denial row
 *    (precedence 401 → 422 → 403 → 428 → body 422);
 *  - the resolved pair is the request attribute, read the way the deserializer reads the body (duplicate keys, BOM), and
 *    the processor's side refuses a different resource with a 500 (M-3).
 */
final class DerivedPermissionTest extends ApiTestCase
{
    use PlantedOperationGate;

    private const MAP = [
        'inventory_item' => 'inventory_item.delete',
        'customer' => 'customer.delete',
        'credit_note' => 'invoice.view',
    ];

    /** The planted derived declaration, shared with `DenialSubjectTest::aDerivedDenialNamesTheResolvedCode` (so that file reads no declaration token). */
    public const DERIVED = [
        PermissionGate::OPERATION_PERMISSION => PermissionGate::DERIVED,
        PermissionGate::DERIVED_PERMISSION => 'app.planted_derived_permission',
    ];

    #[Test]
    public function aKnownResourceResolvesOneCodeAndTheGateTakesOneDecisionOnIt(): void
    {
        $requirements = PlantedDerivedPermission::requirements(new PlantedDerivedPermission(self::MAP));
        $everyCode = array_values(array_unique(self::MAP));

        foreach (self::MAP as $resource => $code) {
            // Holding nothing: refused naming exactly the resolved code (never the sentinel).
            $this->holding([]);
            $request = self::plantedRequest((string) json_encode(['resource' => $resource, 'ids' => []]));
            $problem = $this->plantedDecision(self::DERIVED, $request, $requirements);
            self::assertSame(403, $problem?->getStatus(), $resource.': a caller holding nothing was not refused.');
            self::assertSame($code, $problem->getRequiredPermission(), $resource.': the 403 does not name the resolved code.');
            self::assertSame(['resource' => $resource, 'code' => $code], $request->attributes->get(PermissionRequirements::REQUEST_ATTRIBUTE));

            // Holding every OTHER code the map can demand: still refused, naming this one (a loose match passes the two
            // ends and fails here).
            $others = array_values(array_diff($everyCode, [$code]));
            $this->holding($others);
            self::assertSame($code, $this->plantedDecision(self::DERIVED,
                self::plantedRequest((string) json_encode(['resource' => $resource])), $requirements)?->getRequiredPermission(),
                $resource.': holding every other derivable code satisfied this one.');

            // Holding exactly this code: through.
            $this->holding([$code]);
            $request = self::plantedRequest((string) json_encode(['resource' => $resource]));
            self::assertNull($this->plantedDecision(self::DERIVED, $request, $requirements), $resource.': its own code did not satisfy it.');
            self::assertSame(['resource' => $resource, 'code' => $code], $request->attributes->get(PermissionRequirements::REQUEST_ATTRIBUTE));
        }
    }

    /**
     * E-1 / E-14: the decision is taken from the `resource` field alone. A statement log over the whole decision shows no
     * read of the resource's table, and an `ids` member the gate would choke on if it read it does not change the answer.
     * The recorder is the default `SqlRecorder`, wired as Doctrine middleware to EVERY connection (`config/services.yaml`),
     * so a read on `erp_app` or the owner connection is seen alike.
     *
     * *Mutations run (2026-10-22, `g5-d0-red.md` L-1):* a real `SELECT … FROM inventory_item` planted on the decision path
     * (in `DenialAuditWriter::record()`, which the decision reaches) → red at the statement-log assertion; and a per-id loop
     * over `ids` planted in `PermissionRequirements::resolve()` → red, but only through `failOnWarning` (foreach over an
     * int), which is weaker and is stated as such.
     */
    #[Test]
    public function oneDecisionBeforeAnyIdIsRead(): void
    {
        $requirements = PlantedDerivedPermission::requirements(new PlantedDerivedPermission(self::MAP));
        $this->holding([]);

        $recorder = $this->recorder();
        $recorder->start();
        $problem = $this->plantedDecision(self::DERIVED,
            self::plantedRequest('{"resource":"inventory_item","ids":7}'), $requirements);
        $recorder->stop();

        self::assertSame(403, $problem?->getStatus(), 'An ids member the gate must never read changed its answer.');
        self::assertSame('inventory_item.delete', $problem->getRequiredPermission());
        self::assertNotSame([], $recorder->statements(), 'Control: the recorder captured nothing (the denial row is written), so "no read" proves nothing.');
        foreach ($recorder->statements() as $sql) {
            self::assertDoesNotMatchRegularExpression('/\binventory_item\b/i', $sql, 'The gate read the resource\'s table before refusing: '.$sql);
        }
        self::assertSame(1, $this->denialRowsOf($this->actorIdOrFail()), 'One decision, one denial row.');
    }

    #[Test]
    public function anUnknownResourceIs422NotA403(): void
    {
        $requirements = PlantedDerivedPermission::requirements(new PlantedDerivedPermission(self::MAP));
        $cases = [
            'unknown resource' => ['{"resource":"role","ids":[]}', '/resource', 'unknown_resource', PlantedDerivedPermission::UNKNOWN],
            'resource missing' => ['{"ids":[]}', '/resource', 'required', null],
            'resource null' => ['{"resource":null}', '/resource', 'not_a_string', null],
            'resource a number' => ['{"resource":7}', '/resource', 'not_a_string', null],
            'resource a list' => ['{"resource":["customer"]}', '/resource', 'not_a_string', null],
            'resource case-different' => ['{"resource":"Customer"}', '/resource', 'unknown_resource', PlantedDerivedPermission::UNKNOWN],
            'top level a list' => ['["customer"]', '', 'not_an_object', null],
            'empty list' => ['[]', '', 'not_an_object', null],
            'empty body' => ['', '', 'malformed_body', FrameworkProblemTranslator::MALFORMED_BODY_MESSAGE],
            'not JSON' => ['{"resource":', '', 'malformed_body', FrameworkProblemTranslator::MALFORMED_BODY_MESSAGE],
            'NUL-led property name' => ['{"\\u0000resource":"customer"}', '', 'malformed_body', FrameworkProblemTranslator::MALFORMED_BODY_MESSAGE],
            'BOM-led body' => ["\xEF\xBB\xBF".'{"resource":"customer"}', '', 'malformed_body', FrameworkProblemTranslator::MALFORMED_BODY_MESSAGE],
        ];

        foreach ($cases as $label => [$body, $pointer, $code, $message]) {
            $this->holding([]);
            $request = self::plantedRequest($body);
            $problem = $this->plantedDecision(self::DERIVED, $request, $requirements);

            self::assertInstanceOf(ApiProblem::class, $problem, $label.': let through.');
            self::assertSame(422, $problem->getStatus(), $label.': answered '.$problem->getStatus().', not 422 -- a 403 here would tell a caller without the permission nothing it should not know, but the order is 422 first.');
            $errors = $problem->getErrors() ?? [];
            self::assertCount(1, $errors, $label);
            self::assertSame([$pointer, $code], [$errors[0]['pointer'], $errors[0]['code']], $label);
            if (null !== $message) {
                self::assertSame($message, $errors[0]['message'], $label);
            }
            self::assertNull($request->attributes->get(PermissionRequirements::REQUEST_ATTRIBUTE), $label.': a refused body left a resolved permission behind.');
            self::assertSame(0, $this->denialRowsOf($this->actorIdOrFail()), $label.': a 422 wrote a denial row (no decision was taken).');
        }
    }

    /**
     * M-3's two hostile bodies: the gate must read `resource` exactly as the deserializer will. A duplicate key resolves to
     * its LAST value in both; a BOM is unreadable to both.
     */
    #[Test]
    public function theGateReadsTheBodyAsTheDeserializerDoes(): void
    {
        $requirements = PlantedDerivedPermission::requirements(new PlantedDerivedPermission(self::MAP));
        $duplicate = '{"resource":"inventory_item","resource":"credit_note"}';

        $decoder = self::getContainer()->get(SerializerInterface::class);
        \assert($decoder instanceof DecoderInterface);
        $deserialized = $decoder->decode($duplicate, 'json');
        self::assertIsArray($deserialized);

        $this->holding([]);
        $request = self::plantedRequest($duplicate);
        $problem = $this->plantedDecision(self::DERIVED, $request, $requirements);
        self::assertSame($deserialized['resource'], $request->attributes->get(PermissionRequirements::REQUEST_ATTRIBUTE)['resource'] ?? null,
            'The gate and the deserializer read a duplicate `resource` key differently.');
        self::assertSame(self::MAP[$deserialized['resource']], $problem?->getRequiredPermission());

        $this->expectException(NotEncodableValueException::class);
        $decoder->decode("\xEF\xBB\xBF".'{"resource":"customer"}', 'json');
    }

    /**
     * `g5-d0-red.md` L-2: the gate decodes the body whatever its `Content-Type`. Content negotiation (415) runs inside the
     * controller's provider chain, AFTER this `kernel.controller` listener, so for a derived operation 415 sits after the
     * permission decision: 401 → 422 → 403 → 415 / 428 → body 422. Pinned here: a valid `resource` sent as `text/plain` is
     * decided (403), not refused 415 by the gate.
     */
    #[Test]
    public function theContentTypeIsNegotiatedAfterTheDecision(): void
    {
        $requirements = PlantedDerivedPermission::requirements(new PlantedDerivedPermission(self::MAP));
        $this->holding([]);
        $request = Request::create('/planted', 'POST', server: ['CONTENT_TYPE' => 'text/plain'], content: '{"resource":"customer"}');

        self::assertSame(['status' => 403, 'code' => 'customer.delete'], [
            'status' => ($problem = $this->plantedDecision(self::DERIVED, $request, $requirements))?->getStatus(),
            'code' => $problem?->getRequiredPermission(),
        ]);
    }

    /** M-3, the processor's side: it acts only on the resolved resource; absent or different is a 500, never an action. */
    #[Test]
    public function theProcessorMayActOnlyOnTheResolvedResource(): void
    {
        $request = new Request();
        $request->attributes->set(PermissionRequirements::REQUEST_ATTRIBUTE, ['resource' => 'customer', 'code' => 'customer.delete']);
        self::assertSame('customer.delete', PermissionRequirements::resolvedFor($request, 'customer'));

        foreach (['a different resource' => [$request, 'inventory_item'], 'nothing resolved' => [new Request(), 'customer']] as $label => [$r, $resource]) {
            try {
                PermissionRequirements::resolvedFor($r, $resource);
                self::fail($label.' was acted on.');
            } catch (\LogicException $e) {
                self::assertNotSame('', $e->getMessage(), $label);
            }
        }
    }

    /**
     * @param list<string> $held
     */
    private function holding(array $held): void
    {
        $this->actAs(self::TENANT_A, $held);
        $this->identities()->set(new Identity(self::TENANT_A, $this->actorIdOrFail(), 'Fixture Actor', $held));
    }

    private function actorIdOrFail(): string
    {
        return $this->actorId ?? throw new \LogicException('No actor.');
    }

    private function denialRowsOf(string $actor): int
    {
        return \count($this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT 1 FROM audit_log WHERE actor_user_id = ?::uuid AND event_type = ?',
            [$actor, AuditEvent::AUTHZ_PERMISSION_DENIED]));
    }
}

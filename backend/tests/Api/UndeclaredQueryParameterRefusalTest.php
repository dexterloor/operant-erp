<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Platform\Http\UndeclaredQueryParameterRefusal;
use App\Tests\Support\CountingKdf;
use PHPUnit\Framework\Attributes\Test;

/**
 * The decisions `Platform\Http\UndeclaredQueryParameterRefusal` takes, each pinned
 * (step F2, 2026-09-15). The registry-wide half — every operation refuses, every
 * declared parameter is accepted — is
 * `QueryParameterContractTest::everyDeclaredParameterIsAcceptedAndNoOtherIs`.
 *
 * Nothing here reads a gitignored document.
 */
final class UndeclaredQueryParameterRefusalTest extends PurchaseOrderTestCase
{
    private const PASSWORD = 'a-password-long-enough-for-e23';

    private const MISSING = '00000000-0000-4000-8000-00000000abcd';

    /**
     * **The shape is the list providers' own, byte for byte**: one entry per
     * undeclared name in query-string order, `?<name>` pointer,
     * `undeclared_parameter`, the same message — on a list and on a non-list alike.
     */
    #[Test]
    public function theRefusalIsTheListShapeOnEveryKindOfOperation(): void
    {
        $item = $this->seedItem(self::TENANT_A, 'SKU-1');

        $expected = '{"errors":[{"pointer":"?c5","code":"undeclared_parameter","message":"This operation declares no query parameter named \u0022c5\u0022."},'
            .'{"pointer":"?c6","code":"undeclared_parameter","message":"This operation declares no query parameter named \u0022c6\u0022."}],'
            .'"type":"urn:erp:problem:validation-failed","title":"Validation failed","status":422}';

        foreach (['/items?c5=1&c6=2', '/items/'.$item.'?c5=1&c6=2', '/me?c5=1&c6=2'] as $path) {
            $this->send('GET', $path);
            self::assertSame(422, $this->httpStatus(), $path);
            self::assertStringStartsWith('application/problem+json', $this->contentType(), $path);
            self::assertSame($expected, (string) $this->client()->getResponse()->getContent(), $path);
        }

        self::assertSame('undeclared_parameter', UndeclaredQueryParameterRefusal::CODE);
    }

    /**
     * **Order: after the gates and the idempotency key, before the row load and
     * the body.** 401, 403 and 428 still win; a missing row and an undeclared body
     * field both lose to the query string, as they did on the lists.
     */
    #[Test]
    public function theRefusalFallsAfterTheGatesAndBeforeTheRowAndTheBody(): void
    {
        $this->send('GET', '/items/'.self::MISSING.'?c5=1');
        self::assertSame('?c5', $this->json()['errors'][0]['pointer'] ?? null, 'A missing row answered before the query string was checked.');

        $this->send('PATCH', '/items/'.self::MISSING.'?c5=1', ['bogus' => 1]);
        self::assertSame(['?c5'], array_column($this->json()['errors'] ?? [], 'pointer'), 'The row load or the body answered before the query string.');

        $this->send('POST', '/items?c5=1', ['sku' => 'N', 'name' => 'N', 'unit_of_measure_code' => 'EA', 'bogus' => 1], ['Idempotency-Key' => '00000000-0000-4000-8000-0000000000aa']);
        self::assertSame(['?c5'], array_column($this->json()['errors'] ?? [], 'pointer'), 'The body was refused before the query string.');

        $this->send('POST', '/items?c5=1', ['sku' => 'N', 'name' => 'N', 'unit_of_measure_code' => 'EA']);
        self::assertSame(428, $this->httpStatus(), 'The idempotency-key gate no longer precedes the query string.');

        $this->actAs(self::TENANT_A, []);
        $this->send('GET', '/items/'.self::MISSING.'?c5=1');
        self::assertSame(403, $this->httpStatus(), 'The permission gate no longer precedes the query string.');

        $this->actAsNobody();
        $this->send('GET', '/items/'.self::MISSING.'?c5=1');
        self::assertSame(401, $this->httpStatus(), 'Authentication no longer precedes the query string.');
    }

    /**
     * **`IssueToken` is not exempt**, and a refused sign-in is not an attempt: the
     * hasher is not invoked and no trail row is written. Control: the same request
     * without the parameter signs in and invokes the hasher.
     */
    #[Test]
    public function aSignInCarryingAnUndeclaredParameterIsRefusedBeforeAnyCredentialWork(): void
    {
        $hasher = self::getContainer()->get(CountingKdf::class);
        \assert($hasher instanceof CountingKdf);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET password_hash = ? WHERE id = ?::uuid', [$hasher->hash(self::PASSWORD), $this->actorId]);
        $email = (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT email FROM app_user WHERE id = ?::uuid', [$this->actorId])[0]['email'];
        $body = ['workspace' => 'tenant-a', 'email' => $email, 'password' => self::PASSWORD];

        $trail = (int) $this->owner()->fetchOne('SELECT count(*) FROM audit_log');
        $hasher->reset();
        $this->send('POST', '/auth/token?c5=1', $body);

        self::assertSame(422, $this->httpStatus());
        self::assertSame([['pointer' => '?c5', 'code' => 'undeclared_parameter']], array_map(
            static fn (array $e): array => ['pointer' => $e['pointer'], 'code' => $e['code']], $this->json()['errors'] ?? []));
        self::assertSame(0, $hasher->invocations(), 'The hasher ran for a sign-in refused for its URL.');
        self::assertSame($trail, (int) $this->owner()->fetchOne('SELECT count(*) FROM audit_log'), 'A trail row was written for a sign-in refused for its URL.');

        $this->send('POST', '/auth/token', $body);
        self::assertSame(200, $this->httpStatus(), 'Control: the same sign-in without the parameter did not succeed.');
        self::assertGreaterThan(0, $hasher->invocations(), 'Control: the hasher is not counted on this path.');
    }

    /** **`ChangePassword` is not exempt**, and a refused change changes nothing. */
    #[Test]
    public function aPasswordChangeCarryingAnUndeclaredParameterChangesNothing(): void
    {
        $hasher = self::getContainer()->get(CountingKdf::class);
        \assert($hasher instanceof CountingKdf);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET password_hash = ? WHERE id = ?::uuid', [$hasher->hash(self::PASSWORD), $this->actorId]);
        $before = $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT password_hash FROM app_user WHERE id = ?::uuid', [$this->actorId])[0]['password_hash'];

        $this->send('POST', '/me/password?c5=1', ['current_password' => self::PASSWORD, 'new_password' => self::PASSWORD.'-changed']);

        self::assertSame(422, $this->httpStatus());
        self::assertSame('?c5', $this->json()['errors'][0]['pointer'] ?? null);
        self::assertSame($before, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT password_hash FROM app_user WHERE id = ?::uuid', [$this->actorId])[0]['password_hash'],
            'The password changed on a request refused for its URL.');
    }

    /**
     * **A repeated or array parameter is its name; an empty value is still the
     * parameter; a name differing only in case is a different name.** And a
     * declared name sent as an array is not this refusal's — it is the vendor's
     * value validation of a declared parameter.
     */
    #[Test]
    public function arraysEmptyValuesAndCaseAreDecidedByTheParsedName(): void
    {
        $item = $this->seedItem(self::TENANT_A, 'SKU-1');

        $cases = [
            '/items/'.$item.'?c5[]=1&c5[]=2' => ['?c5'],
            '/items/'.$item.'?c5[x]=1' => ['?c5'],
            '/items/'.$item.'?c5=' => ['?c5'],
            '/items/'.$item.'?c5' => ['?c5'],
            '/items?Limit=1' => ['?Limit'],
            '/items?LIMIT=1&limit=1' => ['?LIMIT'],
        ];
        foreach ($cases as $path => $pointers) {
            $this->send('GET', $path);
            self::assertSame(422, $this->httpStatus(), $path);
            self::assertSame($pointers, array_column($this->json()['errors'] ?? [], 'pointer'), $path);
            self::assertSame(['undeclared_parameter'], array_values(array_unique(array_column($this->json()['errors'], 'code'))), $path);
        }

        $this->send('GET', '/items?limit[]=1');
        self::assertSame(422, $this->httpStatus());
        self::assertNotContains('undeclared_parameter', array_column($this->json()['errors'] ?? [], 'code'), 'A declared name sent as an array was called undeclared.');

        $this->send('GET', '/items?limit=1');
        self::assertSame(200, $this->httpStatus(), 'Control: the declared lower-case name is accepted.');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Http\Problem\FrameworkProblemTranslator;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * G-101: a value of the wrong JSON type is refused at its field with a 422, not answered with a bare 400 "The input data
 * is misformatted.". Field shapes only — a whole body that is not an object is G-102's (`NonObjectBodyRefusalTest`).
 * `RequestBodySchemaProbeTest` probe 3 covers every request field; this class covers the shapes it does not: several
 * wrong values on one field, a missing constructor field, the sign-in operations, a wrong field next to an undeclared one,
 * and that no response names a PHP class, a PHP type, a PHP member name or the submitted value.
 *
 * The `/auth/*` refusals are charged to no sign-in limiter: they happen in the deserializer, before the processors that
 * charge (G-101 RED L-4), so the three limiter tables stay empty.
 */
final class WrongJsonTypeTest extends PurchaseOrderTestCase
{
    /** Strings that must never reach the wire: PHP names, PHP type syntax, the vendor's wording. */
    private const LEAKS = ['App\\', 'Input', 'misformatted', 'attribute', '?string', 'array<', 'currentPassword', 'newPassword', 'reorderPoint', 'unitOfMeasureCode', 'customerId', 'warehouseId'];

    private const AUTH_TABLES = ['auth_attempt', 'auth_ip_budget', 'auth_lockout'];

    /** @var list<string> every response body this class received */
    private array $bodies = [];

    protected function tearDown(): void
    {
        $this->owner()->executeStatement('TRUNCATE auth_attempt, auth_ip_budget, auth_lockout');
        parent::tearDown();
    }

    #[Test]
    public function aStringFieldGivenAnotherTypeIsNotAString(): void
    {
        foreach (['true', '[]', '{}', '5'] as $value) {
            $this->post('/items', '{"sku":'.$value.',"name":"Widget","unit_of_measure_code":"EA"}', true);
            $this->assertRefused([['/sku', 'not_a_string', 'This field must be a string.']], 'sku = '.$value);
        }
        self::assertSame(0, (int) $this->owner()->fetchOne('SELECT count(*) FROM inventory_item'), 'A refused create wrote an item.');
        $this->assertNoLeak();
    }

    /** `lines` of the wrong JSON type answers the sentence the writer answers for `lines: {"a":1}`: one field, one sentence. */
    #[Test]
    public function linesOfTheWrongTypeIsNotAListInTheWritersWords(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Acme Wrong Type');
        $address = $this->seedCustomerAddress(self::TENANT_A, $customer);
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH Wrong Type');
        $head = '"customer_id":"'.$customer.'","warehouse_id":"'.$warehouse.'","currency_code":"EUR","ship_to_address_id":"'.$address.'"';

        $answers = [];
        foreach (['5', 'true', '{"a":1}'] as $value) {
            $this->post('/sales-orders', '{'.$head.',"lines":'.$value.'}', true);
            $answers[$value] = $this->assertRefused([['/lines', 'not_a_list', FrameworkProblemTranslator::NOT_A_LIST_OF_LINES]], 'lines = '.$value);
        }
        self::assertSame('This field must be a list of lines.', $answers['5'][0]['message']);
        self::assertSame($answers['{"a":1}'], $answers['5'], 'The translator and the writer answer `lines` in different words.');
        self::assertSame($answers['{"a":1}'], $answers['true']);
        $this->assertNoLeak();
    }

    /** Final RED L-6: the sentence has one source — the constant — and the six line writers refer to it. */
    #[Test]
    public function theLinesSentenceHasOneSource(): void
    {
        $src = \dirname(__DIR__, 2).'/src';
        $occurrences = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $count = substr_count((string) file_get_contents($file->getPathname()), 'This field must be a list of lines.');
            if ($count > 0) {
                $occurrences[substr($file->getPathname(), \strlen($src) + 1)] = $count;
            }
        }
        self::assertSame(['Platform/Http/Problem/FrameworkProblemTranslator.php' => 1], $occurrences, 'The lines sentence is written out somewhere other than its constant.');

        foreach ([
            'Modules/Sales/Application/SalesOrderWriter.php',
            'Modules/Sales/Application/FulfilmentWriter.php',
            'Modules/Sales/Application/SalesReturnWriter.php',
            'Modules/Sales/Application/CreditNoteWriter.php',
            'Modules/Purchasing/Application/GoodsReceiptWriter.php',
            'Modules/Purchasing/Application/PurchaseOrderWriter.php',
        ] as $writer) {
            self::assertStringContainsString('FrameworkProblemTranslator::NOT_A_LIST_OF_LINES', (string) file_get_contents($src.'/'.$writer), $writer.' does not answer `lines` with the shared sentence.');
        }
    }

    #[Test]
    public function aPasswordChangeNamesTheFieldByItsWireName(): void
    {
        $this->sendRawBody('POST', '/me/password', '{"current_password":5,"new_password":"a-new-password-long-enough"}');
        $this->assertRefused([['/current_password', 'not_a_string', 'This field must be a string.']], 'current_password = 5');

        $this->sendRawBody('POST', '/me/password', '{"new_password":"a-new-password-long-enough"}');
        $this->assertRefused([['/current_password', 'required', 'This field is required.']], 'current_password absent');
        $this->assertNoLeak();
    }

    #[Test]
    public function aSignInIsRefusedAtTheFieldAndChargesNoLimiter(): void
    {
        $this->actAsNobody();
        $this->owner()->executeStatement('TRUNCATE auth_attempt, auth_ip_budget, auth_lockout');

        $this->sendRawBody('POST', '/auth/token', '{}');
        $this->assertRefused([
            ['/workspace', 'required', 'This field is required.'],
            ['/email', 'required', 'This field is required.'],
            ['/password', 'required', 'This field is required.'],
        ], '{}');

        $this->sendRawBody('POST', '/auth/token', '{"workspace":"tenant-a","email":null,"password":"a-password-long-enough"}');
        $this->assertRefused([['/email', 'required', 'This field is required.']], 'email = null');

        $this->sendRawBody('POST', '/auth/token', '{"workspace":"tenant-a","email":"someone@example.test","password":12345678}');
        $this->assertRefused([['/password', 'not_a_string', 'This field must be a string.']], 'password = 12345678');
        self::assertStringNotContainsString('12345678', $this->responseContent(), 'The refusal repeats the submitted password.');

        foreach (self::AUTH_TABLES as $table) {
            self::assertSame(0, (int) $this->owner()->fetchOne('SELECT count(*) FROM '.$table), 'A refused sign-in body was charged to '.$table.'.');
        }
        $this->assertNoLeak();
    }

    /** Only the first wrong field is named; a wrong type wins over an undeclared field, in either order. */
    #[Test]
    public function aWrongTypeNextToAnUndeclaredFieldIsTheWrongTypeAlone(): void
    {
        foreach (['{"sku":5,"zzz":1,"name":"W","unit_of_measure_code":"EA"}', '{"zzz":1,"sku":5,"name":"W","unit_of_measure_code":"EA"}'] as $body) {
            $this->post('/items', $body, true);
            $this->assertRefused([['/sku', 'not_a_string', 'This field must be a string.']], $body);
        }
        $this->assertNoLeak();
    }

    /** The control: malformed JSON is still `malformed_body`. */
    #[Test]
    public function malformedJsonIsStillMalformed(): void
    {
        $this->post('/items', '{', true);
        $this->assertRefused([['', 'malformed_body', FrameworkProblemTranslator::MALFORMED_BODY_MESSAGE]], '{');
    }

    private function post(string $path, string $raw, bool $key): void
    {
        $this->sendRawBody('POST', $path, $raw, $key ? [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()] : []);
    }

    /**
     * @param list<array{0: string, 1: string, 2: string}> $expected
     *
     * @return list<array<string, mixed>>
     */
    private function assertRefused(array $expected, string $label): array
    {
        $this->bodies[] = $this->responseContent();
        self::assertSame(422, $this->httpStatus(), $label.': '.$this->responseContent());
        $json = $this->json();
        self::assertSame('urn:erp:problem:validation-failed', $json['type'] ?? null, $label);
        $want = array_map(static fn (array $e): array => ['pointer' => $e[0], 'code' => $e[1], 'message' => $e[2]], $expected);
        self::assertSame($want, $json['errors'] ?? null, $label);

        return $json['errors'];
    }

    private function assertNoLeak(): void
    {
        self::assertNotSame([], $this->bodies, 'Anti-vacuity: no response was recorded.');
        foreach ($this->bodies as $body) {
            foreach (self::LEAKS as $leak) {
                self::assertStringNotContainsString($leak, $body, 'A refusal names something internal ('.$leak.').');
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\DemoSeed;

use ErpDemoWalkthrough\Main;
use ErpDemoWalkthrough\Plan;
use ErpDemoWalkthrough\Safety;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once \dirname(__DIR__, 2).'/bin/demo-walkthrough.php';

/**
 * **2a C3: the walkthrough script's refusals, as pure functions** (`demo-seed-2a-design.md` Revision 1 §R1-7,
 * `demo-seed-2a-roles.md` S-5). Every token-shaped value here is built at run time, so no fixture literal looks like a
 * secret to a scanner (design RED M-4).
 *
 * Mutations: M3 (the host check removed) → the loopback cases red; M4 (`follow_location` 1) → the context-options case red;
 * M5 (the output filter always passes) → the filter cases red; M5b (`DEMO_PASSWORD` in the environment accepted) → red on
 * the refusal's text.
 */
final class DemoWalkthroughSafetyTest extends TestCase
{
    #[Test]
    public function onlyTheLiteralLoopbackWithAnExplicitPortIsAccepted(): void
    {
        foreach (['http://127.0.0.1:8000', 'http://127.0.0.1:8000/', 'https://127.0.0.1:58025', 'http://[::1]:8000'] as $url) {
            self::assertNull(Safety::refusalForUrl($url, '--api'), 'Refused: '.$url);
        }
        $refused = [
            'http://localhost:8000', 'http://10.0.0.1:8000', 'http://192.168.1.20:8000', 'http://example.com:8000',
            'http://127.0.0.1.example.com:8000', 'http://0.0.0.0:8000', 'http://u:p@127.0.0.1:8000', 'ftp://127.0.0.1:21',
            'http://127.0.0.1', 'http://127.0.0.1:', 'http://127.1:8000', 'http://127.0.0.2:8000', 'http://0177.0.0.1:8000',
            'http://2130706433:8000', 'http://[::ffff:127.0.0.1]:8000', 'http://127.0.0.1.:8000', 'http://127.0.0.1:8000@example.com',
            'http://127.0.0.1:8000/x@example.com', 'http://127.0.0.1:8000/#@example.com', 'http://127.0.0.1:8000/api', 'http://127.0.0.1:8000?x=1',
            'http://127.0.0.1:0', 'http://127.0.0.1:65536', 'http://127.0.0.1:08000', ' http://127.0.0.1:8000', 'HTTP://127.0.0.1:8000', '',
        ];
        foreach ($refused as $url) {
            self::assertSame(\sprintf(Safety::REFUSED_URL, '--api'), Safety::refusalForUrl($url, '--api'), 'Accepted: '.json_encode($url));
        }
    }

    #[Test]
    public function aRedirectIsNeverFollowed(): void
    {
        $options = Safety::contextOptions('POST', ['Accept: application/json'], '{}');
        self::assertSame(0, $options['http']['follow_location']);
        self::assertTrue($options['http']['ignore_errors']);
        self::assertSame('{}', $options['http']['content']);
        self::assertArrayNotHasKey('proxy', $options['http']);
    }

    #[Test]
    public function aLineShapedLikeASecretIsWithheld(): void
    {
        $token = str_repeat('Ab3_', 21).'xy';
        $jwt = 'ey'.'J'.str_repeat('a', 10).'.'.str_repeat('b', 10).'.'.str_repeat('c', 10);
        foreach (['/reset-password#'.'token='.$token, 'Authorization: Bear'.'er '.$token, $jwt, 'x '.str_repeat('Z', 40).' y'] as $line) {
            self::assertFalse(Safety::isPrintable($line), 'Printable: a planted secret shape');
        }
        foreach (['Bea raised PO-000001, Paul approved it.', 'step 7 (ApprovePurchaseOrder, as Paul Purchasing): expected 200, got 409 urn:erp:problem:business-rule-violated purchase_order.approver_is_not_the_creator',
            'id 3f1c2a9e-1b2c-4d5e-8f90-112233445566'] as $line) {
            self::assertTrue(Safety::isPrintable($line), 'Withheld: '.$line);
        }
    }

    #[Test]
    public function thePasswordMayNotComeFromTheEnvironment(): void
    {
        self::assertSame(Safety::REFUSED_PASSWORD_IN_ENVIRONMENT, Safety::refusalForEnvironment(['DEMO_PASSWORD' => 'x', 'PATH' => '/bin']));
        self::assertNull(Safety::refusalForEnvironment(['PATH' => '/bin']));
    }

    /** The run refuses before any request: an API off this machine, then a password in the environment. */
    #[Test]
    public function theCommandRefusesBeforeAnyRequest(): void
    {
        $transport = new class implements \ErpDemoWalkthrough\Transport {
            public int $requests = 0;

            public function send(string $method, string $url, array $headers, ?string $body): array
            {
                ++$this->requests;

                return ['status' => 500, 'body' => ''];
            }
        };
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        $quiet = new \ErpDemoWalkthrough\Output($stream);
        self::assertSame(Plan::EXIT_REFUSED, Main::run(['x', 'populate', '--api=http://192.0.2.10:8000', '--mailbox=http://127.0.0.1:58025'], $transport, $quiet));
        $_SERVER['DEMO_PASSWORD'] = 'planted-'.bin2hex(random_bytes(4));
        try {
            self::assertSame(Plan::EXIT_REFUSED, Main::run(['x', 'populate', '--api=http://127.0.0.1:8000', '--mailbox=http://127.0.0.1:58025'], $transport, $quiet));
        } finally {
            unset($_SERVER['DEMO_PASSWORD']);
        }
        self::assertSame(0, $transport->requests, 'A refused run sent a request.');
        rewind($stream);
        $printed = (string) stream_get_contents($stream);
        self::assertStringContainsString(\sprintf(Safety::REFUSED_URL, '--api'), $printed);
        self::assertStringContainsString(Safety::REFUSED_PASSWORD_IN_ENVIRONMENT, $printed);
        self::assertStringNotContainsString('planted-', $printed);
    }

    /** Every operation the script calls exists in the committed spec (an unknown one is a refusal before any write). */
    #[Test]
    public function everyOperationTheScriptCallsIsInTheCommittedSpec(): void
    {
        $spec = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/contracts/openapi.json'), true);
        self::assertIsArray($spec);
        self::assertSame([], array_values(array_diff(Plan::operationsUsed(), array_keys(Plan::operationsIn($spec)))));
        self::assertNotNull(Plan::tokenIn('https://x/reset-password#'.'token='.str_repeat('a', 86)));
    }
}

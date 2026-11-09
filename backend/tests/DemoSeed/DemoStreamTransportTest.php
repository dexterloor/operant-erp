<?php

declare(strict_types=1);

namespace App\Tests\DemoSeed;

use ErpDemoWalkthrough\Plan;
use ErpDemoWalkthrough\Stop;
use ErpDemoWalkthrough\StreamTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once \dirname(__DIR__, 2).'/bin/demo-walkthrough.php';

/**
 * **2a C3: the walkthrough script's real stream transport, over a socket** (`demo-seed-2a-design.md` Revision 1 §R1-9).
 * The in-kernel tests replace the transport, so this one starts `php -S 127.0.0.1:<free port>` on a stub router
 * (`support/stub-api.php`: no database, no kernel, no dotenv) and drives the script's own `StreamTransport` class, not a
 * copy (final RED L-1). It shows: a 302 comes back as a 302 and is not followed; the `Authorization` header and the body
 * are sent; a problem body is reduced to its type and rule; a dead port is a transport stop (exit 1).
 * Mutation M4b (`follow_location` 1) → the redirect case red (the stub's target answers 200).
 */
final class DemoStreamTransportTest extends TestCase
{
    /** @var resource|null */
    private static $server;

    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        self::$port = self::freePort();
        self::$server = proc_open([\PHP_BINARY, '-S', '127.0.0.1:'.self::$port, __DIR__.'/support/stub-api.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, __DIR__, ['PATH' => (string) getenv('PATH')]);
        self::assertIsResource(self::$server);
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', self::$port, $errno, $error, 0.2);
            if (false !== $socket) {
                fclose($socket);

                return;
            }
            usleep(100_000);
        }
        self::fail('The stub server did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (\is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    #[Test]
    public function aRedirectComesBackAsARedirectAndIsNotFollowed(): void
    {
        $answer = (new StreamTransport())->send('GET', $this->url('/redirect'), ['Accept: application/json'], null);
        self::assertSame(302, $answer['status'], 'The redirect was followed.');
    }

    #[Test]
    public function theHeadersAndTheBodyAreSent(): void
    {
        $bearer = 'stub-'.bin2hex(random_bytes(8));
        $answer = (new StreamTransport())->send('POST', $this->url('/echo'), ['Accept: application/json', 'Authorization: Bearer '.$bearer, 'Content-Type: application/json'], '{"a":1}');
        self::assertSame(200, $answer['status']);
        self::assertSame(['method' => 'POST', 'authorization' => 'Bearer '.$bearer, 'content_type' => 'application/json', 'body' => '{"a":1}'], json_decode($answer['body'], true));
    }

    #[Test]
    public function aProblemIsReducedToItsTypeAndRule(): void
    {
        $answer = (new StreamTransport())->send('GET', $this->url('/problem'), ['Accept: application/json'], null);
        self::assertSame(409, $answer['status']);
        self::assertSame('urn:erp:problem:business-rule-violated demo.stub_rule', Plan::problemOf($answer['body']));
    }

    #[Test]
    public function aDeadPortIsATransportStop(): void
    {
        try {
            (new StreamTransport())->send('GET', 'http://127.0.0.1:'.self::freePort().'/x', [], null);
            self::fail('No stop.');
        } catch (Stop $stop) {
            self::assertSame(Plan::EXIT_TRANSPORT, $stop->exitCode);
        }
    }

    private function url(string $path): string
    {
        return 'http://127.0.0.1:'.self::$port.$path;
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}

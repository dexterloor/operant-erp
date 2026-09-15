<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\OwnerRequestWindow;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The positive control for the owner-service recorder (V3-1(i) item (5); V4 batch B2b-3).
 *
 * `ApiTestCase::tearDown()` fails every HTTP test in which the schema owner's connection issued a statement between the
 * main request's `kernel.request` and `kernel.terminate`. A recorder that records nothing would make that assertion green
 * for every test in the suite, so this test proves it can see: a statement planted on the owner connection inside a real
 * request is caught, and owner statements issued by fixtures outside the window are not.
 */
final class OwnerRequestWindowTest extends ApiTestCase
{
    #[Test]
    public function anOwnerStatementInsideARequestIsCaughtAndFixturesOutsideItAreNot(): void
    {
        $this->owner()->fetchOne('SELECT 11 AS outside_before');

        $dispatcher = self::getContainer()->get('event_dispatcher');
        \assert($dispatcher instanceof EventDispatcherInterface);
        $dispatcher->addListener(KernelEvents::REQUEST, function (): void {
            $this->owner()->fetchOne('SELECT 42 AS planted_inside_the_request');
        }, -1024);

        $this->send('GET', '/me');
        self::assertSame(200, $this->httpStatus(), 'the request under the plant must still be a real, served request');

        $this->owner()->fetchOne('SELECT 12 AS outside_after');

        self::assertSame(['SELECT 42 AS planted_inside_the_request'], $this->ownerRequestWindow()->takeStatementsInsideRequests());
    }

    #[Test]
    public function aRequestThatDoesNotTouchTheOwnerRecordsNothing(): void
    {
        $this->send('GET', '/me');
        self::assertSame(200, $this->httpStatus());

        self::assertSame([], $this->ownerRequestWindow()->takeStatementsInsideRequests());
    }

    private function ownerRequestWindow(): OwnerRequestWindow
    {
        $window = self::getContainer()->get(OwnerRequestWindow::class);
        \assert($window instanceof OwnerRequestWindow);

        return $window;
    }
}

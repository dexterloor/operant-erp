<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Records every statement the schema owner's connection issues while a main request is being served — between
 * `kernel.request` and `kernel.terminate` (V3-1(i) item (5); V4 batch B2b-3). Test environment only.
 *
 * `test.sql_recorder.owner` is fed by a `doctrine.middleware` scoped to the `owner` connection, so it sees what went through
 * the owner SERVICE, at the driver layer. This subscriber opens it at the first `kernel.request` listener of a main request
 * and closes it at the last `kernel.terminate` listener; statements recorded in between accumulate until taken.
 * `ApiTestCase::tearDown()` takes them and fails the test if there are any; `OwnerRequestWindowTest` is the planted
 * positive control.
 *
 * **Coverage, stated honestly:** every route to the owner through the container's owner connection service, on the paths
 * the HTTP suite exercises. It does not see a connection a class builds for itself from a credential (B2, B15), a network
 * login (B13), or a path no test requests. Fixtures that use the owner between requests are outside the window by design.
 */
final class OwnerRequestWindow implements EventSubscriberInterface
{
    /** @var list<string> */
    private array $inside = [];

    private bool $open = false;

    public function __construct(private readonly SqlRecorder $ownerRecorder)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['open', \PHP_INT_MAX],
            KernelEvents::TERMINATE => ['close', \PHP_INT_MIN],
        ];
    }

    public function open(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $this->open) {
            return;
        }
        $this->open = true;
        $this->ownerRecorder->start();
    }

    public function close(TerminateEvent $event): void
    {
        $this->closeWindow();
    }

    /**
     * Closes a window a request left open (a handler that never reached `kernel.terminate`) and returns, then forgets,
     * every owner statement recorded inside a request so far.
     *
     * @return list<string>
     */
    public function takeStatementsInsideRequests(): array
    {
        $this->closeWindow();
        $inside = $this->inside;
        $this->inside = [];

        return $inside;
    }

    private function closeWindow(): void
    {
        if (!$this->open) {
            return;
        }
        $this->open = false;
        $this->ownerRecorder->stop();
        array_push($this->inside, ...$this->ownerRecorder->statements());
    }
}

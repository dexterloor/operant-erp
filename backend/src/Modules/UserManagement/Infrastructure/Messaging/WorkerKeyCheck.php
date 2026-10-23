<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Infrastructure\Messaging;

use App\Modules\UserManagement\Domain\SealsIdentities;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

/**
 * **The mail worker refuses to start on a wrong key, a long SMTP wait or exception arguments in its failure records**
 * (Mail-C1, `mail-c1-build-plan.md` R1.4 item 5, R1.6 step 10, R1.7; G-75 T-G75-2; RED L-3, L-4).
 *
 * On `WorkerStartedEvent`, before the first message:
 *  1. **`default_socket_timeout` = 10** (L-3), read back: the SMTP send holds the worker's transaction and its identity's
 *     only pooled server connection. No Mailer DSN option sets it (measured by RED); PHP's default is 60 s.
 *  2. **`zend.exception_ignore_args` = 1** (L-4), read back: a failure inside the SMTP stream must not carry a body chunk —
 *     the link — into the `ErrorDetailsStamp` that is parked in `failed`. `make worker` passes `-d` too.
 *  3. **The current seal is built**, forcing its constructor's refusals: an `AUTH_CREDENTIAL_MAC_KEY_ID` that is not the
 *     key's id (and, in production, an absent one) stops `messenger:consume` with a non-zero exit (T-S3).
 * Any refusal throws: the worker never handles a message in a state it cannot vouch for.
 */
final readonly class WorkerKeyCheck implements EventSubscriberInterface
{
    public const SOCKET_TIMEOUT_SECONDS = '10';

    /** @param \Closure(): SealsIdentities $seal */
    public function __construct(
        #[AutowireServiceClosure(SealsIdentities::class)]
        private \Closure $seal,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [WorkerStartedEvent::class => 'onWorkerStarted'];
    }

    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        self::pin('default_socket_timeout', self::SOCKET_TIMEOUT_SECONDS);
        self::pin('zend.exception_ignore_args', '1');
        ($this->seal)();
    }

    private static function pin(string $setting, string $value): void
    {
        ini_set($setting, $value);
        if ($value !== ini_get($setting)) {
            throw new \RuntimeException(\sprintf('The mail worker could not set %s to %s, so it will not start.', $setting, $value));
        }
    }
}

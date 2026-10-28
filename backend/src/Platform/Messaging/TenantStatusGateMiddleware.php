<?php

declare(strict_types=1);

namespace App\Platform\Messaging;

use App\Platform\Tenancy\Entity\Tenant;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\HandlerArgumentsStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * **A workspace message is handled only while its workspace permits sign-in** (API.md §11.2 O-8; U-C1a,
 * `docs/session13/u-c1-build-plan.md` Revision 2 FINAL R2.1 item 1; Mail-C1 carry item 1).
 *
 * On a **received** {@see TenantMessage} only — after {@see TenantStampMiddleware} has checked that exactly one
 * {@see TenantIdStamp} is present, and before the handler runs — it reads that workspace's `status` on the worker's
 * connection and asks {@see Tenant::statusPermitsLogin()} (the method, never a literal). No row, or a status that does not
 * permit sign-in → `UnrecoverableMessageHandlingException`: the envelope goes to `failed`, is never retried, and the handler
 * never runs. A dispatch, and a pre-authentication message in either direction, pass straight through.
 *
 * **The worker connection is taken LAZILY (`u-c1-final-red.md` C-1).** The bus builds its middleware in order on every
 * dispatch, so the web process constructs this class on its first dispatch; the web kernel's worker DSN is empty by design
 * (`doctrine.yaml`), and building that connection throws (measured at MU-21: the dispatch
 * throws `Doctrine\DBAL\ConnectionException`). `config/services.yaml` therefore binds
 * `$worker` to `app.messaging.lazy_worker_connection`, a lazy proxy that is initialised only by the `SELECT` below, i.e.
 * only on a received workspace message. `WorkerTenantStatusTest::theWebKernelDispatchesWithNoWorkerDsn` (T-U7c) and
 * mutation MU-21 (bind the connection eagerly) hold this. *ARCH TO CONFIRM the means and the Platform placement.*
 */
final readonly class TenantStatusGateMiddleware implements MiddlewareInterface
{
    public const STATUS_SQL = 'SELECT status FROM tenant WHERE id = ?::uuid';

    public function __construct(private Connection $worker)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (!$envelope->getMessage() instanceof TenantMessage || null === $envelope->last(ReceivedStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }

        $stamps = $envelope->all(TenantIdStamp::class);
        if (1 !== \count($stamps)) {
            throw new UnrecoverableMessageHandlingException('A workspace message arrived without exactly one workspace stamp.');
        }
        $stamp = $stamps[0];
        \assert($stamp instanceof TenantIdStamp);

        $status = $this->worker->fetchOne(self::STATUS_SQL, [$stamp->tenantId]);
        if (!\is_string($status) || !Tenant::statusPermitsLogin($status)) {
            throw new UnrecoverableMessageHandlingException('A workspace message was parked: its workspace does not permit sign-in.');
        }

        // U-C1b (final RED M-3): the handler receives the workspace it was checked for as its second argument, from the one
        // stamp checked above — never a payload value, which a dispatcher writes. `HandlerArgumentsStamp` is non-sendable (a
        // retry or a park never writes it to the queue) and `AllowlistSerializer` denies it on decode (a forged one never
        // arrives). Mutation MU-22 (a constant tenant id here) → T-U2 red.
        return $stack->next()->handle($envelope->with(new HandlerArgumentsStamp([$stamp->tenantId])), $stack);
    }
}

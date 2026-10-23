<?php

declare(strict_types=1);

namespace App\Platform\Messaging;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * **Stamps a {@see TenantMessage} with the workspace it was dispatched in, and refuses one that has none** (Mail-C0;
 * `docs/session13/mail-c0-build-plan.md` Revision 1 FINAL §R4, RED L-6, M-5).
 *
 * At dispatch the workspace is read from the transaction's own `app.tenant_id` setting — the value RLS actually reads —
 * the way the shipped ports detect an open tenancy (`SupplierOpenPurchaseOrders::TENANT_GUC`; this is the sixth copy of
 * that constant, single-sourcing it is ARCH's call). So it also covers a context set by `TenantContext::establishOn()`
 * on the application connection, which a `TenantContext` accessor would miss. Costs one `SELECT` per `TenantMessage`
 * dispatch (none at Mail-C0; U-C1's statement-count pins must include it). It reads the APPLICATION entity manager's
 * connection only: a pre-authentication message is a {@see PreAuthenticationMessage}, dispatched with no database
 * principal at all (Mail-C1 FA-8: `CreatePasswordReset` touches only `erp_ratelimit`, before the dispatch), and carries no stamp.
 *
 *   - dispatched outside a transaction, or in one with no tenant set → refused (`\LogicException`);
 *   - dispatched in a tenancy → every `TenantIdStamp` already attached is REMOVED and the transaction's is added: a
 *     dispatcher's own stamp is never trusted;
 *   - received (`ReceivedStamp`) without exactly one well-formed stamp → `UnrecoverableMessageHandlingException`
 *     (straight to `failed`, never retried).
 *
 * **Not here yet, by design (RED M-5):** the `Tenant::permitsLogin()` gate before the handler and `WorkerTenantStatusTest`
 * land with the first `TenantMessage` (U-C1). `MessengerConfigurationTest::aTenantMessageInTheCatalogueRequiresTheTenantStatusGate`
 * goes red the moment one is catalogued without them.
 */
final readonly class TenantStampMiddleware implements MiddlewareInterface
{
    public const TENANT_GUC = "SELECT NULLIF(current_setting('app.tenant_id', true), '')";

    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (!$envelope->getMessage() instanceof TenantMessage) {
            return $stack->next()->handle($envelope, $stack);
        }

        if (null !== $envelope->last(ReceivedStamp::class)) {
            $stamps = $envelope->all(TenantIdStamp::class);
            if (1 !== \count($stamps)) {
                throw new UnrecoverableMessageHandlingException('A workspace message arrived without exactly one workspace stamp.');
            }

            return $stack->next()->handle($envelope, $stack);
        }

        // Through the entity manager, never a typed `Connection` (A-10).
        $connection = $this->em->getConnection();
        if (0 === $connection->getTransactionNestingLevel()) {
            throw new \LogicException('A workspace message may only be dispatched inside the workspace\'s transaction.');
        }
        $tenant = $connection->fetchOne(self::TENANT_GUC);
        if (!\is_string($tenant)) {
            throw new \LogicException('A workspace message was dispatched in a transaction with no workspace set.');
        }

        $envelope = $envelope->withoutAll(TenantIdStamp::class)->with(new TenantIdStamp($tenant));

        return $stack->next()->handle($envelope, $stack);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Messaging;

use App\Platform\Messaging\AllowlistSerializer;
use App\Platform\Messaging\TenantIdStamp;
use App\Platform\Messaging\TenantStampMiddleware;
use App\Platform\Tenancy\TenantContext;
use App\Tests\Messaging\Fixture\CataloguedProbe;
use App\Tests\Messaging\Fixture\ProbeId;
use App\Tests\Messaging\Fixture\TenantProbe;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Uid\Uuid;

/**
 * **`TenantStampMiddleware`, against the real application connection** (Mail-C0; plan Revision 1 FINAL §R4/§R7; RED L-6).
 * The workspace is read from `app.tenant_id` — the value RLS reads — so a context set by `TenantContext::run()` and one
 * set by `establishOn()` on the same connection are both seen. Mutation M10 (trust a pre-attached stamp) → red.
 */
final class TenantStampMiddlewareTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    /** @var list<Envelope> */
    private array $passed = [];

    protected function setUp(): void
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->passed = [];
    }

    #[Test]
    public function aWorkspaceMessageDispatchedOutsideATransactionIsRefused(): void
    {
        self::assertSame(0, $this->em->getConnection()->getTransactionNestingLevel());
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('only be dispatched inside the workspace\'s transaction');
        $this->bus()->dispatch(self::probe());
    }

    #[Test]
    public function aWorkspaceMessageDispatchedInATransactionWithNoWorkspaceIsRefused(): void
    {
        $this->em->getConnection()->beginTransaction();
        try {
            $this->bus()->dispatch(self::probe());
            self::fail('A dispatch with no workspace set was not refused.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('no workspace set', $e->getMessage());
        } finally {
            $this->em->getConnection()->rollBack();
        }
    }

    #[Test]
    public function insideTenantContextTheMessageIsStampedWithThatWorkspace(): void
    {
        $tenant = Uuid::v4()->toRfc4122();
        self::tenantContext()->run($tenant, fn (): Envelope => $this->bus()->dispatch(self::probe()));

        self::assertSame([$tenant], self::stampedTenants($this->passed));
    }

    #[Test]
    public function aContextSetByEstablishOnIsSeenToo(): void
    {
        $tenant = Uuid::v4()->toRfc4122();
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            self::tenantContext()->establishOn($connection, $tenant);
            $this->bus()->dispatch(self::probe());
        } finally {
            $connection->rollBack();
        }

        self::assertSame([$tenant], self::stampedTenants($this->passed));
    }

    #[Test]
    public function aStampTheDispatcherAttachedIsReplacedNeverTrusted(): void
    {
        $tenant = Uuid::v4()->toRfc4122();
        $foreign = Uuid::v4()->toRfc4122();
        self::tenantContext()->run($tenant, fn (): Envelope => $this->bus()->dispatch(self::probe(), [new TenantIdStamp($foreign), new TenantIdStamp($foreign)]));

        self::assertSame([$tenant], self::stampedTenants($this->passed), 'A forged stamp survived the dispatch.');
    }

    #[Test]
    public function aReceivedWorkspaceMessageWithoutExactlyOneStampIsUnrecoverable(): void
    {
        foreach ([[], [new TenantIdStamp(Uuid::v4()->toRfc4122()), new TenantIdStamp(Uuid::v4()->toRfc4122())]] as $stamps) {
            try {
                $this->bus()->dispatch(new Envelope(self::probe(), [new ReceivedStamp('async'), ...$stamps]));
                self::fail('A received workspace message with '.\count($stamps).' stamps was handled.');
            } catch (UnrecoverableMessageHandlingException) {
            }
        }
        $this->bus()->dispatch(new Envelope(self::probe(), [new ReceivedStamp('async'), new TenantIdStamp(Uuid::v4()->toRfc4122())]));
        self::assertCount(1, $this->passed);
    }

    #[Test]
    public function aPreAuthenticationMessageIsNeitherStampedNorRefused(): void
    {
        $this->bus()->dispatch(new CataloguedProbe(new ProbeId('p')));

        self::assertCount(1, $this->passed);
        self::assertSame([], $this->passed[0]->all(TenantIdStamp::class));
    }

    /** The tenant stamp survives `AllowlistSerializer` (its allowlist entry's stated reason, `MessengerConfigurationTest` #8). */
    #[Test]
    public function theTenantStampRoundTripsThroughTheAllowlistSerializer(): void
    {
        $tenant = Uuid::v4()->toRfc4122();
        $serializer = new AllowlistSerializer([TenantProbe::class], new NullLogger());
        $back = $serializer->decode($serializer->encode(new Envelope(self::probe(), [new TenantIdStamp($tenant)])));

        self::assertSame([$tenant], self::stampedTenants([$back]));
    }

    private function bus(): MessageBus
    {
        $passed = &$this->passed;

        return new MessageBus([
            new TenantStampMiddleware($this->em),
            new class($passed) implements MiddlewareInterface {
                /** @param list<Envelope> $passed */
                public function __construct(private array &$passed)
                {
                }

                public function handle(Envelope $envelope, StackInterface $stack): Envelope
                {
                    $this->passed[] = $envelope;

                    return $envelope;
                }
            },
        ]);
    }

    private static function probe(): TenantProbe
    {
        return new TenantProbe(new ProbeId('p'));
    }

    private static function tenantContext(): TenantContext
    {
        $context = self::getContainer()->get(TenantContext::class);
        self::assertInstanceOf(TenantContext::class, $context);

        return $context;
    }

    /**
     * @param list<Envelope> $envelopes
     *
     * @return list<string>
     */
    private static function stampedTenants(array $envelopes): array
    {
        $tenants = [];
        foreach ($envelopes as $envelope) {
            foreach ($envelope->all(TenantIdStamp::class) as $stamp) {
                \assert($stamp instanceof TenantIdStamp);
                $tenants[] = $stamp->tenantId;
            }
        }

        return $tenants;
    }
}

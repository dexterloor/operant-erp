<?php

declare(strict_types=1);

namespace App\Tests\Messaging;

use App\Platform\Messaging\AllowlistSerializer;
use App\Platform\Messaging\MessageCatalogue;
use App\Platform\Messaging\MessageClassAllowlistMiddleware;
use App\Platform\Messaging\MessageNotCatalogued;
use App\Platform\Messaging\PreAuthenticationMessage;
use App\Platform\Messaging\TenantIdStamp;
use App\Platform\Messaging\TenantMessage;
use App\Platform\Tenancy\Entity\Tenant;
use App\Tests\Messaging\Fixture\BareScalarProbe;
use App\Tests\Messaging\Fixture\BothMarkersProbe;
use App\Tests\Messaging\Fixture\CataloguedProbe;
use App\Tests\Messaging\Fixture\ConstructionTrap;
use App\Tests\Messaging\Fixture\NoMarkerProbe;
use App\Tests\Messaging\Fixture\ProbeId;
use App\Tests\Messaging\Fixture\TenantProbe;
use App\Tests\Messaging\Fixture\TrapStamp;
use App\Tests\Support\MessagePayloadRule;
use Composer\InstalledVersions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Symfony\Bridge\Doctrine\Messenger\DoctrineTransactionMiddleware;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\EventListener\AddErrorDetailsStampListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\AddBusNameStampMiddleware;
use Symfony\Component\Messenger\Middleware\DispatchAfterCurrentBusMiddleware;
use Symfony\Component\Messenger\Middleware\FailedMessageProcessingMiddleware;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\RejectRedeliveredMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\Serialization\SigningSerializer;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Mime\Email;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer as SymfonySerializer;
use Symfony\Component\Yaml\Yaml;

/**
 * **The mail bus, held to its rules by measurement** (Mail-C0, 2026-10-23; `docs/session13/mail-c0-build-plan.md`
 * Revision 1 FINAL §R7; `mail-c0-red.md`; API.md §11.1; ARCHITECTURE.md §6.4). Each method names the mutation that
 * turns it red in its docblock; the record of each run is `docs/session13/mail-c0-progress.md`.
 *
 * Tests over the catalogue run over a PLANTED list as well as the real one, because the real one is empty at Mail-C0:
 * a check over an empty list proves nothing (the empty-set trap). Each says when it becomes non-vacuous.
 */
final class MessengerConfigurationTest extends KernelTestCase
{
    private const MESSENGER_YAML = 'config/packages/messenger.yaml';

    /** The env files whose `MESSENGER_TRANSPORT_DSN` scheme is checked. Absent gitignored files are simply not read. */
    private const ENV_FILES = ['../.env.example', '../.env.worker.example', '../.env', '../.env.worker', '.env.dev', '../.env.test'];

    protected function setUp(): void
    {
        ConstructionTrap::$constructed = false;
        TrapStamp::$constructed = false;
    }

    /** 1. API.md §11.1 O-2's named test. Mutation M1: add `doctrine_transaction` to the bus → red. */
    #[Test]
    public function doctrineTransactionIsNotOnTheBus(): void
    {
        $middleware = self::busMiddleware();
        self::assertNotSame([], $middleware, 'The bus has no middleware at all, so this check sees nothing.');
        foreach ($middleware as $one) {
            self::assertNotInstanceOf(DoctrineTransactionMiddleware::class, $one,
                'doctrine_transaction is on the bus: a handler\'s writes would commit or roll back with the bus, not with the work they describe (O-2).');
        }
        self::assertContains(MessageClassAllowlistMiddleware::class, array_map(get_class(...), $middleware), 'The allowlist middleware is not on the bus.');

        foreach (self::configBlocks() as $where => $block) {
            foreach ($block['buses'] ?? [] as $bus => $definition) {
                foreach ((array) ($definition['middleware'] ?? []) as $entry) {
                    $id = \is_array($entry) ? (string) array_key_first($entry) : (string) $entry;
                    self::assertStringNotContainsString('doctrine_transaction', $id, "$where: bus $bus lists doctrine_transaction.");
                }
            }
        }
    }

    /**
     * 2. No transport is Doctrine in any environment (H-4): every DSN in `messenger.yaml` (base and every `when@*`) and the
     * scheme of `MESSENGER_TRANSPORT_DSN` in each env file — names and scheme only, never a value. Mutation M2: a
     * `when@dev` transport `doctrine://default` → red.
     */
    #[Test]
    public function noTransportIsDoctrineInAnyEnvironment(): void
    {
        $seen = 0;
        foreach (self::configBlocks() as $where => $block) {
            foreach ($block['transports'] ?? [] as $name => $transport) {
                $dsn = \is_array($transport) ? (string) ($transport['dsn'] ?? '') : (string) $transport;
                ++$seen;
                self::assertDoesNotMatchRegularExpression('/^doctrine:/i', $dsn, "$where: transport $name is Doctrine.");
            }
        }
        self::assertGreaterThan(0, $seen, 'No transport was read from messenger.yaml.');

        foreach (self::ENV_FILES as $file) {
            $path = self::backend().'/'.$file;
            if (!is_file($path)) {
                continue;
            }
            foreach ((array) file($path, \FILE_IGNORE_NEW_LINES) as $line) {
                if (1 === preg_match('/^\s*MESSENGER_TRANSPORT_DSN\s*=\s*["\']?([a-z0-9+.-]*):/i', (string) $line, $m)) {
                    self::assertNotSame('doctrine', strtolower($m[1]), "$file: MESSENGER_TRANSPORT_DSN has the doctrine scheme.");
                }
            }
        }
    }

    /** 3. The strongest form of E-10's refusal: without the package a `doctrine://` DSN cannot build a transport. */
    #[Test]
    public function theDoctrineTransportPackageIsNotInstalled(): void
    {
        self::assertFalse(InstalledVersions::isInstalled('symfony/doctrine-messenger'), 'symfony/doctrine-messenger is installed.');
    }

    /**
     * 4. Never PHP-native: `default_serializer` and every transport's own serializer are `AllowlistSerializer`, in config
     * (every block) and in the built container (through Symfony's `SigningSerializer` decorator, F-A). Mutations M3
     * (`failed.serializer: messenger.transport.native_php_serializer`) and M4 (delete `default_serializer`) → red.
     */
    #[Test]
    public function theSerializerIsNeverPhpNative(): void
    {
        $blocks = self::configBlocks();
        self::assertSame(AllowlistSerializer::class, $blocks['framework']['serializer']['default_serializer'] ?? null, 'default_serializer is not pinned.');
        foreach ($blocks as $where => $block) {
            foreach ($block['transports'] ?? [] as $name => $transport) {
                self::assertIsArray($transport, "$where: transport $name is a bare DSN, so it takes the default serializer implicitly.");
                self::assertSame(AllowlistSerializer::class, $transport['serializer'] ?? null, "$where: transport $name does not pin AllowlistSerializer.");
            }
        }

        $container = self::getContainer();
        self::assertInstanceOf(AllowlistSerializer::class, self::unwrap($container->get('messenger.default_serializer')));
        foreach (['async', 'failed'] as $name) {
            $transport = $container->get('messenger.transport.'.$name);
            self::assertInstanceOf(InMemoryTransport::class, $transport);
            $serializer = (new \ReflectionProperty(InMemoryTransport::class, 'serializer'))->getValue($transport);
            self::assertInstanceOf(AllowlistSerializer::class, self::unwrap($serializer), "Transport $name does not serialize through AllowlistSerializer.");
        }
    }

    /**
     * 4b. The inner serializer is Messenger's own shape, built inside the class — never the application `serializer`
     * (API Platform's normalisers can read the database) (M-7). Mutation M22 → red.
     */
    #[Test]
    public function theInnerSerializerIsNotTheApplicationSerializer(): void
    {
        $inner = self::innerSymfonySerializer(self::configuredSerializer());
        self::assertNotSame(self::getContainer()->get('serializer'), $inner, 'AllowlistSerializer uses the application serializer.');
        $normalizers = (new \ReflectionProperty(SymfonySerializer::class, 'normalizers'))->getValue($inner);
        self::assertSame(
            ['Symfony\Component\Messenger\Transport\Serialization\Normalizer\FlattenExceptionNormalizer', DateTimeNormalizer::class, ArrayDenormalizer::class, ObjectNormalizer::class],
            array_map(get_class(...), $normalizers),
            'The inner serializer\'s normalisers are not Messenger\'s own four.',
        );
    }

    /**
     * 5. A `type` outside the catalogue is refused before ANY construction (H-3): the planted class records construction,
     * and no `LogicException('constructed')` may appear anywhere in the chain (Symfony wraps every Throwable in
     * `MessageDecodingFailedException`, so asserting the class alone proves nothing). Mutation M5 (check after
     * delegating) → red.
     */
    #[Test]
    public function aClassOutsideTheCatalogueIsRefusedOnDecodeBeforeConstruction(): void
    {
        $refusal = self::refusalOf(self::configuredSerializer(), ['body' => '{}', 'headers' => ['type' => ConstructionTrap::class]]);

        self::assertFalse(ConstructionTrap::$constructed, 'The uncatalogued class was constructed before the refusal.');
        self::assertNoConstructionInChain($refusal);
    }

    /**
     * 6. A PHP-serialized body is refused (M-7): through the CONFIGURED transport serializer (L-5, so M3/M4 reach it), and
     * through a planted-catalogue instance where the type IS catalogued, so the body itself is what is refused.
     */
    #[Test]
    public function aRawPhpSerializedBodyIsRefused(): void
    {
        $envelope = ['body' => 'O:8:"stdClass":0:{}', 'headers' => ['type' => CataloguedProbe::class]];
        $transportSerializer = (new \ReflectionProperty(InMemoryTransport::class, 'serializer'))->getValue(self::getContainer()->get('messenger.transport.async'));
        self::assertInstanceOf(SerializerInterface::class, $transportSerializer);
        self::refusalOf($transportSerializer, $envelope);
        self::refusalOf(self::planted([CataloguedProbe::class]), $envelope);
        self::refusalOf(self::planted([CataloguedProbe::class]), ['body' => 'O:8:"stdClass":0:{}', 'headers' => ['type' => \stdClass::class]]);
    }

    /**
     * 7. A stamp header naming a `StampInterface` class outside the allowlist is refused before construction (H-2).
     * Mutation M6 (drop our stamp-header check) → red, ONLY because the trap is a real stamp.
     */
    #[Test]
    public function aStampHeaderNamingAnUnlistedStampIsRefused(): void
    {
        $serializer = self::planted([CataloguedProbe::class]);
        $good = $serializer->encode(new Envelope(new CataloguedProbe(new ProbeId('p'))));
        self::assertInstanceOf(CataloguedProbe::class, $serializer->decode($good)->getMessage(), 'The control envelope does not decode, so a refusal below proves nothing.');

        foreach (['X-Message-Stamp-'.TrapStamp::class, 'x-message-stamp-'.TrapStamp::class] as $header) {
            TrapStamp::$constructed = false;
            $refusal = self::refusalOf($serializer, ['body' => $good['body'], 'headers' => $good['headers'] + [$header => '[{}]']]);
            self::assertFalse(TrapStamp::$constructed, "The unlisted stamp ($header) was constructed before the refusal.");
            self::assertNoConstructionInChain($refusal);
        }
    }

    /** 7b. Symfony's effectful stamps are denied, one case each (H-2). Mutation M6 → red. */
    #[Test]
    #[DataProvider('deniedStamps')]
    public function theEffectfulSymfonyStampsAreDenied(string $stamp): void
    {
        $serializer = self::planted([CataloguedProbe::class]);
        $good = $serializer->encode(new Envelope(new CataloguedProbe(new ProbeId('p'))));

        self::refusalOf($serializer, ['body' => $good['body'], 'headers' => $good['headers'] + ['X-Message-Stamp-'.$stamp => '[]']]);
    }

    /** @return iterable<string, array{string}> */
    public static function deniedStamps(): iterable
    {
        foreach (AllowlistSerializer::DENIED_STAMPS as $stamp) {
            yield $stamp => [$stamp];
        }
    }

    /**
     * 8. **The stamp allowlist is derived, not remembered:** a planted catalogued message whose handler always throws is
     * dispatched, retried under the CONFIGURED `async` retry strategy until it lands in `failed`, and every stamp header
     * any encode wrote is collected. Observed ⊆ allowlist, and every allow-listed stamp was observed or carries a reason
     * below. The envelope is then decoded back from `failed` (M-7: proves `FlattenExceptionNormalizer` is present —
     * mutation M21 → red). The bus is built from Symfony's own default middleware in the order `default_middleware: true`
     * composes it; the clock is mocked, so the retry delays pass without sleeping.
     */
    #[Test]
    public function theStampAllowlistIsExactlyWhatRetryAndFailureWrite(): void
    {
        $recorded = [];
        $serializer = new class(self::planted([CataloguedProbe::class]), $recorded) implements SerializerInterface {
            /** @param list<string> $recorded */
            public function __construct(private readonly SerializerInterface $inner, private array &$recorded)
            {
            }

            public function decode(array $encodedEnvelope): Envelope
            {
                return $this->inner->decode($encodedEnvelope);
            }

            public function encode(Envelope $envelope): array
            {
                $encoded = $this->inner->encode($envelope);
                foreach (array_keys($encoded['headers']) as $name) {
                    if (str_starts_with((string) $name, 'X-Message-Stamp-')) {
                        $this->recorded[] = substr((string) $name, \strlen('X-Message-Stamp-'));
                    }
                }

                return $encoded;
            }
        };

        $clock = new MockClock();
        $async = new InMemoryTransport($serializer, $clock);
        $failed = new InMemoryTransport($serializer, $clock);
        $transports = self::locator(['async' => $async, 'failed' => $failed]);
        $retry = self::getContainer()->get('messenger.retry_strategy_locator');
        self::assertInstanceOf(ContainerInterface::class, $retry);
        self::assertInstanceOf(RetryStrategyInterface::class, $retry->get('async'), 'The configured async retry strategy is not reachable.');

        $bus = new MessageBus([
            new AddBusNameStampMiddleware('messenger.bus.default'),
            new RejectRedeliveredMessageMiddleware(),
            new DispatchAfterCurrentBusMiddleware(),
            new FailedMessageProcessingMiddleware(),
            new MessageClassAllowlistMiddleware([CataloguedProbe::class]),
            new SendMessageMiddleware(new SendersLocator([CataloguedProbe::class => ['async']], $transports)),
            new HandleMessageMiddleware(new HandlersLocator([CataloguedProbe::class => [static function (): never {
                throw new \RuntimeException('the probe handler always fails');
            }]])),
        ]);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new AddErrorDetailsStampListener());
        $dispatcher->addSubscriber(new SendFailedMessageForRetryListener($transports, $retry, new NullLogger(), $dispatcher));
        $dispatcher->addSubscriber(new SendFailedMessageToFailureTransportListener(self::locator(['async' => $failed]), new NullLogger()));
        $turns = 0;
        $dispatcher->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event) use (&$turns, $failed): void {
            if (++$turns > 200 || ($event->isWorkerIdle() && [] !== $failed->getSent())) {
                $event->getWorker()->stop();
            }
        });

        $bus->dispatch(new CataloguedProbe(new ProbeId('drain')));
        (new Worker(['async' => $async], $bus, $dispatcher, null, null, $clock))->run(['sleep' => 1_000_000]);

        self::assertLessThanOrEqual(200, $turns, 'The drain did not reach the failure transport.');
        $clock->sleep(1); // the failure send carries `DelayStamp(0)`; the in-memory transport releases strictly after it
        $parked = $failed->get();
        self::assertCount(1, $parked, 'The envelope did not land in failed exactly once.');
        $back = $parked[0];
        self::assertInstanceOf(CataloguedProbe::class, $back->getMessage());
        self::assertNotNull($back->last(ErrorDetailsStamp::class), 'The parked envelope lost its error details: FlattenExceptionNormalizer is missing (M-7).');
        self::assertNotNull($back->last(SentToFailureTransportStamp::class));

        $observed = array_values(array_unique($recorded));
        sort($observed);
        self::assertSame([], array_values(array_diff($observed, AllowlistSerializer::STAMP_ALLOWLIST)),
            'The drain wrote a stamp the allowlist refuses: list it (with a reason) or find out why it is written.');
        $notObserved = array_values(array_diff(AllowlistSerializer::STAMP_ALLOWLIST, $observed, array_keys(self::NOT_WRITTEN_BY_THE_DRAIN)));
        self::assertSame([], $notObserved, 'An allow-listed stamp was never written by the drain and carries no reason: remove it.');
    }

    /** Allow-listed stamps the drain cannot write, each with its reason. */
    private const NOT_WRITTEN_BY_THE_DRAIN = [
        TenantIdStamp::class => 'Written by TenantStampMiddleware for a TenantMessage; TenantStampMiddlewareTest round-trips it through AllowlistSerializer.',
    ];

    /**
     * 9. Every catalogued class implements exactly ONE marker. Vacuous over the real catalogue until Mail-C1; the planted
     * lists carry it until then.
     */
    #[Test]
    public function everyCatalogueMessageIsEitherPreAuthenticationOrTenantStamped(): void
    {
        self::assertSame([], self::markerViolations(MessageCatalogue::ALL));
        self::assertSame([], self::markerViolations([CataloguedProbe::class, TenantProbe::class]));
        self::assertNotSame([], self::markerViolations([BothMarkersProbe::class]), 'Two markers were not refused.');
        self::assertNotSame([], self::markerViolations([NoMarkerProbe::class]), 'No marker was not refused.');
    }

    /** 10. ARCHITECTURE.md §6.4 M-2: no bare scalar in a payload. Vacuous over the real catalogue until Mail-C1. */
    #[Test]
    public function messagePayloadsHoldNoBareScalars(): void
    {
        foreach (MessageCatalogue::ALL as $class) {
            self::assertSame([], MessagePayloadRule::violations($class));
        }
        self::assertSame([], MessagePayloadRule::violations(CataloguedProbe::class));
        self::assertNotSame([], MessagePayloadRule::violations(BareScalarProbe::class), 'A bare string payload was not refused.');
    }

    /**
     * 11. The mailer SENDS and never dispatches (`message_bus: false`, H-2). Mutation M7 (remove it) → the structural half
     * red (`Mailer::$bus` is the bus) and the behavioural half red too (the uncatalogued `SendEmailMessage` is refused by
     * the allowlist middleware, L-7). **The structural half is the one that survives if that middleware is ever removed.**
     */
    #[Test]
    public function theMailerSendsSynchronouslyAndNeverDispatches(): void
    {
        $container = self::getContainer();
        $mailer = $container->get('test.mailer');
        self::assertInstanceOf(Mailer::class, $mailer, 'MailerInterface is not the mailer.mailer service.');
        self::assertNull((new \ReflectionProperty(Mailer::class, 'bus'))->getValue($mailer), 'mailer.mailer holds a message bus: a send would dispatch.');

        $mailer->send((new Email())->to('someone@example.test')->subject('probe')->text('probe'));
        self::assertEmailCount(1);
        $async = $container->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $async);
        self::assertSame([], $async->getSent(), 'A send put an envelope on async.');
    }

    /** 12. `SendEmailMessage` is never catalogued nor routed, and dispatching one is refused. Mutations M8, M19 → red. */
    #[Test]
    public function sendEmailMessageIsNeverCatalogued(): void
    {
        self::assertNotContains(SendEmailMessage::class, MessageCatalogue::ALL);
        self::assertArrayNotHasKey(SendEmailMessage::class, self::compiledRouting());
        $email = (new Email())->from('a@example.test')->to('b@example.test')->subject('s')->text('t');
        try {
            self::getContainer()->get('messenger.default_bus')->dispatch(new SendEmailMessage($email));
            self::fail('Dispatching a SendEmailMessage was not refused.');
        } catch (MessageNotCatalogued) {
        }
    }

    /**
     * 12b. **Finding F-A (Messenger 7.4.21):** framework-bundle registers handlers on our bus that we never asked for —
     * `SendEmailMessage` (the mailer) and `RunCommandMessage` (runs ANY console command). No framework-handled message
     * may ever be catalogued (so none can be dispatched or decoded), and every `App\` handled message must be. Derived
     * from the compiled handlers locator; the two named framework messages are the anti-vacuity control.
     */
    #[Test]
    public function noFrameworkHandledMessageIsCatalogued(): void
    {
        $locator = self::getContainer()->get('messenger.bus.default.messenger.handlers_locator');
        self::assertInstanceOf(HandlersLocator::class, $locator);
        $handled = array_keys((new \ReflectionProperty(HandlersLocator::class, 'handlers'))->getValue($locator));
        self::assertContains(SendEmailMessage::class, $handled, 'The handler derivation sees nothing.');
        self::assertContains(RunCommandMessage::class, $handled, 'The handler derivation sees nothing.');
        foreach ($handled as $message) {
            if (str_starts_with($message, 'App\\')) {
                self::assertContains($message, MessageCatalogue::ALL, "$message has a handler but is not catalogued.");
            } else {
                self::assertNotContains($message, MessageCatalogue::ALL, "The framework message $message is catalogued.");
            }
        }
    }

    /**
     * 13. Routing is exactly the catalogue, all to `async`, in config and compiled (both empty at Mail-C0). No `sync`
     * transport exists. Mutation M9 (route an existing class not in the catalogue) → red.
     */
    #[Test]
    public function everyCatalogueClassIsRoutedToAsyncAndNothingElseIsRouted(): void
    {
        $routing = self::compiledRouting();
        self::assertSame(MessageCatalogue::ALL, array_keys($routing), 'Compiled routing is not exactly the catalogue.');
        foreach ($routing as $class => $senders) {
            self::assertSame(['async'], $senders, "$class is not routed to async alone.");
        }
        foreach (self::configBlocks() as $where => $block) {
            self::assertSame([], array_values(array_diff(array_keys($block['routing'] ?? []), MessageCatalogue::ALL)), "$where routes a class outside the catalogue.");
            foreach ($block['transports'] ?? [] as $name => $transport) {
                $dsn = \is_array($transport) ? (string) ($transport['dsn'] ?? '') : (string) $transport;
                self::assertStringStartsNotWith('sync:', $dsn, "$where: transport $name is synchronous.");
            }
        }
    }

    /**
     * 14. **Tripwire (RED M-5): the first `TenantMessage` owes the tenant-status gate.** Red when a catalogue class
     * implements `TenantMessage` and `App\Tests\Messaging\WorkerTenantStatusTest` is absent or its `tenantStatuses()`
     * provider does not cover every `Tenant::STATUS_*`. Planted control: a `TenantMessage` in a planted catalogue → red.
     * Mutation M18.
     */
    #[Test]
    public function aTenantMessageInTheCatalogueRequiresTheTenantStatusGate(): void
    {
        self::assertNotSame([], self::tenantGateObligations([TenantProbe::class]), 'A planted TenantMessage did not trip the tripwire.');
        self::assertSame([], self::tenantGateObligations([CataloguedProbe::class]));
        $owed = self::tenantGateObligations(MessageCatalogue::ALL);
        self::assertSame([], $owed, implode("\n", $owed));
    }

    /**
     * 15. L-9: a message sent straight through `mailer.transports` (what Mail-C1's handler will use) is seen by
     * `MailerAssertionsTrait`, so C1's mail tests have an observer.
     */
    #[Test]
    public function mailerAssertionsSeeAMessageSentThroughTheTransportDirectly(): void
    {
        $transport = self::getContainer()->get('mailer.transports');
        self::assertInstanceOf(\Symfony\Component\Mailer\Transport\TransportInterface::class, $transport);
        $transport->send((new Email())->from('no-reply@erp.local')->to('someone@example.test')->subject('direct')->text('direct'));
        self::assertEmailCount(1);
    }

    /**
     * 16. **`async` and `failed` are two streams in every non-test environment** (mail-c0-red.md M-1). In the Redis bridge a
     * DSN path segment overrides `options.stream`, so `redis://…/messages` would put both transports on ONE stream and a
     * retried message would be consumed as failed (and the reverse). Builds each transport's bridge `Connection` from the
     * committed config's options and `.env.worker.example`'s DSN shape, with an unconnected `\Redis` and `lazy` on — no
     * socket is opened. Planted control: the same DSN with a path collapses the two. Mutation M20 → red.
     */
    #[Test]
    public function asyncAndFailedUseDistinctStreams(): void
    {
        $dsn = null;
        foreach ((array) file(self::backend().'/../.env.worker.example', \FILE_IGNORE_NEW_LINES) as $line) {
            if (1 === preg_match('/^MESSENGER_TRANSPORT_DSN=(.+)$/', (string) $line, $m)) {
                $dsn = trim($m[1], '"\'');
            }
        }
        self::assertIsString($dsn, '.env.worker.example carries no MESSENGER_TRANSPORT_DSN.');
        self::assertStringStartsWith('redis://', $dsn);

        $blocks = self::configBlocks();
        foreach (['dev', 'prod'] as $env) {
            $streams = [];
            foreach (['async', 'failed'] as $name) {
                $options = (array) ($blocks['framework']['transports'][$name]['options'] ?? []);
                $options = array_replace($options, (array) ($blocks['when@'.$env]['transports'][$name]['options'] ?? []));
                self::assertTrue((bool) ($options['lazy'] ?? false), "$env: transport $name is not lazy, so the web process would connect at boot.");
                $streams[$name] = self::streamOf($dsn, $options);
            }
            self::assertNotSame($streams['async'], $streams['failed'], "$env: async and failed share the stream {$streams['async']}.");
        }

        $withPath = rtrim($dsn, '/').'/messages';
        self::assertSame(
            self::streamOf($withPath, ['stream' => 'messages', 'lazy' => true]),
            self::streamOf($withPath, ['stream' => 'failed', 'lazy' => true]),
            'Control: a DSN path no longer overrides options.stream, so this test no longer measures M-1.',
        );
    }

    /** @param array<string, mixed> $options */
    private static function streamOf(string $dsn, array $options): string
    {
        $connection = \Symfony\Component\Messenger\Bridge\Redis\Transport\Connection::fromDsn($dsn, $options, new \Redis());

        return (string) (new \ReflectionProperty($connection, 'stream'))->getValue($connection);
    }

    // ---------------------------------------------------------------------------------------------------------------

    /**
     * @param list<class-string> $catalogue
     *
     * @return list<string> the obligations still owed, empty when none
     */
    public static function tenantGateObligations(array $catalogue): array
    {
        $tenantMessages = array_values(array_filter($catalogue, static fn (string $c): bool => is_subclass_of($c, TenantMessage::class)));
        if ([] === $tenantMessages) {
            return [];
        }
        $statuses = [];
        foreach ((new \ReflectionClass(Tenant::class))->getReflectionConstants() as $constant) {
            if (str_starts_with($constant->getName(), 'STATUS_')) {
                $statuses[] = (string) $constant->getValue();
            }
        }
        sort($statuses);
        $covered = [];
        $gate = 'App\Tests\Messaging\WorkerTenantStatusTest';
        if (class_exists($gate) && method_exists($gate, 'tenantStatuses')) {
            foreach ($gate::tenantStatuses() as $case) {
                $covered[] = (string) (\is_array($case) ? reset($case) : $case);
            }
        }
        $covered = array_values(array_unique($covered));
        sort($covered);
        if ($covered === $statuses) {
            return [];
        }

        return [
            'The catalogue holds a TenantMessage ('.implode(', ', $tenantMessages).') but the tenant-status gate is not built.',
            'Owed with it (API.md §11.2, mail-c0-red.md M-5): gate on Tenant::permitsLogin() before the handler runs; a message for a',
            'tenant that does not permit login is dead-lettered, never retried; '.$gate.'::tenantStatuses() covers every status ('.implode(', ', $statuses).');',
            'and the owner accepts or orders closing work on RESIDUAL_GAPS.md\'s "whoever holds the mail queue\'s password" entry at this trigger.',
        ];
    }

    /**
     * @param list<class-string> $catalogue
     *
     * @return list<string>
     */
    private static function markerViolations(array $catalogue): array
    {
        $violations = [];
        foreach ($catalogue as $class) {
            $markers = (int) is_subclass_of($class, PreAuthenticationMessage::class) + (int) is_subclass_of($class, TenantMessage::class);
            if (1 !== $markers) {
                $violations[] = "$class implements $markers markers; exactly one of PreAuthenticationMessage and TenantMessage is required.";
            }
        }

        return $violations;
    }

    /** @param list<class-string> $catalogue */
    private static function planted(array $catalogue): AllowlistSerializer
    {
        return new AllowlistSerializer($catalogue, new NullLogger());
    }

    private static function configuredSerializer(): AllowlistSerializer
    {
        $serializer = self::unwrap(self::getContainer()->get('messenger.default_serializer'));
        self::assertInstanceOf(AllowlistSerializer::class, $serializer);

        return $serializer;
    }

    private static function innerSymfonySerializer(AllowlistSerializer $serializer): object
    {
        $messenger = (new \ReflectionProperty(AllowlistSerializer::class, 'inner'))->getValue($serializer);
        $inner = (new \ReflectionProperty($messenger::class, 'serializer'))->getValue($messenger);
        self::assertIsObject($inner);

        return $inner;
    }

    /** Symfony 7.4.21 decorates every transport serializer with `SigningSerializer` (finding F-A); look through it. */
    private static function unwrap(mixed $serializer): mixed
    {
        while ($serializer instanceof SigningSerializer) {
            $serializer = (new \ReflectionProperty(SigningSerializer::class, 'inner'))->getValue($serializer);
        }

        return $serializer;
    }

    /** @param array{body: string, headers: array<string, mixed>} $envelope */
    private static function refusalOf(SerializerInterface $serializer, array $envelope): MessageDecodingFailedException
    {
        try {
            $serializer->decode($envelope);
        } catch (MessageDecodingFailedException $e) {
            self::assertNotSame('', $e->getMessage());

            return $e;
        }
        self::fail('The envelope was decoded: '.json_encode($envelope['headers']));
    }

    private static function assertNoConstructionInChain(\Throwable $e): void
    {
        for ($t = $e; null !== $t; $t = $t->getPrevious()) {
            self::assertFalse($t instanceof \LogicException && 'constructed' === $t->getMessage(), 'The refusal chain carries the trap\'s construction.');
        }
    }

    /** @return list<object> the compiled bus's middleware, through any debug wrapper */
    private static function busMiddleware(): array
    {
        $bus = self::getContainer()->get('messenger.bus.default');
        while (!$bus instanceof MessageBus && property_exists($bus, 'decoratedBus')) {
            $bus = (new \ReflectionProperty($bus, 'decoratedBus'))->getValue($bus);
        }
        self::assertInstanceOf(MessageBus::class, $bus);
        $aggregate = (new \ReflectionProperty(MessageBus::class, 'middlewareAggregate'))->getValue($bus);
        self::assertInstanceOf(\Traversable::class, $aggregate);

        return array_values(iterator_to_array($aggregate, false));
    }

    /** @return array<string, list<string>> */
    private static function compiledRouting(): array
    {
        $locator = self::getContainer()->get('messenger.senders_locator');
        self::assertInstanceOf(SendersLocator::class, $locator);

        return (new \ReflectionProperty(SendersLocator::class, 'sendersMap'))->getValue($locator);
    }

    /** @return array<string, array<string, mixed>> the `framework.messenger` block of the base config and of every `when@*` */
    private static function configBlocks(): array
    {
        $parsed = Yaml::parseFile(self::backend().'/'.self::MESSENGER_YAML);
        self::assertIsArray($parsed);
        $blocks = ['framework' => (array) ($parsed['framework']['messenger'] ?? [])];
        foreach ($parsed as $key => $value) {
            if (str_starts_with((string) $key, 'when@') && \is_array($value)) {
                $blocks[(string) $key] = (array) ($value['framework']['messenger'] ?? []);
            }
        }
        self::assertNotSame([], $blocks['framework'], 'messenger.yaml has no framework.messenger block.');

        return $blocks;
    }

    /** @param array<string, object> $services */
    private static function locator(array $services): ContainerInterface
    {
        return new class($services) implements ContainerInterface {
            /** @param array<string, object> $services */
            public function __construct(private readonly array $services)
            {
            }

            public function get(string $id): object
            {
                return $this->services[$id] ?? throw new \OutOfBoundsException($id);
            }

            public function has(string $id): bool
            {
                return isset($this->services[$id]);
            }
        };
    }

    private static function backend(): string
    {
        return \dirname(__DIR__, 2);
    }
}

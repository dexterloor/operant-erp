<?php

declare(strict_types=1);

namespace App\Platform\Messaging;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\HandlerArgumentsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\SerializerStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Stamp\ValidationStamp;
use Symfony\Component\Messenger\Transport\Serialization\Normalizer\FlattenExceptionNormalizer;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer as SymfonySerializer;

/**
 * **What keeps a queued envelope from being code execution in the worker** (Mail-C0, 2026-10-23;
 * `docs/session13/mail-c0-build-plan.md` Revision 1 FINAL §R4; `mail-c0-red.md` H-2, H-3, M-3, M-7). The mail queue is
 * outside Postgres, so no grant stands between whoever can write to it and the worker: this class is the rule.
 *
 * `decode()` refuses, **before anything is deserialized**:
 *   - a `type` header outside {@see MessageCatalogue::ALL};
 *   - any `X-Message-Stamp-*` header (compared case-insensitively) naming a class outside {@see self::STAMP_ALLOWLIST},
 *     or inside {@see self::DENIED_STAMPS}.
 * Symfony's own decoder (from 7.4.18) already refuses a stamp header naming a non-`StampInterface` class, skips
 * `NonSendableStampInterface` stamps and strips `SerializerStamp`'s code-affecting context. What it does not refuse is a
 * `StampInterface` implementation WITH AN EFFECT: a forged `HandledStamp` makes the handler skip (the mail is silently
 * dropped), a `HandlerArgumentsStamp` injects handler arguments. The lists close that.
 *
 * On refusal it LOGS the refused `type` and stamp class names — never the body, never a header value — and throws
 * `MessageDecodingFailedException`. ⚠ On the Redis receiver that rejects the envelope and stops the worker
 * (`RESIDUAL_GAPS.md` G-1.3, M-3); the entry is gone.
 *
 * `encode()` refuses a message outside the catalogue too, so a hand-built envelope cannot reach a transport either.
 *
 * **The inner serializer is built here, never taken from the container** (M-7): `serializer` and
 * `messenger.transport.symfony_serializer` carry API Platform's normalisers, whose IRI resolution can read the database.
 * The shape is Messenger's own `Serializer::create()` minus XML: `FlattenExceptionNormalizer` (without it
 * `ErrorDetailsStamp` cannot decode and the failure stream breaks), `DateTimeNormalizer`, `ArrayDenormalizer`,
 * `ObjectNormalizer` with a `ReflectionExtractor`; `JsonEncoder` only. Never `PhpSerializer`.
 */
final class AllowlistSerializer implements SerializerInterface
{
    /**
     * The stamps a queued envelope may carry: our tenant stamp plus exactly the Symfony stamps the bus, the retry and the
     * failure path write. Derived by `MessengerConfigurationTest::theStampAllowlistIsExactlyWhatRetryAndFailureWrite`
     * (a drain through retries into `failed`), not from memory.
     *
     * `BusNameStamp` is listed although RED asked for it to be denied "unless the drain test observes it": the default
     * middleware writes it on every dispatch, so it is observed, and with one bus it can only name that bus.
     * `TransportMessageIdStamp` is observed too (every transport writes its id).
     */
    public const STAMP_ALLOWLIST = [
        TenantIdStamp::class,
        BusNameStamp::class,
        DelayStamp::class,
        ErrorDetailsStamp::class,
        RedeliveryStamp::class,
        SentToFailureTransportStamp::class,
        TransportMessageIdStamp::class,
    ];

    /** Refused even if a later edit lists one above (the constructor refuses an overlap). */
    public const DENIED_STAMPS = [
        HandledStamp::class,
        HandlerArgumentsStamp::class,
        SerializerStamp::class,
        ValidationStamp::class,
        TransportNamesStamp::class,
        DispatchAfterCurrentBusStamp::class,
    ];

    private const STAMP_HEADER_PREFIX = 'x-message-stamp-';

    private readonly Serializer $inner;

    /** @param list<class-string> $catalogue */
    public function __construct(
        #[Autowire(MessageCatalogue::ALL)]
        private readonly array $catalogue,
        private readonly LoggerInterface $logger,
    ) {
        if ([] !== array_intersect(self::STAMP_ALLOWLIST, self::DENIED_STAMPS)) {
            throw new \LogicException('A stamp is both allowed and denied.');
        }
        $this->inner = new Serializer(self::innerSerializer(), 'json');
    }

    public function decode(array $encodedEnvelope): Envelope
    {
        $headers = \is_array($encodedEnvelope['headers'] ?? null) ? $encodedEnvelope['headers'] : [];
        $type = $headers['type'] ?? null;
        $refusedStamps = [];
        foreach (array_keys($headers) as $name) {
            $name = (string) $name;
            if (!str_starts_with(strtolower($name), self::STAMP_HEADER_PREFIX)) {
                continue;
            }
            $class = substr($name, \strlen(self::STAMP_HEADER_PREFIX));
            if (!\in_array($class, self::STAMP_ALLOWLIST, true) || \in_array($class, self::DENIED_STAMPS, true)) {
                $refusedStamps[] = $class;
            }
        }

        $typeRefused = !\is_string($type) || !\in_array($type, $this->catalogue, true);
        if ($typeRefused || [] !== $refusedStamps) {
            $this->logger->error('A queued envelope was refused before decoding: its message or stamp class is not allow-listed.', [
                'type' => \is_string($type) ? $type : get_debug_type($type),
                'type_refused' => $typeRefused,
                'refused_stamps' => $refusedStamps,
            ]);

            throw new MessageDecodingFailedException($typeRefused
                ? 'The envelope\'s message type is not in the message catalogue.'
                : 'The envelope carries a stamp that is not allow-listed.');
        }

        return $this->inner->decode($encodedEnvelope);
    }

    public function encode(Envelope $envelope): array
    {
        $class = $envelope->getMessage()::class;
        if (!\in_array($class, $this->catalogue, true)) {
            throw MessageNotCatalogued::forClass($class);
        }

        return $this->inner->encode($envelope);
    }

    private static function innerSerializer(): SymfonySerializer
    {
        return new SymfonySerializer(
            [
                new FlattenExceptionNormalizer(),
                new DateTimeNormalizer(),
                new ArrayDenormalizer(),
                new ObjectNormalizer(propertyTypeExtractor: new ReflectionExtractor()),
            ],
            [new JsonEncoder()],
        );
    }
}

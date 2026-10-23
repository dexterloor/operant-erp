<?php

declare(strict_types=1);

namespace App\Platform\Messaging;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * **Refuses any message outside {@see MessageCatalogue::ALL} before it is sent or handled** (Mail-C0; plan §4). It sits
 * after Symfony's default middleware and before `send_message`/`handle_message`, so an uncatalogued class never reaches a
 * transport or a handler — including `SendEmailMessage`, if anything ever dispatches one (the mailer does not:
 * `mailer.yaml` `message_bus: false`).
 *
 * The catalogue is a constructor argument so a test can build it with a planted list; the container binds it to the
 * real constant.
 */
final readonly class MessageClassAllowlistMiddleware implements MiddlewareInterface
{
    /** @param list<class-string> $catalogue */
    public function __construct(
        #[Autowire(MessageCatalogue::ALL)]
        private array $catalogue,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $class = $envelope->getMessage()::class;
        if (!\in_array($class, $this->catalogue, true)) {
            throw MessageNotCatalogued::forClass($class);
        }

        return $stack->next()->handle($envelope, $stack);
    }
}

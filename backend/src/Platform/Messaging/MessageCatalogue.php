<?php

declare(strict_types=1);

namespace App\Platform\Messaging;

/**
 * **The only message classes the bus may carry** (Mail-C0, 2026-10-23; `docs/session13/mail-c0-build-plan.md` Revision 1
 * FINAL §R4; design §4.1). Three things read this one list, so it is never copied: `AllowlistSerializer` (a queued envelope
 * whose `type` is not here is refused before any class is built), `MessageClassAllowlistMiddleware` (a dispatch of a class
 * not here is refused), and `MessengerConfigurationTest` (routing is exactly this list, all to `async`).
 *
 * **Empty at Mail-C0** — no message exists yet. Mail-C1 adds the password-reset request.
 *
 * Adding a class is a reviewed edit: it must implement exactly one of {@see PreAuthenticationMessage} or
 * {@see TenantMessage}, its constructor may hold no bare scalar, and the first {@see TenantMessage} trips
 * `MessengerConfigurationTest::aTenantMessageInTheCatalogueRequiresTheTenantStatusGate`.
 * **Never `Symfony\Component\Mailer\Messenger\SendEmailMessage`:** the mailer sends synchronously from the worker
 * (`mailer.yaml` `message_bus: false`); a serialized `Email` on the queue would be a second, unguarded copy of every body.
 */
final class MessageCatalogue
{
    /** @var list<class-string> */
    public const ALL = [];
}

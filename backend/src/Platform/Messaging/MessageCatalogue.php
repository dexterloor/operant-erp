<?php

declare(strict_types=1);

namespace App\Platform\Messaging;

use App\Modules\UserManagement\Application\Contract\Message\IssueInvitation;
use App\Modules\UserManagement\Application\Contract\Message\PasswordResetRequested;

/**
 * **The only message classes the bus may carry** (Mail-C0, 2026-10-23; `docs/session13/mail-c0-build-plan.md` Revision 1
 * FINAL §R4; design §4.1). Three things read this one list, so it is never copied: `AllowlistSerializer` (a queued envelope
 * whose `type` is not here is refused before any class is built), `MessageClassAllowlistMiddleware` (a dispatch of a class
 * not here is refused), and `MessengerConfigurationTest` (routing is exactly this list, all to `async`).
 *
 * **Two members since U-C1b:** the password-reset request (Mail-C1) and the invitation (`IssueInvitation`, the first
 * {@see TenantMessage}). It names a MODULE class from `Platform` — the one Platform
 * list that must (it is read by the serializer, the middleware and the config test, and a string list would hide the
 * dependency from deptrac without removing it): an A-1 exception, registered in `deptrac.yaml` with its revisit trigger
 * (the third catalogued message, or the first from a module other than UserManagement — then feed this from
 * `framework.messenger.routing` through a container parameter). *ARCH TO CONFIRM.*
 *
 * **The payloads' value objects are a wire contract (M-4 (b)).** Changing a catalogued class's constructor, a VO's
 * constructor, their parameter names or their invariants is a message-version change: drain `async` and `failed` before
 * deploying it, or add a new message class. A queued envelope that no longer decodes is refused, and on the Redis receiver
 * a decode refusal stops the worker (`RESIDUAL_GAPS.md` G-1.3).
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
    public const ALL = [
        PasswordResetRequested::class,
        IssueInvitation::class,
    ];
}

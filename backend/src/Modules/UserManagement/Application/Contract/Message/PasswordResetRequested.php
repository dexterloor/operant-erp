<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application\Contract\Message;

use App\Modules\UserManagement\Domain\ClientIp;
use App\Modules\UserManagement\Domain\EmailAddress;
use App\Modules\UserManagement\Domain\MessageId;
use App\Modules\UserManagement\Domain\WorkspaceSlug;
use App\Platform\Messaging\PreAuthenticationMessage;

/**
 * **"Someone asked for a password link for this workspace and address"** — the first catalogued message (Mail-C1,
 * `mail-c1-build-plan.md` R1.6; design §4.2). Dispatched by `CreatePasswordReset` with **no database principal at all**
 * and handled by the mail worker on `erp_worker`, which decides — out of the request's sight — whether anyone gets mail.
 *
 * It carries what the requester typed (normalised and validated), never anything looked up: the request path reads no
 * user, so the 202 is the same for every well-formed request. It never carries a token, a link or a user id.
 * `requestedAt` is truncated to the second at dispatch (M-4 (a)): the wire carries no sub-second field, because the
 * serializer's RFC 3339 format would drop it. The worker stamps token instants from its own clock.
 *
 * **The payload's value objects are a wire contract (M-4 (b)).** Changing a VO's constructor, its parameter names or its
 * invariants is a message-version change: drain `async` and `failed` before deploying it, or add a new message class. A
 * queued envelope that no longer satisfies a VO is refused at decode, and on the Redis receiver a decode refusal stops
 * the worker (`RESIDUAL_GAPS.md` G-1.3).
 */
final readonly class PasswordResetRequested implements PreAuthenticationMessage
{
    public function __construct(
        public WorkspaceSlug $workspace,
        public EmailAddress $email,
        public ?ClientIp $clientIp,
        public \DateTimeImmutable $requestedAt,
        public MessageId $messageId,
    ) {
        if ('000000' !== $requestedAt->format('u')) {
            throw new \InvalidArgumentException('A queued request carries whole seconds only.');
        }
    }
}

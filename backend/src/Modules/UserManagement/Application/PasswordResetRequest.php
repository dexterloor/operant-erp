<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Modules\UserManagement\Application\Contract\Message\PasswordResetRequested;
use App\Modules\UserManagement\Domain\ClientIp;
use App\Modules\UserManagement\Domain\EmailAddress;
use App\Modules\UserManagement\Domain\MessageId;
use App\Modules\UserManagement\Domain\PreAuthKeys;
use App\Modules\UserManagement\Domain\WorkspaceSlug;
use App\Modules\UserManagement\Infrastructure\PreAuthBudgets;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * **`CreatePasswordReset` — ask for a password link without learning who has an account** (Mail-C1,
 * `docs/session13/mail-c1-build-plan.md` Revision 1 FINAL R1.6; design §4.2; `API.md` §4.3a item 4, §4.4.1 API-21 branch 6).
 *
 *  1  normalise the slug and the address (the sign-in normalisation)         — no statement
 *  2  format check: a slug `tenant_slug_shape` admits, an address `EmailAddress` admits — else 422, no statement, no envelope
 *  3  consume the `(ip_prefix)` budget, then `(slug, ip)` — **the same budgets as sign-in** (D-M3) — **erp_ratelimit**
 *  4  dispatch {@see PasswordResetRequested} to `async`                     — **no database principal at all**
 *  5  202, no body
 *
 * **Nothing here reads a user or a workspace.** The worker decides who, if anyone, gets mail, so the response — status,
 * headers, body — is the same object for a known address, an unknown one, a deactivated or deleted one, and a suspended
 * workspace. **Zero statements on every principal except `erp_ratelimit`** (T-M2). A queue that refuses the message is
 * logged — with the message id, never the address or the slug — and the 202 stands (design :384): a 503 for a dead queue
 * would be one more signal, and the requester can ask again.
 *
 * Order is a rule (M-2): normalisation is pure and runs first, so `" Acme "` is accepted; the format check runs before
 * the budgets, so malformed input writes no counter row; the budgets run before the dispatch, so a throttled caller
 * queues nothing.
 */
final readonly class PasswordResetRequest
{
    public function __construct(
        private PreAuthBudgets $budgets,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function request(PasswordResetRequestCommand $command): PasswordResetRequestResult
    {
        // --- 1 + 2 --------------------------------------------------------
        $errors = [];
        $workspace = $email = null;
        try {
            $workspace = WorkspaceSlug::fromInput($command->workspace);
        } catch (\InvalidArgumentException) {
            $errors[] = ['pointer' => '/workspace', 'code' => 'invalid_format', 'message' => 'Enter the workspace name you sign in with.'];
        }
        try {
            $email = EmailAddress::fromInput($command->email);
        } catch (\InvalidArgumentException) {
            $errors[] = ['pointer' => '/email', 'code' => 'invalid_format', 'message' => 'Enter an email address.'];
        }
        if (null === $workspace || null === $email) {
            return PasswordResetRequestResult::invalid($errors);
        }

        // --- 3 ------------------------------------------------------------
        $now = $this->clock->now();
        $ipPrefix = PreAuthKeys::ipPrefix($command->ipAddress);
        if (!$this->budgets->consumeIpBudget($ipPrefix, $now)) {
            return PasswordResetRequestResult::throttled();
        }
        if (!$this->budgets->consumeSlugBudget($workspace->value, $ipPrefix, $now)) {
            return PasswordResetRequestResult::throttled();
        }

        // --- 4 ------------------------------------------------------------
        $messageId = MessageId::new();
        try {
            $this->bus->dispatch(new PasswordResetRequested(
                workspace: $workspace,
                email: $email,
                clientIp: ClientIp::fromRequest($command->ipAddress),
                requestedAt: new \DateTimeImmutable('@'.$now->getTimestamp()),
                messageId: $messageId,
            ));
        } catch (\Throwable $e) {
            // Never the address, the slug or the exception message (which can quote either).
            $this->logger->error('A password reset request could not be queued.', ['message_id' => $messageId->value, 'exception_class' => $e::class]);
        }

        // --- 5 ------------------------------------------------------------
        return PasswordResetRequestResult::accepted();
    }
}

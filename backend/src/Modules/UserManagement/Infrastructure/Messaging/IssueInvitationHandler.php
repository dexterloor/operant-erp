<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Infrastructure\Messaging;

use App\Modules\AuditLog\Infrastructure\PasswordResetAuditWriter;
use App\Modules\UserManagement\Application\Contract\Message\IssueInvitation;
use App\Modules\UserManagement\Domain\SealCheck;
use App\Modules\UserManagement\Domain\SealsIdentities;
use App\Platform\Tenancy\Entity\Tenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;

/**
 * **The mail worker mails a newly added person the link that sets their first password** (U-C1b;
 * `docs/session13/u-c1-build-plan.md` Revision 2 FINAL R2.2; the reset handler's shape). One transaction on **`erp_worker`**;
 * the token row, its audit row and the SMTP send succeed or roll back together.
 *
 * The workspace is the STAMP's: `TenantStatusGateMiddleware` checked it and hands it over as the handler's second argument
 * (`HandlerArgumentsStamp`, final RED M-3) — never a value from the payload, which a dispatcher writes. That stamp is
 * non-sendable and denied on decode, so a forged one never arrives.
 *
 *  1  `SELECT slug, status FROM tenant WHERE id = <stamp>` — not permitted (status moved after the gate) or absent: acknowledge
 *  2  tenant context on this connection (`TenantContext::establishOn`)
 *  3  the person by id, live rows only (RLS: a stamp naming another workspace finds nothing), with
 *     `password_mac IS NULL AS uncredentialed` (`erp_worker` SELECTs that column since U-C1b, FA-6, with this caller)
 *  4  silent acknowledgement, nothing sent: no row · inactive · already has a credential · an identity seal that does not
 *     match (then `auth.credential.identity_mismatch`, once per person per UTC day, either kind) · a seal under ANOTHER key
 *     id: the U-C1a park (row `{"unknown_key_id": true}`, COMMIT, operator log, `failed`)
 *  5  the per-person advisory lock (the reset cap's, class 7002), then **one invitation per account, ever** (D-U7′): any
 *     `purpose = 'invitation'` row for this person → acknowledge. It holds while invitation rows are never purged
 *     (`RESIDUAL_GAPS.md` G-80 item 1) — and it is what bounds a forged queue entry (G-76, owner Q-1)
 *  6  mint: 16 random bytes of selector, 32 of verifier; `created_at` truncated to the second, `expires_at` + 72 h
 *     (PO/UX TO CONFIRM)
 *  7  INSERT, purpose `invitation`; a duplicate `message_id` → roll back, acknowledge (unreachable behind step 5: RC-5)
 *  8  `auth.password_reset.issued` `{"purpose":"invitation","message_id":…,"requested_by":"invitation"}` — the literal is
 *     this handler's (H-2): nothing on the queue can name an inviter in the trail. No IP: nobody made a request
 *  9  send through `TransportInterface` to the STORED email; the link is the reset page's (`/reset-password#token=`, UX TO
 *     CONFIRM; the page is unbuilt, G-80 item 2); a transport failure rolls back and is retried (3×, then `failed`)
 * 10  COMMIT (the caller).
 *
 * **Never logged, never queued, never in the trail:** the token, the verifier, the link or the email.
 */
#[AsMessageHandler]
final readonly class IssueInvitationHandler
{
    /** D-U2 (PO/UX TO CONFIRM): how long an invitation works — 72 hours. */
    public const LINK_LIFETIME_SECONDS = 259200;

    public const PURPOSE = 'invitation';

    /** The trail's `requested_by` (H-2): a literal, never a message value. */
    public const REQUESTED_BY = 'invitation';

    public function __construct(
        private Connection $worker,
        private TenantContext $tenants,
        private SealsIdentities $seals,
        private PasswordResetAuditWriter $audit,
        private TransportInterface $mailer,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private string $from,
        private string $publicUrl,
    ) {
    }

    public function __invoke(IssueInvitation $message, string $tenantId): void
    {
        $this->worker->beginTransaction();

        try {
            $this->handle($message, Uuid::fromString($tenantId)->toRfc4122());
        } catch (\Throwable $e) {
            if ($this->worker->isTransactionActive()) {
                $this->worker->rollBack();
            }

            throw $e;
        }

        if ($this->worker->isTransactionActive()) {
            $this->worker->commit();
        }
    }

    private function handle(IssueInvitation $message, string $tenantId): void
    {
        $messageId = $message->messageId->value;
        $userId = $message->user->value;

        // --- 1 ------------------------------------------------------------
        $tenant = $this->worker->fetchAssociative('SELECT slug, status FROM tenant WHERE id = ?::uuid', [$tenantId]);
        if (false === $tenant || !Tenant::statusPermitsLogin((string) $tenant['status'])) {
            return;
        }

        // --- 2 ------------------------------------------------------------
        $this->tenants->establishOn($this->worker, $tenantId);

        // --- 3 ------------------------------------------------------------
        $user = $this->worker->fetchAssociative(
            'SELECT id, email, is_active, identity_mac, password_mac IS NULL AS uncredentialed FROM app_user WHERE id = ?::uuid AND deleted_at IS NULL',
            [$userId],
        );

        // --- 4 ------------------------------------------------------------
        if (false === $user || true !== (bool) $user['is_active'] || true !== (bool) $user['uncredentialed']) {
            return;
        }
        $storedEmail = (string) $user['email'];
        $now = $this->clock->now();
        $seal = $this->seals->checkIdentity($tenantId, $userId, $storedEmail, null === $user['identity_mac'] ? null : (string) $user['identity_mac']);
        if (SealCheck::Verified !== $seal) {
            $dayStart = new \DateTimeImmutable('@'.($now->getTimestamp() - ($now->getTimestamp() % 86400)));
            if (!$this->audit->identityMismatchRecordedSince($userId, $dayStart)) {
                $this->audit->recordIdentityMismatch($tenantId, $userId, $storedEmail, null, $messageId, $now, self::REQUESTED_BY, SealCheck::UnknownKey === $seal);
            }
            if (SealCheck::UnknownKey !== $seal) {
                return;
            }
            // The U-C1a park: the row is COMMITTED first, then the operator log, then `failed`.
            $stored = (string) $user['identity_mac'];
            $this->worker->commit();
            $this->logger->error('An invitation was parked: the account\'s identity seal was made with another key.', [
                'message_id' => $messageId,
                'stored_key_id' => substr($stored, 3, 8),
                'configured_key_id' => $this->seals->keyId(),
            ]);

            throw new UnrecoverableMessageHandlingException('The identity seal carries a key id this worker does not hold.');
        }

        // --- 5 ------------------------------------------------------------
        $this->worker->executeStatement('SELECT pg_advisory_xact_lock(?, hashtext(?::uuid::text))', [PasswordResetRequestedHandler::CLASS_ID, $userId]);
        $invited = (int) $this->worker->fetchOne(
            'SELECT count(*) FROM password_reset_token WHERE user_id = ?::uuid AND purpose = ?',
            [$userId, self::PURPOSE],
        );
        if ($invited >= 1) {
            return;
        }

        // --- 6 ------------------------------------------------------------
        $selector = random_bytes(16);
        $verifier = random_bytes(32);
        $createdAt = new \DateTimeImmutable('@'.$now->getTimestamp());
        $expiresAt = $createdAt->modify('+'.self::LINK_LIFETIME_SECONDS.' seconds');
        $verifierMac = $this->seals->macVerifier($tenantId, $userId, self::PURPOSE, bin2hex($selector), $expiresAt->getTimestamp(), bin2hex($verifier));

        // --- 7 ------------------------------------------------------------
        try {
            $this->worker->executeStatement(<<<'SQL'
                INSERT INTO password_reset_token (id, tenant_id, user_id, purpose, selector, verifier_mac, message_id, created_at, expires_at)
                VALUES (?::uuid, ?::uuid, ?::uuid, ?, ?, ?, ?::uuid, ?::timestamptz, ?::timestamptz)
                SQL, [
                Uuid::v4()->toRfc4122(), $tenantId, $userId, self::PURPOSE, bin2hex($selector), $verifierMac, $messageId,
                $createdAt->format('Y-m-d H:i:sP'), $expiresAt->format('Y-m-d H:i:sP'),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'password_reset_token_message_key')) {
                $this->worker->rollBack();

                return;
            }

            throw $e;
        }

        // --- 8 ------------------------------------------------------------
        $this->audit->recordIssued($tenantId, $userId, $storedEmail, null, $messageId, $now, self::PURPOSE, self::REQUESTED_BY);

        // --- 9 ------------------------------------------------------------
        if ('' === $this->from || '' === $this->publicUrl) {
            throw new \LogicException('MAILER_FROM and APP_PUBLIC_URL must be set for the mail worker (.env.worker).');
        }
        $token = rtrim(strtr(base64_encode(Uuid::fromString($tenantId)->toBinary().$selector.$verifier), '+/', '-_'), '=');
        $link = rtrim($this->publicUrl, '/').'/reset-password#token='.$token;
        $email = (new Email())
            ->from(new Address($this->from))
            ->to(new Address($storedEmail))
            ->subject('You have been added to a workspace')
            ->text(self::body((string) $tenant['slug'], $link));

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->worker->rollBack();
            $this->logger->warning('An invitation could not be sent; it will be retried.', ['message_id' => $messageId]);

            throw $e;
        }
        // --- 10: the caller commits.
    }

    /** UX TO CONFIRM — placeholder copy. Plain text: no template engine is installed. */
    private static function body(string $workspace, string $link): string
    {
        return "You have been added to the workspace \"$workspace\".\n\n"
            ."To choose your password, open this link. It works once, for 72 hours:\n\n$link\n\n"
            ."If the link has run out, use \"Forgot your password?\" on the sign-in page with this workspace name.\n";
    }
}

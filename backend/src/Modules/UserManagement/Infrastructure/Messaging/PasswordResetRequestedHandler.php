<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Infrastructure\Messaging;

use App\Modules\AuditLog\Infrastructure\PasswordResetAuditWriter;
use App\Modules\UserManagement\Application\Contract\Message\PasswordResetRequested;
use App\Modules\UserManagement\Domain\SealCheck;
use App\Modules\UserManagement\Domain\SealsIdentities;
use App\Platform\Tenancy\Entity\Tenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
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
 * **The mail worker's handling of a password-reset request** (Mail-C1, `docs/session13/mail-c1-build-plan.md` Revision 1
 * FINAL R1.6; design §4.2). One transaction on **`erp_worker`**; the token row, its audit row and the SMTP send succeed
 * or roll back together.
 *
 *  1  `SELECT id, status FROM tenant WHERE slug = ?` — a miss: commit, acknowledge, nothing sent
 *  2  tenant context on this connection (`TenantContext::establishOn`)
 *  3  the user by stored email, live rows only (RLS); a data error (SQLSTATE class 22) is a silent refusal (M-3 depth)
 *  4  silent refusals — no row · inactive · a non-active workspace · an identity seal that does not match (then at most one
 *     `auth.credential.identity_mismatch` per user per UTC day) · a seal under ANOTHER key id: parked in `failed`, never
 *     retried, operator log naming both key ids (T-G75-4)
 *  5  the per-user advisory lock (L-5), then the cap: ≥ 3 links in the trailing 24 h → commit, acknowledge, nothing sent
 *  6  mint: 16 random bytes of selector, 32 of verifier; `created_at` = the worker's clock **truncated to the second**
 *     (the MAC encodes `expires_at` in whole seconds), `expires_at` = + 30 minutes
 *  7  INSERT the token; a duplicate `message_id` (a redelivery after a lost acknowledgement) → roll back, acknowledge
 *  8  `auth.password_reset.issued`
 *  9  send through `TransportInterface` — never `MailerInterface`, which could dispatch (H-2) — to the **stored** email,
 *     inside the transaction; a transport failure rolls back and is retried by the bus (3×, then `failed`)
 * 10  COMMIT. A commit that fails after a send leaves one dead link; the user asks again.
 *
 * **Never logged, never queued, never in the trail:** the token, the verifier, the link or the email. The SMTP socket
 * timeout is the worker's `default_socket_timeout`, set to 10 s at start by {@see WorkerKeyCheck} (L-3): the send holds
 * the transaction and this identity's only pooled server connection (`max_user_connections = 1`).
 */
#[AsMessageHandler]
final readonly class PasswordResetRequestedHandler
{
    /** D-U2 (PO/UX TO CONFIRM): how long a link works. */
    public const LINK_LIFETIME_SECONDS = 1800;

    /** The per-person cap: links in the trailing 24 hours (`RESIDUAL_GAPS.md`, a stranger can spend it — Q-M5). */
    public const LINKS_PER_DAY = 3;

    public const CAP_WINDOW_SECONDS = 86400;

    /** The advisory-lock class id for the per-user cap (≠ `RoleAdministrationLock::CLASS_ID`; a test pins them distinct). */
    public const CLASS_ID = 7002;

    public const PURPOSE = 'reset';

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

    public function __invoke(PasswordResetRequested $message): void
    {
        $messageId = $message->messageId->value;
        $this->worker->beginTransaction();

        try {
            $this->handle($message, $messageId);
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

    private function handle(PasswordResetRequested $message, string $messageId): void
    {
        // --- 1 ------------------------------------------------------------
        $tenant = $this->worker->fetchAssociative('SELECT id, status FROM tenant WHERE slug = ?', [$message->workspace->value]);
        if (false === $tenant) {
            return;
        }
        $tenantId = (string) $tenant['id'];

        // --- 2 ------------------------------------------------------------
        $this->tenants->establishOn($this->worker, $tenantId);

        // --- 3 ------------------------------------------------------------
        try {
            $user = $this->worker->fetchAssociative(
                'SELECT id, email, is_active, identity_mac FROM app_user WHERE email = ? AND deleted_at IS NULL',
                [$message->email->value],
            );
        } catch (DriverException $e) {
            if (str_starts_with((string) $e->getSQLState(), '22')) {
                // A value the VO should have refused reached the lookup (M-3 depth): acknowledge, never retry.
                $this->worker->rollBack();
                $this->logger->warning('A password reset request was dropped: its address could not be looked up.', ['message_id' => $messageId]);

                return;
            }

            throw $e;
        }

        // --- 4 ------------------------------------------------------------
        if (false === $user || true !== (bool) $user['is_active'] || !Tenant::statusPermitsLogin((string) $tenant['status'])) {
            return;
        }
        $userId = (string) $user['id'];
        $storedEmail = (string) $user['email'];
        $ip = $message->clientIp?->value;

        $now = $this->clock->now();
        $seal = $this->seals->checkIdentity($tenantId, $userId, $storedEmail, null === $user['identity_mac'] ? null : (string) $user['identity_mac']);
        if (SealCheck::UnknownKey === $seal) {
            $stored = (string) $user['identity_mac'];
            $this->worker->rollBack();
            $this->logger->error('A password reset request was parked: the account\'s identity seal was made with another key.', [
                'message_id' => $messageId,
                'stored_key_id' => substr($stored, 3, 8),
                'configured_key_id' => $this->seals->keyId(),
            ]);

            throw new UnrecoverableMessageHandlingException('The identity seal carries a key id this worker does not hold.');
        }
        if (SealCheck::Mismatch === $seal) {
            $dayStart = new \DateTimeImmutable('@'.($now->getTimestamp() - ($now->getTimestamp() % 86400)));
            if (!$this->audit->identityMismatchRecordedSince($userId, $dayStart)) {
                $this->audit->recordIdentityMismatch($tenantId, $userId, $storedEmail, $ip, $messageId, $now);
            }

            return;
        }

        // --- 5 ------------------------------------------------------------
        $this->worker->executeStatement('SELECT pg_advisory_xact_lock(?, hashtext(?::uuid::text))', [self::CLASS_ID, $userId]);
        $since = new \DateTimeImmutable('@'.($now->getTimestamp() - self::CAP_WINDOW_SECONDS));
        $recent = (int) $this->worker->fetchOne(
            'SELECT count(*) FROM password_reset_token WHERE user_id = ?::uuid AND created_at > ?::timestamptz',
            [$userId, $since->format('Y-m-d H:i:sP')],
        );
        if ($recent >= self::LINKS_PER_DAY) {
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
                // A redelivery after a lost acknowledgement: this message already minted its link.
                $this->worker->rollBack();

                return;
            }

            throw $e;
        }

        // --- 8 ------------------------------------------------------------
        $this->audit->recordIssued($tenantId, $userId, $storedEmail, $ip, $messageId, $now);

        // --- 9 ------------------------------------------------------------
        if ('' === $this->from || '' === $this->publicUrl) {
            throw new \LogicException('MAILER_FROM and APP_PUBLIC_URL must be set for the mail worker (.env.worker).');
        }
        $token = rtrim(strtr(base64_encode(Uuid::fromString($tenantId)->toBinary().$selector.$verifier), '+/', '-_'), '=');
        $link = rtrim($this->publicUrl, '/').'/reset-password#token='.$token;
        $email = (new Email())
            ->from(new Address($this->from))
            ->to(new Address($storedEmail))
            ->subject('Reset your password')
            ->text(self::body($message->workspace->value, $link));

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->worker->rollBack();
            $this->logger->warning('A password reset mail could not be sent; it will be retried.', ['message_id' => $messageId]);

            throw $e;
        }
        // --- 10: the caller commits.
    }

    /** UX TO CONFIRM — placeholder copy. Plain text: no template engine is installed. */
    private static function body(string $workspace, string $link): string
    {
        return "Someone asked to reset the password for your account in the workspace \"$workspace\".\n\n"
            ."To choose a new password, open this link. It works once, for 30 minutes:\n\n$link\n\n"
            ."If you did not ask for this, ignore this message. Your password has not changed.\n";
    }
}

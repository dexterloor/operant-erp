<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Modules\AuditLog\Infrastructure\UserAuditWriter;
use App\Modules\UserManagement\Application\Contract\Message\IssueInvitation;
use App\Modules\UserManagement\Domain\ChecksMailAddresses;
use App\Modules\UserManagement\Domain\EmailAddress;
use App\Modules\UserManagement\Domain\MessageId;
use App\Modules\UserManagement\Domain\SealsIdentities;
use App\Modules\UserManagement\Domain\UserId;
use App\Modules\UserManagement\Domain\VerifiesPasswords;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\Identity;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\DBAL\Exception\CheckConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`CreateUser` — add a person, who then sets their own password from a mailed link** (U-C1b; `docs/session13/u-c1-build-plan.md`
 * Revision 2 FINAL R2.2 as fixed by `u-c1-final-red.md`; `API.md` §4.3a). `POST /users`, `app_user.create`, **201** +
 * `Location` + `GetUser`'s body. `unique:app_user_tenant_email_live`: the header is demanded (428) and a repeat is refused by
 * the live-email unique (409 `app_user.email_taken`), never replayed.
 *
 *  1. **Validate (422), before any statement:** `/email` — `EmailAddress::fromInput()` (the ONE address predicate, stored
 *     form), THEN the create-only rule {@see ChecksMailAddresses} on that normalised value (the worker's own `new Address()`
 *     plus no `\p{Z}`; L-3, final RED M-2), never folded into `EmailAddress`; `/display_name` — {@see UserDisplayName}.
 *  2. **A person who cannot sign in (§4.3a):** a fresh id, `password_hash` = a KDF of 32 random bytes whose secret is
 *     discarded, `password_mac` NULL (not insertable: the seal fails closed at sign-in), `identity_mac` = the keyed seal over
 *     (tenant, id, stored email). **The KDF runs outside every transaction** (RC-2): it never holds `erp_app`'s pooled server
 *     connection.
 *  3. **One transaction:** `RoleAdministrationLock` FIRST (D-U4′, spar M-1: it serialises against `RestoreUser`'s email
 *     check and a concurrent deactivation of the actor), the actor re-checked under it with `app_user.create` (a
 *     {@see LockTimeDenial} returned out of `run()`, an inactive actor 409 `user_role.actor_is_inactive`), the ONE DBAL
 *     `INSERT` naming exactly the six granted columns (the ORM would name `password_mac`, which `erp_app` must never insert),
 *     the read-back, then `user.created` (`{"fields":["display_name","email"]}`, field names only — D-U6, `SEC` TO CONFIRM).
 *  4. **Outside `run()`:** `23505` on `app_user_tenant_email_live` → 409 `app_user.email_taken` (the code `RestoreUser`
 *     already ships for the same fact); `23514` on `app_user_email_normalised` → 422 `/email` (RC-3, a backstop: no such
 *     input exists on the local collation).
 *  5. **After COMMIT, in a short tenancy transaction of its own (D-U3):** dispatch {@see IssueInvitation} — ids only. Inside
 *     the create transaction a fast worker could read before the commit and acknowledge an invitation for nobody. A
 *     dispatch failure is logged (no email, no exception message) and the 201 stands (`CreatePasswordReset`'s precedent;
 *     residual: the person exists with no link — "Forgot your password?" recovers it).
 *  6. 201.
 */
final readonly class UserCreation
{
    /** The permission this operation requires — the gate's, re-derived under the lock. */
    public const REQUIRED_PERMISSION = 'app_user.create';

    /** PLACEHOLDER — `UX` to confirm. Names no person (the caller typed the address). */
    public const EMAIL_TAKEN = 'Someone in this workspace already uses this email address.';

    /** PLACEHOLDER — `UX` to confirm. */
    public const EMAIL_INVALID = 'Enter an email address.';

    /** PLACEHOLDER — `UX` to confirm (L-3 / N-9). */
    public const EMAIL_UNDELIVERABLE = 'This email address can\'t receive mail from us. Check it for spaces or hidden characters.';

    public const INSERT = <<<'SQL'
        INSERT INTO app_user (id, tenant_id, email, password_hash, display_name, identity_mac)
        VALUES (?::uuid, ?::uuid, ?, ?, ?, ?)
        SQL;

    private const EMAIL_UNIQUE = 'app_user_tenant_email_live';

    private const EMAIL_NORMALISED = 'app_user_email_normalised';

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private RoleAdministrationActor $actor,
        private UserAuditWriter $audit,
        private UserReader $users,
        private VerifiesPasswords $passwords,
        private SealsIdentities $seals,
        private ChecksMailAddresses $addresses,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed>          $submitted     the body's members, keyed by wire name
     * @param array{0: ?string, 1: ?string} $denialSubject what `PermissionGate::denialSubjectOf()` resolves for this request
     */
    public function create(array $submitted, ?string $ipAddress, array $denialSubject): UserView
    {
        // 1.
        [$email, $displayName] = $this->validated($submitted);
        $identity = $this->identities->authenticated();
        $tenantId = $this->tenant->tenantId();

        // 2.
        $userId = Uuid::v4()->toRfc4122();
        $hash = $this->passwords->hash(bin2hex(random_bytes(32)));
        $identityMac = $this->seals->sealIdentity($tenantId, $userId, $email);

        // 3.
        try {
            $outcome = $this->tenants->run(
                $tenantId,
                fn (EntityManagerInterface $em): UserView|LockTimeDenial => $this->createInside($em, $identity, $tenantId, $userId, $email, $hash, $displayName, $identityMac, $ipAddress, $denialSubject),
            );
        } catch (UniqueConstraintViolationException $e) {
            // 4. Outside `run()`: the failed INSERT aborted the transaction. The id is minted here, so the email is the one
            // unique a create can hit.
            if (str_contains($e->getMessage(), self::EMAIL_UNIQUE)) {
                throw ApiProblem::businessRuleViolated('app_user.email_taken', self::EMAIL_TAKEN);
            }

            throw $e;
        } catch (CheckConstraintViolationException $e) {
            if (str_contains($e->getMessage(), self::EMAIL_NORMALISED)) {
                throw ApiProblem::validationFailed([RoleDefinitionFields::error('/email', 'invalid_format', self::EMAIL_INVALID)]);
            }

            throw $e;
        }

        if ($outcome instanceof LockTimeDenial) {
            // Outside `run()`, after the commit: the gate's own factory, so the body is byte-identical to its 403.
            throw ApiProblem::permissionDenied($outcome->permissionCode);
        }

        // 5.
        $messageId = MessageId::new();
        try {
            $this->tenants->run($tenantId, function () use ($userId, $messageId): void {
                $this->bus->dispatch(new IssueInvitation(new UserId($userId), $messageId));
            });
        } catch (\Throwable $e) {
            // Never the address or the exception message (which can quote it).
            $this->logger->error('An invitation could not be queued; the person can use "Forgot your password?".', [
                'user_id' => $userId,
                'message_id' => $messageId->value,
                'exception_class' => $e::class,
            ]);
        }

        // 6.
        return $outcome;
    }

    /** @param array{0: ?string, 1: ?string} $denialSubject */
    private function createInside(EntityManagerInterface $em, Identity $identity, string $tenantId, string $userId, string $email, string $hash, string $displayName, string $identityMac, ?string $ipAddress, array $denialSubject): UserView|LockTimeDenial
    {
        RoleAdministrationLock::holdInside($em, $tenantId);

        $actorCodes = $this->actor->reCheckUnderTheLock($em, $identity, $ipAddress, $denialSubject, self::REQUIRED_PERMISSION);
        if ($actorCodes instanceof LockTimeDenial) {
            return $actorCodes;
        }

        $em->getConnection()->executeStatement(self::INSERT, [$userId, $tenantId, $email, $hash, $displayName, $identityMac]);

        $view = $this->users->get($userId) ?? throw new \LogicException('The person just inserted is not visible to its own transaction.');

        $this->audit->recordCreated($identity->tenantId, $identity->userId, $identity->actorLabel, $userId, $ipAddress);

        return $view;
    }

    /**
     * @param array<string, mixed> $submitted
     *
     * @return array{0: string, 1: string} the stored email and display name
     */
    private function validated(array $submitted): array
    {
        $errors = [];
        $email = null;

        $raw = $submitted['email'] ?? null;
        if (null === $raw) {
            $errors[] = RoleDefinitionFields::error('/email', 'required', 'This field is required.');
        } elseif (!\is_string($raw)) {
            $errors[] = RoleDefinitionFields::error('/email', 'not_a_string', 'This field must be a string.');
        } else {
            try {
                $email = EmailAddress::fromInput($raw)->value;
            } catch (\InvalidArgumentException) {
                $errors[] = RoleDefinitionFields::error('/email', 'invalid_format', self::EMAIL_INVALID);
            }
            if (null !== $email && !$this->addresses->accepts($email)) {
                $errors[] = RoleDefinitionFields::error('/email', 'undeliverable', self::EMAIL_UNDELIVERABLE);
                $email = null;
            }
        }

        [$displayName, $nameError] = UserDisplayName::check($submitted['display_name'] ?? null);
        if (null !== $nameError) {
            $errors[] = $nameError;
        }

        if ([] !== $errors || null === $email || null === $displayName) {
            throw ApiProblem::validationFailed($errors);
        }

        return [$email, $displayName];
    }
}

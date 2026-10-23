<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Infrastructure;

/**
 * What `API.md` §7.1 step 4 read, and everything the rest of the sequence is
 * allowed to know.
 *
 * Steps 6 and 6a *"cost no statement"* precisely because this object carries
 * `tenantStatus` and `userIsActive` out of the transaction with it. An
 * implementation that re-read `tenant` to check status would add a statement to
 * one branch and break E-7 by the clock, which is the shape E-7's *Violated by*
 * names.
 */
final readonly class CredentialRow
{
    public function __construct(
        /** The resolved tenant, or `SchemaManifest::SENTINEL_TENANT_ID` on a slug miss. */
        public string $tenantId,
        public string $tenantStatus,
        public bool $tenantResolved,
        public ?string $userId,
        public ?string $passwordHash,
        /** Cred-C1: the stored seal, or null (no row, or a row nobody sealed — refused either way). */
        public ?string $passwordMac,
        public bool $userIsActive,
        /** The address as `app_user` stores it — never the one the client sent. */
        public ?string $storedEmail,
    ) {
    }

    /**
     * ADR-006 §2.2's first two rows collapse here: a slug that does not resolve
     * and an email that matches no `app_user` are both *"counters only"*, and
     * neither reaches the trail.
     */
    public function isResolvedPair(): bool
    {
        return $this->tenantResolved && null !== $this->userId;
    }
}

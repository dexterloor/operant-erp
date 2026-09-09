<?php

declare(strict_types=1);

namespace App\Auth\Application;

use App\Entity\AppUser;
use App\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * `API.md` §7.1 step 9 — `last_login_at`, on **`erp_app`**, on the success
 * branch only and **after the JWT has been minted**.
 *
 * ---------------------------------------------------------------------------
 * Why this is the only thing here that touches the application principal
 * ---------------------------------------------------------------------------
 * ADR-006 §2.1: *"the successful-login follow-on happens on `erp_app` **after**
 * the JWT is minted, i.e. on an authenticated path"*. Everything before it runs
 * on a principal that can do one thing. This runs on the principal that can do
 * everything, which is admissible exactly because authentication has already
 * succeeded — and it is why `SignIn` calls it last rather than folding it into
 * the success branch's other writes.
 *
 * **Step 9 records no session.** §7.1's revision 1 said *"session record,
 * `last_login_at`"* and `SchemaManifest::TABLES` declares no session table, so
 * the phrase asserted storage that does not exist. D-14 does not ship one, and
 * the consequence — a minted token cannot be revoked before `exp` — is
 * **API-O-13**, not something this class quietly covers.
 *
 * `AppUser::recordLogin()` is called rather than an `UPDATE` written here: the
 * entity's own docblock carries the rule that *"nothing on this class may be
 * written before [authentication has succeeded]"*, and a hand-written statement
 * would put this path outside the class that states it.
 */
final readonly class LoginRecorder
{
    public function __construct(private TenantContext $tenants, private EntityManagerInterface $em)
    {
    }

    public function record(string $tenantId, string $userId, \DateTimeImmutable $at): void
    {
        $this->tenants->run($tenantId, function () use ($userId, $at): void {
            $user = $this->em->find(AppUser::class, Uuid::fromString($userId));

            // Null is reachable and is not an error: RLS filters this read, and
            // between step 4 and here the row can have been removed. The token
            // is already minted and valid; failing the request now would refuse
            // a sign-in that succeeded.
            if ($user instanceof AppUser) {
                $user->recordLogin($at);
                $this->em->flush();
            }
        });
    }
}

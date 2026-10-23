<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Infrastructure\Http;

use App\Modules\UserManagement\Domain\SealsCredentials;
use App\Modules\UserManagement\Infrastructure\CredentialSeal;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * **Production refuses to serve without a matching `AUTH_CREDENTIAL_MAC_KEY_ID`** (Mail-C1, `mail-c1-build-plan.md` R1.4
 * item 4; RED M-1; G-75 T-G75-2's serving half). In `prod` only, on the first main request of the process, the current
 * credential seal is built, which forces its constructor's two refusals: no declared key id, or one that is not the key's
 * id. Either way every route is answered **503** — `ServingCredentialGuard`'s documented meaning of "refuses to serve" —
 * and one line naming the variable (never a value) goes to the error log, until it is fixed.
 *
 * A listener rather than a check in `public/index.php`, because matching the id needs the key's HKDF (RED M-1). It takes
 * the seal as a closure so a refusing constructor is caught here, not turned into a 500. *ARCH TO CONFIRM placement.*
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4096)]
final class CredentialKeyBootCheck
{
    public const MARKER = 'CREDENTIAL_KEY_BOOT_CHECK: refused';

    private static bool $verified = false;

    /** @param \Closure(): SealsCredentials $seal */
    public function __construct(
        #[AutowireServiceClosure(SealsCredentials::class)]
        private readonly \Closure $seal,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if ('prod' !== $this->environment || self::$verified || !$event->isMainRequest()) {
            return;
        }

        try {
            ($this->seal)();
            self::$verified = true;
        } catch (\Throwable) {
            error_log(self::MARKER.' — '.CredentialSeal::KEY_ID_NAME.' is missing or does not match AUTH_CREDENTIAL_MAC_KEY');

            // An HttpKernel exception, not a hand-built Response: this layer may not name HttpFoundation (deptrac), and the
            // framework renders it as a 503 on every route, before routing.
            throw new ServiceUnavailableHttpException(null, 'This server is not configured to serve.');
        }
    }
}

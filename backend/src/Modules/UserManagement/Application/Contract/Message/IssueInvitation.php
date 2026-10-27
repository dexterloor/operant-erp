<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application\Contract\Message;

use App\Modules\UserManagement\Domain\MessageId;
use App\Modules\UserManagement\Domain\UserId;
use App\Platform\Messaging\TenantMessage;

/**
 * **Mail a newly added person the link that sets their first password** (U-C1b; `docs/session13/u-c1-build-plan.md` Revision 2
 * FINAL R2.2). Dispatched by `CreateUser` AFTER its transaction commits, in a short tenancy transaction of its own (D-U3), so
 * `TenantStampMiddleware` stamps the workspace; handled by the mail worker on `erp_worker`, behind the tenant-status gate.
 *
 * **Ids only (D-U5; H-2):** no email (the worker reads the STORED address — `RESIDUAL_GAPS.md` G-79), and no inviter: the
 * trail never takes a name from the queue (`requested_by` is the handler's literal `"invitation"`). `messageId` makes a
 * redelivery mint nothing (the per-account cap answers first, RC-5).
 */
final readonly class IssueInvitation implements TenantMessage
{
    public function __construct(
        public UserId $user,
        public MessageId $messageId,
    ) {
    }
}

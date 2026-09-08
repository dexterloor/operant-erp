<?php

declare(strict_types=1);

namespace App\Api\Tenancy;

/**
 * The tenant the current request acts as.
 *
 * T-1 and API-7: no route, query parameter, header or request-body field may
 * carry a tenant identifier. The tenant comes from the signed JWT claim, read
 * server-side. There is no authentication stack in the tree yet -- no firewall,
 * no token -- so this interface is the seam the auth output (§7) fills, and it
 * exists now so that the resources written against it never grow a request-borne
 * tenant parameter that would later have to be removed.
 */
interface CurrentTenant
{
    /** @return string the tenant id, in RFC 4122 form */
    public function tenantId(): string;
}

<?php

declare(strict_types=1);

namespace App\Platform\Messaging;

/**
 * A message dispatched **inside a workspace** (Mail-C0; design §4.1). {@see TenantStampMiddleware} stamps it with the
 * dispatching transaction's workspace and refuses it outside one; at handling, a missing or malformed stamp is
 * unrecoverable. The first such class in {@see MessageCatalogue::ALL} also owes the tenant-status gate and its test
 * (`MessengerConfigurationTest::aTenantMessageInTheCatalogueRequiresTheTenantStatusGate`).
 */
interface TenantMessage
{
}

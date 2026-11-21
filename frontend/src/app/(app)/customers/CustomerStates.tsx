import { NotFound, PermissionDenied } from '@/components/states/PageStates';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { CUSTOMERS_LIST } from '@/copy/customers';
import { gateCode } from '@/lib/bff/surface';

// The customer pages' ST-4b and ST-5 (the supplier pages' shape): REUSE the RULED page-state templates. The permission
// named is the list's gate, read from the contract (`ListCustomers` and `GetCustomer` share it).

export function CustomersDenied() {
  const code = gateCode('ListCustomers');
  return <PermissionDenied resourcePlural={CUSTOMERS_LIST.resourcePlural} permissionName={(code !== null ? PERMISSION_LABELS[code]?.label : undefined) ?? ''} />;
}

export function CustomerNotFound() {
  return <NotFound listHref="/customers" />;
}

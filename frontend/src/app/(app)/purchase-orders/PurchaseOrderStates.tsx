import { NotFound, PermissionDenied } from '@/components/states/PageStates';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { PURCHASE_ORDERS_LIST } from '@/copy/purchasing';
import { gateCode } from '@/lib/bff/surface';

// The purchase-order pages' ST-4b and ST-5 (purchase-orders-ux-copy.md B-0 `resourcePlural`): REUSE the RULED
// page-state templates. The permission named is the list's gate, read from the contract (`ListPurchaseOrders`,
// `GetPurchaseOrder` and `ListPurchaseOrderLines` share it).

export function PurchaseOrdersDenied() {
  const code = gateCode('ListPurchaseOrders');
  return <PermissionDenied resourcePlural={PURCHASE_ORDERS_LIST.resourcePlural} permissionName={(code !== null ? PERMISSION_LABELS[code]?.label : undefined) ?? ''} />;
}

export function PurchaseOrderNotFound() {
  return <NotFound listHref="/purchase-orders" />;
}

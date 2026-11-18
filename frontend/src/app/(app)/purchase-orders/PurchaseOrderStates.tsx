import { NotFound, PermissionDenied } from '@/components/states/PageStates';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { GOODS_RECEIPTS_LIST, NEW_PURCHASE_ORDER, PURCHASE_ORDERS_LIST } from '@/copy/purchasing';
import { gateCode } from '@/lib/bff/surface';

// The purchase-order pages' ST-4b and ST-5 (purchase-orders-ux-copy.md B-0 `resourcePlural`): REUSE the RULED
// page-state templates. The permission named is the list's gate, read from the contract (`ListPurchaseOrders`,
// `GetPurchaseOrder` and `ListPurchaseOrderLines` share it).

export function PurchaseOrdersDenied() {
  const code = gateCode('ListPurchaseOrders');
  return <PermissionDenied resourcePlural={PURCHASE_ORDERS_LIST.resourcePlural} permissionName={(code !== null ? PERMISSION_LABELS[code]?.label : undefined) ?? ''} />;
}

/**
 * PO-3: the operations `/purchase-orders/new` needs beyond GetPurchaseOrder, in the order they are checked — creating, and
 * the two reads its choices need (plan §2.4: without either, ST-4b, never an empty choice). The list offers its create
 * links only to a viewer who holds all of them (and GetPurchaseOrder), so a link never leads to ST-4b.
 */
export const NEW_ORDER_GATES = ['CreatePurchaseOrder', 'ListSuppliers', 'ListWarehouses'] as const;

/** `/purchase-orders/new` without one of `NEW_ORDER_GATES` (UX B-2 `deniedResource`): ST-4b naming that operation's permission. */
export function NewPurchaseOrderDenied({ operation }: { operation: (typeof NEW_ORDER_GATES)[number] }) {
  const code = gateCode(operation);
  return <PermissionDenied resourcePlural={NEW_PURCHASE_ORDER.deniedResource} permissionName={(code !== null ? PERMISSION_LABELS[code]?.label : undefined) ?? ''} />;
}

/**
 * GR-2 (R1-3, UX S-7): the operations `/purchase-orders/[id]/receive` needs beyond GetPurchaseOrder, in the order they are
 * checked — the lines read (gr-2-red.md L-5: named, not assumed to share GetPurchaseOrder's code), recording, and the
 * locations read every row chooses from. The order page
 * offers Receive only to a viewer who holds all of them (and GetPurchaseOrder), so the link never leads to ST-4b.
 */
export const RECEIVE_GATES = ['ListPurchaseOrderLines', 'CreateGoodsReceipt', 'ListStorageLocations'] as const;

/**
 * `/purchase-orders/[id]/receive` without one of `RECEIVE_GATES`: ST-4b naming that operation's permission. Its resource is
 * `GOODS_RECEIPTS_LIST.resourcePlural` (B-0's *goods receipts*; B-8 names none — UX TO CONFIRM).
 */
export function ReceiveDenied({ operation }: { operation: (typeof RECEIVE_GATES)[number] }) {
  const code = gateCode(operation);
  return <PermissionDenied resourcePlural={GOODS_RECEIPTS_LIST.resourcePlural} permissionName={(code !== null ? PERMISSION_LABELS[code]?.label : undefined) ?? ''} />;
}

export function PurchaseOrderNotFound() {
  return <NotFound listHref="/purchase-orders" />;
}

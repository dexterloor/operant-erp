import { NotFound, PermissionDenied } from '@/components/states/PageStates';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { GOODS_RECEIPTS_LIST } from '@/copy/purchasing';
import { gateCode } from '@/lib/bff/surface';

// The goods-receipt pages' ST-4b and ST-5 (purchase-orders-ux-copy.md B-0 `resourcePlural`: "goods receipts"): REUSE the
// RULED page-state templates. The permission named is the register's gate, read from the contract (`ListGoodsReceipts`
// and `GetGoodsReceipt` share it). Revision 10 R10-2: the `purchasing` family's second route folder; it imports no other
// family's `components/` or `lib/`.

export function GoodsReceiptsDenied() {
  const code = gateCode('ListGoodsReceipts');
  return <PermissionDenied resourcePlural={GOODS_RECEIPTS_LIST.resourcePlural} permissionName={(code !== null ? PERMISSION_LABELS[code]?.label : undefined) ?? ''} />;
}

export function GoodsReceiptNotFound() {
  return <NotFound listHref="/goods-receipts" />;
}

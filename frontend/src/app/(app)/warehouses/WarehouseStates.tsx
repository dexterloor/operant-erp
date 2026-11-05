import { NotFound, PermissionDenied } from '@/components/states/PageStates';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { WAREHOUSES_LIST } from '@/copy/warehouses';
import { gateCode } from '@/lib/bff/surface';

// The warehouse pages' ST-4b and ST-5 (warehouses-ux-copy.md B-1 403 row): REUSE the RULED page-state templates. The
// permission named is the list's gate, read from the contract (`ListWarehouses` and `GetWarehouse` share it).

export function WarehousesDenied() {
  const code = gateCode('ListWarehouses');
  return <PermissionDenied resourcePlural={WAREHOUSES_LIST.resourcePlural} permissionName={(code !== null ? PERMISSION_LABELS[code]?.label : undefined) ?? ''} />;
}

export function WarehouseNotFound() {
  return <NotFound listHref="/warehouses" />;
}

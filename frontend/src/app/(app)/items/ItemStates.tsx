import { NotFound, PermissionDenied } from '@/components/states/PageStates';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { ITEMS_LIST } from '@/copy/items';
import { gateCode } from '@/lib/bff/surface';

// The item pages' ST-4b and ST-5 (items-ux-copy.md B-1 403 row, B-3 404 row): REUSE the RULED page-state templates. The
// permission named is the list's gate, read from the contract (`ListItems` and `GetItem` share it).

export function ItemsDenied() {
  const code = gateCode('ListItems');
  return <PermissionDenied resourcePlural={ITEMS_LIST.resourcePlural} permissionName={(code !== null ? PERMISSION_LABELS[code]?.label : undefined) ?? ''} />;
}

export function ItemNotFound() {
  return <NotFound listHref="/items" />;
}

import { NotFound, PermissionDenied } from '@/components/states/PageStates';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { NEW_SUPPLIER, SUPPLIERS_LIST } from '@/copy/suppliers';
import { gateCode } from '@/lib/bff/surface';

// The supplier pages' ST-4b and ST-5 (suppliers-ux-copy.md Revision 1 S-1 `resourcePlural`; Revision 0 B-1 403, B-3 404):
// REUSE the RULED page-state templates. The permission named is the list's gate, read from the contract (`ListSuppliers`
// and `GetSupplier` share it).

export function SuppliersDenied() {
  const code = gateCode('ListSuppliers');
  return <PermissionDenied resourcePlural={SUPPLIERS_LIST.resourcePlural} permissionName={(code !== null ? PERMISSION_LABELS[code]?.label : undefined) ?? ''} />;
}

/** `/suppliers/new` without `CreateSupplier`'s codes (UX S-2 `deniedResource`): ST-4b naming the create permission. */
export function SupplierCreateDenied() {
  const code = gateCode('CreateSupplier');
  return <PermissionDenied resourcePlural={NEW_SUPPLIER.deniedResource} permissionName={(code !== null ? PERMISSION_LABELS[code]?.label : undefined) ?? ''} />;
}

export function SupplierNotFound() {
  return <NotFound listHref="/suppliers" />;
}

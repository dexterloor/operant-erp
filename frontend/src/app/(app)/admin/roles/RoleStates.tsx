import { NotFound, PermissionDenied } from '@/components/states/PageStates';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { ROLES_LIST } from '@/copy/roles';
import { gateCode } from '@/lib/bff/surface';

// The roles pages' ST-4b and ST-5 (ux-copy-b3b.md C7-1 403 row, C7-2): REUSE the RULED page-state templates. The
// permission named is the list's gate, read from the contract (`ListRoles` and `GetRole` share it).

export function RolesDenied() {
  const code = gateCode('ListRoles');
  return <PermissionDenied resourcePlural={ROLES_LIST.resourcePlural} permissionName={(code !== null ? PERMISSION_LABELS[code]?.label : undefined) ?? ''} />;
}

export function RoleNotFound() {
  return <NotFound listHref="/admin/roles" />;
}

import { NotFound, PermissionDenied } from '@/components/states/PageStates';
import { USERS_LIST } from '@/copy/people';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { gateCode } from '@/lib/bff/surface';

// The people pages' ST-4b and ST-5 (ux-copy-b3b.md C5-1 403 row, C5-2 404 row): REUSE the RULED page-state templates.
// The permission named is the list's own gate, read from the contract (`ListUsers` and `GetUser` share it), labelled
// from `copy/permissionLabels.ts` — never typed here.

export function PeopleDenied() {
  const code = gateCode('ListUsers');
  return <PermissionDenied resourcePlural={USERS_LIST.resourcePlural} permissionName={(code !== null ? PERMISSION_LABELS[code]?.label : undefined) ?? ''} />;
}

export function PersonNotFound() {
  return <NotFound listHref="/admin/users" />;
}

import { useRef, useState } from 'react';
import type { Permission } from '@/lib/api/permissions';
import type { Role } from '@/lib/api/roles';
import type { SaveRoleResult } from '@/lib/permissions/results';
import { PermissionChecklist } from '@/components/permissions/PermissionChecklist';
import { PERMISSIONS } from '@/components/preview/fixtures/permissions';

// A route-shaped wrapper for the checklist tests (not a test file): owns the <h1> and the role prop, and re-reads the
// role (replaces the prop with the save's 200 body) after a successful save, as the caller contract says.

export type SaveAnswer = SaveRoleResult | ((codes: readonly string[]) => SaveRoleResult | Promise<SaveRoleResult>) | 'throw';

export interface ChecklistHarnessProps {
  role: Role;
  permissions?: readonly Permission[];
  canEdit?: boolean;
  /** Default: saved, echoing the sent codes with the role's own margin flag. */
  answer?: SaveAnswer;
  onSave?: (codes: readonly string[]) => void;
  /** False: the caller never re-reads after a save (to see what the 200 alone does). */
  reread?: boolean;
  /** R-15: the checklist's report after a saved message (the page decides whether to refresh). */
  onSaved?: () => void;
}

export function ChecklistHarness({ role: initial, permissions = PERMISSIONS, canEdit = true, answer, onSave, reread = true, onSaved }: ChecklistHarnessProps) {
  const h1 = useRef<HTMLHeadingElement>(null);
  const [role, setRole] = useState(initial);
  return (
    <main>
      <h1 ref={h1} tabIndex={-1}>
        {role.name}
      </h1>
      <PermissionChecklist
        role={role}
        permissions={permissions}
        canEdit={canEdit}
        fallbackFocusRef={h1}
        onSaved={onSaved}
        save={async (codes) => {
          onSave?.(codes);
          await new Promise((r) => setTimeout(r, 5));
          if (answer === 'throw') throw new Error('network');
          const a = answer ?? { kind: 'saved', role: { ...role, permission_codes: [...codes] } };
          const r = typeof a === 'function' ? await a(codes) : a;
          if (r.kind === 'saved' && reread) setRole({ ...r.role });
          return r;
        }}
      />
    </main>
  );
}

import type { Role } from '../api/roles';
import type { ApiCaller } from '../bff/apiCall';
import { ACTION_DEADLINES_MS, startDeadline } from '../bff/deadline';
import { readOf, type ReadFailure } from '../bff/reads';
import { loadRole } from './load';
import type { SaveRoleAction } from './results';

// The checklist's Save changes over the BFF (b3b-design.md §9.4; replaces `unwired.ts` on the role page): PATCH the
// whole set, sorted, as `application/json` (`patch_formats: json`). `UpdateRole`'s `Idem` is `no` and sound: the edit
// is a SET, so a repeat writes nothing (lib/permissions/results.ts). One 30 s deadline covers the request AND the GetRole
// re-read (§6.2 R-2 item 2). Caller contract: after `saved` the role is re-read and passed as the new prop — the
// checklist is never unmounted.

export interface RoleSaveWiring {
  readonly caller: ApiCaller;
  readonly id: string;
  /** Sets the re-read role and resolves after the commit that shows it. */
  readonly setRole: (role: Role) => Promise<void>;
  /** The re-read after a save did not answer a role: the page shows its region error, or swaps (ST-4b / ST-5). */
  readonly onRereadFailed: (failure: ReadFailure) => void;
}

const isRole = (x: unknown): x is Role => !!x && typeof x === 'object' && typeof (x as Role).id === 'string' && Array.isArray((x as Role).permission_codes);

export function roleSave(w: RoleSaveWiring): SaveRoleAction {
  return async (permissionCodes) => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.checklistSave);
    try {
      const r = await w.caller.call('UpdateRole', { id: w.id }, { permission_codes: [...permissionCodes].sort() }, { signal: deadline.signal });
      if (r.kind !== 'ok') {
        switch (r.kind) {
          case 'refused':
          case 'not-found':
          case 'forbidden':
          case 'session-ended':
          case 'unreachable':
          case 'server-error':
            return r;
          default:
            return { kind: 'server-error' };
        }
      }
      if (!isRole(r.body)) return { kind: 'server-error' };
      // The 200 is what the checklist shows until the re-read's role arrives (its served `margin_exposure` included).
      const again = await loadRole(readOf(w.caller), w.id, deadline.signal);
      if (again.kind === 'ok') await w.setRole(again.role);
      else w.onRereadFailed(again.kind);
      return { kind: 'saved', role: r.body };
    } finally {
      deadline.cancel();
    }
  };
}

import { useLayoutEffect, useRef, useState } from 'react';
import type { Collection } from '@/lib/api/envelope';
import type { RoleGrantProposal } from '@/lib/api/proposals';
import type { Permission } from '@/lib/api/permissions';
import type { OfferedRole, Role } from '@/lib/api/roles';
import type { User } from '@/lib/api/users';
import type { AssignResult, ConfirmResult, ProposeResult, RejectResult, RereadResult, RevokeResult, WithdrawResult } from '@/lib/roles/results';
import { RolePicker } from '@/components/roles/RolePicker';
import { RoleProposals } from '@/components/roles/RoleProposals';
import { TooltipProvider } from '@/components/ui/Tooltip';
import { PERMISSIONS } from '@/components/preview/fixtures/permissions';
import { ROLES, VIEWER_ID, page } from '@/components/preview/fixtures/roles';

// A route-shaped wrapper for the picker tests (not a test file). It owns the <h1>, composes the two siblings as a person
// page would (RED M-3), and plays the caller contract: `reread()` sets the new props, THEN resolves (Revision 1 "State").
// `pendingRoleIds` is derived from the scene's whole pending list (A-1: the full walk, never the visible page).

export interface Scene {
  person: User;
  offered: OfferedRole[];
  proposals: Collection<RoleGrantProposal>;
}

type Answer<T> = T | ((id: string) => T | Promise<T>) | 'throw';

export interface PickerHarnessProps {
  scene: Scene;
  viewerId?: string;
  /** False: the viewer holds `role.assign` but not `role.view` (R4-2). */
  roleView?: boolean;
  canConfirm?: boolean;
  /** Hide the Waiting group (the page decides; own page never shows it). */
  showProposals?: boolean;
  assign?: Answer<AssignResult>;
  propose?: Answer<ProposeResult>;
  revoke?: Answer<RevokeResult>;
  confirm?: Answer<ConfirmResult>;
  reject?: Answer<RejectResult>;
  withdraw?: Answer<WithdrawResult>;
  /** What each re-read loads: a new scene (or the same one), or a failure. Called once per re-read; may be held open. */
  reread?: (current: Scene, n: number) => Scene | RereadResult | Promise<Scene | RereadResult>;
  log?: string[];
  /** `ListRoles` / `ListPermissions` (default: the fixture's). The invariance test passes renamed copies. */
  roles?: readonly Role[];
  permissions?: readonly Permission[];
  onPage?: (direction: 'after' | 'before', cursor: string) => void;
  /** R-15: the picker's and the group's reports (the page refreshes its router cache on each). */
  onRolesChanged?: () => void;
  onProposalChanged?: () => void;
}

const delay = () => new Promise((r) => setTimeout(r, 5));

async function answer<T>(a: Answer<T> | undefined, id: string, fallback: T): Promise<T> {
  await delay();
  if (a === 'throw') throw new Error('network');
  if (a === undefined) return fallback;
  return typeof a === 'function' ? await (a as (id: string) => T | Promise<T>)(id) : a;
}

export function PickerHarness(props: PickerHarnessProps) {
  const { viewerId = VIEWER_ID, roleView = true, canConfirm = false, showProposals = true, log } = props;
  const h1 = useRef<HTMLHeadingElement>(null);
  const [scene, setScene] = useState(props.scene);
  const rereads = useRef(0);
  const sceneRef = useRef(scene);
  useLayoutEffect(() => {
    sceneRef.current = scene;
  }, [scene]);
  const pendingRoleIds = new Set(scene.proposals.items.filter((p) => !p.is_expired).map((p) => p.role.id));
  const allRoles = props.roles ?? ROLES;
  const roleOf = (id: string) => allRoles.find((r) => r.id === id)!;

  const reread = async (): Promise<RereadResult> => {
    log?.push('reread');
    await delay();
    rereads.current += 1;
    const next = props.reread ? await props.reread(sceneRef.current, rereads.current) : { ...sceneRef.current };
    if ('kind' in next) return next;
    setScene(next);
    await delay(); // resolve only after the new props are set
    return { kind: 'ok' };
  };

  return (
    <TooltipProvider delayDuration={0}>
      <main>
        <h1 ref={h1} tabIndex={-1}>
          {scene.person.display_name}
        </h1>
        <RolePicker
          person={scene.person}
          viewerId={viewerId}
          offered={scene.offered}
          pendingRoleIds={pendingRoleIds}
          roles={roleView ? allRoles : undefined}
          permissions={roleView ? (props.permissions ?? PERMISSIONS) : undefined}
          actions={{
            assign: (id) => (log?.push(`assign:${roleOf(id).name}`), answer(props.assign, id, { kind: 'given', role: roleOf(id) })),
            propose: (id) => (log?.push(`propose:${roleOf(id).name}`), answer(props.propose, id, { kind: 'unreachable' })),
            revoke: (id) => (log?.push(`revoke:${roleOf(id).name}`), answer(props.revoke, id, { kind: 'taken-away' })),
            reread,
          }}
          onRolesChanged={props.onRolesChanged}
        />
        {showProposals ? (
          <RoleProposals
            proposals={scene.proposals}
            canConfirm={canConfirm}
            marginExposure={scene.person.margin_exposure}
            fallbackFocusRef={h1}
            timeZone="Asia/Manila"
            onProposalChanged={props.onProposalChanged}
            actions={{
              confirm: (id) => (log?.push(`confirm:${id}`), answer(props.confirm, id, { kind: 'unreachable' })),
              reject: (id) => (log?.push(`reject:${id}`), answer(props.reject, id, { kind: 'unreachable' })),
              withdraw: (id) => (log?.push(`withdraw:${id}`), answer(props.withdraw, id, { kind: 'unreachable' })),
              reread,
              onPage: (d, c) => props.onPage?.(d, c),
            }}
          />
        ) : null}
      </main>
    </TooltipProvider>
  );
}

export const scene = (person: User, offered: OfferedRole[], proposals: RoleGrantProposal[] = []): Scene => ({ person, offered, proposals: page(proposals) });

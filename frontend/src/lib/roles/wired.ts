import type { RoleGrantProposal } from '../api/proposals';
import type { Role } from '../api/roles';
import type { ApiCaller, ApiResult } from '../bff/apiCall';
import { ACTION_DEADLINES_MS, startDeadline, type Deadline } from '../bff/deadline';
import type { AssignResult, ProposeResult, RereadAction, RereadResult, RevokeResult, RoleActionFailure, RolePickerActions, RoleProposalActions } from './results';

// The role picker's and the Waiting group's actions over the BFF (b3b-design.md §9.2, §9.3; replaces `unwired.ts` on
// the person page). Time limits (§6.2 R-2 item 2, final RED M-1): none of the B-4 calls takes a signal, so each factory
// holds `activeDeadline` in its closure. The first assign/revoke/propose (picker, 60 s) or confirm/reject/withdraw
// (Waiting group, 30 s) made with no active deadline starts one; every later call AND the next `reread()` use it; it
// clears when that re-read settles. A `reread()` made with no active deadline (Load roles again, the pager) starts its
// own 30 s one. The action signatures are B-4's, unchanged (A-10).
//
// AssignRole and CreateRoleGrantProposal REQUIRE an Idempotency-Key and do not store its value (lib/roles/results.ts):
// a new key per call (§7 "Give / propose"), so a retry is refused `already_held` / `already_pending`, never replayed.

/** What the re-read follows, for the page's banner heading when it takes over (§10, ux-copy-b3b.md C6-1). */
export type RereadContext =
  | {
      readonly kind: 'save';
      /** Requests in the save that did not answer 2xx. */
      readonly notDone: number;
      /** Requests in the save that answered 2xx: something changed, so the lists are stale (FE-R1 R-15a / R-15b). */
      readonly done: number;
    }
  | { readonly kind: 'proposal'; readonly act: 'confirm' | 'reject' | 'withdraw'; readonly ok: boolean }
  | { readonly kind: 'none' };

/** The page's whole re-read under `signal`; it resolves after the new props are committed (lib/roles/results.ts). */
export type PageReread = (signal: AbortSignal, context: RereadContext) => Promise<RereadResult>;

export interface RoleWiring {
  readonly caller: ApiCaller;
  readonly personId: string;
  readonly reread: PageReread;
  /** A fresh key per call; `crypto.randomUUID()` unless a test injects one. */
  readonly newKey?: () => string;
}

/** An ApiResult failure as a role-action failure. An expired key or a 429 cannot be served here: server-error. */
export function roleFailureOf(r: Exclude<ApiResult, { kind: 'ok' }>): RoleActionFailure {
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

const isObject = (x: unknown): x is Record<string, unknown> => !!x && typeof x === 'object';
const isRole = (x: unknown): x is Role => isObject(x) && typeof x.id === 'string' && Array.isArray(x.permission_codes);
const isProposal = (x: unknown): x is RoleGrantProposal => isObject(x) && typeof x.id === 'string' && isObject(x.role);

/** The shared deadline holder: one action deadline, then the re-read that ends it. */
function deadlineHolder(actionMs: number) {
  let active: Deadline | null = null;
  return {
    forAction(): AbortSignal {
      if (active === null) active = startDeadline(actionMs);
      return active.signal;
    },
    /** The re-read's deadline: the action's when one is active, else a fresh 30 s one. Settling clears it. */
    async reread<T>(run: (signal: AbortSignal, shared: boolean) => Promise<T>): Promise<T> {
      const shared = active !== null;
      const d = active ?? startDeadline(ACTION_DEADLINES_MS.reread);
      try {
        return await run(d.signal, shared);
      } finally {
        d.cancel();
        if (active === d) active = null;
      }
    },
  };
}

export function pickerActions(w: RoleWiring): RolePickerActions {
  const deadline = deadlineHolder(ACTION_DEADLINES_MS.saveRoles);
  const newKey = w.newKey ?? (() => crypto.randomUUID());
  let notDone = 0;
  let done = 0;
  const track = <T extends { kind: string }>(r: T, success: string): T => {
    if (r.kind !== success) notDone += 1;
    else done += 1;
    return r;
  };

  return {
    assign: async (roleId): Promise<AssignResult> => {
      const r = await w.caller.call('AssignRole', { id: w.personId }, { role_id: roleId }, { idempotencyKey: newKey(), signal: deadline.forAction() });
      const out: AssignResult = r.kind !== 'ok' ? roleFailureOf(r) : isRole(r.body) ? { kind: 'given', role: r.body } : { kind: 'server-error' };
      return track(out, 'given');
    },
    propose: async (roleId): Promise<ProposeResult> => {
      const r = await w.caller.call('CreateRoleGrantProposal', { id: w.personId }, { role_id: roleId }, { idempotencyKey: newKey(), signal: deadline.forAction() });
      const out: ProposeResult = r.kind !== 'ok' ? roleFailureOf(r) : isProposal(r.body) ? { kind: 'proposed', proposal: r.body } : { kind: 'server-error' };
      return track(out, 'proposed');
    },
    revoke: async (roleId): Promise<RevokeResult> => {
      const r = await w.caller.call('RevokeRole', { id: w.personId, roleId }, undefined, { signal: deadline.forAction() });
      const out: RevokeResult = r.kind === 'ok' ? { kind: 'taken-away' } : roleFailureOf(r);
      return track(out, 'taken-away');
    },
    reread: () =>
      deadline.reread((signal, shared) => {
        // Only requests that were SENT are counted: runSave marks the rest `not-sent` without calling here, so the page
        // reads `notDone > 0` as "something in this save was not saved", never as an exact figure (deviation D-22).
        const context: RereadContext = shared ? { kind: 'save', notDone, done } : { kind: 'none' };
        notDone = 0;
        done = 0;
        return w.reread(signal, context);
      }),
  };
}

export function proposalActions(w: RoleWiring & { readonly onPage: (direction: 'after' | 'before', cursor: string, signal: AbortSignal) => Promise<void> }): RoleProposalActions {
  const deadline = deadlineHolder(ACTION_DEADLINES_MS.proposalAction);
  let last: RereadContext = { kind: 'none' };
  const act = async <K extends 'confirmed' | 'rejected' | 'withdrawn'>(
    which: 'confirm' | 'reject' | 'withdraw',
    run: (signal: AbortSignal) => Promise<ApiResult>,
    success: K,
  ): Promise<{ kind: K; proposal: RoleGrantProposal } | RoleActionFailure> => {
    const r = await run(deadline.forAction());
    const result: { kind: K; proposal: RoleGrantProposal } | RoleActionFailure = r.kind !== 'ok' ? roleFailureOf(r) : isProposal(r.body) ? { kind: success, proposal: r.body } : { kind: 'server-error' };
    last = { kind: 'proposal', act: which, ok: result.kind === success };
    return result;
  };
  const reread: RereadAction = () =>
    deadline.reread((signal, shared) => {
      const context = shared ? last : ({ kind: 'none' } as const);
      last = { kind: 'none' };
      return w.reread(signal, context);
    });
  return {
    confirm: (id) => act('confirm', (signal) => w.caller.call('ConfirmRoleGrantProposal', { id }, undefined, { signal }), 'confirmed'),
    // A body is required; `reason` is optional and not sent (§9.3, VERIFIED (RED) "what held" 3).
    reject: (id) => act('reject', (signal) => w.caller.call('RejectRoleGrantProposal', { id }, {}, { signal }), 'rejected'),
    withdraw: (id) => act('withdraw', (signal) => w.caller.call('CancelRoleGrantProposal', { id }, undefined, { signal }), 'withdrawn'),
    reread,
    onPage: (direction, cursor) => {
      const d = startDeadline(ACTION_DEADLINES_MS.reread);
      void w.onPage(direction, cursor, d.signal).finally(() => d.cancel());
    },
  };
}

// The Save roles protocol (RED Q-2 + M-1; Revision 1/2 "Save"; Revision 3 A-2, A-4). Framework-free, unit-tested
// without React.
//
// Q-2, measured in `backend/src`: AssignRole / CreateRoleGrantProposal judge phase 4 against the subject's LIVE held codes
// at that moment, and all three operations take the tenant-wide `RoleAdministrationLock`. So the only correct order is
// every take-away first, then gives, then proposals, STRICTLY SEQUENTIAL (a give that a held role blocks succeeds only
// after that held role is taken away). Parallel is wrong, not just slower: the server would serialise in an order the
// client does not control.

import type { OfferedRole } from '../api/roles';
import { giveKindOf, type Intents } from './intents';
import type { AssignResult, ProposeResult, RevokeResult, RolePickerActions } from './results';

export type RequestKind = 'take-away' | 'give' | 'propose';

export interface SaveRequest {
  roleId: string;
  roleName: string;
  kind: RequestKind;
}

const ORDER: readonly RequestKind[] = ['take-away', 'give', 'propose'];

/**
 * Ordered requests: take-aways, then gives, then proposals; within a kind, the order the roles are listed (`offered`).
 * A give's kind is derived from the served `requires_owner_confirmation` now, never stored (RED H-1). `nameOf` resolves a
 * held role's name if it is somehow absent from `offered`.
 */
export function planSave(intents: Intents, offered: readonly OfferedRole[], nameOf: (roleId: string) => string): SaveRequest[] {
  const position = new Map(offered.map((r, i) => [r.id, i]));
  const byId = new Map(offered.map((r) => [r.id, r]));
  const requests: SaveRequest[] = [];
  for (const [roleId, intent] of intents) {
    const role = byId.get(roleId);
    const kind: RequestKind = intent === 'take-away' ? 'take-away' : role ? giveKindOf(role) : 'give';
    requests.push({ roleId, roleName: role?.name ?? nameOf(roleId), kind });
  }
  const pos = (r: SaveRequest) => position.get(r.roleId) ?? Number.MAX_SAFE_INTEGER;
  return requests.sort((a, b) => ORDER.indexOf(a.kind) - ORDER.indexOf(b.kind) || pos(a) - pos(b));
}

export type RequestResult = AssignResult | ProposeResult | RevokeResult;
export type OutcomeResult = RequestResult | { kind: 'not-sent' };
export interface Outcome {
  request: SaveRequest;
  result: OutcomeResult;
}

/** A-4: the stop set. A `forbidden` stop is not an interrupted save: it produces R1-5's banner only. */
export type StopKind = 'unreachable' | 'server-error' | 'session-ended' | 'forbidden';
const STOPS: ReadonlySet<string> = new Set<StopKind>(['unreachable', 'server-error', 'session-ended', 'forbidden']);

export interface SaveRun {
  outcomes: Outcome[];
  /** The first stop, or null when every request was answered with a 2xx or a refusal (incl. 404). */
  stop: StopKind | null;
}

export const isSuccess = (r: OutcomeResult): boolean => r.kind === 'given' || r.kind === 'proposed' || r.kind === 'taken-away';

/**
 * Sends the plan one request at a time (each starts only after the previous resolved). A refusal (incl. 404) continues
 * to the next request; `unreachable` / `server-error` / `session-ended` / `forbidden` stops and marks the rest
 * `not-sent`. A thrown action counts as `unreachable`. `onOutcome` fires as each answer arrives (A-2: a 2xx clears its
 * own intent at once, before the re-read). The caller re-reads in EVERY case afterwards.
 */
export async function runSave(plan: readonly SaveRequest[], actions: Pick<RolePickerActions, 'assign' | 'propose' | 'revoke'>, onOutcome?: (o: Outcome) => void): Promise<SaveRun> {
  const outcomes: Outcome[] = [];
  let stop: StopKind | null = null;
  for (const request of plan) {
    if (stop !== null) {
      outcomes.push({ request, result: { kind: 'not-sent' } });
      continue;
    }
    let result: RequestResult;
    try {
      result =
        request.kind === 'take-away'
          ? await actions.revoke(request.roleId)
          : request.kind === 'give'
            ? await actions.assign(request.roleId)
            : await actions.propose(request.roleId);
    } catch {
      result = { kind: 'unreachable' };
    }
    const outcome = { request, result };
    outcomes.push(outcome);
    onOutcome?.(outcome);
    if (STOPS.has(result.kind)) stop = result.kind as StopKind;
  }
  return { outcomes, stop };
}

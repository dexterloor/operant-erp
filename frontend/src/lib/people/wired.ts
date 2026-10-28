import type { User } from '../api/users';
import type { ApiCaller, ApiResult } from '../bff/apiCall';
import { ACTION_DEADLINES_MS, startDeadline } from '../bff/deadline';
import { readOf, type ReadFailure } from '../bff/reads';
import { loadPerson } from './load';
import type { DeletePersonAction, PersonActionFailure, RestorePersonAction } from './results';

// Delete / Restore person over the BFF (b3b-design.md §9.1; replaces `unwired.ts` on the person page). One 30 s deadline
// per action covers the request AND its re-read (§6.2 rule R-2 item 2). Caller contract (lib/people/results.ts): resolve
// only after the person prop has been replaced, so the page's branch on `archived_at` has swapped when the dialog closes.

export interface PersonWiring {
  readonly caller: ApiCaller;
  readonly id: string;
  /** Sets the person and resolves after the commit that shows it. */
  readonly setPerson: (person: User) => Promise<void>;
  /** The re-read after a delete did not answer a person: the page shows its region error, or swaps (ST-4b / ST-5). */
  readonly onRereadFailed: (failure: ReadFailure) => void;
}

/** An ApiResult failure as a person-action failure. An expired key or a 429 cannot come from these two: server-error. */
export function personFailureOf(r: Exclude<ApiResult, { kind: 'ok' }>): PersonActionFailure {
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

const isUser = (x: unknown): x is User => !!x && typeof x === 'object' && typeof (x as User).id === 'string' && Array.isArray((x as User).roles);

export function personActions(w: PersonWiring): { deletePerson: DeletePersonAction; restorePerson: RestorePersonAction } {
  const deletePerson: DeletePersonAction = async () => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.personAction);
    try {
      const r = await w.caller.call('DeleteUser', { id: w.id }, undefined, { signal: deadline.signal });
      if (r.kind !== 'ok') return personFailureOf(r);
      // §9.1: a 204, then the person is re-read (served with `archived_at`). If that read fails or hits the deadline the
      // delete still happened: resolve `deleted`, and the page shows its region error for the person read.
      const again = await loadPerson(readOf(w.caller), w.id, deadline.signal);
      if (again.kind === 'ok') await w.setPerson(again.person);
      else w.onRereadFailed(again.kind);
      return { kind: 'deleted' };
    } finally {
      deadline.cancel();
    }
  };

  const restorePerson: RestorePersonAction = async () => {
    const deadline = startDeadline(ACTION_DEADLINES_MS.personAction);
    try {
      const r = await w.caller.call('RestoreUser', { id: w.id }, undefined, { signal: deadline.signal });
      if (r.kind !== 'ok') return personFailureOf(r);
      if (!isUser(r.body)) return { kind: 'server-error' };
      // The 200 is the restored person: no second read (§9.1).
      await w.setPerson(r.body);
      return { kind: 'restored', person: r.body };
    } finally {
      deadline.cancel();
    }
  };

  return { deletePerson, restorePerson };
}

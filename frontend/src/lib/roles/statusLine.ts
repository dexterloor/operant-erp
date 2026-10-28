// The picker's ONE status line (ux-copy-frontend.md R4-1 as replaced by 4b-1; b4-owed-copy.md R1-1/R1-2): which sentences,
// in which order. Framework-free and copy-free (lib never imports copy): the words arrive as functions from
// `copy/roles.ts`, which matches these interfaces structurally. Announce from event handlers or after an awaited action,
// never from a passive effect (RED L-8).

import type { Outcome, RequestKind } from './save';

export interface TickWords {
  unavailableOne: (role: string, cause: string) => string;
  unavailableMany: (roles: string, cause: string) => string;
  againOne: (role: string) => string;
  againMany: (roles: string) => string;
  /** ux-confirm-b4.md §2.7 (ST-4f middle arm): Save roles has just become unavailable because nothing is staged. */
  saveNothing: string;
}

/**
 * One update per tick (4b-1): the newly-unavailable sentence, the available-again sentence, then the margin warning's own
 * sentences when it appeared or changed form because of this tick (R4-2 L-3), then the nothing-to-save sentence when
 * this toggle left nothing staged (ux-confirm-b4.md §2.7). `''` when the tick changed nothing else (the checkbox
 * announces its own state).
 */
export function tickStatus(
  words: TickWords,
  join: (items: readonly string[]) => string,
  change: { newlyUnavailable: readonly string[]; availableAgain: readonly string[]; cause: string; marginSentences: readonly string[]; nothingStaged: boolean },
): string {
  const out: string[] = [];
  const { newlyUnavailable: n, availableAgain: a, cause } = change;
  if (n.length === 1) out.push(words.unavailableOne(n[0]!, cause));
  else if (n.length > 1) out.push(words.unavailableMany(join(n), cause));
  if (a.length === 1) out.push(words.againOne(a[0]!));
  else if (a.length > 1) out.push(words.againMany(join(a)));
  out.push(...change.marginSentences);
  if (change.nothingStaged) out.push(words.saveNothing);
  return out.join(' ');
}

export interface SummaryWords {
  given: (roles: string) => string;
  proposed: (roles: string) => string;
  takenAway: (roles: string) => string;
  notGiven: (roles: string) => string;
  notProposed: (roles: string) => string;
  notTakenAway: (roles: string) => string;
  /** 4b-1: ends the summary when any request was refused. */
  reasons: string;
  /** R1-1: an interrupted save that still shows its banner. */
  didntFinish: string;
  /** R1-2 case B: interrupted, re-read OK, nothing still marked. */
  allSaved: string;
}

/** A request the server answered with a refusal of its own (409/422, 404, 403) — it has a banner. */
export const isRefusal = (o: Outcome) => o.result.kind === 'refused' || o.result.kind === 'not-found' || o.result.kind === 'forbidden';

export interface SummaryInput {
  outcomes: readonly Outcome[];
  /** `runSave` stopped on `unreachable` / `server-error` (R1-2). */
  interrupted: boolean;
  /** R1-2 case B: interrupted, the re-read succeeded and nothing is still marked (excluding refused rows). */
  nothingStillMarked: boolean;
  /** 4b-2: the held form's sentence when the re-read made it appear, else null. */
  heldForm: string | null;
}

/**
 * After Save roles (R4-1 clauses; 4b-1; 4b-2; R1-1; R1-2 case B). Outcome clauses in R4-1's order — `Given`, `Proposed,
 * waiting for an owner`, `Taken away`, then (not interrupted) `Not given` / `Not proposed` / `Not taken away` — then the
 * held form, then `The save didn't finish…` (interrupted, banner shown) or `All your changes are now saved.` (case B), then
 * the reasons sentence when any request was refused. R1-1 lists only the 2xx clauses for an interrupted save.
 */
export function saveSummary(words: SummaryWords, join: (items: readonly string[]) => string, input: SummaryInput): string {
  const names = (pred: (o: Outcome) => boolean) => input.outcomes.filter(pred).map((o) => o.request.roleName);
  const of = (kind: string) => (o: Outcome) => o.result.kind === kind;
  const refusedAs = (kind: RequestKind) => (o: Outcome) => isRefusal(o) && o.request.kind === kind;
  const clauses: Array<[string[], (roles: string) => string]> = [
    [names(of('given')), words.given],
    [names(of('proposed')), words.proposed],
    [names(of('taken-away')), words.takenAway],
  ];
  if (!input.interrupted) {
    clauses.push([names(refusedAs('give')), words.notGiven], [names(refusedAs('propose')), words.notProposed], [names(refusedAs('take-away')), words.notTakenAway]);
  }
  const out = clauses.filter(([n]) => n.length > 0).map(([n, w]) => w(join(n)));
  if (input.heldForm) out.push(input.heldForm);
  const anyRefused = input.outcomes.some(isRefusal);
  if (input.interrupted) {
    if (!input.nothingStillMarked) out.push(words.didntFinish);
    else if (!anyRefused) out.push(words.allSaved);
  }
  if (anyRefused) out.push(words.reasons);
  return out.join(' ');
}

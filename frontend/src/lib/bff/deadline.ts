// Per-action time limits (b3b-design.md §6.2 rule R-2 item 2; L-8): one deadline signal per user action, covering its
// request(s) and its re-read. Each figure lives here and nowhere else. A request aborted by its deadline resolves
// `unreachable`, which the surfaces render as R1-1 ("We can't tell whether…"): an aborted request may have committed.

export const ACTION_DEADLINES_MS = {
  /** Delete / Restore person, request + re-read. */
  personAction: 30_000,
  /** Confirm / Reject / Withdraw, request + re-read. */
  proposalAction: 30_000,
  /** Checklist save, request + re-read. */
  checklistSave: 30_000,
  /** Save roles: every request + the re-read. */
  saveRoles: 60_000,
  /** A bulk run (its re-read is outside the busy dialog, A-5). */
  bulkRun: 30_000,
  /** A re-read started by a button (Load roles again, the pager). */
  reread: 30_000,
} as const;

export interface Deadline {
  readonly signal: AbortSignal;
  /** Clears the timer; the signal is never aborted afterwards. */
  cancel(): void;
}

export function startDeadline(ms: number): Deadline {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), ms);
  return { signal: controller.signal, cancel: () => clearTimeout(timer) };
}

// Bulk selection as pure state (b4-architecture.md tree: `lib/bulk/selection.ts` — "scope-keyed state, derived reset";
// RED M-8; Revision 1 C-1 for the key). Framework-free: no react, next or component import (architecture.test.ts).
//
// A selection belongs to ONE scope: this page and this view (round-6 §2c; ux-copy Rev 3 item 4: changing page, segment,
// filter or search, or switching to or from the Archived view, clears it). The caller turns those into one `scopeKey`.
// The reset is DERIVED, never written by an effect: a state whose `scopeKey` is not the current one reads as empty in
// the very render the scope changes (an effect would show one frame of the old ids under the new view — the S-3 hazard
// of a Draft → Archived switch offering Restore for draft ids — and double-runs under StrictMode). The next change
// writes the new scope.
//
// The idempotency key belongs to the selection: minted when the selection is first submitted, reused while it is
// unchanged, dropped by ANY change (and by clear, and by a scope change). No cap lives here: `maxMembers` is a prop
// (H-5 item 2), never a literal in `src/`.

export interface SelectionState {
  readonly scopeKey: string;
  /** Selected row ids in the order they were selected. */
  readonly ids: readonly string[];
  /** The key the current, unchanged selection was submitted with; null until it is first submitted. */
  readonly key: string | null;
}

export type SelectionAction =
  | { type: 'toggle'; scopeKey: string; id: string; next: boolean }
  /** The header checkbox: select or unselect exactly the rows on this page — never more. */
  | { type: 'page'; scopeKey: string; pageIds: readonly string[]; next: boolean }
  | { type: 'clear' }
  /** Records the key minted for the current selection (ignored if the scope has moved on). */
  | { type: 'keyed'; scopeKey: string; key: string };

const NONE: readonly string[] = Object.freeze([]);

export function initialSelection(scopeKey: string): SelectionState {
  return { scopeKey, ids: NONE, key: null };
}

/** The state as seen from `scopeKey`: another scope's ids and key do not exist here. */
function inScope(state: SelectionState, scopeKey: string): SelectionState {
  return state.scopeKey === scopeKey ? state : initialSelection(scopeKey);
}

function withIds(base: SelectionState, ids: readonly string[]): SelectionState {
  const same = ids.length === base.ids.length && ids.every((id, i) => id === base.ids[i]);
  // Any change to the selection drops its key; a no-op keeps it (the selection is unchanged).
  return same ? base : { scopeKey: base.scopeKey, ids, key: null };
}

export function reduceSelection(state: SelectionState, action: SelectionAction): SelectionState {
  switch (action.type) {
    case 'toggle': {
      const base = inScope(state, action.scopeKey);
      const has = base.ids.includes(action.id);
      if (action.next === has) return base;
      return withIds(base, action.next ? [...base.ids, action.id] : base.ids.filter((id) => id !== action.id));
    }
    case 'page': {
      const base = inScope(state, action.scopeKey);
      if (action.next) return withIds(base, [...base.ids, ...action.pageIds.filter((id) => !base.ids.includes(id))]);
      return withIds(base, base.ids.filter((id) => !action.pageIds.includes(id)));
    }
    case 'clear':
      return state.ids.length === 0 && state.key === null ? state : initialSelection(state.scopeKey);
    case 'keyed':
      return state.scopeKey === action.scopeKey ? { ...state, key: action.key } : state;
  }
}

/** The selected ids for the scope being rendered — empty if the state belongs to another scope (derived reset). */
export function selectedIn(state: SelectionState, scopeKey: string): readonly string[] {
  return inScope(state, scopeKey).ids;
}

/** The key of the current, unchanged selection in this scope, or null if it has not been submitted since it changed. */
export function keyIn(state: SelectionState, scopeKey: string): string | null {
  return inScope(state, scopeKey).key;
}

/** The header checkbox: ticked when every row on the page is selected, mixed when some are, clear when none (or no rows). */
export function pageCheckState(selected: readonly string[], pageIds: readonly string[]): boolean | 'mixed' {
  const n = pageIds.filter((id) => selected.includes(id)).length;
  if (n === 0) return false;
  return n === pageIds.length ? true : 'mixed';
}

/** Over the served per-action cap: the action is aria-disabled with its reason (round-6 §2c). */
export function isOverCap(count: number, maxMembers: number): boolean {
  return count > maxMembers;
}

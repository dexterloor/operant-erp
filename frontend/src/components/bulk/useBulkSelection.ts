'use client';

import { useCallback, useMemo, useState, type ReactNode } from 'react';
import { initialSelection, keyIn, reduceSelection, selectedIn, type SelectionAction, type SelectionState } from '@/lib/bulk/selection';
import { useLiveRegion, type LiveRegionHandle } from '@/components/ui/LiveRegion';

/** A banner in the list's banner zone (bulk Restore's refusals and interruptions; R1-8's Already sent). */
export interface BulkNotice {
  /** A new id is a new banner: when `focus` is set, focus moves to its heading once per id. */
  id: number;
  heading: string;
  body: ReactNode;
  /**
   * R1-1: a banner whose heading takes focus carries no live role. R1-8's Already sent does not take focus (the bar
   * goes, so focus goes to the list's `<h1>`, R1-9), so it keeps ST-3's alert role.
   */
  focus: boolean;
  /** Danger for a refusal or interruption; info for R1-8's Already sent (nothing went wrong this time). */
  tone: 'danger' | 'info';
}

export interface UseBulkSelectionOptions {
  /**
   * This page and this view, as one string (round-6 §2c; ux-copy Rev 3 item 4): the cursor (Newer/Older), the segment,
   * the filters, the search and the Archived view. A different key reads as an empty selection in the same render
   * (derived reset, RED M-8) — never cleared in an effect.
   */
  scopeKey: string;
  /**
   * Decided by the caller, never here: the viewer holds the list's delete permission (round-6 §2c: otherwise there is
   * no checkbox column at all, ST-4c) AND this view admits bulk (on orders only the Draft segment and the Archived view).
   * While false, the column and the bar render nothing.
   */
  offered: boolean;
  /** Mints an idempotency key. Default `crypto.randomUUID()`; tests pass a counter. */
  mintKey?: () => string;
}

export interface BulkSelection {
  offered: boolean;
  scopeKey: string;
  /** The selected ids in this scope (empty when the scope has just changed). */
  selected: readonly string[];
  count: number;
  isSelected: (id: string) => boolean;
  toggle: (id: string, next: boolean) => void;
  /** The header checkbox: exactly the rows on this page. */
  setPage: (pageIds: readonly string[], next: boolean) => void;
  clear: () => void;
  /**
   * Revision 1 C-1 / RED H-3: the key to submit the current selection with — minted the first time it is submitted,
   * the same key on every re-run while the selection is unchanged, a new one after any change. Call it from the event
   * handler that submits, never in render.
   */
  submitKey: () => string;
  /** The always-mounted after-message region's state (RED M-6): the PARENT renders `<BulkResultMessage bulk={…}>`. */
  live: LiveRegionHandle;
  notice: BulkNotice | null;
  showNotice: (notice: Omit<BulkNotice, 'id'>) => void;
  clearNotice: () => void;
}

const defaultMint = () => crypto.randomUUID();
const NONE: readonly string[] = Object.freeze([]);

/**
 * The state of one list's bulk surface. Call it in the list component (the surface's parent), and render the column
 * (`BulkHeaderCheckbox`, `BulkRowCheckbox`), `BulkBar`, `BulkNotices` and `BulkResultMessage` from it. The after-message
 * region lives in `BulkResultMessage`, rendered by the parent outside the bar, so it is mounted empty from first render
 * and survives the bar going away when a run clears the selection (R4-4 L-2, RED M-6).
 */
export function useBulkSelection({ scopeKey, offered, mintKey = defaultMint }: UseBulkSelectionOptions): BulkSelection {
  const [state, setState] = useState<SelectionState>(() => initialSelection(scopeKey));
  const [notice, setNotice] = useState<BulkNotice | null>(null);
  const live = useLiveRegion();

  const dispatch = useCallback((action: SelectionAction) => setState((s) => reduceSelection(s, action)), []);
  const selected = selectedIn(state, scopeKey);
  const currentKey = keyIn(state, scopeKey);

  const toggle = useCallback(
    (id: string, next: boolean) => {
      setNotice(null);
      dispatch({ type: 'toggle', scopeKey, id, next });
    },
    [dispatch, scopeKey],
  );
  const setPage = useCallback(
    (pageIds: readonly string[], next: boolean) => {
      setNotice(null);
      dispatch({ type: 'page', scopeKey, pageIds, next });
    },
    [dispatch, scopeKey],
  );
  const clear = useCallback(() => dispatch({ type: 'clear' }), [dispatch]);
  const submitKey = useCallback(() => {
    if (currentKey !== null) return currentKey;
    const key = mintKey();
    dispatch({ type: 'keyed', scopeKey, key });
    return key;
  }, [currentKey, dispatch, mintKey, scopeKey]);
  const showNotice = useCallback((n: Omit<BulkNotice, 'id'>) => setNotice((prev) => ({ ...n, id: (prev?.id ?? 0) + 1 })), []);
  const clearNotice = useCallback(() => setNotice(null), []);

  return useMemo(
    () => ({
      offered,
      scopeKey,
      selected: offered ? selected : NONE,
      count: offered ? selected.length : 0,
      isSelected: (id: string) => offered && selected.includes(id),
      toggle,
      setPage,
      clear,
      submitKey,
      live,
      notice,
      showNotice,
      clearNotice,
    }),
    [offered, scopeKey, selected, toggle, setPage, clear, submitKey, live, notice, showNotice, clearNotice],
  );
}

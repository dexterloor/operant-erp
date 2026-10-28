'use client';

import { useCallback, useLayoutEffect, useRef, useState } from 'react';

/**
 * State whose setter resolves after the commit that shows the new value (b3b-design.md §10 "Re-read": a generation
 * counter and a deferred promise, resolved in a layout effect). The wired actions' caller contracts need it: a re-read
 * resolves only after its props are set (lib/roles/results.ts, lib/people/results.ts). `router.refresh()` gives no such
 * signal (F-30).
 */
export function useCommittedState<T>(initial: T): [T, (next: T) => Promise<void>] {
  const [state, setState] = useState<{ value: T; gen: number }>({ value: initial, gen: 0 });
  const waiting = useRef(new Map<number, () => void>());
  useLayoutEffect(() => {
    for (const [gen, resolve] of waiting.current) {
      if (gen <= state.gen) {
        waiting.current.delete(gen);
        resolve();
      }
    }
  }, [state.gen]);
  const set = useCallback(
    (next: T) =>
      new Promise<void>((resolve) => {
        setState((s) => {
          const gen = s.gen + 1;
          waiting.current.set(gen, resolve);
          return { value: next, gen };
        });
      }),
    [],
  );
  return [state.value, set];
}

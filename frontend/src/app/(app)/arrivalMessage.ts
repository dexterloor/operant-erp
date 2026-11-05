'use client';

import { useEffect, useRef } from 'react';

// A one-shot, in-memory message for the page a create navigates to (Revision 7 A-19 item 7, Rev 2 S-5, R3-6): the
// module-state shape `DocumentArrival.tsx` uses. Not a query parameter (it would sit in the address and history, and a
// reload would announce it again) and not `sessionStorage` (it survives a reload). A full reload loses it, which is
// correct: the page then arrives as a document load, with nothing to announce.

/** A message not taken within this long is dropped (step-4.3 RED L-1): a much later visit to the page says nothing. */
const LIFETIME_MS = 10_000;

let left: { href: string; text: string; at: number } | null = null;

/** Leaves `text` for the page at `href`. A later leave replaces it. */
export function leaveArrivalMessage(href: string, text: string): void {
  left = { href, text, at: Date.now() };
}

/**
 * The text left for `href` within the last 10 s, or null. Clears the message WHATEVER it returns, so one never taken is
 * gone at the next take.
 */
export function takeArrivalMessage(href: string): string | null {
  const m = left;
  left = null;
  return m !== null && m.href === href && Date.now() - m.at <= LIFETIME_MS ? m.text : null;
}

/**
 * Takes the message left for `href` once and writes it with `announce`. The take runs in the effect, guarded by the ref
 * (`if (ref.current === undefined) ref.current = takeArrivalMessage(href)`), never in render: the ref survives
 * StrictMode's simulated remount, the effect re-run does not take again. The write is a task queued from the effect
 * (`setTimeout(…, 0)`, cancelled in cleanup), so it lands after `RouteFocus`'s effect, which runs later because
 * `RouteFocus` is `<main>`'s later sibling (`AppFrame.tsx`): the `h1` has focus when the region speaks.
 */
export function useArrivalMessage(href: string, announce: (text: string) => void): void {
  const ref = useRef<string | null | undefined>(undefined);
  useEffect(() => {
    if (ref.current === undefined) ref.current = takeArrivalMessage(href);
    const text = ref.current;
    if (text === null) return;
    const t = setTimeout(() => announce(text), 0);
    return () => clearTimeout(t);
  }, [href, announce]);
}
